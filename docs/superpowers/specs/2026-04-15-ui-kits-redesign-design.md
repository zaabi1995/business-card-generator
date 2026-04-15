# Cardify UI Refresh — Design Spec (2026-04-15)

## Goal
Visual-only refresh of Cardify.om using Tailwind UI v4 (Alpine) patterns. Live, paying system — no logic, no schema, no payment, no canvas editor changes.

## Scope (IN)
- Landing page (`index.php`): hero, features, pricing, testimonials, footer polish
- Auth: `login.php`, `company/register.php` (visual polish only; OTP/password logic untouched)
- Company admin dashboard: `admin/customer-dashboard.php` + `admin/index.php` (stat cards, tables, empty states)
- Settings / billing: `admin/billing.php`, `admin/settings.php`
- Print shop dashboard: `printshop/dashboard.php`, `printshop/orders.php` (shell only)
- Surrounding chrome of canvas editor: header bar + save/share/back buttons only

## Scope (OUT — DO NOT TOUCH)
- `customize.php` canvas editor internals (Fabric.js canvas, toolbars, layer panel, text/shape controls)
- `includes/Payment.php`, `paymob/*`, `amwalpay/*`, `bhd/*` callback paths
- `includes/Currency.php`, FX rates, multi-currency logic
- Card data schema, migrations, `generate_card_html.php`
- `printshop/template-editor.php` internals
- `digital_card.php` public render

## Brand palette (preserve — do not introduce new colors)
- Primary Blue: `#2563eb` (blue-600) → hover `#1d4ed8` (blue-700)
- Accent gradients already in use: blue-600 → indigo-600 for hero, blue-50 → purple-50 for info panels
- Success Green: `#16a34a` / WhatsApp `#25d366`
- Neutral: gray-50 bg, gray-900 text, gray-100/200 borders
- Font: Inter (already wired)

## Tailwind UI patterns to adopt
- **Hero**: `marketing/sections/heroes/05-split-with-image` — stronger headline hierarchy, eyebrow, dual CTA
- **Features**: `marketing/sections/feature-sections/07-with-product-screenshot` — 3-column with icon + product shot
- **Pricing**: `marketing/sections/pricing/02-with-featured-tier` — per-design tier table with "Popular" highlight (already present — polish spacing/typography)
- **Testimonials**: `marketing/sections/testimonials/01-grid` — clean 2-3 column grid
- **CTA**: `marketing/sections/cta-sections/02-simple-centered`
- **Footer**: `marketing/sections/footers/03-four-columns-with-newsletter`
- **Login split**: `marketing/sections/heroes/…` + existing pattern already aligned — minor polish
- **Dashboard stat cards**: `application-ui/lists/stacked-lists` + `application-ui/data-display/stats/01-simple`
- **Dashboard tables**: `application-ui/lists/tables/02-with-checkbox-action` — padding, header contrast, row dividers
- **Empty states**: `application-ui/feedback/empty-states/01-simple-with-icon`
- **Settings shell**: `application-ui/page-examples/settings-screens/01-full-width`
- **Application shell (sidebar)**: `application-ui/application-shells/sidebar/04-dark-sidebar` (keep light variant — inherits brand palette)

## Non-goals
- No new colors, icons, or fonts
- No library swaps (Flowbite stays, Alpine stays, Font Awesome stays)
- No build-system change (Tailwind CDN-style; content scans `**/*.php`)
- No i18n/content changes except cosmetic copy tightening

## Success criteria
- Visual parity with TailwindUI reference patterns
- Zero PHP fatal errors on all touched pages
- Auth flows still sign in (form fields + CSRF token unchanged)
- Admin sidebar nav unchanged structurally (only presentation)
- Mobile (375px) + desktop (1440px) render without overflow

## Risks & mitigations
- Dynamic Tailwind classes purge: keep all class strings static literals (no interpolated widths/cols)
- PHP includes assume certain CSS: keep cardify-overrides.css + techwind/tailwind.min.css unchanged
- Company-slug admin routing: all links use `getBasePath()` / `$ext` pattern — preserve
