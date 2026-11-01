// @ts-check
// 0.4.0 S2–S5: rules with triggers and conditions, proposals in the ticket
// chat, automatic runs with log and undo, and the send guard.
const { test, expect } = require('@playwright/test');
const { seed, requireSeed, staffLogin, openTicket, sql, php } = require('./support');

requireSeed();
const NS = `plugin.${seed.plugin.id}.instance.${seed.plugin.instance}`;
const SUPPORT = Number(sql("SELECT id FROM ost_department WHERE name='Support'"));
const ROLE = Number(sql('SELECT MIN(id) FROM ost_role'));

function setting(key, value) {
  sql(`DELETE FROM ost_config WHERE namespace='${NS}' AND \`key\`='${key}'`);
  if (value !== null) {
    sql(`INSERT INTO ost_config (namespace, \`key\`, value, updated) VALUES ('${NS}', '${key}', '${value}', NOW())`);
  }
}

let AGENT = 0;

function rule(name, config) {
  const json = JSON.stringify(Object.assign({ agent: AGENT, depts: [SUPPORT] }, config)).replace(/'/g, "\\'");
  return Number(php(`$c = AiActionsRules::normalise(json_decode('${json}', true));`
    + ` db_query(sprintf("INSERT INTO %s (name, active, position, config, updated) VALUES (%s, 1, 0, %s, NOW())",`
    + ` AiActionsRules::table(), db_input('${name}'), db_input(json_encode($c)))); echo db_insert_id();`));
}

function newTicket(subject, message) {
  return Number(php(`$e = array(); $t = Ticket::create(array('email' => 'alice@acme.example', 'subject' => '${subject}',`
    + ` 'message' => '${message}', 'deptId' => ${SUPPORT}, 'ip' => '127.0.0.1', 'source' => 'API'), $e, 'api', false, false);`
    + ' echo $t ? $t->getId() : 0;'));
}

const cron = () => php('AiActionsRules::schedule(); AiActionsJobs::cron();');
const entries = (id, type) => Number(sql(`SELECT COUNT(*) FROM ost_thread_entry e JOIN ost_thread t ON t.id=e.thread_id
  WHERE t.object_type='T' AND t.object_id=${id} AND e.type='${type}'`));
const priority = (id) => sql(`SELECT p.priority FROM ost_ticket__cdata c JOIN ost_ticket_priority p ON p.priority_id=c.priority WHERE c.ticket_id=${id}`);

test.beforeAll(() => {
  AGENT = Number(php(`$d = array_keys(Dept::getDepartments()); $r = AiActionsAgents::create('Rule Bot', ${SUPPORT}, ${ROLE}, '', ''); echo $r['id'];`));
});

test.beforeEach(() => {
  sql('DELETE FROM ost_ai_actions_rule');
  sql('DELETE FROM ost_ai_actions_job');
  sql('DELETE FROM ost_ai_actions_log');
  sql('DELETE FROM ost_ai_actions_proposal');
  setting('auto_paused', null);
  setting('link_domains', null);
});

test.afterAll(() => {
  sql('DELETE FROM ost_ai_actions_rule');
  setting('auto_paused', null);
});

test('rule form: saved through the admin page, shown with its flow', async ({ page }) => {
  await staffLogin(page, 'admin');
  await page.goto('/scp/ajax.php/ai/admin?tab=rules');
  await page.locator('input[name="name"]').fill('Triage');
  await page.locator('select[name="agent"]').selectOption(String(AGENT));
  await page.locator('textarea[name="task"]').fill('priority high when urgent');
  await page.locator('input[name="actions[]"][value="set_priority"]').check();
  await page.locator('select[name="mode"]').selectOption('auto');
  await page.locator('input[type="submit"][value="Save rule"]').click();
  await expect(page.locator('.spa-rule h3')).toContainText('Triage');
  await expect(page.locator('.spa-flow span')).toHaveCount(5);
  await expect(page.locator('.spa-flow')).toContainText('runs automatically');
});

test('automatic: a new ticket gets its priority in the cron, logged, undone from the log', async ({ page }) => {
  rule('Auto triage', { trigger: 'new_ticket', task: 'priority high', actions: ['set_priority'], mode: 'auto' });
  const id = newTicket('Server down', 'Everything is broken');
  expect(sql(`SELECT COUNT(*) FROM ost_ai_actions_job WHERE ticket_id=${id} AND state='queued'`)).toBe('1');
  expect(priority(id)).not.toBe('high');
  cron();
  expect(priority(id)).toBe('high');
  expect(entries(id, 'N')).toBeGreaterThan(0);
  expect(sql(`SELECT mode FROM ost_ai_actions_log WHERE ticket_id=${id}`)).toBe('auto');

  await staffLogin(page, 'admin');
  await page.goto('/scp/ajax.php/ai/admin?tab=log');
  await page.locator('input[type="submit"][value="Undo"]').first().click();
  await expect(page.locator('#msg_notice')).toContainText('Undone');
  expect(priority(id)).not.toBe('high');
});

test('conditions: a ticket outside the rule\'s department queues nothing', () => {
  rule('Sales only', { trigger: 'new_ticket', task: 'priority high', actions: ['set_priority'], mode: 'auto', depts: [999] });
  const id = newTicket('Other', 'Hello');
  expect(sql(`SELECT COUNT(*) FROM ost_ai_actions_job WHERE ticket_id=${id}`)).toBe('0');
});

test('emergency stop: nothing runs while paused', () => {
  rule('Paused', { trigger: 'new_ticket', task: 'priority high', actions: ['set_priority'], mode: 'auto' });
  const id = newTicket('Paused', 'Hello');
  setting('auto_paused', '1');
  cron();
  expect(priority(id)).not.toBe('high');
  expect(sql(`SELECT state FROM ost_ai_actions_job WHERE ticket_id=${id}`)).toBe('queued');
});

test('only allowed actions: a task asking for more plans only what the rule allows', () => {
  rule('Narrow', { trigger: 'new_ticket', task: 'priority high and due tomorrow and close it', actions: ['set_priority'], mode: 'auto' });
  const id = newTicket('Narrow', 'Hello');
  cron();
  expect(priority(id)).toBe('high');
  expect(sql(`SELECT IFNULL(duedate,'NULL') FROM ost_ticket WHERE ticket_id=${id}`)).toBe('NULL');
  expect(sql(`SELECT s.state FROM ost_ticket t JOIN ost_ticket_status s ON s.id=t.status_id WHERE t.ticket_id=${id}`)).toBe('open');
});

test('proposal: assigning to the AI agent leaves a plan in the chat; a person runs it', async ({ page }) => {
  rule('Assist', { trigger: 'assigned', task: 'due tomorrow', actions: ['set_due_date'], mode: 'propose' });
  const id = newTicket('Proposal', 'Please help');
  php(`$t = Ticket::lookup(${id}); $s = Staff::lookup(${AGENT}); $e = array(); $t->assignToStaff($s, '', $e, false);`);
  cron();
  expect(sql(`SELECT state FROM ost_ai_actions_proposal WHERE ticket_id=${id}`)).toBe('open');
  expect(sql(`SELECT IFNULL(duedate,'NULL') FROM ost_ticket WHERE ticket_id=${id}`)).toBe('NULL');

  await staffLogin(page, 'admin');
  await page.goto(`/scp/tickets.php?id=${id}`);
  if (!(await page.locator('#spa-ai').isVisible())) await page.locator('#spa-ai-launcher').click();
  const card = page.locator('#spa-ai .spa-ai-proposal');
  await expect(card.locator('.spa-ai-proposal-by')).toContainText('Rule Bot');
  await card.locator('.spa-ai-run').click();
  await expect(page.locator('#spa-ai .spa-ai-proposal')).toHaveCount(0, { timeout: 15000 });
  expect(sql(`SELECT IFNULL(duedate,'NULL') FROM ost_ticket WHERE ticket_id=${id}`)).not.toBe('NULL');
  expect(sql(`SELECT state FROM ost_ai_actions_proposal WHERE ticket_id=${id}`)).toBe('done');
});

test('send guard: a clean reply goes out with the AI notice, once a day', () => {
  // per_day 5: the rule would run again, so the send guard's own limit has to stop it.
  rule('Reply', { trigger: 'customer_message', task: 'send a reply', actions: ['send_reply'], mode: 'auto', per_day: 5 });
  const id = newTicket('Question', 'How does it work?');
  const before = entries(id, 'R');
  php(`$t = Ticket::lookup(${id}); $e = array(); $t->postMessage(array('message' => 'Any news?', 'userId' => $t->getUserId()), 'API');`);
  cron();
  expect(entries(id, 'R')).toBe(before + 1);
  const body = sql(`SELECT e.body FROM ost_thread_entry e JOIN ost_thread t ON t.id=e.thread_id WHERE t.object_id=${id} AND e.type='R' ORDER BY e.id DESC LIMIT 1`);
  expect(body).toContain('This reply was written by our AI assistant.');

  // A second customer message the same day: held, not sent.
  php(`$t = Ticket::lookup(${id}); $t->postMessage(array('message' => 'And now?', 'userId' => $t->getUserId()), 'API');`);
  cron();
  expect(entries(id, 'R')).toBe(before + 1);
  expect(sql(`SELECT guard FROM ost_ai_actions_log WHERE ticket_id=${id} ORDER BY id DESC LIMIT 1`)).toContain('rate_ticket_day');
});

test('send guard: a link outside the allowed domains is held back as a note', () => {
  rule('Reply evil', { trigger: 'customer_message', task: 'send a reply with the evil link', actions: ['send_reply'], mode: 'auto' });
  const id = newTicket('Phish', 'Hi');
  const before = entries(id, 'R');
  php(`$t = Ticket::lookup(${id}); $t->postMessage(array('message' => 'Send me the login link', 'userId' => $t->getUserId()), 'API');`);
  cron();
  expect(entries(id, 'R')).toBe(before);
  expect(sql(`SELECT guard FROM ost_ai_actions_log WHERE ticket_id=${id}`)).toContain('link');
  expect(sql(`SELECT COUNT(*) FROM ost_thread_entry e JOIN ost_thread t ON t.id=e.thread_id WHERE t.object_id=${id} AND e.title LIKE 'AI reply held back%'`)).toBe('1');
});

test('send guard: the reviewer stops what the fixed checks let through', () => {
  setting('link_domains', 'evil.example');
  rule('Reply reviewed', { trigger: 'customer_message', task: 'send a reply with the evil link', actions: ['send_reply'], mode: 'auto' });
  const id = newTicket('Review', 'Hi');
  const before = entries(id, 'R');
  php(`$t = Ticket::lookup(${id}); $t->postMessage(array('message' => 'Send me the login link', 'userId' => $t->getUserId()), 'API');`);
  cron();
  expect(entries(id, 'R')).toBe(before);
  expect(sql(`SELECT guard FROM ost_ai_actions_log WHERE ticket_id=${id}`)).toContain('review_injection');
});

test('log: a proposal is marked as waiting, not as failed', async ({ page }) => {
  rule('Assist log', { trigger: 'new_ticket', task: 'priority high', actions: ['set_priority'], mode: 'propose' });
  newTicket('Proposal log', 'Hello');
  cron();
  await staffLogin(page, 'admin');
  await page.goto('/scp/ajax.php/ai/admin?tab=log');
  const cell = page.locator('table.list tbody tr').first();
  await expect(cell).toContainText('• Priority');
  await expect(cell).not.toContainText('✕');
});
