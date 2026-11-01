<?php

declare(strict_types=1);

/**
 * One conversation per agent and ticket, server-side only.
 *
 * Two lists are kept: `messages` is what goes to the API (thinking blocks
 * included, append-only), `transcript` is what the chat window shows. The
 * browser never sends either back; it only sends the next message. So a
 * client can't slip in an invented assistant turn or tool result.
 *
 * A conversation ends with "New" or IDLE_HOURS after its last message. Its
 * transcript then moves to the archive, where the agent can read it again
 * for $keepDays days (0: not kept). The archive holds what the window showed,
 * not the API messages, and no plan IDs: nothing in it can run or be undone.
 */
final class AiActionsChatStore
{
    public const IDLE_HOURS = 12;

    /** User messages per conversation. */
    public const MAX_TURNS = 20;

    /** Earlier conversations listed per agent and ticket. */
    public const ARCHIVE_LIST_MAX = 50;

    /** @var int days an ended conversation stays readable; set from the plugin settings */
    public static $keepDays = 90;

    /** @var bool */
    private static $ready = false;

    public static function table(): string
    {
        return TABLE_PREFIX . 'ai_actions_chat';
    }

    public static function archiveTable(): string
    {
        return TABLE_PREFIX . 'ai_actions_chat_archive';
    }

