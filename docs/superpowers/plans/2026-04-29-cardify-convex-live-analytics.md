# Cardify Live Analytics, Convex Hybrid Event Store

**Date**: 2026-04-29
**Author**: Ali (with Claude)
**Status**: planned, scaffolding in progress

## TL;DR

Add a real-time admin analytics surface to Cardify, powered by self-hosted Convex,
without touching the PHP+MySQL system of record. PHP fires every card event into
Convex over HTTP (fire-and-forget), a React island in `/admin/live-analytics.php`
subscribes reactively. Feature-flagged. Rollback is a flag flip.

This is an additive, hybrid event store, not a migration.
The reference for the migration approach is the Mithaq `convex-migration` skill,
adapted for a non-JS host per Option B in that skill.

## Why hybrid, not a full Convex migration

Cardify is PHP 7.4+ / MySQL. Convex has no PHP SDK. The mature `card_events` table
+ `CardAnalytics::log()` aggregation already powers `admin/analytics.php` and
`admin/card-analytics.php`. Migrating that store to Convex would be 6 to 12 months
of rewrites with no user-visible win.

The user-visible win is *real-time*: live view counter on a card admin page, "Sarah's
card was just viewed in Muscat" toasts, geographic stream of scans, all without
the user pressing refresh.

That win is reactive queries. Convex provides them. We achieve the win by
side-storing events into Convex from PHP and reading from Convex in a tiny
React island. MySQL stays system of record. No dual-write of business data.

## Non-goals

- Replacing `card_events` MySQL table. Stays.
- Replacing `admin/analytics.php` or `admin/card-analytics.php`. Stays.
- Migrating Cardify auth / billing / cards / orders to Convex. Stays in MySQL.
- Building a public-facing live counter on the `<tenant>.cardify.om/<employee>`
  page yet. Phase 2 if Phase 1 ships well.

## Architecture

```
                                    ┌──── MySQL `card_events` (system of record)
                                    │     QRTracker + CardAnalytics::log()
                                    │
Browser visits card                 │
        │                           │
        ▼                           │
Cardify PHP (digital_card.php) ─────┤
                                    │
                                    ▼
                           POST /_convex/api/ingest
                           (50ms timeout, non-blocking)
                                    │
                                    ▼
                          Convex backend (port 3210)
                                    │
                                    ├── events table (tenantId=companyId)
                                    │
                                    ▼
                          WebSocket subscription
                                    │
                                    ▼
                       React island in /admin/live-analytics.php
                       (LiveCounter, RecentActivity, GeoBreakdown, Timeline)
```

## Components

### 1. Convex backend (self-hosted Docker sidecar)

- `docker-compose.convex.yml` adds `convex-backend` (ports 3210, 3211) and
  `convex-dashboard` (port 6791). All bound to `127.0.0.1`.
- SQLite default volume at `/opt/cardify/convex-data` (single file, easy backups).
- INSTANCE_NAME = `cardify`. INSTANCE_SECRET, ADMIN_KEY generated on first VPS start.
- Resource budget: ~500MB RAM idle, ~1-2GB peak. VPS handles fine.

### 2. `convex/` scaffold

Per Mithaq pattern, but smaller:

```
convex/
├── package.json         # convex@latest dev dep
├── tsconfig.json
├── schema.ts            # companies + events + sessions tables
├── auth.config.ts       # HS256 customJwt provider for admin reactive UI
├── http.ts              # routes httpAction -> events.ingestEvent
├── events.ts            # ingestEvent (httpAction) + reactive queries
├── lib/
│   ├── tenant.ts        # requireTenantId(), assertSameTenant()
│   ├── identity.ts      # requireIdentity() for HS256 admin tokens
│   └── ingestAuth.ts    # verifyIngestSecret() for PHP server-to-server
└── _generated/          # gitignored, npx convex dev --once produces this
```

#### Schema

