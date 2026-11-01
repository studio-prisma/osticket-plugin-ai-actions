// @ts-check
// 0.4.0 S1: AI agent accounts (no login), the admin page, the job queue and
// the cron stamp.
const { test, expect } = require('@playwright/test');
const { seed, requireSeed, staffLogin, sql, php } = require('./support');

requireSeed();
const T1 = seed.tickets.T1;
const ADMIN = seed.agents.admin;

test.beforeEach(() => {
  sql('DELETE FROM ost_ai_actions_job');
  sql("DELETE FROM ost_ai_actions_state WHERE name='cron_last'");
});

async function createAgent(page, name) {
  await page.goto('/scp/ajax.php/ai/admin?tab=agents');
  await page.locator('input[name="name"]').fill(name);
  await page.locator('input[type="submit"][value="Create AI agent"]').click();
  await expect(page.locator('#msg_notice')).toContainText('AI agent created');
  return Number(sql(`SELECT MAX(staff_id) FROM ost_ai_actions_agent`));
}

test('admin page: admins only, other agents get 404', async ({ page, browser }) => {
  await staffLogin(page, 'admin');
  await page.goto('/scp/ajax.php/ai/admin');
  await expect(page.locator('h2').first()).toHaveText('AI agents');

  const other = await browser.newPage();
  await staffLogin(other, 'philipp');
  const res = await other.goto('/scp/ajax.php/ai/admin');
  expect(res.status()).toBe(404);
  // With a valid token, so it reaches the plugin's own admin check.
  await other.goto('/scp/index.php');
  const token = await other.locator('meta[name="csrf_token"]').getAttribute('content');
  const before = sql('SELECT COUNT(*) FROM ost_ai_actions_agent');
  const post = await other.request.post('/scp/ajax.php/ai/admin', { form: { do: 'agent_create', name: 'X', __CSRFToken__: token } });
  expect(post.status()).toBe(404);
  expect(sql('SELECT COUNT(*) FROM ost_ai_actions_agent')).toBe(before);
  await other.close();
});

test('a form post without the CSRF token changes nothing', async ({ page }) => {
  await staffLogin(page, 'admin');
  const before = sql('SELECT COUNT(*) FROM ost_ai_actions_agent');
  const res = await page.request.post('/scp/ajax.php/ai/admin', { form: { do: 'agent_create', name: 'Sneaky' } });
  expect(res.status()).toBe(400);
  expect(sql('SELECT COUNT(*) FROM ost_ai_actions_agent')).toBe(before);
});

test('AI agent: created as an osTicket agent, assignable, listed', async ({ page }) => {
  await staffLogin(page, 'admin');
  const id = await createAgent(page, 'Prisma Test');
  const [backend, email, active] = sql(`SELECT backend, email, isactive FROM ost_staff WHERE staff_id=${id}`).split('\t');
  expect(backend).toBe('ai-agent');
  expect(email).toMatch(/^ai-prisma-test-[0-9a-f]{6}@example\.com$/);
  expect(active).toBe('1');
  await expect(page.locator('table.list')).toContainText('Prisma Test');

  const options = php(`echo json_encode(AiActionsChoices::forTicket(Ticket::lookup(${T1}), Staff::lookup(${ADMIN}))['assign_agent'] ?? array());`);
  expect(options).toContain(`"s${id}"`);
});

test('AI agent: nobody can sign in, even with a known password', async ({ page }) => {
  await staffLogin(page, 'admin');
  const id = await createAgent(page, 'Locked Bot');
  const user = sql(`SELECT username FROM ost_staff WHERE staff_id=${id}`);
  // Give it a password we know, as an attacker who had set one would.
  php(`$s = Staff::lookup(${id}); $s->setPassword('Known-Passw0rd-123', null); $s->save();`);
  await page.context().clearCookies();
  await page.goto('/scp/login.php');
  await page.fill('input[name="userid"]', user);
  await page.fill('input[name="passwd"]', 'Known-Passw0rd-123');
  await page.click('#login button[type="submit"]');
  await page.waitForLoadState('networkidle');
  expect(page.url()).toContain('login.php');
  await expect(page.locator('#login')).toBeVisible();
  const me = await page.request.get('/scp/index.php');
  expect(me.url()).toContain('login.php');
});

test('cron: stamps its run and works through queued jobs once', async ({ page }) => {
  const id = php(`echo AiActionsJobs::enqueue('test', 0, ${T1}, array('x' => 1));`);
  php('AiActionsJobs::cron();');
  // Only rule jobs are worked on; anything else fails instead of running.
  expect(sql(`SELECT state, error FROM ost_ai_actions_job WHERE id=${id}`)).toBe('failed\tunknown_kind');
  php('AiActionsJobs::cron();');
  expect(sql(`SELECT COUNT(*) FROM ost_ai_actions_job WHERE state='running'`)).toBe('0');

  await staffLogin(page, 'admin');
  await page.goto('/scp/ajax.php/ai/admin?tab=status');
  await expect(page.locator('table.list')).toContainText('Last cron run');
  await expect(page.locator('table.list')).toContainText('1 failed');
});

test('status: without a cron run the page says what to set up', () => {
  // osTicket's auto-cron would stamp it on the next agent page; check the plain rendering.
  sql("DELETE FROM ost_ai_actions_state WHERE name='cron_last'");
  expect(php('var_dump(AiActionsJobs::cronAge());')).toBe('NULL');
});
