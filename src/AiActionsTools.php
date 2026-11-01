<?php

declare(strict_types=1);

/**
 * The tools the model may call, and the server-side checks on their input.
 *
 * Write tools never run when the model calls them. The planner only collects
 * them; they run after the agent confirms the preview. The preview text is
 * built here from the validated input, never from text the model wrote, so
 * the agent sees what will actually happen.
 */
final class AiActionsTools
{
    /** Tools that change the ticket. Each one needs a confirmed preview. */
    public const WRITE = array('set_due_date');

    /**
     * Definitions in the shape of the Messages API `tools` parameter.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function definitions(): array
    {
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
        );
    }

    public static function isWrite(string $name): bool
    {
        return in_array($name, self::WRITE, true);
    }

    /**
     * Normalised input, or null if the input is not acceptable. Unknown
     * keys, wrong types and impossible dates all fail.
     *
     * @param mixed $input
     */
    public static function validate(string $name, $input): ?array
    {
        if (!is_array($input)) {
            return null;
        }
        switch ($name) {
            case 'set_due_date':
                if (array_keys($input) !== array('due') || !is_string($input['due'])) {
                    return null;
                }
                $due = self::localDateTime($input['due']);
                return $due === null ? null : array('due' => $due);
        }
        return null;
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

    /**
     * Label and value for the preview line, from validated input.
     *
     * @return array{label:string, value:string}
     */
    public static function summary(string $name, array $input, array $t): array
    {
        switch ($name) {
            case 'set_due_date':
                $d = DateTime::createFromFormat('Y-m-d H:i', $input['due']);
                return array(
                    'label' => $t['due_date'],
                    'value' => $d ? $d->format('Y-m-d H:i') . ' (' . $t['weekday'][(int) $d->format('w')] . ')' : $input['due'],
                );
        }
        return array('label' => $name, 'value' => '');
    }
}
