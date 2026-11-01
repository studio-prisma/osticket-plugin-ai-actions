<?php

declare(strict_types=1);

/**
 * AI Actions for osTicket 1.18.x
 *
 * Hooks, all without core patches:
 *
 *   Agent panel   The ajax.scp signal of scp/ajax.php receives /ai/*.
 *
 * Why scp/ajax.php and not apps/: in 1.18.4, apps/dispatcher.php requires
 * client.inc.php by a relative path that doesn't resolve from apps/ (fatal
 * error), and scp/apps/dispatcher.php refuses direct calls.
 *
 * bootstrap() runs inside osTicket::start(), before $cfg is loaded: no
 * StaffAuthenticationBackend::getUser() and no getLocalName() in here.
 *
 * @see docs/INSTALL.md
 */

require_once INCLUDE_DIR . 'class.plugin.php';
require_once INCLUDE_DIR . 'class.dispatcher.php';

require_once __DIR__ . '/config.php';

class AiActionsPlugin extends Plugin
{
    /** Mirrors plugin.php; used for cache-busting the asset URLs only. */
    public const VERSION = '0.0.1';

    /** Route base, relative to ROOT_PATH. */
    public const STAFF_BASE = 'scp/ajax.php/ai';

    var $config_class = 'AiActionsConfig';

    /** @var bool */
    private static $booted = false;

    /** One instance per installation; a second one would only compete for the same routes. */
    function isMultiInstance()
    {
        return false;
    }

    public function bootstrap(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        $config = $this->getConfig();
        if (!$config || !$config->get('enabled')) {
            return;
        }
        // Routes and the ticket-view field follow in slice 1.
    }
}
