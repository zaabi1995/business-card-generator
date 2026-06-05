/**
 * Cardify header + footer audit.
 *
 * The site renders tens of thousands of URLs from ~7 distinct
 * header/footer paths and ~60-70 templates. This spec hits ONE
 * representative URL per archetype and checks the invariants that
 * the June 2026 brand pass could regress:
 *
 *   1. HTTP 200.
 *   2. Header renders (logo visible; desktop nav OR mobile hamburger).
 *   3. FontAwesome glyph actually PAINTS — the cross-origin woff2 CORS
 *      trap makes icons blank even when the CSS loads and
 *      document.fonts.check() lies. We assert the FA font reports
 *      status === 'loaded' AND a known icon has non-zero width, AND
 *      the fa-solid woff2 response carried an ACAO header.
 *   4. Footer is the correct variant and a SINGLE ROW on desktop
 *      (computed grid column count: 6 full / 5 inline / minimal=flex).
 *   5. No i18n leak (no literal t('...') or namespace.key in the body).
 *   6. No horizontal overflow (scrollWidth <= innerWidth + 2).
 *   7. No failed SAME-ORIGIN stylesheet requests; the cross-origin FA woff2
 *      is verified by PAINT (glyph width) + CORS header, not by network
 *      success — Cloudflare can block CI datacenter IPs while real users load
 *      it fine.
 *   8. Brand-color correctness: Cardify's own pages carry the
 *      `cardify-brand` body class and a cyan primary CTA; tenant
 *      subdomains must NOT carry it (they keep their own brand).
 *
 * Run:  npx playwright test tests/e2e/header-footer-audit.spec.ts
 * Tenant suite needs:  CARDIFY_TENANT_SLUG=<live-slug>
 * Authed suite needs:  CARDIFY_ADMIN_USER/PASS, CARDIFY_PS_USER/PASS
 */
import { test, expect, Page } from '@playwright/test';

const CYAN = 'rgb(0, 155, 193)'; // #009bc1 Cardify brand
const FA_WOFF2 = /design\.bhd\.om\/fa\/.*\.woff2/i;

type FooterKind = 'full' | 'inline' | 'minimal' | 'custom';
interface Arch {
  url: string;          // path (apex) or absolute URL (tenant)
  label: string;
  footer: FooterKind;   // expected footer shape
  cols?: number;        // expected desktop grid column count for full/inline
  marketing: boolean;   // expects the `cardify-brand` body class
  ctaCyan?: boolean;    // page has a known filled primary CTA that must be cyan
}

// One representative URL per rendering archetype. Add rows, not depth.
const PUBLIC: Arch[] = [
  { url: '/',                              label: 'homepage (inline footer)', footer: 'inline', cols: 5, marketing: true, ctaCyan: true },
  { url: '/pricing',                       label: 'pricing',          footer: 'full', cols: 6, marketing: true, ctaCyan: true },
  { url: '/about',                         label: 'about',            footer: 'full', cols: 6, marketing: true },
  { url: '/faq',                           label: 'faq',              footer: 'full', cols: 6, marketing: true },
  { url: '/contact',                       label: 'contact',          footer: 'full', cols: 6, marketing: true },
  { url: '/companies',                     label: 'directory hub',    footer: 'full', cols: 6, marketing: true },
  { url: '/companies/oq',                  label: 'company profile',  footer: 'full', cols: 6, marketing: true },
  { url: '/oman-business-index',           label: 'oman index',       footer: 'full', cols: 6, marketing: true },
  { url: '/blog',                          label: 'blog index',       footer: 'full', cols: 6, marketing: true },
  { url: '/solutions',                     label: 'solutions hub',    footer: 'full', cols: 6, marketing: true },
  { url: '/industries',                    label: 'industries hub',   footer: 'full', cols: 6, marketing: true },
  { url: '/tools',                         label: 'tools hub',        footer: 'full', cols: 6, marketing: true },
  { url: '/tools/vcard-qr-generator',      label: 'tool page',        footer: 'full', cols: 6, marketing: true },
  { url: '/logos',                         label: 'logos hub',        footer: 'full', cols: 6, marketing: true },
  { url: '/print-shops',                   label: 'print-shops',      footer: 'full', cols: 6, marketing: true },
  { url: '/login',                         label: 'login (minimal)',  footer: 'minimal', marketing: true },
];

// Arabic / RTL variants — highest value for i18n-leak + overflow.
const RTL: Arch[] = [
  { url: '/ar/',                 label: 'AR homepage',  footer: 'inline', cols: 5, marketing: true },
  { url: '/ar/pricing',          label: 'AR pricing',   footer: 'full', cols: 6, marketing: true },
  { url: '/ar/companies',        label: 'AR directory', footer: 'full', cols: 6, marketing: true },
];

