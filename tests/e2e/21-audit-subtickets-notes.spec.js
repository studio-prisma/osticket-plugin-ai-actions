// @ts-check
// Regressions for the security audit of 2026-11:
//   F1  an automatic "create sub-ticket" rule started itself again on its own
//       sub-tickets (15 chained tickets from one in three cron runs)
//   F2  the send guard missed internal notes that were reworded or
//       translated, and left an AI agent's notes out
//   F3  a sub-ticket's text stood in the thread as HTML in the customer's name
const { test, expect } = require('@playwright/test');
const { seed, requireSeed, sql, php } = require('./support');

requireSeed();
const NS = `plugin.${seed.plugin.id}.instance.${seed.plugin.instance}`;
const SUPPORT = Number(sql("SELECT id FROM ost_department WHERE name='Support'"));
const ROLE = Number(sql('SELECT MIN(id) FROM ost_role'));

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
  return Number(php(`$e = array(); $t = Ticket::create(array('email' => 'alice@acme.example', 'subject' => '${subject}',`
    + ` 'message' => '${message}', 'deptId' => ${SUPPORT}, 'ip' => '127.0.0.1', 'source' => 'API'), $e, 'api', false, false);`
    + ' echo $t ? $t->getId() : 0;'));
}

const cron = () => php('AiActionsRules::schedule(); AiActionsJobs::cron();');
const children = (id) => sql(`SELECT ticket_id FROM ost_ticket WHERE ticket_pid=${id} ORDER BY ticket_id`).split('\n').filter(Boolean).map(Number);
const entry = (id, type, column) => sql(`SELECT e.${column} FROM ost_thread_entry e JOIN ost_thread t ON t.id=e.thread_id
  WHERE t.object_type='T' AND t.object_id=${id} AND e.type='${type}' ORDER BY e.id LIMIT 1`);

test.beforeAll(() => {
  AGENT = Number(php(`$r = AiActionsAgents::create('Audit Bot', ${SUPPORT}, ${ROLE}, '', ''); echo $r['id'];`));
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
  sql('DELETE FROM ost_ai_actions_job');
});

test('F1: a "new ticket" rule that creates sub-tickets does not start on its own sub-tickets', () => {
  // per_day 5, as in the audit: the daily limit is not what stops the chain.
  rule('Split', { trigger: 'new_ticket', task: 'create a sub-ticket', actions: ['create_subticket'], mode: 'auto', per_day: 5 });
  const parent = newTicket('Two problems', 'Printer broken and VPN down.');
  cron();
  cron();
  cron();
  const kids = children(parent);
  expect(kids).toHaveLength(1);
  expect(children(kids[0])).toHaveLength(0);
  // Not even queued: the sub-ticket never reached the rule.
  expect(sql(`SELECT COUNT(*) FROM ost_ai_actions_job WHERE ticket_id=${kids[0]}`)).toBe('0');
  expect(sql(`SELECT COUNT(*) FROM ost_ai_actions_log WHERE ticket_id=${kids[0]}`)).toBe('0');
});

test('F1: a sub-ticket gets no sub-ticket of its own, whoever asks', () => {
  const parent = newTicket('Lead', 'Lead ticket.');
  const out = php(`$s = Staff::lookup(${AGENT}); $p = Ticket::lookup(${parent});`
    + ` $a = AiActionsExecutor::run($p, $s, 'create_subticket', array('subject' => 'Child', 'message' => 'x'));`
    + ` $c = Ticket::lookupByNumber($a['created']);`
    + ` $b = AiActionsExecutor::run($c, $s, 'create_subticket', array('subject' => 'Grandchild', 'message' => 'y'));`
    + ' echo json_encode(array($a["ok"], $b));');
  const [first, second] = JSON.parse(out);
  expect(first).toBe(true);
  expect(second).toEqual({ ok: false, error: 'is_subticket' });
  const kids = children(parent);
  expect(kids).toHaveLength(1);
  expect(children(kids[0])).toHaveLength(0);
});

test('F1: at most 3 sub-tickets per automatic run', () => {
  rule('Split many', { trigger: 'new_ticket', task: 'create 4 sub-tickets', actions: ['create_subticket'], mode: 'auto' });
  const parent = newTicket('Four problems', 'A, B, C and D.');
  cron();
  expect(children(parent)).toHaveLength(3);
  expect(sql(`SELECT results FROM ost_ai_actions_log WHERE ticket_id=${parent} AND mode='auto'`)).toContain('subticket_limit');
});

