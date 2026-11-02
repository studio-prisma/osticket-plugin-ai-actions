<?php

declare(strict_types=1);

/**
 * Rules: when an AI agent works on a ticket, what it may do, and whether a
 * human confirms first.
 *
 * A rule is a fixed chain: trigger → conditions → AI agent + task →
 * allowed actions → mode. Triggers only queue a job (AiActionsJobs); the
 * work happens in osTicket's cron (AiActionsAutomation).
 */
final class AiActionsRules
{
    public const TRIGGERS = array(
        'new_ticket'       => 'New ticket',
        'assigned'         => 'Assigned to the AI agent',
        'customer_message' => 'New customer message',
        'schedule'         => 'Schedule',
    );

    /** Actions a rule may allow; send_reply goes through the send guard. */
    public const ACTIONS = array(
        'add_internal_note' => 'Internal note',
        'set_priority'      => 'Priority',
        'assign_agent'      => 'Assign to agent',
        'assign_team'       => 'Assign to team',
        'transfer'          => 'Department',
        'set_due_date'      => 'Due date',
        'set_topic'         => 'Help topic',
        'link_parent'       => 'Parent ticket',
        'set_status'        => 'Status',
        'create_subticket'  => 'Sub-ticket',
        'draft_reply'       => 'Reply draft',
        'send_reply'        => 'Send reply (send guard)',
    );

    public const SCOPES = array('open' => 'all open tickets', 'overdue' => 'overdue tickets',
                                'stale' => 'open tickets without an update for N days');

    public const SOURCES = array('email' => 'Email', 'web' => 'Web', 'phone' => 'Phone', 'api' => 'API', 'other' => 'Other');

    /** Characters of a rule's task: room for a triage scheme. */
    public const TASK_MAX = 3000;

    /** Tickets queued per schedule run of one rule. */
    public const SCHEDULE_MAX = 20;

    /** @var bool */
    private static $ready = false;

    /** @var array|null */
    private static $cache;

    public static function table(): string
    {
        return TABLE_PREFIX . 'ai_actions_rule';
    }

