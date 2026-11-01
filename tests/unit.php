<?php

declare(strict_types=1);

/**
 * Unit tests: no osTicket, no database, no network.
 *
 *   php tests/unit.php
 */

define('INCLUDE_DIR', __DIR__ . '/stubs/');
define('ROOT_PATH', '/');

$dir = getenv('PLUGIN_DIR') ?: dirname(__DIR__);
require $dir . '/class.AiActionsPlugin.php';

$failures = 0;
$count = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $count;
    $count++;
    if ($ok) {
        echo "ok   $name\n";
    } else {
        $failures++;
        echo "FAIL $name" . ($detail !== '' ? ": $detail" : '') . "\n";
    }
}

// ----------------------------------------------------------------- manifest

$manifest = require $dir . '/plugin.php';
check('manifest: version matches the plugin class', $manifest['version'] === AiActionsPlugin::VERSION,
    $manifest['version'] . ' vs ' . AiActionsPlugin::VERSION);
check('manifest: id is namespaced', $manifest['id'] === 'studio-prisma:ai-actions');
list($file, $class) = explode(':', $manifest['plugin']);
check('manifest: plugin entry points at the class', $file === 'class.AiActionsPlugin.php' && $class === 'AiActionsPlugin');

// ------------------------------------------------------------------- config

check('config: class is declared', class_exists('AiActionsConfig'));

echo "\n$count checks, $failures failed\n";
exit($failures ? 1 : 0);
