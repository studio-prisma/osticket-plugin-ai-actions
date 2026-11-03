<?php

declare(strict_types=1);

/**
 * The one mail about a new ticket, after the AI agent has looked at it.
 *
 *   afterRun   An automatic run assigns without osTicket's alert. Once its
 *              note is posted, the assignee gets osTicket's own "assigned"
 *              alert, with the note as its comments: final status, the AI
 *              agent's assessment, what changed. Tickets the run closed or
 *              hung under a lead ticket stay quiet.
 *   fallback   In the cron: a new ticket nobody got after `fallback_minutes`
 *              (no rule, failed run, limit, proposal mode, AI didn't assign)
 *              goes to the notify agent, through the core's assignment with
 *              its alert, saying why.
 *   digest     Optional weekly mail: what AI agents settled quietly, the
 *              fallbacks, failed jobs.
 *
 * Works with osTicket's "New ticket" alert switched off and no ticket
 * filter assigning on arrival (docs/RULES.md).
 */
final class AiActionsNotify
{
    /** Tickets the fallback looks at per cron run. */
    public const FALLBACK_MAX = 20;

    /** Older tickets are never picked up by the fallback, however long it was off. */
    public const FALLBACK_HOURS = 24;

    public const DIGEST_DAYS = 7;
    public const DIGEST_ROWS = 200;

    // ------------------------------------------------------------ after run

    /**
     * @param array $results the run's result lines (action, ok, unchanged)
     * @return int alerts sent
     */
    public static function afterRun($ticket, array $results, string $text, $note = null): int
    {
        if (!$ticket || !($assignee = self::assignee($ticket, $results)) || self::quiet($ticket)) {
            return 0;
        }
        return self::assignedAlert($ticket, $assignee, $text, $note);
    }

    /** Who this run assigned the ticket to, if anyone: the agent before the team. */
    private static function assignee($ticket, array $results)
    {
        $agent = $team = false;
        foreach ($results as $r) {
            if (empty($r['ok']) || !empty($r['unchanged'])) {
                continue;
            }
            $agent = $agent || ($r['action'] ?? '') === 'assign_agent';
            $team = $team || ($r['action'] ?? '') === 'assign_team';
        }
        if ($agent && ($staff = $ticket->getStaff())) {
            return $staff;
        }
        return $team ? ($ticket->getTeam() ?: null) : null;
    }

    /** Closed, or a sub-ticket of a lead ticket: settled, no mail. */
    private static function quiet($ticket): bool
    {
        return !$ticket->isOpen() || (int) $ticket->getPid() > 0;
    }

    /** What afterRun() decides for a run, without a ticket: '' (no mail), 'agent' or 'team'. Public for the unit tests. */
    public static function wouldMail(array $results, bool $open, bool $child): string
    {
        if (!$open || $child) {
            return '';
        }
        $done = array();
        foreach ($results as $r) {
            if (!empty($r['ok']) && empty($r['unchanged'])) {
                $done[] = $r['action'] ?? '';
            }
        }
        return in_array('assign_agent', $done, true) ? 'agent' : (in_array('assign_team', $done, true) ? 'team' : '');
    }

    /**
     * Ticket::onAssign()'s alert, with the same settings, recipients and
     * template; only the comments come from the run's note.
     */
    private static function assignedAlert($ticket, $assignee, string $text, $note): int
    {
        global $cfg;

        $dept = $ticket->getDept();
        if (!$cfg || !$cfg->alertONAssignment() || !$dept || !$dept->getNumMembersForAlerts()
                || !($tpl = $dept->getTemplate()) || !($email = $dept->getAlertEmail())
                || !($msg = $tpl->getAssignedAlertMsgTemplate())) {
            return 0;
        }
        $recipients = array();
        if ($assignee instanceof Staff) {
            if ($cfg->alertStaffONAssignment()) {
                $recipients[] = $assignee;
            }
        } elseif ($assignee instanceof Team && $assignee->alertsEnabled()) {
            if ($cfg->alertTeamMembersONAssignment() && ($members = $assignee->getMembersForAlerts())) {
                $recipients = array_merge($recipients, is_array($members) ? $members : iterator_to_array($members));
            } elseif ($cfg->alertTeamLeadONAssignment() && ($lead = $assignee->getTeamLead())) {
                $recipients[] = $lead;
            }
        }
        $msg = $ticket->replaceVars($msg->asArray(), array(
            'comments' => self::html($text),
            'assignee' => $assignee,
            'assigner' => $note && method_exists($note, 'getPoster') ? (string) $note->getPoster() : _S('SYSTEM (Auto Assignment)'),
        ));
        $options = $note instanceof ThreadEntry ? array('thread' => $note) : array();
        $sent = array();
        foreach ($recipients as $staff) {
            if (!is_object($staff) || !$staff->isAvailable() || in_array($staff->getEmail(), $sent, true)) {
                continue;
            }
            $alert = $ticket->replaceVars($msg, array('recipient' => $staff));
            $email->sendAlert($staff, $alert['subj'], $alert['body'], null, $options);
            $sent[] = $staff->getEmail();
        }
        return count($sent);
    }

