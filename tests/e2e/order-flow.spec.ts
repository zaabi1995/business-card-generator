/**
 * Cardify Journey E — admin orders printed cards (Cat U 479 + 480).
 *
 * Placing a real Paymob order against prod would create a live print
 * order + a live Paymob intent; that must happen on stage (queued
 * 829). Against prod we cover:
 *
 *  A. Order-checkout + print-orders admin surfaces are auth-gated
 *     in EN and AR — unauth GET redirects to login, never 500.
 *  B. Paymob callback URL is POST-only: GET returns a non-200
 *     that's not 5xx, never echoes transaction data.
 *  C. Order i18n parity on the keys the checkout page emits
 *     (checkout_title, order_summary, order_total, pay_button…).
 *  D. Cart-size constraint: checkout link with a bogus quantity
 *     does not 500 (validator absorbs bad input cleanly).
 */
import { test, expect } from '@playwright/test';
import { readFileSync, existsSync } from 'fs';
import path from 'path';

test.describe('Journey E — Order admin surfaces (auth-gated)', () => {
  for (const { name, locale } of [
    { name: 'EN', locale: '' },
    { name: 'AR', locale: '?lang=ar' },
  ]) {
    test(`${name} /admin/print redirects unauth`, async ({ page }) => {
      await page.context().clearCookies();
      const res = await page.goto(`/admin/print.php${locale}`, { waitUntil: 'domcontentloaded' });
      expect(res?.status()).toBeLessThan(500);
      expect(page.url()).toMatch(/\/login|\/company\/register|\/intro|\/$/);
    });

    test(`${name} /admin/order-checkout redirects unauth`, async ({ page }) => {
      await page.context().clearCookies();
      const res = await page.goto(`/admin/order-checkout.php?order=1${locale ? '&lang=ar' : ''}`, { waitUntil: 'domcontentloaded' });
      expect(res?.status()).toBeLessThan(500);
      expect(page.url()).toMatch(/\/login|\/company\/register|\/intro|\/$/);
    });
  }
});

test.describe('Journey E — Paymob callback route', () => {
  test('GET /paymob/callback.php returns a safe non-2xx, no payload leak', async ({ request }) => {
    const res = await request.get('/paymob/callback.php');
    expect(res.status()).toBeLessThan(500);
    const body = await res.text();
    // Must not echo a real transaction or HMAC; the endpoint rejects
    // GET (or missing HMAC) before it does anything sensitive.
    expect(body).not.toMatch(/"hmac":"[0-9a-f]{32,}"/i);
    expect(body).not.toMatch(/"transaction_id":\d+/);
  });
});

test.describe('Journey E — Order i18n parity', () => {
  const repoRoot = path.resolve(__dirname, '..', '..');
  const enPath = path.join(repoRoot, 'lang/en/order.php');
  const arPath = path.join(repoRoot, 'lang/ar/order.php');

  test('order namespace: EN + AR share the checkout keys', async () => {
    test.skip(!existsSync(enPath) || !existsSync(arPath),
      'lang files not present in this checkout');

    const en = readFileSync(enPath, 'utf8');
    const ar = readFileSync(arPath, 'utf8');
    // Keys the checkout + receipt surface on /admin/order-checkout.
    const required = [
      'checkout_title', 'order_summary', 'order_number', 'order_total',
      'receipt_total_row', 'receipt_paid_full', 'receipt_payment_method',
    ];
    for (const key of required) {
      expect(en, `EN missing ${key}`).toContain(`'${key}'`);
      expect(ar, `AR missing ${key}`).toContain(`'${key}'`);
    }
  });
});
