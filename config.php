<?php

declare(strict_types=1);

/**
 * Configuration of the AI Actions plugin.
 *
 * Off by default. The plugin sends ticket content to an external API, so it
 * is switched on deliberately after the steps in docs/INSTALL.md.
 *
 * The API key comes from one source the admin chooses (AiActionsKey): a file
 * outside the web root, the web server's environment, or this form. Stored
 * here, it goes through osTicket's PasswordField, which encrypts it with
 * SECRET_SALT from ost-config.php; plain settings are stored in clear text.
 */

require_once INCLUDE_DIR . 'class.plugin.php';
require_once INCLUDE_DIR . 'class.forms.php';

class AiActionsConfig extends PluginConfig
{
    public function getOptions()
    {
        return array(

            'sec_access' => new SectionBreakField(array(
                'label' => 'Access',
            )),

            'enabled' => new BooleanField(array(
                'label'         => 'AI assistant in the agent panel',
                'default'       => false,
                'configuration' => array(
                    'desc' => 'Shows the chat bubble to agents. Changes still need the '
                            . 'agent\'s own permissions and an explicit confirmation.',
                ),
            )),

            'roles' => new ChoiceField(array(
                'label'         => 'Agents with these primary roles',
                'default'       => '',
                'choices'       => self::roles(),
                'hint'          => 'Only agents whose primary role is selected see the chat. '
                                 . 'Nothing selected means nobody.',
                'configuration' => array('multiselect' => true, 'prompt' => 'None'),
            )),

            'sec_api' => new SectionBreakField(array(
                'label' => 'Claude API',
            )),

            'key_source' => new ChoiceField(array(
                'label'   => 'Where the API key comes from',
                'default' => 'file',
                'choices' => array(
                    'file'   => 'Key file on the server (recommended)',
                    'env'    => 'Environment variable ANTHROPIC_API_KEY',
                    'stored' => 'Stored here, encrypted',
                ),
                'hint'    => 'Only the chosen source is used. File: never in the database or a backup of it. '
                           . 'Environment: set by the server admin, e.g. in the PHP settings of the panel. '
                           . 'Stored here: encrypted with osTicket\'s secret from ost-config.php; someone with '
                           . 'both the database and the files can decrypt it.',
            )),

            'key_file' => new TextboxField(array(
                'label'         => 'API key file',
                'default'       => '',
                'hint'          => 'For "Key file": absolute path to a file that contains only the API key, '
                                 . 'outside the osTicket directory and readable by the web server. On Plesk: '
                                 . '/var/www/vhosts/example.com/private/anthropic.key (your domain instead of example.com)',
                'configuration' => array('size' => 60, 'length' => 255),
            )),

            'key_stored' => new PasswordField(array(
                'label'         => 'API key',
                'required'      => false,
                'hint'          => 'For "Stored here": paste the key (sk-ant-…). It is never shown again; '
                                 . 'leaving the box empty keeps the stored key.',
                'configuration' => array('size' => 60, 'length' => 300, 'key' => 'studio-prisma:ai-actions'),
            )),

            'key_stored_clear' => new BooleanField(array(
                'label'         => 'Remove the stored key',
                'default'       => false,
                'configuration' => array('desc' => 'Deletes the key stored here when you save.'),
            )),

            'model' => new ChoiceField(array(
                'label'   => 'Model',
                'default' => 'claude-opus-5-5',
                'choices' => array(
                    'claude-opus-5-5'   => 'Claude Opus 5.5',
                    'claude-sonnet-5-5' => 'Claude Sonnet 5.5 (cheaper)',
                ),
            )),

            'effort' => new ChoiceField(array(
                'label'   => 'Effort',
                'default' => 'low',
                'choices' => array(
                    'low'    => 'Low (fast, cheapest; enough for most requests)',
                    'medium' => 'Medium',
                    'high'   => 'High (slow, for long or tangled threads)',
                ),
            )),

            'sec_look' => new SectionBreakField(array(
                'label' => 'Appearance',
            )),

            'assistant_name' => new TextboxField(array(
                'label'         => 'Name of the assistant',
                'default'       => '',
                'hint'          => 'Shown in the window header and on the bubble, e.g. "Prisma". '
                                 . 'Empty: "AI assistant" in the agent\'s language.',
                'configuration' => array('size' => 30, 'length' => 60),
            )),

            'assistant_icon' => new ChoiceField(array(
                'label'   => 'Icon',
                'default' => 'sparkles',
                'choices' => array(
                    'sparkles' => 'Sparkles',
                    'robot'    => 'Robot',
                    'chat'     => 'Speech bubble',
                    'bulb'     => 'Light bulb',
                    'bolt'     => 'Lightning bolt',
                    'initial'  => 'First letter of the name',
                    'image'    => 'Own image (below)',
                ),
                'hint'    => 'In the round bubble and as the picture next to the name.',
            )),

            'assistant_image' => new TextboxField(array(
                'label'         => 'Own image',
                'default'       => '',
                'hint'          => 'For "Own image": an https:// address or a path on this server, e.g. '
                                 . '/logo-square.png. Square images look best; it is shown round.',
                'configuration' => array('size' => 60, 'length' => 500),
            )),

            'accent' => new TextboxField(array(
                'label'         => 'Accent colour',
                'default'       => '',
                'hint'          => 'Hex colour for the bubble, your own messages and the main buttons, e.g. '
                                 . '#330066. Empty: the theme\'s colour.',
                'configuration' => array('size' => 10, 'length' => 7),
            )),

            'welcome' => new TextareaField(array(
                'label'         => 'Welcome text',
                'default'       => '',
                'hint'          => 'Shown in an empty conversation, plain text. Empty: a short built-in hint.',
                'configuration' => array('rows' => 3, 'cols' => 60, 'html' => false, 'length' => 500),
            )),

            'sec_history' => new SectionBreakField(array(
                'label' => 'Conversations',
            )),

            'history_days' => new TextboxField(array(
                'label'         => 'Keep earlier conversations (days)',
                'default'       => '90',
                'hint'          => 'Conversations ended with "New" or after 12 hours idle stay readable for '
                                 . 'their agent under "Earlier conversations". 0 = not kept.',
                'configuration' => array('size' => 6, 'length' => 4, 'validator' => 'number'),
            )),

            'sec_limits' => new SectionBreakField(array(
                'label' => 'Limits per agent and day',
            )),

            'daily_requests' => new TextboxField(array(
                'label'         => 'Messages',
                'default'       => '100',
                'hint'          => 'Messages to the AI per agent and day. 0 = no limit.',
                'configuration' => array('size' => 8, 'length' => 6, 'validator' => 'number'),
            )),

            'daily_tokens' => new TextboxField(array(
                'label'         => 'Tokens',
                'default'       => '500000',
                'hint'          => 'Input plus output tokens per agent and day. A message costs about '
                                 . '1,000 to 5,000. 0 = no limit.',
                'configuration' => array('size' => 12, 'length' => 10, 'validator' => 'number'),
            )),

            'thread_chars' => new TextboxField(array(
                'label'         => 'Thread length',
                'default'       => '12000',
                'hint'          => 'Characters of the ticket thread the AI reads, newest first '
                                 . '(2,000 to 50,000). More context, more tokens.',
                'configuration' => array('size' => 8, 'length' => 6, 'validator' => 'number'),
            )),
        );
    }

