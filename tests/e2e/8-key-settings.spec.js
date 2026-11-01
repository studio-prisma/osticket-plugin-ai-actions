// @ts-check
// The API key source in the plugin settings, through osTicket's real form:
// the stored key is encrypted by osTicket's PasswordField, never shown again,
// kept when the box stays empty, removable, and checked before saving.
const { test, expect } = require('@playwright/test');
const { requireSeed, staffLogin, sql, php } = require('./support');

const seed = requireSeed();
const NS = `plugin.${seed.plugin.id}.instance.${seed.plugin.instance}`;
// Test value in the shape of a key, not a real one.
const KEY = 'sk-ant-api03-' + 'T'.repeat(48) + '_e2e';

function stored(key) {
  return sql(`SELECT IFNULL((SELECT value FROM ost_config WHERE namespace='${NS}' AND \`key\`='${key}'), '<none>')`);
}

function field(page, label) {
  return page.locator(`xpath=//div[contains(@class,"form-field")][div[1][starts-with(normalize-space(.), "${label}:")]]`);
}

async function openSettings(page) {
  await page.goto(`/scp/plugins.php?id=${seed.plugin.id}&xid=${seed.plugin.instance}`);
  await page.locator('a', { hasText: 'Config' }).first().click();
  await expect(field(page, 'API key')).toBeVisible();
}

async function save(page) {
  await page.locator('input[type="submit"][value="Save Changes"]').click();
  await page.waitForLoadState('networkidle');
}

test.beforeEach(async ({ page }) => {
  sql(`DELETE FROM ost_config WHERE namespace='${NS}' AND \`key\` IN ('key_source', 'key_stored', 'key_stored_clear')`);
  await staffLogin(page, 'admin');
});

test.afterAll(() => {
  sql(`DELETE FROM ost_config WHERE namespace='${NS}' AND \`key\` IN ('key_source', 'key_stored', 'key_stored_clear')`);
});

test('stored key: encrypted with osTicket\'s secret, never shown again, kept when left empty', async ({ page }) => {
  await openSettings(page);
  await field(page, 'Where the API key comes from').locator('select').selectOption({ label: 'Stored here, encrypted' });
  await field(page, 'API key').locator('input[type="password"]').fill(KEY);
  await save(page);
  await expect(page.locator('body')).toContainText('Updated Successfully');

  expect(stored('key_source')).toContain('stored');
  const cipher = stored('key_stored');
  expect(cipher).not.toBe('<none>');
  expect(cipher).not.toContain('sk-ant');
  expect(cipher).not.toContain('TTTT');
  // osTicket's own decryption gives the key back (what the plugin reads).
  // Single quotes: the ciphertext starts with "$", which PHP would expand in double quotes.
  const literal = `'${cipher.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
  expect(php(`echo Crypto::decrypt(${literal}, SECRET_SALT, 'studio-prisma:ai-actions');`)).toBe(KEY);

  // The form never shows it: empty box, only a placeholder.
  await openSettings(page);
  const box = field(page, 'API key').locator('input[type="password"]');
  await expect(box).toHaveValue('');
  expect(await page.content()).not.toContain(KEY);

  // Saving again without typing keeps the very same value.
  await save(page);
  expect(stored('key_stored')).toBe(cipher);
});

test('stored key: something that isn\'t a key is refused, nothing changes', async ({ page }) => {
  await openSettings(page);
  await field(page, 'API key').locator('input[type="password"]').fill(KEY);
  await save(page);
  const cipher = stored('key_stored');

  await openSettings(page);
  await field(page, 'API key').locator('input[type="password"]').fill('hunter2-not-a-key');
  await save(page);
  await expect(page.locator('body')).toContainText('doesn\'t look like an API key of Anthropic');
  expect(stored('key_stored')).toBe(cipher);
});

test('stored key: the remove box deletes it; removing and pasting at once is refused', async ({ page }) => {
  await openSettings(page);
  await field(page, 'API key').locator('input[type="password"]').fill(KEY);
  await save(page);
  const cipher = stored('key_stored');

  await openSettings(page);
  await field(page, 'API key').locator('input[type="password"]').fill(KEY);
  await field(page, 'Remove the stored key').locator('input[type="checkbox"]').check();
  await save(page);
  await expect(page.locator('body')).toContainText('not both');
  expect(stored('key_stored')).toBe(cipher);

  await openSettings(page);
  await field(page, 'Remove the stored key').locator('input[type="checkbox"]').check();
  await save(page);
  await expect(page.locator('body')).toContainText('Updated Successfully');
  expect(stored('key_stored')).toBe('');
  // The box doesn't stay ticked for the next save.
  await openSettings(page);
  await expect(field(page, 'Remove the stored key').locator('input[type="checkbox"]')).not.toBeChecked();
});
