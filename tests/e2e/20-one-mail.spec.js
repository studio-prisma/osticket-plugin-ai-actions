// @ts-check
// 0.7.0: one mail per new ticket, after the AI agent's run, with its note;
// none for tickets it settles; a fallback assignment when no AI agent did.
const { test, expect } = require('@playwright/test');
const { env, seed, requireSeed, sql, php } = require('./support');

requireSeed();
const NS = `plugin.${seed.plugin.id}.instance.${seed.plugin.instance}`;
const SUPPORT = Number(sql("SELECT id FROM ost_department WHERE name='Support'"));
const ROLE = Number(sql('SELECT MIN(id) FROM ost_role'));
const CLOSED = seed.status.closed;
const MAIL = `http://127.0.0.1:${env.SPA_MAIL_PORT || 8027}/api/v1`;
const ADMIN = seed.agents.admin;
const HOLD = 'e2e-hold';

// Every php()/sql() call is a docker exec; the fallback test makes many.
test.describe.configure({ timeout: 120000 });

let AGENT = 0;

function setting(key, value) {
  sql(`DELETE FROM ost_config WHERE namespace='${NS}' AND \`key\`='${key}'`);
  if (value !== null) {
    sql(`INSERT INTO ost_config (namespace, \`key\`, value, updated) VALUES ('${NS}', '${key}', '${value}', NOW())`);
  }
}

