# Cardify Live Analytics, Convex Deploy Runbook

Self-hosted Convex sidecar that powers `/admin/live-analytics.php`. PHP keeps
writing to MySQL `card_events` (system of record), and additionally fires each
event into Convex over a 200ms-capped HTTP call so the live admin surface gets
reactive updates.

This runbook is for the VPS. Local development needs only `convex/` dependency
install if you want to run `npx convex dev` against a Mac-side container.

## Prerequisites

- Cardify deployed at `/www/wwwroot/cardify.om` on the VPS.
- Docker installed (`docker -v` succeeds).
- Cloudflare DNS for `cardify.om` already in place.
- nginx already serving `cardify.om` (confirmed via the existing deploy).

## Step 1 — Configure secrets

```bash
cd /www/wwwroot/cardify.om
cp .env.convex.example .env.convex
$EDITOR .env.convex   # generate INSTANCE_SECRET, CARDIFY_INGEST_SECRET, CONVEX_AUTH_SECRET via openssl rand -hex 32
```

## Step 2 — Bring up the Convex sidecar

```bash
docker compose -f docker-compose.convex.yml up -d
docker compose -f docker-compose.convex.yml ps
docker compose -f docker-compose.convex.yml logs --tail=50 convex-backend
```

Healthcheck:

```bash
curl -fsS http://127.0.0.1:3210/version
```

## Step 3 — Generate the admin key (one-time)

```bash
docker compose -f docker-compose.convex.yml exec convex-backend ./generate_admin_key.sh
```

Save the printed key; we'll use it in Step 4.

## Step 4 — Push the schema and functions

From the repo root:

```bash
cd /www/wwwroot/cardify.om/convex
npm install
CONVEX_SELF_HOSTED_URL='http://127.0.0.1:3210' \
CONVEX_SELF_HOSTED_ADMIN_KEY='<paste from step 3>' \
  npx convex deploy
```

This generates `_generated/` and pushes `schema.ts`, `events.ts`, `http.ts`,
`auth.config.ts`. Re-run after any code change in `convex/`.

## Step 5 — Build the React island

```bash
cd /www/wwwroot/cardify.om/analytics-ui
npm install
npm run build
```

Output lands in `/www/wwwroot/cardify.om/assets/live-analytics/` with a Vite
manifest. PHP reads the manifest at request time to find the hashed entry.

## Step 6 — nginx vhost additions

Edit the existing `cardify.om` server block (typically
`/www/server/panel/vhost/nginx/cardify.om.conf`). Add **before** any catch-all
PHP location:

```nginx
# Convex backend (queries/mutations + WebSocket)
location /_convex/api/ {
    proxy_pass http://127.0.0.1:3210/;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_read_timeout 3600s;
    proxy_send_timeout 3600s;
    proxy_buffering off;
}

# Convex HTTP actions (the /ingest endpoint PHP calls)
location /_convex/http/ {
    proxy_pass http://127.0.0.1:3211/;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_read_timeout 30s;
}

# Convex dashboard (Cloudflare Access guarded)
location /_convex/admin/ {
    proxy_pass http://127.0.0.1:6791/;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
}
```

```bash
nginx -t && systemctl reload nginx
```

Smoke:

```bash
curl -fsS https://cardify.om/_convex/http/healthz
# {"ok":true,"ts":...}
```

## Step 7 — Set Cardify config.php

Append to `config.php` (NEVER commit):

```php
define('FEATURE_LIVE_ANALYTICS', true);
define('CONVEX_INGEST_URL',    'https://cardify.om/_convex/http/ingest');
define('CONVEX_INGEST_SECRET', '<value from .env.convex CARDIFY_INGEST_SECRET>');
define('CONVEX_BROWSER_URL',   'https://cardify.om/_convex/api');
define('CONVEX_AUTH_SECRET',   '<value from .env.convex CONVEX_AUTH_SECRET>');
```

The Convex backend reads `CARDIFY_INGEST_SECRET` and `CONVEX_AUTH_SECRET` from
its own env (passed via `.env.convex` → docker-compose). Both sides MUST match
for the ingestion + JWT bridge to work.

Restart PHP-FPM if your config is opcache-cached:

```bash
systemctl reload php-fpm-7.4   # or php-fpm-8.x
```

## Step 8 — End-to-end smoke

1. Visit any card: `https://cardify.om/<slug>/card/<emp>`
2. Watch ingestion log: `docker compose -f docker-compose.convex.yml logs -f convex-backend | grep ingest`
3. Open `https://cardify.om/admin/live-analytics.php` and confirm the KPI cards
   tick up live as you reload the card page.

## Step 9 — Backups

Add to your existing nightly backup cron:

```bash
tar -czf "/backups/convex-data-$(date +%F).tgz" -C /www/wwwroot/cardify.om convex-data
```

Keep last 14 days. SQLite is a single file; restore = `docker compose down`,
extract, `docker compose up -d`.

## Rollback

```bash
# Disable live analytics (keeps Convex container running but PHP stops mirroring + admin redirects to static)
sed -i "s/FEATURE_LIVE_ANALYTICS', true/FEATURE_LIVE_ANALYTICS', false/" config.php
systemctl reload php-fpm-7.4
```

To stop the container entirely:

```bash
docker compose -f docker-compose.convex.yml down
```

MySQL keeps writing to `card_events`. The two static analytics dashboards
(`/admin/analytics.php`, `/admin/card-analytics.php`) keep working unchanged.

## Cloudflare Access for /_convex/admin/ (recommended)

The dashboard exposes raw queries / mutations / data. Lock it down to Ali's
email only:

1. Cloudflare → Zero Trust → Access → Applications → Add an application.
2. Self-hosted, domain `cardify.om`, path `/_convex/admin/`.
3. Policy: include emails `ali@bhd.om`, `alibinhd@gmail.com`, `ali.zaabi@ithca.om`.

## Notes

- INSTANCE_NAME=`cardify`. If we ever stand up a second Convex sidecar on the
  same VPS (LinkedIn Engine, Mithaq tenant), use a different INSTANCE_NAME
  and a different port triplet (e.g. 3220/3221/6792). Track in
  `context/convex-ports.md`.
- Self-hosted Convex supports the cloud Free-tier feature set. Vector search,
  advanced indexing, etc. are paid-cloud-only. We don't use them.
- The `CONVEX_AUTH_SECRET` is HS256 not RS256. If we move to multiple admin
  consumers (e.g. mobile app), switch to RS256 with a JWKS endpoint.
