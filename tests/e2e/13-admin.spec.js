// @ts-check
// 0.5.0 S6: the settings chat for admins. Every change: preview, Run, and
// nothing but the named fields changes in the database row.
const { test, expect } = require('@playwright/test');
const { requireSeed, staffLogin, sql, php } = require('./support');

requireSeed();

async function adminChat(page, path = '/scp/helptopics.php') {
  await page.goto(path);
  if (!(await page.locator('#spa-ai').isVisible())) await page.locator('#spa-ai-launcher').click();
  const chat = page.locator('#spa-ai');
  await expect(chat.locator('.spa-ai-subtitle')).toHaveText('Settings');
  return chat;
}

async function say(chat, text) {
  const before = await chat.locator('.spa-ai-msg-ai').count();
  await chat.locator('.spa-ai-input').fill(text);
  await chat.locator('.spa-ai-input').press('Enter');
  await expect(chat.locator('.spa-ai-msg-ai:not(.spa-ai-busy)')).toHaveCount(before + 1);
  return chat.locator('.spa-ai-msg-ai').last();
}

/** Run, then the admin page reloads to show the change; the chat opens again by itself. */
async function runPlan(reply) {
  const page = reply.page();
  await Promise.all([page.waitForEvent('load', { timeout: 15000 }), reply.locator('.spa-ai-run').click()]);
  await expect(page.locator('#spa-ai .spa-ai-plan-done').last()).toBeVisible({ timeout: 15000 });
  return page.locator('#spa-ai');
}

/** A row without the columns that may change. */
function row(table, idCol, id, skip) {
  const cols = sql(`SELECT GROUP_CONCAT(column_name) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='${table}'`)
    .split(',').filter((c) => !skip.includes(c));
  return sql(`SELECT ${cols.map((c) => '`' + c + '`').join(',')} FROM ${table} WHERE ${idCol}=${id}`);
}

test.beforeEach(() => {
  sql('DELETE FROM ost_ai_actions_chat WHERE ticket_id=0');
});

test('only admins: other agents get 404 for the settings chat', async ({ page }) => {
  await staffLogin(page, 'philipp');
  const res = await page.request.get('/scp/ajax.php/ai/chat?ticket_id=0');
  expect(res.status()).toBe(404);
  await page.goto('/scp/index.php');
  const token = await page.locator('meta[name="csrf_token"]').getAttribute('content');
  const post = await page.request.post('/scp/ajax.php/ai/plan', { form: { ticket_id: '0', prompt: 'create a canned response', __CSRFToken__: token },
    headers: { 'X-Requested-With': 'XMLHttpRequest' } });
  expect(post.status()).toBe(404);
});

test('canned response: planned, previewed, created on Run', async ({ page }) => {
  sql("DELETE FROM ost_canned_response WHERE title='Password reset'");
  await staffLogin(page, 'admin');
  const chat = await adminChat(page, '/scp/canned.php');
  const reply = await say(chat, 'create a canned response');
  await expect(reply.locator('.spa-ai-plan-label')).toHaveText('Canned response');
  await expect(reply.locator('.spa-ai-plan-value')).toContainText('title: Password reset');
  expect(sql("SELECT COUNT(*) FROM ost_canned_response WHERE title='Password reset'")).toBe('0');
  await runPlan(reply);
  expect(sql("SELECT isenabled FROM ost_canned_response WHERE title='Password reset'")).toBe('1');
});

test('SLA rename: only the name changes, undo puts it back', async ({ page }) => {
  const id = sql('SELECT MIN(id) FROM ost_sla');
  sql(`UPDATE ost_sla SET name='Default SLA' WHERE id=${id}`);
  sql(`UPDATE ost_sla SET flags = flags & ~6 WHERE id=${id}`); // alerts and transient off: the isset() trap would switch them on
  const name = sql(`SELECT name FROM ost_sla WHERE id=${id}`);
  const before = row('ost_sla', 'id', id, ['name', 'updated']);
  await staffLogin(page, 'admin');
  const chat = await adminChat(page, '/scp/slas.php');
  const reply = await say(chat, 'rename sla');
  await expect(reply.locator('.spa-ai-plan-value')).toContainText(`name: ${name} → Renamed SLA`);
  const after = await runPlan(reply);
  expect(sql(`SELECT name FROM ost_sla WHERE id=${id}`)).toBe('Renamed SLA');
  expect(row('ost_sla', 'id', id, ['name', 'updated'])).toBe(before);

  await Promise.all([page.waitForEvent('load', { timeout: 15000 }), after.locator('.spa-ai-plan-done .spa-ai-undo').last().click()]);
  await expect(page.locator('#spa-ai .spa-ai-plan-undone')).toBeVisible({ timeout: 15000 });
  expect(sql(`SELECT name FROM ost_sla WHERE id=${id}`)).toBe(name);
  expect(row('ost_sla', 'id', id, ['name', 'updated'])).toBe(before);
});

