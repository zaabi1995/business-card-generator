# Cardify.om — Project Context for Claude

Read this before touching anything. Rules are small, but they're
non-negotiable — we learned each one from a production break.

## One-line summary

Multi-tenant SaaS for Omani companies to design + share + print
bilingual business cards. Live at https://cardify.om. PHP 7.4+ direct
(no framework, no autoloader), MySQL, Tailwind + Alpine, Fabric.js,
Paymob Oman for payments, BHD-ERP for accounting.

## Where the source of truth lives

| File / path | What's in it |
|---|---|
| `DOCUMENTATION.md` | Complete engineer + ops guide. Trust the "What shipped in v2.0" section when older sections disagree. |
| `RELEASE_NOTES_v2.0.md` | April 2026 sprint narrative. Highlights + category table + migration list. |
| `CHANGELOG.md` | Engineering log (per-version). |
| `/changelog` (public) | Marketing timeline powered by `data/changelog.php`. |
| `ops/runbook.md` | Incident playbook, 10 sections, symptom → check → fix → escalation. |
| `SPRINT_PLAN.md` + `SPRINT_ACTIONS.md` + `SPRINT_LOG.md` | Sprint state. Used by the autonomous /loop iteration. |
| `docs/CONVEX_DEPLOY.md` + `docs/superpowers/plans/2026-04-29-cardify-convex-live-analytics.md` | Live Analytics architecture + 9-step deploy runbook. Self-hosted Convex sidecar at `/_convex/{api,http,admin}/`, hybrid event store mirrors `card_events` MySQL writes. Admin page: `/admin/live-analytics.php`. Feature-flagged via `FEATURE_LIVE_ANALYTICS`. |

## Cardify invariants (break these, break prod)

### Deploy
- **Always use `/usr/local/bin/deploy-cardify.sh`** on the VPS. Never
  raw `git pull` as root: new files land `root:root 0640` and PHP-FPM
  (user `www`) can't read them → site 403s. This has broken prod at
  least 5 times. See memory `feedback_cardify_deploy_perms.md`.
- The deploy script includes pre-flight `php -l` and post-flight
  5-URL smoke. A failing deploy auto-rolls back without reloading FPM
  so the previous good code stays hot in OPcache.
- If you must bypass the deploy script, ALWAYS follow with the full
  perms sweep (preserving +x on `.sh` so backup/disk-alert/slow-query
  crons keep firing):
  ```bash
  ssh root@147.93.20.54 "cd /www/wwwroot/cardify.om && \
    chown -R www:www . && \
    find . -type f ! -name '*.sh' -exec chmod 644 {} + && \
    find . -type f -name '*.sh' -exec chmod 755 {} + && \
    find . -type d -exec chmod 755 {} +"
  ```
  (15-day silent backup outage on 7 May 2026 traced to the deploy
  script stripping +x from `scripts/backup-*.sh` etc.; deploy script
  patched on the VPS to special-case `.sh`.)

### Git
- **Main lives in the `.worktrees/ux-employee-tabs/` worktree.** That
  is where you edit + push from. Root repo checkout is a different
  branch. See memory `feedback_cardify_main_branch_in_worktree.md`.
- Push to `origin/main`, then run the deploy script.

### i18n
- **Every new user-facing string lands in `lang/en/*.php` AND
  `lang/ar/*.php` in the same commit.** `scripts/i18n-audit.php`
  enforces parity; `tests/e2e/i18n-leak.spec.ts` runtime-scans the
  AR pages for literal `t()` calls.
- Namespaced keys: `t('onboarding.step_logo')`, never mix namespaces.
- RTL via `currentDir()` + Tailwind logical properties (`ps-*`, `pe-*`).

### Admin pages
- **Register every new admin page in `company_admin.php` `$pageMap`**
  or company-slug URLs (`/{slug}/admin/xyz`) will 404. The pageMap
  is a whitelist; see iter 65-68 for the latest adds.
- Use `getAdminBasePath()` + `$ext` pattern for links, not hardcoded
  `/admin/xyz.php` paths.

### Database
- Every new table uses `utf8mb4_unicode_ci` — mismatched collation
  breaks JOINs.
- Migration files go in `database/migrations/NNN_description.php`.
  Numbers increment strictly. Run them with the aaPanel PHP 8.3
  binary: `/www/server/php/83/bin/php` (system `/usr/bin/php` fails
  silently on MySQL socket).
- DB credentials: `bc` / `pWewN3fwFmEHh32J` / host `127.0.0.1` (NOT
  `localhost` — socket mismatch). See memory
  `cardify-db-host-127001.md`.

### Payments
- **Never skip Paymob HMAC verification** on callbacks. See
  `Payment::verifyHmac()`; reject if missing.
- **Balance updates must be atomic.** Wrap credit_accounts changes
  in `beginTransaction() + SELECT ... FOR UPDATE`.
- OMR is **3 decimals**. Always `number_format($amount, 3)` +
  `DECIMAL(10,3)` in MySQL.
- Since iter 94, every successful card payment returns a reusable
  `card_token` stored in `saved_cards`. Use it for MOTO + renewals.

### File uploads
- Detect MIME from file contents via `new finfo(FILEINFO_MIME_TYPE)`.
  Never trust `$_FILES['type']` — client-controlled.

