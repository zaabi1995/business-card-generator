/**
 * Cardify core E2E smoke tests.
 *
 * Three flows, one file. Catches breakage from migrations, perms, Codex
 * sweeps, random 500s. Runs nightly against prod + on every push.
 *
 * Run: BASE_URL=https://cardify.om npx playwright test
 */
import { test, expect, Page } from '@playwright/test';
import { KNOWN_CARD, cardPath } from './fixtures';

const consoleErrors: string[] = [];

function trackConsoleErrors(page: Page) {
  page.on('console', (msg) => {
    if (msg.type() === 'error') {
      const text = msg.text();
      // Ignore noisy third-party errors that aren't ours.
      if (/favicon|flag-icons|fontawesome|cdn\.jsdelivr/i.test(text)) return;
      consoleErrors.push(text);
    }
  });
  page.on('pageerror', (err) => {
    consoleErrors.push(`pageerror: ${err.message}`);
  });
}

test.describe('Cardify — public card view', () => {
  test.beforeEach(() => {
    consoleErrors.length = 0;
  });

  test(`GET ${cardPath()} renders a valid public card`, async ({ page }) => {
    trackConsoleErrors(page);

    const response = await page.goto(cardPath(), { waitUntil: 'networkidle' });

    // HTTP 200
    expect(response, 'response should exist').not.toBeNull();
    expect(response!.status(), 'status code').toBe(200);

    // Title contains employee name
    const title = await page.title();
    expect(title, 'page title').toContain(KNOWN_CARD.name);

    // Save Contact button visible
    const saveContact = page.getByRole('link', { name: /save contact/i }).first();
    await expect(saveContact, 'Save Contact button').toBeVisible();

    // "Made with Cardify" viral footer visible
    const viralFooter = page.locator('.cardify-viral-footer');
    await expect(viralFooter, 'viral footer container').toBeVisible();
    await expect(viralFooter, 'viral footer text').toContainText(/made with cardify/i);

    // Save-contact link embeds the VCF/QR endpoint — verify its href points at
    // /qr.php (the VCF download the QR on printed/PDF cards resolves to).
    const saveHref = await saveContact.getAttribute('href');
    expect(saveHref, 'save-contact href').toMatch(/qr\.php/);

    // And the endpoint itself must return a valid vCard payload.
    const qrRes = await page.request.get(`/qr.php?i=${KNOWN_CARD.eid}`);
    expect(qrRes.status(), 'qr.php status').toBe(200);
    const qrCt = qrRes.headers()['content-type'] || '';
    expect(qrCt.toLowerCase(), 'qr.php content-type').toMatch(/vcard|octet-stream/);
    const qrBody = await qrRes.text();
    expect(qrBody, 'qr.php body').toContain('BEGIN:VCARD');

    // No console errors
    expect(consoleErrors, 'console errors').toEqual([]);
  });
});

test.describe('Cardify — PDF download', () => {
  test('Download PDF button returns a real PDF', async ({ page, request }) => {
    await page.goto(cardPath(), { waitUntil: 'networkidle' });

    // Resolve the PDF URL from the "Download PDF" link rather than clicking —
    // the link wraps a click-tracker redirect so we fetch the final card-pdf.php
    // endpoint directly with the test's auth/cookie context.
    const pdfHref = await page
      .locator('a.btn-pdf, a[href*="card-pdf.php"], a[href*="download_pdf"]')
      .first()
      .getAttribute('href');
    expect(pdfHref, 'PDF link href').toBeTruthy();

    // card_click.php redirects to card-pdf.php — follow redirects
    const resolved = new URL(pdfHref!, process.env.BASE_URL || 'https://cardify.om');
    const res = await request.get(resolved.toString(), { maxRedirects: 5 });

    expect(res.status(), 'PDF HTTP status').toBe(200);

    const ct = res.headers()['content-type'] || '';
    expect(ct.toLowerCase(), 'content-type').toContain('application/pdf');

    const body = await res.body();
    expect(body.byteLength, 'PDF byte size').toBeGreaterThan(1024);

    // PDF magic bytes
    const head = body.subarray(0, 4).toString('ascii');
    expect(head, 'PDF magic').toBe('%PDF');
  });
});

test.describe('Cardify — viral footer → /claim', () => {
  // Clearly-fake test phone (Oman local format 00000000 → +96800000000).
  // Never belongs to a real subscriber; safe even if a future revision of
  // claim.php re-introduces WA side-effects.
  const TEST_PHONE_LOCAL = '00000000';
  const TEST_PHONE_E164 = '+96800000000';

  test('Clicking "Create yours free" lands on /claim with a phone input', async ({
    page,
    request,
  }) => {
    // Tag every request this test makes so claim.php flags the lead row as
    // source='e2e_test' and we can purge it in cleanup.
    await page.setExtraHTTPHeaders({ 'X-E2E-Test': '1' });

    await page.goto(cardPath(), { waitUntil: 'networkidle' });

    // The viral-footer link tracks via /card_click.php?dest=/claim... — clicking
    // follows the redirect chain to /claim.
    const viralLink = page.locator('.cardify-viral-footer a.viral-link').first();
    await expect(viralLink, 'viral footer link').toBeVisible();

    await Promise.all([
      page.waitForURL(/\/claim(\?|$|\b)/, { timeout: 15_000 }),
      viralLink.click(),
    ]);

    await page.waitForLoadState('networkidle');

    expect(page.url(), 'URL after viral footer click').toMatch(/\/claim(\?|$)/);

    // Phone input visible
    const phone = page.locator('input[type="tel"], input[name="phone"]').first();
    await expect(phone, 'phone input').toBeVisible();

    // Submit a clearly-fake phone (Codex round-3 finding #3).
    await phone.fill(TEST_PHONE_LOCAL);

    const submit = page
      .getByRole('button', { name: /send me the link|أرسل لي الرابط/i })
      .first();
    await expect(submit, 'submit button').toBeVisible();
    await submit.click();

    await page.waitForLoadState('networkidle');

    // Success indicator: either the success copy shows, or we stay on /claim
    // without a hard error. The page uses "Thanks!" on success; accept either
    // success copy OR the form being replaced.
    const successVisible = await page
      .getByText(/thanks!|شكرا|we'll whatsapp you/i)
      .first()
      .isVisible()
      .catch(() => false);

    if (!successVisible) {
      // Rate limiter or validation may have short-circuited — the page should
      // at least still render claim.php, not a 500. Verify status + that the
      // form is still there so the test doesn't silently pass on a blank page.
      expect(page.url(), 'still on /claim').toMatch(/\/claim/);
      await expect(page.locator('input[type="tel"], input[name="phone"]').first())
        .toBeVisible();
    } else {
      expect(successVisible).toBe(true);
    }

    // Cleanup — best-effort delete of the test lead so the table doesn't
    // accumulate junk rows across nightly CI runs. Relies on an opt-in
    // admin endpoint at /api/e2e-cleanup.php (gated by X-E2E-Test header +
    // E2E_CLEANUP_TOKEN env var on the server). Failure is non-fatal — we
    // never want cleanup to fail a smoke test.
    const cleanupToken = process.env.E2E_CLEANUP_TOKEN;
    if (cleanupToken) {
      try {
        await request.post('/api/e2e-cleanup.php', {
          headers: {
            'X-E2E-Test': '1',
            'X-E2E-Cleanup-Token': cleanupToken,
          },
          data: { phone: TEST_PHONE_E164 },
        });
      } catch (_e) {
        /* non-fatal */
      }
    }
  });
});
