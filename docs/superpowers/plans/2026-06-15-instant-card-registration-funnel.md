# Instant Card → Self-Serve Registration Funnel, Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: use superpowers:subagent-driven-development or superpowers:executing-plans. Steps use `- [ ]` for tracking.

**Goal:** Turn the homepage interactive hero into the lowest-friction acquisition funnel: a visitor types name/title/company/email + picks a brand colour, instantly gets a live demo card at `demo.cardify.om/<email-as-slug>`, a wallet pass whose QR points there, and an auto welcome email with a verify link that confirms ownership and unlocks upgrading to their own branded space.

**Architecture:** All instant cards live under ONE sandbox tenant `demo` (demo.cardify.om), so nothing ever touches a real company subdomain (no squatting). Slug = the email with `@`→`.` (`ali@bhd.om` → `ali.bhd.om`). The card is created immediately as an UNVERIFIED demo employee under `demo`; per-employee brand colour is stored in `employees.customizations`. A magic-link email verifies inbox ownership; verifying flips the card to confirmed and surfaces an "upgrade to your own space" CTA. Reuses Cardify's existing `createCompany`/`addEmployee`, `EmployeeEditToken`, `cardify_signup_leads`, and `Mailer::sendTemplated`.

**Tech Stack:** Vanilla PHP 8.3, MySQL, Alpine.js (hero), existing wallet endpoints, Mailer (Dardasha/SMTP).

**Decisions locked (Ali, 15 Jun 2026):** sandbox-tenant model (`demo.cardify.om/<email-slug>`); card is instant but UNVERIFIED until the email link is clicked; free + business emails both supported under the same scheme; if the email domain matches an existing real tenant, also notify that company's admin.

---

## MANDATORY revisions from adversarial review (15 Jun 2026)

Three parallel reviewers (reuse-correctness, security, product) verified this plan against the live code. Apply ALL of these; they override the task bodies below where they conflict.

### Factual corrections (verified against live schema)
- **`employees.customizations` does NOT exist** (only `custom_fields`, `hide_cardify_branding`). Add a migration FIRST: `database/migrations/120_employee_demo_meta.php` → `ALTER TABLE employees ADD COLUMN demo_meta JSON DEFAULT NULL`. Store `{brand_color, verified, source}` there. Replace every `customizations.brand_color` / `customizations.verified` in this plan with `demo_meta`.
- **Email templates live in `lang/{en,ar}/emails.php`**, NOT `mail.php`. Keys are `<key>_subject` + `<key>_body` (e.g. existing `welcome_subject`). Add `instant_card_welcome_subject/_body` (+ `instant_card_colleague_subject/_body` for Task 7).
- **`cardify_signup_leads` real column names:** `ip_address`, `user_agent` (not `ip`/`ua`). `id` + `source` are the only NOT NULL.
- `addEmployee()` ignores a provided `id` (derives from email localpart) → use a **direct atomic INSERT** (next item), not `addEmployee`.
- Dot-in-slug routing CONFIRMED safe: nginx `rewrite ^/([a-z0-9][a-z0-9_.-]*)/?$` allows dots; `digital_card.php` resolves by id. `ali.bhd.om` works.

### Security (HIGH — must fix before ship)
1. **Pass impersonation.** The pkpass currently puts the user-typed company in `organizationName` + has no demo marker, so anyone can mint a convincing "CEO, Big Bank" pass. FIX in `wallet_demo.php`: force `organizationName = 'Cardify'` (never user input); add a `backFields` entry `"Demo card — unverified. Made on cardify.om"`; the typed company stays only as a visible secondary field, not as the pass issuer.
2. **Slug overwrite / collision.** `ali+tag@bhd.om` and `ali@bhd.om` must NOT collide-overwrite. In `emailToSlug`: strip `+tag` before `@`, collapse repeated dots, lowercase, cap 80. In `capture()`: **atomic upsert that refuses to overwrite a different email** —
   ```php
   // employees has UNIQUE(company_id, id). Atomic, race-safe, refuses cross-email overwrite.
   $sql = "INSERT INTO employees (company_id,id,email,name_en,position_en,company_en,demo_meta,created_at)
           VALUES (:cid,:slug,:email,:name,:pos,:comp,:meta,NOW())
           ON DUPLICATE KEY UPDATE
             name_en=IF(email=VALUES(email),VALUES(name_en),name_en),
             position_en=IF(email=VALUES(email),VALUES(position_en),position_en),
             company_en=IF(email=VALUES(email),VALUES(company_en),company_en),
             demo_meta=IF(email=VALUES(email),VALUES(demo_meta),demo_meta),
             updated_at=NOW()";
   // then re-SELECT; if stored email != this email -> slug taken by someone else -> return error, do NOT email.
   ```
   Also confirm `UNIQUE(company_id, id)` exists on `employees` (add in migration 120 if missing).
