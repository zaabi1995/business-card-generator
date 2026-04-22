# Cardify Incident Runbook

Cat T action 470. Target audience: ops-on-call (usually Ali) or any
engineer who inherits the pager. Assumes root access to VPS
`147.93.20.54` and the Cardify GitHub repo.

Each section below is **SYMPTOM → QUICK CHECK → FIX → ESCALATION**.

---

## 1. Site returns 5xx

**Symptom:** `https://cardify.om/` returns 500/502/503/504. Uptime
monitor pages.

**Quick check**
```bash
ssh root@147.93.20.54 "/usr/local/bin/deploy-cardify.sh"  # no-op if pulled
curl -sIL https://cardify.om/ | head -5
curl -s https://cardify.om/api/health | head -1
systemctl status nginx php8.3-fpm
tail -n 80 /www/wwwlogs/cardify.om.error.log
```

**Fix**
- If last deploy was within 30 min → run `/usr/local/bin/rollback-cardify.sh`.
- If PHP-FPM down → `systemctl restart php8.3-fpm`.
- If nginx down → `nginx -t && systemctl restart nginx`.
- If DB down → see §3.
- If OOM → `systemctl restart php8.3-fpm` and check `/var/log/syslog | grep -i oom`.

**Escalation:** if down > 10 min, post incident in `status_incidents`
table + WhatsApp Ali (+96871616161). Incident shows live on
`/status` once inserted.

---

## 2. Cardify ERP sync failing

**Symptom:** Ali gets WhatsApp from `ERPSync::alertFailure` OR
`/api/erp-health` returns 503.

**Quick check**
```bash
curl -s https://cardify.om/api/erp-health | python3 -m json.tool
ssh root@147.93.20.54 "tail -n 40 /var/log/cardify-erp-retry.log"
```

**Fix paths**
- `auth_failed` → JWT has rotated or its session row was purged from
  Mongo. Re-inject the session on the ERP box (see
  `memory bhd-erp-ai-assistant-architecture.md`):
  ```
  docker exec bhd-erp-mongo-prod mongosh bhd_erp --quiet --eval \
    "db.adminpasswords.updateOne({user: ObjectId('ADMIN_ID')}, \
     {\$addToSet: {loggedSessions: 'TOKEN'}});"
  ```
- `unreachable` → erp.bhd.om DNS / BHD-ERP down. Alert the ERP team.
- `degraded` (HTTP 5xx) → check ERP side logs.

**Escalation:** failed retries retry on the ladder
`[2m, 5m, 15m, 1h, 3h, 12h, 24h]` (7 attempts). After exhaustion,
`erp_sync_retries.status = 'exhausted'` and the sync stops trying.
Monthly reconcile script `scripts/erp-reconcile.php` surfaces these.

---

## 3. Database unreachable

**Symptom:** `/api/health` returns `checks.db = false`. Pages 500.

**Quick check**
```bash
ssh root@147.93.20.54 "systemctl status mariadb && mysql -ubc -ppWewN3fwFmEHh32J -h127.0.0.1 -e 'SELECT 1'"
df -h /www /var         # disk full = MariaDB stops writing
```

**Fix**
- Restart: `systemctl restart mariadb`.
- If InnoDB crash recovery: see `/var/log/mysql/error.log`; usually
  reboot of MariaDB clears it.
- If disk full: clear `/var/backups/cardify-storage/*.tar.gz` older
  than 30 days (the rotation keeps 14, sometimes orphans survive).

