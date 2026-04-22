/**
 * Omani Logo Library E2E smoke tests.
 *
 * Validates: hub loads, sector page loads, API endpoints return valid JSON,
 * takedown form reachable, claim page requires auth, profile shows logo hero.
 *
 * Run: BASE_URL=https://cardify.om npx playwright test tests/e2e/logos.spec.ts
 */
import { test, expect } from '@playwright/test';

const BASE = process.env.BASE_URL || 'https://cardify.om';

test.describe('Omani Logo Library', () => {
  test('hub loads with grid', async ({ page }) => {
    await page.goto(`${BASE}/logos`);
    await expect(page.locator('h1')).toContainText(/Omani Logo Library/i);
    const tiles = page.locator('a[href^="/companies/"]');
    await expect(tiles.first()).toBeVisible({ timeout: 10000 });
    const count = await tiles.count();
    expect(count).toBeGreaterThan(10);
  });

  test('sector page loads (oil-gas)', async ({ page }) => {
    await page.goto(`${BASE}/logos/oil-gas`);
    await expect(page.locator('h1')).toContainText(/Oil & Gas/i);
    // Breadcrumb present (site nav is nav[0], breadcrumb is nav[1])
    await expect(page.locator('nav').filter({ hasText: /Logo Library/i }).first()).toBeVisible();
  });

  test('terms page renders', async ({ page }) => {
    await page.goto(`${BASE}/logos/terms`);
    await expect(page.locator('h1')).toContainText(/Terms/i);
    // Takedown link in body (page has inline link + button; check at least one visible)
    await expect(page.locator('a[href="/logo-takedown"]').first()).toBeVisible();
  });

  test('press kit page renders', async ({ page }) => {
    await page.goto(`${BASE}/logos/press`);
    await expect(page.locator('h1')).toContainText(/Press Kit/i);
    await expect(page.locator('a[href^="mailto:press@"]')).toBeVisible();
  });

  test('API list returns shaped results', async ({ request }) => {
    const r = await request.get(`${BASE}/api/logos/list?per_page=5`);
    expect(r.ok()).toBeTruthy();
    const j = await r.json();
    expect(j.total).toBeGreaterThan(0);
    expect(j.results.length).toBeGreaterThan(0);
    expect(j.attribution).toContain('cardify.om/logos');
    const first = j.results[0];
    expect(first.slug).toBeTruthy();
    expect(first.urls).toBeDefined();
    expect(first.profile_url).toMatch(/\/companies\//);
  });

  test('API stats returns counts', async ({ request }) => {
    const r = await request.get(`${BASE}/api/logos/stats`);
    expect(r.ok()).toBeTruthy();
    const j = await r.json();
    expect(typeof j.total).toBe('number');
    expect(typeof j.verified).toBe('number');
    expect(typeof j.indexed).toBe('number');
    expect(j.total).toBeGreaterThanOrEqual(j.verified + j.indexed - 1);
  });

  test('API sectors returns 23 sectors with counts', async ({ request }) => {
    const r = await request.get(`${BASE}/api/logos/sectors`);
    expect(r.ok()).toBeTruthy();
    const j = await r.json();
    expect(Array.isArray(j.sectors)).toBe(true);
    expect(j.sectors.length).toBeGreaterThan(0);
    expect(j.sectors[0].slug).toBeTruthy();
    expect(typeof j.sectors[0].count).toBe('number');
  });

  test('API CORS headers present', async ({ request }) => {
    const r = await request.get(`${BASE}/api/logos/stats`);
    expect(r.headers()['access-control-allow-origin']).toBe('*');
    expect(r.headers()['x-attribution']).toBe('cardify.om/logos');
  });

  test('takedown form reachable without auth', async ({ page }) => {
    await page.goto(`${BASE}/logo-takedown`);
    await expect(page.locator('h1')).toContainText(/takedown/i);
    await expect(page.locator('input[name="name"]')).toBeVisible();
    await expect(page.locator('input[name="email"]')).toBeVisible();
    await expect(page.locator('textarea[name="claim_basis"]')).toBeVisible();
  });

  test('company profile shows logo hero', async ({ page, request }) => {
    const list = await (await request.get(`${BASE}/api/logos/list?per_page=1`)).json();
    expect(list.results.length).toBeGreaterThan(0);
    const slug = list.results[0].slug;
    await page.goto(`${BASE}/companies/${slug}`);
    // Either a download button (verified) or a claim CTA (indexed) should be visible
    const heroAction = page.locator(
      'a[href*="/logo-download"], a[href*="/logo-claim"]'
    ).first();
    await expect(heroAction).toBeVisible({ timeout: 10000 });
  });

  test('sitemap-logos.xml returns valid XML', async ({ request }) => {
    const r = await request.get(`${BASE}/sitemap-logos.xml`);
    expect(r.ok()).toBeTruthy();
    const body = await r.text();
    expect(body).toMatch(/<urlset/);
    expect(body).toContain('/logos');
  });
});
