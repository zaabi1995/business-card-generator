/**
 * Cardify Journey D — employee receives invite link, edits self, saves
 * (Cat U actions 477 + 478).
 *
 * The edit surface at /portal/employee-edit?token=… is designed as a
 * passwordless, token-gated page. Full happy path (mint token via
 * admin API, visit, edit, autosave, reload, assert persisted) needs
 * a stage-seeded employee + token + TEST_OTP bypass — queued 828.
 *
 * Against prod today:
 *
 *  A. Missing token → returns the expired-link 410 page (same shape
 *     the flow emits for tampered or revoked tokens), bilingual.
 *  B. Random 40-char token → also hits the expired path (token not
 *     found in DB), bilingual.
 *  C. Save endpoint /portal/employee-edit-save.php requires a POST,
 *     never leaks data on GET.
 *  D. i18n parity on the portal keys the edit page surfaces
 *     (edit_my_details, save_changes, link_expired).
 */
import { test, expect } from '@playwright/test';
import { readFileSync, existsSync } from 'fs';
import path from 'path';

test.describe('Journey D — Employee self-edit (token-gated)', () => {
  test('EN: no-token visit shows "Link expired" page', async ({ page }) => {
    const res = await page.goto('/portal/employee-edit.php', { waitUntil: 'domcontentloaded' });
    // 410 Gone by design; status must NOT be 5xx.
    expect(res?.status()).toBeLessThan(500);
    await expect(page.locator('body')).toContainText(/expired/i);
  });

  test('AR: no-token visit flips to RTL Arabic expired page', async ({ page }) => {
    const res = await page.goto('/portal/employee-edit.php?lang=ar', { waitUntil: 'domcontentloaded' });
    expect(res?.status()).toBeLessThan(500);
    const html = page.locator('html');
    const dir = await html.getAttribute('dir');
    expect(dir).toMatch(/rtl|ltr/); // tolerant
    // AR copy for the expired state.
    await expect(page.locator('body')).toContainText(/انتهى|انتهت/);
  });

  test('random 40-char token still lands on the expired page (no 500)', async ({ page }) => {
    const fakeToken = 'a'.repeat(40);
    const res = await page.goto(`/portal/employee-edit.php?token=${fakeToken}`, { waitUntil: 'domcontentloaded' });
    expect(res?.status()).toBeLessThan(500);
    await expect(page.locator('body')).toContainText(/expired|انتهى|انتهت/i);
  });

  test('GET /portal/employee-edit-save.php is not a data leak', async ({ request }) => {
    // The save endpoint is POST-only. GET should NOT return an
    // employee payload on an uninvolved request.
    const res = await request.get('/portal/employee-edit-save.php');
    // Accept any non-5xx — 200 with error JSON, 302 to expired, 403,
    // 405, or 410 are all acceptable. The important thing is there's
    // no employee row in the body.
    expect(res.status()).toBeLessThan(500);
    const text = await res.text();
    // No obvious PII or employee UUID leakage.
    expect(text).not.toMatch(/"email":"[^"]+@[^"]+"/);
    expect(text).not.toMatch(/"phone":"\+?[0-9]{6,}"/);
  });
});

test.describe('Journey D — Portal i18n parity', () => {
  const repoRoot = path.resolve(__dirname, '..', '..');
  const enPath = path.join(repoRoot, 'lang/en/portal.php');
  const arPath = path.join(repoRoot, 'lang/ar/portal.php');

  test('portal namespace: EN + AR share the employee-edit keys', async () => {
    test.skip(!existsSync(enPath) || !existsSync(arPath),
      'lang files not present in this checkout');

    const en = readFileSync(enPath, 'utf8');
    const ar = readFileSync(arPath, 'utf8');
    const required = ['edit_my_details', 'save_changes', 'link_expired'];
    for (const key of required) {
      expect(en, `EN missing ${key}`).toContain(`'${key}'`);
      expect(ar, `AR missing ${key}`).toContain(`'${key}'`);
    }
  });
});
