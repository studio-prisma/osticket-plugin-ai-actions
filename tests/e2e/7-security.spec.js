// @ts-check
// Slice 8: attacks against the endpoints, run against the live test stack.
// Each test is one proof of concept; a pass means the attack failed.
const { test, expect } = require('@playwright/test');
const { requireSeed, staffLogin, openTicket, aiPost, sql } = require('./support');

const seed = requireSeed();
const T1 = seed.tickets.T1;
const POSTS = ['/plan', '/run', '/undo', '/discard', '/reset'];

test.beforeEach(() => {
  sql('DELETE FROM ost_ai_actions_chat');
  sql('DELETE FROM ost_ai_actions_usage');
  sql(`UPDATE ost_ticket SET duedate=NULL WHERE ticket_id=${T1}`);
  sql(`UPDATE ost_staff SET change_passwd=0 WHERE staff_id=${seed.agents.philipp}`);
});

test.afterAll(() => {
  sql(`UPDATE ost_staff SET change_passwd=0 WHERE staff_id=${seed.agents.philipp}`);
});

test('CSRF: every POST endpoint refuses a missing or wrong token', async ({ page }) => {
  await staffLogin(page, 'admin');
  await openTicket(page, 'T1');
  const runsBefore = sql('SELECT COUNT(*) FROM ost_ai_actions_run');
  for (const path of POSTS) {
    for (const token of [undefined, 'f'.repeat(40)]) {
      const form = { ticket_id: String(T1), prompt: 'due tomorrow', plan_id: '0'.repeat(32) };
      if (token) form.__CSRFToken__ = token;
      const res = await page.request.post(`/scp/ajax.php/ai${path}`, { form, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      expect(res.status(), `${path} token=${token ? 'wrong' : 'none'}`).toBe(400);
    }
  }
  expect(sql('SELECT COUNT(*) FROM ost_ai_actions_run')).toBe(runsBefore);
  expect(sql('SELECT COUNT(*) FROM ost_ai_actions_usage')).toBe('0');
});

test('no session: nothing answers', async ({ request }) => {
  for (const path of POSTS) {
    const res = await request.post(`/scp/ajax.php/ai${path}`, { form: { ticket_id: String(T1), prompt: 'x' } });
    expect(res.status(), path).not.toBe(200);
  }
  const chat = await request.get(`/scp/ajax.php/ai/chat?ticket_id=${T1}`);
  expect(chat.status()).not.toBe(200);
  expect(await chat.text()).not.toContain('transcript');
});

test('race: two clicks on Run for the same plan run it once', async ({ page }) => {
  await staffLogin(page, 'admin');
  await openTicket(page, 'T1');
  const plan = await aiPost(page, '/plan', { ticket_id: String(T1), prompt: 'due tomorrow' });
  const id = plan.json.transcript.at(-1).plan.id;
  const lastEntry = sql('SELECT IFNULL(MAX(id), 0) FROM ost_thread_entry');
  const [a, b] = await Promise.all([aiPost(page, '/run', { plan_id: id }), aiPost(page, '/run', { plan_id: id })]);
  expect([a.status, b.status].sort()).toEqual([200, 422]);
  expect(sql(`SELECT COUNT(*) FROM ost_thread_entry e JOIN ost_thread t ON t.id=e.thread_id
    WHERE t.object_type='T' AND t.object_id=${T1} AND e.title='AI action' AND e.id > ${lastEntry}`)).toBe('1');
});

test('race: two clicks on Undo undo once', async ({ page }) => {
  await staffLogin(page, 'admin');
  await openTicket(page, 'T1');
  const plan = await aiPost(page, '/plan', { ticket_id: String(T1), prompt: 'due tomorrow' });
  const id = plan.json.transcript.at(-1).plan.id;
  expect((await aiPost(page, '/run', { plan_id: id })).status).toBe(200);
  const [a, b] = await Promise.all([aiPost(page, '/undo', { plan_id: id }), aiPost(page, '/undo', { plan_id: id })]);
  expect([a.status, b.status].sort()).toEqual([200, 422]);
});

test('tampered parameters: arrays, junk and other people\'s IDs get nowhere', async ({ page }) => {
  await staffLogin(page, 'philipp');
  await openTicket(page, 'T1');
  const token = await page.locator('meta[name="csrf_token"]').getAttribute('content');
  const raw = async (path, body) => (await page.request.post(`/scp/ajax.php/ai${path}`, {
    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
    data: `__CSRFToken__=${token}&${body}`,
  })).status();

  expect(await raw('/plan', `ticket_id[]=${T1}&prompt=x`)).toBe(404);
  expect(await raw('/plan', `ticket_id=${seed.tickets.S1}&prompt=x`)).toBe(404);   // Sales: not his department
  expect(await raw('/plan', `ticket_id=${T1}&prompt[]=x`)).toBe(422);
  expect(await raw('/plan', `ticket_id=${T1}&prompt=${'a'.repeat(2001)}`)).toBe(422);
  expect(await raw('/run', 'plan_id[]=x')).toBe(422);
  expect(await raw('/undo', `plan_id=${encodeURIComponent("' OR '1'='1")}`)).toBe(422);
  expect(await raw('/reset', `ticket_id=${seed.tickets.S1}`)).toBe(404);
});

test('conversations are private: another agent sees only their own', async ({ page, browser }) => {
  await staffLogin(page, 'admin');
  await openTicket(page, 'T1');
  await aiPost(page, '/plan', { ticket_id: String(T1), prompt: 'SECRET-MARKER what is this?' });

  const other = await browser.newPage();
  await staffLogin(other, 'philipp');
  const res = await other.request.get(`/scp/ajax.php/ai/chat?ticket_id=${T1}`);
  expect(res.status()).toBe(200);
  expect(await res.text()).not.toContain('SECRET-MARKER');
  // "New" of the other agent leaves the admin's conversation alone.
  await openTicket(other, 'T1');
  expect((await aiPost(other, '/reset', { ticket_id: String(T1) })).status).toBe(200);
  await other.close();
  expect(sql(`SELECT COUNT(*) FROM ost_ai_actions_chat WHERE staff_id=${seed.agents.admin} AND ticket_id=${T1}`)).toBe('1');
});

test('pending password change: no bubble, and the endpoints act as if they did not exist', async ({ page }) => {
  await staffLogin(page, 'philipp');
  await openTicket(page, 'T1');
  const token = await page.locator('meta[name="csrf_token"]').getAttribute('content');
  sql(`UPDATE ost_staff SET change_passwd=1 WHERE staff_id=${seed.agents.philipp}`);
  const res = await page.request.post('/scp/ajax.php/ai/plan', {
    form: { __CSRFToken__: token, ticket_id: String(T1), prompt: 'due tomorrow' },
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(res.status()).toBe(404);
  expect((await page.request.get(`/scp/ajax.php/ai/chat?ticket_id=${T1}`)).status()).toBe(404);
});

test('responses: JSON with nosniff; assets only by fixed name', async ({ page }) => {
  await staffLogin(page, 'admin');
  const chat = await page.request.get(`/scp/ajax.php/ai/chat?ticket_id=${T1}`);
  expect(chat.headers()['content-type']).toContain('application/json');
  expect(chat.headers()['x-content-type-options']).toBe('nosniff');
  expect(chat.headers()['cache-control']).toContain('no-store');

  for (const name of ['..%2f..%2fconfig.php', '..%2F..%2Fplugin.php', 'ai-actions.php', '.htaccess', 'AI-ACTIONS.JS', 'ai-actions.js%00.css']) {
    expect((await page.request.get(`/scp/ajax.php/ai/assets/${name}`)).status(), name).not.toBe(200);
  }
  const js = await page.request.get('/scp/ajax.php/ai/assets/ai-actions.js');
  expect(js.status()).toBe(200);
  expect(js.headers()['x-content-type-options']).toBe('nosniff');
});

test('the browser never gets the stored model history or old values', async ({ page }) => {
  await staffLogin(page, 'admin');
  await openTicket(page, 'T1');
  const plan = await aiPost(page, '/plan', { ticket_id: String(T1), prompt: 'due tomorrow' });
  const run = await aiPost(page, '/run', { plan_id: plan.json.transcript.at(-1).plan.id });
  const chat = await (await page.request.get(`/scp/ajax.php/ai/chat?ticket_id=${T1}`)).text();
  for (const body of [JSON.stringify(plan.json), JSON.stringify(run.json), chat]) {
    expect(body).not.toContain('previous');
    expect(body).not.toContain('tool_use');
    expect(body).not.toContain('<ticket_data>');
  }
});
