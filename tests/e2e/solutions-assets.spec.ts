/**
 * /solutions/ landing pages asset-path regression guard (10 Jun 2026 audit
 * fix G5). The 20 solutions/*.php pages render the shared header loader and
 * footer logo via getBasePath(). 'solutions' was missing from the getBasePath
 * $appDirs whitelist, so it returned '/solutions/' and the assets resolved to
 * /solutions/assets/images/... (404, polluting the nginx error log + a broken
 * loader). The fix adds 'solutions' (and 'views') to $appDirs.
 *
 * We assert the page emits ROOT-relative asset paths and that those assets
 * resolve 200, and that no /solutions/assets/... ref remains.
 *
 * Run: BASE_URL=https://cardify.om npx playwright test solutions-assets
 */
import { test, expect } from '@playwright/test';

const PAGE =
  process.env.SOLUTIONS_PAGE ||
  '/solutions/business-cards-for-ramadan-networking';

test.describe('Solutions landing-page assets', () => {
  test('emits root-relative asset paths, none under /solutions/assets', async ({
    request,
  }) => {
    const res = await request.get(PAGE);
    expect(res.status()).toBe(200);
    const html = await res.text();

    // The broken pattern must be gone entirely.
    expect(html).not.toContain('/solutions/assets/');

    // The loader + logo must be referenced at the site root.
    expect(html).toMatch(/src="\/assets\/images\/cardify-loader\.svg"/);
    expect(html).toMatch(/src="\/assets\/images\/logo(-light)?\.svg"/);
  });

  test('referenced loader + logo assets resolve 200', async ({ request }) => {
    for (const asset of [
      '/assets/images/cardify-loader.svg',
      '/assets/images/logo.svg',
      '/assets/images/logo-light.svg',
    ]) {
      const res = await request.get(asset);
      expect(res.status(), `${asset} should resolve`).toBe(200);
    }
  });
});
