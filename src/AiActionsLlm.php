<?php

declare(strict_types=1);

/**
 * One request to a language model, in a provider-neutral shape.
 *
 * send() returns:
 *   stop     'tool_use' | 'end_turn' | 'max_tokens' | 'refusal'
 *   content  list of ['type' => 'text', 'text' => ...]
 *            and ['type' => 'tool_use', 'id' => ..., 'name' => ..., 'input' => array]
 *   usage    ['input' => int, 'output' => int]
 */
interface AiActionsLlm
{
    public function send(string $system, array $messages, array $tools): array;
}

/**
 * Deterministic stand-in for the tests. Understands a few words, each one
 * planning one change with a value from <ticket_options>:
 *   "due" / "fällig"              due date tomorrow 17:00
 *   "philipp"                     assign to the first agent named Philipp
 *   "close" / "schließ"           status "Closed"
 *   "priority high" / "hoch"      priority "High"
 *   "sales"                       transfer to the Sales department
 *   "note" / "notiz"             an internal note
 *   "draft" / "entwurf"          a reply draft (with markup, to prove it is escaped)
 *   "send" / "sende"             a sent reply (only when offered)
 *   "sub-ticket" / "unterticket" a sub-ticket
 * Everything else gets a fixed answer.
 *
 * Only used when the server environment sets SPA_AI_FAKE=1 (the local test
 * stack does). Nothing in a request can switch it on.
 */
final class AiActionsFakeLlm implements AiActionsLlm
{
    /** @var string Y-m-d in the agent's time zone */
    private $today;

    public function __construct(string $today)
    {
        $this->today = $today;
    }

    public function send(string $system, array $messages, array $tools): array
    {
        $last = end($messages);
        $usage = array('input' => 0, 'output' => 0);

        $prompt = '';
        $outcome = '';
        $options = '';
        $toolResult = false;
        foreach ((array) ($last['content'] ?? array()) as $block) {
            $type = $block['type'] ?? '';
            $text = (string) ($block['text'] ?? '');
            if ($type === 'text' && strpos($text, '<agent_request>') === 0) {
                $prompt = $text;
            } elseif ($type === 'text' && strpos($text, '<plan_result>') === 0) {
                $outcome = $text;
            } elseif ($type === 'text' && strpos($text, '<ticket_options>') === 0) {
                $options = $text;
            } elseif ($type === 'tool_result') {
                $toolResult = true;
            }
        }

        // Second round: the planner answered the tool calls.
        if ($prompt === '' && $toolResult) {
            return array('stop' => 'end_turn', 'content' => array(
                array('type' => 'text', 'text' => 'Planned. Please check the preview.'),
            ), 'usage' => $usage);
        }

        // Lets the tests see that the outcome of the last plan arrived.
        if ($outcome !== '' && preg_match('/\b(previous|vorher)/iu', $prompt)) {
            preg_match('/The agent (confirmed|discarded|wrote a new message)/', $outcome, $m);
            return array('stop' => 'end_turn', 'content' => array(
                array('type' => 'text', 'text' => 'Previous plan: ' . ($m[1] ?? 'unknown') . '.'),
            ), 'usage' => $usage);
        }

        $calls = array();
        if (preg_match('/\b(due|fällig)/iu', $prompt)) {
            $tomorrow = (new DateTime($this->today))->modify('+1 day')->format('Y-m-d');
            $calls[] = array('set_due_date', array('due' => $tomorrow . ' 17:00'));
        }
        $wants = array(
            array('/philipp/iu', 'assign_agent', 'agent', '/^Philipp/i'),
            array('/\b(close|schließ)/iu', 'set_status', 'status', '/^Closed/i'),
            array('/\b(priority high|hoch)/iu', 'set_priority', 'priority', '/^High/i'),
            array('/\bsales\b/iu', 'transfer', 'department', '/^Sales/i'),
        );
        if (preg_match('/\b(note|notiz)/iu', $prompt)) {
            $calls[] = array('add_internal_note', array('text' => 'Fake note: customer called back.'));
        }
        if (preg_match('/\b(send|sende)/iu', $prompt) && strpos($options, 'send_reply:') !== false) {
            $calls[] = array('send_reply', array('text' => "Hello,\n\nyour request is solved.\n\nBest regards"));
        }
        if (preg_match('/\b(draft|entwurf)/iu', $prompt)) {
            $calls[] = array('draft_reply', array('text' => "Hello,\n\nthanks for your message. <b>We are on it.</b>\n\nBest regards"));
        }
        if (preg_match('/\b(sub-?ticket|unterticket)/iu', $prompt)) {
            $calls[] = array('create_subticket', array('subject' => 'Fake sub-ticket', 'message' => 'Split off from the parent.'));
        }
        foreach ($wants as $w) {
            if (preg_match($w[0], $prompt) && ($value = self::option($options, $w[1], $w[3])) !== null) {
                $calls[] = array($w[1], array($w[2] => $value));
            }
        }
        if ($calls) {
            $content = array();
            foreach ($calls as $i => $c) {
                $content[] = array('type' => 'tool_use', 'id' => 'fake_' . ($i + 1), 'name' => $c[0], 'input' => $c[1]);
            }
            return array('stop' => 'tool_use', 'content' => $content, 'usage' => $usage);
        }

        return array('stop' => 'end_turn', 'content' => array(
            array('type' => 'text', 'text' => 'Offline test model: no changes planned.'),
        ), 'usage' => $usage);
    }

    /** First value under "$tool:" in the options block whose label matches. */
    private static function option(string $options, string $tool, string $label): ?string
    {
        $section = '';
        foreach (preg_split('/\R/', $options) as $line) {
            if (preg_match('/^([a-z_]+):$/', $line, $m)) {
                $section = $m[1];
            } elseif ($section === $tool && preg_match('/^- (\S+): (.*)$/', $line, $m) && preg_match($label, $m[2])) {
                return $m[1];
            }
        }
        return null;
    }
}
