/**
 * Cardify core E2E smoke tests.
 *
 * Three flows, one file. Catches breakage from migrations, perms, Codex
 * sweeps, random 500s. Runs nightly against prod + on every push.
 *
 * Run: BASE_URL=https://cardify.om npx playwright test
 */
import { test, expect, Page } from '@playwright/test';
import { KNOWN_CARD, BAD_UUID, cardPath } from './fixtures';

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

// ---------------------------------------------------------------------------
// Test 4 — QR redirect flow
//
// /qr.php?i=<eid> should either serve a vCard (default) or 302-redirect when
// the employee has qr_redirect_url set. Unknown eid must 404, not 500.
// ---------------------------------------------------------------------------
test.describe('Cardify — qr.php endpoint', () => {
  test('known eid returns vCard or 302 redirect (never 500)', async ({ request }) => {
    const res = await request.get(`/qr.php?i=${KNOWN_CARD.eid}`, {
      maxRedirects: 0,
    });
    const status = res.status();

    // Accept either vCard-200 (no redirect set) or 302 (redirect set).
    expect([200, 302], 'status').toContain(status);

    if (status === 200) {
      const ct = (res.headers()['content-type'] || '').toLowerCase();
      expect(ct, 'content-type').toMatch(/vcard|octet-stream/);
      const body = await res.text();
      expect(body, 'vCard body prefix').toMatch(/^BEGIN:VCARD/);
    } else {
      // 302: we just check status + Location header exists. Don't follow.
      const loc = res.headers()['location'];
      expect(loc, 'Location header on 302').toBeTruthy();
    }
  });

  test('unknown eid returns 404', async ({ request }) => {
    const res = await request.get(`/qr.php?i=${BAD_UUID}`, {
      maxRedirects: 0,
    });
    expect(res.status(), 'status').toBe(404);
  });
});

// ---------------------------------------------------------------------------
// Test 5 — Login page loads (auth UI, don't submit real credentials)
//
// Sanity-check that /login.php renders the form, embeds a CSRF token, and
// the empty submit path goes through validation (not a 500). We don't test
// actual auth — the test account lives in prod and we never POST real creds.
// ---------------------------------------------------------------------------
test.describe('Cardify — login page', () => {
  test('renders form with email, password, CSRF, and logo', async ({ page }) => {
    const res = await page.goto('/login.php', { waitUntil: 'domcontentloaded' });
    expect(res?.status(), 'status').toBe(200);

    await expect(page.locator('input[name="email"]'), 'email input').toBeVisible();
    await expect(page.locator('input[name="password"]'), 'password input').toBeVisible();

    // CSRF token hidden field must exist and be non-empty.
    const csrfToken = await page
      .locator('input[name="csrf_token"]')
      .first()
      .getAttribute('value');
    expect(csrfToken, 'CSRF token present').toBeTruthy();
    expect((csrfToken || '').length, 'CSRF token length').toBeGreaterThan(16);

    // Brand logo renders (alt text contains the brand name).
    const logo = page.locator('img[alt*="Cardify" i], img[src*="logo" i]').first();
    await expect(logo, 'brand logo').toBeVisible();
  });

  test('POSTing empty body does not 500', async ({ request }) => {
    // Send an empty POST (no CSRF, no credentials). Page must handle this
    // gracefully: either 200 (re-render with error) or 4xx, never 5xx.
    const res = await request.post('/login.php', {
      maxRedirects: 0,
      form: {},
    });
    expect(res.status(), 'status').toBeLessThan(500);
  });
});

// ---------------------------------------------------------------------------
// Test 6 — Admin pages must be auth-gated
//
// Catches regressions where a dev accidentally removes `requireAdmin()`
// from the top of a new admin page. Each page must 302-redirect to /login
// for an unauthenticated request.
// ---------------------------------------------------------------------------
test.describe('Cardify — admin auth gate', () => {
  const gatedAdminPages = [
    '/admin/growth.php',
    '/admin/bulk-claim.php',
    '/admin/card-analytics.php',
  ];

  for (const path of gatedAdminPages) {
    test(`${path} redirects unauthenticated to /login`, async ({ request }) => {
      const res = await request.get(path, { maxRedirects: 0 });
      expect(res.status(), `${path} status`).toBe(302);

      const loc = res.headers()['location'] || '';
      expect(loc, `${path} Location header`).toMatch(/login\.php/);
    });
  }
});

