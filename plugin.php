<?php

/**
 * osTicket plugin manifest: AI Actions
 *
 * Tested against osTicket 1.18.x. PluginManager splits the 'plugin' key with
 * explode(':'), see include/class.plugin.php:698.
 */

return array(
    'id'          => 'studio-prisma:ai-actions',
    'version'     => '0.7.0',
    'ost_version' => '1.18',
    'name'        => 'AI Actions',
    'author'      => 'Studio Prisma',
    'url'         => 'https://github.com/studio-prisma/osticket-plugin-ai-actions',
    'description' => 'A chat in the agent panel that turns plain-language requests '
                   . 'into ticket changes through the Claude API. Every change is shown '
                   . 'as a preview first and only runs after the agent confirms it.',
    'plugin'      => 'class.AiActionsPlugin.php:AiActionsPlugin',
);
