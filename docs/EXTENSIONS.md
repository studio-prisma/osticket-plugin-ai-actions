# Adding actions from another plugin

Another osTicket plugin can give the assistant its own actions. They work like the built-in ones: the model plans them, the agent sees them in the preview, they run only after **Run**, they are validated again right before running, and the "AI action" note records them. They can't be undone through the assistant.

## Register

Connect to the signal `ai-actions.tools` in your plugin's `bootstrap()`. The assistant sends it once per request when it needs the list.

```php
Signal::connect('ai-actions.tools', function ($registry) {
    $registry->add(array(
        'name'         => 'x_myplugin_flag',           // x_ + lowercase letters, digits, _
        'label'        => 'Flag on board',             // the preview's label
        'description'  => 'Plan to flag the ticket card in a colour.',
        'input_schema' => array(
            'type'                 => 'object',
            'properties'           => array('color' => array('type' => 'string', 'enum' => array('red', 'blue'))),
            'required'             => array('color'),
            'additionalProperties' => false,
        ),
        // Return the clean input, or null to refuse. Runs when the model plans
        // the action and again right before it runs.
        'validate'     => function (array $input, $ticket, $staff) {
            return $ticket->checkStaffPerm($staff, Ticket::PERM_EDIT)
                && in_array($input['color'] ?? '', array('red', 'blue'), true)
                ? array('color' => $input['color']) : null;
        },
        // Do it. Return array('ok' => true) or array('ok' => false, 'message' => '…').
        'run'          => function (array $input, $ticket, $staff) {
            return array('ok' => MyPlugin::flag($ticket, $input['color']));
        },
        // Optional: the preview's value. Default: the input as JSON.
        'summary'      => function (array $input) {
            return 'Colour: ' . $input['color'];
        },
    ));
});
```

## Rules

- **Check the agent's permissions in `validate` and `run`.** The assistant passes the agent; it doesn't know what your action needs.
- **Treat the input as untrusted.** The model fills it, and ticket content can try to steer the model. Accept only what you can check, ideally values from a fixed list.
- Names start with `x_`; at most 20 actions in total. Invalid registrations are skipped and logged as `ai-actions: extension tool skipped (name)`.
- An exception in a callback counts as a rejected change; the log only gets the exception class.
- Actions run after notes, drafts, sub-tickets and replies, before transfer and status.

## Example in use

The [Ticket Board](https://github.com/studio-prisma/osticket-plugin-board) (from 0.6.0) registers two actions this way, `x_board_release` and `x_board_answered`, in `src/BoardAiTools.php`. Both run through the board's own quick-view handler, so its permission checks apply to the agent who clicks **Run**. Rules can't use extension actions; they are offered in the chat only.
