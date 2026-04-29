# v2.0 Sprint — Appended Actions Triage (2026-04-23)

Cat U action 508. Snapshot of the 63 queued follow-ups (actions
780-842) after 117 iterations, grouped by theme so Ali can pick the
next wave without re-reading the whole file.

## 1. Needs Ali's approval to unblock a broadcast (3 — highest owner-cost)

- **840** — social posts (Twitter / LinkedIn / Instagram)
- **841** — customer email blast
- **842** — customer WhatsApp blast

All three drafts are staged under `ops/launch-*.md`. One "go" from
Ali unblocks all.

## 2. Needs one-time ops config (8 — small DBA / infra hops)

- **817** — Sentry DSN (PHP + JS) in config.php
- **818** — Uptime Robot / StatusCake paste `ops/uptime-monitors.json`
- **819** — Backblaze B2 creds + rclone + `CARDIFY_BACKUP_PASS`
- **820** — `GRANT ALL ON bc_restore_test.* TO 'bc'@'localhost'`
- **821** — Prune VPS disk to <75% (backup tarballs + apt cache)
- **822** — Cloudflare DNS A record `stage.cardify.om`
- **823** — Push `stage` branch to GitHub
- **824** — `apt install k6` + first baseline run

Cleanest sequence: 821 (disk headroom) → 820 (restore-test flips
GREEN) → 817 + 818 (Sentry + uptime) → 822 + 823 (stage stands up) →
824 (k6 baseline) → 819 (offsite backups).

## 3. Stage-dependent E2E happy paths (8 — all block on 822 + 825)

- 825 — TEST_OTP bypass for `@cardify.test` emails on stage
- 826 — 7-step onboarding wizard happy path
- 827 — template editor save + preview on stage
- 828 — employee-edit token mint + autosave + persist
- 829 — Paymob checkout SANDBOX
- 831 — PO-upload credit flow
- 832 — analytics load + filter + Export CSV
- 833 — marketplace browse → pick → checkout
- 834 — mobile-responsive against admin surfaces

All will land in one sitting once stage is warm.

## 4. SEO + CWV polish (10 — incremental, no blockers)

- 792 — Seo helper rollout on 40+ pages
- 793/794/795 — OG images for company / print-shop / blog
- 796 — Print-shop slug routing
- 797 — 301 redirect audit
- 798 — Landing CWV pass
- 805 — CWV on secondary hubs (/companies /logos /tools)
- 806 — Merge tokens + components + toast CSS
- 807 — AVIF/WebP hero image
- 808 — Conditional flag-icons.min.css

Low-risk, high-reward. Pick any 3 for the next 30-min slice.

## 5. Security sweep (7 — sized for a focused half-day)

- 781 — RateLimiter on login
- 782 — RateLimiter on public read endpoints
- 783 — CSRF sweep (grep every POST)
- 784 — SQL-injection audit (grep interpolated queries)
- 785 — XSS output audit
- 786 — Site-wide SecurityHeaders::send() rollout + CSP enforce
- 787 — `session_regenerate_id(true)` on login
- 788 — Password policy + bcrypt cost upgrade
- 789 — 2FA TOTP /admin/security
- 790 — Super-admin IP allowlist
- 791 — File-upload sandbox (.htaccess + EXIF strip)

Best done in one pass so the security narrative is coherent.

## 6. Admin UX + product gaps (8 — each one self-contained)

- 810 — Admin blog editor AR tabs
- 811 — BHD-ERP Arabic invoice PDF
- 812 — `Tax::persistOnOrder()` on insert paths
- 813 — Extend ERPSync payload with CR / taxId / billingAddress
- 814 — Link "Billing info" from sidebar
- 815 — Card editor credits badge + Top-up nudge
- 816 — Confirm no legacy print-time card_credits decrement
- 809 — Admin CRUD for landing testimonials

## 7. Hardware / manual QA cadence (4 — monthly recurring)

- 835 — NFC tag write QA
- 836 — Wallet pass QA (after cert rollout)
- 837 — Add Skip-to-content link
- 838 — Monthly VoiceOver + NVDA walk

## 8. Small pathing follow-ups (2)

- 839 — Route `/ar/` landing (currently 404)
- 780 — Suspicious-activity watcher (audit_logs burst detector)

## Dependencies graph (shorthand)

```
821 (disk) ──┐
             ├─► 820 (restore-test)
             ├─► 817 (Sentry)
             ├─► 818 (Uptime Robot)
             └─► 822 (DNS) ──► 825 (TEST_OTP) ──► 826, 827, 828,
                                                   829, 831, 832,
                                                   833, 834
                        └─► 823 (stage branch) ──► (same)
                        └─► 824 (k6 baseline)

811 ◄─► 813 (ERP Arabic invoice coordinates with payload extension)
836 ◄── (blocks on wallet cert ops)
```

## Nothing was dropped during the sprint

63 queued follow-ups are exactly the 63 new-discovery actions the
iteration loop appended via the "If the action exposes new work,
append numbered actions at the bottom" rule. Cross-checked by
counting `Action NNN` mentions in `SPRINT_LOG.md` against the
current `- [ ] NNN.` rows.
