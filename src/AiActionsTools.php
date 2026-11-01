<?php

declare(strict_types=1);

/**
 * The tools the model may call, and the server-side checks on their input.
 *
 * Write tools never run when the model calls them. The planner only collects
 * them; they run after the agent confirms the preview. The preview text is
 * built here from the validated input and osTicket's own labels, never from
 * text the model wrote, so the agent sees what will actually happen.
 */
final class AiActionsTools
{
    /**
     * Tools that change the ticket, in the order they run: fields first,
     * status last (a closed ticket can't be assigned any more), transfer
     * just before it (it can take away the agent's access).
     */
    public const WRITE = array('set_due_date', 'set_priority', 'assign_agent', 'assign_team', 'transfer', 'set_status');

    /** Tool => input key holding a value from AiActionsChoices. */
    private const CHOICE_KEY = array(
        'set_priority' => 'priority',
        'assign_agent' => 'agent',
        'assign_team'  => 'team',
        'transfer'     => 'department',
        'set_status'   => 'status',
    );

    /** Format of each choice value, checked before the list lookup. */
    private const CHOICE_FORMAT = array(
        'set_priority' => '/^[1-9]\d{0,9}$/',
        'assign_agent' => '/^s[1-9]\d{0,9}$/',
        'assign_team'  => '/^t[1-9]\d{0,9}$/',
        'transfer'     => '/^[1-9]\d{0,9}$/',
        'set_status'   => '/^[1-9]\d{0,9}$/',
    );

    /**
     * Definitions in the shape of the Messages API `tools` parameter.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function definitions(): array
    {
        $choice = static function (string $name, string $key, string $what): array {
            return array(
                'name'         => $name,
                'description'  => "Plan to $what. Use a value listed under \"$name:\" in <ticket_options>. "
                                . 'The change only happens after the agent confirms it.',
                'strict'       => true,
                'input_schema' => array(
                    'type'                 => 'object',
                    'properties'           => array(
                        $key => array('type' => 'string', 'description' => "Value from <ticket_options> \"$name:\"."),
                    ),
                    'required'             => array($key),
                    'additionalProperties' => false,
                ),
            );
        };
        return array(
            array(
                'name'         => 'set_due_date',
                'description'  => 'Plan a new due date for the current ticket. The change only happens '
                                . 'after the agent confirms it. Use the agent\'s local time. If the agent '
                                . 'gives no time of day, use 17:00.',
                'strict'       => true,
                'input_schema' => array(
                    'type'                 => 'object',
                    'properties'           => array(
                        'due' => array(
                            'type'        => 'string',
                            'description' => 'Local date and time as YYYY-MM-DD HH:MM, in the future.',
                        ),
                    ),
                    'required'             => array('due'),
                    'additionalProperties' => false,
                ),
            ),
            $choice('set_priority', 'priority', 'change the ticket\'s priority'),
            $choice('assign_agent', 'agent', 'assign the ticket to an agent'),
            $choice('assign_team', 'team', 'assign the ticket to a team'),
            $choice('transfer', 'department', 'transfer the ticket to another department'),
            $choice('set_status', 'status', 'change the ticket\'s status, e.g. to close or resolve it'),
        );
    }

    public static function isWrite(string $name): bool
    {
        return in_array($name, self::WRITE, true);
    }

    /**
     * Normalised input, or null if the input is not acceptable. Unknown
     * keys, wrong types, impossible dates and values outside the agent's
     * choices all fail. Without $choices, choice tools only get the format
     * check (unit tests).
     *
     * @param mixed $input
     */
    public static function validate(string $name, $input, ?array $choices = null): ?array
    {
        if (!is_array($input)) {
            return null;
        }
        if ($name === 'set_due_date') {
            if (array_keys($input) !== array('due') || !is_string($input['due'])) {
                return null;
            }
            $due = self::localDateTime($input['due']);
            return $due === null ? null : array('due' => $due);
        }
        $key = self::CHOICE_KEY[$name] ?? null;
        if ($key === null || array_keys($input) !== array($key) || !is_string($input[$key])) {
            return null;
        }
        $value = trim($input[$key]);
        if (!preg_match(self::CHOICE_FORMAT[$name], $value)) {
            return null;
        }
        if ($choices !== null && !AiActionsChoices::has($choices, $name, $value)) {
            return null;
        }
        return array($key => $value);
    }

    /** "YYYY-MM-DD HH:MM" if it is a real calendar date and time, else null. */
    public static function localDateTime(string $raw): ?string
    {
        $raw = trim($raw);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})$/', $raw, $m)) {
            return null;
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59) {
            return null;
        }
        return sprintf('%s-%s-%s %s:%s', $m[1], $m[2], $m[3], $m[4], $m[5]);
    }

    /** The input value a choice tool refers to. */
    public static function choiceValue(string $name, array $input): ?string
    {
        $key = self::CHOICE_KEY[$name] ?? null;
        return $key !== null && isset($input[$key]) ? (string) $input[$key] : null;
    }

    /**
     * Label and value for the preview line, from validated input and the
     * labels osTicket shows.
     *
     * @return array{label:string, value:string}
     */
    public static function summary(string $name, array $input, array $t, array $choices = array()): array
    {
        if ($name === 'set_due_date') {
            $d = DateTime::createFromFormat('Y-m-d H:i', $input['due']);
            return array(
                'label' => $t['due_date'],
                'value' => $d ? $d->format('Y-m-d H:i') . ' (' . $t['weekday'][(int) $d->format('w')] . ')' : $input['due'],
            );
        }
        $value = (string) self::choiceValue($name, $input);
        return array(
            'label' => $t['labels'][$name] ?? $name,
            'value' => AiActionsChoices::label($choices, $name, $value) ?? $value,
        );
    }

    /** Sorts planned changes into run order (see WRITE). Public for the unit tests. */
    public static function inRunOrder(array $changes): array
    {
        $rank = array_flip(self::WRITE);
        $i = 0;
        foreach ($changes as &$c) {
            $c['_pos'] = array($rank[$c['action']] ?? 99, $i++);
        }
        unset($c);
        usort($changes, static function ($a, $b) {
            return $a['_pos'] <=> $b['_pos'];
        });
        foreach ($changes as &$c) {
            unset($c['_pos']);
        }
        unset($c);
        return $changes;
    }
}