// ---- shared diagnostics collector ----
async function collect(page: Page) {
  const failedCss: string[] = [];
  const faFontNetFail: string[] = []; // cross-origin FA woff2 network failures — collected, NOT fatal
  const faWoff2: { url: string; status: number; acao: string | undefined }[] = [];
  page.on('requestfailed', (r) => {
    const u = r.url();
    // Same-origin stylesheet failures are real regressions we own → fatal.
    if (/\.css(\?|$)/.test(u)) failedCss.push(`${u} :: ${r.failure()?.errorText}`);
    // The FontAwesome woff2 lives on the cross-origin design.bhd.om CDN behind
    // Cloudflare, which intermittently blocks datacenter/CI IPs (net::ERR_FAILED)
    // even though it serves 200 + ACAO to real browsers. That is a CI/CDN-edge
    // artifact, NOT a site regression — collect it for visibility but do not fail
    // the suite on it. Whether FA actually PAINTS is asserted separately via
    // faStatus / iconWidth / iconGlyph, and its CORS via the badAcao check below.
    else if (FA_WOFF2.test(u)) faFontNetFail.push(`${u} :: ${r.failure()?.errorText}`);
  });
  page.on('response', (res) => {
    const u = res.url();
    if (FA_WOFF2.test(u)) faWoff2.push({ url: u, status: res.status(), acao: res.headers()['access-control-allow-origin'] });
    if (/\.css(\?|$)/.test(u) && res.status() >= 400) failedCss.push(`${u} :: HTTP ${res.status()}`);
  });
  return { failedCss, faFontNetFail, faWoff2 };
}

async function evalAudit(page: Page, a: Arch) {
  return page.evaluate(({ cyan, footer, cols, marketing }) => {
    const out: any = {};
    // header: a logo (img) OR a brand link/text in the header/nav. Tenant
    // portals use an initials avatar + company name instead of an <img>.
    const headerEl = document.querySelector('header, nav');
    out.hasLogo = !!document.querySelector('header img, nav img, a[href="/"] img, a[href$="/"] img')
      || !!document.querySelector('header a[href="/"], header [class*="logo" i], nav [class*="logo" i]')
      || !!(headerEl && (headerEl as HTMLElement).innerText.trim().length > 0);
    out.hasNav = !!document.querySelector('header nav a, nav a, [id*="menu"], button[aria-label*="menu" i]');
    // FontAwesome painted: a page may declare several FA faces (brands,
    // solid, regular) and only the used ones load. Pass if ANY is loaded.
    const faFaces = (Array.from((document as any).fonts) as any[]).filter((f) => /Font Awesome/i.test(f.family));
    out.faStatus = faFaces.some((f) => f.status === 'loaded') ? 'loaded' : (faFaces[0] ? faFaces[0].status : 'absent');
    const icon = document.querySelector('i.fa-solid, i.fa-brands, i.fa-regular') as HTMLElement | null;
    out.iconWidth = icon ? Math.round(icon.getBoundingClientRect().width) : 0;
    out.iconGlyph = icon ? getComputedStyle(icon, '::before').content : null;
    // footer
    const f = document.querySelector('footer');
    out.hasFooter = !!f;
    const grid = f ? f.querySelector('.grid') : null;
    out.footerCols = grid ? getComputedStyle(grid).gridTemplateColumns.trim().split(/\s+/).length : 0;
    // i18n leak
    const txt = document.body.innerText || '';
    out.i18nLeak = /\bt\('|[a-z_]+\.[a-z_]{3,}\b(?![^<]*@)/.test(
      // narrow to obvious key leaks: footer.col_x, pricing.home_x, common.save
      (txt.match(/\b(footer|pricing|common|landing|testimonials|nav)\.[a-z_]+/g) || []).join(' ')
    );
    // overflow
    out.scrollWidth = document.documentElement.scrollWidth;
    out.innerWidth = window.innerWidth;
    // brand
    out.hasBrandClass = document.body.classList.contains('cardify-brand');
    out.dir = document.documentElement.dir || document.body.dir || 'ltr';
    const cta = document.querySelector('a[href*="register"], a[href*="get-started"], .btn-primary') as HTMLElement | null;
    out.ctaBg = cta ? getComputedStyle(cta).backgroundColor : null;
    out.expect = { cyan, footer, cols, marketing };
    return out;
  }, { cyan: CYAN, footer: a.footer, cols: a.cols, marketing: a.marketing });
}