    public static function ensure(): void
    {
        if (self::$ready) {
            return;
        }
        db_query(sprintf("CREATE TABLE IF NOT EXISTS `%s` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(80) NOT NULL,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `position` INT NOT NULL DEFAULT 0,
            `config` TEXT NOT NULL,
            `updated` DATETIME NOT NULL,
            PRIMARY KEY (`id`)
        ) DEFAULT CHARSET=utf8mb4", self::table()));
        self::$ready = true;
    }

    /** @return array<int, array> rules by ID, in order */
    public static function all(): array
    {
        if (self::$cache === null) {
            self::ensure();
            self::$cache = array();
            $res = db_query(sprintf('SELECT id, name, active, position, config FROM `%s` ORDER BY position, id', self::table()));
            while ($res && ($r = db_fetch_array($res))) {
                $c = json_decode((string) $r['config'], true);
                self::$cache[(int) $r['id']] = self::normalise(is_array($c) ? $c : array())
                    + array('id' => (int) $r['id'], 'name' => (string) $r['name'], 'active' => (bool) $r['active'],
                            'position' => (int) $r['position']);
            }
        }
        return self::$cache;
    }

    public static function get(int $id): ?array
    {
        return self::all()[$id] ?? null;
    }

    /**
     * A rule's settings from form input or storage: only known keys, IDs as
     * integers, bounded text. Public for the unit tests.
     */
    public static function normalise(array $in): array
    {
        $ints = static function ($v): array {
            $out = array();
            foreach ((array) $v as $x) {
                if (is_scalar($x) && ctype_digit((string) $x) && (int) $x > 0) {
                    $out[] = (int) $x;
                }
            }
            return array_values(array_unique(array_slice($out, 0, 100)));
        };
        $pick = static function ($v, array $allowed): array {
            return array_values(array_intersect(array_map('strval', (array) $v), $allowed));
        };
        $trigger = (string) ($in['trigger'] ?? '');
        $mode = (string) ($in['mode'] ?? '');
        return array(
            'trigger'      => isset(self::TRIGGERS[$trigger]) ? $trigger : 'new_ticket',
            'agent'        => is_scalar($in['agent'] ?? null) ? max(0, (int) $in['agent']) : 0,
            'task'         => AiActionsController::welcome($in['task'] ?? '', self::TASK_MAX) ?: '',
            'actions'      => $pick($in['actions'] ?? array(), array_keys(self::ACTIONS)),
            'mode'         => $mode === 'auto' ? 'auto' : 'propose',
            'depts'        => $ints($in['depts'] ?? array()),
            'topics'       => $ints($in['topics'] ?? array()),
            'priorities'   => $ints($in['priorities'] ?? array()),
            'statuses'     => $ints($in['statuses'] ?? array()),
            'sources'      => $pick($in['sources'] ?? array(), array_keys(self::SOURCES)),
            'contains'     => AiActionsController::cleanName(is_string($in['contains'] ?? null) ? $in['contains'] : '', ''),
            'sender'       => AiActionsController::cleanName(is_string($in['sender'] ?? null) ? $in['sender'] : '', ''),
            'every'        => max(15, min(1440, is_scalar($in['every'] ?? null) ? (int) $in['every'] : 60)),
            'scope'        => isset(self::SCOPES[(string) ($in['scope'] ?? '')]) ? (string) $in['scope'] : 'overdue',
            'days'         => max(1, min(365, is_scalar($in['days'] ?? null) ? (int) $in['days'] : 3)),
            'per_day'      => max(1, min(10, is_scalar($in['per_day'] ?? null) ? (int) $in['per_day'] : 1)),
            'stop'         => !empty($in['stop']),
        );
    }

    /**
     * Whether a ticket meets a rule's conditions. $t holds plain values so
     * the unit tests need no osTicket. Public for the unit tests.
     *
     * @param array{dept:int, topic:int, priority:int, status:int, source:string, text:string, sender?:string} $t
     */
    public static function matches(array $rule, array $t): bool
    {
        foreach (array('depts' => 'dept', 'topics' => 'topic', 'priorities' => 'priority', 'statuses' => 'status') as $k => $f) {
            if ($rule[$k] && !in_array((int) $t[$f], $rule[$k], true)) {
                return false;
            }
        }
        if ($rule['sources'] && !in_array(strtolower((string) $t['source']), $rule['sources'], true)) {
            return false;
        }
        return self::anyOf($rule['contains'], (string) $t['text'])
            && self::anyOf($rule['sender'] ?? '', (string) ($t['sender'] ?? ''));
    }

    /** Empty list, or one of its comma-separated words is in $haystack. */
    private static function anyOf(string $list, string $haystack): bool
    {
        $words = array_filter(array_map('trim', explode(',', $list)));
        foreach ($words as $word) {
            if (stripos($haystack, $word) !== false) {
                return true;
            }
        }
        return !$words;
    }

    /** Plain values of a ticket for matches(). */
    public static function facts($ticket): array
    {
        $text = (string) $ticket->getSubject();
        $last = $ticket->getThread()->getEntries()->filter(array('type' => 'M'))->order_by('-id')->first();
        if ($last && ($body = $last->getBody())) {
            $text .= "\n" . $body->getSearchable();
        }
        // Who wrote the latest customer message; the ticket's owner before there is one.
        $user = $last ? $last->getUser() : null;
        $sender = $user ? (string) $user->getEmail() : (string) $ticket->getEmail();
        $prio = $ticket->getPriority();
        return array(
            'dept'     => (int) $ticket->getDeptId(),
            'topic'    => (int) $ticket->getTopicId(),
            'priority' => $prio ? (int) $prio->getId() : 0,
            'status'   => (int) $ticket->getStatusId(),
            'source'   => (string) $ticket->getSource(),
            'text'     => $text,
            'sender'   => $sender,
        );
    }

    // ---------------------------------------------------------- triggers

    /** Queues a job for every active rule of this trigger that matches. */
    public static function fire(string $trigger, $ticket, int $assignee = 0): void
    {
        if (!$ticket || AiActionsController::settings()['auto_paused']) {
            return;
        }
        $facts = null;
        foreach (self::all() as $rule) {
            if (!$rule['active'] || $rule['trigger'] !== $trigger || !$rule['agent']) {
                continue;
            }
            if ($trigger === 'assigned' && $rule['agent'] !== $assignee) {
                continue;
            }
            $facts = $facts ?? self::facts($ticket);
            if (!self::matches($rule, $facts) || self::pending((int) $rule['id'], (int) $ticket->getId())) {
                continue;
            }
            AiActionsJobs::enqueue('rule', (int) $rule['id'], (int) $ticket->getId(), array('trigger' => $trigger));
            if ($rule['stop']) {
                break;
            }
        }
    }

    /** A job of this rule for this ticket is waiting or running already. */
    private static function pending(int $ruleId, int $ticketId): bool
    {
        $res = db_query(sprintf("SELECT 1 FROM `%s` WHERE rule_id=%d AND ticket_id=%d AND state IN ('queued','running') LIMIT 1",
            AiActionsJobs::table(), $ruleId, $ticketId));
        return $res && db_num_rows($res) > 0;
    }

    public static function onTicketCreated($ticket): void
    {
        if ($ticket instanceof Ticket) {
            self::fire('new_ticket', $ticket);
        }
    }

    public static function onEdited($object, $data): void
    {
        if (!($object instanceof Ticket) || ($data['type'] ?? '') !== 'assigned') {
            return;
        }
        $staffId = (int) $object->getStaffId();
        if ($staffId && AiActionsAgents::isAgent($staffId)) {
            self::fire('assigned', $object, $staffId);
        }
    }

    /** A customer's message on an existing ticket; the first one belongs to "new ticket". */
    public static function onEntry($entry): void
    {
        if (!($entry instanceof ThreadEntry) || $entry->getType() !== 'M') {
            return;
        }
        $thread = $entry->getThread();
        $ticket = $thread ? $thread->getObject() : null;
        if (!($ticket instanceof Ticket)) {
            return;
        }
        $first = $thread->getEntries()->order_by('id')->first();
        if ($first && (int) $first->getId() === (int) $entry->getId()) {
            return;
        }
        self::fire('customer_message', $ticket);
    }

    /** In the cron: schedule rules whose interval has passed queue their tickets. */
    public static function schedule(): void
    {
        foreach (self::all() as $rule) {
            if (!$rule['active'] || $rule['trigger'] !== 'schedule' || !$rule['agent']) {
                continue;
            }
            $key = 'rule_' . $rule['id'] . '_last';
            $last = (int) (AiActionsJobs::state($key) ?? 0);
            if (time() - $last < $rule['every'] * 60) {
                continue;
            }
            AiActionsJobs::setState($key, (string) time());
            $q = Ticket::objects()->filter(array('status__state' => 'open'))->order_by('lastupdate')->limit(self::SCHEDULE_MAX * 5);
            if ($rule['scope'] === 'overdue') {
                $q->filter(array('isoverdue' => 1));
            } elseif ($rule['scope'] === 'stale') {
                $q->filter(array('lastupdate__lt' => SqlFunction::NOW()->minus(SqlInterval::DAY($rule['days']))));
            }
            $queued = 0;
            foreach ($q as $ticket) {
                if ($queued >= self::SCHEDULE_MAX) {
                    break;
                }
                if (!self::matches($rule, self::facts($ticket)) || self::pending((int) $rule['id'], (int) $ticket->getId())
                        || AiActionsAutomation::runsToday((int) $rule['id'], (int) $ticket->getId()) >= $rule['per_day']) {
                    continue;
                }
                AiActionsJobs::enqueue('rule', (int) $rule['id'], (int) $ticket->getId(), array('trigger' => 'schedule'));
                $queued++;
            }
        }
    }

    // ------------------------------------------------------------- admin

    /** @return array{0:string, 1:string} tab, notice */
    public static function handle(string $do, array $post): array
    {
        self::ensure();
        $id = (int) ($post['rule_id'] ?? 0);
        switch ($do) {
            case 'rule_save':
                $name = AiActionsController::cleanName((string) ($post['name'] ?? ''), '');
                $config = self::normalise($post);
                if ($name === '' || !$config['agent'] || !AiActionsAgents::isAgent($config['agent']) || !$config['actions']) {
                    return array('rules', 'rule_incomplete');
                }
                $json = db_input((string) json_encode($config));
                if ($id && self::get($id)) {
                    db_query(sprintf('UPDATE `%s` SET name=%s, config=%s, position=%d, updated=NOW() WHERE id=%d',
                        self::table(), db_input($name), $json, (int) ($post['position'] ?? 0), $id));
                } else {
                    db_query(sprintf('INSERT INTO `%s` (name, active, position, config, updated) VALUES (%s, 1, %d, %s, NOW())',
                        self::table(), db_input($name), (int) ($post['position'] ?? 0), $json));
                }
                self::$cache = null;
                return array('rules', 'saved');
            case 'rule_on':
            case 'rule_off':
                db_query(sprintf('UPDATE `%s` SET active=%d WHERE id=%d', self::table(), $do === 'rule_on' ? 1 : 0, $id));
                self::$cache = null;
                return array('rules', 'saved');
            case 'rule_delete':
                db_query(sprintf('DELETE FROM `%s` WHERE id=%d', self::table(), $id));
                self::$cache = null;
                return array('rules', 'deleted');
            case 'log_undo':
                return array('log', AiActionsAutomation::undo((int) ($post['log_id'] ?? 0)) ? 'undone' : 'error');
        }
        return array('rules', 'error');
    }

    /** One rule as five boxes: what it does at a glance. Public for the unit tests. */
    public static function flow(array $rule, array $agents): string
    {
        $e = array('AiActionsAdmin', 'e');
        $agent = '?';
        foreach ($agents as $a) {
            if ($a['staff_id'] === $rule['agent']) {
                $agent = $a['name'];
            }
        }
        $trigger = self::TRIGGERS[$rule['trigger']] . ($rule['trigger'] === 'schedule'
            ? sprintf(', every %d min, %s', $rule['every'], str_replace('N', (string) $rule['days'], self::SCOPES[$rule['scope']])) : '');
        $conds = array();
        foreach (array('depts' => 'departments', 'topics' => 'help topics', 'priorities' => 'priorities', 'statuses' => 'statuses') as $k => $l) {
            if ($rule[$k]) {
                $conds[] = count($rule[$k]) . ' ' . $l;
            }
        }
        if ($rule['sources']) {
            $conds[] = implode('/', $rule['sources']);
        }
        if ($rule['contains'] !== '') {
            $conds[] = 'text contains "' . $rule['contains'] . '"';
        }
        if ($rule['sender'] !== '') {
            $conds[] = 'sender contains "' . $rule['sender'] . '"';
        }
        $acts = array_map(static function ($a) {
            return self::ACTIONS[$a];
        }, $rule['actions']);
        $boxes = array($trigger, $conds ? implode(', ', $conds) : 'every ticket', 'AI agent ' . $agent,
                       implode(', ', $acts) ?: 'nothing', $rule['mode'] === 'auto' ? 'runs automatically' : 'proposal, a human confirms');
        return '<div class="spa-flow">' . implode('<i>→</i>', array_map(static function ($b) use ($e) {
            return '<span>' . call_user_func($e, $b) . '</span>';
        }, $boxes)) . '</div>';
    }

    public static function adminView(): string
    {
        $e = array('AiActionsAdmin', 'e');
        $agents = AiActionsAgents::all();
        $edit = isset($_GET['edit']) ? self::get((int) $_GET['edit']) : null;
        $out = '<h2>Rules</h2><p class="faded">Checked in order. Triggers queue the work; osTicket\'s cron does it '
            . '(see Status). Automatic rules run with the AI agent\'s role; proposals run with the rights of the agent who confirms.</p>';
        if (AiActionsController::settings()['auto_paused']) {
            $out .= '<div id="msg_warning">All automatic work is paused in the plugin settings.</div>';
        }
        if (!$agents) {
            return $out . '<p>Create an AI agent first.</p>';
        }
        foreach (self::all() as $r) {
            $out .= sprintf('<div class="spa-rule"><h3>%s %s <small>%s</small></h3>%s<p>%s %s %s</p></div>',
                call_user_func($e, $r['name']), $r['active'] ? '' : '<em>(off)</em>', call_user_func($e, '#' . $r['id']),
                self::flow($r, $agents),
                sprintf('<a class="button" href="%s">Edit</a>', call_user_func($e, ROOT_PATH . AiActionsPlugin::STAFF_BASE . '/admin?tab=rules&edit=' . $r['id'])),
                AiActionsAdmin::button($r['active'] ? 'rule_off' : 'rule_on', $r['active'] ? 'Switch off' : 'Switch on', array('rule_id' => (string) $r['id'])),
                AiActionsAdmin::button('rule_delete', 'Delete', array('rule_id' => (string) $r['id'])));
        }
        return $out . '<h3>' . ($edit ? 'Edit rule' : 'New rule') . '</h3>' . self::formView($edit, $agents);
    }

    private static function formView(?array $r, array $agents): string
    {
        $e = array('AiActionsAdmin', 'e');
        $r = $r ?? (self::normalise(array()) + array('id' => 0, 'name' => '', 'position' => 0));
        $opt = static function (array $choices, $selected, bool $multi = false) use ($e): string {
            $out = '';
            foreach ($choices as $v => $l) {
                $on = $multi ? in_array((string) $v, array_map('strval', (array) $selected), true) : (string) $v === (string) $selected;
                $out .= sprintf('<option value="%s"%s>%s</option>', call_user_func($e, (string) $v), $on ? ' selected' : '', call_user_func($e, (string) $l));
            }
            return $out;
        };
        $agentChoices = array();
        foreach ($agents as $a) {
            $agentChoices[$a['staff_id']] = $a['name'];
        }
        $topics = array();
        foreach (Topic::getHelpTopics() as $id => $name) {
            $topics[$id] = $name;
        }
        $prios = array();
        foreach (Priority::getPriorities() as $id => $name) {
            $prios[$id] = $name;
        }
        $statuses = array();
        foreach (TicketStatus::objects() as $s) {
            $statuses[$s->getId()] = $s->getName();
        }
        $actions = '';
        foreach (self::ACTIONS as $k => $l) {
            $actions .= sprintf('<label style="display:inline-block;margin-right:12px"><input type="checkbox" name="actions[]" value="%s"%s> %s</label>',
                $k, in_array($k, $r['actions'], true) ? ' checked' : '', call_user_func($e, $l));
        }
        $row = static function (string $label, string $field, string $hint = '') use ($e): string {
            return sprintf('<tr><td width="200">%s</td><td>%s%s</td></tr>', call_user_func($e, $label), $field,
                $hint !== '' ? '<br><span class="faded">' . call_user_func($e, $hint) . '</span>' : '');
        };
        $html = '<table class="form_table" width="100%"><tbody>'
            . $row('Name', sprintf('<input name="name" maxlength="80" size="40" required value="%s">', call_user_func($e, $r['name'])))
            . $row('Trigger', '<select name="trigger">' . $opt(self::TRIGGERS, $r['trigger']) . '</select>')
            . $row('Schedule', sprintf('every <input name="every" size="4" value="%d"> minutes, for <select name="scope">%s</select>, N = <input name="days" size="3" value="%d"> days',
                $r['every'], $opt(self::SCOPES, $r['scope']), $r['days']), 'Only for the trigger "Schedule". At most 20 tickets per run.')
            . $row('Departments', '<select name="depts[]" multiple size="4">' . $opt(Dept::getDepartments(), $r['depts'], true) . '</select>', 'Nothing selected: all.')
            . $row('Help topics', '<select name="topics[]" multiple size="4">' . $opt($topics, $r['topics'], true) . '</select>')
            . $row('Priorities', '<select name="priorities[]" multiple size="4">' . $opt($prios, $r['priorities'], true) . '</select>')
            . $row('Statuses', '<select name="statuses[]" multiple size="4">' . $opt($statuses, $r['statuses'], true) . '</select>')
            . $row('Sources', '<select name="sources[]" multiple size="5">' . $opt(self::SOURCES, $r['sources'], true) . '</select>')
            . $row('Text contains', sprintf('<input name="contains" size="40" maxlength="60" value="%s">', call_user_func($e, $r['contains'])),
                'Subject or latest customer message; several words separated by commas, any one matches.')
            . $row('Sender contains', sprintf('<input name="sender" size="40" maxlength="60" value="%s">', call_user_func($e, $r['sender'])),
                'Email address of whoever wrote the latest customer message, e.g. "noreply@example.com" or "@example.com"; '
                . 'several separated by commas, any one matches. Combined with the other conditions, all must match.')
            . $row('AI agent', '<select name="agent">' . $opt($agentChoices, $r['agent']) . '</select>')
            . $row('Task', sprintf('<textarea name="task" rows="8" cols="70" maxlength="%d">%s</textarea>', self::TASK_MAX, call_user_func($e, $r['task'])),
                'What the AI agent should do, e.g. "Set the priority from the urgency the customer describes and assign the right team."')
            . $row('Allowed actions', $actions, 'The AI agent can only plan these. Its role still decides what is possible.')
            . $row('Mode', '<select name="mode">' . $opt(array('propose' => 'Proposal: a human confirms in the ticket', 'auto' => 'Automatic'), $r['mode']) . '</select>')
            . $row('Runs per ticket and day', sprintf('<input name="per_day" size="3" value="%d">', $r['per_day']))
            . $row('Order', sprintf('<input name="position" size="3" value="%d">', $r['position'])
                . sprintf(' <label><input type="checkbox" name="stop" value="1"%s> no further rules after this one</label>', $r['stop'] ? ' checked' : ''))
            . '</tbody></table>'
            . sprintf('<input type="hidden" name="rule_id" value="%d"><p><input type="submit" class="button" value="Save rule"></p>', (int) $r['id']);
        return AiActionsAdmin::form('rule_save', $html);
    }

    public static function logView(): string
    {
        return AiActionsAutomation::logView();
    }
}
