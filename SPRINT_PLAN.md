# Cardify.om, Full-Sprint Plan (Apr 22, 2026)

> Goal: Take Cardify from "works for power users who already understand it" to "any employee at any business can register, onboard, design a card for their whole team, order the physical cards, distribute digital cards, and track engagement without ever reading docs, in either English or Arabic." Everything end-to-end. No half-shipped features, no English-only strings, no UX friction.

## 1. What Cardify Actually Is

Cardify is a **white-label SaaS business card platform** that sits between three actors:

1. **Companies** (the customer): A business that wants modern business cards, both printed and digital, for its employees. The company's HR/admin owns the tenant, uploads employees, picks a template, and distributes cards. Companies are the ones who pay (per-card or per-month).
2. **Employees** (the end user): Each employee gets a digital card (NFC tap, QR scan, direct link, `cardify.om/{company-slug}/{employee-slug}`, and optional custom domain), an Apple Wallet/Google Wallet pass, and optionally a physical card printed via a partner print shop. Employees edit their own name/title/socials via a lightweight self-service page, no login screen gymnastics.
3. **Print shops** (fulfillment): Partner printers claim incoming print orders, manage their own credit accounts with BHD/Cardify, quote per-company, and mark orders paid. This is what turns Cardify from "digital flex" into real physical card delivery.

Cardify also hosts two **free public tools** that drive SEO + top-of-funnel:
- **Omani Logo Library**, `/logos`, 2,400+ Omani companies with official logos, downloaded by designers.
- **Oman Business Index**, `/oman-business-index`, searchable CR directory.

The strategic premise: "Let printers and HR admins in Oman issue a full company's cards in 10 minutes, for OMR 1-3 per employee per year, with zero design work."

## 2. The Seven End-to-End Journeys

Every feature on Cardify must serve one of these seven journeys. If it doesn't, it gets deprecated. If a journey has any friction worse than "your competitor does this in 2 clicks," it gets fixed in this sprint.

### 2.1 Journey A, Company Registration
**Actor**: HR manager at a 30-person Omani company who just heard about Cardify from a referral.
**Path**: Lands on `/` → clicks "Start Free" → enters company name + admin email + phone → receives OTP → sets password → lands in onboarding wizard.
**Current state**: Works but forces password creation before value is shown, no OTP option, no Arabic UI, no guided tour, no "skeleton company" pre-filled with sample employees to play with.
**Target state**: OTP-first (passwordless, WhatsApp/Email), instantly inside a fully-populated demo tenant with 5 sample employees, a branded preview card, and a "replace demo data with real data" CTA. Full Arabic mirror.

### 2.2 Journey B, Onboarding Wizard (First 5 Minutes)
**Actor**: Same HR manager, first time logged in.
**Path**: 7-step wizard: (1) Upload logo, (2) Pick brand colors (eyedropped from logo), (3) Choose template (live preview rendered with real brand), (4) Add first employee, (5) See digital card preview, (6) Invite team via CSV or paste-list, (7) Order physical cards or skip.
**Current state**: No wizard exists. Admin lands on dashboard cold, has to figure it out.
**Target state**: Wizard is mandatory on first session, skippable later, resumable. Each step takes <30 seconds. Progress saved server-side. Full bilingual.

### 2.3 Journey C, Designing the Company Card
**Actor**: Admin with brand already uploaded.
**Path**: `/admin/templates` → pick a layout → customize (drag/drop Fabric.js canvas) → save as company default → lock so employees can't override → assign to departments if different templates per dept.
**Current state**: Template editor exists, but: (a) not company-wide defaults, (b) no department overrides, (c) no "preview with any employee" feature, (d) no versioning (if you edit, old cards break), (e) Fabric.js canvas is desktop-only.
**Target state**: One-click "Set as company default," per-department override, version history (every save = new version, old cards keep old version), mobile-friendly editor (touch drag on phones). Bilingual card support, auto-flip EN/AR side.

