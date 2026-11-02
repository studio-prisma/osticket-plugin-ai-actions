// @ts-check
// 0.5.3: the settings chat creates departments, gives agents access to
// departments, lets filters reject or send an email; all with undo, all
// for admins only.
const { test, expect } = require('@playwright/test');
const { seed, requireSeed, staffLogin, sql, php } = require('./support');

requireSeed();
const ADMIN = seed.agents.admin;
const PHILIPP = seed.agents.philipp;
const SUPPORT = Number(sql("SELECT id FROM ost_department WHERE name='Support'"));
const SALES = Number(sql("SELECT id FROM ost_department WHERE name='Sales'"));
const ROLE = Number(sql('SELECT MAX(id) FROM ost_role'));

/** Runs one admin tool as $staff and returns run()'s result; validate() first, as the planner does. */
function tool(name, input, staff = ADMIN) {
  const json = JSON.stringify(input).replace(/'/g, "\\'");
  return JSON.parse(php(`$in = AiActionsAdminTools::validate('${name}', json_decode('${json}', true));`
    + ` echo json_encode($in === null ? array('ok' => false, 'error' => 'invalid') : AiActionsAdminTools::run('${name}', $in, Staff::lookup(${staff})));`));
}

function undo(name, previous, staff = ADMIN) {
  const json = JSON.stringify(previous).replace(/'/g, "\\'");
  return JSON.parse(php(`echo json_encode(AiActionsAdminTools::undo('${name}', json_decode('${json}', true), Staff::lookup(${staff})));`));
}

function newFilter(name) {
  sql(`DELETE FROM ost_filter WHERE name='${name}'`);
  const r = tool('save_filter', { id: '', name, enabled: 'yes', target: 'Any', match: 'all',
    rules: 'email contains @spam.example.com', actions: `dept ${SUPPORT}` });
  expect(r.ok).toBe(true);
  return Number(sql(`SELECT id FROM ost_filter WHERE name='${name}'`));
}

const actions = (id) => sql(`SELECT GROUP_CONCAT(type ORDER BY type) FROM ost_filter_action WHERE filter_id=${id}`);

test.beforeEach(() => {
  sql('DELETE FROM ost_ai_actions_chat WHERE ticket_id=0');
});

test('only admins: the tools refuse an agent who is not one', () => {
  const r = tool('save_department', { id: '', name: 'Not allowed', manager: '', sla: '', public: '' }, PHILIPP);
  expect(r).toMatchObject({ ok: false, error: 'forbidden' });
  expect(sql("SELECT COUNT(*) FROM ost_department WHERE name='Not allowed'")).toBe('0');
});

test('department: created from the settings chat, switched off by undo', async ({ page }) => {
  sql("DELETE FROM ost_department WHERE name='AI Created Dept'");
  await staffLogin(page, 'admin');
  await page.goto('/scp/departments.php');
  if (!(await page.locator('#spa-ai').isVisible())) await page.locator('#spa-ai-launcher').click();
  const chat = page.locator('#spa-ai');
  await chat.locator('.spa-ai-input').fill('create department');
  await chat.locator('.spa-ai-input').press('Enter');
  await expect(chat.locator('.spa-ai-msg-ai:not(.spa-ai-busy)')).toHaveCount(1);
  const reply = chat.locator('.spa-ai-msg-ai:not(.spa-ai-busy)').last();
  await expect(reply.locator('.spa-ai-plan-label')).toHaveText('Department');
  expect(sql("SELECT COUNT(*) FROM ost_department WHERE name='AI Created Dept'")).toBe('0');
  await Promise.all([page.waitForEvent('load', { timeout: 15000 }), reply.locator('.spa-ai-run').click()]);
  const id = Number(sql("SELECT id FROM ost_department WHERE name='AI Created Dept'"));
  expect(id).toBeGreaterThan(0);
  expect(sql(`SELECT ispublic FROM ost_department WHERE id=${id}`)).toBe('0');
  expect(sql(`SELECT COUNT(*) FROM ost_staff_dept_access WHERE dept_id=${id}`)).toBe('0');

  const last = JSON.parse(sql("SELECT results FROM ost_ai_actions_log WHERE mode='admin' ORDER BY id DESC LIMIT 1"));
  expect(undo('save_department', last[0].previous).ok).toBe(true);
  expect(Number(sql(`SELECT flags & 4 FROM ost_department WHERE id=${id}`))).toBe(0);
  expect(sql(`SELECT COUNT(*) FROM ost_department WHERE id=${id}`)).toBe('1');
});

test('department access: given with a role, changed, taken; undo restores each step; members of others stay', () => {
  sql(`DELETE FROM ost_staff_dept_access WHERE staff_id=${PHILIPP} AND dept_id=${SALES}`);
  const othersBefore = sql(`SELECT COUNT(*) FROM ost_staff_dept_access WHERE dept_id=${SALES} AND staff_id<>${PHILIPP}`);
  const add = tool('dept_member', { id: '', department: String(SALES), agent: String(PHILIPP), role: String(ROLE), member: 'yes' });
  expect(add.ok).toBe(true);
  expect(sql(`SELECT role_id FROM ost_staff_dept_access WHERE staff_id=${PHILIPP} AND dept_id=${SALES}`)).toBe(String(ROLE));

  const remove = tool('dept_member', { id: '', department: String(SALES), agent: String(PHILIPP), role: '', member: 'no' });
  expect(remove.ok).toBe(true);
  expect(sql(`SELECT COUNT(*) FROM ost_staff_dept_access WHERE staff_id=${PHILIPP} AND dept_id=${SALES}`)).toBe('0');

  expect(undo('dept_member', remove.previous).ok).toBe(true);
  expect(sql(`SELECT role_id FROM ost_staff_dept_access WHERE staff_id=${PHILIPP} AND dept_id=${SALES}`)).toBe(String(ROLE));
  expect(undo('dept_member', add.previous).ok).toBe(true);
  expect(sql(`SELECT COUNT(*) FROM ost_staff_dept_access WHERE staff_id=${PHILIPP} AND dept_id=${SALES}`)).toBe('0');
  expect(sql(`SELECT COUNT(*) FROM ost_staff_dept_access WHERE dept_id=${SALES} AND staff_id<>${PHILIPP}`)).toBe(othersBefore);
});

test('department access: the primary department is left to the agent settings', () => {
  const primary = sql(`SELECT dept_id FROM ost_staff WHERE staff_id=${PHILIPP}`);
  const r = tool('dept_member', { id: '', department: primary, agent: String(PHILIPP), role: String(ROLE), member: 'yes' });
  expect(r.ok).toBe(false);
});

test('filter: reject and email are set, then undo puts the old actions back exactly', () => {
  const id = newFilter('AI reject test');
  expect(actions(id)).toBe('dept');
  const rej = tool('save_filter', { id: String(id), name: '', enabled: '', target: '', match: '', rules: '', actions: 'reject' });
  expect(rej.ok).toBe(true);
  expect(actions(id)).toBe('dept,reject');
  const mail = tool('filter_email', { id: String(id), recipients: 'ops@example.com', subject: 'Spam seen', message: 'A filter matched.', active: 'yes' });
  expect(mail.ok).toBe(true);
  expect(actions(id)).toBe('dept,email,reject');
  const config = JSON.parse(sql(`SELECT configuration FROM ost_filter_action WHERE filter_id=${id} AND type='email'`));
  expect(config).toMatchObject({ recipients: 'ops@example.com', subject: 'Spam seen', from: '' });

  expect(undo('filter_email', mail.previous).ok).toBe(true);
  expect(actions(id)).toBe('dept,reject');
  expect(undo('save_filter', rej.previous).ok).toBe(true);
  expect(actions(id)).toBe('dept');
});

test('filter email: an osTicket variable as recipient is refused', () => {
  const id = newFilter('AI email test');
  const r = tool('filter_email', { id: String(id), recipients: '%{user}', subject: 'x', message: 'y', active: 'yes' });
  expect(r).toMatchObject({ ok: false, error: 'invalid' });
  expect(actions(id)).toBe('dept');
});
