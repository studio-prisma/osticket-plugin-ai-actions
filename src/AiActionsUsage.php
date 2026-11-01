<?php

declare(strict_types=1);

/**
 * Cost limits per agent: requests and tokens per day, one request at a time.
 *
 * A request is counted before the model is called, so a burst of parallel
 * clicks can't slip past the limit; tokens are added once the answer is in.
 * The per-agent lock is a MySQL named lock: it ends with the request, even
 * when PHP dies halfway.
 */
final class AiActionsUsage
{
    /** @var bool */
    private static $ready = false;

    public static function table(): string
    {
        return TABLE_PREFIX . 'ai_actions_usage';
    }

    public static function ensure(): void
    {
        if (self::$ready) {
            return;
        }
        db_query(sprintf("CREATE TABLE IF NOT EXISTS `%s` (
            `staff_id` INT UNSIGNED NOT NULL,
            `day` DATE NOT NULL,
            `requests` INT UNSIGNED NOT NULL DEFAULT 0,
            `input_tokens` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `output_tokens` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`staff_id`, `day`)
        ) DEFAULT CHARSET=utf8mb4", self::table()));
        self::$ready = true;
    }

    /** Takes the agent's lock; false if another request of theirs is running. */
    public static function lock(int $staffId): bool
    {
        $res = db_query(sprintf("SELECT GET_LOCK('%s', 0)", self::lockName($staffId)));
        $row = $res ? db_fetch_row($res) : null;
        return $row && (int) $row[0] === 1;
    }

    public static function unlock(int $staffId): void
    {
        db_query(sprintf("SELECT RELEASE_LOCK('%s')", self::lockName($staffId)));
    }

    /** Public for the unit tests. */
    public static function lockName(int $staffId): string
    {
        return 'spa_ai_actions_' . $staffId;
    }

    /** @return array{requests:int, tokens:int} today's use so far */
    public static function today(int $staffId): array
    {
        self::ensure();
        $res = db_query(sprintf("SELECT requests, input_tokens + output_tokens FROM `%s` WHERE staff_id=%d AND day=CURDATE()",
            self::table(), $staffId));
        $row = $res ? db_fetch_row($res) : null;
        return array('requests' => (int) ($row[0] ?? 0), 'tokens' => (int) ($row[1] ?? 0));
    }

    /**
     * null if the agent may make another request, else the error code.
     * Public for the unit tests.
     */
    public static function blocked(array $used, int $maxRequests, int $maxTokens): ?string
    {
        if ($maxRequests > 0 && $used['requests'] >= $maxRequests) {
            return 'limit_requests';
        }
        if ($maxTokens > 0 && $used['tokens'] >= $maxTokens) {
            return 'limit_tokens';
        }
        return null;
    }

    public static function countRequest(int $staffId): void
    {
        self::ensure();
        db_query(sprintf("INSERT INTO `%s` (staff_id, day, requests) VALUES (%d, CURDATE(), 1)
            ON DUPLICATE KEY UPDATE requests = requests + 1", self::table(), $staffId));
        // Rows older than a quarter carry no limit any more.
        db_query(sprintf("DELETE FROM `%s` WHERE day < CURDATE() - INTERVAL 90 DAY", self::table()));
    }

    public static function addTokens(int $staffId, int $input, int $output): void
    {
        db_query(sprintf("UPDATE `%s` SET input_tokens = input_tokens + %d, output_tokens = output_tokens + %d
            WHERE staff_id=%d AND day=CURDATE()", self::table(), max(0, $input), max(0, $output), $staffId));
    }
}
