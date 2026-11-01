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

    /** @var array */
    private static $settings = array('enabled' => false, 'roles' => array());

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
        return array(
            'enabled' => !empty($raw['enabled']),
            'roles'   => array_map('intval', self::keyList($raw['roles'] ?? null, '/^[1-9]\d{0,9}$/')),
        );
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

    public static function plan(): string
    {
        global $thisstaff;

        $ticket = self::guard();
        $prompt = self::promptText($_POST['prompt'] ?? '');
        if ($prompt === null) {
            return self::json(422, array('error' => 'invalid_prompt'));
        }
        $llm = self::llm();
        if (!$llm) {
            return self::json(422, array('error' => 'not_configured'));
        }

        $t = AiActionsI18n::strings(self::language($thisstaff));
        $result = (new AiActionsPlanner($llm))->plan(self::context($thisstaff, $ticket), $prompt);

        $preview = array();
        foreach ($result['changes'] as $c) {
            $preview[] = AiActionsTools::summary($c['action'], $c['input'], $t);
        }
        $planId = $result['changes']
            ? AiActionsRunStore::create((int) $thisstaff->getId(), (int) $ticket->getId(), $prompt, $result['changes'])
            : null;

        return self::json(200, array(
            'answer'   => $result['answer'],
            'changes'  => $preview,
            'plan_id'  => $planId,
            'rejected' => $result['rejected'],
        ));
    }

    public static function run(): string
    {
        global $thisstaff;

        self::guard(false);
        $run = AiActionsRunStore::claim((string) ($_POST['plan_id'] ?? ''), (int) $thisstaff->getId());
        if (!$run) {
            return self::json(422, array('error' => 'expired'));
        }
        $ticket = self::visibleTicket($thisstaff, $run['ticket_id']);
        if (!$ticket) {
            AiActionsRunStore::finish($run['id'], array());
            return self::json(404, array('error' => 'not_found'));
        }

        $t = AiActionsI18n::strings(self::language($thisstaff));
        $results = array();
        foreach ($run['changes'] as $c) {
            $input = AiActionsTools::validate((string) ($c['action'] ?? ''), $c['input'] ?? null);
            $line = $input !== null
                ? AiActionsTools::summary($c['action'], $input, $t)
                : array('label' => (string) ($c['action'] ?? ''), 'value' => '');
            $r = $input !== null
                ? AiActionsExecutor::run($ticket, $thisstaff, $c['action'], $input)
                : array('ok' => false, 'error' => 'rejected');
            $results[] = $line + array(
                'ok'      => $r['ok'],
                'error'   => $r['error'] ?? null,
                'message' => $r['message'] ?? '',
            );
            // A change can alter what the agent may do next (e.g. a transfer).
            $ticket = Ticket::lookup($ticket->getId());
        }
        AiActionsRunStore::finish($run['id'], $results);

        $errors = array();
        $note = $ticket->postNote(array(
            'title' => $t['note_title'],
            'note'  => new TextThreadEntryBody(self::noteBody($run['prompt'], $results, $t)),
        ), $errors, $thisstaff);

        return self::json(200, array('results' => $results, 'note' => $note ? 'saved' : 'failed'));
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
     * The model client. Slice 1 only knows the offline fake, switched on by
     * the server environment, never by the request.
     */
    private static function llm(): ?AiActionsLlm
    {
        if (getenv('SPA_AI_FAKE') === '1') {
            return new AiActionsFakeLlm(self::now(self::timezone($GLOBALS['thisstaff']))->format('Y-m-d'));
        }
        return null;
    }

    private static function context($staff, $ticket): array
    {
        $tz = self::timezone($staff);
        $now = self::now($tz);
        return array(
            'now'      => $now->format('Y-m-d H:i'),
            'weekday'  => $now->format('l'),
            'timezone' => $tz,
            'ticket'   => array('number' => (string) $ticket->getNumber()),
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
        Http::response($code, (string) json_encode($payload, JSON_UNESCAPED_UNICODE), 'application/json');
        exit;
    }
}
