<?php

declare(strict_types=1);

/**
 * The plugin's own admin page, /scp/ajax.php/ai/admin: AI agents, rules,
 * the log of automatic runs and whether osTicket's cron runs.
 * Admins only; everything else gets the same 404 as the other endpoints.
 */
final class AiActionsAdmin
{
    public const TABS = array('agents', 'rules', 'log', 'status');

    public static function page(): string
    {
        global $thisstaff, $nav, $ost;

        // Agents reach this page from Applications; only admins manage it.
        $agent = $thisstaff instanceof Staff && $thisstaff->isValid() && AiActionsController::settings()['enabled'];
        if (!$agent) {
            Http::response(404, 'Not found');
            exit;
        }
        if (!$nav) {
            $nav = new StaffNav($thisstaff);
        }
        $nav->setActiveTab('apps');
        if (!self::admin($thisstaff)) {
            return self::render('AI Actions', '<p>AI agents, rules and the log of automatic work are managed by administrators. '
                . 'Ask an admin if an AI agent should take over tickets of yours.</p>');
        }
        $tab = in_array($_GET['tab'] ?? '', self::TABS, true) ? $_GET['tab'] : 'agents';
        $notice = is_string($_GET['done'] ?? null) && preg_match('/^[a-z_]{1,30}$/', $_GET['done']) ? $_GET['done'] : '';

        $body = self::tabs($tab);
        if ($notice !== '') {
            $body .= '<div id="msg_notice">' . self::e(self::notice($notice)) . '</div>';
        }
        switch ($tab) {
            case 'status':
                $body .= self::status();
                break;
            case 'rules':
                $body .= class_exists('AiActionsRules') ? AiActionsRules::adminView() : '<p>Rules come with the next part.</p>';
                break;
            case 'log':
                $body .= class_exists('AiActionsRules') ? AiActionsRules::logView() : '<p>The log comes with the next part.</p>';
                break;
            default:
                $body .= self::agents();
        }
        return self::render('AI Actions', $body);
    }

    /** POST /ai/admin: one form action, then back to the page (post/redirect/get). */
    public static function post(): string
    {
        global $thisstaff, $ost;

        if (!self::admin($thisstaff)) {
            Http::response(404, 'Not found');
            exit;
        }
        if (!$ost || !$ost->checkCSRFToken()) {
            Http::response(400, 'Session expired, reload the page.');
            exit;
        }
        $do = (string) ($_POST['do'] ?? '');
        $done = 'error';
        $tab = 'agents';
        switch ($do) {
            case 'agent_create':
                $r = AiActionsAgents::create((string) ($_POST['name'] ?? ''), (int) ($_POST['dept_id'] ?? 0),
                    (int) ($_POST['role_id'] ?? 0), (string) ($_POST['persona'] ?? ''), (string) ($_POST['model'] ?? ''),
                    (string) ($_POST['image'] ?? ''), (string) ($_POST['email'] ?? ''));
                $done = $r['id'] ? 'agent_created' : (isset($r['errors']['email']) ? 'agent_email' : 'agent_failed');
                if (!$r['id']) {
                    error_log('ai-actions: AI agent not created: ' . implode(', ', array_keys($r['errors'])));
                }
                break;
            case 'agent_on':
            case 'agent_off':
                $id = (int) ($_POST['staff_id'] ?? 0);
                if (AiActionsAgents::isAgent($id)) {
                    AiActionsAgents::setActive($id, $do === 'agent_on');
                    $done = 'saved';
                }
                break;
            case 'agent_image':
                $done = AiActionsAgents::setImage((int) ($_POST['staff_id'] ?? 0), (string) ($_POST['image'] ?? '')) ? 'saved' : 'image_bad';
                break;
            case 'agent_email':
                $done = AiActionsAgents::setEmail((int) ($_POST['staff_id'] ?? 0), (string) ($_POST['email'] ?? '')) ?: 'saved';
                break;
            default:
                if (class_exists('AiActionsRules')) {
                    list($tab, $done) = AiActionsRules::handle($do, $_POST);
                }
        }
        Http::redirect(ROOT_PATH . AiActionsPlugin::STAFF_BASE . '/admin?tab=' . rawurlencode($tab) . '&done=' . rawurlencode($done));
        exit;
    }

    public static function admin($staff): bool
    {
        return $staff instanceof Staff && $staff->isValid() && $staff->isAdmin()
            && AiActionsController::settings()['enabled'];
    }

    private static function notice(string $code): string
    {
        $map = array(
            'agent_created' => 'AI agent created. It shows up in the assignment lists; nobody can sign in with it.',
            'agent_failed'  => 'osTicket refused the account. Check name, department and role.',
            'saved'         => 'Saved.',
            'deleted'       => 'Deleted.',
            'undone'        => 'Undone.',
            'agent_email'   => 'osTicket refused the email address: it must be valid, not one of the help desk\'s own addresses and not used by another agent.',
            'image_bad'     => 'Picture not saved: use an https:// address or a path on this server, e.g. /images/bot.png.',
            'email_bad'     => 'Email address not saved: it isn\'t a valid address.',
            'email_system'  => 'Email address not saved: it is one of the help desk\'s own addresses (Emails). osTicket would fetch its own alerts and file mail from it under the AI agent. Use a mailbox osTicket doesn\'t fetch.',
            'email_taken'   => 'Email address not saved: another agent already uses it. For several AI agents on one team mailbox, use plus addresses (team+triage@…), if your mail server supports them.',
            'error'         => 'That didn\'t work.',
        );
        return $map[$code] ?? 'Saved.';
    }

