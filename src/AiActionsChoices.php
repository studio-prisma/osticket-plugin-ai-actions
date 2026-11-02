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
    /** @param bool|null $send offer send_reply; null = the plugin setting (chat), a rule decides for itself */
    public static function forTicket($ticket, $staff, ?bool $send = null): array
    {
        $send = $send ?? AiActionsController::settings()['allow_send'];
        $out = array('set_status' => self::statuses($ticket));
        if ($ticket->checkStaffPerm($staff, Ticket::PERM_EDIT) && ($f = $ticket->getField('priority'))) {
            $out['set_priority'] = self::options($f->getChoices());
        }
        if ($ticket->isOpen() && $ticket->checkStaffPerm($staff, Ticket::PERM_ASSIGN)) {
            $out['assign_agent'] = self::assignees($ticket, 'agents');
            $out['assign_team'] = self::assignees($ticket, 'teams');
        }
        // Who a sent reply reaches, exactly as the reply form's default ("all").
        if ($send && $ticket->checkStaffPerm($staff, Ticket::PERM_REPLY)) {
            $out['send_reply'] = array(array('value' => 'recipients', 'label' => self::recipients($ticket)));
        }
        if ($ticket->checkStaffPerm($staff, Ticket::PERM_TRANSFER)) {
            $field = $ticket->getTransferForm()->getField('dept');
            $out['transfer'] = $field ? self::options($field->getChoices(), (string) $ticket->getDeptId()) : array();
        }
        if ($ticket->checkStaffPerm($staff, Ticket::PERM_EDIT)) {
            // The ticket view's "Help Topic" field: the core decides which topics it offers.
            $field = AiActionsExecutor::topicField($ticket, $staff);
            $out['set_topic'] = $field ? self::options($field->getChoices(), (string) (int) $ticket->getTopicId()) : array();
            $out['link_parent'] = self::parents($ticket, $staff);
        }
        return array_filter($out);
    }

    /** Lead tickets to choose from, at most this many, newest first. */
    public const PARENTS_MAX = 20;

    /**
     * Open tickets of the same customer that this ticket can hang under:
     * not itself, not a sub-ticket themselves, visible to the agent. A
     * ticket that is a parent itself or already a sub-ticket gets none:
     * no chains, and moving a sub-ticket is a decision for a person.
     */
    private static function parents($ticket, $staff): array
    {
        if ($ticket->getPid() || self::hasChildren((int) $ticket->getId())) {
            return array();
        }
        $res = db_query(sprintf("SELECT t.ticket_id FROM `%s` t JOIN `%s` s ON s.id=t.status_id
            WHERE t.user_id=%d AND t.ticket_id<>%d AND (t.ticket_pid IS NULL OR t.ticket_pid=0) AND s.state='open'
            ORDER BY t.created DESC LIMIT %d", TICKET_TABLE, TICKET_STATUS_TABLE, (int) $ticket->getOwnerId(),
            (int) $ticket->getId(), self::PARENTS_MAX * 2));
        $out = array();
        while ($res && ($row = db_fetch_row($res)) && count($out) < self::PARENTS_MAX) {
            $t = Ticket::lookup((int) $row[0]);
            if (!$t || !$t->checkStaffPerm($staff)) {
                continue;
            }
            // The subject is the customer's text: one line, short, cleaned again in render().
            $subject = (string) $t->getSubject();
            if (preg_match_all('/./su', $subject) > 80) {
                $subject = implode('', array_slice(preg_split('//u', $subject, -1, PREG_SPLIT_NO_EMPTY) ?: array(), 0, 79)) . '…';
            }
            $out[] = array('value' => (string) $t->getId(), 'label' => sprintf('#%s %s (%s, %s)', $t->getNumber(),
                $subject, (string) $t->getStatus(), substr((string) $t->created, 0, 10)));
        }
        return $out;
    }

    public static function hasChildren(int $ticketId): bool
    {
        $res = db_query(sprintf('SELECT 1 FROM `%s` WHERE ticket_pid=%d LIMIT 1', TICKET_TABLE, $ticketId));
        return $res && db_num_rows($res) > 0;
    }

    /** "To: Name <mail>; Cc: …" for the preview. Public for the controller. */
    public static function recipients($ticket): string
    {
        $owner = $ticket->getOwner();
        $line = 'To: ' . ($owner ? trim($owner->getName() . ' <' . $owner->getEmail() . '>') : '?');
        $cc = array();
        foreach (($ticket->getActiveCollaborators() ?: array()) as $c) {
            if (!is_object($c)) {
                continue;
            }
            $cc[] = trim($c->getName() . ' <' . $c->getEmail() . '>');
        }
        return $cc ? $line . '; Cc: ' . implode(', ', $cc) : $line;
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