```typescript
events: defineTable({
  tenantId: v.id("companies"),         // = Cardify companyId
  employeeId: v.string(),              // FK to MySQL employees.id
  type: v.union(
    v.literal("view"), v.literal("qr_scan"),
    v.literal("click_phone"), v.literal("click_whatsapp"),
    v.literal("click_email"), v.literal("click_website"),
    v.literal("click_map"), v.literal("click_social"),
    v.literal("save_contact"), v.literal("wallet_add"),
    v.literal("offer_redeem"), v.literal("product_order_click"),
    v.literal("short_link_click"),
    v.literal("viral_footer_click"), v.literal("viral_footer_view"),
  ),
  ctaTarget: v.optional(v.string()),
  visitorId: v.string(),               // sha256(ip+ua+date) hash, matches PHP
  ip: v.optional(v.string()),
  countryCode: v.optional(v.string()),
  countryName: v.optional(v.string()),
  city: v.optional(v.string()),
  device: v.optional(v.string()),       // mobile/desktop/tablet
  browser: v.optional(v.string()),
  os: v.optional(v.string()),
  referrer: v.optional(v.string()),
  ts: v.number(),                       // Date.now()
})
.index("by_tenant_ts", ["tenantId", "ts"])
.index("by_tenant_employee_ts", ["tenantId", "employeeId", "ts"])
.index("by_tenant_type_ts", ["tenantId", "type", "ts"])
.index("by_tenant_country_ts", ["tenantId", "countryCode", "ts"])
```

`companies` table is a stub (just `slug`, `nameEn`) so we have a real
`v.id("companies")` for tenancy guards. We provision a row at install-time via
a `convex/lib/bootstrap.ts` migration helper.

`sessions` is reserved for Phase 2 presence; not part of this ship.

### 3. Two trust boundaries

**A. PHP → Convex (server-to-server, shared secret)**

`POST /_convex/api/ingest` with header `x-cardify-ingest-secret: <CARDIFY_INGEST_SECRET>`.
PHP reads from `config.php`. Convex `httpAction` validates header in
`lib/ingestAuth.ts::verifyIngestSecret(req)` then calls internal mutation.

**B. Admin browser → Convex (per-user, HS256 JWT bridge)**

Admin loads `/admin/live-analytics.php`. PHP mints a 10-minute JWT signed with
`CONVEX_SHARED_AUTH_SECRET`, claims `{ sub: adminId, tenantSlug: companySlug,
tenantId: companyId, role }`. React island passes JWT to `ConvexReactClient`.
Convex `auth.config.ts` validates JWT via `customJwt` provider with the same
secret. `lib/identity.ts::requireIdentity(ctx)` resolves identity, queries are
scoped to `tenantId`.

### 4. PHP integration

New file: `includes/ConvexEvents.php`. Single static method:

```php
ConvexEvents::send(string $employeeId, string $companyId, string $eventType,
                   array $extras = []): void
```

- Reads `CONVEX_INGEST_URL`, `CONVEX_INGEST_SECRET` from `config.php`.
  Returns immediately if not configured (feature flag).
- cURL with `CURLOPT_TIMEOUT_MS = 200`, `CURLOPT_CONNECTTIMEOUT_MS = 100`,
  `CURLOPT_NOSIGNAL = 1`. Errors logged to `error_log`, never thrown.
- On servers with `pcntl_fork`, optionally fork-and-detach so PHP returns even
  faster. Default cURL with hard 200ms cap is fine.

Hook: `CardAnalytics::log()` adds one line at the end of the success path
sending the same payload to Convex. The MySQL write is the source of truth;
Convex is best-effort.

### 5. React island, `analytics-ui/`

```
analytics-ui/
├── package.json         # react, convex, vite
├── vite.config.ts
├── index.html
├── src/
│   ├── main.tsx         # ConvexProvider + bootstrap
│   ├── App.tsx          # tabs: Live / Activity / Geo / Timeline
│   └── components/
│       ├── LiveCounter.tsx
│       ├── RecentActivity.tsx
│       ├── GeoBreakdown.tsx
│       └── EventTimeline.tsx
└── tsconfig.json
```

Build output → `analytics-ui/dist/`. Cardify already serves `assets/`; we
mount the build output under `assets/live-analytics/` in the deploy step.

The island reads `data-` attributes from the host element:
- `data-convex-url` (e.g. `https://cardify.om/_convex/api`)
- `data-token` (HS256 JWT minted by PHP)
- `data-employee-id` (filter, optional)
- `data-days` (default 7)

Components use `useQuery(api.events.recentActivity, {...})` etc. Updates
arrive over WebSocket without page reload.

### 6. Admin host page, `admin/live-analytics.php`

