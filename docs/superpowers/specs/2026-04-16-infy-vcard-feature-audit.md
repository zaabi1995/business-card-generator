# Infy vCard SaaS v14.8.0 — Feature Audit & Cardify Port Recommendations

**Date:** 2026-04-16
**Source:** CodeCanyon item 35815965 (Regular License purchased by Ali)
**Archive analyzed:** `/Users/ali/Downloads/Infy vCard SaaS v14.8.0.rar` (277 MB)
**Extraction path:** `/Users/ali/claude/tmp/infy-vcard/` (local only — NOT installed)
**Purpose:** Identify features worth porting into Cardify (cardify.om) as a clean-room reimplementation.

> Infy's PHP code is licensed and MUST NOT be copied verbatim. This audit captures *patterns* and *feature ideas*; implementation in Cardify must be rewritten from scratch against Cardify's own raw-PHP + Tailwind + Fabric.js stack.

---

## 1. Infy Stack (one line)

**Laravel 10 + Livewire 3 + Alpine.js + Tailwind + Fabric.js 5 + Laravel Mix**, multi-tenant via `stancl/tenancy`, modular via `nwidart/laravel-modules`, PHP 8.1+.

### Supporting packages (notable)
- **Payments:** Stripe, PayPal, PayPal Payouts, Razorpay, Paystack, Flutterwave, Iyzico, MercadoPago, SSLCommerz, Cashfree, Phonepe, Payfast (12 gateways)
- **QR:** `bacon/bacon-qr-code`, `simplesoftwareio/simple-qrcode`, `werneckbh/laravel-qr-code`
- **vCard file:** `jeroendesloovere/vcard` (.vcf export)
- **PDF:** `barryvdh/laravel-dompdf`
- **Excel:** `maatwebsite/excel`
- **Media:** `spatie/laravel-medialibrary`
- **Permissions:** `spatie/laravel-permission`
- **Social login:** `laravel/socialite` + Apple provider
- **2FA:** `pragmarx/google2fa-laravel`
- **Impersonation:** `lab404/laravel-impersonate`
- **Geo:** `stevebauman/location`
- **reCAPTCHA:** `biscolab/laravel-recaptcha`
- **OneSignal push:** `berkayk/onesignal-laravel`
- **PWA:** `ladumor/laravel-pwa`
- **Frontend libs:** fabric, chart.js, fullcalendar, intl-tel-input, quill, summernote, slick-slider, shepherd.js, flatpickr, pickr color picker

### Tenancy model
Uses `stancl/tenancy` with domain-based tenant routing (`Route::domain('{alias}')`), plus a `checkCustomDomain` middleware for BYO domains.

---

## 2. Complete Feature Inventory

### 2.1 vCard templates
- **40 new templates** (`resources/views/vcardTemplates/vcard1.blade.php` … `vcard40.blade.php`)
- **~30 legacy templates** in `oldVcardTemplates/` (kept for backwards-compat)
- Each template is a standalone mobile-first single-page layout
- Shared feature blocks per template: contact-request, product-buy, appointment, products grid, gallery, testimonials, services, blog, iframes, custom links, social icons, business hours
- Templates support theme color override (user picks primary color)

### 2.2 vCard builder (User / Company admin side)
From `resources/views/vcards/`:
- Create/edit vCard with: name, title, company, phone(s), email(s), address(es), website, bio, social links, business hours, theme color, logo, cover
- **Sections** (toggle on/off per card): services, products, gallery (+ categories), testimonials, blog posts, custom links, banners, iframes, Instagram embed, LinkedIn embed, payment link
- **Appointments / scheduling** (calendar + booking form, types, confirmations)
- **Contact request / enquiry form** (lead capture)
- **Email subscribers** collection + bulk email
- **Product catalog** with buy flow + transactions
- **AI description generator** (`ai-description/`, `ai-generate/` folders) — OpenAI-assisted bio/copy writing
- **Dynamic vCards** (QR editable without reprinting)
- **Custom domain** per vCard (CNAME to tenant)
- **Virtual backgrounds** for video calls
- **Analytics** per card (`analytic.blade.php`, `sub_analytics.blade.php`): views, clicks, referrers, devices, countries, time-series charts

