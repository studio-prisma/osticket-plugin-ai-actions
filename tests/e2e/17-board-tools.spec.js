// @ts-check
// 0.5.3: the Ticket Board plugin adds its own actions through
// ai-actions.tools, and an AI agent can be created while a password policy
// rejects every password (the HTTP 500 of 0.5.0).
const { test, expect } = require('@playwright/test');
const { seed, requireSeed, staffLogin, sql, php } = require('./support');

requireSeed();
const SUPPORT = Number(sql("SELECT id FROM ost_department WHERE name='Support'"));
const ROLE = Number(sql('SELECT MIN(id) FROM ost_role'));

test('a password policy that rejects everything doesn\'t stop creating an AI agent', () => {
  const r = php("class SpaRejectAll extends PasswordPolicy { static $id = 'spa-reject'; static $name = 'Reject all';"
    + " function onset($new, $current) { throw new BadPassword('rejected by test policy'); }"
    + " function onLogin($user, $password) {} }"
    + " PasswordPolicy::register('SpaRejectAll');"
    + ` $r = AiActionsAgents::create('Policy Bot', ${SUPPORT}, ${ROLE}, '', ''); echo json_encode($r);`);
  const res = JSON.parse(r);
  expect(res.id).toBeGreaterThan(0);
  expect(sql(`SELECT backend FROM ost_staff WHERE staff_id=${res.id}`)).toBe('ai-agent');
  expect(sql(`SELECT passwd FROM ost_staff WHERE staff_id=${res.id}`)).toMatch(/^\$2[aby]\$/);
});

test('board actions: offered to the assistant, run with the board\'s checks', async ({ page }) => {
  test.skip(!seed.board, 'Ticket Board plugin not mounted');
  // The board registers these actions from 0.6.0 on.
  test.skip(php("echo is_file(INCLUDE_DIR . 'plugins/board/src/BoardAiTools.php') ? 1 : 0;").trim() !== '1',
    'Ticket Board plugin older than 0.6.0');
  const id = seed.tickets.T1;
  php(`$t = Ticket::lookup(${id}); $s = Staff::lookup(${seed.agents.admin}); $e = array(); $t->assignToStaff($s, '', $e, false);`);
  expect(sql(`SELECT staff_id FROM ost_ticket WHERE ticket_id=${id}`)).toBe(String(seed.agents.admin));
  sql(`DELETE FROM ost_ai_actions_chat WHERE ticket_id=${id}`);

  await staffLogin(page, 'admin');
  await page.goto(`/scp/tickets.php?id=${id}`);
  if (!(await page.locator('#spa-ai').isVisible())) await page.locator('#spa-ai-launcher').click();
  const chat = page.locator('#spa-ai');
  await chat.locator('.spa-ai-input').fill('release the assignment');
  await chat.locator('.spa-ai-input').press('Enter');
  const plan = chat.locator('.spa-ai-plan-open').last();
  await expect(plan.locator('.spa-ai-plan-label')).toHaveText('Release assignment');
  await plan.locator('.spa-ai-run').click();
  await expect(chat.locator('.spa-ai-plan-done').last()).toBeVisible({ timeout: 15000 });
  expect(sql(`SELECT staff_id FROM ost_ticket WHERE ticket_id=${id}`)).toBe('0');
});
