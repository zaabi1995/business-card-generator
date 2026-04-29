/**
 * Cardify NFC write-flow auth gate (Cat U action 490, automatable slice).
 *
 * The physical NFC tag-write itself needs Chrome Android + an NTAG-
 * compatible sticker + a phone. That's manual QA — see
 * ops/qa-nfc-manual.md for the tag-programming procedure. Queued as
 * action 835 in the Appended section.
 *
 * Against prod we assert what's automatable:
 *   A. /admin/nfc/batch and /admin/nfc/write are auth-gated and
 *      redirect unauth in EN + AR.
 *   B. POST /admin/nfc/mark-programmed is not a GET-leaky endpoint.
 */
import { test, expect } from '@playwright/test';

test.describe('NFC write — auth gate', () => {
  for (const { name, locale } of [
    { name: 'EN', locale: '' },
    { name: 'AR', locale: '?lang=ar' },
  ]) {
    test(`${name} /admin/nfc/batch redirects unauth`, async ({ page }) => {
      await page.context().clearCookies();
      const res = await page.goto(`/admin/nfc/batch.php${locale}`, { waitUntil: 'domcontentloaded' });
      expect(res?.status()).toBeLessThan(500);
      expect(page.url()).toMatch(/\/login|\/company\/register|\/intro|\/$/);
    });

    test(`${name} /admin/nfc/write redirects unauth`, async ({ page }) => {
      await page.context().clearCookies();
      const res = await page.goto(`/admin/nfc/write.php?eid=00000000-0000-0000-0000-000000000000${locale ? '&lang=ar' : ''}`, { waitUntil: 'domcontentloaded' });
      expect(res?.status()).toBeLessThan(500);
      expect(page.url()).toMatch(/\/login|\/company\/register|\/intro|\/$/);
    });
  }

  test('mark-programmed endpoint does not leak on GET', async ({ request }) => {
    const res = await request.get('/admin/nfc/mark-programmed.php');
    expect(res.status()).toBeLessThan(500);
    const body = await res.text();
    expect(body).not.toMatch(/"employee_id":"[0-9a-f-]{20,}"/);
    expect(body).not.toMatch(/"tag_uid":"[0-9a-f]{6,}"/i);
  });
});
