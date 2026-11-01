<?php

declare(strict_types=1);

/**
 * Configuration of the AI Actions plugin.
 *
 * Off by default. The plugin sends ticket content to an external API, so it
 * is switched on deliberately after the steps in docs/INSTALL.md.
 *
 * The API key is not a setting: osTicket stores plugin settings in plain text
 * in its database. The key is read from the environment or a file outside the
 * web root instead.
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
                'label'         => 'AI field in the ticket view',
                'default'       => false,
                'configuration' => array(
                    'desc' => 'Shows the AI field to agents. Changes still need the '
                            . 'agent\'s own permissions and an explicit confirmation.',
                ),
            )),

            'roles' => new ChoiceField(array(
                'label'         => 'Agents with these primary roles',
                'default'       => '',
                'choices'       => self::roles(),
                'hint'          => 'Only agents whose primary role is selected see the field. '
                                 . 'Nothing selected means nobody.',
                'configuration' => array('multiselect' => true, 'prompt' => 'None'),
            )),

            'sec_api' => new SectionBreakField(array(
                'label' => 'Claude API',
            )),

            'key_file' => new TextboxField(array(
                'label'         => 'API key file',
                'default'       => '',
                'hint'          => 'Absolute path to a file that contains only the API key, outside the '
                                 . 'osTicket directory and readable by the web server. Empty: the '
                                 . 'ANTHROPIC_API_KEY environment variable is used. The key itself is '
                                 . 'never stored here.',
                'configuration' => array('size' => 60, 'length' => 255),
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
        );
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
