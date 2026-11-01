<?php

declare(strict_types=1);

/**
 * Serves the field's CSS and JavaScript through the plugin's own route, and
 * adds them to full agent-panel pages.
 *
 * include/ is "Deny from all" on Apache, so files under include/plugins/
 * cannot be linked directly. The route maps a fixed name to a fixed file;
 * nothing from the request is used to build a path.
 *
 * The tags go into full page loads only. The agent panel switches pages
 * through PJAX afterwards; the script stays loaded and mounts the field on
 * every ticket view it sees.
 */
final class AiActionsAssets
{
    private const FILES = array(
        'ai-actions.css' => 'text/css; charset=UTF-8',
        'ai-actions.js'  => 'application/javascript; charset=UTF-8',
    );

    /** Agent-panel pages that load the script. Downloads (print, zip) are skipped. */
    private const PAGES = array('index.php', 'tickets.php');

    public static function version(): string
    {
        $mtime = 0;
        foreach (array_keys(self::FILES) as $name) {
            $mtime = max($mtime, (int) @filemtime(self::path($name)));
        }
        return substr(sha1(AiActionsPlugin::VERSION . ':' . $mtime), 0, 10);
    }

    public static function serve(string $name): string
    {
        if (!isset(self::FILES[$name]) || !is_file(self::path($name))) {
            Http::response(404, 'Not found');
            exit;
        }
        header('Content-Type: ' . self::FILES[$name]);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=86400');
        return (string) file_get_contents(self::path($name));
    }

    /**
     * Whether this request is a full agent-panel page that gets the tags.
     * Public for the unit tests.
     */
    public static function isPage(string $script, string $method, array $get, bool $pjax, string $pathInfo = ''): bool
    {
        if ($method !== 'GET' || $pjax || !preg_match('#/scp/([a-z]+\.php)$#', $script, $m)) {
            return false;
        }
        // The Ticket Board plugin's page (studio-prisma/osticket-plugin-board).
        if ($m[1] === 'ajax.php') {
            return (bool) preg_match('#^/board/?$#', $pathInfo);
        }
        if (!in_array($m[1], self::PAGES, true)) {
            return false;
        }
        $a = is_string($get['a'] ?? null) ? $get['a'] : '';
        return !in_array($a, array('print', 'zip', 'export'), true);
    }

    /** Called from bootstrap. */
    public static function watch(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        if (!self::isPage((string) ($_SERVER['SCRIPT_NAME'] ?? ''), (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
                $_GET, isset($_SERVER['HTTP_X_PJAX']), (string) ($_SERVER['PATH_INFO'] ?? ''))) {
            return;
        }
        ob_start(array(__CLASS__, 'inject'));
    }

    /** Output-buffer callback, runs once the page is complete. */
    public static function inject($buffer)
    {
        $staff = $GLOBALS['thisstaff'] ?? null;
        if (!is_string($buffer) || !AiActionsController::allowed($staff) || !self::isHtml(headers_list())) {
            return $buffer;
        }
        $pos = stripos($buffer, '</head>');
        if ($pos === false) {
            return $buffer;
        }
        return substr($buffer, 0, $pos) . self::tags($staff) . substr($buffer, $pos);
    }

    /** Public for the unit tests. */
    public static function isHtml(array $headers): bool
    {
        foreach ($headers as $h) {
            if (stripos($h, 'content-type:') === 0) {
                return stripos($h, 'text/html') !== false;
            }
        }
        return true; // PHP's default
    }

    public static function tags($staff): string
    {
        $base = ROOT_PATH . AiActionsPlugin::STAFF_BASE;
        $v = rawurlencode(self::version());
        $config = array(
            'base'    => $base,
            'strings' => AiActionsI18n::strings(AiActionsController::language($staff)),
        );
        return sprintf(
            "\n<link rel=\"stylesheet\" href=\"%s/assets/ai-actions.css?v=%s\">"
            . "\n<script type=\"application/json\" id=\"spa-ai-config\">%s</script>"
            . "\n<script src=\"%s/assets/ai-actions.js?v=%s\" defer></script>\n",
            htmlspecialchars($base, ENT_QUOTES), $v,
            json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE),
            htmlspecialchars($base, ENT_QUOTES), $v
        );
    }

    private static function path(string $name): string
    {
        return dirname(__DIR__) . '/assets/' . $name;
    }
}
