<?php

declare(strict_types=1);

/**
 * AI agents: osTicket agent accounts the assistant works as, assignable
 * like anyone else, but nobody can sign in with them.
 *
 * Three locks against a login:
 *   1. backend "ai-agent", which no authentication backend answers to, so
 *      StaffAuthenticationBackend::isBackendAllowed() refuses every login
 *      and every password reset;
 *   2. a bcrypt hash of 256 random bits, written straight to the record and
 *      never stored anywhere else. Not through setPassword(): osTicket runs
 *      that through the password policies, and a policy plugin that rejects
 *      the value throws and aborts the request;
 *   3. auth.login.succeeded / auth.pwreset.login end the session at once,
 *      should a future osTicket version let one through.
 *
 * The email address is name@example.com (RFC 2606, never delivered), so
 * alerts to the AI agent go nowhere instead of into a real mailbox that
 * osTicket might fetch back as a ticket.
 */
final class AiActionsAgents
{
    public const BACKEND = 'ai-agent';

    /** @var bool */
    private static $ready = false;

    /** @var array<int, bool>|null */
    private static $ids;

    public static function table(): string
    {
        return TABLE_PREFIX . 'ai_actions_agent';
    }

    public static function ensure(): void
    {
        if (self::$ready) {
            return;
        }
        db_query(sprintf("CREATE TABLE IF NOT EXISTS `%s` (
            `staff_id` INT UNSIGNED NOT NULL,
            `persona` TEXT NOT NULL,
            `model` VARCHAR(64) NOT NULL DEFAULT '',
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `created` DATETIME NOT NULL,
            PRIMARY KEY (`staff_id`)
        ) DEFAULT CHARSET=utf8mb4", self::table()));
        self::$ready = true;
    }

    public static function isAgent(int $staffId): bool
    {
        if (self::$ids === null) {
            self::ensure();
            self::$ids = array();
            $res = db_query(sprintf('SELECT staff_id FROM `%s`', self::table()));
            while ($res && ($row = db_fetch_row($res))) {
                self::$ids[(int) $row[0]] = true;
            }
        }
        return isset(self::$ids[$staffId]);
    }

    /** @return array<int, array{staff_id:int, name:string, dept:string, persona:string, model:string, active:bool}> */
    public static function all(): array
    {
        self::ensure();
        $out = array();
        $res = db_query(sprintf('SELECT a.staff_id, a.persona, a.model, a.active, s.firstname, s.lastname, d.name AS dept
            FROM `%s` a JOIN `%s` s ON s.staff_id=a.staff_id LEFT JOIN `%s` d ON d.id=s.dept_id ORDER BY s.firstname',
            self::table(), STAFF_TABLE, DEPT_TABLE));
        while ($res && ($r = db_fetch_array($res))) {
            $out[] = array(
                'staff_id' => (int) $r['staff_id'],
                'name'     => trim($r['firstname'] . ' ' . $r['lastname']),
                'dept'     => (string) $r['dept'],
                'persona'  => (string) $r['persona'],
                'model'    => (string) $r['model'],
                'active'   => (bool) $r['active'],
            );
        }
        return $out;
    }

    /** Username and address for a name. Public for the unit tests. */
    public static function handle(string $name, string $suffix): array
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name));
        $slug = trim(substr($slug, 0, 20), '-') ?: 'agent';
        return array('username' => 'ai-' . $slug . '-' . $suffix, 'email' => 'ai-' . $slug . '-' . $suffix . '@example.com');
    }

    /**
     * Creates the account through osTicket's own Staff::update(), so every
     * check of the agent form applies. Returns the staff ID or an error.
     *
     * @return array{id:int, errors:array}
     */
    public static function create(string $name, int $deptId, int $roleId, string $persona, string $model): array
    {
        self::ensure();
        $name = AiActionsController::cleanName($name, '');
        $persona = AiActionsController::welcome($persona);
        if ($name === '') {
            return array('id' => 0, 'errors' => array('name' => 'Name is required.'));
        }
        if (!in_array($model, AiActionsController::MODELS, true)) {
            $model = '';
        }
        $parts = preg_split('/\s+/', $name, 2);
        $handle = self::handle($name, bin2hex(random_bytes(3)));
        $vars = array(
            'id'        => null,
            'firstname' => $parts[0],
            'lastname'  => $parts[1] ?? 'AI',
            'username'  => $handle['username'],
            'email'     => $handle['email'],
            'dept_id'   => $deptId,
            'role_id'   => $roleId,
            'backend'   => self::BACKEND,
            'isvisible' => 1,
            'notes'     => 'AI agent of the AI Actions plugin. Nobody can sign in with this account.',
            'perms'     => array(),
        );
        $errors = array();
        $staff = Staff::create();
        try {
            $ok = $staff->update($vars, $errors);
        } catch (Throwable $e) {
            error_log('ai-actions: AI agent not created: ' . get_class($e));
            return array('id' => 0, 'errors' => array('err' => 'osTicket refused the account (' . get_class($e) . ').'));
        }
        if (!$ok) {
            return array('id' => 0, 'errors' => $errors ?: array('err' => 'osTicket refused the account.'));
        }
        // An unknown password instead of none, so no login path ever depends on how an empty hash compares.
        $staff->passwd = Passwd::hash(bin2hex(random_bytes(32)));
        $staff->save();
        db_query(sprintf("INSERT INTO `%s` (staff_id, persona, model, active, created) VALUES (%d, %s, %s, 1, NOW())",
            self::table(), (int) $staff->getId(), db_input($persona), db_input($model)));
        self::$ids = null;
        return array('id' => (int) $staff->getId(), 'errors' => array());
    }

    public static function setActive(int $staffId, bool $active): void
    {
        self::ensure();
        db_query(sprintf('UPDATE `%s` SET active=%d WHERE staff_id=%d', self::table(), $active ? 1 : 0, $staffId));
    }

    public static function get(int $staffId): ?array
    {
        foreach (self::all() as $a) {
            if ($a['staff_id'] === $staffId) {
                return $a;
            }
        }
        return null;
    }

    /** Signal handler: a session of an AI agent ends right away. */
    public static function refuseLogin($staff): void
    {
        if (!($staff instanceof Staff) || !self::isAgent((int) $staff->getId())) {
            return;
        }
        error_log('ai-actions: login as AI agent ' . (int) $staff->getId() . ' refused');
        $_SESSION['_auth']['staff'] = array();
        unset($_SESSION['_staff']);
        if (PHP_SAPI !== 'cli') {
            Http::redirect(ROOT_PATH . 'scp/login.php');
            exit;
        }
    }
}