3. **QR open-redirect.** Task 6's `&card=` must be host-validated, not substring-matched:
   ```php
   $p = parse_url($card);
   $okHost = isset($p['host']) && preg_match('/^[a-z0-9-]+\.cardify\.om$/i', $p['host']);
   if (!$card || ($p['scheme']??'') !== 'https' || !$okHost) { $card = 'https://cardify.om'; }
   ```

### Security (MED)
4. **Email header injection + spam.** Reject `name`/`company`/`title` containing `\r` or `\n`; reuse the `wallet_demo.php` control-char strip before emailing. Rate-limit per-email AND globally, not just per-IP: `RateLimiter::check('instant_card_email', strtolower($email), 3, 3600)` + `RateLimiter::check('instant_card_global','global',120,60)`. **Idempotent:** if a `cardify_signup_leads` row for this email with `status='pending'` exists within 60 min, return ok WITHOUT re-emailing.
5. **CSRF for anonymous POST.** No session token for anon visitors → gate with `isSameOriginRequest()` (existing helper, functions.php ~L261); for logged-in users also `validateCSRFToken()`. `instant_card.php` is POST-only → `if ($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}`.
6. **Token safety.** On verify (Task 8): confirm employee is under `DEMO_COMPANY_ID`, `demo_meta.verified` is still false, and the token's bound email matches before mutating; `EmployeeEditToken` already revokes prior tokens on mint + is single-use — assert it.
7. **Admin-notify abuse (Task 7).** Rate-limit per company/day: `RateLimiter::check('instant_card_admin', $companyId, 5, 86400)`.
8. **PII / indexing.** Demo cards MUST be `noindex`: in `digital_card.php`, when company slug is `demo`, send `header('X-Robots-Tag: noindex, nofollow')` + emit `<meta name="robots" content="noindex">`. Add `Disallow` for demo in robots logic. Hero form shows a one-line privacy note ("public demo card, deleted in 14 days if unverified"). Disposable-email block + `checkdnsrr($domain,'MX')` gate. Retention (Task 10): purge **unverified** demos after **14 days** (not 30); verified ones are exempt.

### Product / UX
9. **Edit path (the "auto-update on logo" promise).** Unverified users have no admin login. FIX: `verify_card.php` on success redirects to the existing employee self-edit page with a fresh `EmployeeEditToken` (`/<slug>/edit` magic-link surface) so they can upload a logo / edit, which auto-updates the card. Before verify, the card is read-only.
10. **Wallet-button flow.** Two states: (a) BEFORE the visitor enters an email, the wallet buttons add the generic demo pass (current behaviour, QR → cardify.om); (b) AFTER a successful `instant_card.php` submit, the buttons switch to their personal card (QR → `demo.cardify.om/<slug>`). Make this explicit in the hero (a "Get my card" primary action does the POST; wallet buttons read the returned slug).
11. **Analytics.** Log funnel milestones via `CardAnalytics::log($slug, DEMO_COMPANY_ID, 'instant_card_created'|'instant_card_verified', ...)` so created→verified→upgrade is measurable.
12. **Slug length.** Keep Ali's `localpart.domain` format, but cap to 80 chars and collapse multi-dot (e.g. `first.last@company.co.uk` → `first.last.company.co.uk`, truncated if long).

---

