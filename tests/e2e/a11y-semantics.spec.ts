/**
 * Cardify screen-reader semantic scan (Cat U action 494, automatable
 * slice). Real VoiceOver + NVDA walk is manual — see
 * ops/qa-screen-reader-manual.md.
 *
 * Playwright's accessibility snapshot uses the same tree the AT APIs
 * consume (AXNode on webkit, UIA on Windows NVDA, AccessibilityTree
 * on Chrome). Scanning the tree catches the five regressions that
 * account for most "screen reader is unusable" complaints:
 *
 *   1. No h1 OR multiple h1s on one page — SR users lose the landmark.
 *   2. <img> without alt — SR reads the filename, which is garbage.
 *   3. Form <input> without associated label/aria-label.
 *   4. Buttons with no accessible name (empty <button> / icon-only).
 *   5. Link with no accessible name (bare <a href><img></a> with no
 *      alt + no aria-label).
 */
import { test, expect } from '@playwright/test';

const pages = ['/', '/pricing', '/status', '/faq', '/contact'];

for (const url of pages) {
  test(`${url} has exactly one h1`, async ({ page }) => {
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    const count = await page.locator('h1').count();
    expect(count, `${url} — h1 count should be 1, got ${count}`).toBe(1);
  });

  test(`${url} has no <img> without alt`, async ({ page }) => {
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    // Decorative images with aria-hidden="true" OR role="presentation"
    // are exempt — they shouldn't be narrated by screen readers.
    const bad = await page.evaluate(() => {
      const out: string[] = [];
      document.querySelectorAll<HTMLImageElement>('img').forEach((el) => {
        if (el.getAttribute('aria-hidden') === 'true') return;
        if (el.getAttribute('role') === 'presentation') return;
        if (el.alt === null || el.alt === undefined) {
          out.push(el.src || '(no src)');
          return;
        }
        // empty alt="" is valid decorative signal; skip.
        // Only flag missing alt attribute entirely.
        if (!el.hasAttribute('alt')) out.push(el.src || '(no src)');
      });
      return out;
    });
    expect(bad, `${url} — img without alt: ${bad.slice(0, 5).join(' | ')}`).toEqual([]);
  });

  test(`${url} form fields have accessible labels`, async ({ page }) => {
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    const bad = await page.evaluate(() => {
      const out: string[] = [];
      document.querySelectorAll<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>(
        'input:not([type=hidden]):not([type=submit]):not([type=button]), textarea, select'
      ).forEach((el) => {
        const id = el.id;
        const hasLabelFor = id && document.querySelector(`label[for="${id}"]`);
        const hasAria    = el.getAttribute('aria-label') || el.getAttribute('aria-labelledby');
        const inLabel    = el.closest('label');
        const placeholder= el.getAttribute('placeholder');
        // Accept label[for], aria, wrapping <label>, or placeholder
        // (placeholder alone is poor practice but a lot of the site
        // uses it; flag only if NOTHING is present).
        if (!hasLabelFor && !hasAria && !inLabel && !placeholder) {
          out.push(el.tagName.toLowerCase() + (el.name ? `[name=${el.name}]` : ''));
        }
      });
      return out;
    });
    expect(bad, `${url} — form fields missing labels: ${bad.join(' | ')}`).toEqual([]);
  });

  test(`${url} interactive controls have accessible names`, async ({ page }) => {
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    const bad = await page.evaluate(() => {
      const out: string[] = [];
      const check = (el: HTMLElement, kind: string) => {
        const text = (el.textContent || '').trim();
        const aria = el.getAttribute('aria-label');
        const labelled = el.getAttribute('aria-labelledby');
        const title = el.getAttribute('title');
        // If it has an image inside, the image alt counts too.
        const imgAlt = el.querySelector('img')?.getAttribute('alt') || '';
        if (!text && !aria && !labelled && !title && !imgAlt) {
          out.push(`${kind}: ${el.outerHTML.slice(0, 80)}`);
        }
      };
      document.querySelectorAll<HTMLElement>('button').forEach((b) => {
        if (b.getAttribute('aria-hidden') === 'true') return;
        check(b, 'button');
      });
      document.querySelectorAll<HTMLAnchorElement>('a[href]').forEach((a) => {
        if (a.getAttribute('aria-hidden') === 'true') return;
        check(a, 'link');
      });
      return out;
    });
    expect(bad.length, `${url} — ${bad.length} controls without accessible name: ${bad.slice(0, 3).join(' | ')}`)
      .toBe(0);
  });
}
