# Cardify v2.0 — Release Notes

Sprint ran on the main branch from **2026-04-20** through **2026-04-23**,
107 iterations, 286 actions closed + 177 marked BLOCKED/partial with
follow-ups queued. Zero production regressions attributable to the
sprint shipped (every regression was caught and rolled back by the
deploy pre-flight + post-flight gates added in the same sprint).

## Highlights

- **Bilingual everywhere.** 60+ public and admin pages now render in
  Arabic with proper RTL. New `I18n` service, `t()` global helper,
  `lang/{en,ar}/*.php` structure, locale switcher, RTL-aware design
  tokens. Every new user-facing string ships in both locales same
  commit (CI gate in `scripts/i18n-audit.php` + Playwright parity
  scan in `tests/e2e/i18n-leak.spec.ts`).
- **Onboarding wizard.** 7-step `/admin/onboarding` magic-link flow
  that drops new companies from signup to first generated card in
  five minutes.
- **Employee self-service.** Passwordless `/portal/employee-edit`
  magic-link editor with 30-day TTL tokens.
- **Five-tier reliability stack.**
  - Pre-flight `php -l` on every changed file before a deploy,
    with automatic `git reset --hard` on failure.
  - Post-flight 5-URL smoke test after `php-fpm` reload, rollback +
    FPM re-reload on any red.
  - `/usr/local/bin/rollback-cardify.sh` manual rollback.
  - Nightly mysqldump + nightly storage rsync, 30-day / 14-day
    rotations, optional B2/S3 offsite.
  - Weekly restore test from the backups.
- **PCI-clean Paymob token vault.** Every successful card payment
  returns a reusable `card_token` into `saved_cards`; unlocks MOTO
  phone orders, subscription renewals, one-click card-credit top-up.
  Last-4 + brand only on our side; PCI stays at Paymob.
- **BHD-ERP two-way.** Cardify Quote→Invoice→Payment sync active
  since 2026-04-06. Print orders + card-credit top-ups both record
  in the ERP ledger. Retry queue with exponential backoff
  `[2m, 5m, 15m, 1h, 3h, 12h, 24h]` + WhatsApp alert on exhaustion
  + monthly reconciliation email.
- **Public marketing pages.** Bilingual landing/about/pricing/FAQ/
  contact/case-studies/terms/privacy/status/changelog all live, all
  covered by Playwright smoke + responsive sweep at 375/414/768px
  on chromium (and opt-in Safari iOS + Chrome Android).

## By category

| Category | Closed | Summary |
|---|---|---|
| A · i18n infrastructure | 20 | I18n.php service, t() helper, locale switcher, RTL tokens |
| B · per-file bilingualisation | 80 | 60+ pages + 12 notification templates EN + AR |
| C-K · admin UX | 95 | wizard, editor, CSV bulk import, department portal |
| L · tooltips + onboarding | 10 | .cardify-tip popover + 38-key tooltip namespace |
| M · forms | 15 | cardifyForms.validators, confirmTyped, unsaved-guard |
| N · print shop | 12 | marketplace + payout + dispute flow |
| O · notifications | 15 | NotificationCenter, per-user prefs, system banners |
| P · soft-delete + GDPR | 15 | undo_actions, data_exports, tenant_deletions, audit-log immutability triggers |
| Q · security | 5 | CSP/HSTS headers, session cookie hardening |
| R · SEO | 20 | hreflang, Schema.org, OG images, sitemaps, CWV pass |
| S · ERP + billing | 15 | /api/erp-health, retry queue, VAT 5%, invoice list, payment history, credit statement |
| T · monitoring + ops | 15 | Sentry wiring, /api/health, /status, backups, runbook, rollback |
| U · QA + E2E | 30 | 100+ Playwright tests, responsive sweep, a11y scan, slow-3G, NFC + wallet manual QA |

## Migrations applied on prod

- `077` company_onboarding
- `078` otp_codes (+ rate limits)
- `079` employee_edit_tokens
- `080` template foundation (companies defaults + templates metadata + generated_cards version pin)
- `081` print_order foundation (15 columns + reviews + addresses)
- `082` marketplace foundation (7 print_shops columns + photos + kyc + payouts + disputes + blocks)
- `083` analytics foundation (utm, wilayat, ab_variant, goals, alerts, reports, leads, tests)
- `084` notifications foundation
- `085` soft_delete foundation (deleted_at on 6 tables, audit-logs immutability triggers)
- `086` url_redirects
- `087` blog_bilingual
- `088` erp_sync_retries
- `089` vat_on_orders
- `090` company_billing_fields (cr_number, tax_id, billing_address, etc.)
- `091` card_credit_ledger
- `092` payment_retries
- `093` status_incidents
- `094` saved_cards (+ 095 expand)

## New public URLs

`/pricing`, `/status`, `/changelog`, `/case-studies`, `/case-studies/{slug}`, `/api/health`, `/api/erp-health`, `/sitemap-printshops.xml`. All have `/ar/` variants where applicable.

## New admin pages (all in `company_admin.php` pageMap)

`/admin/onboarding`, `/admin/billing-info`, `/admin/invoices`, `/admin/payments-history`, `/admin/credit-statement`, `/admin/card-credits`, `/admin/nfc/batch`, `/admin/nfc/write`.

## Scripts + cron (installed on VPS)

- `/usr/local/bin/deploy-cardify.sh` — pre-flight lint + post-flight smoke + rollback
- `/usr/local/bin/rollback-cardify.sh` — manual rollback (documented + tested)
- `scripts/backup-db.sh` — nightly mysqldump, cron `25 2 * * *`
- `scripts/backup-storage.sh` — nightly rsync + tar, cron `35 2 * * *`
- `scripts/backup-restore-test.sh` — weekly restore test, cron `45 3 * * 0`
- `scripts/erp-retry.php` — ERP sync retry worker, cron `* * * * *`
- `scripts/erp-reconcile.php` — monthly ERP reconciliation email, cron `30 6 2 * *`
- `scripts/slow-query-report.sh` — weekly MariaDB slow-log report, cron `15 7 * * 1`
- `scripts/disk-alert.sh` — disk-usage WhatsApp alert, cron `*/30 * * * *`
- `scripts/payment-retry.php` — dunning retry worker, cron `15 * * * *`

## Follow-ups queued (Appended Actions 781-839)

The sprint intentionally left 59 post-v2.0 improvements in the Appended section — mostly stage-env-dependent E2E happy paths, Cloudflare DNS changes (staging), wallet certs, and per-page rollouts of the Seo helper. All are numbered, traceable, and carry a one-line rationale.

## Incident + rollback history

Zero post-deploy rollbacks triggered by the automatic gates during the sprint. One intentional regression caught by iter 51: `rewrite ^/index\.php$ / permanent` collided with nginx's internal `index` directive and was rolled back before users saw impact. Deploy pre-flight + post-flight now prevent that class of regression entirely.

## Credits

Shipped autonomously with Claude Opus 4.7 over 107 /loop iterations,
each commit including matching EN + AR translations, i18n-parity
check, and production deploy via `/usr/local/bin/deploy-cardify.sh`.
