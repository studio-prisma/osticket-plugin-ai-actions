// @ts-check
// AC-12: the chat in the Ticket Board's quick view. Runs only when the stack
// has the board plugin mounted (dev/local/compose.override.yml, see
// dev/README.md); CI checks it out next to this repository.
const { test, expect } = require('@playwright/test');
const { requireSeed, watchErrors, staffLogin, sql } = require('./support');

const seed = requireSeed();

test.skip(!seed.board, 'Ticket Board plugin not mounted in the test stack');

test.beforeEach(() => {
  sql('DELETE FROM ost_ai_actions_chat');
});

test('AC-12: the chat follows the card in the quick view and makes room for the drawer', async ({ page }) => {
  const errors = watchErrors(page);
  await staffLogin(page, 'admin');
  await page.goto('/scp/ajax.php/board');
  await expect(page.locator('.spb-board')).toBeVisible();

  await page.locator('#spa-ai-launcher').click();
  const chat = page.locator('#spa-ai');
  await expect(chat.locator('.spa-ai-hint')).toContainText('Open a ticket');
  const rightClosed = await chat.evaluate((n) => parseInt(n.style.right, 10));

  await page.locator(`.spb-card[data-ticket-id="${seed.tickets.T1}"]`).click();
  await expect(page.locator(`.spb-drawer.spb-open .spb-panel-body[data-ticket-id="${seed.tickets.T1}"]`)).toBeVisible();
  await expect(chat.locator('.spa-ai-subtitle')).toContainText(`#${seed.numbers.T1}`);
  await expect.poll(() => chat.evaluate((n) => parseInt(n.style.right, 10))).toBeGreaterThan(rightClosed + 100);

  // Window and drawer don't overlap.
  const drawerBox = await page.locator('.spb-drawer.spb-open').boundingBox();
  const chatBox = await chat.boundingBox();
  expect(chatBox.x + chatBox.width).toBeLessThanOrEqual(drawerBox.x);

  await chat.locator('.spa-ai-input').fill('What is this ticket about?');
  await chat.locator('.spa-ai-input').press('Enter');
  await expect(chat.locator('.spa-ai-msg-ai')).toHaveCount(1);

  // Another card: another conversation. The open window can cover cards in
  // the right-hand column, so the click goes to the card directly.
  await page.locator(`.spb-card[data-ticket-id="${seed.tickets.T2}"]`).dispatchEvent('click');
  await expect(chat.locator('.spa-ai-subtitle')).toContainText(`#${seed.numbers.T2}`);
  await expect(chat.locator('.spa-ai-msg')).toHaveCount(0);
  expect(errors).toEqual([]);
});
