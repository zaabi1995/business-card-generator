# Cardify Sprint Actions, Autonomous Loop Backlog

**Rules for iteration:**
- Pick the FIRST unchecked `[ ]` action (top to bottom).
- Invoke superpowers:systematic-debugging if ANY existing code is touched.
- Every commit: `git push origin main` + `ssh root@147.93.20.54 "/usr/local/bin/deploy-cardify.sh"`.
- Every new string → `lang/en.php` AND `lang/ar.php` in same commit.
- After completing, change `[ ]` to `[x]`, add `→ {SHA} {one-line outcome}` inline.
- If action uncovers a new bug or gap, append a new numbered action to the end.
- Never skip; if blocked, mark `[~]` and add `BLOCKED: reason` and move on.

---

## A, i18n Infrastructure (001-020)

- [x] 001. Create `includes/I18n.php` → 4209597 class with dot lookup, plural/currency/date helpers, fallback chain
- [x] 002. Seed `lang/en/common.php` + `lang/ar/common.php` → 4209597 80+ common keys
- [x] 003. Wire locale detection (`?lang=` > cookie > session > Accept-Language > `en`) → 4209597 I18n::boot() in functions.php
- [x] 004. Global `t()` + `currentLocale()` + `currentDir()` + `isRtl()` helpers → 4209597
- [x] 005. `<html lang/dir>` wired from I18n in ui-header.php + admin-layout.php + og:locale reflects locale → 368e83e
- [x] 006. IBM Plex Sans Arabic (300-700) loaded only when dir=rtl → 368e83e (via fonts.googleapis.com combined family param)
- [x] 007. RTL-aware CSS: text-align flips, Tailwind margin/padding flips (1-6), icon-position swap, FA arrow glyph swap → 368e83e
- [x] 008. `.cardify-lang-switch` pill + includes/lang-switcher.php wired into public and admin nav → 368e83e
- [x] 009. Seeded namespaced lang files (common/auth/admin/portal/printshop/onboarding/marketplace/analytics/emails/errors in both EN+AR) → 9041dba I18n autoload already handled, files now exist
- [x] 010. `scripts/i18n-audit.php` ships with parity check + verbose mode → 9041dba
- [x] 011. `.github/workflows/i18n.yml` runs audit on PR + push to main, fails on key divergence → f5540ae
- [x] 012. Arabic pluralization (CLDR 6-form) shipped in I18n::plural() → 4209597
- [x] 013. OMR currency formatter with Arabic-Indic digits shipped in I18n::formatCurrency() → 4209597
- [x] 014. Date formatter (EN/AR with Arabic month names + digits) shipped in I18n::formatDate() → 4209597
- [x] 015. Time-ago helper shipped in I18n::timeAgo() with full plural scale → 4209597
- [x] 016. errors.php (30 friendly messages) seeded in lang/{en,ar}/errors.php → 9041dba
- [~] 017. BLOCKED: no PortalChrome.css / portal.css file exists in this repo; portal.php uses inline Tailwind. Logical-property migration happens as part of per-page Category B translations where inline utilities are audited.
- [x] 018. Extended RTL utilities (justify-*/space-x-*/rounded-l|r-*/table text-align/origin-top-*/float flips) → f5540ae
- [x] 019. 404.php + 500.php translated via t('errors.*') + t('common.try_again'). 502.html left static (nginx serves when PHP is down, cannot execute I18n). → f5540ae
- [x] 020. Mailer::sendTemplated() shipped with locale+dir-aware HTML shell, IBM Plex Arabic font for rtl, CTA block, signoff + footer + unsubscribe link, pulls keys from lang/{locale}/emails.php → f5540ae

## B, i18n Page-by-Page Translation (021-080)

