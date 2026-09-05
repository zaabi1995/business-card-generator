import { chromium } from 'playwright';

const ROUTES = [
  '/', '/ar/', '/pricing', '/ar/pricing', '/get-started', '/ar/get-started',
  '/faq', '/ar/faq', '/about', '/contact', '/nfc-business-card', '/tools',
  '/tools/vcard-qr-generator', '/tools/whatsapp-qr-generator',
  '/tools/email-signature-generator', '/solutions', '/companies',
  '/oman-business-index', '/gcc-business-index', '/logos', '/case-studies',
  '/blog', '/press', '/changelog', '/status', '/print-shops',
  '/print-shops/register', '/ar/print-shops/register', '/login.php',
  '/company/register.php', '/app', '/industries/oil-gas', '/business-card-scanner',
  '/design-showcase', '/intro', '/compare', '/glossary', '/security', '/privacy', '/terms',
];
const base = process.env.BASE_URL || 'https://cardify.om';
const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1366, height: 900 } });
const page = await ctx.newPage();

const problems = [];
page.on('console', m => {
  const t = m.text();
  if (m.type() !== 'error') return;
  if (/Content Security Policy|Refused to (execute|load|apply|connect)|Alpine Expression Error/i.test(t)) {
    problems.push(`CSP  ${page.url().replace(base, '')} :: ${t.slice(0, 200)}`);
  } else {
    problems.push(`ERR  ${page.url().replace(base, '')} :: ${t.slice(0, 160)}`);
  }
});
page.on('pageerror', e => problems.push(`PAGEERR ${page.url().replace(base, '')} :: ${String(e).slice(0, 160)}`));

let n = 0;
for (const r of ROUTES) {
  n++;
  try {
    const resp = await page.goto(base + r, { waitUntil: 'networkidle', timeout: 35000 });
    if (!resp || resp.status() >= 400) { problems.push(`HTTP ${r} ${resp ? resp.status() : '?'}`); continue; }
    // give Alpine a beat and poke the mobile menu if one exists
    await page.waitForTimeout(500);
    const alpineOk = await page.evaluate(() => {
      if (!window.Alpine) return 'no-alpine';
      const roots = document.querySelectorAll('[x-data]');
      if (!roots.length) return 'no-roots';
      let live = 0;
      roots.forEach(el => { if (el.__x || el._x_dataStack) live++; });
      return live + '/' + roots.length;
    });
    if (typeof alpineOk === 'string' && alpineOk.includes('/')) {
      const [live, total] = alpineOk.split('/').map(Number);
      if (total > 0 && live === 0) problems.push(`ALPINE ${r} :: ${live}/${total} roots initialised`);
    }
    const acts = await page.evaluate(() => document.querySelectorAll('[data-cardify-action]').length);
    const cssOk = await page.evaluate(() => {
      const l = [...document.querySelectorAll('link[data-cardify-async-css]')];
      return l.length ? l.filter(x => x.media === 'all' || x.rel === 'stylesheet').length + '/' + l.length : 'none';
    });
    if (typeof cssOk === 'string' && cssOk.includes('/')) {
      const [ok, tot] = cssOk.split('/').map(Number);
      if (ok < tot) problems.push(`ASYNCCSS ${r} :: only ${ok}/${tot} activated`);
    }
  } catch (e) {
    problems.push(`NAV ${r} :: ${String(e).slice(0, 120)}`);
  }
}
await browser.close();
console.log(`routes: ${n}`);
console.log(`problems: ${problems.length}`);
for (const p of [...new Set(problems)]) console.log('  ' + p);
