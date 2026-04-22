# Cardify v2.0 post-release monitor — 24h playbook

Cat U action 506. What to watch between hours 0 and 24 after a
release. Assumes the deploy script's pre-flight + post-flight have
already green-lit the push (actions 465 + 466) so this is about
catching issues the smoke test couldn't see: real user sessions,
slow drip failures, and integration regressions.

## T+0 (immediately after deploy)

- [ ] `/api/health` returns 200 + all checks green.
- [ ] `/api/erp-health` returns 200 + `status=ok`.
- [ ] `/status` page all 5 components green.
- [ ] `tail -f /www/wwwlogs/cardify.om.error.log` for 5 min — look
      for new `PHP Warning`/`Fatal error` lines that weren't there
      before.
- [ ] `tail -f /var/log/cardify-erp-retry.log` — no retry-queue
      spike.
- [ ] Sentry dashboard open (once DSN wired, action 817):
      filter to `environment = production` + last-30-min window.

## T+1h

- [ ] MySQL slow-log check: `/www/server/data/mysql-slow.log` new
      entries? Expect zero for a healthy release.
- [ ] `payment_retries WHERE status = 'pending' AND created_at >
      deploy_ts` count stays stable (only the usual rate).
- [ ] `erp_sync_retries WHERE status = 'pending'` same sanity check.
- [ ] Eyeball `/admin/dashboard` on a real company (BHD Oman is the
      safest sanity target).
- [ ] Open one live employee card on iPhone Safari; one on Chrome
      Android. Render + Save Contact + QR all work.

## T+6h

- [ ] `cardify-disk-alert.log` — no spikes.
- [ ] `cardify-payment-retry.log` — see if any failed dunning
      loops flipped to `exhausted`.
- [ ] Spot-check the Sentry "top issues" panel; triage anything
      new by severity.

## T+24h

- [ ] Run the weekly restore test script manually so we know the
      night's backup is valid on the new code:
      ```
      ssh root@147.93.20.54 /www/wwwroot/cardify.om/scripts/backup-restore-test.sh
      ```
- [ ] Run `scripts/erp-reconcile.php --no-email --month=YYYY-MM`
      for the current month to confirm no drift.
- [ ] Run the full Playwright suite against prod:
      ```
      BASE_URL=https://cardify.om npx playwright test
      ```
- [ ] Skim the last 24h of cardify-*.log files for anything novel.

## Escalation thresholds (call Ali immediately if)

- Any `/api/health` check goes red for > 5 min.
- Any `/api/erp-health` `auth_failed` (token rotation needed).
- > 3 new Sentry issues in < 10 min window (release regression).
- > 10 failed payment_retries in the first hour (Paymob broken).
- `mysql-slow.log` growing faster than 10 entries/hour.

## What "post-release is successful" looks like

After 24h:
  - Zero manual rollbacks.
  - /api/health, /api/erp-health, /status all green the whole time.
  - No user-reported bugs on WhatsApp or reply-to support emails.
  - Sentry issue count stable (new issues all resolved or dismissed
    with notes).

Log the final state in `ops/release-log.md` (create if missing):
  `v2.0 | 2026-04-23 | deploy SHA | 24h outcome | any action items`.
