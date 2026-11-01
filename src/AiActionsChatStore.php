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
 * A conversation is dropped IDLE_HOURS after its last message.
 */
final class AiActionsChatStore
{
    public const IDLE_HOURS = 12;

    /** User messages per conversation. */
    public const MAX_TURNS = 20;

    /** @var bool */
    private static $ready = false;

    public static function table(): string
    {
        return TABLE_PREFIX . 'ai_actions_chat';
    }

    public static function ensure(): void
    {
        if (self::$ready) {
            return;
        }
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
        db_query(sprintf("DELETE FROM `%s` WHERE updated < NOW() - INTERVAL %d HOUR", self::table(), self::IDLE_HOURS));
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

    public static function reset(int $staffId, int $ticketId): void
    {
        self::ensure();
        db_query(sprintf("DELETE FROM `%s` WHERE staff_id=%d AND ticket_id=%d", self::table(), $staffId, $ticketId));
    }

    /**
     * Records what happened to a plan, for the chat window and for the
     * model's next turn. Does nothing if the conversation is gone.
     *
     * @param string $state done, discarded or expired
     */
    public static function planOutcome(int $staffId, int $ticketId, string $planId, string $state, array $results = array()): void
    {
        $chat = self::load($staffId, $ticketId);
        if (!$chat['transcript']) {
            return;
        }
        $chat['transcript'] = self::markPlan($chat['transcript'], $planId, $state, $results);
        $chat['pending'][] = array('state' => $state, 'results' => $results);
        self::save($staffId, $ticketId, $chat);
    }

    /** Public for the unit tests. */
    public static function markPlan(array $transcript, string $planId, string $state, array $results): array
    {
        foreach ($transcript as &$item) {
            if (($item['plan']['id'] ?? null) === $planId) {
                $item['plan']['state'] = $state;
                $item['plan']['results'] = $results;
            }
        }
        unset($item);
        return $transcript;
    }

    /** The transcript as the browser gets it: plan IDs only while a plan can still run. */
    public static function forClient(array $transcript): array
    {
        $out = array();
        foreach ($transcript as $item) {
            if (isset($item['plan']) && ($item['plan']['state'] ?? '') !== 'open') {
                unset($item['plan']['id']);
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
