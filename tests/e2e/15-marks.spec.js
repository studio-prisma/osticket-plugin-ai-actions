// @ts-check
// 0.5.3: a waiting proposal shows as a dot, automatic runs show in the
// ticket's chat with undo, AI agents have their own picture.
const { test, expect } = require('@playwright/test');
const { seed, requireSeed, staffLogin, sql, php, watchErrors } = require('./support');

requireSeed();
const SUPPORT = Number(sql("SELECT id FROM ost_department WHERE name='Support'"));
const ROLE = Number(sql('SELECT MIN(id) FROM ost_role'));
const PICTURE = '/images/mystery-oscar.png';

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
const priority = (id) => sql(`SELECT p.priority FROM ost_ticket__cdata c JOIN ost_ticket_priority p ON p.priority_id=c.priority WHERE c.ticket_id=${id}`);

test.beforeAll(() => {
  AGENT = Number(php(`$r = AiActionsAgents::create('Picture Bot', ${SUPPORT}, ${ROLE}, '', '', '${PICTURE}'); echo $r['id'];`));
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

test('a waiting proposal puts a dot on the bubble; deciding removes it', async ({ page }) => {
  const errors = watchErrors(page);
  rule('Dot', { trigger: 'new_ticket', task: 'priority high', actions: ['set_priority'], mode: 'propose' });
  const id = newTicket('Dot', 'Hello');
  cron();
  expect(sql(`SELECT state FROM ost_ai_actions_proposal WHERE ticket_id=${id}`)).toBe('open');

  await staffLogin(page, 'admin');
  await page.goto(`/scp/tickets.php?id=${id}`);
  await expect(page.locator('#spa-ai-launcher.spa-ai-launcher-dot')).toBeVisible();

  if (!(await page.locator('#spa-ai').isVisible())) await page.locator('#spa-ai-launcher').click();
  const card = page.locator('#spa-ai .spa-ai-proposal');
  await expect(card.locator('.spa-ai-agent-img')).toHaveAttribute('src', PICTURE);
  await card.locator('.spa-ai-discard').click();
  await expect(page.locator('#spa-ai .spa-ai-proposal')).toHaveCount(0);
  await expect(page.locator('#spa-ai-launcher.spa-ai-launcher-dot')).toHaveCount(0);
  expect(errors).toEqual([]);
});

test('an automatic run shows in the ticket chat and can be undone there', async ({ page }) => {
  rule('Auto', { trigger: 'new_ticket', task: 'priority high', actions: ['set_priority'], mode: 'auto' });
  const id = newTicket('Auto chat', 'Hello');
  cron();
  expect(priority(id)).toBe('high');

  await staffLogin(page, 'philipp');
  await page.goto(`/scp/tickets.php?id=${id}`);
  if (!(await page.locator('#spa-ai').isVisible())) await page.locator('#spa-ai-launcher').click();
  const run = page.locator('#spa-ai .spa-ai-autorun');
  await expect(run).toContainText('Picture Bot');
  await expect(run).toContainText('High');
  await run.locator('.spa-ai-autorun-undo').click();
  await expect(page.locator('#spa-ai .spa-ai-autorun .spa-ai-autorun-undo')).toHaveCount(0, { timeout: 15000 });
  expect(priority(id)).not.toBe('high');
  expect(sql(`SELECT undone FROM ost_ai_actions_log WHERE ticket_id=${id}`)).toBe('1');
});

test('undo from a chat only reaches runs of that ticket', () => {
  rule('Auto two', { trigger: 'new_ticket', task: 'priority high', actions: ['set_priority'], mode: 'auto' });
  const a = newTicket('Run A', 'Hello');
  const b = newTicket('Run B', 'Hello');
  cron();
  const logA = Number(sql(`SELECT id FROM ost_ai_actions_log WHERE ticket_id=${a}`));
  const r = php(`$GLOBALS['thisstaff'] = Staff::lookup(${seed.agents.admin}); var_export(AiActionsAutomation::undo(${logA}, ${b}));`);
  expect(r).toBe('false');
  expect(priority(a)).toBe('high');
});

test('the AI agent\'s picture shows at its thread entries', async ({ page }) => {
  const errors = watchErrors(page);
  rule('Note', { trigger: 'new_ticket', task: 'a note', actions: ['add_internal_note'], mode: 'auto' });
  const id = newTicket('Picture', 'Hello');
  cron();
  await staffLogin(page, 'admin');
  await page.goto(`/scp/tickets.php?id=${id}`);
  await expect(page.locator(`.thread-entry[data-spa-ai-img="${PICTURE}"]`).first()).toBeVisible();
  expect(errors).toEqual([]);
});

test('marks: other people\'s tickets are left out; a bad picture URL is refused', () => {
  rule('Hidden', { trigger: 'new_ticket', task: 'priority high', actions: ['set_priority'], mode: 'propose' });
  const id = newTicket('Hidden', 'Hello');
  cron();
  // The sales agent can't see Support tickets.
  const r = php(`$GLOBALS['thisstaff'] = Staff::lookup(${seed.agents.salesagent}); $_GET['ids'] = '${id}';`
    + ` $ids = AiActionsAutomation::withProposals(array(${id})); echo json_encode(array_values(array_filter($ids, function ($i) {`
    + ` $t = Ticket::lookup($i); return $t && $t->checkStaffPerm($GLOBALS['thisstaff']); })));`);
  expect(JSON.parse(r)).toEqual([]);
  expect(php(`var_export(AiActionsAgents::setImage(${AGENT}, 'javascript:alert(1)'));`)).toBe('false');
  expect(php(`var_export(AiActionsAgents::setImage(${AGENT}, '//evil.example/x.png'));`)).toBe('false');
  expect(php(`var_export(AiActionsAgents::setImage(${AGENT}, '${PICTURE}'));`)).toBe('true');
});

test('board: cards with a waiting proposal get a dot', async ({ page }) => {
  test.skip(!seed.board, 'Ticket Board plugin not mounted');
  rule('Board dot', { trigger: 'new_ticket', task: 'priority high', actions: ['set_priority'], mode: 'propose' });
  const id = newTicket('Board dot', 'Hello');
  cron();
  await staffLogin(page, 'admin');
  await page.goto('/scp/ajax.php/board');
  await expect(page.locator(`.spb-card[data-ticket-id="${id}"] .spa-ai-card-dot`)).toBeVisible();
});
