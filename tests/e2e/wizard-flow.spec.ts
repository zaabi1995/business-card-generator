/**
 * Cardify Journey B — onboarding wizard (Cat U actions 473 + 474).
 *
 * The real 7-step wizard lives at /admin/onboarding and is fully
 * auth-gated. Full happy-path E2E needs a logged-in test company,
 * which will live on stage.cardify.om once action 822 lands DNS and
 * 825 lands the test-OTP bypass. Queued as action 826.
 *
 * What we CAN verify against prod today:
 *
 *  A. The auth gate actually gates: unauthenticated GET redirects to
 *     /login (or returns a non-200 that isn't 500).
 *  B. Every i18n key the wizard uses is present in BOTH lang/en and
 *     lang/ar onboarding namespaces — caught at CI time before a
 *     deploy can ship with a missing translation.
 *
 * Both checks are read-only and safe to run against live.
 */
import { test, expect, request } from '@playwright/test';
import { readFileSync, existsSync } from 'fs';
import path from 'path';

test.describe('Journey B — Onboarding wizard (auth-gated)', () => {
  test('EN /admin/onboarding redirects unauthenticated users', async ({ page }) => {
    // Start detached from any cookies
    await page.context().clearCookies();
    const response = await page.goto('/admin/onboarding.php', { waitUntil: 'domcontentloaded' });
    // Either redirected to login or landed on the login page itself.
    expect(response?.status()).toBeLessThan(500);
    const finalUrl = page.url();
    expect(finalUrl).toMatch(/\/login|\/company\/register|\/intro|\/$/);
  });

  test('AR /admin/onboarding redirects unauthenticated users', async ({ page }) => {
    await page.context().clearCookies();
    const response = await page.goto('/admin/onboarding.php?lang=ar', { waitUntil: 'domcontentloaded' });
    expect(response?.status()).toBeLessThan(500);
    expect(page.url()).toMatch(/\/login|\/company\/register|\/intro|\/$/);
  });
});

/**
 * Local-only CI-style check: walk every `step_*` key the wizard reads
 * and make sure EN and AR both define it. Runs against the repo
 * files, not HTTP, so it's effectively a unit test smuggled into the
 * E2E suite — cheap, fast, and catches the #1 onboarding bug (ship
 * a new step, forget the AR translation).
 */
test.describe('Journey B — Onboarding i18n parity', () => {
  const repoRoot = path.resolve(__dirname, '..', '..');
  const enPath = path.join(repoRoot, 'lang/en/onboarding.php');
  const arPath = path.join(repoRoot, 'lang/ar/onboarding.php');

  test('wizard i18n keys: EN and AR both define every step_* key', async () => {
    // Skip when running in an environment that doesn't have the repo
    // checked out (e.g. a downstream CI that only has the built
    // artefact). Keeps the suite portable.
    test.skip(!existsSync(enPath) || !existsSync(arPath),
      'lang files not present in this checkout');

    const en = readFileSync(enPath, 'utf8');
    const ar = readFileSync(arPath, 'utf8');
    const keyRe = /'(step_[a-z0-9_]+)'\s*=>/g;
    const enKeys = new Set<string>();
    const arKeys = new Set<string>();
    let m: RegExpExecArray | null;
    while ((m = keyRe.exec(en)) !== null) enKeys.add(m[1]);
    keyRe.lastIndex = 0;
    while ((m = keyRe.exec(ar)) !== null) arKeys.add(m[1]);

    // Bidirectional set diff — each side must cover the other's keys.
    const onlyEn = [...enKeys].filter((k) => !arKeys.has(k));
    const onlyAr = [...arKeys].filter((k) => !enKeys.has(k));
    expect(onlyEn, `keys only in EN: ${onlyEn.join(', ')}`).toEqual([]);
    expect(onlyAr, `keys only in AR: ${onlyAr.join(', ')}`).toEqual([]);

    // Sanity floor: the wizard spec calls for 7 steps + a couple of
    // helpers, so both locales should expose at least that many
    // step_* keys.
    expect(enKeys.size).toBeGreaterThanOrEqual(7);
  });
});
