<?php

declare(strict_types=1);

/**
 * Endpoints under /scp/ajax.php/ai:
 *
 *   POST /ai/plan     ticket_id, prompt  -> answer, preview, plan_id
 *   POST /ai/run      plan_id            -> result per change
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

    public const MODELS = array('claude-opus-5-5', 'claude-sonnet-5-5');
    public const EFFORTS = array('low', 'medium', 'high');

    /** @var array */
    private static $settings = array('enabled' => false, 'roles' => array(), 'model' => 'claude-opus-5-5',
                                     'effort' => 'low', 'key_file' => '', 'daily_requests' => 100,
                                     'daily_tokens' => 500000, 'thread_chars' => 12000);

    public static function configure(array $raw): void
    {
        self::$settings = self::normalise($raw);
    }

    public static function settings(): array
    {
        return self::$settings;
    }

    /** Public for the unit tests. */
    public static function normalise(array $raw): array
    {
        $model = self::keyList($raw['model'] ?? null, '/^claude-[a-z0-9-]+$/');
        $effort = self::keyList($raw['effort'] ?? null, '/^[a-z]+$/');
        return array(
            'enabled'  => !empty($raw['enabled']),
            'roles'    => array_map('intval', self::keyList($raw['roles'] ?? null, '/^[1-9]\d{0,9}$/')),
            'model'    => in_array($model[0] ?? '', self::MODELS, true) ? $model[0] : self::MODELS[0],
            'effort'   => in_array($effort[0] ?? '', self::EFFORTS, true) ? $effort[0] : 'low',
            'key_file' => is_string($raw['key_file'] ?? null) ? trim($raw['key_file']) : '',
            // 0 = no limit, for admins who cap spend in the Anthropic console instead.
            'daily_requests' => self::number($raw['daily_requests'] ?? null, 100, 0, 10000),
            'daily_tokens'   => self::number($raw['daily_tokens'] ?? null, 500000, 0, 100000000),
            'thread_chars'   => self::number($raw['thread_chars'] ?? null, 12000, 2000, 50000),
        );
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
        $context = self::context($thisstaff, $ticket, $chat['seen_entry']);
        $choices = AiActionsChoices::forTicket($ticket, $thisstaff);
        $context['options'] = AiActionsChoices::render($choices);
        $blocks = $chat['messages']
            ? AiActionsPlanner::nextMessage($context['data'], $chat['pending'], $prompt, $context['options'])
            : AiActionsPlanner::firstMessage($context, $prompt);
        try {
            $result = (new AiActionsPlanner($llm, $choices))->turn(AiActionsPlanner::system($context), $chat['messages'], $blocks);
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
        $ticket = self::visibleTicket($thisstaff, $run['ticket_id']);
        if (!$ticket) {
            AiActionsRunStore::finish($run['id'], array());
            return self::json(404, array('error' => 'not_found'));
        }

        $t = AiActionsI18n::strings(self::language($thisstaff));
        $results = array();
        $assigned = false;
        foreach (AiActionsTools::inRunOrder($run['changes']) as $c) {
            // Checked again against what the agent may choose now: the
            // ticket or the agent's rights may have changed since the plan.
            $choices = AiActionsChoices::forTicket($ticket, $thisstaff);
            $input = AiActionsTools::validate((string) ($c['action'] ?? ''), $c['input'] ?? null, $choices);
            $line = $input !== null
                ? AiActionsTools::summary($c['action'], $input, $t, $choices)
                : array('label' => $t['labels'][$c['action'] ?? ''] ?? (string) ($c['action'] ?? ''), 'value' => '');
            $r = $input !== null
                ? AiActionsExecutor::run($ticket, $thisstaff, $c['action'], $input, $assigned)
                : array('ok' => false, 'error' => 'rejected');
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
        }
        AiActionsRunStore::finish($run['id'], $results);
        $public = self::publicResults($results);

        $errors = array();
        $note = $ticket->postNote(array(
            'title' => $t['note_title'],
            'note'  => new TextThreadEntryBody(self::noteBody($run['prompt'], $public, $t)),
        ), $errors, $thisstaff);
        $undoable = (bool) array_filter($results, function ($r) {
            return $r['ok'] && !$r['unchanged'] && !in_array($r['action'], AiActionsTools::NO_UNDO, true);
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
        $ticket = self::visibleTicket($thisstaff, $run['ticket_id']);
        if (!$ticket) {
            AiActionsRunStore::finishUndo($run['id'], $run['results'], array());
            return self::json(404, array('error' => 'not_found'));
        }

        $t = AiActionsI18n::strings(self::language($thisstaff));
        $undone = array();
        foreach (array_reverse($run['results']) as $r) {
            if (empty($r['ok']) || !empty($r['unchanged']) || empty($r['action'])
                    || in_array($r['action'], AiActionsTools::NO_UNDO, true)) {
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

    private static function chatPayload($ticket, array $chat): array
    {
        return array(
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
    private static function llm(): ?AiActionsLlm
    {
        if (getenv('SPA_AI_FAKE') === '1') {
            return new AiActionsFakeLlm(self::now(self::timezone($GLOBALS['thisstaff']))->format('Y-m-d'));
        }
        $key = AiActionsKey::load(self::$settings['key_file'], defined('ROOT_DIR') ? ROOT_DIR : dirname(INCLUDE_DIR));
        if ($key['key'] === null) {
            error_log('ai-actions: no usable API key (' . $key['problem'] . ')');
            return null;
        }
        return new AiActionsClaude($key['key'], self::$settings['model'], self::$settings['effort']);
    }

    private static function context($staff, $ticket, int $seenEntry = 0): array
    {
        $tz = self::timezone($staff);
        $now = self::now($tz);
        $data = AiActionsContext::data($ticket, $seenEntry, self::$settings['thread_chars']);
        return array(
            'now'        => $now->format('Y-m-d H:i'),
            'weekday'    => $now->format('l'),
            'timezone'   => $tz,
            'data'       => $data['text'],
            'last_entry' => $data['last'],
        );
    }

    private static function timezone($staff): string
    {
        global $cfg;

        $candidates = array();
        if ($staff instanceof Staff) {
            $candidates[] = (string) $staff->getTimezone();
        }
        if ($cfg && method_exists($cfg, 'getDefaultTimezone')) {
            $candidates[] = (string) $cfg->getDefaultTimezone();
        }
        foreach ($candidates as $tz) {
            if ($tz !== '' && in_array($tz, DateTimeZone::listIdentifiers(), true)) {
                return $tz;
            }
        }
        return 'UTC';
    }

    private static function now(string $tz): DateTime
    {
        return new DateTime('now', new DateTimeZone($tz));
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