### 2.4 Journey D, Employee Self-Service
**Actor**: Employee who just received a WhatsApp message: "Hi Ahmed, here's your Cardify card: cardify.om/bhdoman/ahmed-xxxx. Tap to edit your details."
**Path**: Taps link → sees their card → taps "Edit" → magic-link OTP to their phone → updates name/title/phone/socials → saves → card updated everywhere instantly (including printed NFC tag if already printed).
**Current state**: No self-service edit flow. Admin has to edit every employee. Doesn't scale past ~20 people.
**Target state**: Every employee gets an edit token in their WhatsApp/email invite. Tap, edit, save, done. No password ever. Full Arabic.

### 2.5 Journey E, Physical Card Ordering
**Actor**: Admin who has finalized design and wants 100 printed cards (10 per employee, 10 employees).
**Path**: `/admin/print-orders` → auto-filled employee list → pick quantity per person → pick print shop from marketplace (or default to BHD print shop) → pick paper, finish → see quote in OMR → pay via Paymob/Credit/PO → order routes to print shop → tracked to delivery.
**Current state**: Order flow exists but is clunky, no marketplace browsing, no per-person quantity, no delivery tracking, Paymob works but credit accounts are confusing.
**Target state**: "Order cards" = 3 clicks. Marketplace shows print shops with ratings, turnaround, price/card. Per-employee quantity defaults to 100 but editable. Tracking page for admin. Paymob + Credit + PO + Cash all work, receipt auto-generated + sent.

### 2.6 Journey F, Analytics & Distribution
**Actor**: CEO who paid OMR 50 for cards and wants ROI.
**Path**: `/admin/analytics` → sees: card taps, contact saves, socials clicked, leads captured, which employee's card is most scanned, geographic heatmap of taps.
**Current state**: Basic analytics exist. Not per-card heatmap, not per-action breakdown, not exportable.
**Target state**: Dashboard that answers "was this worth it?" in 5 seconds. Top 10 employees by engagement. Conversion funnel (tap → contact save → WhatsApp click). Export to CSV/PDF. Monthly auto-emailed report. Full Arabic.

### 2.7 Journey G, Print Shop Marketplace & Fulfillment
**Actor**: A print shop in Salalah that wants Cardify jobs.
**Path**: `/printshop/register` → verify business → set services/pricing → approved → receives incoming orders → prints → marks shipped → gets paid via ERP integration.
**Current state**: Print shop portal exists, works, ERP integration live. Missing: public marketplace (`/print-shops/{slug}`), reviews, turnaround SLA display, geographic routing.
**Target state**: Admin picks print shop like picking an Uber, distance, rating, price, ETA visible upfront. Reviews collected after every order. Print shops get a public profile page to market themselves.

## 3. The Language Problem (i18n)

**Current state**: No i18n system at all. Every string is hard-coded English. Some pages have `name_en` + `name_ar` columns for data, but UI chrome, buttons, labels, errors, empty states, emails, OTP messages, print receipts, **all English**.

**Why this is a blocker**: Oman is bilingual. Cardify's target customer, Omani HR, Omani print shops, Omani government suppliers, expects Arabic. If we can't show the product in Arabic, we can't sell it to 60% of the market.

**The fix**:
1. Introduce a proper i18n system. PHP has no standard, so we build one:
   - `includes/I18n.php`, loads locale files, provides `t($key, $params)` helper.
   - `lang/en.php` + `lang/ar.php`, flat arrays of translations.
   - Per-section files loaded on demand: `lang/{locale}/admin.php`, `lang/{locale}/printshop.php`, `lang/{locale}/portal.php`, `lang/{locale}/emails.php`, `lang/{locale}/errors.php`.
   - Auto-detect via: `?lang=ar` → session cookie → `Accept-Language` → default `en`.
   - Persist via cookie `cardify_lang` (1-year).
   - RTL auto-toggle: `<html dir="<?= $dir ?>" lang="<?= $lang ?>">` everywhere.
   - Arabic font: IBM Plex Sans Arabic (weights 400/600/700), preloaded.
   - Currency format: always OMR 3-decimal, no locale switch (Oman is always OMR).
