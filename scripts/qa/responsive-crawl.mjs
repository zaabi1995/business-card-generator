import { chromium } from 'playwright';

const ROUTES = [
  '/', '/ar/', '/pricing', '/ar/pricing', '/get-started', '/ar/get-started',
  '/faq', '/ar/faq', '/about', '/ar/about', '/contact', '/ar/contact',
  '/nfc-business-card', '/ar/nfc-business-card', '/tools', '/ar/tools',
  '/solutions', '/ar/solutions', '/companies', '/ar/companies',
  '/oman-business-index', '/gcc-business-index', '/logos', '/case-studies',
  '/blog', '/press', '/changelog', '/status', '/print-shops',
  '/print-shops/register', '/ar/print-shops/register',
  '/login.php', '/company/register.php', '/app', '/tools/vcard-qr-generator',
  '/industries/oil-gas', '/business-card-scanner', '/ar/business-card-scanner',
];
const VIEWPORTS = [
  { name: '320', width: 320, height: 720 },
  { name: '390', width: 390, height: 844 },
  { name: '768', width: 768, height: 1024 },
  { name: '1440', width: 1440, height: 900 },
];

const base = process.env.BASE_URL || 'https://cardify.om';
const browser = await chromium.launch();
const issues = [];
let checks = 0;

for (const vp of VIEWPORTS) {
  const ctx = await browser.newContext({ viewport: { width: vp.width, height: vp.height } });
  const page = await ctx.newPage();
  const consoleErrors = new Map();
  page.on('console', m => {
    if (m.type() !== 'error') return;
    const u = page.url();
    if (!consoleErrors.has(u)) consoleErrors.set(u, []);
    consoleErrors.get(u).push(m.text().slice(0, 160));
  });
  for (const route of ROUTES) {
    checks++;
    try {
      const resp = await page.goto(base + route, { waitUntil: 'domcontentloaded', timeout: 30000 });
      if (!resp || resp.status() >= 400) {
        issues.push(`${vp.name} ${route} HTTP ${resp ? resp.status() : 'none'}`);
        continue;
      }
      await page.waitForTimeout(350);
      const r = await page.evaluate(() => {
        const de = document.documentElement;
        const over = de.scrollWidth - de.clientWidth;
        let worst = null;
        if (over > 1) {
          const vw = de.clientWidth;
          for (const el of document.querySelectorAll('body *')) {
            const b = el.getBoundingClientRect();
            if (b.width === 0 || b.height === 0) continue;
            const spill = Math.max(b.right - vw, -b.left);
            if (spill > 2 && (!worst || spill > worst.spill)) {
              worst = { spill: Math.round(spill), tag: el.tagName.toLowerCase(),
                        cls: (el.className || '').toString().slice(0, 90) };
            }
          }
        }
        return { over, worst, title: document.title.slice(0, 60) };
      });
      if (r.over > 1) {
        issues.push(`${vp.name} ${route} overflow ${r.over}px  ${r.worst ? r.worst.tag + '.' + r.worst.cls : '?'}`);
      }
    } catch (e) {
      issues.push(`${vp.name} ${route} ERROR ${String(e).slice(0, 90)}`);
    }
  }
  for (const [u, msgs] of consoleErrors) {
    for (const m of [...new Set(msgs)].slice(0, 2)) issues.push(`${vp.name} console ${u.replace(base,'')} :: ${m}`);
  }
  await ctx.close();
}
await browser.close();
console.log(`checks: ${checks}`);
console.log(`issues: ${issues.length}`);
for (const i of issues) console.log('  ' + i);