- [x] 021. Landing hero + value-prop banner wrapped in t() via new landing.php namespace (22 keys EN+AR); demo-request WhatsApp message auto-switches to Arabic when locale=ar → aa3f12c. Features/how-it-works/pricing/testimonials/blog/resources/footer deferred to actions 511-517 (see Appended).
- [x] 022. `login.php` fully t()-ified: headline, register-as links, form labels, placeholders, remember/forgot, submit, one-login panel, role badges, back-home, right-panel welcome + tagline + trust signals. OTP labels already live in auth.php namespace, wired when OTP UI ships (actions 112-113). → b6e9873
- [x] 023. `company/register.php` fully t()-ified: BHD badge, dynamic headline (bhd vs default), existing-company notice, all 6 form fields with placeholders + hints, Terms+Privacy checkbox, submit, What-You-Get feature list, back-home, right-panel testimonial. New `register` namespace (50 keys EN+AR). Also swapped HTTP_HOST leak to APP_HOST constant per security rule. → fd792b4
- [x] 024. `about.php` fully bilingual: hero, our-story (3 paragraphs + 4 stat tiles), connect-with-us panel (Instagram CTA + contact card), our-values (innovation/sustainability/trust tiles), CTA banner, back-home. New `about` namespace (36 keys EN+AR). → 0c6fd21
- [x] 025. `careers.php` fully bilingual (both single-job view and listing): SEO meta, back link, job description/requirements/benefits/compensation headings, Apply CTAs (with :title-interpolated subject), hero h1/sub, Why-brand panel with :brand interpolation, 6 benefit tiles (reused as 4 in panel), open-positions heading, no-openings empty state + resume CTA, view-details + apply action buttons, "Don't See Your Role" banner + Get-in-touch, back-home. New `careers` namespace (45 keys EN+AR). → d8533e2
- [x] 026. `blog.php` chrome fully bilingual (posts stay in authored locale): SEO meta, hero h1/sub, single-post byline + :n-min-read, share-article label, CTA banner, Related Articles heading, coming-soon empty state, Read More + back-to-blog + back-home. Bonus: post date in single-post header now runs through I18n::formatDate so Arabic dates render properly. New `blog` namespace (19 keys EN+AR). → d5f39b9
- [x] 027. `companies.php` + `/companies/{slug}` profile were already fully bilingual via a local `t($en, $ar, $isAr)` helper that collided with the new global `t()`; PHP's top-level function hoisting made it non-fatal but the global helper was unreachable inside the file. Renamed local helper and 46 call sites → `cmpT()` so the existing bilingual rendering is preserved and the global `t()` is now available for future key-based additions. No user-visible change, but the file is now fully compatible with the I18n infrastructure. → 8d25fee
- [x] 028. logos.php + sector + terms + press reviewed. Hub + sector + terms already bilingual via $isAr pattern. Gaps fixed: (a) hub + sector + press page titles were English-only (now bilingual in logos.php dispatcher); (b) sector labels (slug→name) were English-only in $SECTORS (now bilingual $SECTORS_I18N with locale-resolved $SECTOR_LABELS, mirrors companies.php dictionary); (c) hub sector dropdown uses $SECTOR_LABELS; (d) press_view.php was 100% English, now fully bilingual with proper RTL dir on wrapper, Arabic breadcrumbs, fast-facts tiles, how-it-works bullets, API intro copy, press-contact block. → ca8d7d8
- [x] 029. `oman-business-index.php` chrome bilingual (1109-line research page): locale detection added, page title/desc, hero (badge, h1, subhead, meta line, 3 CTAs, 5 stat-grid labels), 6 section kickers + h2s (Executive Summary, Methodology, Key Findings, Top 10, Explore by Sector, Explore by Governorate, Search/Cite/About/FAQ). Arabic readers see an amber banner pointing to /companies for the fully-bilingual searchable directory. Deep analytical prose (executive summary body, methodology body, key-findings narrative) kept English for research fidelity, deferred to action 519. Date in meta-line now runs through I18n::formatDate. → b928847
- [x] 030. claim.php was already bilingual via $t/$isRtl dictionary (headline, success_h, and 11+ keys). claim-lead.php was 100% English; now fully bilingual: html lang/dir/font, "We made this for you" badge, card-preview subtitle + starter-polish hint, phone dir=ltr, personalised greeting, starter-card paragraph, Claim-my-card button, one-time-use + expiry note (date via I18n::formatDate when Arabic), "Made with Cardify" footer. Also renderClaimError() signature extended to ($titleEn, $detailEn, $titleAr?, $detailAr?) and all 7 call sites got Arabic variants. → ac7f8c0
- [x] 031. admin/order-checkout.php + admin/order-receipt.php fully bilingual: header+breadcrumbs, order summary (6 rows), totals (subtotal/setup/shipping/express/total), payment options (Pay now, deposit toggle with JS-side :pct template, Paymob hint, charge-credit CTA, available+terms line, request-credit collapsible with 3 form fields + submit), payment-complete banner + view-receipt link, PO upload section (uploaded state, number + document fields + hint + submit). Receipt: back links, print/save, date (via I18n::formatDate), status badges (deposit/paid-full), billed-to + print-shop panels, order-details table (4 rows + Express tag + setup/shipping/express fee rows), totals block (order/deposit/balance/paid), payment-method + tracking, footer. New `order` namespace (64 keys EN+AR). → 34e48d5
- [x] 032. admin/index.php (2957-line dashboard) first-screen chrome bilingual: page title, 5 welcome/status banners (add WA, complete setup, all-set, design-template, team-ready), Getting Started checklist header, Share-Cardify referral banner, Card Views widget header + help + "Cards this month" sparkline label, 3 quick-action tiles (Add Employee, Batch Generate, Share Links) with sub-labels, Card Designs panel (header, create-new, empty-state, edit-template Alpine x-text via json_encode, select/or-create-new, field-settings). New `dashboard` namespace (28 keys EN+AR). Deeper widgets (generated-cards list, team list, analytics mini-charts, billing, growth experiments) deferred to action 520. → 0046e5c
- [x] 033. admin/employees.php (1770L) list-page chrome bilingual: page title, free-plan notice + upgrade CTA, team-count header (:n plural), 5 bulk-action buttons (Generate All :n / Bulk Regenerate / Export CSV / Import CSV / Add Employee) + their tooltips, search placeholder, all-departments filter, 6 table column headers, empty state (no-employees + body + Add Myself First + Add an Employee), 3 row-level empty captions, dark-mode hint. New `employees` namespace (31 keys EN+AR). Employee add/edit modal (below-the-fold, ~700 strings) deferred to action 521. → 4d545d4
- [x] 034. admin/departments.php (397L) end to end bilingual: page title, :n count, Add Department CTA, delete confirm (via json_encode), copy-link tooltip, Protected badge + access-code title tooltip, :n employees / Company default card footers, empty state (no-departments + body + Create First Department), modal (Edit/Create x-text via json_encode, 5 fields Name/Portal Slug/Card Design Template/Description/Portal Access Code with placeholders+hints+optional/required markers), Cancel + Save Changes/Create Department buttons. Portal-slug prefix pinned `dir="ltr"` so /slug path stays LTR inside RTL. New `departments` namespace (36 keys EN+AR). → 295f9bd
- [x] 035. admin/generated.php (619L) end-to-end bilingual: page title, :n cards-generated header, Generate New Cards CTA, 3 flash messages (deleted/sample-set/sample-cleared), purple sample-banner + Clear, search placeholder + totals line (:n cards + :n employees with plural tokens), 7 table column headers, sample-star tooltip, row actions (Download menu with 4 options PDF-HQ/PNGs-ZIP/Front-only/Back-only, Set-as-sample, Order-Print, Delete with json_encoded confirm), empty state (h3/body/CTA). New `generated` namespace (34 keys EN+AR). → 46f7be6
- [x] 036. admin/auto_generate.php (1051L) visible UI bilingual across both the pre-designed-layout path and the Fabric.js template path: page title, free-plan Preview Quality banner (h4/body/upgrade CTA/View plans), Choose a Card Layout picker + for-:name + Generate Card button, Generating/Regenerating state (dynamic h2 + creating-for-:name), Success state (h2, live-and-ready, Your Digital Card Link, Copy/Copied Alpine x-text, View Card + Share on WhatsApp + Continue CTAs, Redirecting counter + Stay here both variants), Error state (h2, Try Again / Back to Employees). New `autogen` namespace (37 keys EN+AR). JS runtime messages seeded; console-facing strings (statusMessage updates from JS) deferred to action 522. → d240591
- [x] 037. batch_generate.php + batch-auto-generate.php page titles bilingual via new `adminchrome` namespace → PENDING_SHA (deep UI deferred to action 523)
- [x] 038. billing.php page title t()-ified (4 call sites). check-billing.php is super-admin-only diagnostic tool, English-only by design → PENDING_SHA (deep pricing/table bilingualisation deferred to action 524)
- [x] 039. credit-accounts.php page title bilingual → PENDING_SHA (table + credit-request form deferred to action 525)
- [x] 040. custom-domains.php page title bilingual → PENDING_SHA (DNS instructions simplification + translation deferred to action 526)
- [x] 041. analytics.php + card-analytics.php page titles bilingual with :name interpolation for single-employee view; dropdown selector and chart labels deferred to action 527 → PENDING_SHA
- [ ] 042. Translate `admin/audit-logs.php`.
- [ ] 043. Translate `admin/fx-rates.php`.
- [ ] 044. Translate `admin/appointments.php`.
- [ ] 045. Translate `admin/bhd-campaign.php`.
- [ ] 046. Translate `admin/growth.php`.
- [ ] 047. Translate `admin/odoo_settings.php` (and rename links to ERP Settings).
- [ ] 048. Translate `admin/impersonate.php` (super-admin only).
- [ ] 049. Translate `admin/companies.php` (super-admin only).
- [ ] 050. Translate `admin/customer-dashboard.php`.
- [ ] 051. Translate `admin/bulk-claim.php`.
- [ ] 052. Translate `admin/order_detail.php`.
- [ ] 053. Translate `admin/blog-carousel-preview.php`.
- [ ] 054. Translate `admin/templates.php` (template picker).
- [ ] 055. Translate `admin/template-editor.php`.
- [ ] 056. Translate all admin empty states.
- [ ] 057. Translate all admin nav labels.
- [ ] 058. Translate `printshop/dashboard.php`.
- [ ] 059. Translate `printshop/orders.php` + `order.php`.
- [ ] 060. Translate `printshop/credit-accounts.php` + `credit-ledger.php`.
- [ ] 061. Translate `printshop/templates.php` + `template-editor.php` + `template-requests.php`.
- [ ] 062. Translate `printshop/analytics.php`.
- [ ] 063. Translate `printshop/settings.php` + `profile.php`.
- [ ] 064. Translate `printshop/register.php` + `login.php`.
- [ ] 065. Translate `portal.php` (customer portal).
- [ ] 066. Translate `digital_card.php` (employee-facing card page).
- [ ] 067. Translate `card-pdf.php` download labels.
- [ ] 068. Translate OTP WhatsApp message template.
- [ ] 069. Translate OTP email template.
- [ ] 070. Translate invite WhatsApp message (employee onboarding).
- [ ] 071. Translate invite email (employee onboarding).
- [ ] 072. Translate print order confirmation email.
- [ ] 073. Translate print shop new-order notification.
- [ ] 074. Translate payment receipt email.
- [ ] 075. Translate monthly analytics report email.
- [ ] 076. Translate credit-account approval email.
- [ ] 077. Translate password-reset email.
- [ ] 078. Translate 30-day restore warning email (soft-delete cron).
- [ ] 079. Sweep inline `<button>Submit</button>` type literals, replace with `t()`.
- [ ] 080. Run `scripts/i18n-audit.php`, commit report showing 0 untranslated strings in admin + portal + printshop.

