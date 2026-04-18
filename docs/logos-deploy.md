# Omani Logo Library — Deploy Runbook

Executes Task 29 of [plan](../../../docs/superpowers/plans/2026-04-18-cardify-omani-logo-library.md).

## 1. Merge branch to main

```bash
# locally
cd /Users/ali/claude/projects/cardify.om
git fetch origin
git checkout main
git merge --no-ff origin/omani-logo-library
git push origin main
```

## 2. Deploy to VPS

```bash
ssh root@147.93.20.54
/usr/local/bin/deploy-cardify.sh
```

Verify pull + chown completed; composer install runs automatically (installs `ksubileau/color-thief-php`).

## 3. Run migrations (PHP CLI on VPS must be /www/server/php/83/bin/php)

```bash
cd /www/wwwroot/cardify.om
PHP=/www/server/php/83/bin/php
$PHP database/migrations/068_om_companies_logo_fields.php
$PHP database/migrations/069_logo_claims_table.php
$PHP database/migrations/070_logo_takedowns_table.php
$PHP database/migrations/071_logo_downloads_table.php
$PHP database/migrations/072_website_domain_cache.php
$PHP database/migrations/073_logo_match_pending_flag.php
```

Each prints "added …" lines, or "exists — skipped" on re-run (idempotent).

## 4. Apply nginx rewrites

```bash
cp docs/logos-nginx-rewrites.conf /www/server/panel/vhost/rewrite/cardify.om-logos.conf
# OR append the contents to the existing /www/server/panel/vhost/rewrite/cardify.om.conf
# (aaPanel usually loads all .conf in vhost/rewrite; check before deciding)

nginx -t && systemctl reload nginx
```

## 5. Dry-run seed (10 entries)

```bash
$PHP scripts/seed-2oman-logos.php --dry-run --limit=10
```

Confirm rows show `score=X.XX -> auto_link|queue|new_row`. No fatal errors.

## 6. Cautious first seed (limit 500)

```bash
$PHP scripts/seed-2oman-logos.php --limit=500
```

Inspect the JSON report: `ls -t storage/logos/seed-reports/*.json | head -1 | xargs cat`.
Sanity-check: `auto_linked + queued + new_rows ≈ scraped`, errors list empty.

Then full run:

```bash
$PHP scripts/seed-2oman-logos.php
```

## 7. Render PNG/WebP variants

```bash
$PHP scripts/render-logo-variants.php --only-missing
```

If Imagick is missing for SVG rasterisation, install:
```bash
apt-get install -y php8.3-imagick imagemagick
systemctl reload php-fpm-83
```

## 8. Generate OG images

```bash
$PHP scripts/generate-logo-og.php
ls storage/og/logos/
# Expected: hub.png + 23 sector PNGs
```

## 9. Smoke production

```bash
curl -sI https://cardify.om/logos | head -1
curl -sI https://cardify.om/logos/oil-gas | head -1
curl -s  https://cardify.om/api/logos/stats | jq .
curl -s  'https://cardify.om/api/logos/list?per_page=3' | jq '.results | length'
curl -sI https://cardify.om/sitemap-logos.xml | head -1
```

All should return `200` or valid JSON. None should 500/502.

## 10. Admin sanity

Browser: log in as super_admin → `/admin/super/logos/` → confirm stats
match expectations (most of 2,414 → indexed).

## 11. Monitor

```bash
tail -f /www/wwwlogs/cardify.om.error.log
```

Watch for 24h. Any 500s tied to logo paths → investigate immediately.

## Rollback

Migrations are additive and safe to keep. To rollback the *feature* without
reverting schema:

```bash
cd /www/wwwroot/cardify.om
mv /www/server/panel/vhost/rewrite/cardify.om-logos.conf ~
nginx -t && systemctl reload nginx
```

URLs will 404 until the rewrite is restored. Files and DB remain intact.
