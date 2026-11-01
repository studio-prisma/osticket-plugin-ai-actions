// @ts-check
// Slice 5: internal note, reply draft, sub-ticket, with the offline fake.
const { test, expect } = require('@playwright/test');
const { requireSeed, watchErrors, staffLogin, openTicket, sql } = require('./support');

const seed = requireSeed();
const T1 = seed.tickets.T1;

// "Expanded Access" as installed; one test takes "ticket.reply" away.
const EXPANDED = sql("SELECT permissions FROM ost_role WHERE name='Expanded Access'");
function restoreRole() {
  sql(`UPDATE ost_role SET permissions='${EXPANDED.replace(/'/g, "''")}' WHERE name='Expanded Access'`);
}

async function openChat(page) {
  if (!(await page.locator('#spa-ai').isVisible())) await page.locator('#spa-ai-launcher').click();
  return page.locator('#spa-ai');
}

async function plan(page, text) {
  const chat = await openChat(page);
  const before = await chat.locator('.spa-ai-msg-ai').count();
  await chat.locator('.spa-ai-input').fill(text);
  await chat.locator('.spa-ai-input').press('Enter');
  await expect(chat.locator('.spa-ai-msg-ai:not(.spa-ai-busy)')).toHaveCount(before + 1);
  return chat.locator('.spa-ai-msg-ai').last().locator('.spa-ai-plan');
}

test.beforeEach(() => {
  restoreRole();
  sql(`UPDATE ost_ticket SET status_id=${seed.status.open}, closed=NULL WHERE ticket_id=${T1}`);
  sql("DELETE FROM ost_draft WHERE namespace LIKE 'ticket.response.%'");
  sql('DELETE FROM ost_ai_actions_chat');
});

test.afterAll(() => restoreRole());

test('internal note: full text in the preview, posted after run, no undo for it', async ({ page }) => {
  const errors = watchErrors(page);
  await staffLogin(page, 'admin');
  await openTicket(page, 'T1');
  const card = await plan(page, 'Add a note that the customer called');
  await expect(card.locator('li .spa-ai-plan-label')).toHaveText(['Internal note']);
  await expect(card.locator('.spa-ai-plan-value')).toHaveText('Fake note: customer called back.');
  await card.locator('.spa-ai-run').click();
  await expect(card.locator('.spa-ai-plan-state')).toHaveText('Done');
  await expect(card.locator('.spa-ai-undo')).toHaveCount(0);
  await expect(page.locator('#ticketThread')).toContainText('Fake note: customer called back.', { timeout: 10000 });
  expect(errors).toEqual([]);
});

test('reply draft: lands in the agent\'s reply field as text, never sent, never overwritten', async ({ page }) => {
  await staffLogin(page, 'admin');
  await openTicket(page, 'T1');
  const responsesBefore = sql(`SELECT COUNT(*) FROM ost_thread_entry e JOIN ost_thread t ON t.id=e.thread_id
    WHERE t.object_type='T' AND t.object_id=${T1} AND e.type='R'`);

  let card = await plan(page, 'Prepare a reply draft');
  await card.locator('.spa-ai-run').click();
  await expect(card.locator('.spa-ai-plan-state')).toHaveText('Done');

  const body = sql(`SELECT body FROM ost_draft WHERE namespace='ticket.response.${T1}' AND staff_id=${seed.agents.admin}`);
  expect(body).toContain('&lt;b&gt;We are on it.&lt;/b&gt;');
  expect(body).not.toContain('<b>');
  // Nothing went to the customer.
  expect(sql(`SELECT COUNT(*) FROM ost_thread_entry e JOIN ost_thread t ON t.id=e.thread_id
    WHERE t.object_type='T' AND t.object_id=${T1} AND e.type='R'`)).toBe(responsesBefore);

  // The ticket view loads it into the reply editor.
  await page.reload();
  await expect(page.locator('#reply')).toContainText('<b>We are on it.</b>');

  // A second draft would replace the agent's own unsent work: refused.
  card = await plan(page, 'Prepare a reply draft');
  await card.locator('.spa-ai-run').click();
  await expect(card.locator('.spa-ai-fail')).toContainText('a reply draft already exists');
});

test('sub-ticket: same customer and department, linked by ticket_pid', async ({ page }) => {
  await staffLogin(page, 'admin');
  await openTicket(page, 'T1');
  const before = Number(sql(`SELECT COUNT(*) FROM ost_ticket WHERE ticket_pid=${T1}`));
  const card = await plan(page, 'Create a sub-ticket for the design part');
  await expect(card.locator('.spa-ai-plan-value')).toHaveText('Fake sub-ticket\nSplit off from the parent.');
  await card.locator('.spa-ai-run').click();
  await expect(card.locator('.spa-ai-plan-state')).toHaveText('Done');
  await expect(card.locator('.spa-ai-plan-value')).toContainText('→ #');
  await expect(card.locator('.spa-ai-undo')).toHaveCount(0);

  expect(Number(sql(`SELECT COUNT(*) FROM ost_ticket WHERE ticket_pid=${T1}`))).toBe(before + 1);
  const [user, dept] = sql(`SELECT c.user_id, c.dept_id FROM ost_ticket c WHERE c.ticket_pid=${T1} ORDER BY c.ticket_id DESC LIMIT 1`).split('\t');
  const [puser, pdept] = sql(`SELECT user_id, dept_id FROM ost_ticket WHERE ticket_id=${T1}`).split('\t');
  expect([user, dept]).toEqual([puser, pdept]);
});

test('a draft needs the reply permission', async ({ page }) => {
  const without = JSON.parse(EXPANDED);
  delete without['ticket.reply'];
  sql(`UPDATE ost_role SET permissions='${JSON.stringify(without)}' WHERE name='Expanded Access'`);
  await staffLogin(page, 'philipp');
  await openTicket(page, 'T1');
  const card = await plan(page, 'Prepare a reply draft');
  await card.locator('.spa-ai-run').click();
  await expect(card.locator('.spa-ai-fail')).toContainText('permission denied');
  expect(sql(`SELECT COUNT(*) FROM ost_draft WHERE namespace='ticket.response.${T1}'`)).toBe('0');
});
