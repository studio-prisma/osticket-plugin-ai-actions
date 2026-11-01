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
     * then notes, drafts and sub-tickets, status last (a closed ticket can't
     * be assigned any more), transfer just before it (it can take away the
     * agent's access).
     */
    public const WRITE = array('set_due_date', 'set_priority', 'assign_agent', 'assign_team',
        'add_internal_note', 'draft_reply', 'create_subticket', 'transfer', 'set_status');

    /** Changes undo can't take back (spec: notes, drafts, sub-tickets). */
    public const NO_UNDO = array('add_internal_note', 'draft_reply', 'create_subticket');

    /** Text tools: input key => [min, max] characters. */
    private const TEXT = array(
        'add_internal_note' => array('text' => array(1, 5000)),
        'draft_reply'       => array('text' => array(1, 10000)),
        'create_subticket'  => array('subject' => array(1, 120), 'message' => array(1, 5000)),
    );

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
        $text = static function (string $name, string $what, array $fields): array {
            $props = array();
            foreach ($fields as $key => $desc) {
                $props[$key] = array('type' => 'string', 'description' => $desc);
            }
            return array(
                'name'         => $name,
                'description'  => "Plan to $what. The agent sees the full text in the preview and decides. "
                                . 'Write it in the language of the agent\'s request, as plain text.',
                'strict'       => true,
                'input_schema' => array(
                    'type'                 => 'object',
                    'properties'           => $props,
                    'required'             => array_keys($fields),
                    'additionalProperties' => false,
                ),
            );
        };
        $defs = array(
            $text('add_internal_note', 'add an internal note (only agents see it)',
                array('text' => 'The note, up to 5000 characters.')),
            $text('draft_reply', 'prepare a reply to the customer as a draft in the agent\'s reply field. '
                . 'It is never sent by you; the agent edits and sends it', array('text' => 'The reply, up to 10000 characters.')),
            $text('create_subticket', 'create a sub-ticket of this ticket for the same customer and department',
                array('subject' => 'Subject, up to 120 characters.', 'message' => 'First message of the sub-ticket, up to 5000 characters.')),
        );
        $all = array_merge($defs, array(
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
        ));
        // In run order, so the list reads the way changes happen.
        usort($all, static function ($a, $b) {
            return array_search($a['name'], self::WRITE, true) <=> array_search($b['name'], self::WRITE, true);
        });
        return $all;
    }

    public static function isText(string $name): bool
    {
        return isset(self::TEXT[$name]);
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
        if (isset(self::TEXT[$name])) {
            $out = array();
            $given = array_keys($input);
            $wanted = array_keys(self::TEXT[$name]);
            sort($given);
            sort($wanted);
            if ($given !== $wanted) {
                return null;
            }
            foreach (self::TEXT[$name] as $key => $bounds) {
                $v = self::text($input[$key], $bounds[0], $bounds[1]);
                if ($v === null || ($key === 'subject' && strpos($v, "\n") !== false)) {
                    return null;
                }
                $out[$key] = $v;
            }
            return $out;
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

    /** Trimmed UTF-8 text without control characters, within bounds; else null. */
    private static function text($raw, int $min, int $max): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $s = trim(str_replace("\r\n", "\n", $raw));
        if (!preg_match('//u', $s) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $s)) {
            return null;
        }
        $len = (int) preg_match_all('/./su', $s);
        return $len >= $min && $len <= $max ? $s : null;
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
        if ($name === 'create_subticket') {
            return array('label' => $t['labels'][$name], 'value' => $input['subject'] . "\n" . $input['message']);
        }
        if (isset(self::TEXT[$name])) {
            return array('label' => $t['labels'][$name], 'value' => $input['text']);
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
