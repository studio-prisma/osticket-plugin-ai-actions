<?php

declare(strict_types=1);

/**
 * Finds the API key from the one source the admin chose:
 *
 *   file    a file outside the osTicket directory (default). Anything under
 *           the web root can end up in a backup, a download or a
 *           misconfigured vhost, so such a file is refused.
 *   env     the ANTHROPIC_API_KEY environment variable of the web server.
 *   stored  pasted into the plugin settings; osTicket's PasswordField keeps
 *           it encrypted with SECRET_SALT from ost-config.php.
 *
 * Only the chosen source counts, no fallback: it's always clear which key
 * is in use. Content that doesn't look like an Anthropic key is refused,
 * so a wrong path never sends some other file's content to the API.
 *
 * The key is never logged, never put into a prompt and never returned.
 */
final class AiActionsKey
{
    public const SOURCES = array('file', 'env', 'stored');

    private const PATTERN = '/^sk-ant-[A-Za-z0-9_-]{20,300}$/';

    public static function isKey(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    /**
     * @param callable|null $stored returns the decrypted stored key; only
     *        called for source "stored", so the key isn't touched otherwise
     * @return array{key:?string, problem:?string} problem is one of
     *         no_key, file_in_webroot, file_unreadable, outside_open_basedir,
     *         bad_format, unknown_source
     */
    public static function load(string $source, string $file, string $root, ?callable $stored = null): array
    {
        switch ($source) {
            case 'file':
                return self::fromFile($file, $root);
            case 'env':
                $env = getenv('ANTHROPIC_API_KEY');
                return self::check(is_string($env) ? trim($env) : '');
            case 'stored':
                $value = $stored ? $stored() : null;
                return self::check(is_string($value) ? trim($value) : '');
        }
        return array('key' => null, 'problem' => 'unknown_source');
    }

    private static function fromFile(string $file, string $root): array
    {
        $file = trim($file);
        if ($file !== '') {
            // Under Plesk and similar panels PHP may only read inside the
            // subscription (open_basedir); say so instead of "unreadable".
            if (!self::allowedByOpenBasedir($file, (string) ini_get('open_basedir'))) {
                return array('key' => null, 'problem' => 'outside_open_basedir');
            }
            $real = realpath($file);
            if ($real === false || !is_file($real) || !is_readable($real)) {
                return array('key' => null, 'problem' => 'file_unreadable');
            }
            if (self::inside($real, $root)) {
                return array('key' => null, 'problem' => 'file_in_webroot');
            }
            $raw = (string) @file_get_contents($real, false, null, 0, 1024);
            // Editors (Plesk's file manager among them) may save a UTF-8 BOM.
            return self::check(trim(preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? ''));
        }
        return array('key' => null, 'problem' => 'no_key');
    }

    /**
     * Whether open_basedir lets PHP read $file. Empty setting: no limit.
     * Public for the unit tests.
     */
    public static function allowedByOpenBasedir(string $file, string $setting): bool
    {
        if (trim($setting) === '') {
            return true;
        }
        $file = str_replace('\\', '/', $file);
        foreach (explode(PATH_SEPARATOR, $setting) as $dir) {
            $dir = rtrim(str_replace('\\', '/', trim($dir)), '/');
            if ($dir !== '' && ($file === $dir || strncmp($file, $dir . '/', strlen($dir) + 1) === 0)) {
                return true;
            }
        }
        return false;
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