    private static function tabs(string $active): string
    {
        $labels = array('agents' => 'AI agents', 'rules' => 'Rules', 'log' => 'Log', 'status' => 'Status');
        // Not osTicket's ul.tabs: scp.js takes over clicks on those (in-page panes) and the links would do nothing.
        $out = '<ul class="spa-admin-tabs">';
        foreach ($labels as $k => $l) {
            $out .= sprintf('<li class="%s"><a href="%s">%s</a></li>', $k === $active ? 'active' : '',
                self::e(ROOT_PATH . AiActionsPlugin::STAFF_BASE . '/admin?tab=' . $k), self::e($l));
        }
        return $out . '</ul>';
    }

    private static function agents(): string
    {
        $rows = '';
        foreach (AiActionsAgents::all() as $a) {
            $picture = ($a['image'] !== '' ? sprintf('<img src="%s" alt="" width="28" height="28" style="border-radius:50%%;object-fit:cover;vertical-align:middle;margin-right:6px">',
                self::e($a['image'])) : '')
                . self::form('agent_image', sprintf('<input type="hidden" name="staff_id" value="%d"><input name="image" size="28" maxlength="500" value="%s" placeholder="https://… or /path.png"> <input type="submit" class="button" value="Save">',
                    (int) $a['staff_id'], self::e($a['image'])), 'spa-inline');
            $email = self::form('agent_email', sprintf('<input type="hidden" name="staff_id" value="%d"><input type="email" name="email" size="28" maxlength="255" value="%s" required> <input type="submit" class="button" value="Save">',
                (int) $a['staff_id'], self::e($a['email'])), 'spa-inline')
                . (AiActionsAgents::reservedDomain($a['email']) ? ' <span class="error">refused by mail servers</span>' : '');
            $rows .= sprintf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                self::e($a['name']), self::e($a['dept']), $email, self::e($a['model'] ?: 'plugin default'), $picture,
                $a['active'] ? 'active' : 'paused',
                self::button($a['active'] ? 'agent_off' : 'agent_on', $a['active'] ? 'Pause' : 'Resume',
                    array('staff_id' => (string) $a['staff_id'])));
        }
        $out = '<h2>AI agents</h2><p class="faded">Agent accounts the AI works as. Assign tickets to them like to anyone; '
            . 'rules decide what they do. Nobody can sign in with them. The picture shows in the ticket chat and at their thread entries.</p>'
            . '<p class="faded">osTicket sends AI agents its agent alerts (new ticket, assignment, …) like anyone else. '
            . 'With SMTP, give them an address your mail server accepts, e.g. a team mailbox or an empty distribution list. '
            . 'Not one of the help desk\'s own addresses: osTicket would fetch the alerts back.</p>';
        $out .= '<table class="list" width="100%"><thead><tr><th>Name</th><th>Department</th><th>Email (alerts)</th><th>Model</th><th>Picture</th><th>State</th><th></th></tr></thead><tbody>'
            . ($rows ?: '<tr><td colspan="7">No AI agents yet.</td></tr>') . '</tbody></table>';