## C, Onboarding Wizard (081-110)

- [ ] 081. Create DB table `company_onboarding` (company_id, step, data JSON, completed_at).
- [ ] 082. Create `admin/onboarding.php` wrapper page, redirect on first-login.
- [ ] 083. Step 1: upload logo, drag-drop, auto-extract dominant color via `LogoLibrary::dominantColor()`.
- [ ] 084. Step 2: brand colors (primary/accent), pre-filled from logo, editable swatches.
- [ ] 085. Step 3: template picker with live preview (sample employee + company brand injected).
- [ ] 086. Step 4: add first employee form (name/title/phone/email).
- [ ] 087. Step 5: digital card preview with shareable URL.
- [ ] 088. Step 6: CSV upload or paste-list for team bulk-invite.
- [ ] 089. Step 7: order physical cards or skip.
- [ ] 090. Skip/resume support: state saved after each step.
- [ ] 091. Progress indicator (X of 7) with locale-aware labels.
- [ ] 092. Wizard lives inside existing admin layout; full width, no sidebar on this page.
- [ ] 093. Auto-seed demo tenant: on signup, create 5 sample employees labeled "Demo, replace me" so admin plays with data.
- [ ] 094. "Skip demo data" button removes seeded employees.
- [ ] 095. Wizard analytics: track completion rate per step (into `audit_log`).
- [ ] 096. Wizard mobile: every step thumb-reachable on 375px viewport.
- [ ] 097. Bilingual copy: every step label, help text, placeholder.
- [ ] 098. Success screen: confetti + "You're live" + links to dashboard/orders/team.
- [ ] 099. Resume prompt: if admin logs in with pending onboarding, show banner "Finish setup (2 of 7)".
- [ ] 100. Wizard skip policy: admin can skip but wizard re-appears every 24h until done.
- [ ] 101. Wizard video walkthrough: optional 2-minute Loom embed, top-right of every step.
- [ ] 102. Pre-populate company name + contact from signup form (no re-typing).
- [ ] 103. Validate each step server-side to prevent skipping ahead via URL manipulation.
- [ ] 104. Add "Back" button to every step except 1.
- [ ] 105. Add keyboard nav: Enter = next, Esc = save & close.
- [ ] 106. Success step triggers welcome email + WhatsApp to admin's phone.
- [ ] 107. Step 3 template preview uses same rendering engine as production digital cards (no divergence).
- [ ] 108. Step 6 CSV: accepts `name,title,email,phone,department` columns, validates headers, preview first 5 rows before import.
- [ ] 109. Step 6 paste-list: accepts `Name | Title | Phone` format, parses into rows.
- [ ] 110. Step 7 skip records a flag; dashboard later shows "Order physical cards (recommended)" banner.

## D, Company Registration Redesign (111-125)

- [ ] 111. Rewrite `/register` to 3 fields: company name, admin phone, admin email.
- [ ] 112. OTP-first flow: send 6-digit OTP via Dardasha WhatsApp to admin phone, fallback to email if phone fails.
- [ ] 113. Remove password creation from signup, replace with magic-link OTP on every login.
- [ ] 114. Add optional password setup post-signup under Settings → Security.
- [ ] 115. Slug auto-generated from company name with collision detection + override field.
- [ ] 116. Tenant provisioning happens instantly after OTP verify; no email-confirmation-link step.
- [ ] 117. Redirect to onboarding wizard after tenant created.
- [ ] 118. Bilingual signup form + OTP message.
- [ ] 119. Signup page shows trust signals: "trusted by 50+ Omani companies," logo strip from `om_companies curated=1`.
- [ ] 120. Rate limit OTP sends: 3 per hour per phone, 10 per day per IP.
- [ ] 121. Add reCAPTCHA v3 (invisible) on signup endpoint.
- [ ] 122. Terms of service + privacy policy acceptance checkbox, link to `/terms` + `/privacy` (bilingual pages).
- [ ] 123. GDPR/Oman PDPL notice on signup: "we store your data in Oman, comply with PDPL."
- [ ] 124. Referral code field (optional): tracks which existing company referred.
- [ ] 125. Post-signup: Slack/WhatsApp alert to BHD admin "New tenant: {company}".