2. Every template gets wrapped: `<?= t('dashboard.welcome', ['name' => $name]) ?>` instead of `Welcome, <?= $name ?>`.
3. Every email template gets an Arabic mirror.
4. Every OTP/WhatsApp message template gets Arabic (using Dardasha line).
5. Every card layout supports bilingual front/back (EN front, AR back).
6. Admin-facing error strings translated.
7. Print shop portal translated (most print shop operators read Arabic faster).
8. OG images / share cards generated in both locales.

**Scope**: ~1,500 unique strings across the app. Not negotiable. All 5 locales eventually (en/ar core now, ur/hi/bn later for expats), but this sprint is **en + ar** only. Every new string committed in both locales in the same commit (Ali's rule).

## 4. The UX Problem (Ease of Use)

**Current state**: Power users who built the product understand it. Everyone else clicks around and gets lost. Observed friction points:

1. **Too many admin pages (47)**. Most users need ~6 of them. Navigation is a flat list. Solution: group into 5 sections (Dashboard, Team, Cards, Orders, Settings) with collapsible sidebar.
2. **No empty states**. Every list page shows "No results" with nothing to do. Solution: each empty state has a CTA + 30-second explainer.
3. **No inline help**. Users don't know what "credit_accounts.exposure_limit" means. Solution: tooltips on every form field with one-sentence plain-English explanation in current locale.
4. **No keyboard shortcuts**. Solution: `?` opens cheatsheet, common actions on `g d`, `g t`, `g o`, `c` = create.
5. **No undo**. Delete = permanent. Solution: soft-delete with 30-day restore for all user-generated data.
6. **Forms too long**. Employee create form has 18 fields. Solution: 3 required (name/email/title), rest under "Advanced" accordion.
7. **Dense tables**. Employee list is a 12-column table. Solution: card/grid view toggle, mobile auto-switch to cards.
8. **No progress feedback on slow actions**. Print order generation takes 15s, user thinks it's frozen. Solution: toast + progress bar + disable button.
9. **Error messages are raw**. "SQLSTATE[HY000]" surfaces to user. Solution: friendly error catch layer + report-to-admin link + i18n.
10. **No mobile admin**. Admin dashboard on phone is unusable. Solution: every admin page passes mobile QA; drawer sidebar; thumb-reachable action buttons.
11. **No bulk actions** except on employees. Solution: bulk on every list (bulk resend invite, bulk change template, bulk reprint).
12. **No preview before destructive action**. Solution: "About to delete 12 employees. This will invalidate 12 digital cards and 240 printed cards. Type DELETE to confirm."

## 5. The Design Language Problem

**Current state**: Mix of Tailwind defaults + BHD teal + Flowbite components + one-off CSS. Looks clean in some places, amateur in others. Not a design system.

**Target design language**: 
- **Primary**: BHD teal `#009bc1` (brand baseline).
- **Accent**: `#fb0` (yellow) for CTAs that want attention.
- **Neutral scale**: 9-step gray using OKLCH, never #000 or pure #fff.
- **Typography**: Inter (UI) + IBM Plex Sans Arabic (Arabic) + DM Mono (numerical/OMR). 
- **Spacing**: 4px base grid, 8/12/16/24/32/48/64/96.
- **Radius**: 8px default, 4px inputs, 16px cards, 999px pills.
- **Shadow**: 3 levels (hover-sm, card, modal).
- **Motion**: 150ms ease-out for all state transitions.
- **Icons**: Heroicons outline (24px) + Font Awesome Pro where Heroicons misses.
- **Tokens**: all in `assets/css/cardify-tokens.css`.
- **Components**: buttons, inputs, selects, cards, tables, modals, toasts, empty states, all defined once in `assets/css/cardify-components.css`, never inline-Tailwinded.
- **Overrides**: already have `cardify-overrides.css`, extend it, don't ship utility soup (per `feedback_cardify_design_language_rule`).

## 6. Architectural Gaps to Fix

Beyond the journeys, there are structural bugs/gaps that block the sprint's "super easy to use" promise:

1. **Onboarding wizard doesn't exist**: must be built from scratch (`admin/onboarding.php` + 7 steps).
2. **Employee self-service edit flow doesn't exist**: magic-link OTP + `portal/employee-edit.php`.
3. **Print shop marketplace doesn't exist**: `/print-shops` (public), `/print-shops/{slug}` (public profile), `/admin/order-checkout` picks from marketplace.
4. **Per-employee quantity on print orders**: current flow orders N cards of the same design for a company, can't mix.
5. **Department-level template override**: schema exists, UI doesn't.
6. **Version history on templates**: schema missing, must add.
7. **Soft-delete pattern**: add `deleted_at` + 30-day restore cron.
8. **Audit log**: exists (`AuditLog.php`), not surfaced in admin UI.
9. **Notification preferences**: users can't opt out of anything.
10. **Rate limits**: need per-endpoint rate limiting, critical for OTP endpoints (have abuse vector).
11. **OTP delivery**: currently email-only, needs WhatsApp via Dardasha integration per `otp-dardasha` skill.
12. **Email i18n**: every templated email needs an Arabic mirror in `lang/ar/emails.php`.
13. **Search**: no global search. Admin has to click through 47 pages to find a setting. Add cmd+K with fuzzy search.
14. **Mobile admin sidebar**: drawer off-canvas pattern.
15. **PWA manifest + service worker**: admin installs on phone.
16. **Performance**: some pages load 2MB of assets. Audit and lazy-load.
17. **N+1 queries**: employees list does 1+N queries, must batch.
18. **Caching**: no page cache, every public page hits DB for every visitor. Add Redis or file-based cache layer (already have `cache/` dir).
19. **Sitemap**: exists for logo library, incomplete for company pages.
20. **robots.txt**: needs audit.
21. **OG images**: use Playwright-rendered social cards per company.
22. **NFC write flow**: `nfc/` dir exists, flow unclear, must document + smoke-test end-to-end.
23. **Apple Wallet + Google Wallet**: code exists (`AppleWalletPass.php` + `GoogleWalletPass.php`), not wired to employee-facing page.
24. **Custom domain flow**: exists (`admin/custom-domains.php`), but DNS verification UX is developer-speak. Fix.
25. **ERP sync failure alerts**: when Cardify→ERP sync fails, no one is notified. Add Slack/WhatsApp alert.
26. **Backup**: no off-site DB backup cadence documented. Nightly dump to B2 or S3.
27. **Monitoring**: no uptime monitor, no error aggregation. Add StatusCake + Sentry (free tier).
28. **Paymob receipt**: minimal, should include tax breakdown + bilingual.
29. **Credit account approval UI for print shops**: improve with applicant profile + risk score.
30. **Company profile public page**: `/companies/{slug}` exists in logo library, extend to show cards/team publicly if company opts in.

## 7. Execution Model

This sprint is run as an **autonomous loop**. Every 10 minutes, an iteration fires:

1. Read `SPRINT_ACTIONS.md`, find the next unchecked action.
2. Invoke systematic-debugging + relevant domain skills.
3. Implement the action (may be a file change, SQL migration, new page, or investigation).
4. Verify (manual load via deploy-cardify.sh + browse or QA script).
5. Commit + push to main + run deploy script on VPS.
6. Check the action, append a one-liner to `SPRINT_LOG.md` with commit SHA + timestamp.
7. If the action revealed new subtasks, append them to the bottom of `SPRINT_ACTIONS.md` with owner note.
8. Exit. Next tick picks up from there.

**Stopping condition**: `SPRINT_ACTIONS.md` has zero unchecked actions AND a final self-review iteration passes with no new actions added.

**Guardrails**:
- Every change commits + pushes + deploys. No staging.
- Every new string lands in both `lang/en.php` + `lang/ar.php`.
- Every new admin page added to `company_admin.php` $pageMap.
- Every new table uses `utf8mb4_unicode_ci`.
- Every new form includes CSRF.
- Every new payment path has HMAC + idempotency.
- No destructive operation runs without "typed confirmation" UI.
- OTP tests use Ali's number `+96871616161` only.
- MHD-group excluded from any outreach.
- No em dashes in any output.

## 8. Action Categories (mapped to actions in SPRINT_ACTIONS.md)

| Category | Action Range | Count |
|---|---|---|
| A, i18n Infrastructure | 001, 020 | 20 |
| B, i18n Page-by-Page | 021, 080 | 60 |
| C, Onboarding Wizard | 081, 110 | 30 |
| D, Company Registration Redesign | 111, 125 | 15 |
| E, Employee Self-Service | 126, 150 | 25 |
| F, Template Editor Upgrades | 151, 180 | 30 |
| G, Print Order Flow | 181, 210 | 30 |
| H, Print Shop Marketplace | 211, 235 | 25 |
| I, Analytics Dashboard | 236, 260 | 25 |
| J, Admin UX (navigation, search, mobile) | 261, 295 | 35 |
| K, Design System Tokens + Components | 296, 320 | 25 |
| L, Empty States + Tooltips | 321, 340 | 20 |
| M, Forms + Validation | 341, 355 | 15 |
| N, Performance (N+1, caching, lazy) | 356, 370 | 15 |
| O, Notifications + Emails | 371, 390 | 20 |
| P, Audit Log + Soft Delete + Undo | 391, 405 | 15 |
| Q, Security (rate limits, CSRF sweep) | 406, 420 | 15 |
| R, Public Pages + SEO | 421, 440 | 20 |
| S, ERP Sync + Billing | 441, 455 | 15 |
| T, Monitoring + Backup + Ops | 456, 470 | 15 |
| U, QA Pass (end-to-end journeys) | 471, 495 | 25 |
| V, Final Polish + Release Notes | 496, 510 | 15 |

**Total initial actions: ~510**. New actions appended as discovered.

## 9. Feature-Completeness Definition (when is Cardify "done")

Cardify is done for this sprint when:
1. A brand-new Omani company admin can land on `/`, switch UI to Arabic, register with OTP, get through the onboarding wizard, upload a 30-person roster, design a card, order 300 printed cards, and see them tracked to delivery, all without touching English.
2. Every employee they invited received a WhatsApp message with their card link, edited their own details, and got their Apple Wallet pass.
3. Every print shop in the marketplace is browsable in Arabic, with reviews, turnaround, and price upfront.
4. Analytics dashboard shows taps, saves, geographic heatmap, and emails the admin a monthly report in Arabic.
5. Mobile works for every admin page.
6. Performance: every admin page <1.5s on 3G-emulated connection.
7. 100% of user-facing strings have Arabic translations.
8. Zero open P1/P2 bugs from QA pass.
9. Release notes published at `/changelog` in both locales.
10. Monitoring alerts wired (Sentry + uptime).

## 10. Rollback Plan

Every commit is atomic and reversible. If a deploy breaks prod:
1. `ssh root@147.93.20.54 "cd /www/wwwroot/cardify.om && git log --oneline -5"`
2. `git reset --hard <last-good>`
3. `chown -R www:www . && chmod -R 755 .`
4. Reload OPcache.

Data migrations are forward-compatible (new columns nullable, new tables standalone). Schema changes never drop columns in the same release as code that depends on them.

## 11. Post-Sprint Plan

After this sprint concludes:
- Enable Ur/Hi/Bn locales (expat workforce).
- Add Stripe for non-Omani customers.
- Ship the affiliate program (print shops earn referral fees).
- Add a white-label mode (print shops resell Cardify as their own brand).
- Build a mobile app (Capacitor wrapper on the admin PWA).

But none of that is in scope for this 10-minute-loop sprint.
