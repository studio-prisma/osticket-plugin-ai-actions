// @ts-check
// 0.8.1: AI agents have their own daily limits, and a run that hits one
// alerts the admin once a day instead of failing quietly in every ticket.
const { test, expect } = require('@playwright/test');
const { env, seed, requireSeed, staffLogin, sql, php } = require('./support');

requireSeed();
const NS = `plugin.${seed.plugin.id}.instance.${seed.plugin.instance}`;
const SUPPORT = Number(sql("SELECT id FROM ost_department WHERE name='Support'"));
const ROLE = Number(sql('SELECT MIN(id) FROM ost_role'));
const MAIL = `http://127.0.0.1:${env.SPA_MAIL_PORT || 8027}/api/v1`;

let AGENT = 0;

function setting(key, value) {
  if (value === null) {
    sql(`DELETE FROM ost_config WHERE namespace='${NS}' AND \`key\`='${key}'`);
  } else {
    sql(`INSERT INTO ost_config (namespace, \`key\`, value, updated) VALUES ('${NS}', '${key}', '${value}', NOW())
         ON DUPLICATE KEY UPDATE value='${value}'`);
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

/** Today's usage of the AI agent, as if earlier runs had spent it. */
function used(tokens) {
  sql(`INSERT INTO ost_ai_actions_usage (staff_id, day, requests, input_tokens, output_tokens) VALUES (${AGENT}, CURDATE(), 1, ${tokens}, 0)
       ON DUPLICATE KEY UPDATE input_tokens=${tokens}, output_tokens=0`);
}

const cron = () => php('AiActionsRules::schedule(); AiActionsJobs::cron();');
const jobError = (id) => sql(`SELECT CONCAT(state, ':', error) FROM ost_ai_actions_job WHERE ticket_id=${id} ORDER BY id DESC LIMIT 1`);

async function alertMails(request) {
  const list = await (await request.get(`${MAIL}/messages?limit=50`)).json();
  return (list.messages || []).map((m) => m.Subject).filter((s) => s.startsWith('AI Actions:'));
}

test.beforeAll(() => {
  AGENT = Number(php(`$r = AiActionsAgents::create('Limit Bot', ${SUPPORT}, ${ROLE}, '', ''); echo $r['id'];`));
});

test.beforeEach(async ({ request }) => {
  sql('DELETE FROM ost_ai_actions_rule');
  sql('DELETE FROM ost_ai_actions_job');
  sql('DELETE FROM ost_ai_actions_log');
  sql('DELETE FROM ost_ai_actions_usage');
  sql("DELETE FROM ost_config WHERE namespace='plugin.ai-actions.alerts'");
  setting('fallback_on', '0');
  await request.delete(`${MAIL}/messages`);
});

test.afterAll(() => {
  sql('DELETE FROM ost_ai_actions_rule');
  sql('DELETE FROM ost_ai_actions_usage');
  for (const k of ['fallback_on', 'daily_tokens', 'agent_daily_tokens']) setting(k, null);
});

test('the chat limit no longer stops AI agents', () => {
  setting('daily_tokens', '1000');
  used(600000);
  rule('Due', { trigger: 'new_ticket', task: 'due date tomorrow', actions: ['set_due_date'], mode: 'auto' });
  const id = newTicket('Agent limit', 'Please set a due date for tomorrow');
  cron();
  expect(jobError(id)).toBe('done:');
  expect(Number(sql(`SELECT tokens FROM ost_ai_actions_log WHERE ticket_id=${id} ORDER BY id DESC LIMIT 1`))).toBeGreaterThan(0);
});

test('AI agent limit reached: runs fail, one admin mail, system log, status tab', async ({ page, request }) => {
  test.setTimeout(120000);
  setting('agent_daily_tokens', '1000');
  used(5000);
  rule('Due', { trigger: 'new_ticket', task: 'due date tomorrow', actions: ['set_due_date'], mode: 'auto' });
  const a = newTicket('Limit one', 'Please set a due date for tomorrow');
  const b = newTicket('Limit two', 'Please set a due date for tomorrow');
  cron();
  cron();
  expect(jobError(a)).toBe('failed:limit_tokens');
  expect(jobError(b)).toBe('failed:limit_tokens');

  await expect.poll(() => alertMails(request), { timeout: 10000 })
    .toEqual(['AI Actions: Limit Bot stopped working (limit_tokens)']);
  expect(Number(sql("SELECT COUNT(*) FROM ost_syslog WHERE title LIKE 'AI Actions: Limit Bot stopped working%'"))).toBeGreaterThan(0);

  await staffLogin(page, 'admin');
  await page.goto('/scp/ajax.php/ai/admin?tab=status');
  await expect(page.locator('tr', { hasText: 'Daily limits' })).toContainText('Limit Bot: 5,000 of 1,000 tokens');

  // Raised: the next run works again.
  setting('agent_daily_tokens', null);
  const c = newTicket('Limit three', 'Please set a due date for tomorrow');
  cron();
  expect(jobError(c)).toBe('done:');
  await page.reload();
  await expect(page.locator('tr', { hasText: 'Daily limits' })).toContainText('No AI agent has reached its daily limit.');
});
