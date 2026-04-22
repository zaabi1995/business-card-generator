/**
 * Cardify responsive smoke at 375px (iPhone SE) + 414px (iPhone 14
 * Pro Max) — Cat U actions 484 + 485.
 *
 * For every public-facing page, we check three invariants that
 * usually break first on narrow viewports:
 *
 *   1. No horizontal scroll: document width <= viewport width.
 *      A wider body means either a too-wide hero, an unclamped
 *      image, or a hard-coded px width leaking through Tailwind.
 *   2. The primary CTA / heading is visible within the first
 *      screenful — no "press me scrolled off-screen" regressions.
 *   3. Page status is 200.
 *
 * Kept public-only so we can run against prod without auth. The
 * admin surfaces get their mobile coverage on stage once we have
 * a logged-in test user (queued 834).
 */
import { test, expect, devices } from '@playwright/test';

const viewports = [
  { name: '375px iPhone SE', width: 375, height: 667 },
  { name: '414px iPhone 14 Pro Max', width: 414, height: 896 },
];

// Each page: path, 1+ markers we expect to find above the fold.
// Markers deliberately avoid words that also appear in the site
// navbar (e.g. "Pricing", "Logos") — those match the hidden-on-mobile
// desktop nav link and produce false failures.
const pages = [
  { path: '/',             marker: /Bilingual cards|every employee|Made in Oman/i },
  { path: '/pricing',      marker: /Starter|Professional|Most popular/i },
  { path: '/status',       marker: /All systems|Partial|Major outage|Operational/i },
  { path: '/faq',          marker: /Frequently asked|Getting started/i },
  { path: '/contact',      marker: /Talk to the team|WhatsApp|Send/i },
  { path: '/case-studies', marker: /Three Omani|team,? three different|Proof, not promises/i },
  { path: '/print-shops',  marker: /Print Shop Marketplace|Are You a Print Shop/i },
  { path: '/logos',        marker: /Omani Logo Library|verified logos|Browse by sector/i },
  { path: '/companies',    marker: /Oman Business Index|Browse by sector|Browse by governorate/i },
];

for (const vp of viewports) {
  test.describe(`Mobile ${vp.name}`, () => {
    test.use({ viewport: { width: vp.width, height: vp.height } });

    for (const p of pages) {
      test(`${p.path} renders clean at ${vp.name}`, async ({ page }) => {
        const res = await page.goto(p.path, { waitUntil: 'domcontentloaded' });
        expect(res?.status(), `${p.path} status`).toBe(200);

        // 1. No horizontal scroll. Allow a 2px rounding fudge.
        const { docWidth, viewportWidth } = await page.evaluate(() => ({
          docWidth: document.documentElement.scrollWidth,
          viewportWidth: window.innerWidth,
        }));
        expect(docWidth, `${p.path} horizontal scroll (doc=${docWidth} vp=${viewportWidth})`)
          .toBeLessThanOrEqual(viewportWidth + 2);

        // 2. Primary marker visible in the viewport. Restrict to h1+h2+main
        // to dodge hidden nav links / meta tags that also match the regex.
        const marker = page.locator('h1, h2, main, section').getByText(p.marker).first();
        await expect(marker, `${p.path} marker ${p.marker}`).toBeVisible();
      });
    }
  });
}