    /** Plain note text as alert HTML. Public for the unit tests. */
    public static function html(string $text): string
    {
        return nl2br(htmlspecialchars(trim($text), ENT_QUOTES, 'UTF-8'), false);
    }

    // ------------------------------------------------------------------ cron

    public static function cron(): void
    {
        $s = AiActionsController::settings();
        if ($s['fallback_on']) {
            self::fallback($s);
        }
        if ($s['digest_on']) {
            self::digest($s);
        }
    }

    /** The agent for fallbacks and the summary: the setting, else the department's manager. */
    private static function target(array $s, $ticket = null)
    {
        $staff = $s['notify_agent'] ? Staff::lookup($s['notify_agent']) : null;
        if (!$staff && $ticket && ($dept = $ticket->getDept())) {
            $staff = $dept->getManager();
        }
        return $staff instanceof Staff && $staff->isAvailable() && !AiActionsAgents::isAgent((int) $staff->getId()) ? $staff : null;
    }

    private static function fallback(array $s): void
    {
        // Only tickets that arrive after the fallback was first switched on.
        $since = AiActionsJobs::state('fallback_since');
        if ($since === null || !ctype_digit($since)) {
            AiActionsJobs::setState('fallback_since', (string) time());
            return;
        }
        AiActionsAutomation::ensure();
        $res = db_query(sprintf("SELECT t.ticket_id FROM `%s` t JOIN `%s` s ON s.id=t.status_id
            WHERE s.state='open' AND t.staff_id=0 AND t.team_id=0 AND (t.ticket_pid IS NULL OR t.ticket_pid=0)
              AND t.created >= FROM_UNIXTIME(%d) AND t.created > NOW() - INTERVAL %d HOUR
              AND t.created < NOW() - INTERVAL %d MINUTE
              AND NOT EXISTS (SELECT 1 FROM `%s` j WHERE j.ticket_id=t.ticket_id AND j.state IN ('queued','running'))
              AND NOT EXISTS (SELECT 1 FROM `%s` l WHERE l.ticket_id=t.ticket_id AND l.mode='fallback')
            ORDER BY t.ticket_id LIMIT %d", TICKET_TABLE, TICKET_STATUS_TABLE, (int) $since, self::FALLBACK_HOURS,
            $s['fallback_minutes'], AiActionsJobs::table(), AiActionsAutomation::logTable(), self::FALLBACK_MAX));
        while ($res && ($row = db_fetch_row($res))) {
            $ticket = Ticket::lookup((int) $row[0]);
            $staff = $ticket ? self::target($s, $ticket) : null;
            if (!$staff) {
                error_log('ai-actions: fallback for ticket ' . (int) $row[0] . ' has nobody to assign to');
                continue;
            }
            $t = AiActionsI18n::strings(AiActionsController::language($staff));
            [$why, $answer] = self::reason((int) $ticket->getId(), $s['auto_paused']);
            $text = self::fallbackText($t, $why, $answer);
            // Logged first: a second cron run must not assign it again.
            AiActionsAutomation::logSystem((int) $ticket->getId(), (int) $staff->getId(), 'fallback', $why);
            $ticket->assignToStaff($staff, self::html($text), true);
        }
    }