// ---------------------------------------------------------------------------
// Test 7 — Link shortener 404 on unknown slug
//
// /s/<slug> must 404 (not 500, not open-redirect to empty destination).
// We never create a real short link from this test (admin-gated).
// ---------------------------------------------------------------------------
test.describe('Cardify — link shortener', () => {
  test('unknown slug returns 404', async ({ request }) => {
    const res = await request.get('/s/nonexistent-slug-99999', {
      maxRedirects: 0,
    });
    expect(res.status(), 'status').toBe(404);
  });
});

// ---------------------------------------------------------------------------
// Test 8 — Bulk-claim magic-token flow (negative paths only)
//
// Positive flow requires a real magic token we can't safely generate in CI
// (it burns a lead + dispatches a WA message). Validate the gate instead:
// missing token → 400, malformed token → 400, and no path leaks a 500.
// ---------------------------------------------------------------------------
test.describe('Cardify — claim-lead guards', () => {
  test('missing token → 400', async ({ request }) => {
    const res = await request.get('/claim-lead.php', { maxRedirects: 0 });
    expect(res.status(), 'status').toBe(400);
  });

  test('malformed token → 400 (never 500)', async ({ request }) => {
    const res = await request.get('/claim-lead.php?token=invalid-hex', {
      maxRedirects: 0,
    });
    // Implementation returns 400 for "not 32-64 hex chars". Accept 404 too
    // in case the regex ever loosens and the lookup misses.
    expect([400, 404], 'status').toContain(res.status());
  });
});

// ---------------------------------------------------------------------------
// Test 9 — card_click.php scheme allow-list
//
// Codex-hardening round-2 established:
//   tel: / mailto: / sms: / wa: / whitelisted https hosts only.
// URL shorteners (goo.gl, bit.ly, …) must be rejected even though https.
// javascript: must never redirect.
// ---------------------------------------------------------------------------
test.describe('Cardify — card_click.php scheme allow-list', () => {
  test('tel: destination redirects (302)', async ({ request }) => {
    const res = await request.get(
      `/card_click.php?eid=${KNOWN_CARD.eid}&cta=click_phone&dest=${encodeURIComponent('tel:123')}`,
      { maxRedirects: 0 }
    );
    expect(res.status(), 'status').toBe(302);
    const loc = res.headers()['location'] || '';
    expect(loc, 'Location header').toBe('tel:123');
  });

  test('goo.gl (URL shortener) is rejected', async ({ request }) => {
    const res = await request.get(
      `/card_click.php?eid=${KNOWN_CARD.eid}&cta=click_website&dest=${encodeURIComponent('https://goo.gl/abc')}`,
      { maxRedirects: 0 }
    );
    expect(res.status(), 'status').toBe(400);
  });

  test('javascript: scheme is rejected', async ({ request }) => {
    const res = await request.get(
      `/card_click.php?eid=${KNOWN_CARD.eid}&cta=click_website&dest=${encodeURIComponent('javascript:alert(1)')}`,
      { maxRedirects: 0 }
    );
    expect(res.status(), 'status').toBe(400);
  });
});

// ---------------------------------------------------------------------------
// Test 10 — SEO health (title + description, plus canonical on homepage)
//
// Catches regressions where a template rewrite drops the <title> or meta
// description. Canonical is only asserted on the homepage — public card
// pages intentionally don't set one (they're per-employee).
// ---------------------------------------------------------------------------
test.describe('Cardify — SEO health', () => {
  test('homepage has title, description, canonical', async ({ page }) => {
    const res = await page.goto('/', { waitUntil: 'domcontentloaded' });
    expect(res?.status(), 'status').toBe(200);

    const title = await page.title();
    expect(title.length, 'title length').toBeGreaterThan(5);

    const desc = await page
      .locator('meta[name="description"]')
      .first()
      .getAttribute('content');
    expect(desc, 'meta description').toBeTruthy();
    expect((desc || '').length, 'description length').toBeGreaterThan(20);

    const canonical = await page
      .locator('link[rel="canonical"]')
      .first()
      .getAttribute('href');
    expect(canonical, 'canonical href').toBeTruthy();
    expect(canonical, 'canonical is absolute URL').toMatch(/^https?:\/\//);
  });

  test('public card page has title and description', async ({ page }) => {
    const res = await page.goto(cardPath(), { waitUntil: 'domcontentloaded' });
    expect(res?.status(), 'status').toBe(200);

    const title = await page.title();
    expect(title, 'title includes employee name').toContain(KNOWN_CARD.name);

    const desc = await page
      .locator('meta[name="description"]')
      .first()
      .getAttribute('content');
    expect(desc, 'meta description').toBeTruthy();
  });
});