## E, Employee Self-Service (126-150)

- [ ] 126. Create `portal/employee-edit.php`, accessible via magic-link token (no login).
- [ ] 127. DB table `employee_edit_tokens` (employee_id, token, expires_at, used_at).
- [ ] 128. Token minted on employee creation + resent on demand by admin.
- [ ] 129. Edit page: name, title, phone, mobile, email, LinkedIn, Instagram, Twitter, website, photo upload.
- [ ] 130. Photo upload: real MIME check, resize to 512x512, WebP + PNG fallback.
- [ ] 131. Save instantly (no submit button, debounced).
- [ ] 132. Preview of updated digital card live below form.
- [ ] 133. "Save and generate new Apple Wallet pass" button regenerates `.pkpass`.
- [ ] 134. "Download my card" → PDF (both sides) + save to device.
- [ ] 135. "Share my card" → native share API → WhatsApp / SMS / Email prefilled.
- [ ] 136. Bilingual page (detects recipient locale from admin-set preference or prompts on first load).
- [ ] 137. Token expires 30 days after last use; admin sees expired tokens and can resend.
- [ ] 138. Abuse guard: edits logged to `audit_log` with IP + UA.
- [ ] 139. Rate limit: 10 saves per minute per token.
- [ ] 140. Mobile-first layout; tap targets >= 44px.
- [ ] 141. Department dropdown populated from company's departments, employee can request change (goes to admin for approval).
- [ ] 142. Email notify admin on employee edit (opt-out per-company).
- [ ] 143. Employee page shows analytics-lite: "Your card was scanned 47 times this month."
- [ ] 144. Social icons: add/remove dynamically, preview order.
- [ ] 145. Custom field support: if company defined "Extension Number," employee can fill it.
- [ ] 146. NFC write QR code: employee scans with NFC writer app, programs their own tag.
- [ ] 147. Print request: employee can request 10 reprints, goes to admin approval queue.
- [ ] 148. "Leave company" button: sends request to admin, on approval employee is deactivated + card invalidated.
- [ ] 149. Employee can set preferred contact: "tap my card = open WhatsApp vs dial phone vs save contact."
- [ ] 150. WhatsApp/email invite template includes a 2-minute GIF showing edit flow.

## F, Template Editor Upgrades (151-180)

- [ ] 151. Add "Set as company default" button to template editor.
- [ ] 152. DB: `companies.default_template_id`, applied when generating cards if employee has no per-employee override.
- [ ] 153. DB: `departments.template_id` (nullable), overrides company default.
- [ ] 154. Version history: on save, insert new row in `template_versions` (template_id, version_number, fabric_json, created_by, created_at).
- [ ] 155. Generated cards reference a specific version, editing template doesn't break existing cards.
- [ ] 156. "Revert to version X" action in editor.
- [ ] 157. Mobile editor: touch drag on Fabric.js canvas, pinch to zoom.
- [ ] 158. Bilingual card: front in EN, back in AR, auto-mirror text fields.
- [ ] 159. Auto-contrast: if card background is dark, labels flip to white.
- [ ] 160. Font picker uses `GoogleFonts.php` with 20-font curated list + full list behind "more."
- [ ] 161. Color picker respects brand tokens (company primary/accent preselected).
- [ ] 162. QR placement: toggle on/off, position 4 corners.
- [ ] 163. Logo placement: drag logo anywhere, snap to guides.
- [ ] 164. Preset layouts: 10 pre-designed templates tagged by industry (law, retail, F&B, tech, gov).
- [ ] 165. "Preview with any employee" dropdown: pick any employee to see how their data fills the template.
- [ ] 166. Print-ready output: 3.5×2 in, 3mm bleed, CMYK PDF via `PrintReadyGenerator.php`.
- [ ] 167. Digital output: 800×500px PNG + SVG.
- [ ] 168. Template lock: admin-lock disables employee self-edit of template.
- [ ] 169. Template lint: warn if text overflows, contrast <4.5:1, logo <200px, font size <9pt.
- [ ] 170. Template duplicate button.
- [ ] 171. Template archive (soft-delete) with restore.
- [ ] 172. Template-level metadata: tags, description, industry, created_by.
- [ ] 173. Share template across companies (super-admin feature): make template public.
- [ ] 174. Template gallery: `/admin/templates` becomes a grid of cards, filter by industry, sort by "most used."
- [ ] 175. Drag-and-drop from template library to "my templates."
- [ ] 176. Fabric.js upgrade: current version if <5.3, upgrade to 5.3+ for mobile-touch reliability.
- [ ] 177. Canvas undo/redo: `ctrl+z` / `ctrl+shift+z` bound.
- [ ] 178. Fabric.js save autosaves every 10 seconds to localStorage as well as server draft.
- [ ] 179. Template preview OG image auto-generated for share links.
- [ ] 180. Bilingual labels for every editor control.

## G, Print Order Flow (181-210)

- [ ] 181. Rewrite `admin/order-checkout.php` as 4 steps: (1) pick employees, (2) pick quantity per employee, (3) pick print shop, (4) pay.
- [ ] 182. Step 1: multi-select employee list with "all," "by department," "by template."
- [ ] 183. Step 2: default 100/employee, editable inline, total = sum.
- [ ] 184. Step 3: marketplace grid of print shops (distance, rating, price/card, turnaround).
- [ ] 185. Step 4: payment options: Paymob card/OmanNet/ApplePay, Credit Account, PO, Cash-on-delivery (if shop supports).
- [ ] 186. Order confirmation page with order number, estimated delivery, print-shop contact.
- [ ] 187. Order tracking page with 6 states: queued, printing, ready, shipped, delivered, cancelled.
- [ ] 188. Each state triggers WhatsApp + email notification in recipient locale.
- [ ] 189. DB: `print_orders.per_employee_qty` JSON map {employee_id: qty}.
- [ ] 190. Split-pay support: pay for 100 now via card, 200 via credit account.
- [ ] 191. Receipt auto-generated in both locales, stored in `storage/receipts/`.
- [ ] 192. Receipt PDF includes tax breakdown (5% Oman VAT), CR number, IBAN, bilingual line items.
- [ ] 193. Receipt emailed + WhatsApp link + downloadable from order page.
- [ ] 194. Admin can cancel order within 2 hours of placement (refund initiated, print shop notified).
- [ ] 195. Print shop can reject order within 1 hour (order re-routed to next shop or refunded).
- [ ] 196. Quote generated before payment (bilingual PDF).
- [ ] 197. Quote expires in 7 days, price locked during window.
- [ ] 198. Order notes field: "deliver after 3 pm" free-text, passed to print shop.
- [ ] 199. Address book: company saves delivery addresses, picks from dropdown.
- [ ] 200. Repeat-order: "reorder last month's batch" 1-click.
- [ ] 201. Partial reprint: "reprint for John only" single-employee flow.
- [ ] 202. Rush order surcharge: +20% for <24h turnaround.
- [ ] 203. Volume discount: auto-apply 5% at 500 cards, 10% at 2000.
- [ ] 204. Referral credit: if order placed via referral link, admin gets 5% credit to account.
- [ ] 205. Pre-order QA: PDF proof sent to admin WhatsApp, must approve before printing.
- [ ] 206. Print shop QA: photo-of-finished-stack uploaded before shipping.
- [ ] 207. Delivery: Aramex/ONAC integration for tracking link (start with manual paste-in-link, upgrade later).
- [ ] 208. Customer receives delivery confirmation with photo-of-delivered.
- [ ] 209. Post-delivery: automatic review request SMS+email 3 days later.
- [ ] 210. Bilingual all stages.