    /**
     * Why no AI agent assigned the ticket: [reason code, the model's last answer].
     * Public for the unit tests via fallbackText().
     */
    private static function reason(int $ticketId, bool $paused): array
    {
        if ($paused) {
            return array('paused', '');
        }
        $res = db_query(sprintf("SELECT state, error FROM `%s` WHERE ticket_id=%d AND kind='rule' ORDER BY id DESC LIMIT 1",
            AiActionsJobs::table(), $ticketId));
        $job = $res ? db_fetch_array($res) : null;
        if (!$job) {
            return array('no_rule', '');
        }
        $res = db_query(sprintf("SELECT mode, answer FROM `%s` WHERE ticket_id=%d AND mode IN ('auto','none','propose','skip')
            ORDER BY id DESC LIMIT 1", AiActionsAutomation::logTable(), $ticketId));
        $log = $res ? db_fetch_array($res) : null;
        if ($job['state'] === 'failed') {
            return array('failed:' . (string) $job['error'], '');
        }
        if ((string) $job['error'] !== '' && !$log) {
            return array('skipped:' . (string) $job['error'], '');
        }
        $modes = array('auto' => 'not_assigned', 'none' => 'no_change', 'propose' => 'proposal', 'skip' => 'skipped:closed');
        return $log ? array($modes[$log['mode']] ?? 'no_rule', $log['mode'] === 'skip' ? '' : (string) $log['answer'])
                    : array('no_rule', '');
    }

    /** The fallback's comment: why, then what the AI said, if anything. Public for the unit tests. */
    public static function fallbackText(array $t, string $why, string $answer): string
    {
        $n = $t['notify'];
        [$code, $detail] = array_pad(explode(':', $why, 2), 2, '');
        $line = sprintf($n['fallback'], ($n['reasons'][$code] ?? $code) . ($detail !== '' ? ' (' . $detail . ')' : ''));
        $answer = trim($answer);
        return $answer !== '' ? $line . "\n\n" . $n['ai_said'] . ":\n" . mb_substr($answer, 0, 3000) : $line;
    }

    // ---------------------------------------------------------------- digest

    /** Due when the agent's local weekday and hour are reached and the last one is 6+ days old. Public for the unit tests. */
    public static function digestDue(DateTime $now, int $day, int $hour, int $last): bool
    {
        return (int) $now->format('N') === $day && (int) $now->format('G') >= $hour
            && $now->getTimestamp() - $last >= 6 * 86400;
    }

    private static function digest(array $s): void
    {
        global $cfg;

        $staff = self::target($s);
        if (!$staff) {
            return;
        }
        $last = (int) (AiActionsJobs::state('digest_last') ?? 0);
        if (!self::digestDue(AiActionsTime::now(AiActionsTime::zone($staff)), $s['digest_day'], $s['digest_hour'], $last)) {
            return;
        }
        $email = $cfg ? ($cfg->getAlertEmail() ?: $cfg->getDefaultEmail()) : null;
        if (!$email) {
            return;
        }
        AiActionsJobs::setState('digest_last', (string) time());
        $t = AiActionsI18n::strings(AiActionsController::language($staff));
        [$subject, $body] = self::digestMail(self::digestRows(), $t, AiActionsTime::zone($staff));
        $email->sendAlert($staff, $subject, $body);
    }

    /** @return array<int, array{kind:string, number:string, id:int, subject:string, what:string, when:string}> */
    private static function digestRows(): array
    {
        AiActionsAutomation::ensure();
        $rows = array();
        $res = db_query(sprintf("SELECT l.mode, l.answer, l.results, l.created, t.ticket_id, t.number, t.ticket_pid, s.state, c.subject
            FROM `%s` l JOIN `%s` t ON t.ticket_id=l.ticket_id JOIN `%s` s ON s.id=t.status_id
            LEFT JOIN `%s` c ON c.ticket_id=t.ticket_id
            WHERE l.mode IN ('auto','fallback') AND l.created > NOW() - INTERVAL %d DAY ORDER BY l.id DESC LIMIT %d",
            AiActionsAutomation::logTable(), TICKET_TABLE, TICKET_STATUS_TABLE, TABLE_PREFIX . 'ticket__cdata',
            self::DIGEST_DAYS, self::DIGEST_ROWS));
        $seen = array();
        while ($res && ($r = db_fetch_array($res))) {
            $id = (int) $r['ticket_id'];
            if (isset($seen[$id])) {
                continue;
            }
            if ($r['mode'] === 'fallback') {
                $kind = 'fallback';
                $what = (string) $r['answer'];
            } elseif ($r['state'] === 'closed' || (int) $r['ticket_pid'] > 0) {
                $kind = $r['state'] === 'closed' ? 'closed' : 'linked';
                $parts = array();
                foreach ((array) json_decode((string) $r['results'], true) as $x) {
                    if (!empty($x['ok']) && empty($x['unchanged'])) {
                        $parts[] = ($x['label'] ?? '') . ': ' . ($x['value'] ?? '');
                    }
                }
                $what = implode(' · ', $parts);
            } else {
                continue;
            }
            $seen[$id] = true;
            $rows[] = array('kind' => $kind, 'number' => (string) $r['number'], 'id' => $id,
                            'subject' => (string) $r['subject'], 'what' => $what, 'when' => (string) $r['created']);
        }
        $res = db_query(sprintf("SELECT j.ticket_id, j.error, j.finished, t.number, c.subject FROM `%s` j
            LEFT JOIN `%s` t ON t.ticket_id=j.ticket_id LEFT JOIN `%s` c ON c.ticket_id=j.ticket_id
            WHERE j.state='failed' AND j.finished > NOW() - INTERVAL %d DAY ORDER BY j.id DESC LIMIT %d",
            AiActionsJobs::table(), TICKET_TABLE, TABLE_PREFIX . 'ticket__cdata', self::DIGEST_DAYS, self::DIGEST_ROWS));
        while ($res && ($r = db_fetch_array($res))) {
            $rows[] = array('kind' => 'failed', 'number' => (string) $r['number'], 'id' => (int) $r['ticket_id'],
                            'subject' => (string) $r['subject'], 'what' => (string) $r['error'], 'when' => (string) $r['finished']);
        }
        return $rows;
    }

    /** Subject and HTML body of the weekly summary. Public for the unit tests. */
    public static function digestMail(array $rows, array $t, string $tz): array
    {
        $n = $t['notify'];
        $count = array('closed' => 0, 'linked' => 0, 'fallback' => 0, 'failed' => 0);
        foreach ($rows as $r) {
            $count[$r['kind']] = ($count[$r['kind']] ?? 0) + 1;
        }
        $subject = sprintf($n['digest_subject'], AiActionsController::helpdesk(), $count['closed'] + $count['linked'],
            $count['fallback'], $count['failed']);
        $e = static function ($v): string {
            return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        };
        global $cfg;
        $base = rtrim($cfg && method_exists($cfg, 'getUrl') ? (string) $cfg->getUrl() : '', '/');
        $html = '<p>' . $e(sprintf($n['digest_intro'], self::DIGEST_DAYS)) . '</p>';
        foreach (array('closed', 'linked', 'fallback', 'failed') as $kind) {
            $html .= '<h3>' . $e($n['digest_kinds'][$kind]) . ' (' . $count[$kind] . ')</h3>';
            $lines = '';
            foreach ($rows as $r) {
                if ($r['kind'] !== $kind) {
                    continue;
                }
                $when = AiActionsTime::fromDb($r['when'], AiActionsTime::dbZone(), $tz) ?: $r['when'];
                $label = $r['kind'] === 'fallback'
                    ? (static function () use ($n, $r): string {
                        [$code, $detail] = array_pad(explode(':', $r['what'], 2), 2, '');
                        return ($n['reasons'][$code] ?? $code) . ($detail !== '' ? ' (' . $detail . ')' : '');
                    })()
                    : $r['what'];
                $lines .= sprintf('<tr><td>%s</td><td><a href="%s">#%s</a></td><td>%s</td><td>%s</td></tr>', $e($when),
                    $e($base . '/scp/tickets.php?id=' . $r['id']), $e($r['number']), $e(mb_substr($r['subject'], 0, 100)),
                    $e(mb_substr($label, 0, 300)));
            }
            $html .= $lines !== ''
                ? '<table cellpadding="4" cellspacing="0" border="1" style="border-collapse:collapse">' . $lines . '</table>'
                : '<p>–</p>';
        }
        return array($subject, $html);
    }
}
