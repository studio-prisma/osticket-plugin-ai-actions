// @ts-check
// Slice 4: undo right after a run (AC-7), with the offline fake model.
const { test, expect } = require('@playwright/test');
const { requireSeed, watchErrors, staffLogin, openTicket, aiPost, sql } = require('./support');

const seed = requireSeed();
const T1 = seed.tickets.T1;
const T2 = seed.tickets.T2;

function ticketRow(id) {
  const [due, staff, status, dept] = sql(
    `SELECT IFNULL(t.duedate,'NULL'), t.staff_id, s.name, d.name FROM ost_ticket t
     JOIN ost_ticket_status s ON s.id=t.status_id JOIN ost_department d ON d.id=t.dept_id WHERE t.ticket_id=${id}`).split('\t');
  const prio = sql(`SELECT p.priority FROM ost_ticket__cdata c JOIN ost_ticket_priority p ON p.priority_id=c.priority WHERE c.ticket_id=${id}`);
  return { due, staff: Number(staff), status, dept, prio };
}

async function openChat(page) {
  if (!(await page.locator('#spa-ai').isVisible())) await page.locator('#spa-ai-launcher').click();
  return page.locator('#spa-ai');
}

async function planAndRun(page, text) {
  const chat = await openChat(page);
  const before = await chat.locator('.spa-ai-msg-ai').count();
  await chat.locator('.spa-ai-input').fill(text);
  await chat.locator('.spa-ai-input').press('Enter');
  await expect(chat.locator('.spa-ai-msg-ai:not(.spa-ai-busy)')).toHaveCount(before + 1);
  const card = chat.locator('.spa-ai-msg-ai').last().locator('.spa-ai-plan');
  await card.locator('.spa-ai-run').click();
  await expect(card.locator('.spa-ai-plan-state')).toHaveText('Done');
  return card;
}

test.beforeEach(() => {
  const support = sql("SELECT id FROM ost_department WHERE name='Support'");
  const normal = sql("SELECT priority_id FROM ost_ticket_priority WHERE priority='normal'");
  sql(`UPDATE ost_ticket SET duedate=NULL, staff_id=0, team_id=0, status_id=${seed.status.open}, closed=NULL,
       dept_id=${support} WHERE ticket_id IN (${T1}, ${T2})`);
  sql(`UPDATE ost_ticket__cdata SET priority=${normal} WHERE ticket_id IN (${T1}, ${T2})`);
  sql(`UPDATE ost_form_entry_values v JOIN ost_form_entry e ON e.id=v.entry_id JOIN ost_form_field f ON f.id=v.field_id
       SET v.value='Normal', v.value_id=${normal} WHERE e.object_type='T' AND e.object_id IN (${T1}, ${T2}) AND f.name='priority'`);
  sql('DELETE FROM ost_ai_actions_chat');
});

test('AC-7: undo puts every field back, in reverse order, and says so in the thread', async ({ page }) => {
  const errors = watchErrors(page);
  await staffLogin(page, 'admin');
  await openTicket(page, 'T1');
  const before = ticketRow(T1);

  const card = await planAndRun(page, 'Close it, due tomorrow, priority high and assign it to Philipp');
  const after = ticketRow(T1);
  expect(after).toMatchObject({ status: 'Closed', staff: seed.agents.philipp, prio: 'high' });
  expect(after.due).not.toBe('NULL');

  // No countdown: a plain button while undo is possible.
  await expect(card.locator('.spa-ai-undo')).toHaveText('Undo');
  await expect(card.locator('.spa-ai-undo')).toBeEnabled();
  await card.locator('.spa-ai-undo').click();
  await expect(card.locator('.spa-ai-plan-state')).toHaveText('Undone');
  await expect(card.locator('.spa-ai-plan-undo li.spa-ai-ok')).toHaveCount(4);
  await expect(card.locator('.spa-ai-undo')).toHaveCount(0);

  expect(ticketRow(T1)).toEqual(before);
  await expect(page.locator('#ticketThread')).toContainText('AI action undone', { timeout: 10000 });
  expect(errors).toEqual([]);
});

test('undo of a transfer brings the ticket back to its department', async ({ page }) => {
  await staffLogin(page, 'admin');
  await openTicket(page, 'T2');
  const card = await planAndRun(page, 'move it to sales');
  expect(ticketRow(T2).dept).toBe('Sales');
  await card.locator('.spa-ai-undo').click();
  await expect(card.locator('.spa-ai-plan-state')).toHaveText('Undone');
  expect(ticketRow(T2).dept).toBe('Support');
});

test('undo works once, only for its agent, only inside the window', async ({ page, browser }) => {
  await staffLogin(page, 'admin');
  await openTicket(page, 'T1');
  await planAndRun(page, 'due tomorrow');
  const id = sql(`SELECT id FROM ost_ai_actions_run WHERE ticket_id=${T1} AND state='done' ORDER BY ran DESC LIMIT 1`);

  // Another agent can't undo it.
  const other = await browser.newPage();
  await staffLogin(other, 'philipp');
  await openTicket(other, 'T1');
  expect(await aiPost(other, '/undo', { plan_id: id })).toEqual({ status: 422, json: { error: 'undo_expired' } });
  await other.close();

  // After the window: no button after a reload, and the server refuses.
  sql(`UPDATE ost_ai_actions_run SET ran = NOW() - INTERVAL 31 SECOND WHERE id='${id}'`);
  expect(await aiPost(page, '/undo', { plan_id: id })).toEqual({ status: 422, json: { error: 'undo_expired' } });
  expect(ticketRow(T1).due).not.toBe('NULL');

  // Inside the window: once.
  sql(`UPDATE ost_ai_actions_run SET ran = NOW() WHERE id='${id}'`);
  expect((await aiPost(page, '/undo', { plan_id: id })).status).toBe(200);
  expect(await aiPost(page, '/undo', { plan_id: id })).toEqual({ status: 422, json: { error: 'undo_expired' } });
  expect(ticketRow(T1).due).toBe('NULL');

  // Undone: the button is gone.
  await page.reload();
  await expect(page.locator('#spa-ai .spa-ai-undo')).toHaveCount(0);
});

test('after the window the undo button stays, greyed out', async ({ page }) => {
  await staffLogin(page, 'admin');
  await openTicket(page, 'T1');
  await planAndRun(page, 'due tomorrow');
  // Moves the stored window into the past, as if 30 seconds had gone by.
  sql(`UPDATE ost_ai_actions_chat SET transcript = REGEXP_REPLACE(transcript, '"undo_until":[0-9]+', '"undo_until":1')`);
  await page.reload();
  const button = page.locator('#spa-ai .spa-ai-plan-done .spa-ai-undo');
  await expect(button).toHaveText('Undo');
  await expect(button).toBeDisabled();
  await expect(button).toHaveAttribute('title', /30 seconds/);
});
