# Plan — Custom Domain per Card (ported from Infy vCard)

**Date:** 2026-04-16
**Branch:** `infy-custom-domains`
**Migration:** 050
**Tier:** Pro feature (free plan denied at admin UI)

## Goal
Owners can point their own domain (e.g. `ceo.acme.com`, `alicezaabi.com`) at
their Cardify card. They add a CNAME → cardify.om → backend resolves the
incoming `Host:` header to a card. v1 = pure PHP + DB. SSL is manual via
certbot per domain.

## DB — migration 050
Table `employee_custom_domains`:
- `id` VARCHAR(36) PK
- `employee_id` VARCHAR(36) NOT NULL  (FK logical → employees)
- `company_id` VARCHAR(36) NOT NULL   (denormalized for fast scoping)
- `domain` VARCHAR(255) NOT NULL UNIQUE
- `verified` TINYINT(1) DEFAULT 0
- `ssl_status` ENUM('pending','active','failed') DEFAULT 'pending'
- `verification_token` VARCHAR(64)
- `last_checked_at` TIMESTAMP NULL
- `last_check_message` VARCHAR(255) NULL
- `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
- INDEX(domain), INDEX(employee_id), INDEX(company_id)

Idempotent — `SHOW TABLES LIKE` guard.

## Verification flow (CNAME)
1. Admin opens employee row → "Custom Domain" panel → enters `ceo.acme.com`.
2. POST `/admin/custom-domains.php` action=add → row inserted, verified=0.
3. UI shows DNS instructions: `CNAME ceo.acme.com → cardify.om` + Verify button.
4. POST action=verify → backend runs `dns_get_record($domain, DNS_CNAME)`
   and accepts if any target matches `cardify.om` (case-insensitive, trim trailing dot).
   Falls back to `dns_get_record(DNS_A)` and accepts if any A record matches the
   IP of `cardify.om` (gethostbyname). Updates `verified`, `last_checked_at`,
   `last_check_message`. SSL status stays 'pending' until Ali runs certbot.

## Public router
New file `custom_domain_router.php`:
- Reads `$_SERVER['HTTP_HOST']`, lowercase, strip port.
- If host equals primary domains (`cardify.om`, `www.cardify.om`,
  `localhost`, `cardify.test`), bail out (`return false`) and let normal
  routing run.
- Else: SELECT `* FROM employee_custom_domains WHERE domain=? AND verified=1`.
- If found → load company + employee → forward to `digital_card.php` by
  setting `$_GET['company_slug']` + `$_GET['employee_id']` + `require`.
- Else → 404.

`index.php` and/or `router.php` will require this BEFORE existing routing,
returning early if it serves a card. If it returns false, original flow runs
unchanged. **No nginx changes for v1** — Ali manually adds `server_name` per
domain when issuing certs.

## Admin UI
- New page `admin/custom-domains.php` (full page list of all domains for the
  company + add/verify/delete).
- Plus an inline panel link from the employee edit modal (small CTA: "Manage
  Custom Domain →" deep links to the admin page filtered by employee).
- Pro-tier gate via `Billing::getCompanyPlanInfo($companyId)` —
  free plan sees an upgrade prompt instead of the form.

## v1 scope
**INCLUDED**
- Migration 050
- `includes/CustomDomain.php` helper class (CRUD + verifyDns)
- `admin/custom-domains.php` (Pro-gated UI)
- `custom_domain_router.php` + hook into `index.php` (only if Host is not primary)
- Pro-tier gate
- CSRF + admin auth on all mutations

**DEFERRED to v2**
- Auto-SSL provisioning (Cloudflare for SaaS or acme-companion)
- Per-domain nginx vhost generation
- Subdomain validation rules / blocking competing tenants
- TXT-record verification fallback (only CNAME for v1)

## Manual nginx + certbot runbook (Ali)
For each verified domain, on the VPS:

1. SSH `root@147.93.20.54`.
2. Append a server block to `/www/server/panel/vhost/nginx/cardify.om.conf`
   (or create `/www/server/panel/vhost/nginx/cardify-custom-<n>.conf`):
   ```nginx
   server {
       listen 443 ssl http2;
       server_name ceo.acme.com;

       ssl_certificate     /etc/letsencrypt/live/ceo.acme.com/fullchain.pem;
       ssl_certificate_key /etc/letsencrypt/live/ceo.acme.com/privkey.pem;

       root /www/wwwroot/cardify.om;
       index index.php;

       location / {
           try_files $uri $uri/ /index.php?$query_string;
       }
       location ~ \.php$ {
           include enable-php-82.conf;
       }
   }
   ```
3. Issue cert: `certbot certonly --nginx -d ceo.acme.com --non-interactive --agree-tos -m ali@bhd.om`.
4. Reload: `nginx -t && nginx -s reload`.
5. In Cardify admin, mark `ssl_status='active'` (UI button updates the row).
6. Cert auto-renews via certbot's systemd timer.

## Risk
- PHP+DB is contained, can't break existing routing — `custom_domain_router.php`
  short-circuits ONLY when host is unknown. Safe to merge.
- Nginx vhost edits are NOT done by code; require manual approval per domain.

## Test plan
- `php -l` every changed file.
- Migration runs cleanly twice (idempotent).
- `Host: cardify.om` → unchanged behaviour (regression).
- `Host: ceo.acme.com` with verified row → serves the linked employee card.
- `Host: ceo.acme.com` with verified=0 → 404.
- Free-plan company → admin page shows upgrade gate.
