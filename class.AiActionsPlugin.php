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
require_once __DIR__ . '/src/AiActionsExtensions.php';
require_once __DIR__ . '/src/AiActionsChoices.php';
require_once __DIR__ . '/src/AiActionsLlm.php';
require_once __DIR__ . '/src/AiActionsKey.php';
require_once __DIR__ . '/src/AiActionsClaude.php';
require_once __DIR__ . '/src/AiActionsProviders.php';
require_once __DIR__ . '/src/AiActionsTime.php';
require_once __DIR__ . '/src/AiActionsContext.php';
require_once __DIR__ . '/src/AiActionsReaders.php';
require_once __DIR__ . '/src/AiActionsPlanner.php';
require_once __DIR__ . '/src/AiActionsRunStore.php';
require_once __DIR__ . '/src/AiActionsChatStore.php';
require_once __DIR__ . '/src/AiActionsUsage.php';
require_once __DIR__ . '/src/AiActionsExecutor.php';
require_once __DIR__ . '/src/AiActionsAssets.php';
require_once __DIR__ . '/src/AiActionsController.php';
require_once __DIR__ . '/src/AiActionsAgents.php';
require_once __DIR__ . '/src/AiActionsJobs.php';
require_once __DIR__ . '/src/AiActionsAdmin.php';
require_once __DIR__ . '/src/AiActionsAdminTools.php';
require_once __DIR__ . '/src/AiActionsRules.php';
require_once __DIR__ . '/src/AiActionsGuard.php';
require_once __DIR__ . '/src/AiActionsAutomation.php';

class AiActionsPlugin extends Plugin
{
    /** Mirrors plugin.php; used for cache-busting the asset URLs only. */
    public const VERSION = '0.5.0';

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
            'enabled'  => $config->get('enabled'),
            'roles'    => $config->get('roles'),
            'model'    => $config->get('model'),
            'effort'   => $config->get('effort'),
            'key_source' => $config->get('key_source'),
            'key_file' => $config->get('key_file'),
            'daily_requests' => $config->get('daily_requests'),
            'daily_tokens'   => $config->get('daily_tokens'),
            'thread_chars'   => $config->get('thread_chars'),
            'history_days'   => $config->get('history_days'),
            'name'     => $config->get('assistant_name'),
            'icon'     => $config->get('assistant_icon'),
            'image'    => $config->get('assistant_image'),
            'accent'   => $config->get('accent'),
            'welcome'  => $config->get('welcome'),
            'allow_send' => $config->get('allow_send'),
            'auto_paused' => $config->get('auto_paused'),
            'ai_notice'   => $config->get('ai_notice'),
            'ai_notice_text' => $config->get('ai_notice_text'),
            'link_domains' => $config->get('link_domains'),
            'auto_per_hour' => $config->get('auto_per_hour'),
        ), static function () use ($config) {
            // PluginConfig decrypts PasswordField values when it loads.
            $v = $config->get('key_stored');
            return is_string($v) ? $v : null;
        });
        if (!AiActionsController::settings()['enabled']) {
            return;
        }
        Signal::connect('ajax.scp', array(__CLASS__, 'staffRoutes'));
        // AI agents: no session for them, ever; their work runs in osTicket's cron.
        Signal::connect('auth.login.succeeded', static function ($staff) {
            AiActionsAgents::refuseLogin($staff);
        });
        Signal::connect('auth.pwreset.login', static function ($staff) {
            AiActionsAgents::refuseLogin($staff);
        });
        AiActionsJobs::$worker = array('AiActionsAutomation', 'work');
        Signal::connect('cron', static function () {
            AiActionsRules::schedule();
            AiActionsJobs::cron();
        });
        // Rule triggers only queue work; the cron does it.
        Signal::connect('ticket.created', static function ($ticket) {
            AiActionsRules::onTicketCreated($ticket);
        });
        Signal::connect('object.edited', static function ($object, $data = null) {
            AiActionsRules::onEdited($object, (array) $data);
        });
        Signal::connect('threadentry.created', static function ($entry) {
            AiActionsRules::onEntry($entry);
        });
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
        $dispatcher->append(url_get('^/ai/chat$', static function () {
            return AiActionsController::chat();
        }));
        $dispatcher->append(url_post('^/ai/undo$', static function () {
            return AiActionsController::undo();
        }));
        $dispatcher->append(url_post('^/ai/discard$', static function () {
            return AiActionsController::discard();
        }));
        $dispatcher->append(url_get('^/ai/history$', static function () {
            return AiActionsController::history();
        }));
        $dispatcher->append(url_post('^/ai/reset$', static function () {
            return AiActionsController::reset();
        }));
        $dispatcher->append(url_post('^/ai/proposal$', static function () {
            return AiActionsController::proposal();
        }));
        $dispatcher->append(url_get('^/ai/admin$', static function () {
            return AiActionsAdmin::page();
        }));
        $dispatcher->append(url_post('^/ai/admin$', static function () {
            return AiActionsAdmin::post();
        }));
        $dispatcher->append(url_get('^/ai/assets/([a-z.-]+)$', static function ($name) {
            return AiActionsController::asset($name);
        }));
    }
}
