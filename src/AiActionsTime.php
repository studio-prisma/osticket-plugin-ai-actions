<?php

declare(strict_types=1);

/**
 * Time zones between the agent, the model and the database.
 *
 * The model reads and writes wall-clock times in the agent's time zone.
 * osTicket stores the due date in its database time zone and parses a date
 * without an offset as UTC, so a bare "17:00" would show up as 18:00 in
 * Berlin in winter. Every value that goes into osTicket therefore carries
 * the offset that applies on that date (summer or winter time).
 */
final class AiActionsTime
{
    /** The agent's time zone, else the help desk's default, else UTC. */
    public static function zone($staff): string
    {
        global $cfg;

        $candidates = array();
        if ($staff instanceof Staff) {
            $candidates[] = (string) $staff->getTimezone();
        }
        if ($cfg && method_exists($cfg, 'getDefaultTimezone')) {
            $candidates[] = (string) $cfg->getDefaultTimezone();
        }
        foreach ($candidates as $tz) {
            if (self::valid($tz)) {
                return $tz;
            }
        }
        return 'UTC';
    }

    /** The database time zone osTicket stores dates in. */
    public static function dbZone(): string
    {
        global $cfg;

        $tz = $cfg && method_exists($cfg, 'getDbTimezone') ? (string) $cfg->getDbTimezone() : '';
        return self::valid($tz) ? $tz : 'UTC';
    }

    public static function now(string $tz): DateTime
    {
        return new DateTime('now', new DateTimeZone(self::valid($tz) ? $tz : 'UTC'));
    }

    /**
     * "Y-m-d H:i" in $tz as an ISO 8601 string with the offset of that date,
     * e.g. 2026-11-07T17:00:00+01:00. Null if the input isn't such a time,
     * or names a wall-clock time that doesn't exist (skipped at the change
     * to summer time).
     */
    public static function withOffset(string $local, string $tz): ?string
    {
        $zone = new DateTimeZone(self::valid($tz) ? $tz : 'UTC');
        $d = DateTime::createFromFormat('!Y-m-d H:i', $local, $zone);
        if (!$d || $d->format('Y-m-d H:i') !== $local) {
            return null;
        }
        return $d->format(DateTime::ATOM);
    }

    /**
     * A database time ("Y-m-d H:i[:s]") as wall-clock time in $tz, "Y-m-d H:i".
     * Empty for empty or unreadable input.
     */
    public static function fromDb(?string $value, string $dbTz, string $tz): string
    {
        $value = trim((string) $value);
        if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $value)) {
            return '';
        }
        try {
            $d = new DateTime($value, new DateTimeZone(self::valid($dbTz) ? $dbTz : 'UTC'));
        } catch (Exception $e) {
            return '';
        }
        $d->setTimezone(new DateTimeZone(self::valid($tz) ? $tz : 'UTC'));
        return $d->format('Y-m-d H:i');
    }

    private static function valid(string $tz): bool
    {
        return $tz !== '' && in_array($tz, DateTimeZone::listIdentifiers(), true);
    }
}
