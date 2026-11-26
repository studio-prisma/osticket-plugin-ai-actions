<?php

declare(strict_types=1);

/**
 * Tells the admin when AI agents stop working, instead of leaving it to the
 * fallback's note in each ticket.
 *
 * A rule run that fails for a reason that won't fix itself (daily limit,
 * no key, refused key, no access to the ticket) sends osTicket's admin
 * alert and writes a warning to the system log: once per AI agent, reason
 * and day, however many tickets fail after it. Passing model outages are
 * left to the fallback.
 */
final class AiActionsAlert
{
    /** Errors worth a mail: they repeat on every ticket until someone acts. */
    public const ALERTS = array('limit_requests', 'limit_tokens', 'not_configured', 'agent_no_access',
                                'model_auth', 'model_bad_request', 'model_refused');

    public const SECTION = 'plugin.ai-actions.alerts';

    public static function wanted(string $error): bool
    {
        return in_array($error, self::ALERTS, true);
    }

    /**
     * True if this agent and reason haven't alerted on $day yet; remembers it.
     * $seen is the stored day per key. Public for the unit tests.
     */
    public static function firstToday(array &$seen, int $agentId, string $error, string $day): bool
    {
        $key = $agentId . ':' . $error;
        if (($seen[$key] ?? '') === $day) {
            return false;
        }
        $seen[$key] = $day;
        return true;
    }

    /** Subject and body of the admin mail. Public for the unit tests. */
    public static function text(string $agent, string $rule, string $error, array $used, array $limits, int $ticket): array
    {
        $why = array(
            'limit_tokens'      => sprintf('it used %s of its %s tokens for today', number_format($used['tokens']),
                number_format($limits['tokens'])),
            'limit_requests'    => sprintf('it made %s of its %s runs for today', number_format($used['requests']),
                number_format($limits['requests'])),
            'not_configured'    => 'no API key was found',
            'agent_no_access'   => 'its role or department doesn\'t give it access to the ticket',
            'model_auth'        => 'the AI provider refused the API key',
            'model_bad_request' => 'the AI provider rejected the request',
            'model_refused'     => 'the model refused the request',
        );
        $fix = array(
            'limit_tokens'      => 'Runs fail until midnight (database time). Raise "AI agents: tokens" in the plugin settings to continue today.',
            'limit_requests'    => 'Runs fail until midnight (database time). Raise "AI agents: runs" in the plugin settings to continue today.',
            'not_configured'    => 'Check the key source in the plugin settings.',
            'agent_no_access'   => 'Give the AI agent the department under Agents, or narrow the rule.',
            'model_auth'        => 'Check or replace the API key.',
            'model_bad_request' => 'The web server\'s error log has the details.',
            'model_refused'     => 'The rule\'s log has the details.',
        );
        $subject = sprintf('AI Actions: %s stopped working (%s)', $agent, $error);
        $body = sprintf("The AI agent \"%s\" could not run the rule \"%s\" on ticket id %d: %s.\n\n%s\n\n"
            . "Until then new tickets are assigned by the fallback, without the AI's assessment. "
            . "This mail comes once per AI agent, reason and day; see Admin panel → AI Actions → Log and Status.",
            $agent, $rule, $ticket, $why[$error] ?? $error, $fix[$error] ?? '');
        return array($subject, $body);
    }

    /** Called by the automation after a failed run. */
    public static function failed(string $error, $agent, array $rule, int $ticketId): void
    {
        global $ost;

        if (!self::wanted($error) || !$agent) {
            return;
        }
        $agentId = (int) $agent->getId();
        $store = class_exists('Config') ? new Config(self::SECTION) : null;
        $seen = array();
        if ($store) {
            $seen = json_decode((string) $store->get('seen', '{}'), true) ?: array();
        }
        if (!self::firstToday($seen, $agentId, $error, date('Y-m-d'))) {
            return;
        }
        // Forget other days, so the entry stays small.
        $today = date('Y-m-d');
        $seen = array_filter($seen, static function ($d) use ($today) { return $d === $today; });
        if ($store) {
            $store->set('seen', json_encode($seen));
        }
        $s = AiActionsController::settings();
        [$subject, $body] = self::text((string) $agent->getName(), (string) ($rule['name'] ?? ''), $error,
            AiActionsUsage::today($agentId),
            array('requests' => (int) $s['agent_daily_requests'], 'tokens' => (int) $s['agent_daily_tokens']), $ticketId);
        error_log('ai-actions: ' . $subject);
        if ($ost) {
            // Mails the admin address and logs a warning in the system log.
            $ost->alertAdmin($subject, $body, true);
        }
    }

    /**
     * AI agents at or over a daily limit today, for the Status tab.
     *
     * @return array<int, array{name:string, what:string}>
     */
    public static function limited(): array
    {
        $s = AiActionsController::settings();
        $out = array();
        foreach (AiActionsAgents::all() as $a) {
            if (!$a['active']) {
                continue;
            }
            $used = AiActionsUsage::today($a['staff_id']);
            $blocked = AiActionsUsage::blocked($used, (int) $s['agent_daily_requests'], (int) $s['agent_daily_tokens']);
            if ($blocked === 'limit_tokens') {
                $out[] = array('name' => $a['name'], 'what' => sprintf('%s of %s tokens', number_format($used['tokens']),
                    number_format((int) $s['agent_daily_tokens'])));
            } elseif ($blocked === 'limit_requests') {
                $out[] = array('name' => $a['name'], 'what' => sprintf('%s of %s runs', number_format($used['requests']),
                    number_format((int) $s['agent_daily_requests'])));
            }
        }
        return $out;
    }
}