### URLs + security
- **Use `APP_HOST` constant**, not `$_SERVER['HTTP_HOST']` (user-
  controlled) for callback + redirect URLs.
- CSRF on every POST: `csrfField()` in forms, `validateCSRFToken()`
  in handlers.
- `SecurityHeaders::send()` early, before any output, whenever you
  add a new standalone PHP entry point.

### Style
- **No em-dashes ("—")** anywhere in output (files, commit messages,
  Arabic + English alike). Use "," or "-". Global rule from
  `~/.claude/CLAUDE.md`.
- Writing tone: direct, action-oriented. Short commit messages that
  say "why" not "what". Long proposals get rejected as AI-sounding.

## Vector PDF render path

Cardify produces two canonical artifacts per employee card:

| Artifact | Source | Used by |
|---|---|---|
| Front + back PNG | Browser-side Fabric.js export | digital_card.php, wallet strip, og:image, print-shop preview |
| Vector PDF (front + back) | Server-side PyMuPDF, `scripts/render-card-pdf.py` | card-pdf.php (download), api/print-ready.php (imposition) |

The vector path engages when `templates.has_vector_source = 1` (set at import time when source.pdf has embedded fonts and the SVG bg renders). Falls back to PNG-in-PDF when the flag is 0 or the Python render fails.

Cache: `tmp/pdf-vector/<sha1>.pdf` keyed by `(employee_id, front_version, back_version, employee.updated_at, theme.updated_at, profile)`. Sidecar `.meta` JSON enables granular invalidation in `CardRenderer::invalidateForCompany|Employee`.

Profiles:
- `web` (default): subset fonts, smaller file (~300 KB), used by `card-pdf.php`
- `print`: full font embed, used by `api/print-ready.php` imposition

Components:
- `scripts/render-card-pdf.py` (Python, PyMuPDF) - single card to PDF
- `scripts/imposition-vector.py` - N copies to A4/A3 sheet with crop marks
- `scripts/extract_template_fonts.py` - pulls Lato/Sora out of source.pdf
- `scripts/warm-vector-cache.php` - cron-driven pre-render, every 5 min
- `includes/CardPDFRenderer.php` - PHP wrapper, signature cache, fallback
- `database/migrations/095_template_vector_assets.php` - has_vector_source + fonts_dir columns

Headers on responses:
- `X-Cardify-Pdf-Mode: vector | vector-304 | raster-fallback | raster-fallback-304`

Cache-Control is `private, max-age=86400` (1 day). The cache is invalidated server-side on template/theme/employee change, so the long TTL is safe. `Last-Modified` is emitted on every response; a conditional `If-Modified-Since` request returns 304 with no body.

If the print shop reports a quality issue: check `audit-card-surfaces.php <slug>` - the VECTOR column shows which employees got vector vs raster fallback.

## Common gotchas from memory

| Memory file | Gotcha |
|---|---|
| `feedback_pdo_reused_placeholders.md` | PDO emulated-prepares are OFF. Reusing `:name` twice in a query 500s with HY093. Split into `:q_en` + `:q_ar` and bind the same value twice. |
| `feedback_state_changing_get_endpoints.md` | State-changing endpoints (counters, rows, emails) MUST be POST-only with rate limits; GET gets prefetched by scanners. |
| `feedback_smoke_tests_need_new_paths.md` | Post-deploy smoke MUST hit URLs exercising NEW code. `/` alone proves nothing. |
| `feedback_cardify_alpine_legacy_css.md` | `cardify-overrides.css` `!important` display rules override `x-show`. Don't mix Alpine state with legacy CSS. |
| `cardify-payment-bugs-apr2026.md` | c.phone SQL + empty-string Paymob billing broke Pay Now. Fixed, but the shape to watch. |

## How to work in this repo

1. Read `SPRINT_ACTIONS.md` to find the next `- [ ]` action.
2. Make the change, commit, push, deploy. Always in that order, always
   with the script.
3. Update `SPRINT_LOG.md` with a one-liner.
4. If you discover new work, append numbered actions at the bottom of
   `SPRINT_ACTIONS.md` in the Appended section — don't let them
   evaporate.

## How to test

- `npm test` or `npx playwright test` — runs the E2E suite against
  https://cardify.om (override via `BASE_URL=...`).
- Individual spec: `npx playwright test tests/e2e/<name>.spec.ts`.
- Cross-browser: `--project="Safari iOS"` or `--project="Chrome Android"`.
- Slow-3G throttled: `tests/e2e/slow-3g.spec.ts` (chromium only).
- A11y: `tests/e2e/a11y-semantics.spec.ts` + `tests/e2e/a11y-keyboard.spec.ts`.

Run the i18n audit before committing: `php scripts/i18n-audit.php`.

## When you're stuck

1. `ops/runbook.md` — most production issues have a symptom there.
2. Search memory under `~/.claude/projects/-Users-ali-claude/memory/`
   for Cardify-specific gotchas.
3. Last-resort rollback: `ssh root@147.93.20.54
   "/usr/local/bin/rollback-cardify.sh"` (defaults to HEAD~1, runs
   smoke, logs to `/var/log/cardify-rollback.log`).
