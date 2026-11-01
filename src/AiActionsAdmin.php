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

        if (!self::admin($thisstaff)) {
            Http::response(404, 'Not found');
            exit;
        }
        if (!$nav) {
            $nav = new StaffNav($thisstaff);
        }
        $nav->setActiveTab('apps');
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
                    (int) ($_POST['role_id'] ?? 0), (string) ($_POST['persona'] ?? ''), (string) ($_POST['model'] ?? ''));
                $done = $r['id'] ? 'agent_created' : 'agent_failed';
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
            'error'         => 'That didn\'t work.',
        );
        return $map[$code] ?? 'Saved.';
    }

    private static function tabs(string $active): string
    {
        $labels = array('agents' => 'AI agents', 'rules' => 'Rules', 'log' => 'Log', 'status' => 'Status');
        $out = '<ul class="clean tabs spa-admin-tabs">';
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
            $rows .= sprintf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                self::e($a['name']), self::e($a['dept']), self::e($a['model'] ?: 'plugin default'),
                $a['active'] ? 'active' : 'paused',
                self::button($a['active'] ? 'agent_off' : 'agent_on', $a['active'] ? 'Pause' : 'Resume',
                    array('staff_id' => (string) $a['staff_id'])));
        }
        $out = '<h2>AI agents</h2><p class="faded">Agent accounts the AI works as. Assign tickets to them like to anyone; '
            . 'rules decide what they do. Nobody can sign in with them.</p>';
        $out .= '<table class="list" width="100%"><thead><tr><th>Name</th><th>Department</th><th>Model</th><th>State</th><th></th></tr></thead><tbody>'
            . ($rows ?: '<tr><td colspan="5">No AI agents yet.</td></tr>') . '</tbody></table>';

        $depts = '';
        foreach (Dept::getDepartments() as $id => $name) {
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
            . '<tr><td>Model</td><td><select name="model">' . $models . '</select></td></tr>'
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
        return '<h2>Status</h2><table class="list" width="100%"><tbody>'
            . '<tr><td width="220">osTicket cron</td><td>' . $cron . '</td></tr>'
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
        echo '<style>.spa-inline{display:inline;margin:0}.spa-admin-tabs{margin:10px 0 16px}.spa-flow{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:8px 0 14px}'
            . '.spa-flow span{border:1px solid #ccd;border-radius:8px;padding:6px 10px;background:#f6f7fb}.spa-flow i{color:#889}</style>';
        printf('<h1>%s</h1>', self::e($heading));
        echo $body;
        require STAFFINC_DIR . 'footer.inc.php';
        return (string) ob_get_clean();
    }
}
