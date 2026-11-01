<?php

declare(strict_types=1);

/**
 * What the model gets to read about the current ticket, and nothing else.
 *
 * Everything here is data that customers or agents wrote. It goes into one
 * block that the system prompt declares untrusted; the block's closing tag
 * is neutralised inside the content so a message can't end the block early.
 */
final class AiActionsContext
{
    /** Upper bound for the thread part, in characters. Newest entries win. */
    public const THREAD_MAX = 12000;

    /** Per entry, so one huge mail can't push out everything else. */
    public const ENTRY_MAX = 4000;

    /** Sub-tickets listed per ticket. */
    public const RELATED_MAX = 20;

    /**
     * Facts always; thread entries newer than $since only (all on the first
     * turn of a conversation). Times are the agent's wall-clock time in $tz.
     *
     * @return array{text:string, last:int} last = highest entry ID seen
     */
    public static function data($ticket, $staff, string $tz, int $since = 0, int $max = self::THREAD_MAX): array
    {
        $dbTz = AiActionsTime::dbZone();
        $owner = $ticket->getOwner();
        $org = $owner && $owner->getOrganization() ? (string) $owner->getOrganization()->getName() : '';
        $status = $ticket->getStatus();
        $facts = array(
            'Number'     => '#' . $ticket->getNumber(),
            'Subject'    => (string) $ticket->getSubject(),
            'Status'     => $status ? (string) $status->getName() : '',
            'Priority'   => (string) $ticket->getPriority(),
            'Department' => (string) $ticket->getDeptName(),
            'Assigned'   => (string) $ticket->getAssignee() ?: '(nobody)',
            'Due date'   => AiActionsTime::fromDb($ticket->getDueDate(), $dbTz, $tz) ?: '(none set)',
            'Customer'   => trim((string) $ticket->getName() . ($org !== '' ? " ($org)" : '')),
        );
        // Without its own due date, osTicket shows the SLA due date in the same place.
        $sla = AiActionsTime::fromDb((string) ($ticket->est_duedate ?? ''), $dbTz, $tz);
        if ($sla !== '') {
            $facts['SLA due date'] = $sla . ' (from the SLA plan; shown as due date while none is set)';
        }

        $entries = array();
        $last = $since;
        foreach ($ticket->getThread()->getEntries()->filter(array('id__gt' => $since))->order_by('-id') as $e) {
            $body = $e->getBody();
            $last = max($last, (int) $e->getId());
            $entries[] = array(
                'type'    => (string) $e->getType(),
                'poster'  => (string) $e->getPoster(),
                'created' => AiActionsTime::fromDb($e->getCreateDate(), $dbTz, $tz),
                'text'    => $body ? (string) $body->getSearchable() : '',
            );
        }

        return array('text' => self::render($facts, $entries, $since > 0, $max, self::related($ticket, $staff, $dbTz, $tz),
                     AiActionsReaders::listing($ticket)), 'last' => $last);
    }

    /**
     * Parent ticket and sub-tickets, one line each. Same rule as the Ticket
     * Board: the relation is ticket_pid, and only tickets the agent may see
     * are listed. Their content stays out; the agent opens them for that.
     *
     * @return string[]
     */
    private static function related($ticket, $staff, string $dbTz, string $tz): array
    {
        $lines = array();
        $pid = (int) ($ticket->ticket_pid ?? 0);
        if ($pid && ($parent = Ticket::lookup($pid)) && $parent->checkStaffPerm($staff)) {
            $lines[] = 'Parent ticket: ' . self::line($parent, $dbTz, $tz);
        }
        $children = Ticket::objects()
            ->filter(array('ticket_pid' => (int) $ticket->getId()))
            ->filter($staff->getTicketsVisibility())
            ->distinct('ticket_id')
            ->order_by('ticket_id')
            ->limit(self::RELATED_MAX + 1);
        $count = 0;
        foreach ($children as $child) {
            if (++$count > self::RELATED_MAX) {
                $lines[] = 'Sub-ticket: (more not listed)';
                break;
            }
            $lines[] = 'Sub-ticket: ' . self::line($child, $dbTz, $tz);
        }
        if (!$lines) {
            $lines[] = 'No parent ticket and no sub-tickets.';
        }
        return $lines;
    }

