// @ts-check
// 0.5.5: osTicket's agent form doesn't know the "ai-agent" backend and can
// set a password. Saving an AI agent there must not undo either lock.
const { test, expect } = require('@playwright/test');
const { requireSeed, staffLogin, sql, php } = require('./support');

requireSeed();

function agentId() {
  return Number(php("$r = AiActionsAgents::create('Guard " + Date.now() + "', 1, (int) db_result(db_query('SELECT MIN(id) FROM ' . ROLE_TABLE)), '', ''); echo $r['id'];"));
}

test('saving an AI agent in osTicket\'s agent form keeps its backend', async ({ page }) => {
  const id = agentId();
  expect(id).toBeGreaterThan(0);
  const hash = sql(`SELECT passwd FROM ost_staff WHERE staff_id=${id}`);
  await staffLogin(page, 'admin');
  await page.goto(`/scp/staff.php?id=${id}`);
  await page.locator('input[name="phone"]').fill('5551234567');
  await page.locator('form#save input[type="submit"], form input[type="submit"][name="submit"]').first().click();
  await expect(page.locator('#msg_notice, .notice_bar')).toBeVisible();
  // The form's change is kept; the locks are back.
  expect(sql(`SELECT phone FROM ost_staff WHERE staff_id=${id}`).replace(/\D/g, '')).toBe('5551234567');
  expect(sql(`SELECT backend FROM ost_staff WHERE staff_id=${id}`)).toBe('ai-agent');
  expect(sql(`SELECT passwd FROM ost_staff WHERE staff_id=${id}`)).toBe(hash);
});

test('a password set for an AI agent is replaced by an unknown one', () => {
  const id = agentId();
  const out = php(`$s = Staff::lookup(${id}); $s->backend = ''; $s->passwd = Passwd::hash('known-pass-123'); $s->save();`
    + ` $s = Staff::lookup(${id}); echo $s->backend, '|', Passwd::cmp('known-pass-123', $s->passwd) ? 'known' : 'unknown';`);
  expect(out).toBe('ai-agent|unknown');
  expect(sql(`SELECT backend FROM ost_staff WHERE staff_id=${id}`)).toBe('ai-agent');
  expect(sql(`SELECT passwd FROM ost_staff WHERE staff_id=${id}`)).toMatch(/^\$2[aby]\$/);
});

test('other agents are left alone', () => {
  const id = Number(sql("SELECT staff_id FROM ost_staff WHERE backend='' OR backend IS NULL ORDER BY staff_id LIMIT 1"));
  const before = sql(`SELECT CONCAT(IFNULL(backend,''),'/',passwd) FROM ost_staff WHERE staff_id=${id}`);
  php(`$s = Staff::lookup(${id}); $s->phone = '5550000000'; $s->save();`);
  expect(sql(`SELECT CONCAT(IFNULL(backend,''),'/',passwd) FROM ost_staff WHERE staff_id=${id}`)).toBe(before);
});
