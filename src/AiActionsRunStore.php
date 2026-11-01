<?php

declare(strict_types=1);

/**
 * Planned and executed runs, in the plugin's own table.
 *
 * A plan is bound to the agent and the ticket it was made for, valid for
 * TTL seconds and can be run once. The browser only ever sends the plan ID;
 * what runs is what is stored here.
 */
final class AiActionsRunStore
{
    public const TTL = 600;

    /** Seconds after a run in which it can be undone (spec AC-7). */
    public const UNDO_WINDOW = 30;

    /** Rows older than this are deleted; the ticket keeps its own note. */
    public const KEEP_DAYS = 2;

    /** @var bool */
    private static $ready = false;

    public static function table(): string
    {
        return TABLE_PREFIX . 'ai_actions_run';
    }

    public static function isId($raw): bool
    {
        return is_string($raw) && preg_match('/^[a-f0-9]{32}$/', $raw) === 1;
    }

    public static function ensure(): void
    {
        if (self::$ready) {
            return;
        }
        db_query(sprintf("CREATE TABLE IF NOT EXISTS `%s` (
            `id` CHAR(32) NOT NULL,
            `staff_id` INT UNSIGNED NOT NULL,
            `ticket_id` INT UNSIGNED NOT NULL,
            `state` VARCHAR(16) NOT NULL,
            `prompt` TEXT NOT NULL,
            `plan` MEDIUMTEXT NOT NULL,
            `result` MEDIUMTEXT NULL,
            `created` DATETIME NOT NULL,
            `expires` DATETIME NOT NULL,
            `ran` DATETIME NULL,
            PRIMARY KEY (`id`),
            KEY `staff_created` (`staff_id`, `created`)
        ) DEFAULT CHARSET=utf8mb4", self::table()));
        // Tables from before undo existed lack `ran`.
        $res = db_query(sprintf("SHOW COLUMNS FROM `%s` LIKE 'ran'", self::table()));
        if ($res && !db_num_rows($res)) {
            db_query(sprintf("ALTER TABLE `%s` ADD COLUMN `ran` DATETIME NULL", self::table()));
        }
        self::$ready = true;
    }

    public static function create(int $staffId, int $ticketId, string $prompt, array $changes): string
    {
        self::ensure();
        db_query(sprintf("DELETE FROM `%s` WHERE created < NOW() - INTERVAL %d DAY", self::table(), self::KEEP_DAYS));
        $id = bin2hex(random_bytes(16));
        db_query(sprintf(
            "INSERT INTO `%s` (id, staff_id, ticket_id, state, prompt, plan, created, expires)
             VALUES ('%s', %d, %d, 'planned', %s, %s, NOW(), NOW() + INTERVAL %d SECOND)",
            self::table(), $id, $staffId, $ticketId, self::ascii($prompt),
            self::ascii($changes), self::TTL));
        return $id;
    }

    /**
     * Marks the plan as running and returns it, or null if it doesn't exist,
     * belongs to another agent, has expired or already ran. The UPDATE is
     * the lock: of two parallel requests, only one changes the row.
     *
     * @return array{id:string, ticket_id:int, prompt:string, changes:array}|null
     */
    public static function claim(string $id, int $staffId): ?array
    {
        if (!self::isId($id)) {
            return null;
        }
        self::ensure();
        db_query(sprintf(
            "UPDATE `%s` SET state='running' WHERE id='%s' AND staff_id=%d AND state='planned' AND expires > NOW()",
            self::table(), $id, $staffId));
        if (db_affected_rows() !== 1) {
            return null;
        }
        $res = db_query(sprintf("SELECT ticket_id, prompt, plan FROM `%s` WHERE id='%s'", self::table(), $id));
        $row = $res ? db_fetch_array($res) : null;
        if (!$row) {
            return null;
        }
        $changes = json_decode((string) $row['plan'], true);
        return array(
            'id'        => $id,
            'ticket_id' => (int) $row['ticket_id'],
            'prompt'    => (string) json_decode((string) $row['prompt'], true),
            'changes'   => is_array($changes) ? $changes : array(),
        );
    }

    /**
     * Withdraws a plan that hasn't run (discarded, or replaced by a newer
     * message). Returns the ticket ID if this agent's plan was withdrawn.
     */
    public static function cancel(string $id, int $staffId, string $state = 'discarded'): ?int
    {
        if (!self::isId($id) || !preg_match('/^[a-z]{1,16}$/', $state)) {
            return null;
        }
        self::ensure();
        db_query(sprintf("UPDATE `%s` SET state='%s' WHERE id='%s' AND staff_id=%d AND state='planned'",
            self::table(), $state, $id, $staffId));
        return db_affected_rows() === 1 ? self::ticketOf($id, $staffId) : null;
    }

    /** Ticket of this agent's plan, whatever its state; null for unknown or foreign plans. */
    public static function ticketOf(string $id, int $staffId): ?int
    {
        if (!self::isId($id)) {
            return null;
        }
        self::ensure();
        $res = db_query(sprintf("SELECT ticket_id FROM `%s` WHERE id='%s' AND staff_id=%d", self::table(), $id, $staffId));
        $row = $res ? db_fetch_row($res) : null;
        return $row ? (int) $row[0] : null;
    }

    /** @param array $results per change, including `previous` for undo (server-side only) */
    public static function finish(string $id, array $results): void
    {
        db_query(sprintf("UPDATE `%s` SET state='done', result=%s, ran=NOW() WHERE id='%s'",
            self::table(), self::ascii($results), $id));
    }

    /**
     * Locks a run for undo and returns it, or null if it isn't this agent's,
     * didn't run, was undone already or is older than UNDO_WINDOW.
     *
     * @return array{id:string, ticket_id:int, prompt:string, results:array}|null
     */
    public static function claimUndo(string $id, int $staffId): ?array
    {
        if (!self::isId($id)) {
            return null;
        }
        self::ensure();
        db_query(sprintf(
            "UPDATE `%s` SET state='undoing' WHERE id='%s' AND staff_id=%d AND state='done'
             AND ran > NOW() - INTERVAL %d SECOND",
            self::table(), $id, $staffId, self::UNDO_WINDOW));
        if (db_affected_rows() !== 1) {
            return null;
        }
        $res = db_query(sprintf("SELECT ticket_id, prompt, result FROM `%s` WHERE id='%s'", self::table(), $id));
        $row = $res ? db_fetch_array($res) : null;
        if (!$row) {
            return null;
        }
        $results = json_decode((string) $row['result'], true);
        return array(
            'id'        => $id,
            'ticket_id' => (int) $row['ticket_id'],
            'prompt'    => (string) json_decode((string) $row['prompt'], true),
            'results'   => is_array($results) ? $results : array(),
        );
    }

    public static function finishUndo(string $id, array $runResults, array $undoResults): void
    {
        db_query(sprintf("UPDATE `%s` SET state='undone', result=%s WHERE id='%s'",
            self::table(), self::ascii(array('run' => $runResults, 'undo' => $undoResults)), $id));
    }

    /**
     * Quoted ASCII-only JSON (\uXXXX). osTicket's connection charset may be
     * 3-byte utf8, which turns emoji into "????".
     *
     * @param mixed $v
     */
    private static function ascii($v): string
    {
        return "'" . db_real_escape((string) json_encode($v)) . "'";
    }
}
