<?php

declare(strict_types=1);

/**
 * What an AI agent does with a queued rule job, in osTicket's cron:
 *
 *   proposal   the plan waits in the ticket's chat window; any agent who
 *              can open the ticket runs it with their own rights, or not
 *   automatic  the plan runs at once with the AI agent's role; a reply
 *              goes out only past the send guard, otherwise it becomes an
 *              internal note with the reasons
 *
 * Every automatic run is logged with what it changed, so it can be undone
 * for UNDO_HOURS from the admin page.
 */
final class AiActionsAutomation
{
    public const UNDO_HOURS = 24;
    public const PROPOSAL_DAYS = 7;

    /** @var bool */
    private static $ready = false;

    public static function logTable(): string
    {
        return TABLE_PREFIX . 'ai_actions_log';
    }

    public static function proposalTable(): string
    {
        return TABLE_PREFIX . 'ai_actions_proposal';
    }

    public static function ensure(): void
    {
        if (self::$ready) {
            return;
        }
        db_query(sprintf("CREATE TABLE IF NOT EXISTS `%s` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `rule_id` INT UNSIGNED NOT NULL,
            `ticket_id` INT UNSIGNED NOT NULL,
            `agent_id` INT UNSIGNED NOT NULL,
            `mode` VARCHAR(10) NOT NULL,
            `answer` TEXT NOT NULL,
            `results` MEDIUMTEXT NOT NULL,
            `guard` TEXT NOT NULL,
            `sent` TINYINT(1) NOT NULL DEFAULT 0,
            `tokens` INT UNSIGNED NOT NULL DEFAULT 0,
            `undone` TINYINT(1) NOT NULL DEFAULT 0,
            `created` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `ticket` (`ticket_id`, `rule_id`, `created`),
            KEY `created` (`created`)
        ) DEFAULT CHARSET=utf8mb4", self::logTable()));
        db_query(sprintf("CREATE TABLE IF NOT EXISTS `%s` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `rule_id` INT UNSIGNED NOT NULL,
            `ticket_id` INT UNSIGNED NOT NULL,
            `agent_id` INT UNSIGNED NOT NULL,
            `answer` TEXT NOT NULL,
            `changes` MEDIUMTEXT NOT NULL,
            `preview` MEDIUMTEXT NOT NULL,
            `state` VARCHAR(10) NOT NULL DEFAULT 'open',
            `results` MEDIUMTEXT NOT NULL,
            `decided_by` INT UNSIGNED NOT NULL DEFAULT 0,
            `created` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `ticket` (`ticket_id`, `state`)
        ) DEFAULT CHARSET=utf8mb4", self::proposalTable()));
        self::$ready = true;
    }

    /** Logged runs of a rule on a ticket in the last 24 hours. */
    public static function runsToday(int $ruleId, int $ticketId): int
    {
        self::ensure();
        $res = db_query(sprintf('SELECT COUNT(*) FROM `%s` WHERE rule_id=%d AND ticket_id=%d AND created > NOW() - INTERVAL 1 DAY',
            self::logTable(), $ruleId, $ticketId));
        $row = $res ? db_fetch_row($res) : null;
        return $row ? (int) $row[0] : 0;
    }

    /** The cron worker for "rule" jobs. */
    public static function work(array $job): array
    {
        self::ensure();
        if (($job['kind'] ?? '') !== 'rule') {
            return array('ok' => false, 'error' => 'unknown_kind');
        }
        $rule = AiActionsRules::get((int) $job['rule_id']);
        if (!$rule || !$rule['active']) {
            return array('ok' => true, 'error' => 'rule_off');
        }
        $info = AiActionsAgents::get((int) $rule['agent']);
        $agent = $info && $info['active'] ? Staff::lookup((int) $rule['agent']) : null;
        $ticket = Ticket::lookup((int) $job['ticket_id']);
        if (!$agent || !$ticket) {
            return array('ok' => false, 'error' => $agent ? 'no_ticket' : 'agent_off');
        }
        if (!AiActionsRules::matches($rule, AiActionsRules::facts($ticket))) {
            return array('ok' => true, 'error' => 'no_match');
        }
        if (self::runsToday((int) $rule['id'], (int) $ticket->getId()) >= $rule['per_day']) {
            return array('ok' => true, 'error' => 'per_day');
        }
        if (!$ticket->checkStaffPerm($agent)) {
            return array('ok' => false, 'error' => 'agent_no_access');
        }

        $plan = AiActionsController::agentPlan($agent, $ticket, $rule, $info);
        if (isset($plan['error'])) {
            return array('ok' => false, 'error' => $plan['error']);
        }
        if (!$plan['changes']) {
            self::log($rule, $ticket, $agent, 'none', $plan['answer'], array(), array(), false, $plan['tokens']);
            return array('ok' => true);
        }
        if ($rule['mode'] === 'propose') {
            self::propose($rule, $ticket, $agent, $plan);
            return array('ok' => true);
        }
        self::runAuto($rule, $ticket, $agent, $plan);
        return array('ok' => true);
    }

    private static function propose(array $rule, $ticket, $agent, array $plan): void
    {
        // A newer proposal of the same rule replaces an open one.
        db_query(sprintf("UPDATE `%s` SET state='expired' WHERE ticket_id=%d AND rule_id=%d AND state='open'",
            self::proposalTable(), (int) $ticket->getId(), (int) $rule['id']));
        db_query(sprintf("INSERT INTO `%s` (rule_id, ticket_id, agent_id, answer, changes, preview, results, created)
            VALUES (%d, %d, %d, %s, %s, %s, '[]', NOW())", self::proposalTable(), (int) $rule['id'], (int) $ticket->getId(),
            (int) $agent->getId(), self::quote($plan['answer']), self::quote(json_encode($plan['changes'])),
            self::quote(json_encode($plan['preview']))));
        self::log($rule, $ticket, $agent, 'propose', $plan['answer'], $plan['preview'], array(), false, $plan['tokens']);
    }

    private static function runAuto(array $rule, $ticket, $agent, array $plan): void
    {
        $t = AiActionsI18n::strings(AiActionsController::language($agent));
        $results = array();
        $guard = array();
        $sent = false;
        $assigned = false;
        AiActionsExtensions::bind($ticket, $agent);
        foreach ($plan['changes'] as $c) {
            $choices = AiActionsChoices::forTicket($ticket, $agent, in_array('send_reply', $rule['actions'], true));
            $input = AiActionsTools::validate($c['action'], $c['input'], $choices);
            $line = $input !== null ? AiActionsTools::summary($c['action'], $input, $t, $choices)
                : array('label' => $c['action'], 'value' => '');
            if ($input === null) {
                $r = array('ok' => false, 'error' => 'rejected');
            } elseif ($c['action'] === 'send_reply') {
                $text = self::withDisclosure($input['text']);
                $guard = AiActionsGuard::check($ticket, $input['text'], $rule, AiActionsController::reviewer());
                if ($guard['ok']) {
                    $r = AiActionsExecutor::run($ticket, $agent, 'send_reply', array('text' => $text), false, true);
                    $sent = $r['ok'];
                } else {
                    $r = array('ok' => false, 'error' => 'held');
                    $errors = array();
                    $ticket->postNote(array(
                        'title' => 'AI reply held back (rule "' . $rule['name'] . '")',
                        'note'  => new TextThreadEntryBody("The send guard stopped this automatic reply: "
                            . implode(', ', $guard['reasons']) . ".\n\nDraft:\n" . $text),
                    ), $errors, $agent);
                }
            } else {
                $r = AiActionsExecutor::run($ticket, $agent, $c['action'], $input, $assigned);
            }
            $assigned = $assigned || ($r['ok'] && in_array($c['action'], array('assign_agent', 'assign_team'), true));
            $results[] = $line + array('ok' => $r['ok'], 'error' => $r['error'] ?? null, 'action' => $c['action'],
                                        'unchanged' => !empty($r['unchanged']), 'previous' => $r['previous'] ?? null);
            $ticket = Ticket::lookup($ticket->getId());
            AiActionsExtensions::bind($ticket, $agent);
        }
        $errors = array();
        $ticket->postNote(array(
            'title' => sprintf('AI action (automatic, %s, rule "%s")', (string) $agent->getName(), $rule['name']),
            'note'  => new TextThreadEntryBody(AiActionsController::noteBody($rule['task'], AiActionsController::publicResults($results), $t)),
        ), $errors, $agent);
        self::log($rule, $ticket, $agent, 'auto', $plan['answer'], $results, $guard, $sent, $plan['tokens']);
    }

    /** The AI notice under automatic replies, if switched on. Public for the unit tests. */
    public static function withDisclosure(string $text, ?array $settings = null): string
    {
        $s = $settings ?? AiActionsController::settings();
        if (!$s['ai_notice'] || trim($s['ai_notice_text']) === '') {
            return $text;
        }
        return rtrim($text) . "\n\n— " . trim($s['ai_notice_text']);
    }

    private static function log(array $rule, $ticket, $agent, string $mode, string $answer, array $results, array $guard, bool $sent, int $tokens): void
    {
        db_query(sprintf("INSERT INTO `%s` (rule_id, ticket_id, agent_id, mode, answer, results, guard, sent, tokens, created)
            VALUES (%d, %d, %d, '%s', %s, %s, %s, %d, %d, NOW())", self::logTable(), (int) $rule['id'], (int) $ticket->getId(),
            (int) $agent->getId(), $mode, self::quote($answer), self::quote(json_encode($results)), self::quote(json_encode($guard)),
            $sent ? 1 : 0, $tokens));
    }

    /** Undo of an automatic run, by an admin, within UNDO_HOURS. */
    public static function undo(int $id): bool
    {
        global $thisstaff;

        self::ensure();
        db_query(sprintf("UPDATE `%s` SET undone=1 WHERE id=%d AND mode='auto' AND undone=0 AND created > NOW() - INTERVAL %d HOUR",
            self::logTable(), $id, self::UNDO_HOURS));
        if (db_affected_rows() !== 1) {
            return false;
        }
        $res = db_query(sprintf('SELECT ticket_id, results, rule_id FROM `%s` WHERE id=%d', self::logTable(), $id));
        $row = $res ? db_fetch_array($res) : null;
        $ticket = $row ? Ticket::lookup((int) $row['ticket_id']) : null;
        if (!$ticket || !($thisstaff instanceof Staff)) {
            return false;
        }
        $lines = array();
        foreach (array_reverse((array) json_decode((string) $row['results'], true)) as $r) {
            if (empty($r['ok']) || !empty($r['unchanged']) || !AiActionsTools::undoable((string) ($r['action'] ?? ''))) {
                continue;
            }
            $u = AiActionsExecutor::undo($ticket, $thisstaff, (string) $r['action'], $r['previous'] ?? null);
            $lines[] = sprintf('- %s: %s (%s)', $r['label'] ?? '', $r['value'] ?? '', $u['ok'] ? 'put back' : 'failed: ' . ($u['error'] ?? ''));
            $ticket = Ticket::lookup($ticket->getId());
        }
        $errors = array();
        $ticket->postNote(array('title' => 'AI action undone', 'note' => new TextThreadEntryBody(
            "Automatic run #$id undone by " . $thisstaff->getName() . ":\n" . implode("\n", $lines))), $errors, $thisstaff);
        return true;
    }

    // ------------------------------------------------------------- proposals

    /** Open proposals of a ticket, for the chat window. */
    public static function proposals(int $ticketId): array
    {
        self::ensure();
        db_query(sprintf("UPDATE `%s` SET state='expired' WHERE state='open' AND created < NOW() - INTERVAL %d DAY",
            self::proposalTable(), self::PROPOSAL_DAYS));
        $res = db_query(sprintf("SELECT p.id, p.answer, p.preview, p.rule_id, s.firstname, s.lastname FROM `%s` p
            LEFT JOIN `%s` s ON s.staff_id=p.agent_id WHERE p.ticket_id=%d AND p.state='open' ORDER BY p.id",
            self::proposalTable(), STAFF_TABLE, $ticketId));
        $out = array();
        while ($res && ($r = db_fetch_array($res))) {
            $rule = AiActionsRules::get((int) $r['rule_id']);
            $out[] = array(
                'id'      => (int) $r['id'],
                'by'      => trim($r['firstname'] . ' ' . $r['lastname']),
                'rule'    => $rule ? $rule['name'] : '',
                'answer'  => (string) $r['answer'],
                'changes' => (array) json_decode((string) $r['preview'], true),
            );
        }
        return $out;
    }

    /**
     * A human runs or discards a proposal: with their own rights, every
     * change checked again against what they may choose now.
     *
     * @return array{ok:bool, results?:array, error?:string}
     */
    public static function decide(int $id, string $do, $staff, $ticket): array
    {
        self::ensure();
        $state = $do === 'run' ? 'running' : 'discarded';
        db_query(sprintf("UPDATE `%s` SET state='%s', decided_by=%d WHERE id=%d AND ticket_id=%d AND state='open'",
            self::proposalTable(), $state, (int) $staff->getId(), $id, (int) $ticket->getId()));
        if (db_affected_rows() !== 1) {
            return array('ok' => false, 'error' => 'expired');
        }
        if ($do !== 'run') {
            return array('ok' => true, 'results' => array());
        }
        $res = db_query(sprintf('SELECT changes, rule_id, agent_id FROM `%s` WHERE id=%d', self::proposalTable(), $id));
        $row = $res ? db_fetch_array($res) : null;
        $t = AiActionsI18n::strings(AiActionsController::language($staff));
        $results = array();
        $assigned = false;
        AiActionsExtensions::bind($ticket, $staff);
        foreach ((array) json_decode((string) ($row['changes'] ?? '[]'), true) as $c) {
            $choices = AiActionsChoices::forTicket($ticket, $staff);
            $input = AiActionsTools::validate((string) ($c['action'] ?? ''), $c['input'] ?? null, $choices);
            $line = $input !== null ? AiActionsTools::summary($c['action'], $input, $t, $choices)
                : array('label' => $t['labels'][$c['action'] ?? ''] ?? (string) ($c['action'] ?? ''), 'value' => '');
            $r = $input !== null ? AiActionsExecutor::run($ticket, $staff, $c['action'], $input, $assigned)
                : array('ok' => false, 'error' => 'rejected');
            $assigned = $assigned || ($r['ok'] && in_array($c['action'], array('assign_agent', 'assign_team'), true));
            $results[] = $line + array('ok' => $r['ok'], 'error' => $r['error'] ?? null);
            $ticket = Ticket::lookup($ticket->getId());
            AiActionsExtensions::bind($ticket, $staff);
        }
        $rule = AiActionsRules::get((int) ($row['rule_id'] ?? 0));
        $agent = Staff::lookup((int) ($row['agent_id'] ?? 0));
        $errors = array();
        $ticket->postNote(array(
            'title' => sprintf('%s (proposal of %s, rule "%s")', $t['note_title'], $agent ? (string) $agent->getName() : 'AI', $rule ? $rule['name'] : ''),
            'note'  => new TextThreadEntryBody(AiActionsController::noteBody($rule ? $rule['task'] : '', $results, $t)),
        ), $errors, $staff);
        db_query(sprintf("UPDATE `%s` SET state='done', results=%s WHERE id=%d", self::proposalTable(), self::quote(json_encode($results)), $id));
        return array('ok' => true, 'results' => $results);
    }

    // ------------------------------------------------------------------ log

    public static function logView(): string
    {
        self::ensure();
        $e = array('AiActionsAdmin', 'e');
        $res = db_query(sprintf('SELECT l.*, t.number, s.firstname, s.lastname FROM `%s` l LEFT JOIN `%s` t ON t.ticket_id=l.ticket_id
            LEFT JOIN `%s` s ON s.staff_id=l.agent_id ORDER BY l.id DESC LIMIT 100', self::logTable(), TICKET_TABLE, STAFF_TABLE));
        $rows = '';
        while ($res && ($r = db_fetch_array($res))) {
            $rule = AiActionsRules::get((int) $r['rule_id']);
            $parts = array();
            foreach ((array) json_decode((string) $r['results'], true) as $x) {
                $parts[] = sprintf('%s %s: %s', !empty($x['ok']) ? '✓' : '✕', $x['label'] ?? '', mb_substr((string) ($x['value'] ?? ''), 0, 80));
            }
            $guard = (array) json_decode((string) $r['guard'], true);
            $undo = $r['mode'] === 'auto' && !$r['undone'] && strtotime($r['created']) > time() - self::UNDO_HOURS * 3600
                ? AiActionsAdmin::button('log_undo', 'Undo', array('log_id' => (string) $r['id'])) : ($r['undone'] ? 'undone' : '');
            $rows .= sprintf('<tr><td>%s</td><td><a href="%s">#%s</a></td><td>%s</td><td>%s</td><td>%s</td><td>%s%s</td><td>%d</td><td>%s</td></tr>',
                call_user_func($e, (string) $r['created']), call_user_func($e, ROOT_PATH . 'scp/tickets.php?id=' . (int) $r['ticket_id']),
                call_user_func($e, (string) $r['number']), call_user_func($e, $rule ? $rule['name'] : '#' . $r['rule_id']),
                call_user_func($e, trim($r['firstname'] . ' ' . $r['lastname'])), call_user_func($e, (string) $r['mode']),
                nl2br(call_user_func($e, implode("\n", $parts))),
                $guard ? '<br><span class="faded">send guard: ' . call_user_func($e, !empty($guard['ok']) ? 'ok' : implode(', ', (array) ($guard['reasons'] ?? array()))) . '</span>' : '',
                (int) $r['tokens'], $undo);
        }
        return '<h2>Log</h2><p class="faded">Every run of a rule, newest first. Automatic runs can be undone for '
            . self::UNDO_HOURS . ' hours (not sent replies).</p><table class="list" width="100%"><thead><tr><th>When</th><th>Ticket</th>'
            . '<th>Rule</th><th>AI agent</th><th>Mode</th><th>Result</th><th>Tokens</th><th></th></tr></thead><tbody>'
            . ($rows ?: '<tr><td colspan="8">Nothing yet.</td></tr>') . '</tbody></table>';
    }

    private static function quote($v): string
    {
        return "'" . db_real_escape((string) $v) . "'";
    }
}