Standard Cardify admin layout. Mints the JWT, sets `<div id="live-analytics-root"
data-...>`, loads the Vite-built bundle. ~80 lines of PHP including layout
includes.

Sidebar nav: add "Live Analytics" under existing "Analytics" group. Behind
`FEATURE_LIVE_ANALYTICS` flag in `config.php`, default off until VPS-deployed.

### 7. nginx + DNS

Single domain: `cardify.om`. Nginx already serves it. We add three location
blocks:

```nginx
location /_convex/api/ { proxy_pass http://127.0.0.1:3210/api/; ... }
location /_convex/http/ { proxy_pass http://127.0.0.1:3211/http/; ... }
location /_convex/admin/ { proxy_pass http://127.0.0.1:6791/; ... }  # Cloudflare Access guard
```

WebSocket headers required on `/_convex/api/`:
```
proxy_http_version 1.1;
proxy_set_header Upgrade $http_upgrade;
proxy_set_header Connection "upgrade";
proxy_read_timeout 3600s;
```

DNS: zero changes. All traffic stays on `cardify.om`.

### 8. Feature flags

`config.php`:
```php
define('FEATURE_LIVE_ANALYTICS', false);   // master switch for admin nav + ingestion
define('CONVEX_INGEST_URL', '');           // e.g. https://cardify.om/_convex/api/ingest
define('CONVEX_INGEST_SECRET', '');        // shared secret with Convex
define('CONVEX_BROWSER_URL', '');          // e.g. https://cardify.om/_convex/api
define('CONVEX_AUTH_SECRET', '');          // HS256 secret for admin JWT bridge
```

When all empty, code is a no-op. Production VPS sets these in `config.php` after
container is healthy. Rollback = clear vars.

## Sequence (8 phases, ~1 day of work)

| Phase | What | Output |
|---|---|---|
| 0 | Docker sidecar + env template + gitignore | `docker-compose.convex.yml`, `.env.convex.example` |
| 1 | `convex/` scaffold + schema | `convex/{package.json,tsconfig,schema.ts}` |
| 2 | tenant/identity helpers + auth.config | `convex/lib/{tenant,identity,ingestAuth}.ts`, `convex/auth.config.ts` |
| 3 | events HTTP action + reactive queries | `convex/{events.ts,http.ts}` |
| 4 | PHP non-blocking client + hook | `includes/ConvexEvents.php`, `CardAnalytics::log()` patch |
| 5 | React island | `analytics-ui/` |
| 6 | Admin host page | `admin/live-analytics.php` |
| 7 | Deploy doc + config additions | `docs/CONVEX_DEPLOY.md`, `config.example.php` |

Local commit + push at the end. VPS deploy is a SEPARATE step requiring Ali's
green-light because it touches DNS-adjacent nginx, opens new ports inside the
VPS, and manages a new container's lifecycle.

## Risk

- **Convex container down** → PHP cURL times out at 200ms, view still records to
  MySQL. Live admin panel shows "Connecting…" instead of data. Zero user-facing
  impact on the public card.
- **Lost events between MySQL and Convex** → MySQL is canonical. Live panel
  approximates. We can backfill from MySQL into Convex with a one-shot script
  if it ever matters; not worth building until it does.
- **Convex container OOM** → bind to 1.5GB ceiling in compose, restart on crash.
- **Ingest secret leak** → rotate by editing `config.php` + Convex env var,
  restart container. Five-minute incident.
- **Admin JWT secret leak** → rotate. All open admin sessions invalidate.
  Acceptable.

## Rollout plan (after Ali approves VPS deploy)

1. `docker compose -f docker-compose.convex.yml up -d` on VPS, generate admin key.
2. `npx convex deploy` from `/opt/cardify/` to push functions + schema.
3. Add nginx location blocks, `nginx -t`, `systemctl reload nginx`.
4. Set the four `config.php` constants.
5. Build `analytics-ui/` on VPS, copy `dist/` to `assets/live-analytics/`.
6. Set `FEATURE_LIVE_ANALYTICS = true`.
7. Visit `/admin/live-analytics.php`, verify a card view ticks up live.
8. Daily backup cron added for `/opt/cardify/convex-data`.

## What lands in this commit

Only Phases 0 to 7 above (code only). VPS deploy is gated.
