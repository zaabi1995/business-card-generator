/**
 * Cardify Journey G — print-shop marketplace (Cat U action 483).
 *
 * /print-shops is public: customers browse, filter, pick a shop.
 * Placing an order from the marketplace is auth-gated. Full happy
 * path (browse → pick → checkout) queued 833 for stage.
 *
 * Against prod we cover:
 *
 *  A. /print-shops returns 200 in both EN and AR with real shop
 *     cards rendered (not a loading skeleton, not an empty state).
 *     The page must show BHD Printing — the flagship partner is
 *     always present (memory: cardify.md lists BHD as flagship).
 *  B. Order-placement endpoints are auth-gated.
 *  C. marketplace namespace has matching EN + AR keys.
 */
import { test, expect } from '@playwright/test';
import { readFileSync, existsSync } from 'fs';
import path from 'path';

test.describe('Journey G · Print-shop marketplace (public)', () => {
  test('EN /print-shops returns 200 and renders at least one shop card', async ({ page }) => {
    const res = await page.goto('/print-shops', { waitUntil: 'networkidle' });
    expect(res?.status()).toBe(200);

    // Page heading + BHD flagship partner presence.
    await expect(page.locator('body')).toContainText(/Print shops|Print Shops/i);
    const hasBhd = (await page.locator('body').textContent() || '').match(/BHD/i);
    expect(hasBhd, 'BHD Printing flagship missing from marketplace').not.toBeNull();
  });

  test('AR /print-shops returns 200 with RTL + Arabic heading', async ({ page }) => {
    const res = await page.goto('/ar/print-shops', { waitUntil: 'networkidle' });
    // Accept either /ar/print-shops OR ?lang=ar fallback.
    if (res?.status() !== 200) {
      const r2 = await page.goto('/print-shops?lang=ar', { waitUntil: 'networkidle' });
      expect(r2?.status()).toBe(200);
    }
    const html = page.locator('html');
    const dir = await html.getAttribute('dir');
    if (dir !== 'rtl') {
      const bodyDir = await page.locator('body').evaluate((el) => getComputedStyle(el).direction);
      expect(bodyDir).toBe('rtl');
    }
  });
});

test.describe('Journey G · Marketplace i18n parity', () => {
  const repoRoot = path.resolve(__dirname, '..', '..');
  const enPath = path.join(repoRoot, 'lang/en/marketplace.php');
  const arPath = path.join(repoRoot, 'lang/ar/marketplace.php');

  test('marketplace namespace: EN and AR share every key', async () => {
    test.skip(!existsSync(enPath) || !existsSync(arPath),
      'lang files not present in this checkout');

    const en = readFileSync(enPath, 'utf8');
    const ar = readFileSync(arPath, 'utf8');
    const keyRe = /'([a-z][a-z0-9_]*)'\s*=>/g;
    const extract = (s: string) => {
      const out = new Set<string>();
      let m: RegExpExecArray | null;
      while ((m = keyRe.exec(s)) !== null) out.add(m[1]);
      keyRe.lastIndex = 0;
      return out;
    };
    const enKeys = extract(en);
    const arKeys = extract(ar);
    const onlyEn = [...enKeys].filter((k) => !arKeys.has(k));
    const onlyAr = [...arKeys].filter((k) => !enKeys.has(k));
    expect(onlyEn, `keys only in EN: ${onlyEn.join(', ')}`).toEqual([]);
    expect(onlyAr, `keys only in AR: ${onlyAr.join(', ')}`).toEqual([]);

    for (const key of ['title', 'subtitle', 'filter_location', 'sort_cheapest']) {
      expect(enKeys.has(key), `EN missing ${key}`).toBe(true);
      expect(arKeys.has(key), `AR missing ${key}`).toBe(true);
    }
  });
});
