<?php

declare(strict_types=1);

/**
 * Turns one agent request into an answer and a list of planned changes.
 *
 * The loop runs the model until it stops calling tools, at most MAX_ROUNDS
 * times. Write tools are answered with "queued" and collected; they run
 * later, after the agent confirms the preview. Invalid tool input is
 * answered with an error so the model can correct itself, and counted.
 */
final class AiActionsPlanner
{
    public const MAX_ROUNDS = 6;

    /** Changes per request. A longer plan is cut, the rest counts as rejected. */
    public const MAX_CHANGES = 10;

    /** @var AiActionsLlm */
    private $llm;

    public function __construct(AiActionsLlm $llm)
    {
        $this->llm = $llm;
    }

    /**
     * @param array $context now (Y-m-d H:i), weekday, timezone, ticket (number, subject)
     * @return array{answer:string, changes:array, rejected:int, usage:array, stop:string}
     */
    public function plan(array $context, string $prompt): array
    {
        $system = self::system($context);
        $tools = AiActionsTools::definitions();
        $messages = array(array('role' => 'user', 'content' => $prompt));
        $changes = array();
        $rejected = 0;
        $usage = array('input' => 0, 'output' => 0);
        $answer = '';
        $stop = 'end_turn';

        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            $res = $this->llm->send($system, $messages, $tools);
            $usage['input'] += (int) ($res['usage']['input'] ?? 0);
            $usage['output'] += (int) ($res['usage']['output'] ?? 0);
            $stop = (string) ($res['stop'] ?? 'end_turn');
            $content = is_array($res['content'] ?? null) ? $res['content'] : array();

            $texts = array();
            $results = array();
            foreach ($content as $block) {
                $type = $block['type'] ?? '';
                if ($type === 'text') {
                    $texts[] = (string) ($block['text'] ?? '');
                } elseif ($type === 'tool_use') {
                    $results[] = $this->handleTool($block, $changes, $rejected);
                }
            }
            if ($texts) {
                $answer = trim(implode("\n\n", $texts));
            }
            if ($stop !== 'tool_use' || !$results) {
                break;
            }
            $messages[] = array('role' => 'assistant', 'content' => $content);
            $messages[] = array('role' => 'user', 'content' => $results);
        }

        return array('answer' => $answer, 'changes' => $changes, 'rejected' => $rejected,
                     'usage' => $usage, 'stop' => $stop);
    }

    private function handleTool(array $block, array &$changes, int &$rejected): array
    {
        $name = (string) ($block['name'] ?? '');
        $id = (string) ($block['id'] ?? '');
        if (!AiActionsTools::isWrite($name)) {
            $rejected++;
            return self::result($id, 'Unknown tool.', true);
        }
        $input = AiActionsTools::validate($name, $block['input'] ?? null);
        if ($input === null) {
            $rejected++;
            return self::result($id, 'Invalid input, nothing was queued.', true);
        }
        if (count($changes) >= self::MAX_CHANGES) {
            $rejected++;
            return self::result($id, 'Too many changes in one request, nothing was queued.', true);
        }
        $changes[] = array('action' => $name, 'input' => $input);
        return self::result($id, 'Queued. It runs only after the agent confirms the preview.', false);
    }

    private static function result(string $id, string $text, bool $error): array
    {
        $r = array('type' => 'tool_result', 'tool_use_id' => $id, 'content' => $text);
        if ($error) {
            $r['is_error'] = true;
        }
        return $r;
    }

    public static function system(array $c): string
    {
        $ticket = $c['ticket'] ?? array();
        return "You help a support agent work on one osTicket ticket.\n"
            . "Changes you plan with tools are only queued. The agent sees a preview and decides.\n"
            . "Plan only what the agent asked for. If the request is ambiguous, ask instead of guessing.\n"
            . "Answer in the language of the agent's request, briefly.\n\n"
            . sprintf("Now: %s (%s), time zone %s.\n", $c['now'] ?? '', $c['weekday'] ?? '', $c['timezone'] ?? 'UTC')
            . sprintf("Ticket: #%s.\n", $ticket['number'] ?? '');
    }
}