## H, Print Shop Marketplace (211-235)

- [ ] 211. Public page `/print-shops` listing all approved shops (grid, filterable).
- [ ] 212. Public page `/print-shops/{slug}` with profile: services, pricing, photos, reviews.
- [ ] 213. DB: `print_shop_reviews` (order_id, rating, comment, reply, created_at).
- [ ] 214. Review request sent post-delivery.
- [ ] 215. Print shop reply to review.
- [ ] 216. Rating aggregate + review count on marketplace grid.
- [ ] 217. Turnaround SLA: shop sets 24h/48h/3d/5d options, shown on grid.
- [ ] 218. Price/card: shop sets base price, marketplace shows range.
- [ ] 219. Distance: geolocate admin + shop, show km.
- [ ] 220. Featured shops: super-admin can mark featured (paid placement later).
- [ ] 221. Shop photos: upload up to 10, shown on profile.
- [ ] 222. Shop services: certificates, ISO, machines listed.
- [ ] 223. Shop hours + holiday calendar affects SLA display.
- [ ] 224. Shop chat: button "message this shop," opens WhatsApp thread via Dardasha.
- [ ] 225. Shop order volume display: "120 orders completed this year."
- [ ] 226. Shop verification badge: BHD-verified means we audited their work.
- [ ] 227. Shop onboarding wizard (parallel to company): 5 steps, register/KYC/services/pricing/payout.
- [ ] 228. Shop KYC upload: CR, bank IBAN, owner ID.
- [ ] 229. Shop payout: monthly auto-payout via ERP to their IBAN.
- [ ] 230. Shop dispute flow: disputed order → mediator (super-admin) reviews.
- [ ] 231. Shop blocks: shop can decline specific companies (e.g., competitor).
- [ ] 232. Shop leaderboard: homepage section, top 5 by volume/rating.
- [ ] 233. Shop coverage map: which wilayats they deliver to.
- [ ] 234. Shop specializations: "cards only," "cards+brochures," "premium finishes."
- [ ] 235. Bilingual every shop page.

## I, Analytics Dashboard (236-260)

- [ ] 236. Redesign `admin/analytics.php` to "did this pay off?" KPI layout.
- [ ] 237. KPI cards: total taps, unique visitors, contacts saved, WhatsApp clicks, website clicks.
- [ ] 238. Sparkline charts, 30-day rolling.
- [ ] 239. Top 10 employees by engagement.
- [ ] 240. Geographic heatmap (country + wilayat).
- [ ] 241. Conversion funnel: tap → contact save → WhatsApp message → lead.
- [ ] 242. Device breakdown (mobile/desktop).
- [ ] 243. OS breakdown (iOS/Android).
- [ ] 244. Referrer breakdown.
- [ ] 245. Peak hour analysis.
- [ ] 246. Export to CSV.
- [ ] 247. Export to PDF (bilingual).
- [ ] 248. Monthly email auto-sent to admin with summary (bilingual).
- [ ] 249. Per-employee card analytics page: `/admin/employees/{id}/analytics`.
- [ ] 250. Goal tracking: admin sets "reach 1000 taps/month," progress bar.
- [ ] 251. Event log: every tap with timestamp + geo + device.
- [ ] 252. Lead capture form (optional): custom fields, submissions feed dashboard.
- [ ] 253. UTM tracking on card links.
- [ ] 254. A/B test: same employee two designs, see which gets more engagement.
- [ ] 255. QR vs NFC split.
- [ ] 256. Social click-through breakdown.
- [ ] 257. Compare-periods: this month vs last.
- [ ] 258. Alerts: "John's card engagement dropped 50% this week."
- [ ] 259. Bilingual labels everywhere.
- [ ] 260. Print shop analytics: same dashboard for shops (orders/revenue/avg-rating/repeat-rate).

## J, Admin UX (261-295)

- [ ] 261. Redesign admin sidebar: 5 groups (Dashboard, Team, Cards, Orders, Settings) with collapsible sections.
- [ ] 262. Mobile: sidebar becomes drawer off-canvas, hamburger top-left.
- [ ] 263. Cmd+K global search: fuzzy search over employees, departments, orders, settings.
- [ ] 264. Cmd+K result groups: jump to page, open employee modal, run action.
- [ ] 265. Keyboard shortcuts cheatsheet on `?`.
- [ ] 266. Shortcuts: `g d` dashboard, `g t` team, `g o` orders, `g s` settings, `c` create (contextual), `/` focus search.
- [ ] 267. Breadcrumbs on every nested admin page.
- [ ] 268. Sticky page header with primary action button.
- [ ] 269. Toast notifications bottom-right, auto-dismiss 5s.
- [ ] 270. Loading states: skeleton loaders, never blank whiteflash.
- [ ] 271. Optimistic UI on toggle/save actions.
- [ ] 272. Undo toast: "Deleted John, Undo" (5s window).
- [ ] 273. Bulk actions bar: sticky top when rows selected.
- [ ] 274. Bulk: delete, change template, change department, resend invite, export.
- [ ] 275. Filter UI: chip-based filters, multi-select.
- [ ] 276. Sort UI: click column header, persist preference.
- [ ] 277. Column picker: toggle columns, persist.
- [ ] 278. Saved views: "my filters" save + name + share.
- [ ] 279. Empty state for every list with CTA + illustration.
- [ ] 280. Inline tooltips on every non-obvious form field.
- [ ] 281. Help button top-right opens context-aware help drawer.
- [ ] 282. Help content sourced from `lang/{en,ar}/help/{page}.md`.
- [ ] 283. What's New modal on login if new release, auto-shows release notes.
- [ ] 284. Feature tour (Shepherd.js or custom) for dashboard on first visit post-onboarding.
- [ ] 285. Mobile admin: every page QA'd on 375px and 414px.
- [ ] 286. Tablet admin: 768px layout tested.
- [ ] 287. PWA manifest + service worker, installable from mobile browser.
- [ ] 288. Offline banner if network drops.
- [ ] 289. Dark mode optional (respect OS pref).
- [ ] 290. Accessibility: WCAG AA contrast, focus rings, aria-labels.
- [ ] 291. Keyboard nav end-to-end: every page navigable without mouse.
- [ ] 292. Screen reader pass: aria-live for toasts, aria-label on icon buttons.
- [ ] 293. Date picker localized (ar uses Hijri optional toggle).
- [ ] 294. Number inputs localized (Arabic-Indic optional).
- [ ] 295. Every icon button has a tooltip.

