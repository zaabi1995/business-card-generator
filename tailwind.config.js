/**
 * The build that produces assets/techwind/css/tailwind.min.css.
 *
 * That file had not been regenerated since 16 April 2026 while pages kept
 * adding classes, so 136 utilities the site actually uses rendered as nothing.
 * text-start left the get-started hero centered at desktop, rounded-3xl left
 * the demo card square, scroll-mt-20 dropped anchor jumps under the sticky
 * header, and tabular-nums did nothing on nine analytics tables.
 *
 * Rebuild with: npm run build:css
 * tests/php/tailwind_build_test.php fails when a used utility is missing from
 * the shipped file, so this cannot rot silently again.
 */
module.exports = {
  content: [
    './**/*.php',
    './**/*.html',
    './assets/js/cardify-*.js',
    './assets/js/card-editor.js',
    '!./node_modules/**',
    '!./vendor/**',
    '!./.worktrees/**',
    '!./web-react/node_modules/**',
  ],
  theme: { extend: {} },
  plugins: [],
};
