/**
 * Cardify Journey A — company registration (Cat U actions 471 + 472).
 *
 * Exercises the EN and AR landing variants of /company/register so we
 * catch broken form markup, missing CSRF, dropped i18n keys, or a
 * layout regression before users find them.
 *
 * The happy-path POST + OTP + wizard landing is NOT covered here
 * because completing it on live would create a real company row, a
 * real OTP email, and a real wizard session. That full-flow test
 * belongs on stage.cardify.om (action 822) with a TEST_OTP bypass
 * env (action 825). See /ops/runbook.md §6 if a regression surfaces.
 *
 * This spec is read-only and safe to run against prod.
 */
import { test, expect } from '@playwright/test';

test.describe('Journey A — Company Registration (read-only)', () => {
  test('EN register page renders, has form + CSRF + key fields', async ({ page }) => {
    await page.goto('/company/register.php', { waitUntil: 'domcontentloaded' });

    await expect(page).toHaveURL(/\/company\/register/);
    await expect(page.locator('html')).toHaveAttribute('lang', /en/);

    // Form presence
    const form = page.locator('form[method="POST"]').first();
    await expect(form).toBeVisible();

    // CSRF is mandatory (memory: CSRF is a Cardify invariant)
    await expect(form.locator('input[name="csrf_token"]')).toBeAttached();

    // Required fields exist
    for (const name of ['admin_email', 'user_name', 'company_name', 'company_slug', 'phone', 'password']) {
      await expect(form.locator(`input[name="${name}"]`)).toBeAttached();
    }

    // The hidden phone_e164 companion field is part of the phone UX
    await expect(form.locator('input[name="phone_e164"]')).toBeAttached();
  });

  test('AR register page flips to RTL + keeps the form', async ({ page }) => {
    await page.goto('/company/register.php?lang=ar', { waitUntil: 'domcontentloaded' });

    // Either /ar/company/register or ?lang=ar should work; both land
    // on a page with dir=rtl when the locale is Arabic.
    const html = page.locator('html');
    const dir = await html.getAttribute('dir');
    // Tolerant check: some implementations set dir="rtl" via body.
    if (dir !== 'rtl') {
      const bodyDir = await page.locator('body').evaluate((el) => getComputedStyle(el).direction);
      expect(bodyDir).toBe('rtl');
    }

    await expect(page.locator('form[method="POST"]').first()).toBeVisible();
    await expect(page.locator('input[name="csrf_token"]')).toBeAttached();
    await expect(page.locator('input[name="admin_email"]')).toBeAttached();
  });

  test('register page links to login for existing accounts', async ({ page }) => {
    await page.goto('/company/register.php', { waitUntil: 'domcontentloaded' });
    // "Already have an account?" → /login.php is a constant on get-started.php,
    // but on register.php it's also important. Accept either literal or role.
    const loginLink = page.getByRole('link', { name: /sign\s*in|login/i }).first();
    await expect(loginLink).toBeVisible();
  });

  test('submitting empty form surfaces a validation error, not a 500', async ({ page }) => {
    await page.goto('/company/register.php', { waitUntil: 'domcontentloaded' });
    const form = page.locator('form[method="POST"]').first();
    // Disable HTML5 required so we can actually submit empty and probe
    // the PHP-side validator.
    await page.evaluate(() => {
      document.querySelectorAll('input[required], select[required]').forEach((el) => {
        (el as HTMLInputElement).required = false;
      });
    });
    const [response] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/company/register') && r.request().method() === 'POST'),
      form.evaluate((f: HTMLFormElement) => f.submit()),
    ]);
    // Anything except a 5xx is acceptable (200 with inline errors OR 302 back).
    expect(response.status()).toBeLessThan(500);
  });
});
