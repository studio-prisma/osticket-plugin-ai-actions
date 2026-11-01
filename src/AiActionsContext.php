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

    /**
     * Facts always; thread entries newer than $since only (all on the first
     * turn of a conversation).
     *
     * @return array{text:string, last:int} last = highest entry ID seen
     */
    public static function data($ticket, int $since = 0): array
    {
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
            'Due date'   => (string) $ticket->getDueDate() ?: '(none)',
            'Customer'   => trim((string) $ticket->getName() . ($org !== '' ? " ($org)" : '')),
        );

        $entries = array();
        $last = $since;
        foreach ($ticket->getThread()->getEntries()->filter(array('id__gt' => $since))->order_by('-id') as $e) {
            $body = $e->getBody();
            $last = max($last, (int) $e->getId());
            $entries[] = array(
                'type'    => (string) $e->getType(),
                'poster'  => (string) $e->getPoster(),
                'created' => (string) $e->getCreateDate(),
                'text'    => $body ? (string) $body->getSearchable() : '',
            );
        }

        return array('text' => self::render($facts, $entries, $since > 0), 'last' => $last);
    }

    /**
     * @param array<string,string> $facts
     * @param array<int,array{type:string,poster:string,created:string,text:string}> $entries newest first
     * @param bool $update later turn of a conversation: only new entries
     * Public for the unit tests.
     */
    public static function render(array $facts, array $entries, bool $update = false): string
    {
        $lines = array($update ? 'Ticket facts now:' : 'Ticket facts:');
        foreach ($facts as $k => $v) {
            $lines[] = "- $k: " . self::clean($v, 300);
        }
        $lines[] = '';
        if ($update && !$entries) {
            $lines[] = 'No new thread entries since the last message.';
        } else {
            $lines[] = $update
                ? 'New thread entries since the last message, newest first (times are server time):'
                : 'Thread, newest first (times are server time):';
        }

        $used = 0;
        $skipped = 0;
        foreach ($entries as $e) {
            $label = array('M' => 'customer message', 'R' => 'agent reply', 'N' => 'internal note')[$e['type']] ?? 'entry';
            $chunk = sprintf("\n[%s] %s by %s:\n%s\n", self::clean($e['created'], 40), $label,
                self::clean($e['poster'], 120), self::clean($e['text'], self::ENTRY_MAX));
            $len = self::length($chunk);
            if ($used + $len > self::THREAD_MAX) {
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

    /** Valid UTF-8, no block tag, bounded length. */
    private static function clean(string $s, int $max): string
    {
        if (!preg_match('//u', $s)) {
            $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        }
        $s = preg_replace('#</?\s*ticket_data\s*>#i', '[tag removed]', $s) ?? '';
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
