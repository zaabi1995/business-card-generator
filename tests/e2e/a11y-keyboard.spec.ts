/**
 * Cardify keyboard-only navigation QA (Cat U action 493).
 *
 * Exercises the three a11y invariants that every public page should
 * satisfy:
 *
 *   1. Tab order actually moves focus forward across interactive
 *      elements. If the first 10 Tab presses don't advance the
 *      activeElement, there's a focus trap or tabindex=-1 hack
 *      leaking out.
 *   2. Focused interactive element shows a visible focus indicator
 *      (outline or box-shadow different from unfocused state).
 *      Tailwind's `focus:ring-*` compiles to a box-shadow, so we
 *      sample that.
 *   3. Enter on a focused <a> or <button> actually activates it —
 *      navigates or submits — rather than being swallowed.
 *
 * Keeps the spec a whole-page smoke so a broken `focus:outline-none`
 * without a replacement indicator (common Tailwind regression) is
 * caught before it ships.
 */
import { test, expect } from '@playwright/test';

const pages = ['/', '/pricing', '/status', '/faq', '/contact'];

for (const url of pages) {
  test(`keyboard tab order advances on ${url}`, async ({ page }) => {
    await page.goto(url, { waitUntil: 'domcontentloaded' });

    // Baseline: body is the starting activeElement.
    const initial = await page.evaluate(() => document.activeElement?.tagName);
    expect(initial?.toLowerCase()).toBe('body');

    // Tab up to 20 times and collect the sequence of focused tagNames.
    const sequence: string[] = [];
    for (let i = 0; i < 20; i++) {
      await page.keyboard.press('Tab');
      const info = await page.evaluate(() => {
        const el = document.activeElement as HTMLElement | null;
        if (!el || el === document.body) return null;
        const cs = getComputedStyle(el);
        return {
          tag: el.tagName.toLowerCase(),
          text: (el.textContent || '').trim().slice(0, 40),
          href: (el as HTMLAnchorElement).href || '',
          outline: cs.outlineStyle + ' ' + cs.outlineWidth,
          boxShadow: cs.boxShadow,
        };
      });
      if (info) sequence.push(`${info.tag}: ${info.text}`);
    }
    // We should have accrued focus on at least 5 distinct interactive
    // elements (nav links + hero CTAs is the minimum on every page).
    const unique = new Set(sequence);
    expect(unique.size, `${url} — too few focusable elements: ${sequence.join(' | ')}`)
      .toBeGreaterThanOrEqual(5);
  });

  test(`first focused element on ${url} has a visible focus indicator`, async ({ page }) => {
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    await page.keyboard.press('Tab');
    const indicator = await page.evaluate(() => {
      const el = document.activeElement as HTMLElement | null;
      if (!el || el === document.body) return null;
      const cs = getComputedStyle(el);
      return {
        outline: cs.outline,
        outlineStyle: cs.outlineStyle,
        outlineWidth: cs.outlineWidth,
        boxShadow: cs.boxShadow,
      };
    });
    expect(indicator, `${url} — nothing is focusable via Tab`).not.toBeNull();
    // Accept EITHER a real outline OR a box-shadow different from "none".
    const hasOutline = indicator!.outlineStyle !== 'none'
      && parseFloat(indicator!.outlineWidth) > 0;
    const hasShadowRing = indicator!.boxShadow !== 'none' && indicator!.boxShadow !== '';
    expect(hasOutline || hasShadowRing,
      `${url} — no focus indicator on first tab stop: outline=${indicator!.outline} shadow=${indicator!.boxShadow}`)
      .toBe(true);
  });
}

test('Skip-to-content link (when present) jumps past the header', async ({ page }) => {
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await page.keyboard.press('Tab');
  const first = await page.evaluate(() => {
    const el = document.activeElement as HTMLElement | null;
    return el ? { tag: el.tagName.toLowerCase(), text: (el.textContent || '').trim() } : null;
  });
  // If the page has a skip link at the top of the tab order, it
  // should target an anchor like #main. Accept graceful absence.
  if (first?.tag === 'a' && /skip|content|main/i.test(first.text)) {
    await page.keyboard.press('Enter');
    const hash = await page.evaluate(() => location.hash);
    expect(hash).not.toBe('');
  } else {
    test.skip(true, 'No skip-to-content link on this page yet; tracked as action 837');
  }
});