    /**
     * Runs before the form is written. A pasted key must look like one; the
     * "remove" box deletes the stored key (PasswordField itself never saves
     * an empty value, it treats it as "unchanged").
     */
    function pre_save(&$config, &$errors)
    {
        $pasted = is_string($config['key_stored'] ?? null) ? trim($config['key_stored']) : '';
        $clear = !empty($config['key_stored_clear']);
        if ($clear && $pasted !== '') {
            $errors['err'] = 'Either paste a new API key or remove the stored one, not both.';
            return false;
        }
        $accent = is_string($config['accent'] ?? null) ? trim($config['accent']) : '';
        if ($accent !== '' && !preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $accent)) {
            $errors['err'] = 'Accent colour: a hex colour such as #330066, or empty.';
            return false;
        }
        $image = is_string($config['assistant_image'] ?? null) ? trim($config['assistant_image']) : '';
        if ($image !== '' && AiActionsController::imageUrl($image) === '') {
            $errors['err'] = 'Own image: an https:// address or a path starting with /, without spaces or quotes.';
            return false;
        }
        if ($pasted !== '' && !AiActionsKey::isKey($pasted)) {
            $errors['err'] = 'That doesn\'t look like an Anthropic API key (sk-ant-…). Nothing was saved.';
            return false;
        }
        if ($pasted !== '') {
            $config['key_stored'] = $pasted;
        }
        if ($clear) {
            $this->set('key_stored', '');
            unset($config['key_stored']);
        }
        $config['key_stored_clear'] = false;
        return true;
    }

    /** @return array<int, string> role ID => name */
    private static function roles(): array
    {
        $out = array();
        if (!class_exists('Role')) {
            return $out;
        }
        foreach (Role::objects()->order_by('name') as $role) {
            $out[(int) $role->getId()] = (string) $role->getName();
        }
        return $out;
    }
}
