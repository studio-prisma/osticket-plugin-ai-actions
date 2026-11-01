<?php

declare(strict_types=1);

/**
 * Work for AI agents, done in osTicket's cron instead of the request that
 * triggered it: a model call takes seconds, and the trigger may be the mail
 * fetch or an agent's click.
 *
 * A job is claimed with one UPDATE (state queued → running, with a token),
 * so two cron runs can't take the same job. A job stuck in "running" for
 * STALE_MINUTES (a crashed run) is failed, not retried: an automatic
 * action must not run twice.
 */
final class AiActionsJobs
{
    public const PER_RUN = 5;
    public const STALE_MINUTES = 15;
    public const KEEP_DAYS = 30;

    /** @var bool */
    private static $ready = false;

    /** @var callable|null fn(array $job): array{ok:bool, error?:string}; set by the rules (S2) */
    public static $worker;

    public static function table(): string
    {
        return TABLE_PREFIX . 'ai_actions_job';
    }

    public static function stateTable(): string
    {
        return TABLE_PREFIX . 'ai_actions_state';
    }

    public static function ensure(): void
    {
        if (self::$ready) {
            return;
        }
        db_query(sprintf("CREATE TABLE IF NOT EXISTS `%s` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `kind` VARCHAR(20) NOT NULL,
            `rule_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `ticket_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `payload` TEXT NOT NULL,
            `state` VARCHAR(10) NOT NULL DEFAULT 'queued',
            `token` CHAR(16) NOT NULL DEFAULT '',
            `error` VARCHAR(200) NOT NULL DEFAULT '',
            `created` DATETIME NOT NULL,
            `started` DATETIME NULL,
            `finished` DATETIME NULL,
            PRIMARY KEY (`id`),
            KEY `state` (`state`, `id`),
            KEY `ticket` (`ticket_id`, `rule_id`, `created`)
        ) DEFAULT CHARSET=utf8mb4", self::table()));
        db_query(sprintf("CREATE TABLE IF NOT EXISTS `%s` (
            `name` VARCHAR(40) NOT NULL,
            `value` VARCHAR(255) NOT NULL,
            PRIMARY KEY (`name`)
        ) DEFAULT CHARSET=utf8mb4", self::stateTable()));
        self::$ready = true;
    }

    public static function enqueue(string $kind, int $ruleId, int $ticketId, array $payload = array()): int
    {
        self::ensure();
        if (!preg_match('/^[a-z_]{1,20}$/', $kind)) {
            return 0;
        }
        db_query(sprintf("INSERT INTO `%s` (kind, rule_id, ticket_id, payload, created) VALUES ('%s', %d, %d, %s, NOW())",
            self::table(), $kind, $ruleId, $ticketId, db_input((string) json_encode($payload))));
        return (int) db_insert_id();
    }

    /** Signal handler for osTicket's cron. */
    public static function cron(): void
    {
        self::ensure();
        self::setState('cron_last', (string) time());
        if (AiActionsController::settings()['auto_paused'] ?? false) {
            return;
        }
        db_query(sprintf("UPDATE `%s` SET state='failed', error='stale', finished=NOW()
            WHERE state='running' AND started < NOW() - INTERVAL %d MINUTE", self::table(), self::STALE_MINUTES));
        db_query(sprintf("DELETE FROM `%s` WHERE created < NOW() - INTERVAL %d DAY", self::table(), self::KEEP_DAYS));
        for ($i = 0; $i < self::PER_RUN; $i++) {
            $job = self::claim();
            if (!$job) {
                break;
            }
            $r = array('ok' => false, 'error' => 'no_worker');
            if (self::$worker) {
                try {
                    $r = call_user_func(self::$worker, $job);
                } catch (Throwable $e) {
                    error_log('ai-actions: job ' . $job['id'] . ' failed: ' . get_class($e));
                    $r = array('ok' => false, 'error' => 'exception');
                }
            }
            self::finish($job['id'], !empty($r['ok']), (string) ($r['error'] ?? ''));
        }
    }

    /** @return array{id:int, kind:string, rule_id:int, ticket_id:int, payload:array}|null */
    public static function claim(): ?array
    {
        self::ensure();
        $token = bin2hex(random_bytes(8));
        db_query(sprintf("UPDATE `%s` SET state='running', token='%s', started=NOW() WHERE state='queued' ORDER BY id LIMIT 1",
            self::table(), $token));
        if (db_affected_rows() !== 1) {
            return null;
        }
        $res = db_query(sprintf("SELECT id, kind, rule_id, ticket_id, payload FROM `%s` WHERE token='%s' AND state='running'",
            self::table(), $token));
        $row = $res ? db_fetch_array($res) : null;
        if (!$row) {
            return null;
        }
        $payload = json_decode((string) $row['payload'], true);
        return array('id' => (int) $row['id'], 'kind' => (string) $row['kind'], 'rule_id' => (int) $row['rule_id'],
                     'ticket_id' => (int) $row['ticket_id'], 'payload' => is_array($payload) ? $payload : array());
    }

    public static function finish(int $id, bool $ok, string $error = ''): void
    {
        db_query(sprintf("UPDATE `%s` SET state='%s', error=%s, finished=NOW() WHERE id=%d",
            self::table(), $ok ? 'done' : 'failed', db_input(substr($error, 0, 200)), $id));
    }

    /** Seconds since osTicket's cron last ran, null if never seen. */
    public static function cronAge(): ?int
    {
        $v = self::state('cron_last');
        return $v !== null && ctype_digit($v) ? max(0, time() - (int) $v) : null;
    }

    /** @return array<string, int> jobs per state */
    public static function counts(): array
    {
        self::ensure();
        $out = array('queued' => 0, 'running' => 0, 'done' => 0, 'failed' => 0);
        $res = db_query(sprintf('SELECT state, COUNT(*) FROM `%s` GROUP BY state', self::table()));
        while ($res && ($r = db_fetch_row($res))) {
            $out[(string) $r[0]] = (int) $r[1];
        }
        return $out;
    }

    public static function state(string $name): ?string
    {
        self::ensure();
        $res = db_query(sprintf("SELECT value FROM `%s` WHERE name=%s", self::stateTable(), db_input($name)));
        $row = $res ? db_fetch_row($res) : null;
        return $row ? (string) $row[0] : null;
    }

    public static function setState(string $name, string $value): void
    {
        self::ensure();
        db_query(sprintf("REPLACE INTO `%s` (name, value) VALUES (%s, %s)", self::stateTable(), db_input($name), db_input($value)));
    }
}