test('F3: the planned text is a plain-text note of the agent, not HTML in the customer\'s name', () => {
  const parent = newTicket('Lead HTML', 'Lead ticket.');
  const number = php(`$a = AiActionsExecutor::run(Ticket::lookup(${parent}), Staff::lookup(${AGENT}), 'create_subticket',`
    + ` array('subject' => 'Child HTML', 'message' => 'Check <a href="https://evil.example">the portal</a> <img src=x onerror=alert(1)> if a<b then c'));`
    + ' echo $a["created"];');
  const child = Number(sql(`SELECT ticket_id FROM ost_ticket WHERE number='${number}'`));
  expect(Number(sql(`SELECT ticket_pid FROM ost_ticket WHERE ticket_id=${child}`))).toBe(parent);

  // The customer's message: a fixed line, nothing the model wrote.
  const first = entry(child, 'M', 'body');
  expect(first).toContain(`Sub-ticket of #${sql(`SELECT number FROM ost_ticket WHERE ticket_id=${parent}`)}`);
  expect(first).not.toContain('evil.example');

  // The model's text: an internal note by the AI agent, stored as text.
  expect(Number(entry(child, 'N', 'staff_id'))).toBe(AGENT);
  expect(Number(entry(child, 'N', 'user_id'))).toBe(0);
  expect(entry(child, 'N', 'format')).toBe('text');
  // Escaped on the way in: no active HTML, and osTicket's clean-up no longer cuts at "a<b".
  const note = entry(child, 'N', 'body');
  expect(note).toContain('Check &lt;a href=');
  expect(note).toContain('if a&lt;b then c');
  expect(note).not.toMatch(/<a |<img /);
});

test('F2: an internal note in other words stops the reply at the reviewer', () => {
  rule('Reply billing', { trigger: 'customer_message', task: 'send a reply about the discount', actions: ['send_reply'], mode: 'auto' });
  const id = newTicket('Invoice', 'Question about my invoice.');
  // German note, English reply: no eight words in common, only the reviewer can tell.
  php(`$t = Ticket::lookup(${id}); $e = array(); $t->postNote(array('title' => 'Billing',`
    + ` 'note' => new TextThreadEntryBody('Kunde bekommt 20 Prozent Rabatt, aber nur intern, nicht weitergeben.')), $e, Staff::lookup(1), false);`);
  const before = Number(sql(`SELECT COUNT(*) FROM ost_thread_entry e JOIN ost_thread t ON t.id=e.thread_id WHERE t.object_id=${id} AND e.type='R'`));
  php(`$t = Ticket::lookup(${id}); $t->postMessage(array('message' => 'Any update?', 'userId' => $t->getUserId()), 'API');`);
  cron();
  expect(Number(sql(`SELECT COUNT(*) FROM ost_thread_entry e JOIN ost_thread t ON t.id=e.thread_id WHERE t.object_id=${id} AND e.type='R'`))).toBe(before);
  expect(sql(`SELECT guard FROM ost_ai_actions_log WHERE ticket_id=${id} ORDER BY id DESC LIMIT 1`)).toContain('review_internal');
});

test('F2: a rule can keep internal notes out of what the AI agent reads', () => {
  const id = newTicket('Hidden notes', 'Hello.');
  php(`$t = Ticket::lookup(${id}); $e = array(); $t->postNote(array('title' => 'Credit',`
    + ` 'note' => new TextThreadEntryBody('Credit check failed, internal only.')), $e, Staff::lookup(1), false);`);
  const read = (notes) => php(`echo AiActionsContext::data(Ticket::lookup(${id}), Staff::lookup(${AGENT}), 'UTC', 0, 12000, ${notes})['text'];`);
  expect(read('true')).toContain('Credit check failed');
  const hidden = read('false');
  expect(hidden).not.toContain('Credit check failed');
  expect(hidden).toContain('Hello.');
  expect(php(`echo json_encode(AiActionsRules::normalise(array('hide_notes' => '1'))['hide_notes']);`)).toBe('true');
});