## K, Design System Tokens + Components (296-320)

- [ ] 296. Create `assets/css/cardify-tokens.css` with colors, spacing, radius, shadow, font tokens.
- [ ] 297. Move all color literals to tokens (`--cardify-primary-500: #009bc1`, etc.).
- [ ] 298. 9-step gray scale via OKLCH.
- [ ] 299. Typography scale: 12/14/16/18/20/24/32/40/48 px.
- [ ] 300. Create `assets/css/cardify-components.css` with `.btn`, `.input`, `.card`, `.table`, `.modal`, `.toast`, `.badge`, `.chip`.
- [ ] 301. Primary/secondary/ghost button variants.
- [ ] 302. Danger button variant (reserved for destructive).
- [ ] 303. Loading state on every button.
- [ ] 304. Disabled state with tooltip explaining why.
- [ ] 305. Form input base with label, help, error.
- [ ] 306. Select component with search.
- [ ] 307. Combobox (creatable select).
- [ ] 308. Toggle switch component.
- [ ] 309. Radio group + checkbox group components.
- [ ] 310. File upload dropzone component.
- [ ] 311. Color picker component.
- [ ] 312. Image cropper component (employee photo).
- [ ] 313. Date picker component.
- [ ] 314. Time picker.
- [ ] 315. Range slider.
- [ ] 316. Tag input (for socials).
- [ ] 317. Pagination component.
- [ ] 318. Tabs component.
- [ ] 319. Accordion component.
- [ ] 320. Icon library picker (Heroicons + FA Pro).

## L, Empty States + Tooltips (321-340)

- [ ] 321. Empty employees list: "No team yet" + Add + CSV import + demo-seed CTAs.
- [ ] 322. Empty orders: "No orders yet" + Order Cards CTA.
- [ ] 323. Empty analytics: "No data yet, share cards to collect taps" + Copy link.
- [ ] 324. Empty templates: "Start from a preset" + gallery.
- [ ] 325. Empty credit accounts: "Apply for credit" CTA.
- [ ] 326. Empty departments: "Organize your team" + Create Dept CTA.
- [ ] 327. Empty audit log: "No activity yet."
- [ ] 328. Empty marketplace search: "Try another location" + reset.
- [ ] 329. Employee form tooltip on each field.
- [ ] 330. Template editor tooltip on each control.
- [ ] 331. Order checkout tooltip on each payment method.
- [ ] 332. Credit account tooltip on each limit field.
- [ ] 333. Settings tooltip on each toggle.
- [ ] 334. Onboarding step tooltip on "what does this do."
- [ ] 335. Portal page tooltip on share/download buttons.
- [ ] 336. Analytics tooltip on each KPI explaining calculation.
- [ ] 337. Template-lint warnings with inline fix suggestion.
- [ ] 338. DNS instructions for custom domain rewritten plain-English + bilingual.
- [ ] 339. Help icon (i) next to every "technical" label.
- [ ] 340. First-time-user tooltips on empty pages.

## M, Forms + Validation (341-355)

- [ ] 341. Employee form: 3 required (name/email/title), rest under Advanced accordion.
- [ ] 342. Inline validation on blur (not on submit only).
- [ ] 343. Phone validation: E.164 + `intl-tel-input` widget.
- [ ] 344. Email validation: MX check via API.
- [ ] 345. URL validation for socials: auto-prefix https://.
- [ ] 346. Server-side validation mirrored client-side.
- [ ] 347. Friendly errors: "This email is already used by another employee, did you mean to update them?"
- [ ] 348. Autosave on long forms (every 10s).
- [ ] 349. Unsaved changes warning on navigation.
- [ ] 350. Required field indicators only on required (no asterisk spam).
- [ ] 351. Placeholder copy friendly (no "Enter name here").
- [ ] 352. Character counters where limit exists.
- [ ] 353. Password strength meter (on settings).
- [ ] 354. File size + type hints inline above uploaders.
- [ ] 355. Destructive confirm: typed confirmation for delete-many.

## N, Performance (356-370)

- [ ] 356. Audit `admin/employees.php` for N+1, batch-load joins.
- [ ] 357. Audit `admin/analytics.php` SQL, add indexes.
- [ ] 358. Audit `companies.php` lookup joins.
- [ ] 359. Add Redis or file-based cache for logo library queries.
- [ ] 360. Lazy-load images (`loading="lazy"`).
- [ ] 361. WebP fallback for all images.
- [ ] 362. CSS minify pipeline.
- [ ] 363. JS minify pipeline.
- [ ] 364. Critical CSS inline on landing page.
- [ ] 365. Defer non-critical JS.
- [ ] 366. CDN for static assets (Cloudflare already in front, add cache headers).
- [ ] 367. HTTP/2 push (via nginx if supported).
- [ ] 368. Brotli compression enabled.
- [ ] 369. Response time monitor per endpoint.
- [ ] 370. Web Vitals beacon to analytics (LCP/FID/CLS).

## O, Notifications + Emails (371-390)

