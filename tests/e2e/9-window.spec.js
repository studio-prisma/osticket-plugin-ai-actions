// @ts-check
// 0.2.0: due dates in the agent's time zone, earlier conversations, sub-tickets
// in the context, name/icon/colour from the settings, large view, resizing
// and the phone layout.
const { test, expect } = require('@playwright/test');
const { seed, requireSeed, staffLogin, openTicket, sql, php } = require('./support');

requireSeed();
const T1 = seed.tickets.T1;
const T2 = seed.tickets.T2;
const NS = `plugin.${seed.plugin.id}.instance.${seed.plugin.instance}`;
const LOOK = ['assistant_name', 'assistant_icon', 'assistant_image', 'accent', 'welcome'];

async function openChat(page) {
  if (!(await page.locator('#spa-ai').isVisible())) await page.locator('#spa-ai-launcher').click();
  await expect(page.locator('#spa-ai')).toBeVisible();
  return page.locator('#spa-ai');
}

async function say(chat, text) {
  const before = await chat.locator('.spa-ai-msg-ai').count();
  await chat.locator('.spa-ai-input').fill(text);
  await chat.locator('.spa-ai-input').press('Enter');
  await expect(chat.locator('.spa-ai-msg-ai:not(.spa-ai-busy)')).toHaveCount(before + 1);
  return chat.locator('.spa-ai-msg-ai').last();
}

function setting(key, value) {
  sql(`DELETE FROM ost_config WHERE namespace='${NS}' AND \`key\`='${key}'`);
  if (value !== null) {
    sql(`INSERT INTO ost_config (namespace, \`key\`, value, updated) VALUES ('${NS}', '${key}', '${value}', NOW())`);
  }
}

test.beforeEach(() => {
  sql('DELETE FROM ost_ai_actions_chat');
  sql('DELETE FROM ost_ai_actions_chat_archive');
  sql('DELETE FROM ost_ai_actions_run');
  sql(`UPDATE ost_ticket SET duedate=NULL, ticket_pid=NULL WHERE ticket_id IN (${T1}, ${T2})`);
});

test.afterAll(() => {
  LOOK.forEach((k) => setting(k, null));
  sql(`UPDATE ost_staff SET timezone=NULL WHERE staff_id=${seed.agents.admin}`);
  sql(`UPDATE ost_ticket SET ticket_pid=NULL WHERE ticket_id IN (${T1}, ${T2})`);
});

test('due date: 17:00 for the agent stays 17:00, in winter and summer time', async ({ page }) => {
  sql(`UPDATE ost_staff SET timezone='Europe/Berlin' WHERE staff_id=${seed.agents.admin}`);
  await staffLogin(page, 'admin');
  await openTicket(page, 'T1');
  const chat = await openChat(page);
  const reply = await say(chat, 'due tomorrow');
  await expect(reply.locator('.spa-ai-plan-value')).toContainText('17:00');
  await reply.locator('.spa-ai-run').click();
  await expect(reply.locator('.spa-ai-plan-state')).toHaveText('Done');

  // What osTicket stored, read back in Berlin time with osTicket's own DB zone.
  const local = php(`$t = Ticket::lookup(${T1}); $d = new DateTime($t->getDueDate(), new DateTimeZone($cfg->getDbTimezone()));`
    + ` $d->setTimezone(new DateTimeZone('Europe/Berlin')); echo $d->format('H:i');`);
  expect(local).toBe('17:00');
});

test('earlier conversations: "New" keeps the old one readable, only for its agent', async ({ page, browser }) => {
  await staffLogin(page, 'admin');
  await openTicket(page, 'T1');
  const chat = await openChat(page);
  await say(chat, 'What is this ticket about?');
  await chat.locator('.spa-ai-new').click();
  await expect(chat.locator('.spa-ai-msg')).toHaveCount(0);

  await chat.locator('.spa-ai-history-btn').click();
  const item = chat.locator('.spa-ai-history-item');
  await expect(item).toHaveCount(1);
  await expect(item.locator('.spa-ai-history-title')).toHaveText('What is this ticket about?');
  await expect(item.locator('.spa-ai-history-meta')).toContainText('2 messages');
  await expect(chat.locator('.spa-ai-form')).toBeHidden();

  await item.click();
  await expect(chat.locator('.spa-ai-msg')).toHaveCount(2);
  await expect(chat.locator('.spa-ai-hint').first()).toContainText('read only');
  await expect(chat.locator('.spa-ai-run, .spa-ai-undo')).toHaveCount(0);

  await chat.locator('.spa-ai-back').click();
  await expect(chat.locator('.spa-ai-history-item')).toHaveCount(1);
  await chat.locator('.spa-ai-back').click();
  await expect(chat.locator('.spa-ai-form')).toBeVisible();

  const id = sql(`SELECT MAX(id) FROM ost_ai_actions_chat_archive WHERE staff_id=${seed.agents.admin}`);
  const other = await browser.newPage();
  await staffLogin(other, 'philipp');
  await openTicket(other, 'T1');
  const list = await other.request.get(`/scp/ajax.php/ai/history?ticket_id=${T1}`);
  expect((await list.json()).conversations).toEqual([]);
  const one = await other.request.get(`/scp/ajax.php/ai/history?ticket_id=${T1}&id=${id}`);
  expect(one.status()).toBe(404);
  await other.close();
});

