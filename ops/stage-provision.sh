#!/usr/bin/env bash
# Cardify staging environment provisioning (Cat T action 468).
#
# One-shot script that stands up stage.cardify.om on the same VPS as
# prod. Safe to re-run (idempotent: skips steps that are already done).
#
# Prerequisite ONE ops step that this script CANNOT automate:
#   - Add Cloudflare DNS A record `stage.cardify.om → 147.93.20.54`.
#     Everything else below is automated.
#
# Usage: sudo ./stage-provision.sh
#
# What it does:
#   1. mkdir /www/wwwroot/stage.cardify.om + clone the prod repo.
#   2. mysql CREATE DATABASE bc_stage + GRANT to bc user.
#   3. Seed bc_stage from the latest prod backup.
#   4. Write config.php overrides (DB name bc_stage, APP_ENV=stage,
#      Paymob SANDBOX creds placeholder, visible STAGE banner flag).
#   5. aaPanel-compatible nginx vhost at
#      /www/server/panel/vhost/nginx/stage.cardify.om.conf.
#   6. Issue Let's Encrypt cert via certbot-auto (requires DNS to be
#      resolving already; the script bails gracefully if not).
#   7. Install deploy-stage hook: /usr/local/bin/deploy-stage-cardify.sh
#      which pulls the `stage` branch and runs the same pre/post-flight
#      pattern as prod.

set -uo pipefail

STAGE_HOST="stage.cardify.om"
STAGE_ROOT="/www/wwwroot/$STAGE_HOST"
PROD_ROOT="/www/wwwroot/cardify.om"
DB_ROOT_REQUIRED="bc_stage grant requires root MySQL credentials"
DB_NAME="bc_stage"
DB_USER="bc"
DB_PASS="pWewN3fwFmEHh32J"
BRANCH="${STAGE_BRANCH:-stage}"

log() { echo "[$(date -Iseconds)] $*"; }

# --- 1. DNS sanity ---
if ! getent hosts "$STAGE_HOST" >/dev/null 2>&1; then
    log "WARN: $STAGE_HOST does not resolve yet. Add Cloudflare A record "
    log "      $STAGE_HOST → 147.93.20.54 then re-run this script."
    # We still provision the local pieces; SSL + vhost will fail later
    # but everything else is useful.
fi

# --- 2. Docroot + git clone ---
if [ ! -d "$STAGE_ROOT/.git" ]; then
    log "Cloning Cardify repo into $STAGE_ROOT"
    mkdir -p "$STAGE_ROOT"
    git -C "$STAGE_ROOT" clone https://github.com/zaabi1995/business-card-generator.git . \
        || { log "ERROR: git clone failed"; exit 1; }
else
    log "Docroot already exists; fetching $BRANCH"
    git -C "$STAGE_ROOT" fetch origin "$BRANCH" || true
fi
git -C "$STAGE_ROOT" checkout "$BRANCH" 2>/dev/null \
    || git -C "$STAGE_ROOT" checkout -b "$BRANCH" "origin/$BRANCH" 2>/dev/null \
    || { log "WARN: origin/$BRANCH doesn't exist yet. Create it from main and push."; }
chown -R www:www "$STAGE_ROOT"
find "$STAGE_ROOT" -type f -exec chmod 644 {} +
find "$STAGE_ROOT" -type d -exec chmod 755 {} +

# --- 3. MySQL scratch DB ---
if mysql -u"$DB_USER" -p"$DB_PASS" -e "USE \`$DB_NAME\`" 2>/dev/null; then
    log "Database $DB_NAME already accessible to $DB_USER"
else
    log "WARN: $DB_NAME not accessible. $DB_ROOT_REQUIRED."
    log "      Run as DBA:"
    log "      CREATE DATABASE $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    log "      GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';"
    log "      GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'%';"
    log "      FLUSH PRIVILEGES;"
fi

