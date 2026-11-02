<?php

declare(strict_types=1);

/**
 * Endpoints under /scp/ajax.php/ai:
 *
 *   POST /ai/plan     ticket_id, prompt  -> answer, preview, plan_id
 *   POST /ai/run      plan_id            -> result per change
 *   POST /ai/undo, /ai/discard, /ai/reset
 *   GET  /ai/chat     ticket_id          -> the conversation
 *   GET  /ai/history  ticket_id[, id]    -> earlier conversations, read only
 *   GET  /ai/assets/* the field's CSS and JavaScript
 *
 * Every POST checks, in this order: plugin on, signed-in agent allowed by
 * role, no pending password change / 2FA setup / upgrade, CSRF token, and
 * that the agent can see the ticket. Unknown, invisible and foreign things
 * all get the same 404, so the endpoints don't reveal what exists.
 */
final class AiActionsController
{
    public const PROMPT_MAX = 2000;

    /** The first one is the default. The prefix picks the provider, see provider(). */
    public const MODELS = array('claude-sonnet-5-5', 'claude-opus-5-5', 'gpt-6.1-sol', 'gpt-6.1-terra',
                                'mistral-large-latest', 'mistral-medium-latest');
    public const EFFORTS = array('low', 'medium', 'high');

    /** @var array */
    private static $settings = array('enabled' => false, 'roles' => array(), 'model' => 'claude-opus-5-5',
                                     'effort' => 'low', 'key_source' => 'file', 'key_file' => '', 'daily_requests' => 100,
                                     'daily_tokens' => 500000, 'thread_chars' => 12000, 'history_days' => 90,
                                     'name' => '', 'icon' => 'sparkles', 'image' => '', 'accent' => '', 'welcome' => '',
                                     'allow_send' => false, 'auto_paused' => false, 'ai_notice' => true,
                                     'ai_notice_text' => 'This reply was written by our AI assistant.', 'link_domains' => array(),
                                     'auto_per_hour' => 20, 'fetch_domains' => array());

    /** Icons for the bubble and the window header; "image" uses the image setting. */
    public const ICONS = array('sparkles', 'robot', 'chat', 'bulb', 'bolt', 'initial', 'image');

    /** @var callable|null returns the stored key, decrypted; kept out of $settings on purpose */
    private static $storedKey;

    public static function configure(array $raw, ?callable $storedKey = null): void
    {
        self::$settings = self::normalise($raw);
        self::$storedKey = $storedKey;
        AiActionsChatStore::$keepDays = self::$settings['history_days'];
    }

    public static function settings(): array
    {
        return self::$settings;
    }

    /** Public for the unit tests. */
    public static function normalise(array $raw): array
    {
        $model = self::keyList($raw['model'] ?? null, '/^[a-z0-9.-]+$/');
        $effort = self::keyList($raw['effort'] ?? null, '/^[a-z]+$/');
        return array(
            'enabled'  => !empty($raw['enabled']),
            'roles'    => array_map('intval', self::keyList($raw['roles'] ?? null, '/^[1-9]\d{0,9}$/')),
            'model'    => in_array($model[0] ?? '', self::MODELS, true) ? $model[0] : self::MODELS[0],
            'effort'   => in_array($effort[0] ?? '', self::EFFORTS, true) ? $effort[0] : 'low',
            'key_source' => in_array(self::keyList($raw['key_source'] ?? null, '/^[a-z]+$/')[0] ?? '', AiActionsKey::SOURCES, true)
                ? self::keyList($raw['key_source'], '/^[a-z]+$/')[0] : 'file',
            'key_file' => is_string($raw['key_file'] ?? null) ? trim($raw['key_file']) : '',
            // 0 = no limit, for admins who cap spend in the Anthropic console instead.
            'daily_requests' => self::number($raw['daily_requests'] ?? null, 100, 0, 10000),
            'daily_tokens'   => self::number($raw['daily_tokens'] ?? null, 500000, 0, 100000000),
            'thread_chars'   => self::number($raw['thread_chars'] ?? null, 12000, 2000, 50000),
            // 0 = earlier conversations are not kept.
            'history_days'   => self::number($raw['history_days'] ?? null, 90, 0, 3650),
            'name'    => self::cleanName(is_string($raw['name'] ?? null) ? $raw['name'] : '', ''),
            'icon'    => in_array(self::keyList($raw['icon'] ?? null, '/^[a-z]+$/')[0] ?? '', self::ICONS, true)
                ? self::keyList($raw['icon'], '/^[a-z]+$/')[0] : 'sparkles',
            'image'   => self::imageUrl($raw['image'] ?? null),
            'accent'  => is_string($raw['accent'] ?? null) && preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', trim($raw['accent']))
                ? strtolower(trim($raw['accent'])) : '',
            'welcome' => self::welcome($raw['welcome'] ?? null),
            'allow_send' => !empty($raw['allow_send']),
            'auto_paused' => !empty($raw['auto_paused']),
            // On unless switched off: KI-VO Art. 50(1).
            'ai_notice'   => !array_key_exists('ai_notice', $raw) || $raw['ai_notice'] === null || !empty($raw['ai_notice']),
            'ai_notice_text' => self::welcome($raw['ai_notice_text'] ?? '') ?: 'This reply was written by our AI assistant.',
            'link_domains' => self::domains($raw['link_domains'] ?? ''),
            'auto_per_hour' => self::number($raw['auto_per_hour'] ?? null, 20, 1, 1000),
            'fetch_domains' => self::domains($raw['fetch_domains'] ?? ''),
        );
    }