## Reused existing infrastructure (do NOT rebuild)
- `extractEmailDomain()`, `isCommonEmailDomain()`, `findCompanyByDomain()` — `includes/functions.php`.
- `createCompany()` / `addEmployee()` — `includes/functions.php` + `DatabaseAdapter` (addEmployee derives employee id from email localpart; demo uses an explicit slug instead, see Task 2).
- `EmployeeEditToken::mint()/verify()/buildUrl()` — `includes/EmployeeEditToken.php` (the magic-link system; reuse for the verify link).
- `cardify_signup_leads` table (id, email, phone, source, utm_*, ref_employee_id, locale, ip, ua, status, claimed_at, created_at).
- `Mailer::sendTemplated($to, $key, $locale, $params)` — `includes/Mailer.php`.
- `wallet_demo.php` — already builds a dynamic Apple/Google pass from name/title/company/colour (Task 6 just swaps its QR target to the derived card URL).
- Per-tenant routing: bare `/<localpart>` on a subdomain already resolves an employee card (`router.php` / `digital_card.php`). The `demo` subdomain inherits this; slug `ali.bhd.om` is a normal employee id under the `demo` company.

## File structure
- Create `includes/InstantCard.php` — all funnel logic (slug derivation, upsert demo employee, lead row, token, email). One responsibility: the funnel service.
- Create `instant_card.php` (POST endpoint) — thin controller calling `InstantCard::capture()`.
- Create `verify_card.php` (GET) — token verify → flip to confirmed → redirect.
- Modify `digital_card.php` — read per-employee `customizations.brand_color`; show an "unverified demo" banner + upgrade CTA when the employee is an unverified demo.
- Modify `wallet_demo.php` — accept `&card=<url>` and use it as the QR target instead of the fixed `cardify.om`.
- Modify `index.php` — add an email field to the hero + a "Get my card" action that POSTs to `instant_card.php`; on success the wallet buttons + QR use the returned slug.
- Add lang keys in `lang/{en,ar}/landing.php` + a new mail template `lang/{en,ar}/mail.php` key.
- One-time: provision the `demo` tenant (script, Task 1).

---

## Task 1: Provision the `demo` sandbox tenant (one-time)

**Files:** run-once script on the VPS (not committed); record the company id in `InstantCard.php` as a constant.

- [ ] **Step 1:** Create the tenant via the app's own functions on the VPS:
```php
// /tmp/mk_demo_tenant.php  (run with /www/server/php/83/bin/php, then delete)
require '/www/wwwroot/cardify.om/config.php';
require INCLUDES_DIR.'/functions.php'; require INCLUDES_DIR.'/Database.php';
require INCLUDES_DIR.'/DatabaseAdapter.php'; require INCLUDES_DIR.'/Auth.php';
if (!Auth::emailExists('demo-owner@cardify.om')['exists']) {
  $r = createCompany('Cardify Demo', 'demo-owner@cardify.om', bin2hex(random_bytes(12)), null, 'demo');
  fwrite(STDERR, "demo company id: ".$r['company']['id']."\n");
}
```
- [ ] **Step 2:** Confirm `demo.cardify.om` resolves (wildcard vhost already serves `*.cardify.om`): `curl -sI https://demo.cardify.om/ | head -1` → 200.
- [ ] **Step 3:** Put the returned company id in `InstantCard::DEMO_COMPANY_ID`.
- [ ] **Step 4:** Commit only the constant (no secrets).

## Task 2: `InstantCard` service — slug derivation

**Files:** Create `includes/InstantCard.php`. Verify: `php -r` one-liner.

- [ ] **Step 1:** `emailToSlug()` — `ali@bhd.om` → `ali.bhd.om`:
```php
public static function emailToSlug(string $email): string {
    $email = strtolower(trim($email));
    $slug = str_replace('@', '.', $email);
    $slug = preg_replace('/[^a-z0-9._-]/', '', $slug);   // safe path chars only
    $slug = preg_replace('/\.+/', '.', trim($slug, '.'));
    return substr($slug, 0, 100);
}
```
- [ ] **Step 2:** Verify: `php -r "require 'includes/InstantCard.php'; echo InstantCard::emailToSlug('Ali@BHD.om');"` → `ali.bhd.om`. Test gmail + a `+tag` address + unicode → all sanitise.
- [ ] **Step 3:** Commit.