        $depts = '';
        // osTicket refuses a new agent in a disabled or archived department.
        foreach (Dept::getDepartments(array('activeonly' => true)) as $id => $name) {
            $depts .= sprintf('<option value="%d">%s</option>', (int) $id, self::e((string) $name));
        }
        $roles = '';
        foreach (Role::objects()->order_by('name') as $role) {
            $roles .= sprintf('<option value="%d">%s</option>', (int) $role->getId(), self::e((string) $role->getName()));
        }
        $models = '<option value="">Plugin default</option>';
        foreach (AiActionsController::MODELS as $m) {
            $models .= sprintf('<option value="%s">%s</option>', self::e($m), self::e($m));
        }
        $out .= '<h3>New AI agent</h3>' . self::form('agent_create',
            '<table class="form_table" width="100%"><tbody>'
            . '<tr><td width="180">Name</td><td><input name="name" maxlength="60" size="30" required placeholder="Prisma"></td></tr>'
            . '<tr><td>Department</td><td><select name="dept_id">' . $depts . '</select></td></tr>'
            . '<tr><td>Role</td><td><select name="role_id">' . $roles . '</select> '
            . '<span class="faded">What the AI agent may do; it never gets more than this role.</span></td></tr>'
            . '<tr><td>Email (alerts)</td><td><input type="email" name="email" size="30" maxlength="255" placeholder="team@your-domain"> '
            . '<span class="faded">Optional. Empty: an @example.com address, fine without SMTP; with SMTP your mail server refuses it.</span></td></tr>'
            . '<tr><td>Model</td><td><select name="model">' . $models . '</select></td></tr>'
            . '<tr><td>Picture</td><td><input name="image" size="40" maxlength="500" placeholder="https://… or /path.png"> '
            . '<span class="faded">Optional. Square images look best; shown round.</span></td></tr>'
            . '<tr><td>Persona</td><td><textarea name="persona" rows="3" cols="60" maxlength="500" '
            . 'placeholder="You are Prisma, the first-level assistant of …"></textarea></td></tr>'
            . '</tbody></table><p><input type="submit" class="button" value="Create AI agent"></p>');
        return $out;
    }

    private static function status(): string
    {
        $age = AiActionsJobs::cronAge();
        $cron = $age === null
            ? '<span class="error">osTicket\'s cron hasn\'t run since the plugin was enabled. Rules need it: '
              . 'schedule api/cron.php every 1–5 minutes (Plesk: Scheduled Tasks).</span>'
            : ($age > 900
                ? sprintf('<span class="error">Last cron run %d minutes ago. Rules wait until it runs again.</span>', intdiv($age, 60))
                : sprintf('Last cron run %d seconds ago.', $age));
        $c = AiActionsJobs::counts();
        $bad = AiActionsAgents::undeliverable();
        $mail = $bad
            ? sprintf('<span class="error">%s: %s. osTicket alerts AI agents; with SMTP the mail server refuses these addresses, '
                . 'and osTicket 1.18 then fails the remaining mails of the same run (system log: "Cannot issue HELO to existing session"). '
                . 'Set an address under AI agents.</span>',
                count($bad) === 1 ? 'An AI agent has an address no mail server delivers to' : 'AI agents have addresses no mail server delivers to',
                self::e(implode(', ', array_map(static function ($a) { return $a['name'] . ' <' . $a['email'] . '>'; }, $bad))))
            : 'Every AI agent has a deliverable address.';
        return '<h2>Status</h2><table class="list" width="100%"><tbody>'
            . '<tr><td width="220">osTicket cron</td><td>' . $cron . '</td></tr>'
            . '<tr><td>AI agents\' email</td><td>' . $mail . '</td></tr>'
            . sprintf('<tr><td>Jobs</td><td>%d queued, %d running, %d done, %d failed (last 30 days)</td></tr>',
                $c['queued'], $c['running'], $c['done'], $c['failed'])
            . '<tr><td>Automatic work</td><td>' . ((AiActionsController::settings()['auto_paused'] ?? false)
                ? '<span class="error">Paused in the plugin settings.</span>' : 'On') . '</td></tr>'
            . '</tbody></table>';
    }

    /** A POST form with the CSRF token. */
    public static function form(string $do, string $inner, string $class = ''): string
    {
        global $ost;

        // csrf_token() echoes instead of returning; the form needs the string.
        $token = $ost ? $ost->getCSRF()->getFormInput() : '';
        return sprintf('<form method="post" action="%s" class="%s">%s<input type="hidden" name="do" value="%s">%s</form>',
            self::e(ROOT_PATH . AiActionsPlugin::STAFF_BASE . '/admin'), self::e($class), $token, self::e($do), $inner);
    }

    public static function button(string $do, string $label, array $fields = array()): string
    {
        $hidden = '';
        foreach ($fields as $k => $v) {
            $hidden .= sprintf('<input type="hidden" name="%s" value="%s">', self::e($k), self::e($v));
        }
        return self::form($do, $hidden . sprintf('<input type="submit" class="button" value="%s">', self::e($label)), 'spa-inline');
    }

    public static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    private static function render(string $heading, string $body): string
    {
        global $ost, $cfg, $thisstaff, $nav, $errors, $msg, $warn, $info;

        // scp/ajax.php/... would break the core's relative links (same as the Ticket Board page).
        $ost->addExtraHeader(sprintf('<base href="%s" />', self::e(ROOT_PATH . 'scp/')));
        ob_start();
        require STAFFINC_DIR . 'header.inc.php';
        echo '<style>.spa-inline{display:inline;margin:0}.spa-admin-tabs{list-style:none;margin:10px 0 16px;padding:0;display:flex;gap:4px;border-bottom:1px solid #ccd}.spa-admin-tabs li{margin:0}.spa-admin-tabs a{display:block;padding:6px 14px;border:1px solid transparent;border-bottom:0;border-radius:6px 6px 0 0;text-decoration:none}.spa-admin-tabs li.active a{border-color:#ccd;background:inherit;font-weight:600;margin-bottom:-1px}.spa-flow{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:8px 0 14px}'
            . '.spa-flow span{border:1px solid #ccd;border-radius:8px;padding:6px 10px;background:#f6f7fb}.spa-flow i{color:#889}</style>';
        printf('<h1>%s</h1>', self::e($heading));
        echo $body;
        require STAFFINC_DIR . 'footer.inc.php';
        return (string) ob_get_clean();
    }
}
