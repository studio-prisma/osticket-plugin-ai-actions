// @ts-check
// 0.5.3: what the first days of automatic triage showed (closed tickets,
// one note, who did it, urgent tickets), help topic with SLA, lead tickets.
const { test, expect } = require('@playwright/test');
const { seed, requireSeed, staffLogin, sql, php } = require('./support');

requireSeed();
const SUPPORT = Number(sql("SELECT id FROM ost_department WHERE name='Support'"));
const ROLE = Number(sql('SELECT MIN(id) FROM ost_role'));
const CLOSED = seed.status.closed;

let AGENT = 0;
let AGENT_USER = '';

function rule(name, config) {
  const json = JSON.stringify(Object.assign({ agent: AGENT, depts: [SUPPORT] }, config)).replace(/'/g, "\\'");
  return Number(php(`$c = AiActionsRules::normalise(json_decode('${json}', true));`
    + ` db_query(sprintf("INSERT INTO %s (name, active, position, config, updated) VALUES (%s, 1, 0, %s, NOW())",`
    + ` AiActionsRules::table(), db_input('${name}'), db_input(json_encode($c)))); echo db_insert_id();`));
}

function newTicket(subject, message, email = 'alice@acme.example') {
  return Number(php(`$e = array(); $t = Ticket::create(array('email' => '${email}', 'name' => 'Sender ${email.split('@')[0]}', 'subject' => '${subject}',`
    + ` 'message' => '${message}', 'deptId' => ${SUPPORT}, 'ip' => '127.0.0.1', 'source' => 'API'), $e, 'api', false, false);`
    + ' echo $t ? $t->getId() : 0;'));
}

const cron = () => php('AiActionsRules::schedule(); AiActionsJobs::cron();');
const notes = (id) => Number(sql(`SELECT COUNT(*) FROM ost_thread_entry e JOIN ost_thread t ON t.id=e.thread_id
  WHERE t.object_type='T' AND t.object_id=${id} AND e.type='N'`));
const priority = (id) => sql(`SELECT p.priority FROM ost_ticket__cdata c JOIN ost_ticket_priority p ON p.priority_id=c.priority WHERE c.ticket_id=${id}`);
const state = (id) => sql(`SELECT s.state FROM ost_ticket t JOIN ost_ticket_status s ON s.id=t.status_id WHERE t.ticket_id=${id}`);

test.beforeAll(() => {
  AGENT = Number(php(`$r = AiActionsAgents::create('Triage Bot', ${SUPPORT}, ${ROLE}, '', ''); echo $r['id'];`));
  AGENT_USER = sql(`SELECT username FROM ost_staff WHERE staff_id=${AGENT}`);
});

test.beforeEach(() => {
  sql('DELETE FROM ost_ai_actions_rule');
  sql('DELETE FROM ost_ai_actions_job');
  sql('DELETE FROM ost_ai_actions_log');
  sql('DELETE FROM ost_ai_actions_proposal');
});

test.afterAll(() => {
  sql('DELETE FROM ost_ai_actions_rule');
});

test('closed by a filter before the cron: skipped without a model call', async ({ page }) => {
  rule('Skip closed', { trigger: 'new_ticket', task: 'priority high', actions: ['set_priority'], mode: 'auto' });
  const id = newTicket('Filtered', 'Closed by a filter');
  php(`$t = Ticket::lookup(${id}); $e = array(); $t->setStatus(TicketStatus::lookup(${CLOSED}), '', $e);`);
  cron();
  expect(sql(`SELECT CONCAT(mode, '/', answer, '/', tokens) FROM ost_ai_actions_log WHERE ticket_id=${id}`)).toBe('skip/closed/0');
  expect(priority(id)).not.toBe('high');

  await staffLogin(page, 'admin');
  await page.goto('/scp/ajax.php/ai/admin?tab=log');
  await expect(page.locator('table.list tbody tr').first()).toContainText('skipped: ticket closed');
});

test('one note per run, and the events name the AI agent instead of SYSTEM', () => {
  rule('One note', { trigger: 'new_ticket', task: 'priority high and a note', actions: ['set_priority', 'add_internal_note'], mode: 'auto' });
  const id = newTicket('Two notes before', 'Hello');
  const before = notes(id);
  cron();
  expect(priority(id)).toBe('high');
  expect(notes(id)).toBe(before + 1);
  const body = sql(`SELECT e.body FROM ost_thread_entry e JOIN ost_thread t ON t.id=e.thread_id
    WHERE t.object_id=${id} AND e.type='N' ORDER BY e.id DESC LIMIT 1`);
  expect(body).toContain('Fake note: customer called back.');
  expect(body).toContain('Priority: High');
  expect(body).not.toContain('Request:');
  const edited = sql(`SELECT GROUP_CONCAT(ev.username) FROM ost_thread_event ev JOIN ost_thread t ON t.id=ev.thread_id
    JOIN ost_event e ON e.id=ev.event_id WHERE t.object_type='T' AND t.object_id=${id} AND e.name='edited'`);
  expect(edited).toBe(AGENT_USER);
});

test('an urgent ticket is never closed automatically', () => {
  rule('No urgent close', { trigger: 'new_ticket', task: 'priority high and close it', actions: ['set_priority', 'set_status'], mode: 'auto' });
  const id = newTicket('Site down', 'Everything is broken');
  cron();
  expect(priority(id)).toBe('high');
  expect(state(id)).toBe('open');
  expect(sql(`SELECT results FROM ost_ai_actions_log WHERE ticket_id=${id}`)).toContain('"error":"urgent"');
});

test('the status a ticket already has counts as unchanged, not as refused', () => {
  const id = newTicket('Same status', 'Hello');
  const r = php(`$t = Ticket::lookup(${id}); echo json_encode(AiActionsExecutor::sameStatus($t, 'set_status', array('status' => (string) $t->getStatusId())));`);
  expect(JSON.parse(r)).toMatchObject({ ok: true, unchanged: true });
  expect(php(`$t = Ticket::lookup(${id}); var_export(AiActionsExecutor::sameStatus($t, 'set_status', array('status' => '${CLOSED}')));`)).toBe('NULL');
});

test('help topic: the topic brings its SLA plan along; undo from the log puts both back', async ({ page }) => {
  const sla = Number(sql('SELECT MIN(id) FROM ost_sla'));
  const saved = sql("SELECT GROUP_CONCAT(CONCAT(topic_id, ':', sla_id)) FROM ost_help_topic");
  // Every topic gets the SLA, so whichever the model picks brings it along.
  sql(`UPDATE ost_help_topic SET sla_id=${sla}`);
  try {
    rule('Topic', { trigger: 'new_ticket', task: 'set the topic', actions: ['set_topic'], mode: 'auto' });
    const id = newTicket('Topic', 'Which topic?');
    sql(`UPDATE ost_ticket SET topic_id=0, sla_id=0, est_duedate=NULL WHERE ticket_id=${id}`);
    cron();
    const [topic, ticketSla, due] = sql(`SELECT CONCAT(topic_id, '/', sla_id, '/', IF(est_duedate IS NULL, 'none', 'set'))
      FROM ost_ticket WHERE ticket_id=${id}`).split('/');
    expect(topic).not.toBe('0');
    expect(ticketSla).toBe(String(sla));
    expect(due).toBe('set');

    await staffLogin(page, 'admin');
    await page.goto('/scp/ajax.php/ai/admin?tab=log');
    await page.locator('input[type="submit"][value="Undo"]').first().click();
    await expect(page.locator('#msg_notice')).toContainText('Undone');
    expect(sql(`SELECT CONCAT(topic_id, '/', sla_id) FROM ost_ticket WHERE ticket_id=${id}`)).toBe('0/0');
  } finally {
    for (const pair of saved.split(',')) {
      const [t, s] = pair.split(':');
      sql(`UPDATE ost_help_topic SET sla_id=${Number(s)} WHERE topic_id=${Number(t)}`);
    }
  }
});

test('lead ticket: hangs under an open ticket of the same customer, with a note there', () => {
  const lead = newTicket('Alert series', 'First alert', 'series@example.com');
  const other = newTicket('Other customer', 'Not a candidate', 'bob@example.org');
  rule('Parent', { trigger: 'new_ticket', task: 'link it to the parent', actions: ['link_parent'], mode: 'auto' });
  const id = newTicket('Alert series', 'Second alert', 'series@example.com');

  const options = php(`$t = Ticket::lookup(${id}); $s = Staff::lookup(${AGENT}); echo json_encode(array_column(AiActionsChoices::forTicket($t, $s)['link_parent'] ?? array(), 'value'));`);
  expect(JSON.parse(options)).toContain(String(lead));
  expect(JSON.parse(options)).not.toContain(String(other));
  expect(JSON.parse(options)).not.toContain(String(id));

  cron();
  expect(sql(`SELECT IFNULL(ticket_pid, 0) FROM ost_ticket WHERE ticket_id=${id}`)).toBe(String(lead));
  const leadNote = sql(`SELECT e.body FROM ost_thread_entry e JOIN ost_thread t ON t.id=e.thread_id
    WHERE t.object_id=${lead} AND e.type='N' ORDER BY e.id DESC LIMIT 1`);
  expect(leadNote).toContain('Fake: same alert, site a.example.');

  // A sub-ticket gets no lead tickets of its own: no chains.
  const again = php(`$t = Ticket::lookup(${id}); $s = Staff::lookup(${AGENT}); echo count(AiActionsChoices::forTicket($t, $s)['link_parent'] ?? array());`);
  expect(again).toBe('0');
});