function rule(name, config) {
  const json = JSON.stringify(Object.assign({ agent: AGENT, depts: [SUPPORT] }, config)).replace(/'/g, "\\'");
  return Number(php(`$c = AiActionsRules::normalise(json_decode('${json}', true));`
    + ` db_query(sprintf("INSERT INTO %s (name, active, position, config, updated) VALUES (%s, 1, 0, %s, NOW())",`
    + ` AiActionsRules::table(), db_input('${name}'), db_input(json_encode($c)))); echo db_insert_id();`));
}

function newTicket(subject, message) {
  return Number(php(`$e = array(); $t = Ticket::create(array('email' => 'alice@acme.example', 'name' => 'Alice Acme', 'subject' => '${subject}',`
    + ` 'message' => '${message}', 'deptId' => ${SUPPORT}, 'ip' => '127.0.0.1', 'source' => 'API'), $e, 'api', false, false);`
    + ' echo $t ? $t->getId() : 0;'));
}

const cron = () => php('AiActionsRules::schedule(); AiActionsJobs::cron(); AiActionsNotify::cron();');
const assignee = (id) => Number(sql(`SELECT staff_id FROM ost_ticket WHERE ticket_id=${id}`));
const email = (staffId) => sql(`SELECT email FROM ost_staff WHERE staff_id=${staffId}`);
const number = (id) => sql(`SELECT number FROM ost_ticket WHERE ticket_id=${id}`);

async function clearMail(request) {
  await request.delete(`${MAIL}/messages`);
}

/** Mails whose subject names the ticket, with their text. */
async function mails(request, id) {
  const list = await (await request.get(`${MAIL}/search?query=${encodeURIComponent(number(id))}`)).json();
  const out = [];
  for (const m of list.messages || []) {
    const full = await (await request.get(`${MAIL}/message/${m.ID}`)).json();
    out.push({ to: m.To.map((t) => t.Address.toLowerCase()), subject: m.Subject, text: full.Text || '' });
  }
  return out;
}

/** The fallback only looks at what this test made: other open tickets are held back. */
function holdOthers(except) {
  sql(`INSERT INTO ost_ai_actions_log (rule_id, ticket_id, agent_id, mode, answer, results, guard, created)
    SELECT 0, t.ticket_id, 0, 'fallback', '${HOLD}', '[]', '[]', NOW() FROM ost_ticket t WHERE t.ticket_id NOT IN (${except.join(',')})`);
}

test.beforeAll(() => {
  AGENT = Number(php(`$r = AiActionsAgents::create('Mail Bot', ${SUPPORT}, ${ROLE}, '', ''); echo $r['id'];`));
});

test.beforeEach(() => {
  sql('DELETE FROM ost_ai_actions_rule');
  sql('DELETE FROM ost_ai_actions_job');
  sql('DELETE FROM ost_ai_actions_log');
  // Fallback off unless a test switches it on: it would also see the other tests' tickets.
  setting('fallback_on', '0');
  setting('notify_agent', null);
});

test.afterAll(() => {
  sql('DELETE FROM ost_ai_actions_rule');
  sql(`DELETE FROM ost_ai_actions_log WHERE answer='${HOLD}'`);
  setting('fallback_on', null);
  setting('notify_agent', null);
});

test('AI assigns: one mail, after the run, with its note and the final state', async ({ request }) => {
  rule('Triage', { trigger: 'new_ticket', task: 'philipp, priority high and a note', mode: 'auto',
    actions: ['assign_agent', 'set_priority', 'add_internal_note'] });
  const id = newTicket('Mail once', 'Please help');
  await clearMail(request);
  cron();

  const who = assignee(id);
  expect(who).toBeGreaterThan(0);
  const got = await mails(request, id);
  expect(got).toHaveLength(1);
  expect(got[0].to).toEqual([email(who).toLowerCase()]);
  // The run's note is the assignment's comment: assessment and changes.
  expect(got[0].text).toContain('Fake note: customer called back.');
  expect(got[0].text).toContain('Priority: High');
  // One note on the ticket, no second "Ticket Assigned" note from the core.
  expect(Number(sql(`SELECT COUNT(*) FROM ost_thread_entry e JOIN ost_thread t ON t.id=e.thread_id
    WHERE t.object_type='T' AND t.object_id=${id} AND e.type='N'`))).toBe(1);
});

test('AI closes the ticket: no mail at all', async ({ request }) => {
  rule('Close noise', { trigger: 'new_ticket', task: 'philipp and close it', mode: 'auto',
    actions: ['assign_agent', 'set_status'] });
  const id = newTicket('Mail never', 'Newsletter');
  await clearMail(request);
  cron();

  expect(sql(`SELECT s.state FROM ost_ticket t JOIN ost_ticket_status s ON s.id=t.status_id WHERE t.ticket_id=${id}`)).toBe('closed');
  expect(await mails(request, id)).toHaveLength(0);
});

// 0.7.1: a related new request hung under an older lead ticket is not settled.
test('AI hangs it under a lead ticket and leaves it open: the assignee still gets the one mail', async ({ request }) => {
  const lead = newTicket('Mail lead', 'First request');
  expect(lead).toBeGreaterThan(0);
  rule('Related', { trigger: 'new_ticket', task: 'philipp and the parent', mode: 'auto',
    actions: ['assign_agent', 'link_parent'] });
  const id = newTicket('Mail related', 'Second, different request');
  await clearMail(request);
  cron();

  expect(Number(sql(`SELECT ticket_pid FROM ost_ticket WHERE ticket_id=${id}`))).toBeGreaterThan(0);
  expect(sql(`SELECT s.state FROM ost_ticket t JOIN ost_ticket_status s ON s.id=t.status_id WHERE t.ticket_id=${id}`)).toBe('open');
  const who = assignee(id);
  expect(who).toBeGreaterThan(0);
  const got = await mails(request, id);
  expect(got).toHaveLength(1);
  expect(got[0].to).toEqual([email(who).toLowerCase()]);
});

test('assigning to whoever has it already counts as done, not as refused', () => {
  rule('Same agent', { trigger: 'new_ticket', task: 'philipp', mode: 'auto', actions: ['assign_agent'] });
  const id = newTicket('Already Philipp', 'Hello');
  const philipp = seed.agents.philipp;
  php(`$t = Ticket::lookup(${id}); $t->assignToStaff(${philipp}, '', false);`);
  const fake = php(`echo json_encode(AiActionsExecutor::same(Ticket::lookup(${id}), 'assign_agent', array('agent' => 's${philipp}')));`);
  expect(JSON.parse(fake)).toMatchObject({ ok: true, unchanged: true });
  expect(php(`echo json_encode(AiActionsExecutor::same(Ticket::lookup(${id}), 'assign_agent', array('agent' => 's${ADMIN}')));`)).toBe('null');
});

test('fallback: nobody assigned after the wait → the notify agent, once, saying why', async ({ request }) => {
  setting('fallback_on', '1');
  setting('notify_agent', `{"${ADMIN}":"Admin"}`);
  sql("REPLACE INTO ost_ai_actions_state (name, value) VALUES ('fallback_since', UNIX_TIMESTAMP(NOW() - INTERVAL 1 HOUR))");
  const late = newTicket('No rule here', 'Nobody looks');
  const fresh = newTicket('Too fresh', 'Wait for me');
  const closed = newTicket('Closed already', 'Done');
  php(`$t = Ticket::lookup(${closed}); $e = array(); $t->setStatus(TicketStatus::lookup(${CLOSED}), '', $e);`);
  sql(`UPDATE ost_ticket SET created = NOW() - INTERVAL 20 MINUTE WHERE ticket_id IN (${late}, ${closed})`);
  holdOthers([late, fresh, closed]);
  await clearMail(request);
  cron();

  expect(assignee(late)).toBe(ADMIN);
  expect(assignee(fresh)).toBe(0);
  const got = await mails(request, late);
  expect(got).toHaveLength(1);
  expect(got[0].to).toEqual([email(ADMIN).toLowerCase()]);
  expect(got[0].text).toMatch(/no rule matched|keine passende Regel/);
  expect(sql(`SELECT CONCAT(mode, '/', answer) FROM ost_ai_actions_log WHERE ticket_id=${late}`)).toBe('fallback/no_rule');

  // Next cron: nothing again, and the fresh one stays until its wait is over.
  cron();
  expect(await mails(request, late)).toHaveLength(1);
  expect(assignee(fresh)).toBe(0);
});

test('fallback off: the plugin assigns nothing', async ({ request }) => {
  setting('notify_agent', `{"${ADMIN}":"Admin"}`);
  sql("REPLACE INTO ost_ai_actions_state (name, value) VALUES ('fallback_since', UNIX_TIMESTAMP(NOW() - INTERVAL 1 HOUR))");
  const id = newTicket('Fallback off', 'Nobody');
  sql(`UPDATE ost_ticket SET created = NOW() - INTERVAL 20 MINUTE WHERE ticket_id=${id}`);
  holdOthers([id]);
  cron();
  expect(assignee(id)).toBe(0);
});