### 2.3 User features
- Gallery with categories
- Testimonials
- Blog (per vCard)
- Subscribers list
- Payment link generator
- Delete account self-service
- Password management
- **Multi-vcard** per user subscription
- **WhatsApp store** module (full storefront: products, orders, transactions, shipping/refund/T&C pages, trending videos, privacy policy, email subscriptions — it's basically a second product)
- **NFC card orders** — user can order a physical NFC card (NfcCardOrder + NfcOrderTransaction models)

### 2.4 SaaS platform features (Admin / Super-admin)
From `resources/views/sadmin/`:
- **Plans & subscriptions** (PlanController, Subscription, PlanFeature, PlanCustomField, PlanTemplate)
- **Coupon codes** + usage tracking (CouponCode, UsedCouponCode)
- **Currencies** multi-currency store
- **Languages** UI (14 langs bundled: ar, de, en, es, fa, fr, hi, it, pt, ru, tr, vi, zh — `lang/` dir)
- **Affiliate program** (AffiliateUser, AffiliationWithdraw, Withdrawal, WithdrawalTransaction) — referral commissions with payout requests
- **Email templates** (editable in admin, per-event)
- **Countries / states / cities** tables
- **About us / Our mission / What drives us / Team** (custom page CMS)
- **Custom pages / Custom links**
- **Front sliders / Banners / FAQs / Testimonials** for marketing landing
- **Contact us** submissions
- **Blog engine** (front + per-vcard)
- **NFC card order management** (shipping)
- **Send bulk mail**
- **Roles & permissions** (spatie)
- **Admin user impersonation** (login as any user to debug)
- **Payment gateway toggles** (12 gateways)
- **Settings** (SEO meta, OG, social, SMTP, reCAPTCHA, cookie consent, policies)
- **Sitemap generator**
- **Log viewer** (opcodesio)

### 2.5 Analytics (built-in)
- Per-vCard view tracking (`Analytic` model) — IP, device, browser, country, referrer, timestamp
- Jenssegers/agent for UA parsing
- stevebauman/location for geo-IP
- Chart.js dashboards: time-series, top sources, device breakdown, country map
- Click tracking on CTA buttons (phone, email, web, social, custom link)

### 2.6 Payments (12 gateways)
Stripe, PayPal, PayPal Payouts (for affiliate withdrawals), Razorpay, Paystack, Flutterwave, Iyzico (Turkey), MercadoPago (LatAm), SSLCommerz (BD), Cashfree (IN), Phonepe (IN), Payfast (ZA). Used for: plan subscriptions, product purchases, appointment payments, NFC card orders, payment links.

### 2.7 Auth & security
- Email/password + Google + Apple + other socialite providers
- **2FA** via Google Authenticator (TOTP), recovery codes download/regenerate
- Email verification
- reCAPTCHA on auth forms
- Password reset
- Session impersonation (admin → user)
- XSS middleware on super-admin routes
- Cookie consent banner

### 2.8 i18n / localization
14 languages bundled. `mariuzzo/laravel-js-localization` exposes translations to JS. RTL handled (ar, fa).

### 2.9 PWA
`ladumor/laravel-pwa` — installable on mobile home screen.

### 2.10 Other modules / curiosities
- **Add-ons marketplace** (AddOnController) — admin can install extensions
- **Modules system** (Nwidart): Test, GoogleWallet, SlackIntegration, MercadoPago, TwofactorAuthentication — hot-pluggable
- **Google Wallet** pass generation (module, disabled by default)
- **Slack integration** module
- **Storage limit** per plan (StorageLimitController)
- **Google Fonts** picker per template

### 2.11 Landing / marketing site
8 pre-designed home layouts (`front/home/home-blog.blade.php` → `home-blog4`, plus business-directory variants) — a full marketing site comes in the box.

---

## 3. Cardify Current Feature Set (for comparison)

From `/Users/ali/claude/projects/cardify.om/`:

### Stack
Raw PHP 7.4+ (no framework) + MySQL (PDO singleton) + Tailwind + Flowbite + Alpine + Fabric.js 7.1 + HTML2Canvas. PHPMailer SMTP. Paymob + Amwal Pay. WhatsApp via Dardasha REST.

### What Cardify already has
- ✅ Multi-tenant (company slug + company_admin scoped URLs)
- ✅ Company admin + super admin + employee + print-shop roles (`Auth.php`)
- ✅ **Print shop integration** (Cardify's unique strength: credit accounts, billing, order PDFs, proof sheets, PO numbers, Odoo/ERP sync) — Infy has none of this
- ✅ Employee onboarding, CVF export (`vcf.php`), QR (`qr.php`)
- ✅ Card requests + approval workflow
- ✅ Bilingual fields (migration 011), multi-currency (per-company), company currency
- ✅ Email logs, password reset tokens, audit logs
- ✅ Paymob + Amwal Pay
- ✅ Blog + Careers
- ✅ QR scan tracking (migration 012)
- ✅ Department templates, template pairs, department portals
- ✅ Fabric.js card editor (save_card_*.php, digital_card.php)
- ✅ Print-ready PDF generation
- ✅ WhatsApp notifications (Dardasha)

### What Cardify lacks vs Infy
See gap analysis below.

---

## 4. Feature Gap Analysis

Legend: ✅ already have | 🟡 port-worthy | 🔴 skip

| Feature | Status | Effort | Note |
|---|---|---|---|
| 40 mobile-first public vCard templates | 🟡 Inspiration | **L** | Cardify's templates are print-focused business cards; Infy's are **public shareable landing pages** (like Linktree). Different product. Worth adding as a new "Public Profile" mode for employees. |
| Public vCard at `/{slug}/{employee-slug}` (card-as-landing-page) | 🟡 | **M** | Currently Cardify employees have a card but not a rich public profile with sections. Low-effort first version: one template + bio + social + gallery + contact form. |
| Lead capture / Contact Request form | 🟡 | **S** | Add a `card_leads` table + form on public card page + admin inbox. 1 day. |
| Appointment booking with calendar | 🟡 | **M** | FullCalendar.js + business hours + booking form + email confirmation. Valuable for service businesses using Cardify. |
| Analytics per card (views, clicks, geo, device) | 🟡 **HIGH VALUE** | **M** | Cardify has QR scan tracking (migration 012) but not full analytics. Extend: log each page view + CTA click, chart in company admin. ~2–3 days. |
| AI bio/description generator (OpenAI) | 🟡 | **S** | Small — a `/api/ai/generate-bio` endpoint that calls Anthropic/OpenAI with job title + industry. 1 day. |
| Gallery / Portfolio section per card | 🟡 | **S** | Upload images with captions, lightbox on public card. |
| Testimonials per card | 🟡 | **S** | Simple CRUD + display. |
| Services list per card | 🟡 | **S** | Title + desc + price/currency. |
| Custom links (Linktree-style) | 🟡 | **S** | Already partially supported via social icons; extend to arbitrary URL+icon+label. |
| Business hours on public card | 🟡 | **S** | JSON column + display block. |
| Dynamic QR (editable target without reprint) | 🟡 **HIGH VALUE** | **M** | Cardify prints physical cards — if QR becomes editable, customer can update card destination without reprint. Huge retention lever. |
| vCard (.vcf) "Save contact" button | ✅ | — | Cardify has `vcf.php`. |
| NFC card orders (physical ordering) | 🔴 | — | Cardify already does physical print; NFC is a future product, not a port. |
| Affiliate program + payouts (PayPal) | 🔴 | — | Over-scope for Cardify's b2b positioning. Revisit later if self-serve tier grows. |
| 12 payment gateways | 🔴 | — | Cardify is Oman-first — Paymob + Amwal Pay cover the market. Don't dilute. |
| Plans / subscription billing | 🟡 | **M** | Cardify has `plans.php` + `billing.php` already; review Infy's PlanFeature/PlanCustomField pattern for per-feature gating. Small refactor. |
| Coupon codes | 🟡 | **S** | Simple addition to billing. |
| 2FA (Google Authenticator TOTP) | 🟡 | **S** | Add for super-admin + print-shop-admin first. Use `pragmarx/google2fa` or equivalent. |
| Admin user impersonation ("login as") | 🟡 **HIGH VALUE** | **S** | Super-admin logs in as a company_admin for support. ~4 hours. |
| Multi-language UI (beyond EN/AR) | ✅ partial | — | Cardify has AR+EN; more languages not a priority. |
| Custom domain per company | 🟡 | **M** | CNAME + nginx SNI + DB lookup middleware. Enterprise plan upsell. |
| PWA install | 🟡 | **S** | Manifest + service worker for offline card view. |
| Email templates editable in admin | 🟡 | **S** | Cardify hardcodes emails; move to DB-driven templates. |
| Blog engine | ✅ | — | `blog.php` exists. |
| FAQ / Testimonials on marketing site | ✅ partial | — | FAQ exists; add testimonials section. |
| Front sliders / Banners (homepage CMS) | 🟡 | **S** | Minor marketing polish. |
| Cookie consent banner | 🟡 | **S** | GDPR baseline. |
| Log viewer in admin | 🟡 | **S** | Tail last 500 lines of `logs/` into an admin page. |
| Google/Apple social login | 🟡 | **M** | Big UX win for employee onboarding. |
| Virtual backgrounds (Zoom) | 🔴 | — | Off-strategy gimmick. |
| WhatsApp store (full commerce) | 🔴 | — | Would duplicate CupsByAA / WooCommerce; Dardasha is BHD's WA channel. |
| Google Wallet pass | 🟡 | **M** | Digital card → add to Apple/Google Wallet = strong differentiator. Medium effort (Apple PassKit + Google Wallet API). |
| Apple Wallet pass | 🟡 **HIGH VALUE** | **M** | Same as above. |
| Instagram / LinkedIn embed blocks | 🟡 | **S** | `<iframe>` wrapper. |
| Shepherd.js product tour | 🟡 | **S** | First-time company-admin onboarding walkthrough. |
| Stancl multi-tenant (tenant-per-DB) | 🔴 | — | Cardify's `company_id` scoping is simpler and sufficient. |
| Nwidart modules | 🔴 | — | Laravel-specific; doesn't apply to raw-PHP Cardify. |

---

## 5. Top 5 High-Leverage Ports (ranked)

### 1. **Analytics dashboard per card** (M, 2–3 days) — biggest upgrade
Log every public card view + CTA click (phone tap, email, WhatsApp, custom link, QR scan). Company admin page with Chart.js time series, top sources, device breakdown, top employees. Table: `card_events (id, employee_id, event_type, ref, ip, ua, country, city, created_at)`. Use Cardify's existing `QRTracker.php` as the starting point — extend to all events.

### 2. **Apple + Google Wallet passes** (M, 3–4 days) — differentiator
Employee taps "Add to Wallet" on digital card → `.pkpass` (Apple) or JWT URL (Google). Pass contains name, title, phone, email, company logo, QR. Updates push silently when employee data changes. Huge sales story vs competitors.

### 3. **Dynamic QR (editable destination)** (M, 2 days) — retention lever
Printed QR points to `cardify.om/q/{token}` → DB lookup → redirect to current card URL. If employee leaves / card changes, super-admin re-maps token — no reprint. Table: `qr_tokens (token, target_url, active, updated_at)`. Pairs perfectly with #1 (every scan logged).

### 4. **Admin impersonation "Login as"** (S, 4 hours) — support efficiency
Super-admin → companies list → "Login as admin" button. Creates impersonation session with banner "Impersonating X — exit". One-click exit restores original session. Massive win for debugging customer issues without asking for passwords.

### 5. **Public card landing page with sections** (M, 3–4 days) — product expansion
Extend the digital card from "single image" to a full mobile landing page with toggleable sections: bio, services, gallery, testimonials, contact form (lead capture). Adopt ~3 of Infy's template layouts as *inspiration only* (clean-room rewrite in Tailwind). Unlocks "Cardify Pro" upsell vs the basic printed-card tier.

**Honorable mentions:** AI bio generator (S), 2FA for super-admin (S), coupon codes (S) — all half-day wins.

---

## 6. Top 3 "Do NOT Port"

### 1. **12 payment gateways**
Cardify is Oman-first. Paymob + Amwal Pay already cover the market. Adding Stripe/PayPal/Razorpay/etc dilutes focus, invites compliance overhead, and none of the target customers (BHD employees, Omani SMBs) ask for them. Skip.

### 2. **WhatsApp Store module**
Infy ships a second full e-commerce product inside the app (products, orders, shipping, refunds, privacy, trending videos). Cardify is a business card platform, not a store builder — this would double the surface area for zero strategic gain. BHD already has CupsByAA + WooCommerce for stores.

### 3. **Affiliate / referral payouts**
B2B print-shop customers don't operate referral programs. Over-engineered for Cardify's positioning. Revisit only if a self-serve consumer tier launches.

**Also skip:** virtual backgrounds, `stancl/tenancy` (Cardify's `company_id` scoping is simpler + sufficient), Nwidart modules (Laravel-specific), NFC card ordering (Cardify already prints).

---

## 7. Reusable UI / Design Patterns to Study (not copy)

- **Template gallery grid** (`resources/views/sadmin/vcards/`, `dashboard/templates/`): live-preview card in a phone frame, hover to enlarge, "Use template" CTA. Good pattern for Cardify's template picker.
- **Color picker in builder** (`@simonwep/pickr`): clean compact UI. Consider adopting the library (MIT-licensed) directly in Cardify's editor.
- **Shepherd.js onboarding tour**: multi-step guided walkthrough. Library is MIT; pattern is reusable.
- **Chart.js layouts for analytics**: time-series card + donut + map. Solid reference for Cardify analytics page #1.
- **Public vCard mobile layouts** (`vcardTemplates/vcard1–40.blade.php`): study spacing, section ordering, CTA hierarchy. Must reimplement clean-room — do not copy blade markup.
- **Admin sidebar with collapsible groups** + breadcrumbs: Cardify's Flowbite sidebar already follows a similar pattern.
- **Appointment calendar** using FullCalendar (GPL dual-license — check MIT commercial version if we port).

---

## 8. Security / Licensing Concerns Found

- ⚠️ **Archive provenance:** The source RAR contains an `NullPHPscript.com.html` affiliate page, indicating it was redistributed via a known nulled-script site. Ali has a **legit Regular License**, so legal use is covered, **but this specific archive copy may contain modifications vs Infy's official release**. Recommendation: **re-download the clean zip from CodeCanyon** before any further deep study. For this audit (patterns + features only), the risk is minimal because no code is being copied.
- ✅ **No obvious license-bypass code in the installer** (`vendor/erag/installererag`): the package does not perform Envato purchase-code verification at all — Infy relies on CodeCanyon distribution rather than runtime license checks. So there's no bypass present because there's no check to bypass.
- ✅ **No obfuscated PHP / eval / base64_decode** hotspots detected in quick scan of app code.
- ⚠️ **NOT verified:** full malware scan of all 1000s of vendor files. **DO NOT deploy this extracted copy to any server** — analysis was filesystem-only on local Mac. Per user instruction, no VPS install was performed.
- **Clean-room rule:** any ported feature must be written from scratch against Cardify's raw-PHP + PDO + Tailwind + Fabric.js conventions. No blade-to-PHP transliteration, no verbatim CSS copy, no copying unique wordings from blade templates.

---

## 9. Recommended Execution Order

**Sprint 1 (1 week)** — easy wins:
1. Admin impersonation (#4 high-leverage)
2. AI bio generator
3. 2FA for super-admin
4. Coupon codes

**Sprint 2 (2 weeks)** — the big one:
5. Analytics dashboard per card (#1)
6. Dynamic QR (#3) — pairs with analytics

**Sprint 3 (2 weeks)** — differentiators:
7. Apple + Google Wallet passes (#2)
8. Public card landing page with sections (#5)

**Sprint 4 (optional, later):**
- Custom domain per enterprise company
- Appointment booking (only if a specific customer requests it)
- Google/Apple social login

---

## 10. Artifacts

- **Extracted Infy source (local only, gitignored):** `/Users/ali/claude/tmp/infy-vcard/dist/vcards/`
- **Inner archive kept for reference:** `/Users/ali/claude/tmp/infy-vcard/Infy vCard SaaS v14.8.0/codecanyon-.../dist.zip`
- **This audit:** `/Users/ali/claude/projects/cardify.om/docs/superpowers/specs/2026-04-16-infy-vcard-feature-audit.md`
- **Cardify memory file:** `/Users/ali/.claude/projects/-Users-ali-claude/memory/cardify.md`

No Infy code or assets have been committed to the Cardify repo. This document is a design reference only.
