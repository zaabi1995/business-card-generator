/**
 * Cardify slow-3G performance smoke (Cat U action 489).
 *
 * Throttles the Chromium network to "slow 3G" profile and asserts
 * critical public pages:
 *   - Respond OK (status 200).
 *   - Paint the above-the-fold marker within a reasonable budget.
 *   - Don't time-out the script at the hard 30s wall.
 *
 * Budgets are deliberately loose — this is a slow-3G run, the goal
 * is "does the site still function at bad connectivity", not "meet
 * LCP targets". CWV happy path lives in iter 51 / action 432.
 *
 * Profile (Chrome DevTools Slow 3G):
 *   download 400 Kbps | upload 400 Kbps | latency 400 ms
 *
 * Safari iOS + Chrome Android use their own CDP dialects; this
 * spec pins to chromium so we don't chase browser-specific
 * throttling APIs.
 */
import { test, expect } from '@playwright/test';

// Kbps → bytes/sec. Playwright takes bps.
const KBPS = (k: number) => (k * 1000) / 8;

const SLOW_3G = {
  offline: false,
  downloadThroughput: KBPS(400),
  uploadThroughput: KBPS(400),
  latency: 400, // ms per round-trip
};

const pages = [
  { path: '/',           marker: /Bilingual cards|every employee|Made in Oman/i, budgetMs: 25000 },
  { path: '/api/health', marker: /"status":"up"/, budgetMs: 15000 },
  { path: '/pricing',    marker: /Starter|Professional/i, budgetMs: 25000 },
  { path: '/status',     marker: /All systems|Operational|Partial|Outage/i, budgetMs: 20000 },
];

test.describe.configure({ mode: 'serial' });

test.describe('Slow-3G smoke (chromium)', () => {
  test.skip(({ browserName }) => browserName !== 'chromium',
    'Slow-3G throttling is a Chrome DevTools Protocol feature; pin to chromium');

  for (const p of pages) {
    test(`${p.path} loads + marker visible within ${p.budgetMs}ms on slow-3G`, async ({ page, context }) => {
      // Attach CDP + enable Network, then apply conditions.
      const client = await context.newCDPSession(page);
      await client.send('Network.enable');
      await client.send('Network.emulateNetworkConditions', SLOW_3G);

      const t0 = Date.now();
      const res = await page.goto(p.path, { waitUntil: 'domcontentloaded', timeout: p.budgetMs });
      expect(res?.status(), `${p.path} status on slow-3G`).toBe(200);

      // Marker lookup; tolerant if the matcher is a string pattern
      // rather than a visible element (e.g. /api/health is JSON).
      if (p.path === '/api/health') {
        const body = await res?.text() ?? '';
        expect(body).toMatch(p.marker as RegExp);
      } else {
        const marker = page.locator('h1, h2, main, section').getByText(p.marker).first();
        await expect(marker, `${p.path} marker not visible on slow-3G`)
          .toBeVisible({ timeout: p.budgetMs });
      }

      const elapsed = Date.now() - t0;
      // Record into the test report as an attachment for trend analysis.
      await test.info().attach('slow-3g-timing', {
        body: JSON.stringify({ path: p.path, elapsed_ms: elapsed, budget_ms: p.budgetMs }),
        contentType: 'application/json',
      });
      expect(elapsed, `${p.path} took ${elapsed}ms`).toBeLessThan(p.budgetMs);
    });
  }
});
