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
require_once __DIR__ . '/src/AiActionsI18n.php';
require_once __DIR__ . '/src/AiActionsTools.php';
require_once __DIR__ . '/src/AiActionsLlm.php';
require_once __DIR__ . '/src/AiActionsPlanner.php';
require_once __DIR__ . '/src/AiActionsRunStore.php';
require_once __DIR__ . '/src/AiActionsExecutor.php';
require_once __DIR__ . '/src/AiActionsAssets.php';
require_once __DIR__ . '/src/AiActionsController.php';

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
        if (!$config) {
            return;
        }
        AiActionsController::configure(array(
            'enabled' => $config->get('enabled'),
            'roles'   => $config->get('roles'),
        ));
        if (!AiActionsController::settings()['enabled']) {
            return;
        }
        Signal::connect('ajax.scp', array(__CLASS__, 'staffRoutes'));
        AiActionsAssets::watch();
    }

    // Handlers are closures on purpose: given array('Class', 'method'), the
    // core dispatcher instantiates the class and requires an access() method
    // (include/class.dispatcher.php, UrlMatcher::dispatch).

    public static function staffRoutes($dispatcher): void
    {
        $dispatcher->append(url_post('^/ai/plan$', static function () {
            return AiActionsController::plan();
        }));
        $dispatcher->append(url_post('^/ai/run$', static function () {
            return AiActionsController::run();
        }));
        $dispatcher->append(url_get('^/ai/assets/([a-z.-]+)$', static function ($name) {
            return AiActionsController::asset($name);
        }));
    }
}