test('an idle conversation moves to the archive instead of disappearing', async ({ page }) => {
  await staffLogin(page, 'admin');
  await openTicket(page, 'T1');
  const chat = await openChat(page);
  await say(chat, 'Idle question');
  sql('UPDATE ost_ai_actions_chat SET updated = NOW() - INTERVAL 13 HOUR');
  await page.reload();
  await expect(chat.locator('.spa-ai-msg')).toHaveCount(0);
  await chat.locator('.spa-ai-history-btn').click();
  await expect(chat.locator('.spa-ai-history-title').first()).toHaveText('Idle question');
});

test('context: the model sees sub-tickets and the parent, as one line each', async () => {
  sql(`UPDATE ost_ticket SET ticket_pid=${T1} WHERE ticket_id=${T2}`);
  const call = (id) => php(`echo AiActionsContext::data(Ticket::lookup(${id}), Staff::lookup(${seed.agents.admin}), 'Europe/Berlin')['text'];`);
  expect(call(T1)).toContain(`Sub-ticket: #${seed.numbers.T2}`);
  expect(call(T2)).toContain(`Parent ticket: #${seed.numbers.T1}`);
});

test('appearance: name, first-letter icon and accent colour from the settings', async ({ page }) => {
  setting('assistant_name', 'Prisma');
  setting('assistant_icon', 'initial');
  setting('accent', '#ff8800');
  setting('welcome', 'Hallo Team');
  await staffLogin(page, 'admin');
  await openTicket(page, 'T1');
  const launcher = page.locator('#spa-ai-launcher');
  await expect(launcher).toHaveAttribute('aria-label', 'Prisma');
  await expect(launcher.locator('.spa-ai-initial')).toHaveText('P');
  await expect(launcher).toHaveCSS('background-color', 'rgb(255, 136, 0)');
  const chat = await openChat(page);
  await expect(chat.locator('.spa-ai-title')).toHaveText('Prisma');
  await expect(chat.locator('.spa-ai-avatar-head .spa-ai-initial')).toHaveText('P');
  await expect(chat.locator('.spa-ai-welcome')).toHaveText('Hallo Team');
  await expect(chat.locator('.spa-ai-chip')).toHaveCount(3);
  LOOK.forEach((k) => setting(k, null));
});

test('large view, and a size dragged by the corner that survives a reload', async ({ page }) => {
  await page.setViewportSize({ width: 1400, height: 900 });
  await staffLogin(page, 'admin');
  await openTicket(page, 'T1');
  const chat = await openChat(page);
  await page.evaluate(() => localStorage.removeItem('spa-ai-size'));

  await chat.locator('.spa-ai-large-btn').click();
  await expect(chat).toHaveClass(/spa-ai-large/);
  expect((await chat.boundingBox()).width).toBeGreaterThan(900);
  await chat.locator('.spa-ai-input').press('Escape');
  await expect(chat).not.toHaveClass(/spa-ai-large/);
  await expect(chat).toBeVisible();

  const box = await chat.boundingBox();
  await page.mouse.move(box.x + 6, box.y + 6);
  await page.mouse.down();
  await page.mouse.move(box.x - 154, box.y - 94, { steps: 5 });
  await page.mouse.up();
  const bigger = await chat.boundingBox();
  expect(Math.round(bigger.width - box.width)).toBeGreaterThanOrEqual(150);
  expect(Math.round(bigger.height - box.height)).toBeGreaterThanOrEqual(90);

  await page.reload();
  const again = await openChat(page);
  expect(Math.abs((await again.boundingBox()).width - bigger.width)).toBeLessThan(2);
  await page.evaluate(() => localStorage.removeItem('spa-ai-size'));
});

test('phone: the window fills the screen and closes again', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await staffLogin(page, 'admin');
  await openTicket(page, 'T1');
  const chat = await openChat(page);
  const box = await chat.boundingBox();
  expect(box).toMatchObject({ x: 0, y: 0, width: 390, height: 844 });
  await expect(page.locator('#spa-ai-launcher')).toBeHidden();
  await expect(chat.locator('.spa-ai-large-btn')).toBeHidden();
  await expect(chat.locator('.spa-ai-input')).toHaveCSS('font-size', '16px');
  const reply = await say(chat, 'What is this ticket about?');
  await expect(reply).toBeVisible();
  await chat.locator('.spa-ai-close').click();
  await expect(chat).toBeHidden();
  await expect(page.locator('#spa-ai-launcher')).toBeVisible();
});

test('the history endpoint needs a visible ticket', async ({ page }) => {
  await staffLogin(page, 'salesagent');
  await page.goto('/scp/index.php');
  const res = await page.request.get(`/scp/ajax.php/ai/history?ticket_id=${T1}`);
  expect([404]).toContain(res.status());
});
