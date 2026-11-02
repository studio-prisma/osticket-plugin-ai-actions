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
 * osTicket's agent form doesn't list the "ai-agent" backend, so saving the
 * account there would set it to "any"; it also lets an admin set a password.
 * guard() puts both back after every save of an AI agent's record.
 *
 * osTicket alerts AI agents like any other agent (new ticket, assignment,
 * …). A new AI agent gets name@example.com (RFC 2606). Without SMTP that is
 * enough; with SMTP the server refuses the address, and osTicket 1.18 then
 * fails every further mail of the same request ("Cannot issue HELO to
 * existing session"). setEmail() gives it an address the mail server takes,
 * e.g. a team mailbox osTicket doesn't fetch. Never a system email: osTicket
 * would fetch its own alerts and file mail from that address under the AI
 * agent.
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
        // 0.5.3: a picture per AI agent. Checked, not ADD COLUMN IF NOT EXISTS: MySQL doesn't know that.
        $res = db_query(sprintf("SHOW COLUMNS FROM `%s` LIKE 'image'", self::table()));
        if ($res && !db_num_rows($res)) {
            db_query(sprintf("ALTER TABLE `%s` ADD `image` VARCHAR(500) NOT NULL DEFAULT ''", self::table()));
        }
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

    /** @return array<int, array{staff_id:int, name:string, email:string, dept:string, persona:string, model:string, active:bool, image:string}> */
    public static function all(): array
    {
        self::ensure();
        $out = array();
        $res = db_query(sprintf('SELECT a.staff_id, a.persona, a.model, a.active, a.image, s.firstname, s.lastname, s.email, d.name AS dept
            FROM `%s` a JOIN `%s` s ON s.staff_id=a.staff_id LEFT JOIN `%s` d ON d.id=s.dept_id ORDER BY s.firstname',
            self::table(), STAFF_TABLE, DEPT_TABLE));
        while ($res && ($r = db_fetch_array($res))) {
            $out[] = array(
                'staff_id' => (int) $r['staff_id'],
                'name'     => trim($r['firstname'] . ' ' . $r['lastname']),
                'email'    => (string) $r['email'],
                'dept'     => (string) $r['dept'],
                'persona'  => (string) $r['persona'],
                'model'    => (string) $r['model'],
                'active'   => (bool) $r['active'],
                // Checked again on the way out: the column may have been written by an older version or by hand.
                'image'    => AiActionsController::imageUrl((string) $r['image']),
            );
        }
        return $out;
    }

    /** Picture URLs of AI agents that have one, by staff ID. */
    public static function images(): array
    {
        $out = array();
        foreach (self::all() as $a) {
            if ($a['image'] !== '') {
                $out[$a['staff_id']] = $a['image'];
            }
        }
        return $out;
    }

    /** An https:// address or a path on this server (AiActionsController::imageUrl()), or '' to remove it. */
    public static function setImage(int $staffId, string $url): bool
    {
        $url = trim($url);
        $clean = AiActionsController::imageUrl($url);
        if (!self::isAgent($staffId) || ($url !== '' && $clean === '')) {
            return false;
        }
        db_query(sprintf('UPDATE `%s` SET image=%s WHERE staff_id=%d', self::table(), db_input($clean), $staffId));
        return true;
    }

    /**
     * Address for osTicket's alerts to the AI agent. The same checks as
     * osTicket's agent form, and the record is changed field by field
     * (Staff::update() would rebuild department access and permissions).
     *
     * @return string '' when saved, else email_bad, email_system or email_taken
     */
    public static function setEmail(int $staffId, string $email): string
    {
        $email = strtolower(trim($email));
        if (!self::isAgent($staffId) || !($staff = Staff::lookup($staffId))
            || strlen($email) > 255 || !Validator::is_valid_email($email)) {
            return 'email_bad';
        }
        if (Email::getIdByEmail($email)) {
            return 'email_system';
        }
        $other = Staff::getIdByEmail($email);
        if ($other && (int) $other !== $staffId) {
            return 'email_taken';
        }
        $staff->email = $email;
        $staff->save();
        return '';
    }

    /**
     * Domains reserved by RFC 2606 / RFC 6761: no mail server delivers there,
     * an SMTP server refuses them outright. Public for the unit tests.
     */
    public static function reservedDomain(string $email): bool
    {
        $domain = strtolower((string) substr((string) strrchr($email, '@'), 1));
        if ($domain === '') {
            return true;
        }
        foreach (array('example.com', 'example.net', 'example.org') as $d) {
            if ($domain === $d || substr($domain, -strlen($d) - 1) === '.' . $d) {
                return true;
            }
        }
        $tld = (string) substr((string) strrchr('.' . $domain, '.'), 1);
        return in_array($tld, array('example', 'invalid', 'test', 'localhost', 'local'), true);
    }

    /** AI agents whose address a mail server refuses. */
    public static function undeliverable(): array
    {
        return array_values(array_filter(self::all(), static function ($a) {
            return self::reservedDomain($a['email']);
        }));
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
    public static function create(string $name, int $deptId, int $roleId, string $persona, string $model, string $image = '', string $email = ''): array
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
            // Staff::update() checks a given address like the agent form: valid, no system email, not taken.
            'email'     => strtolower(trim($email)) ?: $handle['email'],
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
        db_query(sprintf("INSERT INTO `%s` (staff_id, persona, model, active, created, image) VALUES (%d, %s, %s, 1, NOW(), %s)",
            self::table(), (int) $staff->getId(), db_input($persona), db_input($model), db_input(AiActionsController::imageUrl($image))));
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

    /**
     * Signal handler for model.updated: an AI agent saved through osTicket's
     * agent form gets its backend back, and a password set there is replaced
     * by an unknown one. Straight to the database, so no second save (and no
     * second signal) follows; everything else from the form stays.
     */
    public static function guard($model, $data = null): void
    {
        if (!($model instanceof Staff) || !self::isAgent((int) $model->getId())) {
            return;
        }
        $dirty = is_array($data) && is_array($data['dirty'] ?? null) ? $data['dirty'] : array();
        $set = array();
        if ((string) $model->backend !== self::BACKEND) {
            $set['backend'] = self::BACKEND;
        }
        if (array_key_exists('passwd', $dirty)) {
            $set['passwd'] = Passwd::hash(bin2hex(random_bytes(32)));
        }
        if (!$set) {
            return;
        }
        $sql = array();
        foreach ($set as $k => $v) {
            $sql[] = sprintf('`%s`=%s', $k, db_input($v));
            $model->ht[$k] = $v;
        }
        db_query(sprintf('UPDATE `%s` SET %s WHERE staff_id=%d', STAFF_TABLE, implode(', ', $sql), (int) $model->getId()));
        error_log('ai-actions: AI agent ' . (int) $model->getId() . ' saved outside the plugin, restored: ' . implode(', ', array_keys($set)));
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
