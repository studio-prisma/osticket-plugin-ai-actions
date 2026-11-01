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
 * Deterministic stand-in for the tests. Understands one thing: a request that
 * mentions a due date ("due", "fällig") plans tomorrow 17:00. Everything else
 * gets a fixed answer.
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
        $toolResult = false;
        foreach ((array) ($last['content'] ?? array()) as $block) {
            $type = $block['type'] ?? '';
            $text = (string) ($block['text'] ?? '');
            if ($type === 'text' && strpos($text, '<agent_request>') === 0) {
                $prompt = $text;
            } elseif ($type === 'text' && strpos($text, '<plan_result>') === 0) {
                $outcome = $text;
            } elseif ($type === 'tool_result') {
                $toolResult = true;
            }
        }

        // Second round: the planner answered the tool call.
        if ($prompt === '' && $toolResult) {
            return array('stop' => 'end_turn', 'content' => array(
                array('type' => 'text', 'text' => 'Planned the due date for tomorrow, 17:00.'),
            ), 'usage' => $usage);
        }

        // Lets the tests see that the outcome of the last plan arrived.
        if ($outcome !== '' && preg_match('/\b(previous|vorher)/iu', $prompt)) {
            preg_match('/The agent (confirmed|discarded|wrote a new message)/', $outcome, $m);
            return array('stop' => 'end_turn', 'content' => array(
                array('type' => 'text', 'text' => 'Previous plan: ' . ($m[1] ?? 'unknown') . '.'),
            ), 'usage' => $usage);
        }

        if (preg_match('/\b(due|fällig)/iu', $prompt)) {
            $tomorrow = (new DateTime($this->today))->modify('+1 day')->format('Y-m-d');
            return array('stop' => 'tool_use', 'content' => array(
                array('type' => 'tool_use', 'id' => 'fake_1', 'name' => 'set_due_date',
                      'input' => array('due' => $tomorrow . ' 17:00')),
            ), 'usage' => $usage);
        }

        return array('stop' => 'end_turn', 'content' => array(
            array('type' => 'text', 'text' => 'Offline test model: no changes planned.'),
        ), 'usage' => $usage);
    }
}
