// @ts-check
// 0.5.4: the address osTicket alerts an AI agent at. A reserved domain
// (example.com) is refused by any SMTP server, and osTicket 1.18 then fails
// the rest of the run's mails, so it can be changed, with the agent form's
// checks, and the status tab warns while one is left.
const { test, expect } = require('@playwright/test');
const { seed, requireSeed, staffLogin, sql, php } = require('./support');

requireSeed();
// Addresses must be unique per agent; a second run on the same stack needs new ones.
const RUN = Date.now();
const SYSTEM = sql('SELECT email FROM ost_email ORDER BY email_id LIMIT 1');
const TAKEN = sql(`SELECT email FROM ost_staff WHERE staff_id=${seed.agents.admin}`);
// osTicket may look the domain up in DNS (Settings → Agents → verify email
// addresses); the test domain doesn't exist, and CI may have no DNS.
// The row only exists once someone saved that page; without it the default is on.
const WHERE = "namespace='core' AND `key`='verify_email_addrs'";
let verify = '';

test.beforeAll(() => {
  verify = sql(`SELECT value FROM ost_config WHERE ${WHERE}`);
  sql(`DELETE FROM ost_config WHERE ${WHERE}`);
  sql("INSERT INTO ost_config (namespace, `key`, value, updated) VALUES ('core', 'verify_email_addrs', '0', NOW())");
});

test.afterAll(() => {
  sql(`DELETE FROM ost_config WHERE ${WHERE}`);
  if (verify !== '') {
    sql(`INSERT INTO ost_config (namespace, \`key\`, value, updated) VALUES ('core', 'verify_email_addrs', '${verify === '1' ? 1 : 0}', NOW())`);
  }
});

async function createAgent(page, name, email = '') {
  await page.goto('/scp/ajax.php/ai/admin?tab=agents');
  await page.locator('input[name="name"]').fill(name);
  if (email) await page.locator('form input[name="email"][placeholder]').fill(email);
  await page.locator('input[type="submit"][value="Create AI agent"]').click();
}

function row(page, name) {
  return page.locator('table.list tr', { hasText: name });
}

test('a new AI agent without an address is flagged; a team address clears the warning', async ({ page }) => {
  const name = `Mailbot ${Date.now()}`;
  await staffLogin(page, 'admin');
  await createAgent(page, name);
  await expect(page.locator('#msg_notice')).toContainText('AI agent created');
  const id = Number(sql('SELECT MAX(staff_id) FROM ost_ai_actions_agent'));
  expect(sql(`SELECT email FROM ost_staff WHERE staff_id=${id}`)).toMatch(/@example\.com$/);
  await expect(row(page, name)).toContainText('refused by mail servers');

  await page.goto('/scp/ajax.php/ai/admin?tab=status');
  await expect(page.locator('body')).toContainText(name);
  await expect(page.locator('body')).toContainText('Cannot issue HELO to existing session');

  const account = `SELECT CONCAT(dept_id,'/',role_id,'/',backend,'/',isactive,'/',passwd) FROM ost_staff WHERE staff_id=${id}`;
  const access = `SELECT COUNT(*) FROM ost_staff_dept_access WHERE staff_id=${id}`;
  const before = [sql(account), sql(access)];
  await page.goto('/scp/ajax.php/ai/admin?tab=agents');
  await row(page, name).locator('input[name="email"]').fill(`Team+Mailbot${RUN}@Helpdesk-Example.de`);
  await row(page, name).locator('form:has(input[name="email"]) input[type="submit"]').click();
  await expect(page.locator('#msg_notice')).toHaveText('Saved.');
  expect(sql(`SELECT email FROM ost_staff WHERE staff_id=${id}`)).toBe(`team+mailbot${RUN}@helpdesk-example.de`);
  await expect(row(page, name)).not.toContainText('refused by mail servers');

  // Only the address changed: department, role, backend, password and access stay as they were.
  expect([sql(account), sql(access)]).toEqual(before);
  await page.goto('/scp/ajax.php/ai/admin?tab=status');
  await expect(page.locator('body')).not.toContainText(name);
});

test('the help desk\'s own addresses, taken and invalid addresses are refused', async ({ page }) => {
  const name = `Mailcheck ${Date.now()}`;
  await staffLogin(page, 'admin');
  await createAgent(page, name, SYSTEM);
  await expect(page.locator('#msg_notice')).toContainText('osTicket refused the email address');
  expect(Number(sql(`SELECT COUNT(*) FROM ost_staff WHERE firstname='Mailcheck' AND lastname='${name.split(' ')[1]}'`))).toBe(0);

  await createAgent(page, name, `alerts${RUN}@helpdesk-example.de`);
  await expect(page.locator('#msg_notice')).toContainText('AI agent created');
  const id = Number(sql('SELECT MAX(staff_id) FROM ost_ai_actions_agent'));
  expect(sql(`SELECT email FROM ost_staff WHERE staff_id=${id}`)).toBe(`alerts${RUN}@helpdesk-example.de`);

  const set = (email) => php(`echo AiActionsAgents::setEmail(${id}, ${JSON.stringify(email)}) ?: 'ok';`);
  expect(set(SYSTEM)).toBe('email_system');
  expect(set(TAKEN)).toBe('email_taken');
  expect(set('not an address')).toBe('email_bad');
  expect(php(`echo AiActionsAgents::setEmail(${seed.agents.admin}, 'x@helpdesk-example.de') ?: 'ok';`)).toBe('email_bad');
  expect(sql(`SELECT email FROM ost_staff WHERE staff_id=${id}`)).toBe(`alerts${RUN}@helpdesk-example.de`);
  expect(set(`alerts${RUN}@helpdesk-example.de`)).toBe('ok');

  await page.goto('/scp/ajax.php/ai/admin?tab=agents');
  await row(page, name).locator('input[name="email"]').fill(SYSTEM);
  await row(page, name).locator('form:has(input[name="email"]) input[type="submit"]').click();
  await expect(page.locator('#msg_notice')).toContainText('one of the help desk\'s own addresses');
});