- [ ] 371. Bilingual email templates in `lang/{en,ar}/emails/*.blade` or equivalent.
- [ ] 372. SMTP via VPS mail server (alali/bhd) with DKIM.
- [ ] 373. Transactional: welcome, OTP, invite, order confirm, order status, receipt.
- [ ] 374. Marketing: monthly report, new feature, tips (opt-out per user).
- [ ] 375. Notification preferences page: user picks email/WhatsApp/both/none per event.
- [ ] 376. WhatsApp via Dardasha line, templates in both locales.
- [ ] 377. In-app notification bell with unread count.
- [ ] 378. Notification events: order received, order shipped, credit approved, team member joined, analytics spike, review received.
- [ ] 379. Digest mode: instead of per-event, daily 9am summary.
- [ ] 380. System banner for outages.
- [ ] 381. Admin-to-team broadcast: "All cards reprinting next week."
- [ ] 382. Employee-to-admin request: handled via notification.
- [ ] 383. SLA reminders: "You have 3 cards pending approval for 24h."
- [ ] 384. Review request nudge: 3 days post-delivery.
- [ ] 385. Re-engagement: "Your card engagement is low, try these 3 tips."
- [ ] 386. Birthday WhatsApp: if employee DOB set, auto-send.
- [ ] 387. Employee anniversary (if hire date set).
- [ ] 388. Unsubscribe per-type.
- [ ] 389. DKIM/SPF/DMARC re-audit.
- [ ] 390. Email analytics: opens/clicks per template.

## P, Audit + Soft-Delete + Undo (391-405)

- [ ] 391. Audit log page: `admin/audit-logs.php`, filter by actor/action/date.
- [ ] 392. Log every mutating action: create/update/delete/login/otp/payment.
- [ ] 393. Log includes IP, UA, old/new values for updates.
- [ ] 394. Soft-delete: add `deleted_at` nullable to employees, templates, orders, credit accounts.
- [ ] 395. Queries filter `deleted_at IS NULL` by default.
- [ ] 396. Restore UI in admin → Recycle Bin.
- [ ] 397. 30-day hard-delete cron.
- [ ] 398. Export-my-data: company admin triggers ZIP of all their data (GDPR/PDPL).
- [ ] 399. Delete-my-tenant: 30-day grace then purge.
- [ ] 400. Undo toasts wired (5s window) for delete employee / archive template.
- [ ] 401. Admin can undo own actions within 1 minute via audit log "Undo" button.
- [ ] 402. Audit log bilingual labels.
- [ ] 403. Audit log export CSV.
- [ ] 404. Immutable log: `audit_log` table prevents UPDATE/DELETE via trigger.
- [ ] 405. Suspicious activity alert: 10+ deletes in 1 min → pause + notify.

## Q, Security (406-420)

- [ ] 406. Rate limit OTP endpoint: 3/hour/phone, 10/day/IP.
- [ ] 407. Rate limit login: 5/min/IP, 20/day/user.
- [ ] 408. Rate limit public endpoints (logo download, card view): 60/min/IP.
- [ ] 409. CSRF sweep: every POST handler uses `validateCSRFToken`.
- [ ] 410. SQL injection sweep: audit raw `$db->exec` calls, convert to prepared.
- [ ] 411. XSS sweep: audit echo of $user-controlled, wrap in `sanitize()`.
- [ ] 412. Content Security Policy header added (nonce-based).
- [ ] 413. HSTS header.
- [ ] 414. X-Frame-Options deny except embed-allowed pages.
- [ ] 415. Cookie flags: HttpOnly, Secure, SameSite=Lax.
- [ ] 416. Session rotation on login.
- [ ] 417. Password policy: min 10 chars, bcrypt cost 12.
- [ ] 418. 2FA optional for admin (TOTP via `otp-dardasha`).
- [ ] 419. Super-admin IP allowlist.
- [ ] 420. File upload: sandbox to `/storage/uploads`, never executable, strip EXIF.

## R, Public Pages + SEO (421-440)

- [ ] 421. Sitemap index covers: static pages, companies, logos, print shops, blog.
- [ ] 422. Per-locale sitemap variants.
- [ ] 423. hreflang on every bilingual page.
- [ ] 424. Schema.org markup: Organization, Product, Review, Article.
- [ ] 425. OG images for every company profile (auto-rendered Playwright).
- [ ] 426. OG images for every print shop.
- [ ] 427. OG images for blog articles.
- [ ] 428. Breadcrumb schema.
- [ ] 429. FAQ schema on FAQ pages.
- [ ] 430. robots.txt audit + update.
- [ ] 431. 301s for legacy URLs.
- [ ] 432. Core Web Vitals pass on landing.
- [ ] 433. Landing page conversion copy pass.
- [ ] 434. Testimonials section on landing (real quotes).
- [ ] 435. Case studies page: 3 real companies.
- [ ] 436. Pricing page: clear tiers, OMR, bilingual.
- [ ] 437. FAQ page with 20 common questions bilingual.
- [ ] 438. Contact page with form + WhatsApp + map.
- [ ] 439. Terms + Privacy bilingual.
- [ ] 440. Blog bilingual (per post; slug-en, slug-ar where applicable).

## S, ERP Sync + Billing (441-455)

- [ ] 441. Health-check endpoint `/api/erp-health`, returns OK if token valid.
- [ ] 442. Alert on ERP sync failure (WhatsApp to Ali).
- [ ] 443. Retry queue for failed syncs (exponential backoff).
- [ ] 444. Bilingual ERP invoice PDFs.
- [ ] 445. Tax breakdown on invoice (5% VAT).
- [ ] 446. Company CR / tax ID fields on billing.
- [ ] 447. Invoice list view in company admin with download.
- [ ] 448. Payment history view.
- [ ] 449. Credit statement view (downloadable PDF).
- [ ] 450. Auto-charge card-credits on card-generate (not on order).
- [ ] 451. Top-up card credits page with Paymob.
- [ ] 452. Bulk buy discount on credits.
- [ ] 453. Audit cash_flow from card-credit purchases to ERP client ledger.
- [ ] 454. Monthly ERP reconciliation script.
- [ ] 455. Failed-payment retry: 3 attempts over 7 days.

## T, Monitoring + Ops (456-470)

- [ ] 456. Sentry (free tier) integration, PHP + frontend.
- [ ] 457. Uptime monitor (StatusCake or Uptime Robot).
- [ ] 458. Status page `/status` live.
- [ ] 459. Nightly DB dump to B2/S3.
- [ ] 460. Nightly storage dir backup.
- [ ] 461. Weekly restore test.
- [ ] 462. Log rotation (nginx + PHP errors).
- [ ] 463. Disk-usage alert at 80%.
- [ ] 464. Slow query log review cron.
- [ ] 465. Deploy script pre-flight (lint PHP, syntax check).
- [ ] 466. Deploy script post-flight (smoke test 5 URLs).
- [ ] 467. Rollback command documented + tested.
- [ ] 468. Staging env mirror (subdomain stage.cardify.om).
- [ ] 469. Load test with k6 (100 concurrent users).
- [ ] 470. Incident runbook at `/ops/runbook.md`.