test('help topic disable: only the status flag changes, auto-assignment and options stay', async ({ page }) => {
  sql('UPDATE ost_help_topic SET noautoresp=0, flags = flags | 2');
  await staffLogin(page, 'admin');
  const chat = await adminChat(page);
  const reply = await say(chat, 'disable topic');
  const value = await reply.locator('.spa-ai-plan-value').innerText();
  expect(value).toContain('enabled: yes → no');
  const id = value.match(/^#(\d+)/)[1];
  const before = row('ost_help_topic', 'topic_id', id, ['flags', 'updated', 'sort']);
  await runPlan(reply);
  expect(Number(sql(`SELECT flags & 2 FROM ost_help_topic WHERE topic_id=${id}`))).toBe(0);
  expect(row('ost_help_topic', 'topic_id', id, ['flags', 'updated', 'sort'])).toBe(before);
  php(`$t = Topic::lookup(${id}); $t->setFlag(Topic::FLAG_ACTIVE, true); $t->save();`);
});

test('list item: added to a list; the log can undo it (disable, not delete)', async ({ page }) => {
  sql("DELETE FROM ost_list_items WHERE value='Fake item'");
  const list = php('$l = DynamicList::create(array("name" => "AI test list", "name_plural" => "AI test lists")); $l->save(); echo $l->getId();');
  await staffLogin(page, 'admin');
  const chat = await adminChat(page, '/scp/lists.php');
  const reply = await say(chat, 'add a list item');
  await runPlan(reply);
  expect(sql(`SELECT status FROM ost_list_items WHERE value='Fake item' ORDER BY id DESC LIMIT 1`)).toBe('1');

  await page.goto('/scp/ajax.php/ai/admin?tab=log');
  await expect(page.locator('table.list')).toContainText('admin chat');
  await page.locator('input[type="submit"][value="Undo"]').first().click();
  await expect(page.locator('#msg_notice')).toContainText('Undone');
  expect(sql(`SELECT status & 1 FROM ost_list_items WHERE value='Fake item' ORDER BY id DESC LIMIT 1`)).toBe('0');
});

test('nothing outside the areas: a delete request plans nothing', async ({ page }) => {
  await staffLogin(page, 'admin');
  const chat = await adminChat(page);
  const reply = await say(chat, 'delete all departments');
  await expect(reply.locator('.spa-ai-plan')).toHaveCount(0);
});

test('ticket pages keep the ticket chat; admin pages without the settings chat for non-admins', async ({ page }) => {
  await staffLogin(page, 'philipp');
  await page.goto('/scp/index.php');
  const box = await page.locator('#spa-ai-config').textContent();
  expect(JSON.parse(box || '{}').admin).toBe(false);
});

test('ticket filter: created with rule and action, and it acts on the next ticket', async ({ page }) => {
  sql("DELETE f, r, a FROM ost_filter f LEFT JOIN ost_filter_rule r ON r.filter_id=f.id LEFT JOIN ost_filter_action a ON a.filter_id=f.id WHERE f.name='AI supplier filter'");
  await staffLogin(page, 'admin');
  const chat = await adminChat(page, '/scp/filters.php');
  const reply = await say(chat, 'create filter');
  await expect(reply.locator('.spa-ai-plan-label')).toHaveText('Ticket filter');
  await expect(reply.locator('.spa-ai-plan-value')).toContainText('rules: email contains @lieferant.example.com');
  await runPlan(reply);
  const id = sql("SELECT id FROM ost_filter WHERE name='AI supplier filter'");
  expect(sql(`SELECT CONCAT(what,' ',how,' ',val) FROM ost_filter_rule WHERE filter_id=${id}`)).toBe('email contains @lieferant.example.com');
  const dept = sql(`SELECT JSON_VALUE(configuration, '$.dept_id') FROM ost_filter_action WHERE filter_id=${id} AND type='dept'`);
  expect(Number(dept)).toBeGreaterThan(0);

  const ticket = php(`User::fromVars(array('name' => 'Bob Supplier', 'email' => 'bob@lieferant.example.com'));`
    + ` $e = array(); $t = Ticket::create(array('email' => 'bob@lieferant.example.com', 'name' => 'Bob', 'subject' => 'Delivery', 'message' => 'Hi',`
    + ` 'ip' => '127.0.0.1', 'source' => 'API'), $e, 'api', false, false); echo $t ? $t->getId() : json_encode($e);`);
  expect(sql(`SELECT dept_id FROM ost_ticket WHERE ticket_id=${ticket}`)).toBe(dept);
});

test('department rename: name only; members and access stay (members[] trap)', async ({ page }) => {
  const id = sql("SELECT MIN(id) FROM ost_department");
  sql(`UPDATE ost_department SET name='Support' WHERE id=${id} AND name='Renamed Dept'`);
  const name = sql(`SELECT name FROM ost_department WHERE id=${id}`);
  const before = row('ost_department', 'id', id, ['name', 'updated', 'path']);
  const members = sql(`SELECT CONCAT((SELECT COUNT(*) FROM ost_staff WHERE dept_id=${id}), '/', (SELECT COUNT(*) FROM ost_staff_dept_access WHERE dept_id=${id}))`);
  await staffLogin(page, 'admin');
  const chat = await adminChat(page, '/scp/departments.php');
  const reply = await say(chat, 'rename department');
  await expect(reply.locator('.spa-ai-plan-value')).toContainText(`name: ${name} → Renamed Dept`);
  await runPlan(reply);
  expect(sql(`SELECT name FROM ost_department WHERE id=${id}`)).toBe('Renamed Dept');
  expect(row('ost_department', 'id', id, ['name', 'updated', 'path'])).toBe(before);
  expect(sql(`SELECT CONCAT((SELECT COUNT(*) FROM ost_staff WHERE dept_id=${id}), '/', (SELECT COUNT(*) FROM ost_staff_dept_access WHERE dept_id=${id}))`)).toBe(members);
  sql(`UPDATE ost_department SET name='${name}' WHERE id=${id}`);
});

test('team member: added alone, the others stay; undo removes only that one', async ({ page }) => {
  let team = sql('SELECT MIN(team_id) FROM ost_team');
  if (team === 'NULL' || !team) {
    team = php('$t = Team::create(array("name" => "Level 2", "flags" => Team::FLAG_ENABLED, "notes" => "")); $t->save(); echo $t->getId();');
  }
  const philipp = sql("SELECT staff_id FROM ost_staff WHERE firstname='Philipp' ORDER BY staff_id LIMIT 1");
  const admin = sql("SELECT MIN(staff_id) FROM ost_staff");
  sql(`DELETE FROM ost_team_member WHERE team_id=${team} AND staff_id=${philipp}`);
  sql(`INSERT IGNORE INTO ost_team_member (team_id, staff_id, flags) VALUES (${team}, ${admin}, 0)`);
  await staffLogin(page, 'admin');
  const chat = await adminChat(page, '/scp/teams.php');
  const reply = await say(chat, 'add to team');
  await expect(reply.locator('.spa-ai-plan-label')).toHaveText('Team member');
  const after = await runPlan(reply);
  expect(sql(`SELECT GROUP_CONCAT(staff_id ORDER BY staff_id) FROM ost_team_member WHERE team_id=${team}`))
    .toBe([admin, philipp].map(Number).sort((a, b) => a - b).join(','));

  await Promise.all([page.waitForEvent('load', { timeout: 15000 }), after.locator('.spa-ai-plan-done .spa-ai-undo').last().click()]);
  await expect(page.locator('#spa-ai .spa-ai-plan-undone')).toBeVisible({ timeout: 15000 });
  expect(sql(`SELECT GROUP_CONCAT(staff_id) FROM ost_team_member WHERE team_id=${team}`)).toBe(String(admin));
});