## Task 3: `InstantCard::capture()` — upsert demo card + lead + token

**Files:** `includes/InstantCard.php`. Verify: curl the endpoint in Task 4.

- [ ] **Step 1:** Implement `capture(array $in): array` returning `['ok'=>bool,'cardUrl'=>..,'slug'=>..,'error'=>..]`:
  - Validate email (`isValidEmail`), sanitise name/title/company/colour (reuse the `wallet_demo.php` sanitiser + hex regex), reject disposable domains (small blocklist), rate-limit `RateLimiter::check('instant_card', $ip, 5, 600)`.
  - `$slug = self::emailToSlug($email)`.
  - Upsert employee under `DEMO_COMPANY_ID` with `id=$slug`: if exists, UPDATE name_en/position_en/company_en + `customizations` JSON (`brand_color`, `verified=false`); else `addEmployee([... 'id'=>$slug ...])`. (addEmployee derives id from email localpart, so INSERT directly via `Database` here to force `id=$slug`.)
  - Insert/De-dupe `cardify_signup_leads` (email, source='hero_instant', locale, ip, ua, status='pending').
  - Mint a verify token: `EmployeeEditToken::mint($slug, 'hero_instant', $ip)`; build verify URL `https://cardify.om/verify_card.php?t=<token>`.
  - `cardUrl = getTenantUrl('demo', '/'.$slug)` → `https://demo.cardify.om/ali.bhd.om`.
  - Fire the welcome email (Task 5) + optional admin notify (Task 7).
  - Return `cardUrl`, `slug`.
- [ ] **Step 2:** Per-employee colour persists in `customizations` JSON (no schema change). Verify the row after a test call.
- [ ] **Step 3:** Commit.

## Task 4: `instant_card.php` POST endpoint

**Files:** Create `instant_card.php`. Verify: curl.

- [ ] **Step 1:** Thin controller: `SecurityHeaders::send()`, require config + `InstantCard`, `validateCSRFToken()` (hero posts a CSRF token), read JSON/form body, call `InstantCard::capture()`, return JSON. POST-only (state-changing; per `feedback_state_changing_get_endpoints`).
- [ ] **Step 2:** Verify: `curl -X POST https://cardify.om/instant_card.php -d 'email=test@example.com&name=Test&...&csrf=..'` → `{"ok":true,"cardUrl":"https://demo.cardify.om/test.example.com",...}` and `demo.cardify.om/test.example.com` returns 200 with the name/colour.
- [ ] **Step 3:** Commit.

## Task 5: Welcome + verify email (bilingual)

**Files:** add `mail.instant_card_welcome` to `lang/en/mail.php` + `lang/ar/mail.php`. Verify: send to a real inbox.

- [ ] **Step 1:** Template: "Your Cardify card is live: <cardUrl>. Verify your email to keep it and upgrade to your own branded space: <verifyUrl>." Bilingual, BHD signature, `api.whatsapp.com` not `wa.me`, no em dashes.
- [ ] **Step 2:** `Mailer::sendTemplated($email, 'instant_card_welcome', $locale, ['cardUrl'=>.., 'verifyUrl'=>.., 'name'=>..])`.
- [ ] **Step 3:** Verify: real send to a test inbox, link clicks through. Commit.

## Task 6: Per-employee colour + demo banner on the card page; wallet QR → card URL

**Files:** Modify `digital_card.php`, `wallet_demo.php`.

- [ ] **Step 1:** `digital_card.php`: when the employee's `customizations.brand_color` is set, use it as the page primary (scoped: only when present, so real tenants are untouched). When the employee is an unverified demo (under `demo` company + `customizations.verified` falsey), render a slim banner: "Demo card — verify your email to keep it" + an "Upgrade / claim" button.
- [ ] **Step 2:** `wallet_demo.php`: accept `&card=<urlencoded>`; if it is a `*.cardify.om` URL, use it as the QR `message`/`value` instead of the fixed `cardify.om` (validate host to prevent open-redirect/phishing in the QR).
- [ ] **Step 3:** Verify cold-cache: `demo.cardify.om/<slug>` shows the chosen colour + banner; the pkpass QR decodes to the card URL. Commit.