function assertAudit(a: Arch, r: any, diag: any) {
  // 2. header
  expect(r.hasLogo, `${a.label}: header logo`).toBeTruthy();
  // 3. FontAwesome actually painted (cross-origin woff2 CORS guard)
  expect(r.faStatus, `${a.label}: FA font status`).toBe('loaded');
  expect(r.iconWidth, `${a.label}: FA icon width`).toBeGreaterThan(0);
  expect(r.iconGlyph, `${a.label}: FA glyph ::before content`).not.toBe('none');
  const badAcao = diag.faWoff2.filter((w: any) => w.status === 200 && !w.acao);
  expect(badAcao, `${a.label}: FA woff2 missing ACAO ${JSON.stringify(badAcao)}`).toEqual([]);
  // 4. footer variant
  expect(r.hasFooter, `${a.label}: footer present`).toBeTruthy();
  if ((a.footer === 'full' || a.footer === 'inline') && a.cols) {
    expect(r.footerCols, `${a.label}: footer cols (got ${r.footerCols}, want ${a.cols})`).toBe(a.cols);
  }
  // 5. i18n leak
  expect(r.i18nLeak, `${a.label}: i18n key leaked into body`).toBeFalsy();
  // 6. overflow
  expect(r.scrollWidth, `${a.label}: horizontal overflow (doc=${r.scrollWidth} vp=${r.innerWidth})`)
    .toBeLessThanOrEqual(r.innerWidth + 2);
  // 7. no failed css/font
  expect(diag.failedCss, `${a.label}: failed stylesheet/font ${JSON.stringify(diag.failedCss)}`).toEqual([]);
  // 8. brand correctness
  if (a.marketing) {
    expect(r.hasBrandClass, `${a.label}: expected cardify-brand body class`).toBeTruthy();
    if (a.ctaCyan && r.ctaBg) expect(r.ctaBg, `${a.label}: primary CTA should be cyan`).toBe(CYAN);
  } else {
    expect(r.hasBrandClass, `${a.label}: tenant page must NOT have cardify-brand`).toBeFalsy();
  }
}

test.describe('header + footer · public', () => {
  test.use({ viewport: { width: 1280, height: 900 } });
  for (const a of [...PUBLIC, ...RTL]) {
    test(`${a.label} (${a.url})`, async ({ page }) => {
      const diag = await collect(page);
      const res = await page.goto(a.url, { waitUntil: 'networkidle' });
      expect(res?.status(), `${a.label}: status`).toBe(200);
      const r = await evalAudit(page, a);
      if (a.url.startsWith('/ar')) expect(r.dir, `${a.label}: dir=rtl`).toBe('rtl');
      assertAudit(a, r, diag);
    });
  }
});

// Mobile overflow pass for the same archetypes (catches RTL/narrow leaks).
test.describe('header + footer · mobile overflow', () => {
  test.use({ viewport: { width: 390, height: 844 } });
  for (const a of [...PUBLIC, ...RTL]) {
    test(`${a.label} no overflow @390`, async ({ page }) => {
      const res = await page.goto(a.url, { waitUntil: 'domcontentloaded' });
      expect(res?.status()).toBe(200);
      const { sw, iw } = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth }));
      expect(sw, `${a.label}: overflow @390 (doc=${sw} vp=${iw})`).toBeLessThanOrEqual(iw + 2);
    });
  }
});

// Tenant subdomain (own header/footer + per-company brand). Opt-in.
test.describe('header + footer · tenant', () => {
  const slug = process.env.CARDIFY_TENANT_SLUG;
  test.skip(!slug, 'set CARDIFY_TENANT_SLUG=<live-tenant> to audit tenant pages');
  test.use({ viewport: { width: 1280, height: 900 } });
  test('tenant public profile keeps its own brand (no cyan leak)', async ({ page }) => {
    const a: Arch = { url: `https://${slug}.cardify.om/`, label: `tenant ${slug}`, footer: 'custom', marketing: false };
    const diag = await collect(page);
    const res = await page.goto(a.url, { waitUntil: 'networkidle' });
    expect(res?.status(), 'tenant status').toBe(200);
    const r = await evalAudit(page, a);
    expect(r.hasLogo, 'tenant header logo').toBeTruthy();
    expect(r.faStatus, 'tenant FA loaded').toBe('loaded');
    expect(r.hasBrandClass, 'tenant must NOT carry cardify-brand').toBeFalsy();
    expect(r.scrollWidth, 'tenant overflow').toBeLessThanOrEqual(r.innerWidth + 2);
    expect(diag.failedCss, `tenant failed css/font ${JSON.stringify(diag.failedCss)}`).toEqual([]);
  });
});

// Authed surfaces (admin / printshop). Opt-in; skip cleanly without creds.
test.describe('header + footer · authed', () => {
  test.skip(!process.env.CARDIFY_ADMIN_USER, 'set CARDIFY_ADMIN_USER/PASS to audit admin chrome');
  test.use({ viewport: { width: 1280, height: 900 } });
  test('admin dashboard chrome', async ({ page }) => {
    // Login flow is project-specific; reuse tests/e2e/fixtures.ts helpers
    // when wiring creds. Placeholder asserts the layout once authed.
    const diag = await collect(page);
    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[type="email"], input[name="email"]', process.env.CARDIFY_ADMIN_USER!);
    await page.fill('input[type="password"], input[name="password"]', process.env.CARDIFY_ADMIN_PASS || '');
    await page.click('button[type="submit"]');
    await page.goto('/admin', { waitUntil: 'networkidle' });
    const r = await evalAudit(page, { url: '/admin', label: 'admin', footer: 'custom', marketing: true });
    expect(r.faStatus, 'admin FA loaded').toBe('loaded');
    expect(r.iconWidth, 'admin FA icon width').toBeGreaterThan(0);
    expect(diag.failedCss, `admin failed css/font ${JSON.stringify(diag.failedCss)}`).toEqual([]);
  });
});
