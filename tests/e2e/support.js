// Shared helpers. Credentials and IDs come from the files dev/up.sh writes
// into dev/local/ (git-ignored). Nothing secret lives in the repo.
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { expect } = require('@playwright/test');

const LOCAL = path.resolve(__dirname, '../../dev/local');
const COMPOSE = path.resolve(__dirname, '../../dev/docker-compose.yml');

function readEnv(file) {
  const out = {};
  if (!fs.existsSync(file)) return out;
  for (const line of fs.readFileSync(file, 'utf8').split(/\r?\n/)) {
    const m = line.match(/^([A-Z0-9_]+)=(.*)$/);
    if (m) out[m[1]] = m[2];
  }
  return out;
}

const env = Object.assign(readEnv(path.join(LOCAL, 'credentials.env')), process.env);
const seedFile = path.join(LOCAL, 'seed.json');
const seed = fs.existsSync(seedFile) ? JSON.parse(fs.readFileSync(seedFile, 'utf8')) : null;

function requireSeed() {
  if (!seed) throw new Error('dev/local/seed.json missing, run dev/up.sh first');
  return seed;
}

/** Collects uncaught page errors and console errors. */
function watchErrors(page) {
  const errors = [];
  page.on('pageerror', (e) => errors.push(`pageerror: ${e.message}`));
  page.on('console', (m) => {
    if (m.type() === 'error') errors.push(`console: ${m.text()}`);
  });
  return errors;
}

const logins = {
  admin: () => [env.SPA_ADMIN_USER, env.SPA_ADMIN_PASS],
  philipp: () => ['philipp', env.SPA_AGENT_PASS],
  salesagent: () => [env.SPA_AGENT_USER, env.SPA_AGENT_PASS],
};

async function staffLogin(page, who) {
  const [user, pass] = logins[who]();
  await page.goto('/scp/login.php');
  await page.fill('input[name="userid"]', user);
  await page.fill('input[name="passwd"]', pass);
  await page.click('#login button[type="submit"]');
  await page.waitForURL(/\/scp\/(index|tickets)\.php|\/scp\/$/);
}

async function openTicket(page, key) {
  await page.goto(`/scp/tickets.php?id=${requireSeed().tickets[key]}`);
  await expect(page.locator('#ticketThread')).toBeVisible();
}

/** POST to the plugin with the page's session and CSRF token. */
async function aiPost(page, path, data) {
  const token = await page.locator('meta[name="csrf_token"]').getAttribute('content');
  const res = await page.request.post(`/scp/ajax.php/ai${path}`, {
    form: Object.assign({ __CSRFToken__: token }, data),
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  let json = {};
  try { json = await res.json(); } catch (e) { /* not JSON */ }
  return { status: res.status(), json };
}

/**
 * Runs SQL in the test database. Only for state the UI can't set up quickly,
 * e.g. resetting a due date or expiring a plan.
 */
function sql(statement) {
  return execFileSync('docker', [
    'compose', '--env-file', path.join(LOCAL, 'credentials.env'), '-f', COMPOSE,
    'exec', '-T', 'db', 'mariadb', '-uosticket', `-p${env.SPA_DB_PASSWORD}`, 'osticket', '-N', '-e', statement,
  ], { encoding: 'utf8' }).trim();
}

module.exports = { env, seed, requireSeed, watchErrors, staffLogin, openTicket, aiPost, sql, LOCAL };
