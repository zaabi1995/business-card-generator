/**
 * Cardify Journey C — design template EN + AR (Cat U 475 + 476).
 *
 * The template editor at /admin/theme.php is auth-gated and
 * Fabric.js-heavy; the full "open editor, add layer, save, preview"
 * walk belongs on stage.cardify.om with a logged-in test company
 * (queued 827). Against prod we cover:
 *
 *   A. Auth gate: unauthenticated /admin/theme.php redirects to
 *      login, both EN and AR, never 500.
 *   B. Public PREVIEW of a real saved template: the KNOWN_CARD
 *      fixture points at a live employee whose card renders from a
 *      template, so hitting it proves the template→digital_card
 *      render pipeline still works end-to-end even if we can't
 *      exercise the editor itself.
 *   C. Template-related i18n keys exist in both lang/en/admin.php
 *      and lang/ar/admin.php so a missing translation (nav_templates,
 *      new_template, etc.) is caught before a deploy ships.
 */
import { test, expect } from '@playwright/test';
import { KNOWN_CARD, cardPath } from './fixtures';
import { readFileSync, existsSync } from 'fs';
import path from 'path';

test.describe('Journey C — Template editor (auth-gated)', () => {
  test('EN /admin/theme.php redirects unauthenticated', async ({ page }) => {
    await page.context().clearCookies();
    const res = await page.goto('/admin/theme.php', { waitUntil: 'domcontentloaded' });
    expect(res?.status()).toBeLessThan(500);
    expect(page.url()).toMatch(/\/login|\/company\/register|\/intro|\/$/);
  });

  test('AR /admin/theme.php redirects unauthenticated', async ({ page }) => {
    await page.context().clearCookies();
    const res = await page.goto('/admin/theme.php?lang=ar', { waitUntil: 'domcontentloaded' });
    expect(res?.status()).toBeLessThan(500);
    expect(page.url()).toMatch(/\/login|\/company\/register|\/intro|\/$/);
  });
});

test.describe('Journey C — Public template preview', () => {
  test('KNOWN_CARD renders the EN template variant', async ({ page }) => {
    const res = await page.goto(cardPath(), { waitUntil: 'networkidle' });
    expect(res?.status()).toBe(200);
    // Name baked into the template render
    await expect(page.getByText(KNOWN_CARD.name, { exact: false })).toBeVisible();
    // Save-contact vCard anchor proves the template wired the
    // employee → card action.
    const vcard = page.locator('a[href*=".vcf"], a[download*="vcf"], a:has-text("Save")').first();
    await expect(vcard).toBeVisible();
  });

  test('KNOWN_CARD renders the AR template variant with dir=rtl', async ({ page }) => {
    const res = await page.goto(cardPath() + '?lang=ar', { waitUntil: 'networkidle' });
    expect(res?.status()).toBe(200);
    const html = page.locator('html');
    const dir = await html.getAttribute('dir');
    if (dir !== 'rtl') {
      const bodyDir = await page.locator('body').evaluate((el) => getComputedStyle(el).direction);
      expect(bodyDir).toBe('rtl');
    }
    // Template must still surface a contact/save affordance. The
    // current card template keeps the English "Save Contact" label
    // even in the AR render (employee-level, not i18n-keyed), so
    // accept either localisation.
    const vcard = page.locator(
      'a[href*=".vcf"], a[download], a:has-text("حفظ"), a:has-text("Save")'
    ).first();
    await expect(vcard).toBeVisible();
  });
});

test.describe('Journey C — Template i18n parity', () => {
  const repoRoot = path.resolve(__dirname, '..', '..');
  const enPath = path.join(repoRoot, 'lang/en/admin.php');
  const arPath = path.join(repoRoot, 'lang/ar/admin.php');

  test('admin namespace: EN + AR share the template-editor keys', async () => {
    test.skip(!existsSync(enPath) || !existsSync(arPath),
      'lang files not present in this checkout');

    const en = readFileSync(enPath, 'utf8');
    const ar = readFileSync(arPath, 'utf8');
    // Any key that mentions template / theme / editor / preview — these
    // are the ones the template editor surfaces.
    const keyRe = /'(nav_templates|new_template|templates|theme[a-z_]*|editor[a-z_]*|preview[a-z_]*|save_template|duplicate_template|delete_template)'\s*=>/g;
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
    expect(onlyEn, `template keys only in EN: ${onlyEn.join(', ')}`).toEqual([]);
    expect(onlyAr, `template keys only in AR: ${onlyAr.join(', ')}`).toEqual([]);
    // The admin surface always carries at least nav_templates +
    // new_template; a drop below that means someone deleted without
    // replacement.
    expect(enKeys.size).toBeGreaterThanOrEqual(2);
  });
});
