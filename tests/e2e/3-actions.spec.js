// @ts-check
// Slice 3: status, priority, assignment, transfer, with the offline fake
// model (it picks values from <ticket_options> like the real one has to).
const { test, expect } = require('@playwright/test');
const { requireSeed, watchErrors, staffLogin, openTicket, sql } = require('./support');

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

async function plan(page, text) {
  const chat = await openChat(page);
  const before = await chat.locator('.spa-ai-msg-ai').count();
  await chat.locator('.spa-ai-input').fill(text);
  await chat.locator('.spa-ai-input').press('Enter');
  await expect(chat.locator('.spa-ai-msg-ai:not(.spa-ai-busy)')).toHaveCount(before + 1);
  return chat.locator('.spa-ai-msg-ai').last().locator('.spa-ai-plan');
}

// "Expanded Access" as installed; AC-3 takes "ticket.close" away for one test.
const EXPANDED = sql("SELECT permissions FROM ost_role WHERE name='Expanded Access'");
function restoreRole() {
  sql(`UPDATE ost_role SET permissions='${EXPANDED.replace(/'/g, "''")}' WHERE name='Expanded Access'`);
}

test.beforeEach(() => {
  const support = sql("SELECT id FROM ost_department WHERE name='Support'");
  const normal = sql("SELECT priority_id FROM ost_ticket_priority WHERE priority='normal'");
  sql(`UPDATE ost_ticket SET duedate=NULL, staff_id=0, team_id=0, status_id=${seed.status.open}, closed=NULL,
       dept_id=${support} WHERE ticket_id IN (${T1}, ${T2})`);
  sql(`UPDATE ost_ticket__cdata SET priority=${normal} WHERE ticket_id IN (${T1}, ${T2})`);
  // The form answer is what osTicket compares against; cdata is only its cache.
  sql(`UPDATE ost_form_entry_values v JOIN ost_form_entry e ON e.id=v.entry_id JOIN ost_form_field f ON f.id=v.field_id
       SET v.value='Normal', v.value_id=${normal} WHERE e.object_type='T' AND e.object_id IN (${T1}, ${T2}) AND f.name='priority'`);
  restoreRole();
  sql('DELETE FROM ost_ai_actions_chat');
});

test.afterAll(() => restoreRole());

test('AC-1 (full): due date, assignment and status in one request, run in a safe order', async ({ page }) => {
  const errors = watchErrors(page);
  await staffLogin(page, 'admin');
  await openTicket(page, 'T1');
  const card = await plan(page, 'Close it, due tomorrow and assign it to Philipp');

  // Preview in run order: fields, assignment, status last.
  await expect(card.locator('li .spa-ai-plan-label')).toHaveText(['Due date', 'Assigned to', 'Status']);
  await expect(card.locator('li').nth(1)).toContainText('Philipp Example');
  await expect(card.locator('li').nth(2)).toContainText('Closed');
  expect(ticketRow(T1).status).toBe('Open');

  await card.locator('.spa-ai-run').click();
  await expect(page.locator('#spa-ai .spa-ai-plan .spa-ai-ok')).toHaveCount(3);
  const row = ticketRow(T1);
  expect(row.status).toBe('Closed');
  expect(row.staff).toBe(seed.agents.philipp);
  expect(row.due).not.toBe('NULL');
  expect(errors).toEqual([]);
});

test('priority and transfer use osTicket\'s own labels', async ({ page }) => {
  await staffLogin(page, 'admin');
  await openTicket(page, 'T2');
  const card = await plan(page, 'Set priority high and move it to sales');
  await expect(card.locator('li .spa-ai-plan-label')).toHaveText(['Priority', 'Department']);
  await expect(card).toContainText('High');
  await expect(card).toContainText('Sales');
  await card.locator('.spa-ai-run').click();
  await expect(page.locator('#spa-ai .spa-ai-plan .spa-ai-ok')).toHaveCount(2);
  const row = ticketRow(T2);
  expect(row.prio).toBe('high');
  expect(row.dept).toBe('Sales');
});

test('AC-3: a change the agent may not make fails alone, the others run', async ({ page }) => {
  await staffLogin(page, 'philipp');
  await openTicket(page, 'T1');
  const card = await plan(page, 'Assign it to Philipp and close it');
  await expect(card.locator('li .spa-ai-plan-label')).toHaveText(['Assigned to', 'Status']);

  // Between plan and run, the agent's role loses "close".
  const without = JSON.parse(EXPANDED);
  delete without['ticket.close'];
  sql(`UPDATE ost_role SET permissions='${JSON.stringify(without)}' WHERE name='Expanded Access'`);
  await card.locator('.spa-ai-run').click();

  const done = page.locator('#spa-ai .spa-ai-plan').last();
  await expect(done.locator('.spa-ai-ok')).toHaveCount(1);
  await expect(done.locator('.spa-ai-fail')).toHaveCount(1);
  await expect(done.locator('.spa-ai-fail')).toContainText('permission denied');
  const row = ticketRow(T1);
  expect(row.staff).toBe(seed.agents.philipp);
  expect(row.status).toBe('Open');
});

