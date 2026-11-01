<?php

declare(strict_types=1);

/**
 * Finds the API key from the one source the admin chose:
 *
 *   file    a file outside the osTicket directory (default). Anything under
 *           the web root can end up in a backup, a download or a
 *           misconfigured vhost, so such a file is refused.
 *   env     the provider's environment variable of the web server
 *           (ANTHROPIC_API_KEY, OPENAI_API_KEY or MISTRAL_API_KEY).
 *   stored  pasted into the plugin settings; osTicket's PasswordField keeps
 *           it encrypted with SECRET_SALT from ost-config.php.
 *
 * Only the chosen source counts, no fallback: it's always clear which key
 * is in use. Content that doesn't look like a key of the provider is refused,
 * so a wrong path never sends some other file's content to the API.
 *
 * The key is never logged, never put into a prompt and never returned.
 */
final class AiActionsKey
{
    public const SOURCES = array('file', 'env', 'stored');

    /** Key shape per provider, so a wrong file or variable is never sent anywhere. */
    private const PATTERNS = array(
        'anthropic' => '/^sk-ant-[A-Za-z0-9_-]{20,300}$/',
        'openai'    => '/^sk-(?!ant-)[A-Za-z0-9_-]{20,300}$/',
        'mistral'   => '/^[A-Za-z0-9]{32}$/',
    );

    public const ENV = array(
        'anthropic' => 'ANTHROPIC_API_KEY',
        'openai'    => 'OPENAI_API_KEY',
        'mistral'   => 'MISTRAL_API_KEY',
    );

    public static function isKey(string $value, string $provider = 'anthropic'): bool
    {
        return isset(self::PATTERNS[$provider]) && preg_match(self::PATTERNS[$provider], $value) === 1;
    }

    /**
     * @param callable|null $stored returns the decrypted stored key; only
     *        called for source "stored", so the key isn't touched otherwise
     * @return array{key:?string, problem:?string} problem is one of
     *         no_key, file_in_webroot, file_unreadable, outside_open_basedir,
     *         bad_format, unknown_source
     */
    public static function load(string $source, string $file, string $root, ?callable $stored = null,
                                string $provider = 'anthropic'): array
    {
        if (!isset(self::PATTERNS[$provider])) {
            return array('key' => null, 'problem' => 'unknown_source');
        }
        switch ($source) {
            case 'file':
                return self::fromFile($file, $root, $provider);
            case 'env':
                $env = getenv(self::ENV[$provider]);
                return self::check(is_string($env) ? trim($env) : '', $provider);
            case 'stored':
                $value = $stored ? $stored() : null;
                return self::check(is_string($value) ? trim($value) : '', $provider);
        }
        return array('key' => null, 'problem' => 'unknown_source');
    }

    private static function fromFile(string $file, string $root, string $provider): array
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
            return self::check(trim(preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? ''), $provider);
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

    private static function check(string $key, string $provider): array
    {
        if ($key === '') {
            return array('key' => null, 'problem' => 'no_key');
        }
        return self::isKey($key, $provider)
            ? array('key' => $key, 'problem' => null)
            : array('key' => null, 'problem' => 'bad_format');
    }
}