## Task 7: Existing-tenant admin notify (optional, light)

**Files:** `includes/InstantCard.php`.

- [ ] **Step 1:** If `findCompanyByDomain(extractEmailDomain($email))` returns a real tenant (not `demo`), additionally `Mailer::sendTemplated($company.admin_email, 'instant_card_colleague', ...)`: "Someone at your company made a Cardify card — invite them to your real space." Non-blocking, rate-limited per company/day.
- [ ] **Step 2:** Verify with `ali@bhd.om` (bhd exists) → bhd admin gets the notify; the demo card still lives under `demo`. Commit.

## Task 8: Verify endpoint + auto-update on verify

**Files:** Create `verify_card.php`. Modify `InstantCard.php` (a `markVerified()`).

- [ ] **Step 1:** `verify_card.php?t=<token>`: `EmployeeEditToken::verify($t)` → on success `InstantCard::markVerified($employeeId)` (set `customizations.verified=true`, `cardify_signup_leads.status='verified'`, `claimed_at=NOW()`), redirect to the card with a one-time "verified, now make it yours" upsell to real onboarding (`/company/register.php?prefill=<email>`). Invalid/expired token → friendly retry page.
- [ ] **Step 2:** Verify: click a minted link → card banner disappears, lead row flips to verified. Commit.

## Task 9: Hero wiring (email field + Get-my-card action)

**Files:** Modify `index.php` (hero Alpine), `lang/{en,ar}/landing.php`.

- [ ] **Step 1:** Add an email input to the interactive pass controls + a primary "Get my card" button. On submit, Alpine POSTs `{name,title,company,email,color,lang,csrf}` to `instant_card.php`; on `ok`, store `slug`/`cardUrl`, set the wallet `_q` to include `&card=<cardUrl>`, and show a success state: "Check <email> to verify + keep it. Your card: demo.cardify.om/<slug>." Errors inline.
- [ ] **Step 2:** i18n both languages, same commit (i18n-audit must pass).
- [ ] **Step 3:** Verify cold-cache mobile + desktop (375/768/1280): type email → Get my card → success + wallet QR now points to the real demo card. Commit.

## Task 10: Abuse cleanup cron

**Files:** Create `scripts/purge-unverified-demos.php`; register in crontab (VPS).

- [ ] **Step 1:** Daily: delete demo employees under `demo` with `customizations.verified` falsey AND `created_at < NOW() - INTERVAL 30 DAY` AND no card events. Log counts.
- [ ] **Step 2:** Verify dry-run prints candidates without deleting; then enable. Commit.

---

## Verification (end-to-end)
1. Hero (cold-cache, mobile+desktop): type name/title/company + `you@yourco.com`, pick violet, tap **Get my card** → success state, wallet QR → `demo.cardify.om/you.yourco.com`.
2. Open `demo.cardify.om/you.yourco.com` → violet card, "demo, verify to keep" banner.
3. Inbox: welcome email with live card link + verify link.
4. Click verify → banner gone, lead `verified`, upsell to real onboarding.
5. `ali@bhd.om` → demo card under `demo` (NOT touching real `bhd`), bhd admin notified.
6. Abuse: 6th submit in 10 min from one IP → 429; QR host validation rejects non-cardify URLs.

## Self-review notes
- No real subdomain is ever provisioned from an email → no squatting (everything under `demo`).
- Email click is the ownership proof; pre-verify cards are clearly labelled demo + auto-purged.
- Per-employee colour via `customizations` JSON = no migration, no impact on real tenants.
- Reuses tokens/leads/mailer/createCompany — net new code is small (~2 endpoints + 1 service + hero field).

## Open follow-ups (not in this plan)
- "Upgrade" path that migrates a verified demo card to the person's OWN real tenant/subdomain (separate plan).
- Wallet auto-update-on-edit (the APNs push feature already queued) so an edited demo card re-pushes to installed passes.
