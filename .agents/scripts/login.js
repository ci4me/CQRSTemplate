/**
 * Playwright login script for CQRSTemplate.
 *
 * Automates browser login via CDP so Devin sessions can skip manual login.
 * Cookies/auth persist in the browser after the script finishes.
 *
 * Usage:
 *   node .agents/scripts/login.js [email] [password]
 *
 * Defaults to admin@example.com / password123 (seeded accounts).
 * Requires the dev server running on http://localhost:8080.
 */

const { chromium } = require('playwright');

const CDP_URL = 'http://localhost:29229';
const BASE_URL = 'http://localhost:8080';

const email = process.argv[2] || 'admin@example.com';
const password = process.argv[3] || 'password123';

(async () => {
    const browser = await chromium.connectOverCDP(CDP_URL);
    const context = browser.contexts()[0];
    const page = context.pages()[0] || await context.newPage();

    await page.goto(`${BASE_URL}/auth/login`);
    await page.waitForSelector('input[name="email"]');

    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', password);
    await page.click('button[type="submit"]');

    await page.waitForURL('**/dashboard**', { timeout: 10000 });

    console.log(`Logged in as ${email} — session active in browser.`);

    // Don't close browser — session persists for manual/automated use.
})();