    public static function ensure(): void
    {
        if (self::$ready) {
            return;
        }
        db_query(sprintf("CREATE TABLE IF NOT EXISTS `%s` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `staff_id` INT UNSIGNED NOT NULL,
            `ticket_id` INT UNSIGNED NOT NULL,
            `transcript` MEDIUMTEXT NOT NULL,
            `ended` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `owner` (`staff_id`, `ticket_id`, `ended`),
            KEY `ended` (`ended`)
        ) DEFAULT CHARSET=utf8mb4", self::archiveTable()));
        db_query(sprintf("CREATE TABLE IF NOT EXISTS `%s` (
            `staff_id` INT UNSIGNED NOT NULL,
            `ticket_id` INT UNSIGNED NOT NULL,
            `messages` MEDIUMTEXT NOT NULL,
            `transcript` MEDIUMTEXT NOT NULL,
            `seen_entry` INT UNSIGNED NOT NULL DEFAULT 0,
            `pending` TEXT NOT NULL,
            `turns` INT UNSIGNED NOT NULL DEFAULT 0,
            `updated` DATETIME NOT NULL,
            PRIMARY KEY (`staff_id`, `ticket_id`),
            KEY `updated` (`updated`)
        ) DEFAULT CHARSET=utf8mb4", self::table()));
        self::$ready = true;
    }

    /**
     * @return array{messages:array, transcript:array, seen_entry:int, pending:array, turns:int}
     */
    public static function load(int $staffId, int $ticketId): array
    {
        self::ensure();
        self::expire();
        $res = db_query(sprintf("SELECT messages, transcript, seen_entry, pending, turns FROM `%s`
            WHERE staff_id=%d AND ticket_id=%d", self::table(), $staffId, $ticketId));
        $row = $res ? db_fetch_array($res) : null;
        if (!$row) {
            return self::fresh();
        }
        return array(
            'messages'   => self::decode($row['messages']),
            'transcript' => self::decode($row['transcript']),
            'seen_entry' => (int) $row['seen_entry'],
            'pending'    => self::decode($row['pending']),
            'turns'      => (int) $row['turns'],
        );
    }

    public static function save(int $staffId, int $ticketId, array $chat): void
    {
        self::ensure();
        db_query(sprintf("REPLACE INTO `%s` (staff_id, ticket_id, messages, transcript, seen_entry, pending, turns, updated)
            VALUES (%d, %d, %s, %s, %d, %s, %d, NOW())", self::table(), $staffId, $ticketId,
            self::quote($chat['messages']), self::quote($chat['transcript']), (int) $chat['seen_entry'],
            self::quote($chat['pending']), (int) $chat['turns']));
    }

    /** Ends the conversation: archived (if kept and not empty), then removed. */
    public static function reset(int $staffId, int $ticketId): void
    {
        self::ensure();
        $chat = self::load($staffId, $ticketId);
        self::archive($staffId, $ticketId, $chat['transcript']);
        db_query(sprintf("DELETE FROM `%s` WHERE staff_id=%d AND ticket_id=%d", self::table(), $staffId, $ticketId));
    }

    /**
     * Moves idle conversations to the archive and drops archived ones past
     * $keepDays. Runs on every load; both queries use an index.
     */
    private static function expire(): void
    {
        $res = db_query(sprintf("SELECT staff_id, ticket_id, transcript FROM `%s`
            WHERE updated < NOW() - INTERVAL %d HOUR LIMIT 100", self::table(), self::IDLE_HOURS));
        while ($res && ($row = db_fetch_array($res))) {
            self::archive((int) $row['staff_id'], (int) $row['ticket_id'], self::decode($row['transcript']));
            db_query(sprintf("DELETE FROM `%s` WHERE staff_id=%d AND ticket_id=%d AND updated < NOW() - INTERVAL %d HOUR",
                self::table(), (int) $row['staff_id'], (int) $row['ticket_id'], self::IDLE_HOURS));
        }
        db_query(sprintf("DELETE FROM `%s` WHERE ended < NOW() - INTERVAL %d DAY",
            self::archiveTable(), max(0, self::$keepDays)));
    }

    private static function archive(int $staffId, int $ticketId, array $transcript): void
    {
        if (self::$keepDays <= 0 || !$transcript) {
            return;
        }
        db_query(sprintf("INSERT INTO `%s` (staff_id, ticket_id, transcript, ended) VALUES (%d, %d, %s, NOW())",
            self::archiveTable(), $staffId, $ticketId, self::quote(self::closed($transcript))));
    }

    /**
     * A transcript for the archive: no plan IDs or undo data, and a plan
     * nobody confirmed reads as not run. Public for the unit tests.
     */
    public static function closed(array $transcript): array
    {
        foreach ($transcript as &$item) {
            if (isset($item['plan'])) {
                unset($item['plan']['id'], $item['plan']['undo_until'], $item['plan']['undo_left']);
                if (($item['plan']['state'] ?? '') === 'open') {
                    $item['plan']['state'] = 'expired';
                }
            }
        }
        unset($item);
        return $transcript;
    }

    /**
     * Earlier conversations of this agent about this ticket, newest first.
     *
     * @return array<int, array{id:int, ended:string, title:string, count:int}> ended in database time
     */
    public static function archived(int $staffId, int $ticketId): array
    {
        self::ensure();
        self::expire();
        $res = db_query(sprintf("SELECT id, transcript, ended FROM `%s` WHERE staff_id=%d AND ticket_id=%d
            ORDER BY ended DESC, id DESC LIMIT %d", self::archiveTable(), $staffId, $ticketId, self::ARCHIVE_LIST_MAX));
        $out = array();
        while ($res && ($row = db_fetch_array($res))) {
            $transcript = self::decode($row['transcript']);
            $out[] = array('id' => (int) $row['id'], 'ended' => (string) $row['ended']) + self::summary($transcript);
        }
        return $out;
    }

    /** One archived transcript, only for its own agent and ticket. */
    public static function archivedOne(int $staffId, int $ticketId, int $id): ?array
    {
        self::ensure();
        $res = db_query(sprintf("SELECT transcript, ended FROM `%s` WHERE id=%d AND staff_id=%d AND ticket_id=%d",
            self::archiveTable(), $id, $staffId, $ticketId));
        $row = $res ? db_fetch_array($res) : null;
        return $row ? array('transcript' => self::decode($row['transcript']), 'ended' => (string) $row['ended']) : null;
    }

    /**
     * First request (shortened) and number of messages. Public for the unit tests.
     *
     * @return array{title:string, count:int}
     */
    public static function summary(array $transcript): array
    {
        $title = '';
        foreach ($transcript as $item) {
            if (($item['role'] ?? '') === 'user' && is_string($item['text'] ?? null)) {
                $title = trim((string) preg_replace('/\s+/u', ' ', $item['text']));
                break;
            }
        }
        if (preg_match_all('/./su', $title) > 80) {
            $title = implode('', array_slice(preg_split('//u', $title, -1, PREG_SPLIT_NO_EMPTY) ?: array(), 0, 80)) . ' …';
        }
        return array('title' => $title, 'count' => count($transcript));
    }

    /**
     * Records what happened to a plan, for the chat window and for the
     * model's next turn. Does nothing if the conversation is gone.
     *
     * @param string $state done, discarded, expired or undone
     * @param array|null $results what the chat shows per change; null keeps the earlier ones (undo)
     * @param array $extra more plan fields, e.g. undo_until or undo
     */
    public static function planOutcome(int $staffId, int $ticketId, string $planId, string $state,
                                       ?array $results = array(), array $extra = array()): void
    {
        $chat = self::load($staffId, $ticketId);
        if (!$chat['transcript']) {
            return;
        }
        $chat['transcript'] = self::markPlan($chat['transcript'], $planId, $state, $results, $extra);
        $chat['pending'][] = array('state' => $state, 'results' => $state === 'undone' ? ($extra['undo'] ?? array()) : (array) $results);
        self::save($staffId, $ticketId, $chat);
    }

    /** Public for the unit tests. */
    public static function markPlan(array $transcript, string $planId, string $state, ?array $results, array $extra = array()): array
    {
        foreach ($transcript as &$item) {
            if (($item['plan']['id'] ?? null) === $planId) {
                $item['plan']['state'] = $state;
                if ($results !== null) {
                    $item['plan']['results'] = $results;
                }
                unset($item['plan']['undo_until']);
                $item['plan'] = $extra + $item['plan'];
            }
        }
        unset($item);
        return $transcript;
    }

    /**
     * The transcript as the browser gets it: a plan's ID only while it can
     * still run, or be undone.
     */
    public static function forClient(array $transcript, ?int $now = null): array
    {
        $now = $now ?? time();
        $out = array();
        foreach ($transcript as $item) {
            if (isset($item['plan'])) {
                $state = $item['plan']['state'] ?? '';
                $undoable = $state === 'done' && (int) ($item['plan']['undo_until'] ?? 0) > $now;
                if ($state !== 'open' && !$undoable) {
                    unset($item['plan']['id']);
                }
                // Seconds left instead of a timestamp: the browser's clock may be off.
                // 0 once the window has passed: the window shows the button greyed out.
                if ($undoable) {
                    $item['plan']['undo_left'] = (int) $item['plan']['undo_until'] - $now;
                } elseif ($state === 'done' && isset($item['plan']['undo_until'])) {
                    $item['plan']['undo_left'] = 0;
                }
                unset($item['plan']['undo_until']);
            }
            $out[] = $item;
        }
        return $out;
    }

    private static function fresh(): array
    {
        return array('messages' => array(), 'transcript' => array(), 'seen_entry' => 0, 'pending' => array(), 'turns' => 0);
    }

    private static function decode($raw): array
    {
        $v = json_decode((string) $raw, true);
        return is_array($v) ? $v : array();
    }

    /** JSON, always quoted (db_input leaves numeric-looking strings bare). */
    private static function quote(array $v): string
    {
        // ASCII-only JSON (\uXXXX): osTicket's connection charset may be 3-byte utf8, which turns
        // emoji into "????". That would alter stored history the API checks byte for byte.
        return "'" . db_real_escape((string) json_encode($v)) . "'";
    }
}
