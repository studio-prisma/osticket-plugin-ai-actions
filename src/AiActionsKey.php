<?php

declare(strict_types=1);

/**
 * Finds the API key without ever storing it in osTicket's database.
 *
 * Order: the key file from the settings, then the ANTHROPIC_API_KEY
 * environment variable. A file inside the osTicket directory is refused:
 * anything under the web root can end up in a backup, a download or a
 * misconfigured vhost. Content that doesn't look like an Anthropic key is
 * refused too, so a wrong path never sends some other file's content to
 * the API.
 *
 * The key is never logged, never put into a prompt and never returned.
 */
final class AiActionsKey
{
    private const PATTERN = '/^sk-ant-[A-Za-z0-9_-]{20,300}$/';

    /**
     * @return array{key:?string, problem:?string} problem is one of
     *         no_key, file_in_webroot, file_unreadable, bad_format
     */
    public static function load(string $file, string $root): array
    {
        $file = trim($file);
        if ($file !== '') {
            $real = realpath($file);
            if ($real === false || !is_file($real) || !is_readable($real)) {
                return array('key' => null, 'problem' => 'file_unreadable');
            }
            if (self::inside($real, $root)) {
                return array('key' => null, 'problem' => 'file_in_webroot');
            }
            $raw = (string) @file_get_contents($real, false, null, 0, 1024);
            return self::check(trim($raw));
        }
        $env = getenv('ANTHROPIC_API_KEY');
        if (is_string($env) && trim($env) !== '') {
            return self::check(trim($env));
        }
        return array('key' => null, 'problem' => 'no_key');
    }

    /** Public for the unit tests. */
    public static function inside(string $path, string $root): bool
    {
        $root = realpath($root);
        if ($root === false) {
            return false;
        }
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $path = str_replace('\\', '/', $path);
        return strncasecmp($path, $root, strlen($root)) === 0;
    }

    private static function check(string $key): array
    {
        if ($key === '') {
            return array('key' => null, 'problem' => 'no_key');
        }
        return preg_match(self::PATTERN, $key)
            ? array('key' => $key, 'problem' => null)
            : array('key' => null, 'problem' => 'bad_format');
    }
}
