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
                'text'    => $body ? self::bodyText((string) $body, (string) $body->getType()) : '',
            );
        }

        return array('text' => self::render($facts, $entries, $since > 0, $max, self::related($ticket, $staff, $dbTz, $tz),
                     AiActionsReaders::listing($ticket)), 'last' => $last);
    }

    /** Longest link target kept. Longer ones are tracking noise, the text stays. */
    public const URL_MAX = 500;

    /**
     * A thread entry as plain text, with link targets kept: "text (URL)".
     *
     * osTicket's getSearchable() drops every href, and alert mails often name
     * the affected site or the fix only in a link. Safe Links wrappers
     * (Microsoft 365) are unwrapped first; at ~500 characters each they would
     * otherwise hide the real target. Public for the unit tests.
     */
    public static function bodyText(string $raw, string $type): string
    {
        if ($type !== 'html') {
            return self::unwrapSafeLinks(trim($raw));
        }
        $s = (string) preg_replace('#<(style|script|head|title)\b[^>]*>.*?</\1\s*>|<!--.*?-->#is', '', $raw);
        $s = (string) preg_replace_callback('#<a\b[^>]*?\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\')[^>]*>(.*?)</a\s*>#is',
            static function ($m) {
                return self::link(html_entity_decode($m[1] !== '' ? $m[1] : ($m[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $m[3]);
            }, $s);
        $s = (string) preg_replace('#<br\s*/?>#i', "\n", $s);
        $s = (string) preg_replace('#<li\b[^>]*>#i', '- ', $s);
        $s = (string) preg_replace('#</(p|div|h[1-6]|li|tr|table|ul|ol|blockquote|pre)\s*>#i', "\n", $s);
        $s = (string) preg_replace('#<[^>]+>#', ' ', $s);
        $s = self::unwrapSafeLinks(html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $s = (string) preg_replace("/[ \t\x{00a0}]+/u", ' ', $s);
        $s = implode("\n", array_map('trim', explode("\n", $s)));
        return trim((string) preg_replace("/\n{3,}/", "\n\n", $s));
    }

    /** One anchor: the target behind its text, or the text alone if the target is unusable. */
    private static function link(string $href, string $label): string
    {
        $url = self::unwrapSafeLink(trim($href));
        $text = trim(html_entity_decode((string) preg_replace('#<[^>]+>#', ' ', $label), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (!preg_match('#^(https?://|mailto:)#i', $url) || strlen($url) > self::URL_MAX || preg_match('/[<>\s]/', $url)) {
            return $label;
        }
        // Encoded so the entity decoding of the whole body leaves it as it is.
        $shown = htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return $text === '' || $text === $url || $text === $href ? " $shown " : "$label ($shown)";
    }

    /** The real target of a Safe Links URL, or the URL unchanged. */
    private static function unwrapSafeLink(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (substr($host, -strlen('.safelinks.protection.outlook.com')) !== '.safelinks.protection.outlook.com') {
            return $url;
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $target = is_string($q['url'] ?? null) ? $q['url'] : '';
        return preg_match('#^(https?://|mailto:)#i', $target) ? $target : $url;
    }

    /** Safe Links in plain text (text mails, or a link whose text is its URL). */
    private static function unwrapSafeLinks(string $s): string
    {
        return (string) preg_replace_callback('#https://[a-z0-9-]+\.safelinks\.protection\.outlook\.com/\?[^\s<>"\')]+#i',
            static function ($m) {
                return self::unwrapSafeLink($m[0]);
            }, $s);
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