**Escalation:** if MariaDB won't start after 5 min, restore from
latest gzip under `/var/backups/cardify/` using
`scripts/backup-restore-test.sh` logic against `bc` itself (DBA only,
because bc user can't `DROP DATABASE bc`).

---

## 4. Paymob payments failing

**Symptom:** Customer WhatsApps that "Pay Now" fails, or
`payment_retries.status = 'pending'` rows spike.

**Quick check**
```bash
curl -sL https://oman.paymob.com/api/health 2>&1 | head    # upstream
mysql -ubc -ppWewN3fwFmEHh32J bc -e \
  "SELECT COUNT(*), status FROM payments WHERE created_at > NOW() - INTERVAL 1 HOUR GROUP BY status"
tail /var/log/cardify-payment-retry.log
```

**Fix**
- If Paymob upstream down → nothing to do server-side. Post
  `status_incidents` row + WhatsApp customers with pending carts.
- If HMAC verification fails (see error log `HMAC invalid` entries),
  confirm `PAYMOB_HMAC_SECRET` in `config.php` matches Paymob
  dashboard (see skill `bhd-paymob`).
- If `c.phone` SQL errors appear, memory
  `cardify-payment-bugs-apr2026.md` has the fix pattern.

**Escalation:** pending dunning retries are already handled by the
hourly `payment-retry.php` cron. Check `payment_retries` table.

---

## 5. Disk usage over 80%

**Symptom:** WhatsApp from `scripts/disk-alert.sh`.

**Quick check**
```bash
ssh root@147.93.20.54 "df -h /"
du -sh /var/backups/* /www/wwwroot/*/storage 2>/dev/null | sort -hr | head -20
docker system df   # if any containers still on host
```

**Fix**
- Prune storage tarballs: `ls -t /var/backups/cardify-storage/*.tar.gz | tail -n +14 | xargs rm`.
- Prune DB dumps older than 30: `ls -t /var/backups/cardify/*.sql.gz | tail -n +30 | xargs rm`.
- Clear apt cache: `apt-get clean`.
- If Docker on host: `docker system prune -a -f`.

**Escalation:** if still >90% after a prune, expand disk via Hostinger
panel or move `/var/backups/*` to B2 (queued as action 819).

---

## 6. Hard rollback

Used when a deploy shipped a regression the post-flight smoke did not
catch.
```bash
ssh root@147.93.20.54 "/usr/local/bin/rollback-cardify.sh"        # → HEAD~1
ssh root@147.93.20.54 "/usr/local/bin/rollback-cardify.sh <sha>"  # → specific
```
Script reloads FPM + smokes 5 URLs; prints summary to
`/var/log/cardify-rollback.log`.

**Escalation:** if rollback's smoke test still fails, DNS or upstream
Paymob/ERP is down. Proceed to §4 or §2.

---

## 7. Restore from backup (last resort)

If DB corruption requires a full restore:

```bash
# Scratch restore first to verify the backup is readable
ssh root@147.93.20.54 "/www/wwwroot/cardify.om/scripts/backup-restore-test.sh"

# Full restore into live DB (DBA-only)
ssh root@147.93.20.54
LATEST=$(ls -t /var/backups/cardify/*.sql.gz | head -1)
gunzip -c "$LATEST" | mysql -uroot -p bc  # requires root MySQL creds
```

**Escalation:** call Ali. A full restore rewinds every employee + order
row to last midnight; customers may need manual reissue of cards
placed after that timestamp.

---

## 8. Contact tree

| Role          | Channel                                 | When              |
|---------------|-----------------------------------------|-------------------|
| Owner (Ali)   | WhatsApp +968 7161 6161                  | Any P0/P1         |
| Email (Ali)   | ali@bhd.om                               | P2 reports, logs  |
| BHD-ERP team  | ali@bhd.om (same)                        | ERP sync issues   |
| Hostinger SR  | Hostinger panel ticket                   | VPS-level outage  |
| Paymob SR     | oman.paymob.com dashboard support ticket | Gateway outage    |

---

## 9. Standard diagnostic commands

```bash
# Service health
systemctl status nginx php8.3-fpm mariadb

# Logs (tail live)
tail -f /www/wwwlogs/cardify.om.error.log
tail -f /var/log/cardify-erp-retry.log
tail -f /var/log/cardify-payment-retry.log
tail -f /www/server/php/83/var/log/php-fpm.log

# Active connections
ss -ntp | grep :3306
ss -ntp | grep :443

# Last cron activity
tail -f /var/log/cardify-backup.log
tail -f /var/log/cardify-disk-alert.log

# Deploy controls
/usr/local/bin/deploy-cardify.sh
/usr/local/bin/rollback-cardify.sh --status
/usr/local/bin/rollback-cardify.sh --list
/usr/local/bin/rollback-cardify.sh <sha>
```

---

## 10. Post-incident

Within 24h of any P0/P1:
1. `INSERT INTO status_incidents (title_en, body_en, severity, status,
   started_at, resolved_at) VALUES (...)` so `/status` history reflects
   reality.
2. Open a GitHub issue summarising root cause + fix; link the commit.
3. Add a memory note if a new pattern was learned
   (`~/.claude/projects/-Users-ali-claude/memory/`).
