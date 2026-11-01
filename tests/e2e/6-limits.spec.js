// @ts-check
// Slice 6: daily limits and one message at a time per agent (AC-8).
const path = require('path');
const { spawn } = require('child_process');
const { test, expect } = require('@playwright/test');
const { env, requireSeed, staffLogin, openTicket, aiPost, sql, LOCAL } = require('./support');

const seed = requireSeed();
const T1 = seed.tickets.T1;
const NS = `plugin.${seed.plugin.id}.instance.${seed.plugin.instance}`;
const ADMIN = seed.agents.admin;

function setting(key, value) {
  if (value === null) {
    sql(`DELETE FROM ost_config WHERE namespace='${NS}' AND \`key\`='${key}'`);
  } else {
    sql(`INSERT INTO ost_config (namespace, \`key\`, value, updated) VALUES ('${NS}', '${key}', '${value}', NOW())
         ON DUPLICATE KEY UPDATE value='${value}'`);
  }
}

async function say(page, text) {
  if (!(await page.locator('#spa-ai').isVisible())) await page.locator('#spa-ai-launcher').click();
  const chat = page.locator('#spa-ai');
  await chat.locator('.spa-ai-input').fill(text);
  await chat.locator('.spa-ai-input').press('Enter');
  await expect(chat.locator('.spa-ai-busy')).toHaveCount(0);
  return chat;
}

test.beforeEach(() => {
  sql('DELETE FROM ost_ai_actions_chat');
  sql('DELETE FROM ost_ai_actions_usage');
});

test.afterEach(() => {
  setting('daily_requests', null);
  setting('daily_tokens', null);
});

test('AC-8: the daily message limit stops the next message, with a clear text, nothing lost', async ({ page }) => {
  setting('daily_requests', '2');
  await staffLogin(page, 'admin');
  await openTicket(page, 'T1');
  await say(page, 'What is this about?');
  await say(page, 'And what else?');
  const chat = await say(page, 'One more?');
  await expect(chat.locator('.spa-ai-error')).toContainText('Daily message limit reached');
  // The message stays in the box, the conversation has two answers.
  await expect(chat.locator('.spa-ai-input')).toHaveValue('One more?');
  await expect(chat.locator('.spa-ai-msg-ai')).toHaveCount(2);
  expect(sql(`SELECT requests FROM ost_ai_actions_usage WHERE staff_id=${ADMIN} AND day=CURDATE()`)).toBe('2');
});

test('AC-8: the daily token limit counts too', async ({ page }) => {
  sql(`INSERT INTO ost_ai_actions_usage (staff_id, day, requests, input_tokens, output_tokens)
       VALUES (${ADMIN}, CURDATE(), 3, 400000, 100000)`);
  await staffLogin(page, 'admin');
  await openTicket(page, 'T1');
  const chat = await say(page, 'What is this about?');
  await expect(chat.locator('.spa-ai-error')).toContainText('Daily AI limit reached');
});

test('limits are per agent', async ({ page }) => {
  setting('daily_requests', '1');
  sql(`INSERT INTO ost_ai_actions_usage (staff_id, day, requests) VALUES (${seed.agents.philipp}, CURDATE(), 5)`);
  await staffLogin(page, 'admin');
  await openTicket(page, 'T1');
  const chat = await say(page, 'What is this about?');
  await expect(chat.locator('.spa-ai-error')).toHaveCount(0);
  await expect(chat.locator('.spa-ai-msg-ai')).toHaveCount(1);
});

test('one message at a time per agent', async ({ page }) => {
  await staffLogin(page, 'admin');
  await openTicket(page, 'T1');

  // Another connection holds the agent's lock for a few seconds, like a
  // slow model call from a second tab would.
  const holder = spawn('docker', [
    'compose', '-p', env.SPA_PROJECT || 'spa-dev', '--env-file', path.join(LOCAL, 'credentials.env'),
    '-f', path.resolve(__dirname, '../../dev/docker-compose.yml'),
    'exec', '-T', 'db', 'mariadb', '-uosticket', `-p${env.SPA_DB_PASSWORD}`, 'osticket', '-N',
    '-e', `SELECT GET_LOCK('spa_ai_actions_${ADMIN}', 0); SELECT SLEEP(6);`,
  ]);
  try {
    await expect.poll(() => sql(`SELECT IS_USED_LOCK('spa_ai_actions_${ADMIN}') IS NOT NULL`), { timeout: 5000 }).toBe('1');
    expect(await aiPost(page, '/plan', { ticket_id: String(T1), prompt: 'What is this about?' }))
      .toEqual({ status: 422, json: { error: 'busy' } });
    expect(sql('SELECT COUNT(*) FROM ost_ai_actions_usage')).toBe('0');
  } finally {
    holder.kill();
  }
  // Once the other request is over, the next one goes through.
  await expect.poll(() => sql(`SELECT IS_USED_LOCK('spa_ai_actions_${ADMIN}') IS NULL`), { timeout: 10000 }).toBe('1');
  expect((await aiPost(page, '/plan', { ticket_id: String(T1), prompt: 'What is this about?' })).status).toBe(200);
});