    private static function line($t, string $dbTz, string $tz): string
    {
        $status = $t->getStatus();
        $due = AiActionsTime::fromDb($t->getDueDate(), $dbTz, $tz);
        return sprintf('#%s "%s", status %s, assigned %s%s', $t->getNumber(), (string) $t->getSubject(),
            $status ? (string) $status->getName() : '?', (string) $t->getAssignee() ?: '(nobody)',
            $due !== '' ? ', due ' . $due : '');
    }

    /**
     * @param array<string,string> $facts
     * @param array<int,array{type:string,poster:string,created:string,text:string}> $entries newest first
     * @param bool $update later turn of a conversation: only new entries
     * @param string[] $related parent and sub-tickets, one line each
     * @param string[] $files attachments, one line each (read_attachment)
     * Public for the unit tests.
     */
    public static function render(array $facts, array $entries, bool $update = false, int $max = self::THREAD_MAX,
                                  array $related = array(), array $files = array()): string
    {
        $lines = array($update ? 'Ticket facts now:' : 'Ticket facts:');
        foreach ($facts as $k => $v) {
            $lines[] = "- $k: " . self::clean($v, 300);
        }
        if ($related) {
            $lines[] = '';
            $lines[] = 'Related tickets (only those the agent may open):';
            foreach ($related as $r) {
                $lines[] = '- ' . self::clean($r, 400);
            }
        }
        if ($files) {
            $lines[] = '';
            $lines[] = 'Attachments (read them with read_attachment):';
            foreach ($files as $f) {
                $lines[] = '- ' . self::clean($f, 200);
            }
        }
        $lines[] = '';
        if ($update && !$entries) {
            $lines[] = 'No new thread entries since the last message.';
        } else {
            $lines[] = $update
                ? 'New thread entries since the last message, newest first (local time of the agent):'
                : 'Thread, newest first (local time of the agent):';
        }

        $used = 0;
        $skipped = 0;
        foreach ($entries as $e) {
            $label = array('M' => 'customer message', 'R' => 'agent reply', 'N' => 'internal note')[$e['type']] ?? 'entry';
            $chunk = sprintf("\n[%s] %s by %s:\n%s\n", self::clean($e['created'], 40), $label,
                self::clean($e['poster'], 120), self::clean($e['text'], self::ENTRY_MAX));
            $len = self::length($chunk);
            if ($used + $len > $max) {
                $skipped++;
                continue;
            }
            $used += $len;
            $lines[] = $chunk;
        }
        if ($skipped) {
            $lines[] = "\n($skipped older entries left out.)";
        }

        return "<ticket_data>\n" . implode("\n", $lines) . "\n</ticket_data>";
    }

    /**
     * The block tags the prompt is built from. Text from a ticket must not
     * contain any of them, so it can neither end its own block nor fake an
     * agent request, a plan result or the list of allowed values.
     */
    public const CONTROL_TAGS = '#<\s*/?\s*(ticket_data|agent_request|plan_result|ticket_options|attachment_data)\b[^>]*>#i';

    /** Valid UTF-8, no block tag, bounded length. */
    private static function clean(string $s, int $max): string
    {
        if (!preg_match('//u', $s)) {
            $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        }
        $s = preg_replace(self::CONTROL_TAGS, '[tag removed]', $s) ?? '';
        if (self::length($s) > $max) {
            $s = implode('', array_slice(preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: array(), 0, $max)) . ' […]';
        }
        return $s;
    }

    private static function length(string $s): int
    {
        return (int) preg_match_all('/./su', $s);
    }
}
