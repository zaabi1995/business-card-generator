# Cardify UI Refresh — Execution Plan (2026-04-15)

Branch: `ui-kits-redesign`. Visual-only. No logic changes.

## Tasks

### T1 — Landing hero + features (index.php)
- Update hero layout: larger headline type, eyebrow pill, stronger CTA pair (Start Free / WhatsApp demo)
- Tighten feature grid: 3-up cards with icon badges, hover lift
- Keep existing pricing tier table (already reworked 15 Apr) but polish card shadows, spacing, "Popular" ribbon
- Polish testimonials section into bordered cards

### T2 — CTA + footer polish (index.php)
- Replace final CTA with centered gradient block
- Footer: 4-column w/ newsletter input (visual only, action points to existing endpoint or `#`)

### T3 — Auth polish (login.php, company/register.php)
- Preserve all form names/CSRF/fields
- Tighten spacing, consistent input styling, dual-panel on desktop, single column on mobile
- Social-proof strip below form (trust badges already exist — keep)

### T4 — Admin dashboard shell (includes/admin-layout.php)
- Sidebar: refined padding, active-state treatment (left border accent + bg-blue-50)
- Top bar: subtle border, user avatar dropdown polish
- No nav item changes (same keys, icons, URLs)

### T5 — Customer dashboard (admin/customer-dashboard.php, admin/index.php)
- Stat cards: 4-up using TailwindUI stats pattern, icon + label + value + delta
- Activity list: stacked list w/ avatars
- Empty states: icon + title + description + primary action
- Tables: consistent header chip, zebra-free, clean dividers

### T6 — Settings + billing (admin/billing.php, admin/settings.php)
- Section cards with header + description + content
- Form inputs consistent radius/border
- Plan selector: preserve values, polish card design

### T7 — Print shop dashboard (printshop/dashboard.php, printshop/orders.php)
- Stat row + orders table using same dashboard patterns
- Status badges: consistent pill styles

### T8 — Canvas editor chrome only (customize.php)
- DO NOT edit canvas, toolbars, layer panel
- Only: top header (back button, save/share/export buttons), sidebar collapse toggle styling
- Verify unchanged JS selectors (data-* attrs and ids preserved)

### T9 — Verification
- Open each touched file in PHP lint (`php -l`)
- Grep for accidentally introduced dynamic Tailwind classes
- Smoke: `php -S localhost:8000` + curl homepage/login/billing returns 200 (or 302 if auth redirect)

### T10 — Commit + PR
- One commit per task group (T1-2 landing, T3 auth, T4-5 dashboard, T6 settings, T7 printshop, T8 editor chrome)
- Push, open PR, do not merge

## Files touched (expected)
- `index.php`
- `login.php`, `company/register.php`
- `includes/admin-layout.php`, `includes/ui-header.php` (minimal), `includes/ui-footer.php`
- `admin/customer-dashboard.php`, `admin/index.php`
- `admin/billing.php`, `admin/settings.php`
- `printshop/dashboard.php`, `printshop/orders.php`
- `customize.php` (header/buttons section only)

## Out of scope (reconfirm)
- No changes to Paymob/Amwal/BHD callbacks
- No changes to Fabric.js canvas code
- No changes to card data schema or migrations
- No changes to `includes/Currency.php`
