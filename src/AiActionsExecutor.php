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
    /** Sub-tickets one automatic run may create. */
    public const SUBTICKETS_PER_RUN = 3;

    /**
     * True while subticket() creates a ticket. osTicket sends "ticket.created"
     * from inside Ticket::create(), before ticket_pid is set; the rules use
     * this to leave the plugin's own sub-tickets alone.
     *
     * @var bool
     */
    private static $creating = false;

    public static function creatingSubticket(): bool
    {
        return self::$creating;
    }

    /**
     * @param bool $assignedInRun an earlier change of the same plan assigned the ticket
     * @return array{ok:bool, error?:string, message?:string, previous?:mixed}
     */
    /**
     * @param bool $ruleSend a rule allowed send_reply (its send guard passed); replaces the chat's setting
     * @param bool $alert osTicket's assignment alert; an automatic run sends its own after its note
     */
    public static function run($ticket, $staff, string $action, array $input, bool $assignedInRun = false, bool $ruleSend = false,
                               bool $alert = true): array
    {
        $value = (string) (AiActionsTools::choiceValue($action, $input) ?? '');
        switch ($action) {
            case 'set_due_date':
                return self::dueDate($ticket, $staff, (string) ($input['due'] ?? ''));
            case 'set_priority':
                return self::priority($ticket, $staff, $value);
            case 'assign_agent':
            case 'assign_team':
                return self::assign($ticket, $staff, $action === 'assign_agent' ? 'agents' : 'teams', $value, $alert);
            case 'transfer':
                return self::transfer($ticket, $staff, $value);
            case 'set_status':
                return self::status($ticket, $staff, $value, $assignedInRun);
            case 'set_topic':
                return self::topic($ticket, $staff, $value);
            case 'link_parent':
                return self::linkParent($ticket, $staff, $value, (string) ($input['note'] ?? ''));
            case 'add_internal_note':
                return self::note($ticket, $staff, (string) ($input['text'] ?? ''));
            case 'draft_reply':
                return self::draft($ticket, $staff, (string) ($input['text'] ?? ''));
            case 'create_subticket':
                return self::subticket($ticket, $staff, (string) ($input['subject'] ?? ''), (string) ($input['message'] ?? ''));
            case 'send_reply':
                return self::reply($ticket, $staff, (string) ($input['text'] ?? ''), $ruleSend);
        }
        if (strpos($action, 'x_') === 0) {
            return AiActionsExtensions::run($action, $input, $ticket, $staff);
        }
        return self::fail('rejected');
    }

    /**
     * The reply form with its defaults: to the owner and active
     * collaborators, the agent's signature if they have one, no status
     * change, no auto-claim. Sending must still be allowed in the settings
     * when the plan runs.
     */
    private static function reply($ticket, $staff, string $text, bool $ruleSend = false): array
    {
        if (!$ruleSend && !AiActionsController::settings()['allow_send']) {
            return self::fail('send_off');
        }
        if (!$ticket->checkStaffPerm($staff, Ticket::PERM_REPLY)) {
            return self::fail('forbidden');
        }
        $errors = array();
        $vars = array(
            'response'  => self::html($text),
            'reply-to'  => 'all',
            'signature' => $staff->getSignature() ? 'mine' : 'none',
            'poster'    => $staff,
            'staffId'   => (int) $staff->getId(),
        );
        $sent = $ticket->postReply($vars, $errors, true, false);
        return $sent ? array('ok' => true) : self::fail('rejected', $errors);
    }

    /** "Post Internal Note" in the ticket view: any agent who can see the ticket. */
    private static function note($ticket, $staff, string $text): array
    {
        if (!$ticket->checkStaffPerm($staff)) {
            return self::fail('forbidden');
        }
        $errors = array();
        $note = $ticket->postNote(array('title' => '', 'note' => self::textBody($text)), $errors, $staff);
        return $note ? array('ok' => true) : self::fail('rejected', $errors);
    }

    /**
     * A draft in the agent's own reply field (namespace ticket.response.<id>,
     * the one the ticket view loads). Never sent from here. An existing
     * draft is kept: it may hold the agent's own unsent work.
     */
    private static function draft($ticket, $staff, string $text): array
    {
        if (!$ticket->checkStaffPerm($staff, Ticket::PERM_REPLY)) {
            return self::fail('forbidden');
        }
        $namespace = 'ticket.response.' . $ticket->getId();
        if (Draft::lookupByNamespaceAndStaff($namespace, $staff->getId())) {
            return self::fail('draft_exists');
        }
        $draft = Draft::create(array('namespace' => $namespace, 'body' => self::html($text)));
        if ((int) $draft->staff_id !== (int) $staff->getId() || !$draft->save()) {
            return self::fail('rejected');
        }
        return array('ok' => true);
    }

    /**
     * Plain text for a thread entry. osTicket's TextThreadEntryBody alone
     * is not enough: its clean-up balances HTML and cuts "if a<b then"
     * after "if a". Escaped first (htmlchars doesn't encode twice), the
     * text arrives whole and shows as written.
     */
    public static function textBody(string $text)
    {
        return new TextThreadEntryBody(Format::htmlchars($text));
    }

    /** Plain text as the reply editor's HTML: escaped, paragraphs and line breaks kept. */
    public static function html(string $text): string
    {
        $out = array();
        foreach (preg_split('/\n{2,}/', trim($text)) as $para) {
            $out[] = '<p>' . str_replace("\n", '<br>', htmlspecialchars($para, ENT_QUOTES, 'UTF-8')) . '</p>';
        }
        return implode('', $out);
    }

    /**
     * A new ticket for the same customer and department, linked by
     * ticket_pid (how sub-ticket plugins and the Ticket Board read it).
     * Created with origin "staff" like the agent's own "New Ticket", which
     * needs a help topic: the parent's, else the default, else the first.
     *
     * osTicket posts a new ticket's first message in the customer's name.
     * That message is a fixed line here; the planned text goes into an
     * internal note of the agent, as plain text. Otherwise model output
     * would stand in the thread as something the customer wrote, as HTML,
     * and the send guard would count it as the customer's own words.
     *
     * A sub-ticket gets no sub-tickets of its own: one level, like the
     * Ticket Board shows it, and no chain of automatic runs.
     */
    private static function subticket($ticket, $staff, string $subject, string $message): array
    {
        global $cfg;

        $role = $ticket->getRole($staff);
        if (!$role || !$role->hasPerm(Ticket::PERM_CREATE)) {
            return self::fail('forbidden');
        }
        if (self::parentOf($ticket) > 0) {
            return self::fail('is_subticket');
        }
        $topics = Topic::getHelpTopics(false, false) ?: array();
        $topic = (int) ($ticket->getTopicId() ?: ($cfg ? $cfg->getDefaultTopicId() : 0) ?: key($topics));
        $errors = array();
        self::$creating = true;
        try {
            $child = Ticket::create(array(
                'uid'     => $ticket->getOwnerId(),
                'deptId'  => $ticket->getDeptId(),
                'topicId' => $topic,
                'subject' => $subject,
                'message' => self::textBody(self::subticketLine((string) $ticket->getNumber(), (string) $staff->getName())),
                'source'  => 'Other',
                'ip'      => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            ), $errors, 'staff', false, false);
        } finally {
            self::$creating = false;
        }
        if (!$child) {
            return self::fail('rejected', $errors);
        }
        $child->ticket_pid = $ticket->getId();
        $child->save();
        $noteErrors = array();
        $note = $child->postNote(array('title' => $subject, 'note' => self::textBody($message)), $noteErrors, $staff, false);
        $r = array('ok' => true, 'created' => (string) $child->getNumber());
        // The sub-ticket exists either way; say so rather than report a failure that can't be undone.
        return $note ? $r : $r + array('message' => 'Text of the sub-ticket not saved: ' . self::firstError($noteErrors));
    }

    /**
     * The parent's ID as stored. Within one request (a cron run works on
     * several jobs) a looked-up ticket can be an older copy without the
     * ticket_pid set since, so the object alone is not enough.
     */
    public static function parentOf($ticket): int
    {
        $pid = (int) $ticket->getPid();
        if ($pid > 0) {
            return $pid;
        }
        $res = db_query(sprintf('SELECT ticket_pid FROM %s WHERE ticket_id=%d', TICKET_TABLE, (int) $ticket->getId()));
        $row = $res ? db_fetch_row($res) : null;
        return $row ? (int) $row[0] : 0;
    }

    /** The first message of a sub-ticket: no model text in the customer's name. Public for the unit tests. */
    public static function subticketLine(string $parentNumber, string $agentName): string
    {
        return sprintf('Sub-ticket of #%s, created by %s. The details are in the internal note.',
            $parentNumber, AiActionsController::cleanName($agentName, 'an agent'));
    }

    /**
     * Puts back what one change replaced, with the permission check of the
     * same dialog. `$previous` is what run() returned for that change.
     *
     * @return array{ok:bool, error?:string, message?:string}
     */
    public static function undo($ticket, $staff, string $action, $previous): array
    {
        switch ($action) {
            case 'set_due_date':
                return self::undoDueDate($ticket, $staff, $previous['duedate'] ?? null);
            case 'set_priority':
                $p = (string) ($previous['priority'] ?? '');
                return $p === '' ? self::fail('rejected') : self::priority($ticket, $staff, $p);
            case 'assign_agent':
            case 'assign_team':
                return self::undoAssign($ticket, $staff, (int) ($previous['staff'] ?? 0), (int) ($previous['team'] ?? 0));
            case 'transfer':
                return self::transfer($ticket, $staff, (string) (int) $previous);
            case 'set_status':
                // Back to the old status; reopening must not hand the ticket to whoever undoes.
                return self::status($ticket, $staff, (string) (int) $previous, true);
            case 'set_topic':
                return self::undoTopic($ticket, $staff, (int) ($previous['topic'] ?? 0), (int) ($previous['sla'] ?? 0));
            case 'link_parent':
                if (!$ticket->checkStaffPerm($staff, Ticket::PERM_EDIT)) {
                    return self::fail('forbidden');
                }
                $ticket->setPid(((int) $previous) ?: null);
                return $ticket->save() ? array('ok' => true) : self::fail('rejected');
        }
        return self::fail('rejected');
    }

    /**
     * The ticket view's "Help Topic" edit. A topic with an SLA plan brings
     * it along, through the SLA field's own edit, which recomputes the due
     * date (core does that only for SLA and due date changes, not for the
     * topic).
     */
    /**
     * The "Help Topic" field with the topics of $staff. The core field asks
     * the signed-in agent ($thisstaff), who doesn't exist in the cron, and
     * would be the wrong person for an AI agent anyway.
     */
    public static function topicField($ticket, $staff)
    {
        $field = $ticket->getField('topic');
        if ($field && property_exists($field, 'topics')) {
            $field->topics = $staff->getTopicNames(false, false);
        }
        return $field;
    }

    private static function topic($ticket, $staff, string $value): array
    {
        if (!$ticket->checkStaffPerm($staff, Ticket::PERM_EDIT) || !($field = self::topicField($ticket, $staff))) {
            return self::fail('forbidden');
        }
        if (!ctype_digit($value) || !self::listed($field->getChoices(), $value) || !($topic = Topic::lookup((int) $value))) {
            return self::fail('rejected');
        }
        $previous = array('topic' => (int) $ticket->getTopicId(), 'sla' => (int) $ticket->getSLAId());
        if ($previous['topic'] === (int) $value) {
            return array('ok' => true, 'unchanged' => true, 'previous' => $previous);
        }
        $r = self::editField($ticket, $field, $value, $previous);
        $sla = (int) $topic->getSLAId();
        if (!$r['ok'] || !$sla || $sla === $previous['sla']) {
            return $r;
        }
        $ticket = Ticket::lookup($ticket->getId());
        $s = self::editField($ticket, $ticket->getField('sla'), (string) $sla, $previous);
        // The topic changed either way; undo must still be able to put it back.
        return $s['ok'] ? $r : $r + array('message' => 'SLA plan of the topic not applied: ' . ($s['message'] ?? $s['error']));
    }

    /** Topic and SLA back as they were; set directly, the old values may be disabled by now. */
    private static function undoTopic($ticket, $staff, int $topic, int $sla): array
    {
        if (!$ticket->checkStaffPerm($staff, Ticket::PERM_EDIT)) {
            return self::fail('forbidden');
        }
        $ticket->topic_id = $topic;
        $ticket->sla_id = $sla;
        if (!$ticket->save()) {
            return self::fail('rejected');
        }
        $ticket->logEvent('edited', array('topic_id' => array(null, $topic), 'sla_id' => array(null, $sla)));
        $ticket->updateEstDueDate();
        return array('ok' => true);
    }

    /**
     * Hangs the ticket under an open lead ticket of the same customer
     * (ticket_pid, as sub-tickets are linked). The note goes onto the lead
     * ticket first: without it the link would lose what this ticket said.
     */
    private static function linkParent($ticket, $staff, string $value, string $note): array
    {
        if (!$ticket->checkStaffPerm($staff, Ticket::PERM_EDIT)) {
            return self::fail('forbidden');
        }
        if ($ticket->getPid() || AiActionsChoices::hasChildren((int) $ticket->getId())) {
            return self::fail('is_parent');
        }
        $parent = ctype_digit($value) ? Ticket::lookup((int) $value) : null;
        if (!$parent || (int) $parent->getId() === (int) $ticket->getId() || !$parent->isOpen() || $parent->getPid()
                || (int) $parent->getOwnerId() !== (int) $ticket->getOwnerId() || !$parent->checkStaffPerm($staff)) {
            return self::fail('rejected');
        }
        $errors = array();
        $posted = $parent->postNote(array(
            'title' => sprintf('Sub-ticket #%s', $ticket->getNumber()),
            'note'  => self::textBody($note . "\n\n#" . $ticket->getNumber() . ' ' . $ticket->getSubject()),
        ), $errors, $staff);
        if (!$posted) {
            return self::fail('rejected', $errors);
        }
        $previous = (int) $ticket->getPid();
        $ticket->setPid((int) $parent->getId());
        if (!$ticket->save()) {
            return self::fail('rejected');
        }
        return array('ok' => true, 'previous' => $previous);
    }

    /**
     * Sets the stored due date directly: the edit form refuses dates in the
     * past, and the old one may well be in the past by now.
     */
    private static function undoDueDate($ticket, $staff, $previous): array
    {
        if (!$ticket->checkStaffPerm($staff, Ticket::PERM_EDIT)) {
            return self::fail('forbidden');
        }
        $previous = is_string($previous) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $previous) ? $previous : null;
        $ticket->duedate = $previous;
        if (!$ticket->save()) {
            return self::fail('rejected');
        }
        return array('ok' => true);
    }

    /** Back to the previous agent and team, or unassigned (TicketsAjaxAPI::release()). */
    private static function undoAssign($ticket, $staff, int $agentId, int $teamId): array
    {
        if ($agentId === 0 && $teamId === 0) {
            if (!$ticket->checkStaffPerm($staff, Ticket::PERM_RELEASE) && !$staff->isManager()) {
                return self::fail('forbidden');
            }
            $vars = array();
            if ($agent = $ticket->getStaff()) {
                $vars['sid'] = array($agent->getId());
            }
            if ($team = $ticket->getTeam()) {
                $vars['tid'] = array($team->getId());
            }
            $errors = array();
            if ($vars && !$ticket->release($vars, $errors)) {
                return self::fail('rejected', $errors);
            }
            return array('ok' => true);
        }
        $r = array('ok' => true);
        if ($agentId) {
            $r = self::assign($ticket, $staff, 'agents', 's' . $agentId);
        }
        if ($r['ok'] && $teamId) {
            $r = self::assign($ticket, $staff, 'teams', 't' . $teamId);
        }
        return $r;
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
        // The model's time is wall-clock time for the agent. Without an offset
        // osTicket would read it as UTC (17:00 shown as 18:00 in Berlin).
        $value = AiActionsTime::withOffset($due, AiActionsTime::zone($staff));
        if ($value === null) {
            return self::fail('rejected');
        }
        $previous = $ticket->getDueDate();
        return self::editField($ticket, $field, $value, array('duedate' => $previous));
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
    private static function assign($ticket, $staff, string $target, string $value, bool $alert = true): array
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
        if (!$form || !$form->isValid() || !$ticket->assign($form, $errors, $alert)) {
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
            return array('ok' => true, 'unchanged' => true, 'previous' => $previous);
        }
        $errors = array();
        if (!$ticket->setStatus($status, '', $errors, !$keepAssignee)) {
            return self::fail('rejected', $errors);
        }
        return array('ok' => true, 'previous' => $previous);
    }

    /**
     * A set_status to the status the ticket already has. The choice lists
     * leave the current status out, so the checks would refuse it; it is
     * "unchanged" instead. Null for anything else.
     *
     * @param mixed $input
     * @return array{ok:bool, unchanged:bool, previous:int, value:string}|null
     */
    public static function sameStatus($ticket, string $action, $input): ?array
    {
        $value = is_array($input) && is_string($input['status'] ?? null) ? trim($input['status']) : '';
        if ($action !== 'set_status' || !ctype_digit($value) || (int) $value !== (int) $ticket->getStatusId()) {
            return null;
        }
        $status = TicketStatus::lookup((int) $value);
        return array('ok' => true, 'unchanged' => true, 'previous' => (int) $value,
                     'value' => $status ? (string) $status->getLocalName() : $value);
    }

    /**
     * An assign_agent/assign_team to whoever already has the ticket. The
     * core's assignment lists leave the current assignee out, so the checks
     * would refuse it; it is "unchanged" instead. Null for anything else.
     *
     * @param mixed $input
     * @return array{ok:bool, unchanged:bool, value:string}|null
     */
    public static function sameAssignee($ticket, string $action, $input): ?array
    {
        $key = $action === 'assign_agent' ? 'agent' : ($action === 'assign_team' ? 'team' : '');
        $value = $key !== '' && is_array($input) && is_string($input[$key] ?? null) ? trim($input[$key]) : '';
        if ($key === 'agent' && preg_match('/^s([1-9]\d{0,9})$/', $value, $m) && (int) $m[1] === (int) $ticket->getStaffId()) {
            $who = $ticket->getStaff();
        } elseif ($key === 'team' && preg_match('/^t([1-9]\d{0,9})$/', $value, $m) && (int) $m[1] === (int) $ticket->getTeamId()) {
            $who = $ticket->getTeam();
        } else {
            return null;
        }
        return array('ok' => true, 'unchanged' => true, 'value' => $who ? (string) $who->getName() : $value);
    }

    /** sameStatus() or sameAssignee(): a change to what the ticket already has. */
    public static function same($ticket, string $action, $input): ?array
    {
        return self::sameStatus($ticket, $action, $input) ?? self::sameAssignee($ticket, $action, $input);
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
