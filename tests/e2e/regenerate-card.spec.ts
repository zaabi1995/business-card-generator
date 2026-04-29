import { test, expect } from '@playwright/test';

/**
 * Trigger a server-side card re-render for one employee, drives the actual
 * Fabric.js + html2canvas pipeline through the auto_generate.php flow so the
 * canonical PNG ends up using the latest templates.fields_json.
 *
 * Required env vars:
 *   BASE_URL        e.g. https://cardify.om
 *   CARDIFY_SID     a PHPSESSID minted via scripts/mint-session.php for an
 *                   admin who owns the employee
 *   EMPLOYEE_ID     e.g. muhammed.ali
 *
 * Example:
 *   SID=$(ssh root@VPS "/www/server/php/83/bin/php /www/wwwroot/cardify.om/scripts/mint-session.php ali@otech.om")
 *   CARDIFY_SID=$SID EMPLOYEE_ID=muhammed.ali npx playwright test tests/e2e/regenerate-card.spec.ts --project=chromium
 */

const SID = process.env.CARDIFY_SID;
const EMPLOYEE_ID = process.env.EMPLOYEE_ID || '';

test.skip(!SID || !EMPLOYEE_ID, 'CARDIFY_SID + EMPLOYEE_ID env vars required');

test('regenerate canonical PNG via auto_generate', async ({ page, context, baseURL }) => {
  const host = new URL(baseURL!).hostname;
  await context.addCookies([{
    name: 'PHPSESSID',
    value: SID!,
    domain: host,
    path: '/',
    httpOnly: true,
    secure: true,
    sameSite: 'Lax',
  }]);

  const url = `${baseURL}/admin/auto_generate.php?employee_id=${encodeURIComponent(EMPLOYEE_ID)}&regenerate=1&return=employees`;
  page.on('console', m => console.log('  [page]', m.type(), m.text()));
  page.on('pageerror', e => console.log('  [pageerror]', e.message));

  await page.goto(url, { waitUntil: 'domcontentloaded' });

  // The page Alpine.js component sets `status` to 'generating' on load
  // (when ?regenerate=1) and to 'success' once both PNG + back upload
  // succeed. Wait up to 60s for that transition.
  await page.waitForFunction(() => {
    const root = document.querySelector('[x-data^="layoutGenerator"]') as any;
    const data = root?._x_dataStack?.[0];
    return data?.status === 'success' || data?.status === 'error';
  }, null, { timeout: 60_000 });

  const result = await page.evaluate(() => {
    const root = document.querySelector('[x-data^="layoutGenerator"]') as any;
    const data = root?._x_dataStack?.[0];
    return { status: data?.status, error: data?.errorMessage };
  });

  console.log('regen result:', result);
  expect(result.status).toBe('success');
});