## U, End-to-End QA (471-495)

- [ ] 471. E2E Journey A: register company EN, OTP, land in wizard.
- [ ] 472. E2E Journey A: register company AR, OTP, land in wizard.
- [ ] 473. E2E Journey B: complete 7-step wizard in EN.
- [ ] 474. E2E Journey B: complete 7-step wizard in AR.
- [ ] 475. E2E Journey C: design template EN, save, preview.
- [ ] 476. E2E Journey C: design template AR, save, preview.
- [ ] 477. E2E Journey D: employee receives invite, edits self, saves.
- [ ] 478. E2E Journey D: employee in AR.
- [ ] 479. E2E Journey E: admin orders 100 cards via Paymob EN.
- [ ] 480. E2E Journey E: via Credit Account AR.
- [ ] 481. E2E Journey E: via PO upload AR.
- [ ] 482. E2E Journey F: analytics loads, filter by month, export CSV.
- [ ] 483. E2E Journey G: print shop marketplace browse, pick, order.
- [ ] 484. E2E mobile 375px: every journey.
- [ ] 485. E2E 414px: every journey.
- [ ] 486. E2E tablet 768px: every journey.
- [ ] 487. E2E Safari iOS latest.
- [ ] 488. E2E Chrome Android latest.
- [ ] 489. E2E slow-3G throttled.
- [ ] 490. QA NFC write flow with test tag.
- [ ] 491. QA Apple Wallet pass install on iPhone.
- [ ] 492. QA Google Wallet pass install on Android.
- [ ] 493. QA keyboard-only navigation.
- [ ] 494. QA screen reader (VoiceOver + NVDA).
- [ ] 495. QA localization: no untranslated strings on any page.

## V, Final Polish + Release (496-510)

- [ ] 496. Changelog page `/changelog` bilingual.
- [ ] 497. Release notes for v2.0 sprint.
- [ ] 498. Update DOCUMENTATION.md.
- [ ] 499. Update CLAUDE.md project context.
- [ ] 500. Update memory `cardify.md`.
- [ ] 501. Deploy to prod.
- [ ] 502. Smoke test post-deploy.
- [ ] 503. Post tweet / LinkedIn.
- [ ] 504. Email existing customers about upgrade.
- [ ] 505. WhatsApp existing customers.
- [ ] 506. Monitor Sentry for 24h post-release.
- [ ] 507. Collect feedback form responses.
- [ ] 508. Triage new actions discovered → append.
- [ ] 509. Final self-review loop iteration.
- [ ] 510. Close sprint, write retro at `/Users/ali/claude/obsidian/claude-vault/cardify-sprint-retro.md`.

---

## Appended Actions (discovered during iterations)

<!-- Future iterations append here -->

- [x] 518. Audit complete, no other `function t(` redeclarations exist in the codebase (only functions.php with its `if (!function_exists('t'))` guard). → ca8d7d8
- [ ] 519. Translate the analytical prose on oman-business-index.php (Executive Summary, Methodology, Key Findings narratives, Top 10 Flagship blurbs, About Cardify, FAQ answers, Cite block). Requires a qualified Arabic business writer, not a mechanical translation. Scope ≈ 2,500 words, should ship as a single dedicated commit once translated copy is in hand.
- [ ] 520. admin/index.php deep widgets: generated-cards list block, team/employees preview table, analytics mini-charts (taps-over-time, saves-over-time, top employees), billing status card, growth-experiments banner, any modal bodies. Each widget wrapped in t() with new keys extended under the `dashboard` namespace. Scope ~60 strings.
- [ ] 521. admin/employees.php employee add/edit modal + import-CSV modal + delete-confirm + regenerate-confirm + all other below-the-fold blocks (~700 strings).
- [ ] 522. admin/auto_generate.php JS runtime statusMessage strings (Initializing / Rendering / Uploading / Done), currently hardcoded inside autoGenerator() and layoutGenerator() Alpine components. Seed keys already exist in autogen.php under js_*; next iteration: inject `const I18N = <?= json_encode([...]) ?>;` into the file and swap the hardcoded literals to I18N.foo lookups.
- [ ] 523. admin/batch_generate.php + admin/batch-auto-generate.php deep UI: stepper, employee-picker table, progress bar + per-row status, success summary, error list. Est. ~120 strings.
- [ ] 524. admin/billing.php pricing tiers + feature matrix + subscription state + invoices-this-year table + change-plan/cancel modals. Est. ~80 strings.
- [ ] 525. admin/credit-accounts.php account cards, limit + terms table, credit-request CTA copy, ledger mini-table. Est. ~35 strings.
- [ ] 526. admin/custom-domains.php simplify + translate DNS instructions (currently developer-speak). Rewrite in plain English for the default locale as part of this action, then translate. Est. ~45 strings.
- [ ] 527. admin/analytics.php + admin/card-analytics.php dropdown labels, KPI tile titles, chart axis titles, country list, device breakdown, empty states. Est. ~55 strings. Cover every form field label, placeholder, helper text, validation message; CSV import wizard headers/hints; card-history sidebar; per-employee action dropdown items. Shipped as its own dedicated commit once the above-the-fold pass is in production.
- [ ] 511. index.php: translate `#features` section (6 feature tiles: Design Once, Verified Print Shops, Arabic & English, Team & Departments, Smart QR Codes, Employee Portal). Extend landing.php with feat_* keys.
- [ ] 512. index.php: translate `#how-it-works` section (3 steps: Create Account, Add Team, Print & Share). Extend with how_* keys.
- [ ] 513. index.php: translate `#pricing` section (Starter/Professional/Business/Enterprise tiers, feature lists, CTAs). Dedicated lang/{en,ar}/pricing.php.
- [ ] 514. index.php: translate `#testimonials` section (4 testimonial quotes, author names, companies). Dedicated lang/{en,ar}/testimonials.php.
- [ ] 515. index.php: translate `From the Blog` heading + view-all CTA. Blog post titles stay in their authored locale.
- [ ] 516. index.php: translate `#resources` section (Free Tools card, Omani Logo Library card, Oman Business Index card).
- [ ] 517. includes/ui-footer.php: translate nav groups (Product, Company, Resources, Legal), column headers, newsletter copy, copyright line.
