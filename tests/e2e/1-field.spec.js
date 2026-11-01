// @ts-check
// Slice 1: the field in the ticket view, plan -> preview -> run, with the
// offline fake model (SPA_AI_FAKE=1 in the test stack).
const { test, expect } = require('@playwright/test');
const { requireSeed, watchErrors, staffLogin, openTicket, aiPost, sql } = require('./support');

const seed = requireSeed();
const T1 = seed.tickets.T1;

function dueOf(id) {
  return sql(`SELECT IFNULL(duedate, 'NULL') FROM ost_ticket WHERE ticket_id=${id}`);
}

function tomorrow() {
  // The stack runs in UTC and the seeded agents have no own time zone.
  const d = new Date(Date.now() + 24 * 3600 * 1000);
  return d.toISOString().slice(0, 10);
}

test.beforeEach(() => {
  sql(`UPDATE ost_ticket SET duedate=NULL WHERE ticket_id=${T1}`);
});

test('AC-1: request -> preview -> run sets the due date and logs a note', async ({ page }) => {
  const errors = watchErrors(page);
  await staffLogin(page, 'admin');
  await openTicket(page, 'T1');

  const box = page.locator('#spa-ai');
  await expect(box).toBeVisible();
  await box.locator('textarea').fill('Set the due date to tomorrow');
  await box.locator('.spa-ai-plan').click();

  const changes = box.locator('.spa-ai-change');
  await expect(changes).toHaveCount(1);
  await expect(changes.first()).toContainText(`Due date: ${tomorrow()} 17:00`);
  // Nothing has changed before the agent confirms.
  expect(dueOf(T1)).toBe('NULL');

  await box.locator('.spa-ai-run').click();
  await expect(box.locator('.spa-ai-ok')).toHaveCount(1);
  expect(dueOf(T1)).toBe(`${tomorrow()} 17:00:00`);

  // The view reloads itself; the note in the thread keeps the record.
  await expect(page.locator('#ticketThread')).toContainText('AI action', { timeout: 10000 });
  await expect(page.locator('#ticketThread')).toContainText('Request: Set the due date to tomorrow');
  expect(errors).toEqual([]);
});

test('AC-4 (fake): a question gets an answer and changes nothing', async ({ page }) => {
  const errors = watchErrors(page);
  await staffLogin(page, 'admin');
  await openTicket(page, 'T1');
  const box = page.locator('#spa-ai');
  await box.locator('textarea').fill('What is this ticket about?');
  await box.locator('.spa-ai-plan').click();
  await expect(box.locator('.spa-ai-answer')).not.toBeEmpty();
  await expect(box.locator('.spa-ai-run')).toHaveCount(0);
  expect(dueOf(T1)).toBe('NULL');
  expect(errors).toEqual([]);
});

test('the field survives PJAX navigation into a ticket', async ({ page }) => {
  await staffLogin(page, 'admin');
  await page.goto('/scp/tickets.php');
  await page.locator(`a[href*="tickets.php?id=${T1}"]`).first().click();
  await expect(page.locator('#ticketThread')).toBeVisible();
  await expect(page.locator('#spa-ai')).toHaveCount(1);
});

test('AC-6: a plan runs once, only for its agent, only while valid', async ({ page, browser }) => {
  await staffLogin(page, 'admin');
  await openTicket(page, 'T1');

  const plan = async () => {
    const r = await aiPost(page, '/plan', { ticket_id: String(T1), prompt: 'due tomorrow' });
    expect(r.status).toBe(200);
    expect(r.json.plan_id).toMatch(/^[a-f0-9]{32}$/);
    return r.json.plan_id;
  };

  // Once.
  let id = await plan();
  expect((await aiPost(page, '/run', { plan_id: id })).status).toBe(200);
  let again = await aiPost(page, '/run', { plan_id: id });
  expect(again).toEqual({ status: 422, json: { error: 'expired' } });

  // Only for the agent who planned it.
  id = await plan();
  const other = await browser.newPage();
  await staffLogin(other, 'philipp');
  await openTicket(other, 'T1');
  expect((await aiPost(other, '/run', { plan_id: id })).json).toEqual({ error: 'expired' });
  await other.close();

  // Only while valid.
  sql(`UPDATE ost_ai_actions_run SET expires = NOW() - INTERVAL 1 SECOND WHERE id='${id}'`);
  expect((await aiPost(page, '/run', { plan_id: id })).json).toEqual({ error: 'expired' });

  // Made-up and malformed IDs look the same.
  for (const bogus of ['0'.repeat(32), "' OR 1=1 -- ", '']) {
    expect((await aiPost(page, '/run', { plan_id: bogus }))).toEqual({ status: 422, json: { error: 'expired' } });
  }
});

test('AC-9: an agent without an allowed role sees no field and gets 404', async ({ page }) => {
  await staffLogin(page, 'salesagent');
  await openTicket(page, 'S1');
  await expect(page.locator('#spa-ai')).toHaveCount(0);
  await expect(page.locator('#spa-ai-config')).toHaveCount(0);
  const r = await aiPost(page, '/plan', { ticket_id: String(seed.tickets.S1), prompt: 'due tomorrow' });
  expect(r.status).toBe(404);
});

test('a ticket outside the agent\'s departments is a 404', async ({ page }) => {
  await staffLogin(page, 'philipp');
  await openTicket(page, 'T1');
  const r = await aiPost(page, '/plan', { ticket_id: String(seed.tickets.S1), prompt: 'due tomorrow' });
  expect(r).toEqual({ status: 404, json: { error: 'not_found' } });
});