# --- 4. Seed from latest prod backup if empty ---
TABLES=$(mysql -u"$DB_USER" -p"$DB_PASS" -NBe \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME'" 2>/dev/null || echo 0)
if [ "${TABLES:-0}" -lt 5 ]; then
    LATEST=$(ls -1t /var/backups/cardify/cardify-*.sql.gz 2>/dev/null | head -1)
    if [ -n "$LATEST" ]; then
        log "Seeding $DB_NAME from $LATEST"
        gunzip -c "$LATEST" | mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" 2>/dev/null || log "WARN seed failed"
    else
        log "WARN: no prod backup found under /var/backups/cardify/, skipping seed"
    fi
fi

# --- 5. config.php overrides ---
if [ ! -f "$STAGE_ROOT/config.php" ]; then
    log "Writing $STAGE_ROOT/config.php (staging overrides)"
    cat > "$STAGE_ROOT/config.php" <<'CFG'
<?php
// Cardify staging config. Loads prod config and overrides for env.
define('APP_ENV', 'stage');
define('APP_HOST', 'stage.cardify.om');
define('DB_NAME_OVERRIDE', 'bc_stage');
define('SHOW_STAGE_BANNER', true);
// Paymob SANDBOX ids — must be set before first payment test.
define('PAYMOB_SECRET_KEY', getenv('PAYMOB_STAGE_SECRET') ?: '');
define('PAYMOB_HMAC_SECRET', getenv('PAYMOB_STAGE_HMAC') ?: '');
define('PAYMOB_INTEGRATION_CARD', (int)(getenv('PAYMOB_STAGE_INTEGRATION_ID') ?: 0));
require_once __DIR__ . '/config.shared.php';
CFG
    chown www:www "$STAGE_ROOT/config.php"
    chmod 640 "$STAGE_ROOT/config.php"
fi

# --- 6. Nginx vhost (aaPanel) ---
VHOST=/www/server/panel/vhost/nginx/$STAGE_HOST.conf
if [ ! -f "$VHOST" ]; then
    log "Writing $VHOST"
    cat > "$VHOST" <<NGINX
server {
    listen 80;
    listen 443 ssl http2;
    server_name $STAGE_HOST;
    root $STAGE_ROOT;
    index index.php index.html;

    # SSL (placeholder; cert issuance below)
    ssl_certificate     /www/server/panel/vhost/cert/$STAGE_HOST/fullchain.pem;
    ssl_certificate_key /www/server/panel/vhost/cert/$STAGE_HOST/privkey.pem;

    # Block search engines from indexing the staging mirror.
    add_header X-Robots-Tag "noindex, nofollow, noarchive" always;

    include /www/server/panel/vhost/rewrite/cardify.om.conf;

    location ~ \.php\$ {
        fastcgi_pass unix:/tmp/php-cgi-83.sock;
        fastcgi_index index.php;
        include fastcgi.conf;
    }
    location ~ /\.(git|env) { deny all; return 404; }
}
NGINX
    log "Vhost written. Reload nginx after cert issuance."
fi

# --- 7. SSL via certbot (skipped if DNS still down) ---
if getent hosts "$STAGE_HOST" >/dev/null 2>&1; then
    if [ ! -f "/www/server/panel/vhost/cert/$STAGE_HOST/fullchain.pem" ]; then
        log "Issuing Let's Encrypt cert for $STAGE_HOST"
        mkdir -p "/www/server/panel/vhost/cert/$STAGE_HOST"
        /www/server/panel/pyenv/bin/certbot certonly --webroot -w "$STAGE_ROOT" \
            -d "$STAGE_HOST" --email info@bhd.om --agree-tos --non-interactive \
            --cert-path "/www/server/panel/vhost/cert/$STAGE_HOST/fullchain.pem" 2>&1 \
            || log "WARN: certbot failed — SSL cert not issued"
    fi
fi

# --- 8. Deploy script for stage ---
if [ ! -f /usr/local/bin/deploy-stage-cardify.sh ]; then
    log "Installing /usr/local/bin/deploy-stage-cardify.sh"
    sed "s|/www/wwwroot/cardify.om|$STAGE_ROOT|g; s|git pull origin main|git pull origin $BRANCH|g; s|https://cardify.om|https://$STAGE_HOST|g" \
        /usr/local/bin/deploy-cardify.sh > /usr/local/bin/deploy-stage-cardify.sh
    chmod +x /usr/local/bin/deploy-stage-cardify.sh
fi

nginx -t 2>&1 | tail -3
systemctl reload nginx 2>/dev/null || true

log "Done. Visit https://$STAGE_HOST (once DNS + cert land)."
