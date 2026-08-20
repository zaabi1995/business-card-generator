/**
 * Vector PDF fall-through path test.
 *
 * Verifies that when has_vector_source=0, card-pdf.php serves the raster
 * PNG-in-PDF fallback (Content-Length > 1_000_000), and when =1 it serves
 * the compact vector PDF (Content-Length < 500_000).
 *
 * Skip in CI -- manual run only:
 *   BASE_URL=https://cardify.om npx playwright test tests/e2e/vector-pdf-fallback.spec.ts --project=chromium
 *
 * SSH to VPS must be authenticated for the current user.
 */

import { test, expect } from '@playwright/test';
import { execSync } from 'node:child_process';

// Target: Otech back template (the one with has_vector_source=1 in prod).
const TEMPLATE_ID  = '2b8a7c4d-b85f-4284-b45a-34747b93206d';
const EMPLOYEE_ID  = 'muhammed.ali';
const MINT_EMAIL   = 'ali@otech.om';
const CARD_PDF_URL = `/card-pdf.php?i=${EMPLOYEE_ID}`;

const SSH_PREFIX   = 'ssh root@147.93.20.54';
const PHP_BIN      = '/www/server/php/83/bin/php';
const CARDIFY_ROOT = '/www/wwwroot/cardify.om';
const MYSQL_CMD    = 'mysql -u bc -ppWewN3fwFmEHh32J -h 127.0.0.1 bc';

function sshExec(cmd: string): string {
  return execSync(`${SSH_PREFIX} "${cmd.replace(/"/g, '\\"')}"`, {
    timeout: 30_000,
    encoding: 'utf8',
  }).trim();
}

function setVectorSource(value: 0 | 1): void {
  sshExec(
    `${MYSQL_CMD} -e "UPDATE templates SET has_vector_source=${value} WHERE id='${TEMPLATE_ID}'"`,
  );
}

function mintSession(): string {
  return sshExec(`${PHP_BIN} ${CARDIFY_ROOT}/scripts/mint-session.php ${MINT_EMAIL}`);
}

// Skip entire file when running in CI.
test.skip(!!process.env.CI, 'vector-pdf-fallback: skipped in CI, run manually');

/**
 * These tests mutate ONE specific template's has_vector_source flag and then
 * fetch ONE specific employee's PDF, so they only mean anything while that
 * exact fixture exists. Both were deleted at some point before 20 Aug 2026,
 * and the suite reported six hard failures across three browsers that looked
 * like a broken vector pipeline. It was not: card-pdf.php answered 404 because
 * the employee was gone, and a live employee on the same tenant still returned
 * `x-cardify-pdf-mode: vector`, 2 pages, produced by PyMuPDF.
 *
 * A missing fixture is a missing fixture, not a regression, so say so and skip.
 * Deliberately NOT re-pointed at whichever template happens to exist today:
 * these tests flip has_vector_source on the row they target, and doing that to
 * a live customer's template mid-run is not something a test should decide.
 * Re-provision the fixture below to turn them back on.
 */
const fixtureMissing = (() => {
  try {
    const n = sshExec(
      `${MYSQL_CMD} -N -e "SELECT (SELECT COUNT(*) FROM employees WHERE id='${EMPLOYEE_ID}')` +
      ` + (SELECT COUNT(*) FROM templates WHERE id='${TEMPLATE_ID}')"`,
    );
    return Number(n) < 2;
  } catch {
    return true; // no ssh, no fixture check, nothing meaningful to assert
  }
})();

test.skip(
  fixtureMissing,
  `vector-pdf-fallback: fixture absent (employee '${EMPLOYEE_ID}' and/or template ` +
  `'${TEMPLATE_ID}'). Re-provision it to re-enable; the vector path itself is ` +
  `verified separately by vector-pdf.spec.ts.`,
);

// Always restore after the suite, even if a test throws.
test.afterAll(() => {
  try {
    setVectorSource(1);
  } catch (e) {
    console.error('afterAll restore failed:', e);
  }
});

test('raster fallback: has_vector_source=0 yields large PDF', async ({ request }) => {
  // 1. Flip to raster fallback mode.
  setVectorSource(0);

  // 2. Mint a session so card-pdf.php can resolve the company context.
  const sid = mintSession();

  // 3. Fetch the PDF.
  const resp = await request.get(CARD_PDF_URL, {
    headers: { Cookie: `PHPSESSID=${sid}` },
  });

  expect(resp.status()).toBe(200);
  expect(resp.headers()['content-type']).toContain('application/pdf');

  const buf = await resp.body();

  // Raster fallback: PNG embedded in a PDF wrapper, typically 1-4 MB.
  expect(buf.length).toBeGreaterThan(1_000_000);
});

test('vector path: has_vector_source=1 yields compact PDF', async ({ request }) => {
  // Restore and confirm vector mode.
  setVectorSource(1);

  const sid = mintSession();

  const resp = await request.get(CARD_PDF_URL, {
    headers: { Cookie: `PHPSESSID=${sid}` },
  });

  expect(resp.status()).toBe(200);
  expect(resp.headers()['content-type']).toContain('application/pdf');

  const buf = await resp.body();

  // Vector PDF: SVG paths + embedded font subsets, typically 150-400 KB.
  expect(buf.length).toBeLessThan(500_000);
});
