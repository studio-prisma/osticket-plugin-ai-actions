<?php

declare(strict_types=1);

/**
 * The values the model may use with the tools, for one ticket and one agent.
 *
 * Taken from the very form fields the core dialogs render, so the agent gets
 * the same choices as in osTicket's own dialogs (the core filters agents and
 * departments there). Lists the agent has no permission for are left out;
 * the executor checks permissions again anyway.
 */
final class AiActionsChoices
{
    /** Entries per list. A longer list is cut; the model is told so. */
    public const MAX = 60;

    /**
     * @return array<string, array<int, array{value:string, label:string}>>
     *         keys: set_status, set_priority, assign_agent, assign_team, transfer
     */
    public static function forTicket($ticket, $staff): array
    {
        $out = array('set_status' => self::statuses($ticket));
        if ($ticket->checkStaffPerm($staff, Ticket::PERM_EDIT) && ($f = $ticket->getField('priority'))) {
            $out['set_priority'] = self::options($f->getChoices());
        }
        if ($ticket->isOpen() && $ticket->checkStaffPerm($staff, Ticket::PERM_ASSIGN)) {
            $out['assign_agent'] = self::assignees($ticket, 'agents');
            $out['assign_team'] = self::assignees($ticket, 'teams');
        }
        if ($ticket->checkStaffPerm($staff, Ticket::PERM_TRANSFER)) {
            $field = $ticket->getTransferForm()->getField('dept');
            $out['transfer'] = $field ? self::options($field->getChoices(), (string) $ticket->getDeptId()) : array();
        }
        return array_filter($out);
    }

    private static function statuses($ticket): array
    {
        $out = array();
        foreach (TicketStatus::objects() as $s) {
            $state = strtolower((string) $s->getState());
            if (!in_array($state, array('open', 'closed'), true) || (int) $s->getId() === (int) $ticket->getStatusId()) {
                continue;
            }
            $out[] = array('value' => (string) $s->getId(), 'label' => (string) $s->getLocalName() . " ($state)");
        }
        return $out;
    }

    private static function assignees($ticket, string $target): array
    {
        $form = $ticket->getAssignmentForm(null, array('target' => $target));
        $field = $form ? $form->getField('assignee') : null;
        return $field ? self::options($field->getChoices()) : array();
    }

    /**
     * Flattens core choice lists (grouped or flat) to value/label pairs,
     * without the empty entry and without $skip. Public for the unit tests.
     */
    public static function options($choices, ?string $skip = null): array
    {
        $out = array();
        foreach ($choices ?: array() as $value => $label) {
            if (is_array($label) || $label instanceof Traversable) {
                foreach (self::options($label, $skip) as $o) {
                    $out[] = $o;
                }
                continue;
            }
            $value = (string) $value;
            if ($value === '' || $value === $skip) {
                continue;
            }
            $out[] = array('value' => $value, 'label' => (string) $label);
        }
        return $out;
    }

    public static function has(array $choices, string $tool, string $value): bool
    {
        return self::label($choices, $tool, $value) !== null;
    }

    public static function label(array $choices, string $tool, string $value): ?string
    {
        foreach ($choices[$tool] ?? array() as $o) {
            if ($o['value'] === $value) {
                return $o['label'];
            }
        }
        return null;
    }

    /** The <ticket_options> block. Public for the unit tests. */
    public static function render(array $choices): string
    {
        $lines = array('Values you may use with the tools (value: label). Use only these values.');
        foreach ($choices as $tool => $options) {
            $lines[] = '';
            $lines[] = $tool . ':';
            foreach (array_slice($options, 0, self::MAX) as $o) {
                $lines[] = '- ' . $o['value'] . ': ' . self::clean($o['label']);
            }
            if (count($options) > self::MAX) {
                $lines[] = sprintf('(%d more not listed; ask the agent to do it in osTicket.)', count($options) - self::MAX);
            }
        }
        if (count($lines) === 1) {
            $lines[] = '(none: the agent may only change the due date here)';
        }
        return "<ticket_options>\n" . implode("\n", $lines) . "\n</ticket_options>";
    }

    private static function clean(string $s): string
    {
        $s = preg_replace('/[\r\n]+/', ' ', $s) ?? '';
        return preg_replace(AiActionsContext::CONTROL_TAGS, '', $s) ?? '';
    }
}