    /** One domain per line (or comma), lower case, no scheme. Public for the unit tests. */
    public static function domains($raw): array
    {
        $out = array();
        foreach (preg_split('/[\s,;]+/', is_string($raw) ? strtolower($raw) : '') ?: array() as $d) {
            $d = trim((string) preg_replace('#^(https?://)?(www\.)?#', '', $d), " ./");
            if (preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $d)) {
                $out[] = $d;
            }
        }
        return array_values(array_unique(array_slice($out, 0, 50)));
    }

    /**
     * An https:// URL or a path on this server, without characters that
     * could leave an HTML attribute or a CSS url(). Empty otherwise.
     * Public for the unit tests.
     */
    public static function imageUrl($raw): string
    {
        $s = is_string($raw) ? trim($raw) : '';
        if ($s === '' || strlen($s) > 500 || !preg_match('#^(https://[A-Za-z0-9.-]+(:\d+)?/|/(?!/))[A-Za-z0-9._~%!$&*+,;=:@/?-]*$#', $s)) {
            return '';
        }
        return $s;
    }

    /** Welcome text for an empty conversation: plain text, at most $max characters. Public for the unit tests. */
    public static function welcome($raw, int $max = 500): string
    {
        $s = is_string($raw) && preg_match('//u', $raw) ? str_replace("\r\n", "\n", $raw) : '';
        $s = trim((string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]+/u', '', $s));
        if (preg_match_all('/./su', $s) > $max) {
            $s = implode('', array_slice(preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: array(), 0, $max));
        }
        return $s;
    }

    /** Whole number from a setting, $default when empty or not a number, clamped. */
    private static function number($raw, int $default, int $min, int $max): int
    {
        if (is_int($raw)) {
            $raw = (string) $raw;
        }
        if (!is_string($raw) || !preg_match('/^\s*\d{1,12}\s*$/', $raw)) {
            return $default;
        }
        return max($min, min($max, (int) $raw));
    }

    /**
     * Keys of a multi-select setting. osTicket hands them over as
     * key => label, as stored JSON or, from older forms, comma-separated.
     *
     * @return string[]
     */
    public static function keyList($raw, string $pattern): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : array_fill_keys(array_map('trim', explode(',', $raw)), true);
        }
        if (!is_array($raw)) {
            return array();
        }
        $out = array();
        foreach (array_keys($raw) as $key) {
            $key = (string) $key;
            if (preg_match($pattern, $key) && !in_array($key, $out, true)) {
                $out[] = $key;
            }
        }
        return array_slice($out, 0, 100);
    }

    /** May this agent use the field at all? Per-action permissions come later. */
    public static function allowed($staff): bool
    {
        global $ost;

        if (!self::$settings['enabled'] || !($staff instanceof Staff) || !$staff->getId() || !$staff->isValid()) {
            return false;
        }
        if (($ost && $ost->isUpgradePending()) || $staff->forcePasswdChange() || $staff->force2faConfig()) {
            return false;
        }
        return in_array((int) $staff->role_id, self::$settings['roles'], true);
    }

    // ------------------------------------------------------------------

    /** GET /ai/chat?ticket_id=N: the conversation as the chat window shows it. */
    public static function chat(): string
    {
        global $thisstaff;

        header('Cache-Control: no-store');
        if (!self::allowed($thisstaff)) {
            return self::json(404, array('error' => 'not_found'));
        }
        if (self::adminTarget($_GET)) {
            if (!AiActionsAdmin::admin($thisstaff)) {
                return self::json(404, array('error' => 'not_found'));
            }
            return self::json(200, self::adminPayload(AiActionsChatStore::load((int) $thisstaff->getId(), 0)));
        }
        $ticket = self::visibleTicket($thisstaff, $_GET['ticket_id'] ?? 0);
        if (!$ticket) {
            return self::json(404, array('error' => 'not_found'));
        }
        $chat = AiActionsChatStore::load((int) $thisstaff->getId(), (int) $ticket->getId());
        return self::json(200, self::chatPayload($ticket, $chat));
    }

    /** POST /ai/plan: the agent's next message in the conversation. */
    public static function plan(): string
    {
        global $thisstaff;

        if (self::adminTarget($_POST)) {
            return self::adminPlan();
        }
        $ticket = self::guard();
        $prompt = self::promptText($_POST['prompt'] ?? '');
        if ($prompt === null) {
            return self::json(422, array('error' => 'invalid_prompt'));
        }
        $staffId = (int) $thisstaff->getId();
        $ticketId = (int) $ticket->getId();

        // One message at a time per agent, taken before the conversation is
        // read so a second tab can't work on a stale copy. The lock ends with
        // the request (self::json() exits), whatever happens in between.
        if (!AiActionsUsage::lock($staffId)) {
            return self::json(422, array('error' => 'busy'));
        }
        $blocked = AiActionsUsage::blocked(AiActionsUsage::today($staffId),
            self::$settings['daily_requests'], self::$settings['daily_tokens']);
        if ($blocked !== null) {
            return self::json(422, array('error' => $blocked));
        }

        $chat = AiActionsChatStore::load($staffId, $ticketId);
        if ($chat['turns'] >= AiActionsChatStore::MAX_TURNS) {
            return self::json(422, array('error' => 'conversation_full'));
        }
        $llm = self::llm();
        if (!$llm) {
            return self::json(422, array('error' => 'not_configured'));
        }
        // Counted before the call: a failed or slow call still counts, so
        // retries can't run past the limit.
        AiActionsUsage::countRequest($staffId);

        // A new message replaces a plan the agent left open.
        foreach ($chat['transcript'] as $item) {
            $open = $item['plan'] ?? null;
            if ($open && ($open['state'] ?? '') === 'open') {
                $state = AiActionsRunStore::cancel((string) $open['id'], $staffId, 'superseded') ? 'superseded' : 'expired';
                $chat['transcript'] = AiActionsChatStore::markPlan($chat['transcript'], (string) $open['id'], $state, array());
                $chat['pending'][] = array('state' => $state, 'results' => array());
            }
        }

        $t = AiActionsI18n::strings(self::language($thisstaff));
        AiActionsExtensions::bind($ticket, $thisstaff);
        $context = self::context($thisstaff, $ticket, $chat['seen_entry']);
        $choices = AiActionsChoices::forTicket($ticket, $thisstaff);
        $context['options'] = AiActionsChoices::render($choices);
        $blocks = $chat['messages']
            ? AiActionsPlanner::nextMessage($context['data'], $chat['pending'], $prompt, $context['options'])
            : AiActionsPlanner::firstMessage($context, $prompt);
        try {
            $tz = $context['timezone'];
            // Documents and images only for Claude; other providers get a note instead.
            $files = self::provider(self::$settings['model']) === 'anthropic';
            $reader = static function (string $name, array $input) use ($ticket, $thisstaff, $tz, $files) {
                return AiActionsReaders::run($name, $input, $ticket, $thisstaff, $tz, $files);
            };
            $result = (new AiActionsPlanner($llm, $choices, $reader, null, self::$settings['fetch_domains']))->turn(AiActionsPlanner::system($context), $chat['messages'], $blocks);
        } catch (AiActionsLlmError $e) {
            // Status and error type only: the detail never carries the key or the prompt.
            error_log(sprintf('ai-actions: model call failed (%s) %s', $e->kind, $e->getMessage()));
            $code = array('auth' => 'not_configured', 'unavailable' => 'unavailable', 'refused' => 'refused')[$e->kind] ?? 'generic';
            return self::json(422, array('error' => $code));
        }
        AiActionsUsage::addTokens($staffId, (int) $result['usage']['input'], (int) $result['usage']['output']);

        // Stored and shown in the order they will run, so preview line i is result i.
        $result['changes'] = AiActionsTools::inRunOrder($result['changes']);
        $preview = array();
        foreach ($result['changes'] as $c) {
            $preview[] = AiActionsTools::summary($c['action'], $c['input'], $t, $choices);
        }
        $planId = $result['changes'] ? AiActionsRunStore::create($staffId, $ticketId, $prompt, $result['changes']) : null;

        $chat['transcript'][] = array('role' => 'user', 'text' => $prompt);
        $reply = array('role' => 'ai', 'text' => $result['answer']);
        if ($planId) {
            $reply['plan'] = array('id' => $planId, 'changes' => $preview, 'state' => 'open');
        }
        $chat['transcript'][] = $reply;
        $chat['messages'] = $result['messages'];
        $chat['seen_entry'] = $context['last_entry'];
        $chat['pending'] = array();
        $chat['turns']++;
        AiActionsChatStore::save($staffId, $ticketId, $chat);

        return self::json(200, self::chatPayload($ticket, $chat) + array('usage' => $result['usage']));
    }

    public static function run(): string
    {
        global $thisstaff;

        self::guard(false);
        $planId = (string) ($_POST['plan_id'] ?? '');
        $staffId = (int) $thisstaff->getId();
        $run = AiActionsRunStore::claim($planId, $staffId);
        if (!$run) {
            if (($tid = AiActionsRunStore::ticketOf($planId, $staffId)) !== null) {
                AiActionsChatStore::planOutcome($staffId, $tid, $planId, 'expired');
            }
            return self::json(422, array('error' => 'expired'));
        }
        if ((int) $run['ticket_id'] === 0) {
            return self::adminRun($run);
        }
        $ticket = self::visibleTicket($thisstaff, $run['ticket_id']);
        if (!$ticket) {
            AiActionsRunStore::finish($run['id'], array());
            return self::json(404, array('error' => 'not_found'));
        }

        $t = AiActionsI18n::strings(self::language($thisstaff));
        AiActionsExtensions::bind($ticket, $thisstaff);
        $results = array();
        $assigned = false;
        foreach (AiActionsTools::inRunOrder($run['changes']) as $c) {
            // Checked again against what the agent may choose now: the
            // ticket or the agent's rights may have changed since the plan.
            $choices = AiActionsChoices::forTicket($ticket, $thisstaff);
            $input = AiActionsTools::validate((string) ($c['action'] ?? ''), $c['input'] ?? null, $choices);
            $same = $input === null ? AiActionsExecutor::sameStatus($ticket, (string) ($c['action'] ?? ''), $c['input'] ?? null) : null;
            $line = $input !== null
                ? AiActionsTools::summary($c['action'], $input, $t, $choices)
                : ($same ? array('label' => $t['labels']['set_status'], 'value' => $same['value'])
                         : AiActionsTools::rejectedLine((string) ($c['action'] ?? ''), $c['input'] ?? null, $t));
            $r = $input !== null
                ? AiActionsExecutor::run($ticket, $thisstaff, $c['action'], $input, $assigned)
                : ($same ?: array('ok' => false, 'error' => 'rejected'));
            $assigned = $assigned || ($r['ok'] && in_array($c['action'], array('assign_agent', 'assign_team'), true));
            if (!empty($r['created'])) {
                $line['value'] .= "\n→ #" . $r['created'];
            }
            $results[] = $line + array(
                'ok'        => $r['ok'],
                'error'     => $r['error'] ?? null,
                'message'   => $r['message'] ?? '',
                // Server-side only, for undo (see publicResults()).
                'action'    => (string) ($c['action'] ?? ''),
                'unchanged' => !empty($r['unchanged']),
                'previous'  => $r['previous'] ?? null,
            );
            // A change can alter what the agent may do next (e.g. a transfer).
            $ticket = Ticket::lookup($ticket->getId());
            AiActionsExtensions::bind($ticket, $thisstaff);
        }
        AiActionsRunStore::finish($run['id'], $results);
        $public = self::publicResults($results);

        $errors = array();
        $note = $ticket->postNote(array(
            'title' => $t['note_title'],
            'note'  => new TextThreadEntryBody(self::noteBody($run['prompt'], $public, $t)),
        ), $errors, $thisstaff);
        $undoable = (bool) array_filter($results, function ($r) {
            return $r['ok'] && !$r['unchanged'] && AiActionsTools::undoable((string) $r['action']);
        });
        AiActionsChatStore::planOutcome($staffId, (int) $ticket->getId(), $run['id'], 'done', $public,
            $undoable ? array('undo_until' => time() + AiActionsRunStore::UNDO_WINDOW) : array());

        return self::json(200, array('results' => $public, 'note' => $note ? 'saved' : 'failed'));
    }

    /**
     * POST /ai/undo: puts back what a run changed, within UNDO_WINDOW,
     * in reverse order. Notes and failed or unchanged steps are skipped.
     */
    public static function undo(): string
    {
        global $thisstaff;

        self::guard(false);
        $staffId = (int) $thisstaff->getId();
        $run = AiActionsRunStore::claimUndo((string) ($_POST['plan_id'] ?? ''), $staffId);
        if (!$run) {
            return self::json(422, array('error' => 'undo_expired'));
        }
        if ((int) $run['ticket_id'] === 0) {
            return self::adminUndo($run);
        }
        $ticket = self::visibleTicket($thisstaff, $run['ticket_id']);
        if (!$ticket) {
            AiActionsRunStore::finishUndo($run['id'], $run['results'], array());
            return self::json(404, array('error' => 'not_found'));
        }

        $t = AiActionsI18n::strings(self::language($thisstaff));
        $undone = array();
        foreach (array_reverse($run['results']) as $r) {
            if (empty($r['ok']) || !empty($r['unchanged']) || empty($r['action'])
                    || !AiActionsTools::undoable((string) $r['action'])) {
                continue;
            }
            $u = AiActionsExecutor::undo($ticket, $thisstaff, (string) $r['action'], $r['previous'] ?? null);
            $undone[] = array(
                'label'   => (string) ($r['label'] ?? ''),
                'value'   => (string) ($r['value'] ?? ''),
                'ok'      => $u['ok'],
                'error'   => $u['error'] ?? null,
                'message' => $u['message'] ?? '',
            );
            $ticket = Ticket::lookup($ticket->getId());
        }
        AiActionsRunStore::finishUndo($run['id'], $run['results'], $undone);

        $errors = array();
        $ticket->postNote(array(
            'title' => $t['note_undo_title'],
            'note'  => new TextThreadEntryBody(self::undoNoteBody($run['prompt'], $undone, $t)),
        ), $errors, $thisstaff);
        AiActionsChatStore::planOutcome($staffId, (int) $ticket->getId(), $run['id'], 'undone', null, array('undo' => $undone));

        return self::json(200, array('results' => $undone));
    }

    /** Run results without the server-side undo data. Public for the unit tests. */
    public static function publicResults(array $results): array
    {
        return array_map(static function ($r) {
            unset($r['previous'], $r['unchanged'], $r['action']);
            return $r;
        }, $results);
    }

    /** Plain-text note for an undo. Public for the unit tests. */
    public static function undoNoteBody(string $prompt, array $undone, array $t): string
    {
        $lines = array($t['note_request'] . ': ' . $prompt, '', $t['note_undone'] . ':');
        foreach ($undone as $u) {
            $state = $u['ok'] ? $t['note_ok'] : ($t['failed'] . ' (' . ($t['errors'][$u['error'] ?? ''] ?? $u['error'] ?? '') . ')');
            $lines[] = sprintf('- %s: %s (%s)', $u['label'], $u['value'], $state);
        }
        return implode("\n", $lines);
    }

    /** POST /ai/discard: the agent rejects a plan. */
    public static function discard(): string
    {
        global $thisstaff;

        self::guard(false);
        $planId = (string) ($_POST['plan_id'] ?? '');
        $staffId = (int) $thisstaff->getId();
        $tid = AiActionsRunStore::cancel($planId, $staffId, 'discarded');
        if ($tid === null) {
            return self::json(422, array('error' => 'expired'));
        }
        AiActionsChatStore::planOutcome($staffId, $tid, $planId, 'discarded');
        return self::json(200, array('ok' => true));
    }

    /** POST /ai/reset: start the conversation about this ticket over. */
    public static function reset(): string
    {
        global $thisstaff;

        if (self::adminTarget($_POST)) {
            self::guard(false);
            if (!AiActionsAdmin::admin($thisstaff)) {
                return self::json(404, array('error' => 'not_found'));
            }
            $staffId = (int) $thisstaff->getId();
            foreach (AiActionsChatStore::load($staffId, 0)['transcript'] as $item) {
                if (($item['plan']['state'] ?? '') === 'open') {
                    AiActionsRunStore::cancel((string) $item['plan']['id'], $staffId, 'discarded');
                }
            }
            AiActionsChatStore::reset($staffId, 0);
            return self::json(200, self::adminPayload(AiActionsChatStore::load($staffId, 0)));
        }
        $ticket = self::guard();
        $staffId = (int) $thisstaff->getId();
        $chat = AiActionsChatStore::load($staffId, (int) $ticket->getId());
        foreach ($chat['transcript'] as $item) {
            if (($item['plan']['state'] ?? '') === 'open') {
                AiActionsRunStore::cancel((string) $item['plan']['id'], $staffId, 'discarded');
            }
        }
        AiActionsChatStore::reset($staffId, (int) $ticket->getId());
        return self::json(200, self::chatPayload($ticket, AiActionsChatStore::load($staffId, (int) $ticket->getId())));
    }

    /**
     * GET /ai/history?ticket_id=N: the agent's earlier conversations about
     * this ticket; with &id=M one of them, read only.
     */
    public static function history(): string
    {
        global $thisstaff;

        header('Cache-Control: no-store');
        if (!self::allowed($thisstaff)) {
            return self::json(404, array('error' => 'not_found'));
        }
        $ticket = self::visibleTicket($thisstaff, $_GET['ticket_id'] ?? 0);
        if (!$ticket) {
            return self::json(404, array('error' => 'not_found'));
        }
        $staffId = (int) $thisstaff->getId();
        $tz = AiActionsTime::zone($thisstaff);
        $dbTz = AiActionsTime::dbZone();
        $id = is_scalar($_GET['id'] ?? null) ? (int) $_GET['id'] : 0;
        if ($id > 0) {
            $one = AiActionsChatStore::archivedOne($staffId, (int) $ticket->getId(), $id);
            if (!$one) {
                return self::json(404, array('error' => 'not_found'));
            }
            return self::json(200, array(
                'id'         => $id,
                'ended'      => AiActionsTime::fromDb($one['ended'], $dbTz, $tz),
                'transcript' => AiActionsChatStore::closed($one['transcript']),
            ));
        }
        $list = array();
        foreach (AiActionsChatStore::archived($staffId, (int) $ticket->getId()) as $row) {
            $row['ended'] = AiActionsTime::fromDb($row['ended'], $dbTz, $tz);
            $list[] = $row;
        }
        return self::json(200, array('conversations' => $list));
    }

    /** POST /ai/proposal: ticket_id, id, do=run|discard — a human decides on an AI agent's proposal. */
    public static function proposal(): string
    {
        global $thisstaff;

        $ticket = self::guard();
        $do = ($_POST['do'] ?? '') === 'run' ? 'run' : 'discard';
        $r = AiActionsAutomation::decide((int) ($_POST['id'] ?? 0), $do, $thisstaff, $ticket);
        if (!$r['ok']) {
            return self::json(422, array('error' => $r['error'] ?? 'expired'));
        }
        return self::json(200, array('results' => $r['results'] ?? array()));
    }

    /** ticket_id=0 in a request: the admin conversation about settings. */
    private static function adminTarget(array $req): bool
    {
        return isset($req['ticket_id']) && is_scalar($req['ticket_id']) && (string) $req['ticket_id'] === '0';
    }

    private static function adminPayload(array $chat): array
    {
        return array(
            'ticket'     => array('id' => 0, 'number' => ''),
            'admin'      => true,
            'proposals'  => array(),
            'transcript' => AiActionsChatStore::forClient($chat['transcript']),
            'turns_left' => max(0, AiActionsChatStore::MAX_TURNS - (int) $chat['turns']),
        );
    }

    /** POST /ai/plan with ticket_id=0: an admin's request about settings. */
    private static function adminPlan(): string
    {
        global $thisstaff;

        self::guard(false);
        if (!AiActionsAdmin::admin($thisstaff)) {
            return self::json(404, array('error' => 'not_found'));
        }
        $prompt = self::promptText($_POST['prompt'] ?? '');
        if ($prompt === null) {
            return self::json(422, array('error' => 'invalid_prompt'));
        }
        $staffId = (int) $thisstaff->getId();
        if (!AiActionsUsage::lock($staffId)) {
            return self::json(422, array('error' => 'busy'));
        }
        $blocked = AiActionsUsage::blocked(AiActionsUsage::today($staffId), self::$settings['daily_requests'], self::$settings['daily_tokens']);
        if ($blocked !== null) {
            return self::json(422, array('error' => $blocked));
        }
        $chat = AiActionsChatStore::load($staffId, 0);
        if ($chat['turns'] >= AiActionsChatStore::MAX_TURNS) {
            return self::json(422, array('error' => 'conversation_full'));
        }
        $llm = self::llm();
        if (!$llm) {
            return self::json(422, array('error' => 'not_configured'));
        }
        AiActionsUsage::countRequest($staffId);
        foreach ($chat['transcript'] as $item) {
            $open = $item['plan'] ?? null;
            if ($open && ($open['state'] ?? '') === 'open') {
                $state = AiActionsRunStore::cancel((string) $open['id'], $staffId, 'superseded') ? 'superseded' : 'expired';
                $chat['transcript'] = AiActionsChatStore::markPlan($chat['transcript'], (string) $open['id'], $state, array());
                $chat['pending'][] = array('state' => $state, 'results' => array());
            }
        }
        $data = AiActionsAdminTools::data();
        $now = AiActionsTime::now(AiActionsTime::zone($thisstaff))->format('Y-m-d H:i');
        $blocks = $chat['messages']
            ? AiActionsPlanner::nextMessage($data, $chat['pending'], $prompt)
            : array(array('type' => 'text', 'text' => $data), array('type' => 'text', 'text' => "<agent_request>
" . $prompt . "
</agent_request>"));
        try {
            $result = AiActionsPlanner::forAdmin($llm)->turn(AiActionsAdminTools::system(self::helpdesk(), $now), $chat['messages'], $blocks);
        } catch (AiActionsLlmError $e) {
            error_log(sprintf('ai-actions: model call failed (%s) %s', $e->kind, $e->getMessage()));
            $code = array('auth' => 'not_configured', 'unavailable' => 'unavailable', 'refused' => 'refused')[$e->kind] ?? 'generic';
            return self::json(422, array('error' => $code));
        }
        AiActionsUsage::addTokens($staffId, (int) $result['usage']['input'], (int) $result['usage']['output']);
        $preview = array();
        foreach ($result['changes'] as $c) {
            $preview[] = AiActionsAdminTools::summary($c['action'], $c['input']);
        }
        $planId = $result['changes'] ? AiActionsRunStore::create($staffId, 0, $prompt, $result['changes']) : null;
        $chat['transcript'][] = array('role' => 'user', 'text' => $prompt);
        $reply = array('role' => 'ai', 'text' => $result['answer']);
        if ($planId) {
            $reply['plan'] = array('id' => $planId, 'changes' => $preview, 'state' => 'open');
        }
        $chat['transcript'][] = $reply;
        $chat['messages'] = $result['messages'];
        $chat['pending'] = array();
        $chat['turns']++;
        AiActionsChatStore::save($staffId, 0, $chat);
        return self::json(200, self::adminPayload($chat));
    }

    /** Runs a confirmed settings plan: each change checked again, logged in osTicket's system log. */
    private static function adminRun(array $run): string
    {
        global $thisstaff, $ost;

        $staffId = (int) $thisstaff->getId();
        if (!AiActionsAdmin::admin($thisstaff)) {
            AiActionsRunStore::finish($run['id'], array());
            return self::json(404, array('error' => 'not_found'));
        }
        $results = array();
        foreach ($run['changes'] as $c) {
            $input = AiActionsAdminTools::validate((string) ($c['action'] ?? ''), $c['input'] ?? null);
            $line = $input !== null ? AiActionsAdminTools::summary($c['action'], $input) : array('label' => (string) ($c['action'] ?? ''), 'value' => '');
            $r = $input !== null ? AiActionsAdminTools::run($c['action'], $input, $thisstaff) : array('ok' => false, 'error' => 'rejected');
            $results[] = $line + array('ok' => $r['ok'], 'error' => $r['error'] ?? null, 'message' => $r['message'] ?? '',
                                        'action' => (string) ($c['action'] ?? ''), 'unchanged' => false, 'previous' => $r['previous'] ?? null);
            if ($ost && $r['ok']) {
                $ost->logInfo('AI admin change', sprintf('%s by %s: %s', $line['label'], $thisstaff->getUserName(), $line['value']), false);
            }
        }
        AiActionsRunStore::finish($run['id'], $results);
        $public = self::publicResults($results);
        $undoable = (bool) array_filter($results, function ($r) {
            return $r['ok'];
        });
        AiActionsAutomation::logAdmin($staffId, (string) $run['prompt'], $results);
        AiActionsChatStore::planOutcome($staffId, 0, $run['id'], 'done', $public,
            $undoable ? array('undo_until' => time() + AiActionsRunStore::UNDO_WINDOW) : array());
        return self::json(200, array('results' => $public));
    }

    private static function adminUndo(array $run): string
    {
        global $thisstaff;

        $staffId = (int) $thisstaff->getId();
        $undone = array();
        foreach (array_reverse($run['results']) as $r) {
            if (empty($r['ok']) || empty($r['action'])) {
                continue;
            }
            $u = AiActionsAdminTools::undo((string) $r['action'], $r['previous'] ?? null, $thisstaff);
            $undone[] = array('label' => (string) ($r['label'] ?? ''), 'value' => (string) ($r['value'] ?? ''),
                              'ok' => $u['ok'], 'error' => $u['error'] ?? null, 'message' => $u['message'] ?? '');
        }
        AiActionsRunStore::finishUndo($run['id'], $run['results'], $undone);
        AiActionsChatStore::planOutcome($staffId, 0, $run['id'], 'undone', null, array('undo' => $undone));
        return self::json(200, array('results' => $undone));
    }

    /**
     * GET /ai/marks?ids=1,2,3: which of these tickets have an open proposal
     * (the dot on the bubble and on board cards). With a single ID also the
     * pictures of AI agents' thread entries on that ticket. Only tickets
     * the agent can open count; the others are left out silently.
     */
    public static function marks(): string
    {
        global $thisstaff;

        header('Cache-Control: no-store');
        if (!self::allowed($thisstaff)) {
            return self::json(404, array('error' => 'not_found'));
        }
        $raw = is_string($_GET['ids'] ?? null) ? $_GET['ids'] : '';
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', $raw))))), 0, 300);
        $marked = array();
        foreach (AiActionsAutomation::withProposals($ids) as $id) {
            if (self::visibleTicket($thisstaff, $id)) {
                $marked[] = $id;
            }
        }
        $out = array('proposals' => $marked);
        if (count($ids) === 1 && self::visibleTicket($thisstaff, $ids[0])) {
            $out['entries'] = (object) AiActionsAutomation::entryImages($ids[0]);
        }
        return self::json(200, $out);
    }

    /** POST /ai/autoundo: ticket_id, id — undo of an AI agent's automatic run from the ticket's chat. */
    public static function autoUndo(): string
    {
        $ticket = self::guard();
        $ok = AiActionsAutomation::undo((int) ($_POST['id'] ?? 0), (int) $ticket->getId());
        return $ok ? self::json(200, array('ok' => true)) : self::json(422, array('error' => 'undo_expired'));
    }

    private static function chatPayload($ticket, array $chat): array
    {
        return array(
            'runs'       => AiActionsAutomation::recentRuns((int) $ticket->getId()),
            'proposals'  => AiActionsAutomation::proposals((int) $ticket->getId()),
            'ticket'     => array('id' => (int) $ticket->getId(), 'number' => (string) $ticket->getNumber()),
            'transcript' => AiActionsChatStore::forClient($chat['transcript']),
            'turns_left' => max(0, AiActionsChatStore::MAX_TURNS - (int) $chat['turns']),
        );
    }

    public static function asset(string $name): string
    {
        return AiActionsAssets::serve($name);
    }

    // ------------------------------------------------------------------

    /**
     * Common checks for POST endpoints. Returns the ticket from ticket_id
     * when $withTicket is set. Ends the request on failure.
     */
    private static function guard(bool $withTicket = true)
    {
        global $thisstaff, $ost;

        header('Cache-Control: no-store');
        if (!self::allowed($thisstaff)) {
            self::json(404, array('error' => 'not_found'));
        }
        if (!$ost || !$ost->checkCSRFToken()) {
            self::json(400, array('error' => 'csrf'));
        }
        if (!$withTicket) {
            return null;
        }
        $ticket = self::visibleTicket($thisstaff, $_POST['ticket_id'] ?? 0);
        if (!$ticket) {
            self::json(404, array('error' => 'not_found'));
        }
        return $ticket;
    }

    private static function visibleTicket($staff, $rawId)
    {
        $id = is_scalar($rawId) ? (int) $rawId : 0;
        $ticket = $id > 0 ? Ticket::lookup($id) : null;
        return $ticket && $ticket->checkStaffPerm($staff) ? $ticket : null;
    }

    /** Trimmed request text, or null if empty, too long or not UTF-8. Public for the unit tests. */
    public static function promptText($raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $text = trim(str_replace("\r\n", "\n", $raw));
        if ($text === '' || !preg_match('//u', $text) || preg_match_all('/./su', $text) > self::PROMPT_MAX) {
            return null;
        }
        return $text;
    }

    /**
     * The model client. The offline fake is switched on by the server
     * environment (SPA_AI_FAKE=1), never by the request. Otherwise the
     * Claude API, if a key is found; null means "not set up".
     */
    /** The model for the send guard's review: the plugin's model, no tools. */
    public static function reviewer(): ?AiActionsLlm
    {
        return self::llm();
    }

    /**
     * An AI agent plans its rule's task on a ticket, without a chat: the
     * rule's task is the request, its allowed actions are the only tools.
     *
     * @return array{answer:string, changes:array, preview:array, tokens:int}|array{error:string}
     */
    public static function agentPlan($agent, $ticket, array $rule, array $info): array
    {
        $llm = self::llm($info['model'] ?: null, $agent);
        if (!$llm) {
            return array('error' => 'not_configured');
        }
        $agentId = (int) $agent->getId();
        $blocked = AiActionsUsage::blocked(AiActionsUsage::today($agentId), self::$settings['daily_requests'], self::$settings['daily_tokens']);
        if ($blocked !== null) {
            return array('error' => $blocked);
        }
        AiActionsUsage::countRequest($agentId);
        $context = self::context($agent, $ticket, 0);
        $choices = AiActionsChoices::forTicket($ticket, $agent, in_array('send_reply', $rule['actions'], true));
        $context['options'] = AiActionsChoices::render($choices);
        $tz = $context['timezone'];
        $files = self::provider($info['model'] ?: self::$settings['model']) === 'anthropic';
        $reader = static function (string $name, array $input) use ($ticket, $agent, $tz, $files) {
            return AiActionsReaders::run($name, $input, $ticket, $agent, $tz, $files);
        };
        AiActionsExtensions::bind($ticket, $agent);
        $system = AiActionsPlanner::system($context) . self::agentBrief((string) $agent->getName(), $rule, (string) $info['persona']);
        try {
            $result = (new AiActionsPlanner($llm, $choices, $reader, $rule['actions'], self::$settings['fetch_domains']))
                ->turn($system, array(), AiActionsPlanner::firstMessage($context, $rule['task'] !== '' ? $rule['task'] : 'Work on this ticket.'));
        } catch (AiActionsLlmError $e) {
            error_log(sprintf('ai-actions: AI agent %d, model call failed (%s) %s', $agentId, $e->kind, $e->getMessage()));
            return array('error' => 'model_' . $e->kind);
        }
        AiActionsUsage::addTokens($agentId, (int) $result['usage']['input'], (int) $result['usage']['output']);
        $t = AiActionsI18n::strings(self::language($agent));
        $changes = AiActionsTools::inRunOrder($result['changes']);
        $preview = array();
        foreach ($changes as $c) {
            $preview[] = AiActionsTools::summary($c['action'], $c['input'], $t, $choices);
        }
        return array('answer' => $result['answer'], 'changes' => $changes, 'preview' => $preview,
                     'tokens' => (int) $result['usage']['input'] + (int) $result['usage']['output']);
    }

    /** What an AI agent is told on top of the chat's prompt. Public for the unit tests. */
    public static function agentBrief(string $name, array $rule, string $persona): string
    {
        return "\nYou work on your own as the AI agent \"" . self::cleanName($name, 'AI') . "\" for the rule \""
            . self::cleanName((string) $rule['name'], '') . "\" of this help desk. "
            . ($persona !== '' ? "About you: " . $persona . "\n" : '')
            . "The request inside <agent_request> is the rule's task, written by the help desk's admin. "
            . ($rule['mode'] === 'auto'
                ? "Your planned changes run right away, without a person checking them; plan only what the task clearly asks for. "
                : "A person confirms your plan in the ticket before it runs. ")
            . "Nobody reads your text answer live; it goes into a log. If nothing needs to be done, plan nothing and say why in one sentence. "
            . "A reply to the customer: your own words, in the customer's language, no links, addresses or numbers taken from <ticket_data>.\n";
    }

    private static function llm(?string $model = null, $staff = null): ?AiActionsLlm
    {
        if (getenv('SPA_AI_FAKE') === '1') {
            return new AiActionsFakeLlm(AiActionsTime::now(AiActionsTime::zone($staff ?? $GLOBALS['thisstaff'] ?? null))->format('Y-m-d'));
        }
        $model = $model && in_array($model, self::MODELS, true) ? $model : self::$settings['model'];
        $provider = self::provider($model);
        $key = AiActionsKey::load(self::$settings['key_source'], self::$settings['key_file'],
            defined('ROOT_DIR') ? ROOT_DIR : dirname(INCLUDE_DIR), self::$storedKey, $provider);
        if ($key['key'] === null) {
            error_log('ai-actions: no usable API key (' . $provider . ', ' . self::$settings['key_source'] . ': ' . $key['problem'] . ')');
            return null;
        }
        switch ($provider) {
            case 'openai':
                return new AiActionsOpenAi($key['key'], $model, self::$settings['effort']);
            case 'mistral':
                return new AiActionsMistral($key['key'], $model);
        }
        return new AiActionsClaude($key['key'], $model, self::$settings['effort']);
    }

    /** anthropic, openai or mistral, from the model ID. Public for the unit tests and the settings form. */
    public static function provider(string $model): string
    {
        if (strpos($model, 'gpt-') === 0) {
            return 'openai';
        }
        if (strpos($model, 'mistral-') === 0) {
            return 'mistral';
        }
        return 'anthropic';
    }

    private static function context($staff, $ticket, int $seenEntry = 0): array
    {
        $tz = AiActionsTime::zone($staff);
        $now = AiActionsTime::now($tz);
        $data = AiActionsContext::data($ticket, $staff, $tz, $seenEntry, self::$settings['thread_chars']);
        return array(
            'now'        => $now->format('Y-m-d H:i'),
            'weekday'    => $now->format('l'),
            'timezone'   => $tz,
            'utc_offset' => $now->format('P'),
            'helpdesk'   => self::helpdesk(),
            'data'       => $data['text'],
            'last_entry' => $data['last'],
        );
    }

    /**
     * The help desk's name from Settings → System (Helpdesk Name/Title), so
     * the model and the window speak of "Example Service" rather than
     * "osTicket". Public for the unit tests via cleanName().
     */
    public static function helpdesk(): string
    {
        global $cfg;

        return self::cleanName($cfg && method_exists($cfg, 'getTitle') ? (string) $cfg->getTitle() : '', 'osTicket');
    }

    /** One line, no markup characters, at most 60 characters; $default when empty. */
    public static function cleanName(string $raw, string $default): string
    {
        $s = preg_match('//u', $raw) ? $raw : '';
        $s = trim((string) preg_replace('/[\x00-\x1F\x7F<>]+/u', ' ', $s));
        $s = (string) preg_replace('/\s+/u', ' ', $s);
        if (preg_match_all('/./su', $s) > 60) {
            $s = implode('', array_slice(preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: array(), 0, 60));
        }
        return $s !== '' ? $s : $default;
    }

    /** Plain-text note body. Public for the unit tests. */
    public static function noteBody(string $prompt, array $results, array $t): string
    {
        $lines = array($t['note_request'] . ': ' . $prompt, '', $t['note_changes'] . ':');
        foreach ($results as $r) {
            $state = $r['ok'] ? $t['note_ok'] : ($t['failed'] . ' (' . ($t['errors'][$r['error'] ?? ''] ?? $r['error'] ?? '') . ')');
            $lines[] = sprintf('- %s: %s (%s)', $r['label'], $r['value'], $state);
        }
        return implode("\n", $lines);
    }

    public static function language($staff): string
    {
        if ($staff instanceof Staff && ($lang = (string) $staff->getLanguage()) !== '') {
            return $lang;
        }
        return class_exists('Internationalization') ? (string) Internationalization::getCurrentLanguage() : 'en';
    }

    /**
     * Sends the response and ends the request. Returning the JSON instead
     * would lose the status code: scp/ajax.php answers every string that
     * starts with "{" with Http::response(200, ...).
     */
    private static function json(int $code, array $payload): string
    {
        header('X-Content-Type-Options: nosniff');
        Http::response($code, (string) json_encode($payload, JSON_UNESCAPED_UNICODE), 'application/json');
        exit;
    }
}
