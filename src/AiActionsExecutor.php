<?php

declare(strict_types=1);

/**
 * Runs one confirmed change as the signed-in agent.
 *
 * Every action goes through the same core method and the same permission
 * check as its osTicket dialog (include/ajax.tickets.php). The agent's own
 * permissions decide; nothing here grants more.
 */
final class AiActionsExecutor
{
    /**
     * @return array{ok:bool, error?:string, message?:string, previous?:mixed}
     */
    public static function run($ticket, $staff, string $action, array $input): array
    {
        switch ($action) {
            case 'set_due_date':
                return self::dueDate($ticket, $staff, (string) ($input['due'] ?? ''));
        }
        return array('ok' => false, 'error' => 'rejected');
    }

    /** TicketsAjaxAPI::editField() for the due date field. */
    private static function dueDate($ticket, $staff, string $due): array
    {
        if (!$ticket->checkStaffPerm($staff, Ticket::PERM_EDIT) || !($field = $ticket->getField('duedate'))) {
            return array('ok' => false, 'error' => 'forbidden');
        }
        if (!$ticket->isOpen()) {
            return array('ok' => false, 'error' => 'not_open');
        }
        if (AiActionsTools::localDateTime($due) === null) {
            return array('ok' => false, 'error' => 'rejected');
        }
        $previous = $ticket->getDueDate();

        $source = array();
        foreach ($field->getFormNames() as $name) {
            $source[$name] = $due;
        }
        $form = $field->getEditForm($source);
        $errors = array();
        if (!$form->isValid() || !$ticket->updateField($form, $errors)) {
            return array('ok' => false, 'error' => 'rejected',
                         'message' => self::firstError($errors ?: $form->errors()));
        }
        return array('ok' => true, 'previous' => $previous);
    }

    private static function firstError($errors): string
    {
        if (!is_array($errors) || !$errors) {
            return '';
        }
        $e = $errors['field'] ?? $errors['err'] ?? reset($errors);
        return strip_tags(is_array($e) ? (string) reset($e) : (string) $e);
    }
}
