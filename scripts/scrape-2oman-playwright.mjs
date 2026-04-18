#!/usr/bin/env node
/**
 * Scrape 2oman.net/omani_logo_library via headless Playwright.
 *
 * 2oman.net has a JS AES-cookie challenge that blocks plain HTTP. We use
 * Playwright's browser context so the cookie is set once and reused for
 * subsequent ctx.request.get() calls.
 *
 * Strategy:
 *   1. Warm up cookie via index page
 *   2. Fetch WP sitemap (llp_logo post type — the actual CPT slug)
 *   3. Visit each logo detail page, extract name + main logo asset URL
 *   4. Download the logo via ctx.request (cookie reused)
 *
 * Output:
 *   - /tmp/2oman-logos/*.{svg,png,jpg,webp}
 *   - /tmp/2oman-logos/index.json   [{name_en, name_ar, slug, src, file}]
 *
 * Run: node scripts/scrape-2oman-playwright.mjs [--limit=N]
 */
import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

const args = Object.fromEntries(
  process.argv.slice(2).flatMap(a => {
    const m = a.match(/^--([a-z-]+)(?:=(.+))?$/);
    return m ? [[m[1], m[2] ?? true]] : [];
  })
);
const LIMIT = args.limit ? parseInt(args.limit, 10) : 0;

const OUT_DIR = '/tmp/2oman-logos';
fs.rmSync(OUT_DIR, { recursive: true, force: true });
fs.mkdirSync(OUT_DIR, { recursive: true });

function slugify(name) {
  return (name || '').toString().toLowerCase()
    .replace(/[^a-z0-9]+/gi, '-')
    .replace(/^-+|-+$/g, '')
    .substring(0, 80) || '';
}

function nameFromUrl(u) {
  try {
    const base = new URL(u).pathname.split('/').pop() || '';
    const stem = base.replace(/\.[a-z0-9]+$/i, '');
    let humanized = stem.replace(/[-_]+/g, ' ').replace(/\s+/g, ' ').trim();
    // Strip trailing artboard counters: "Foo 01", "Foo 02 1", "Foo-01", "Foo 1"
    humanized = humanized.replace(/\s+\d{1,3}(\s+\d{1,3})?$/, '').trim();
    // Strip trailing locale marker "Ar" (Aquafina-Ar, etc.)
    humanized = humanized.replace(/\s+Ar$/i, '').trim();
    // Title-case when all lowercase (URL was lowercased)
    if (humanized === humanized.toLowerCase()) {
      humanized = humanized.replace(/\b\w/g, ch => ch.toUpperCase());
    }
    if (/^(svglogo|logo|image|img|file|asset)\s*\d*$/i.test(humanized)) return '';
    if (humanized.length < 2) return '';
    return humanized;
  } catch (e) { return ''; }
}

const browser = await chromium.launch({ headless: true });
const ctx = await browser.newContext({
  userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
  viewport: { width: 1200, height: 900 },
});
const page = await ctx.newPage();

console.log('[scrape] warming up cookie…');
await page.goto('https://www.2oman.net/omani_logo_library/', { waitUntil: 'networkidle', timeout: 45000 });
await page.waitForTimeout(800);

console.log('[scrape] fetching sitemap…');
const sm = await ctx.request.get('https://www.2oman.net/wp-sitemap-posts-llp_logo-1.xml');
const smBody = await sm.text();
let urls = [...smBody.matchAll(/<loc>([^<]+)<\/loc>/g)].map(m => m[1]);
console.log(`[scrape] total logo detail URLs: ${urls.length}`);
if (LIMIT) urls = urls.slice(0, LIMIT);

const all = [];
let ok = 0, fail = 0;

for (let i = 0; i < urls.length; i++) {
  const detailUrl = urls[i];
  try {
    await page.goto(detailUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.waitForTimeout(250);

    // Extract: h1/title (Arabic name) + the first substantial logo img.
    const info = await page.evaluate(() => {
      const title = (document.querySelector('h1')?.textContent
                   || document.querySelector('title')?.textContent
                   || '').trim().replace(/\s*[-–|—]\s*.*$/, '').trim();  // strip site suffix
      // Find logo-ish img: under wp-content/uploads, ignore site chrome.
      const imgs = Array.from(document.querySelectorAll('img'))
        .map(i => ({ src: i.getAttribute('src') || i.getAttribute('data-src') || '', w: i.naturalWidth, h: i.naturalHeight }))
        .filter(x => x.src
          && /wp-content\/uploads/.test(x.src)
          && !/logo-2oman|favicon|sprite|icon[-_]?home|footer|header|menu|avatar/.test(x.src)
          && /\.(svg|png|jpe?g|webp|gif)(\?|$)/i.test(x.src));
      // Prefer SVG (cleanest), then largest PNG/JPG by natural pixels
      imgs.sort((a, b) => {
        const svgA = /\.svg/i.test(a.src) ? 1 : 0;
        const svgB = /\.svg/i.test(b.src) ? 1 : 0;
        if (svgA !== svgB) return svgB - svgA;
        return (b.w * b.h) - (a.w * a.h);
      });
      return { title, src: imgs[0]?.src || '' };
    });

    if (!info.src) {
      fail++;
      console.log(`[${i+1}/${urls.length}] SKIP (no logo img) ${detailUrl}`);
      continue;
    }

    let abs = info.src;
    if (abs.startsWith('//')) abs = 'https:' + abs;
    else if (abs.startsWith('/')) abs = 'https://www.2oman.net' + abs;

    const nameEn = nameFromUrl(abs);
    const nameAr = info.title;
    const slug = slugify(nameEn) || slugify(nameAr) || `logo-${i+1}`;

    const resp = await ctx.request.get(abs, { timeout: 30000 });
    if (!resp.ok()) {
      fail++;
      console.log(`[${i+1}/${urls.length}] FAIL ${resp.status()} ${abs}`);
      continue;
    }
    const buf = await resp.body();
    if (buf.length < 200) { fail++; continue; }
    const ext = (path.extname(new URL(abs).pathname).replace('.', '') || 'png').toLowerCase();
    const file = `${slug}.${ext}`;
    fs.writeFileSync(path.join(OUT_DIR, file), buf);
    all.push({ name_en: nameEn || nameAr, name_ar: nameAr, slug, src: abs, file, detail_url: detailUrl });
    ok++;
    if (i % 10 === 0) console.log(`[${i+1}/${urls.length}] ${slug} (${ext}, ${(buf.length/1024).toFixed(1)}kB)`);
  } catch (err) {
    fail++;
    console.error(`[${i+1}/${urls.length}] ERROR ${detailUrl}: ${err.message}`);
  }
}

fs.writeFileSync(path.join(OUT_DIR, 'index.json'), JSON.stringify(all, null, 2));
console.log(`\n[scrape] done: ok=${ok} fail=${fail} / ${urls.length}`);
console.log(`[scrape] output: ${OUT_DIR}`);
await browser.close();
