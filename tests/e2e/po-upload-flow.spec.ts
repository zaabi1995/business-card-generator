/**
 * Cardify Journey E via PO upload — AR variant (Cat U action 481).
 *
 * Credit-account PO upload is a credit-qualified checkout path:
 * customer ticks "pay by credit", attaches a PO number + scanned
 * PO document, and the order goes on the credit ledger. Live flow
 * needs an authenticated admin with an approved credit account;
 * queued as action 831 on stage.
 *
 * Against prod we cover:
 *   A. /admin/credit-accounts auth-gated in EN + AR (redirect to
 *      login, never 500).
 *   B. /admin/order-checkout.php?order=1 auth-gated (same).
 *   C. Order i18n parity on the full PO vocab: po_title, po_number
 *      plus placeholder, po_document + hint, po_uploaded, po_view.
 *      If AR is missing any, the PO modal silently falls back to the
 *      English label and the form looks broken to Arabic customers.
 */
import { test, expect } from '@playwright/test';
import { readFileSync, existsSync } from 'fs';
import path from 'path';

test.describe('Journey E · PO upload (auth-gated)', () => {
  test('AR /admin/credit-accounts redirects unauth', async ({ page }) => {
    await page.context().clearCookies();
    const res = await page.goto('/admin/credit-accounts.php?lang=ar', { waitUntil: 'domcontentloaded' });
    expect(res?.status()).toBeLessThan(500);
    expect(page.url()).toMatch(/\/login|\/company\/register|\/intro|\/$/);
  });

  test('AR /admin/order-checkout redirects unauth', async ({ page }) => {
    await page.context().clearCookies();
    const res = await page.goto('/admin/order-checkout.php?order=1&lang=ar', { waitUntil: 'domcontentloaded' });
    expect(res?.status()).toBeLessThan(500);
    expect(page.url()).toMatch(/\/login|\/company\/register|\/intro|\/$/);
  });
});

test.describe('Journey E · PO i18n parity', () => {
  const repoRoot = path.resolve(__dirname, '..', '..');
  const enPath = path.join(repoRoot, 'lang/en/order.php');
  const arPath = path.join(repoRoot, 'lang/ar/order.php');

  test('order namespace: every PO key present in EN and AR', async () => {
    test.skip(!existsSync(enPath) || !existsSync(arPath),
      'lang files not present in this checkout');

    const en = readFileSync(enPath, 'utf8');
    const ar = readFileSync(arPath, 'utf8');
    // Required PO vocab the checkout modal + receipt surface.
    const required = [
      'po_title',
      'po_uploaded',
      'po_view',
      'po_number_label',
      'po_number_placeholder',
      'po_document_label',
      'po_document_hint',
    ];
    for (const key of required) {
      expect(en, `EN missing ${key}`).toContain(`'${key}'`);
      expect(ar, `AR missing ${key}`).toContain(`'${key}'`);
    }
  });
});
