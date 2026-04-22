/**
 * Cardify Journey F — analytics + CSV export (Cat U action 482).
 *
 * Analytics dashboard at /admin/analytics.php is auth-gated and
 * pulls time-windowed data. Full happy path (load, filter by month,
 * click export, assert a CSV downloaded with the expected headers)
 * needs a signed-in admin on stage — queued 832.
 *
 * Against prod we cover:
 *   A. /admin/analytics auth-gated in EN + AR.
 *   B. analytics i18n parity covering every KPI key and the
 *      export_csv action, so a CI run fails if a new metric ships
 *      without its Arabic label.
 */
import { test, expect } from '@playwright/test';
import { readFileSync, existsSync } from 'fs';
import path from 'path';

test.describe('Journey F · Analytics (auth-gated)', () => {
  for (const { name, locale } of [
    { name: 'EN', locale: '' },
    { name: 'AR', locale: '?lang=ar' },
  ]) {
    test(`${name} /admin/analytics redirects unauth`, async ({ page }) => {
      await page.context().clearCookies();
      const res = await page.goto(`/admin/analytics.php${locale}`, { waitUntil: 'domcontentloaded' });
      expect(res?.status()).toBeLessThan(500);
      expect(page.url()).toMatch(/\/login|\/company\/register|\/intro|\/$/);
    });
  }
});

test.describe('Journey F · Analytics i18n parity', () => {
  const repoRoot = path.resolve(__dirname, '..', '..');
  const enPath = path.join(repoRoot, 'lang/en/analytics.php');
  const arPath = path.join(repoRoot, 'lang/ar/analytics.php');

  test('every KPI + export key present in EN and AR', async () => {
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

    // Bidirectional set diff — the full namespace must match.
    const onlyEn = [...enKeys].filter((k) => !arKeys.has(k));
    const onlyAr = [...arKeys].filter((k) => !enKeys.has(k));
    expect(onlyEn, `analytics keys only in EN: ${onlyEn.join(', ')}`).toEqual([]);
    expect(onlyAr, `analytics keys only in AR: ${onlyAr.join(', ')}`).toEqual([]);

    // Sanity: the required headline keys must all be present.
    for (const key of ['title', 'kpi_total_taps', 'kpi_unique', 'export_csv']) {
      expect(enKeys.has(key), `EN missing ${key}`).toBe(true);
      expect(arKeys.has(key), `AR missing ${key}`).toBe(true);
    }
  });
});
