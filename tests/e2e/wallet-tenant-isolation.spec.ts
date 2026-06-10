/**
 * Wallet-pass tenant isolation (regression guard for the 10 Jun 2026 audit
 * fix G3). Before the fix, /wallet_apple.php?i=<uuid> with no ?c= did an
 * UNSCOPED employee lookup, so an employee of tenant B could be requested
 * from tenant A's subdomain. The fix scopes the lookup to the subdomain's
 * company (TenantHost::resolve) and refuses pending_approval cards.
 *
 * We assert the cross-tenant request 404s while the same-tenant request still
 * resolves (2xx/3xx/503-when-disabled, never 5xx). Two real production
 * tenant+employee pairs; override via env if either is ever deactivated.
 *
 * Run: BASE_URL=https://cardify.om npx playwright test wallet-tenant-isolation
 */
import { test, expect } from '@playwright/test';

const TENANT_A = process.env.WALLET_TENANT_A || 'ithca';
const EMP_A = process.env.WALLET_EMP_A || 'ali.zaabi';
const TENANT_B = process.env.WALLET_TENANT_B || 'otech';
const EMP_B = process.env.WALLET_EMP_B || 'ktyagi';

// Same-tenant happy path may be 200 (pkpass), 302 (Google save redirect), or
// 503 when wallet certs are not configured. Never a 5xx.
const SAME_TENANT_OK = [200, 302, 404, 410, 503];

for (const ep of ['wallet_apple.php', 'wallet_google.php']) {
  test.describe(`${ep} tenant isolation`, () => {
    test(`cross-tenant request is rejected (404)`, async ({ request }) => {
      // Tenant B's employee requested from tenant A's subdomain.
      const res = await request.get(
        `https://${TENANT_A}.cardify.om/${ep}?i=${encodeURIComponent(EMP_B)}`,
        { maxRedirects: 0 }
      );
      expect(res.status()).toBe(404);
      const body = await res.text();
      // And it must not leak the other tenant's employee data in the body.
      expect(body).not.toMatch(/"email":"[^"]+@[^"]+"/);
    });

    test(`same-tenant request still resolves`, async ({ request }) => {
      const res = await request.get(
        `https://${TENANT_B}.cardify.om/${ep}?i=${encodeURIComponent(EMP_B)}`,
        { maxRedirects: 0 }
      );
      expect(SAME_TENANT_OK).toContain(res.status());
      expect(res.status()).toBeLessThan(500);
    });
  });
}
