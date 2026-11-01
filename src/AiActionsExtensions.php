<?php

declare(strict_types=1);

/**
 * Actions other plugins add to the assistant.
 *
 * A plugin connects to the signal "ai-actions.tools" and adds tools to the
 * registry it receives (docs/EXTENSIONS.md):
 *
 *   Signal::connect('ai-actions.tools', function ($registry) {
 *       $registry->add(array(
 *           'name'         => 'x_myplugin_do_it',   // x_ + letters, digits, _
 *           'label'        => 'Do it',              // preview label
 *           'description'  => 'Plan to …',          // for the model
 *           'input_schema' => array('type' => 'object', 'properties' => …, 'required' => …),
 *           'validate'     => function (array $input, $ticket, $staff) { return $clean ?: null; },
 *           'run'          => function (array $input, $ticket, $staff) { return array('ok' => true); },
 *           'summary'      => function (array $input) { return 'what the preview shows'; }, // optional
 *       ));
 *   });
 *
 * They follow the same rules as built-in write tools: queued, shown in the
 * preview, run only after the agent clicks Run, validated again right
 * before running, recorded in the "AI action" note. They can't be undone
 * through the plugin. Their validate and run callbacks get the agent and
 * must check the agent's permissions themselves.
 */
final class AiActionsExtensions
{
    public const SIGNAL = 'ai-actions.tools';
    public const NAME = '/^x_[a-z0-9_]{1,40}$/';
    public const MAX = 20;

    /** @var array<string, array>|null */
    private static $tools;

    /** @var object|null ticket the current request works on */
    private static $ticket;
    /** @var object|null */
    private static $staff;

    /** Collected once per request. */
    public static function all(): array
    {
        if (self::$tools === null) {
            self::$tools = array();
            $registry = new AiActionsExtensionRegistry();
            if (class_exists('Signal')) {
                Signal::send(self::SIGNAL, $registry);
            }
            self::$tools = $registry->tools;
        }
        return self::$tools;
    }

    /** For the unit tests. */
    public static function reset(?array $tools = null): void
    {
        self::$tools = $tools;
    }

    public static function bind($ticket, $staff): void
    {
        self::$ticket = $ticket;
        self::$staff = $staff;
    }

    public static function has(string $name): bool
    {
        return isset(self::all()[$name]);
    }

    /** Definitions for the model, in the Messages API shape. */
    public static function definitions(): array
    {
        $out = array();
        foreach (self::all() as $t) {
            $out[] = array(
                'name'         => $t['name'],
                'description'  => $t['description'] . ' The change only happens after the agent confirms it.',
                'input_schema' => $t['input_schema'],
            );
        }
        return $out;
    }

    public static function validate(string $name, $input): ?array
    {
        $t = self::all()[$name] ?? null;
        if (!$t || !is_array($input) || !self::$ticket || !self::$staff) {
            return null;
        }
        try {
            $out = call_user_func($t['validate'], $input, self::$ticket, self::$staff);
        } catch (Throwable $e) {
            error_log('ai-actions: extension ' . $name . ' failed to validate: ' . get_class($e));
            return null;
        }
        return is_array($out) ? $out : null;
    }

    public static function summary(string $name, array $input): array
    {
        $t = self::all()[$name] ?? null;
        $value = '';
        if ($t && isset($t['summary'])) {
            try {
                $value = (string) call_user_func($t['summary'], $input);
            } catch (Throwable $e) {
                $value = '';
            }
        }
        if ($value === '') {
            $value = (string) json_encode($input, JSON_UNESCAPED_UNICODE);
        }
        return array('label' => $t ? $t['label'] : $name, 'value' => $value);
    }

    public static function run(string $name, array $input, $ticket, $staff): array
    {
        $t = self::all()[$name] ?? null;
        if (!$t) {
            return array('ok' => false, 'error' => 'rejected');
        }
        try {
            $r = call_user_func($t['run'], $input, $ticket, $staff);
        } catch (Throwable $e) {
            error_log('ai-actions: extension ' . $name . ' failed: ' . get_class($e));
            return array('ok' => false, 'error' => 'rejected');
        }
        return is_array($r) && !empty($r['ok'])
            ? array('ok' => true)
            : array('ok' => false, 'error' => 'rejected', 'message' => is_array($r) ? substr((string) ($r['message'] ?? ''), 0, 200) : '');
    }
}

/** What a plugin gets with the signal. */
final class AiActionsExtensionRegistry
{
    /** @var array<string, array> */
    public $tools = array();

    /** Adds a tool; an invalid or duplicate one is skipped and logged. */
    public function add(array $tool): bool
    {
        $name = (string) ($tool['name'] ?? '');
        $ok = preg_match(AiActionsExtensions::NAME, $name)
            && !isset($this->tools[$name])
            && count($this->tools) < AiActionsExtensions::MAX
            && is_string($tool['label'] ?? null) && trim($tool['label']) !== ''
            && is_string($tool['description'] ?? null) && trim($tool['description']) !== ''
            && is_array($tool['input_schema'] ?? null) && ($tool['input_schema']['type'] ?? '') === 'object'
            && is_callable($tool['validate'] ?? null) && is_callable($tool['run'] ?? null)
            && (!isset($tool['summary']) || is_callable($tool['summary']));
        if (!$ok) {
            error_log('ai-actions: extension tool skipped (' . preg_replace('/[^a-z0-9_]/', '', strtolower($name)) . ')');
            return false;
        }
        $this->tools[$name] = array(
            'name'         => $name,
            'label'        => substr(trim($tool['label']), 0, 60),
            'description'  => substr(trim($tool['description']), 0, 1000),
            'input_schema' => $tool['input_schema'],
            'validate'     => $tool['validate'],
            'run'          => $tool['run'],
        ) + (isset($tool['summary']) ? array('summary' => $tool['summary']) : array());
        return true;
    }
}
