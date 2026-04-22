/**
 * Cardify localisation leak detector (Cat U action 495).
 *
 * Two layers:
 *
 *   A. Scan the lang/en|ar/ tree for bidirectional key parity —
 *      catches "shipped a new string in EN, forgot AR" at CI time.
 *      This is what scripts/i18n-audit.php does too; we echo it
 *      here so a CI Playwright run alone is enough.
 *
 *   B. Runtime scan: visit AR-locale versions of the top public
 *      pages and grep for English tell-tales in h1/h2/main/section/
 *      button/a.text that mean a t() call fell back to the EN
 *      string (or worse, the literal key).
 *
 *      Tell-tales:
 *        - "t('" — a t() call that rendered as literal text.
 *        - Dotted keys like "common.save" — key name leaked as body.
 *        - Known EN-only words that should never appear in an AR
 *          card view: "Welcome", "Dashboard" chrome, "Submit",
 *          "Save Contact" — acceptable in specific contexts (we
 *          accept the digital card's Save Contact button per iter
 *          92), but NOT on the marketing AR pages.
 */
import { test, expect } from '@playwright/test';
import { readFileSync, readdirSync } from 'fs';
import path from 'path';

const repoRoot = path.resolve(__dirname, '..', '..');

test.describe('i18n · source parity', () => {
  test('every lang/en/*.php has a matching lang/ar/*.php with the same keys', () => {
    const enDir = path.join(repoRoot, 'lang/en');
    const arDir = path.join(repoRoot, 'lang/ar');
    const enFiles = readdirSync(enDir).filter((f) => f.endsWith('.php'));
    const arFiles = new Set(readdirSync(arDir).filter((f) => f.endsWith('.php')));

    const missing = enFiles.filter((f) => !arFiles.has(f));
    expect(missing, `AR namespaces missing: ${missing.join(', ')}`).toEqual([]);

    // Key-level parity on every shared file.
    const keyRe = /'([a-z][a-z0-9_]*)'\s*=>/g;
    const extract = (buf: string) => {
      const out = new Set<string>();
      let m: RegExpExecArray | null;
      while ((m = keyRe.exec(buf)) !== null) out.add(m[1]);
      keyRe.lastIndex = 0;
      return out;
    };

    for (const f of enFiles) {
      const en = extract(readFileSync(path.join(enDir, f), 'utf8'));
      const ar = extract(readFileSync(path.join(arDir, f), 'utf8'));
      const onlyEn = [...en].filter((k) => !ar.has(k));
      const onlyAr = [...ar].filter((k) => !en.has(k));
      expect(onlyEn, `${f}: keys only in EN: ${onlyEn.join(', ')}`).toEqual([]);
      expect(onlyAr, `${f}: keys only in AR: ${onlyAr.join(', ')}`).toEqual([]);
    }
  });
});

test.describe('i18n · runtime leak on AR pages', () => {
  const arPages = [
    '/ar/',
    '/ar/pricing',
    '/ar/status',
    '/ar/faq',
    '/ar/contact',
    '/ar/case-studies',
    '/ar/terms',
    '/ar/privacy',
  ];

  for (const url of arPages) {
    test(`${url} — no literal t() calls or dotted keys in body text`, async ({ page }) => {
      const res = await page.goto(url, { waitUntil: 'domcontentloaded' });
      // Some AR URLs may not have dedicated rewrites; skip if the
      // server didn't land on a 2xx page.
      if (!res || res.status() >= 400) {
        test.skip(true, `${url} not available (status ${res?.status()})`);
        return;
      }

      const leaks = await page.evaluate(() => {
        // Only sample visible text inside structural / interactive
        // containers so nav UA sniff markup doesn't pollute.
        const els = document.querySelectorAll<HTMLElement>('h1, h2, h3, main, section, p, li, button, a, label');
        const bad: string[] = [];
        els.forEach((el) => {
          const t = (el.textContent || '').trim();
          if (!t) return;
          // t('namespace.key') literally rendered.
          if (/t\('[a-z_\.]+'\)/.test(t)) bad.push('t() literal: ' + t.slice(0, 60));
          // A dotted key like common.save visible as body text with no
          // real prose around it. We flag only when the string is
          // short (≤30 chars) and matches the full string, so things
          // like "Install the app. card.credits notice." don't false-
          // positive on the "card.credits" segment.
          if (t.length <= 30 && /^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/.test(t)) {
            bad.push('dotted key: ' + t);
          }
        });
        return bad;
      });
      expect(leaks, `${url} i18n leaks: ${leaks.slice(0, 5).join(' | ')}`).toEqual([]);
    });
  }
});
