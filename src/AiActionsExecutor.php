<?php

declare(strict_types=1);

/**
 * Runs one confirmed change as the signed-in agent.
 *
 * Every action goes through the same core form or method as its osTicket
 * dialog, behind the same permission check as the dialog's handler in
 * include/ajax.tickets.php. The agent's own permissions decide; nothing
 * here grants more.
 *
 * `previous` in the result holds what the change replaced, for undo.
 */
final class AiActionsExecutor
{
    /**
     * @param bool $assignedInRun an earlier change of the same plan assigned the ticket
     * @return array{ok:bool, error?:string, message?:string, previous?:mixed}
     */
    public static function run($ticket, $staff, string $action, array $input, bool $assignedInRun = false): array
    {
        $value = (string) (AiActionsTools::choiceValue($action, $input) ?? '');
        switch ($action) {
            case 'set_due_date':
                return self::dueDate($ticket, $staff, (string) ($input['due'] ?? ''));
            case 'set_priority':
                return self::priority($ticket, $staff, $value);
            case 'assign_agent':
            case 'assign_team':
                return self::assign($ticket, $staff, $action === 'assign_agent' ? 'agents' : 'teams', $value);
            case 'transfer':
                return self::transfer($ticket, $staff, $value);
            case 'set_status':
                return self::status($ticket, $staff, $value, $assignedInRun);
        }
        return self::fail('rejected');
    }

    /** TicketsAjaxAPI::editField() for the due date field. */
    private static function dueDate($ticket, $staff, string $due): array
    {
        if (!$ticket->checkStaffPerm($staff, Ticket::PERM_EDIT) || !($field = $ticket->getField('duedate'))) {
            return self::fail('forbidden');
        }
        if (!$ticket->isOpen()) {
            return self::fail('not_open');
        }
        if (AiActionsTools::localDateTime($due) === null) {
            return self::fail('rejected');
        }
        $previous = $ticket->getDueDate();
        return self::editField($ticket, $field, $due, array('duedate' => $previous));
    }

    /** TicketsAjaxAPI::editField() for the priority field. */
    private static function priority($ticket, $staff, string $value): array
    {
        if (!$ticket->checkStaffPerm($staff, Ticket::PERM_EDIT) || !($field = $ticket->getField('priority'))) {
            return self::fail('forbidden');
        }
        if (!ctype_digit($value) || !self::listed($field->getChoices(), $value)) {
            return self::fail('rejected');
        }
        $answer = $field->getAnswer();
        $previous = $answer ? (string) $answer->getIdValue() : '';
        if ($previous === $value) {
            // Core answers "already assigned this value" with an error.
            return array('ok' => true, 'unchanged' => true, 'previous' => array('priority' => $previous));
        }
        return self::editField($ticket, $field, $value, array('priority' => $previous));
    }

    private static function editField($ticket, $field, string $value, array $previous): array
    {
        $source = array();
        foreach ($field->getFormNames() as $name) {
            $source[$name] = $value;
        }
        $form = $field->getEditForm($source);
        $errors = array();
        if (!$form->isValid() || !$ticket->updateField($form, $errors)) {
            return self::fail('rejected', $errors ?: $form->errors());
        }
        return array('ok' => true, 'previous' => $previous);
    }

    /** TicketsAjaxAPI::assign() */
    private static function assign($ticket, $staff, string $target, string $value): array
    {
        if (!$ticket->checkStaffPerm($staff, Ticket::PERM_ASSIGN)) {
            return self::fail('forbidden');
        }
        if (!$ticket->isOpen()) {
            return self::fail('not_open');
        }
        $prefix = $target === 'agents' ? 's' : 't';
        $form = $ticket->getAssignmentForm(null, array('target' => $target));
        $field = $form ? $form->getField('assignee') : null;
        if (!preg_match('/^' . $prefix . '\d+$/', $value) || !$field || !self::listed($field->getChoices(), $value)) {
            return self::fail('rejected');
        }
        $agent = $ticket->getStaff();
        $team = $ticket->getTeam();
        $previous = array('staff' => $agent ? (int) $agent->getId() : 0, 'team' => $team ? (int) $team->getId() : 0);
        if ($value === $prefix . ($prefix === 's' ? $previous['staff'] : $previous['team'])) {
            return array('ok' => true, 'unchanged' => true, 'previous' => $previous);
        }

        $form = $ticket->getAssignmentForm(array('assignee' => array($value), 'comments' => ''), array('target' => $target));
        $errors = array();
        if (!$form || !$form->isValid() || !$ticket->assign($form, $errors)) {
            return self::fail('rejected', $errors ?: ($form ? $form->errors() : array()));
        }
        return array('ok' => true, 'previous' => $previous);
    }

    /** TicketsAjaxAPI::transfer() */
    private static function transfer($ticket, $staff, string $value): array
    {
        if (!$ticket->checkStaffPerm($staff, Ticket::PERM_TRANSFER)) {
            return self::fail('forbidden');
        }
        $field = $ticket->getTransferForm()->getField('dept');
        if (!ctype_digit($value) || !$field || !self::listed($field->getChoices(), $value)
                || (int) $value === (int) $ticket->getDeptId()) {
            return self::fail('rejected');
        }
        $previous = (int) $ticket->getDeptId();
        $form = $ticket->getTransferForm(array('dept' => array((int) $value), 'refer' => false, 'comments' => ''));
        $errors = array();
        if (!$form->isValid() || !$ticket->transfer($form, $errors)) {
            return self::fail('rejected', $errors ?: $form->errors());
        }
        return array('ok' => true, 'previous' => $previous);
    }

    /**
     * TicketsAjaxAPI::setTicketStatus(), with the dialog's permission matrix.
     *
     * One deliberate difference: closing makes the closing agent the
     * assignee in core (setStatus' $set_closing_agent). If the same plan
     * just assigned someone ("assign it to Philipp and close it"), that
     * would undo the agent's request, so the assignee stays.
     */
    private static function status($ticket, $staff, string $value, bool $keepAssignee = false): array
    {
        $status = ctype_digit($value) ? TicketStatus::lookup((int) $value) : null;
        $state = $status ? strtolower((string) $status->getState()) : '';
        if (!$status || !in_array($state, array('open', 'closed'), true)) {
            return self::fail('rejected');
        }
        $role = $ticket->getRole($staff);
        if (!$role
                || ($state === 'open' && !$role->hasPerm(Ticket::PERM_CLOSE) && !$role->hasPerm(Ticket::PERM_CREATE))
                || ($state === 'closed' && !$role->hasPerm(Ticket::PERM_CLOSE))) {
            return self::fail('forbidden');
        }
        $previous = (int) $ticket->getStatusId();
        if ($previous === (int) $status->getId()) {
            return array('ok' => true, 'previous' => $previous);
        }
        $errors = array();
        if (!$ticket->setStatus($status, '', $errors, !$keepAssignee)) {
            return self::fail('rejected', $errors);
        }
        return array('ok' => true, 'previous' => $previous);
    }

    /** Is $value a key of a (possibly grouped) core choice list? */
    private static function listed($choices, string $value): bool
    {
        foreach (AiActionsChoices::options($choices) as $o) {
            if ($o['value'] === $value) {
                return true;
            }
        }
        return false;
    }

    private static function fail(string $error, $errors = array()): array
    {
        $r = array('ok' => false, 'error' => $error);
        $message = self::firstError($errors);
        if ($message !== '') {
            $r['message'] = $message;
        }
        return $r;
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
