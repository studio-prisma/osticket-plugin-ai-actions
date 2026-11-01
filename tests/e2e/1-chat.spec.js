// @ts-check
// The chat bubble in the ticket view, with the offline fake model
// (SPA_AI_FAKE=1 in the test stack).
const { test, expect } = require('@playwright/test');
const { requireSeed, watchErrors, staffLogin, openTicket, aiPost, sql } = require('./support');

const seed = requireSeed();
const T1 = seed.tickets.T1;
const T2 = seed.tickets.T2;

function dueOf(id) {
  return sql(`SELECT IFNULL(duedate, 'NULL') FROM ost_ticket WHERE ticket_id=${id}`);
}

function tomorrow() {
  // The stack runs in UTC and the seeded agents have no own time zone.
  return new Date(Date.now() + 24 * 3600 * 1000).toISOString().slice(0, 10);
}

async function openChat(page) {
  await page.locator('#spa-ai-launcher').click();
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

test.beforeEach(() => {
  sql(`UPDATE ost_ticket SET duedate=NULL WHERE ticket_id IN (${T1}, ${T2})`);
  sql('DELETE FROM ost_ai_actions_chat');
});

test('AC-1: message -> plan card -> run sets the due date and logs a note', async ({ page }) => {
  const errors = watchErrors(page);
  await staffLogin(page, 'admin');
  await openTicket(page, 'T1');
  const chat = await openChat(page);
  await expect(chat.locator('.spa-ai-subtitle')).toContainText(`#${seed.numbers.T1}`);

  const reply = await say(chat, 'Set the due date to tomorrow');
  await expect(chat.locator('.spa-ai-msg-me')).toContainText('Set the due date to tomorrow');
  const card = reply.locator('.spa-ai-plan');
  await expect(card.locator('li')).toHaveCount(1);
  await expect(card).toContainText(`Due date${tomorrow()} 17:00`);
  expect(dueOf(T1)).toBe('NULL');

  await card.locator('.spa-ai-run').click();
  await expect(chat.locator('.spa-ai-plan .spa-ai-ok')).toHaveCount(1);
  await expect(chat.locator('.spa-ai-plan-state')).toHaveText('Done');
  expect(dueOf(T1)).toBe(`${tomorrow()} 17:00:00`);

  // The ticket view reloads behind the window; the window stays.
  await expect(page.locator('#ticketThread')).toContainText('Request: Set the due date to tomorrow', { timeout: 10000 });
  await expect(chat).toBeVisible();
  expect(errors).toEqual([]);
});

test('AC-4 (fake): a question gets an answer and changes nothing', async ({ page }) => {
  await staffLogin(page, 'admin');
  await openTicket(page, 'T1');
  const chat = await openChat(page);
  const reply = await say(chat, 'What is this ticket about?');
  await expect(reply.locator('.spa-ai-bubble')).not.toBeEmpty();
  await expect(chat.locator('.spa-ai-plan')).toHaveCount(0);
  expect(dueOf(T1)).toBe('NULL');
});

test('AC-10 (mechanics): the model learns what happened to its plan', async ({ page }) => {
  await staffLogin(page, 'admin');
  await openTicket(page, 'T1');
  const chat = await openChat(page);

  await (await say(chat, 'due tomorrow')).locator('.spa-ai-run').click();
  await expect(chat.locator('.spa-ai-plan-state')).toHaveText('Done');
  await expect(await say(chat, 'what happened to the previous plan?')).toContainText('Previous plan: confirmed.');

  await (await say(chat, 'due tomorrow')).locator('.spa-ai-discard').click();
  await expect(chat.locator('.spa-ai-plan-state').last()).toHaveText('Discarded');
  await expect(await say(chat, 'what happened to the previous plan?')).toContainText('Previous plan: discarded.');
});

test('a new message replaces an open plan, which then cannot run', async ({ page }) => {
  await staffLogin(page, 'admin');
  await openTicket(page, 'T1');
  const chat = await openChat(page);
  await say(chat, 'due tomorrow');
  const planId = sql(`SELECT id FROM ost_ai_actions_run WHERE ticket_id=${T1} AND state='planned' ORDER BY created DESC LIMIT 1`);
  await expect(await say(chat, 'what happened to the previous plan?')).toContainText('Previous plan: wrote a new message.');
  await expect(chat.locator('.spa-ai-plan-state')).toHaveText('Not run (new message)');
  expect((await aiPost(page, '/run', { plan_id: planId })).json).toEqual({ error: 'expired' });
  expect(dueOf(T1)).toBe('NULL');
});

test('AC-11: the conversation belongs to agent and ticket and survives a reload', async ({ page, browser }) => {
  await staffLogin(page, 'admin');
  await openTicket(page, 'T1');
  let chat = await openChat(page);
  await say(chat, 'What is this ticket about?');

  // Same ticket after a full reload: the window reopens with the conversation.
  await page.reload();
  chat = page.locator('#spa-ai');
  await expect(chat).toBeVisible();
  await expect(chat.locator('.spa-ai-msg-me')).toHaveText('What is this ticket about?');

  // Another ticket: empty.
  await openTicket(page, 'T2');
  await expect(chat.locator('.spa-ai-subtitle')).toContainText(`#${seed.numbers.T2}`);
  await expect(chat.locator('.spa-ai-msg')).toHaveCount(0);

  // Another agent on the same ticket: empty.
  const other = await browser.newPage();
  await staffLogin(other, 'philipp');
  await openTicket(other, 'T1');
  const otherChat = await openChat(other);
  await expect(otherChat.locator('.spa-ai-hint')).toBeVisible();
  await expect(otherChat.locator('.spa-ai-msg')).toHaveCount(0);
  await other.close();

  // "New" starts over.
  await openTicket(page, 'T1');
  await expect(chat.locator('.spa-ai-msg')).toHaveCount(2);
  await chat.locator('.spa-ai-new').click();
  await expect(chat.locator('.spa-ai-msg')).toHaveCount(0);
});

test('the chat follows PJAX navigation between tickets', async ({ page }) => {
  await staffLogin(page, 'admin');
  await page.goto('/scp/tickets.php');
  const chat = await openChat(page);
  await expect(chat.locator('.spa-ai-hint')).toBeVisible();
  await page.locator(`a[href$="tickets.php?id=${T1}"]`).first().click();
  await expect(page.locator('#ticketThread')).toBeVisible();
  await expect(chat.locator('.spa-ai-subtitle')).toContainText(`#${seed.numbers.T1}`);
  await expect(page.locator('#spa-ai-launcher')).toHaveCount(1);
});

test('messages are text, never markup', async ({ page }) => {
  await staffLogin(page, 'admin');
  await openTicket(page, 'T1');
  const chat = await openChat(page);
  await say(chat, '<img src=x onerror="window.__spaXss=1"> what is this?');
  await expect(chat.locator('img')).toHaveCount(0);
  expect(await page.evaluate(() => window.__spaXss)).toBeUndefined();
});

test('4-byte characters survive storage (connection charset may be 3-byte utf8)', async ({ page }) => {
  await staffLogin(page, 'admin');
  await openTicket(page, 'T1');
  const chat = await openChat(page);
  await say(chat, 'Kunde schreibt 🙂 – was nun?');
  await page.reload();
  await expect(page.locator('#spa-ai .spa-ai-msg-me')).toHaveText('Kunde schreibt 🙂 – was nun?');
});

test('AC-6: a plan runs once, only for its agent, only while valid', async ({ page, browser }) => {
  await staffLogin(page, 'admin');
  await openTicket(page, 'T1');

  const plan = async () => {
    const r = await aiPost(page, '/plan', { ticket_id: String(T1), prompt: 'due tomorrow' });
    expect(r.status).toBe(200);
    const id = r.json.transcript.at(-1).plan.id;
    expect(id).toMatch(/^[a-f0-9]{32}$/);
    return id;
  };

  let id = await plan();
  expect((await aiPost(page, '/run', { plan_id: id })).status).toBe(200);
  expect(await aiPost(page, '/run', { plan_id: id })).toEqual({ status: 422, json: { error: 'expired' } });

  id = await plan();
  const other = await browser.newPage();
  await staffLogin(other, 'philipp');
  await openTicket(other, 'T1');
  expect((await aiPost(other, '/run', { plan_id: id })).json).toEqual({ error: 'expired' });
  expect((await aiPost(other, '/discard', { plan_id: id })).json).toEqual({ error: 'expired' });
  await other.close();

  sql(`UPDATE ost_ai_actions_run SET expires = NOW() - INTERVAL 1 SECOND WHERE id='${id}'`);
  expect((await aiPost(page, '/run', { plan_id: id })).json).toEqual({ error: 'expired' });

  for (const bogus of ['0'.repeat(32), "' OR 1=1 -- ", '']) {
    expect(await aiPost(page, '/run', { plan_id: bogus })).toEqual({ status: 422, json: { error: 'expired' } });
  }
});

test('AC-9: an agent without an allowed role gets no bubble and 404s', async ({ page }) => {
  await staffLogin(page, 'salesagent');
  await openTicket(page, 'S1');
  await expect(page.locator('#spa-ai-launcher')).toHaveCount(0);
  await expect(page.locator('#spa-ai-config')).toHaveCount(0);
  expect((await aiPost(page, '/plan', { ticket_id: String(seed.tickets.S1), prompt: 'due tomorrow' })).status).toBe(404);
  const res = await page.request.get(`/scp/ajax.php/ai/chat?ticket_id=${seed.tickets.S1}`);
  expect(res.status()).toBe(404);
});

test('tickets outside the agent\'s departments are 404s', async ({ page }) => {
  await staffLogin(page, 'philipp');
  await openTicket(page, 'T1');
  expect(await aiPost(page, '/plan', { ticket_id: String(seed.tickets.S1), prompt: 'due tomorrow' }))
    .toEqual({ status: 404, json: { error: 'not_found' } });
  expect((await page.request.get(`/scp/ajax.php/ai/chat?ticket_id=${seed.tickets.S1}`)).status()).toBe(404);
});
