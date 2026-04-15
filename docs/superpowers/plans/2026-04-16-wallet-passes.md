# Plan: Apple Wallet + Google Wallet Pass Generation for Cardify

**Date:** 2026-04-16
**Branch:** `infy-wallet-passes`
**Status:** Implementation landed; awaiting credentials before go-live.

## Goal

Each Cardify digital card (`/{slug}/card/{id}`) gains two buttons:
- "Add to Apple Wallet" → downloads `.pkpass` (signed)
- "Add to Google Wallet" → redirects to `https://pay.google.com/gp/v/save/<JWT>`

When scanned/opened on an iPhone or Android, the user can save the card to their native wallet. The pass displays name, title, company, phone, email, website, and a QR code linking back to the live digital card URL.

## Architecture

Cardify is flat PHP (not Laravel). Implementation uses top-level PHP entry points consistent with `digital_card.php`, `qr.php`, `vcf.php`:

- `wallet_apple.php` — accepts `?i=<employee_id>` (and optional `?c=<company_slug>`), returns `.pkpass` binary
- `wallet_google.php` — same params, issues a 302 redirect to the Google Wallet save URL
- `includes/AppleWalletPass.php` — clean-room pure-PHP PKPass generator (uses `openssl_pkcs7_sign`)
- `includes/GoogleWalletPass.php` — generic pass JWT builder using RS256 service-account signing
- `includes/jwt.php` — minimal RS256 JWT signer (no composer dependency; ~40 LOC)

Fabric.js editor and Paymob flows are untouched. Existing `config.php`/`config.example.php` gets new env constants; absent credentials cause the endpoints to render a clear admin-facing error and the buttons to hide.

## Library Choice

**No composer libraries added.** Cardify's composer.json only carries `tecnickcom/tcpdf`. To keep the surface minimal and match the rest of the codebase (all helpers live in `includes/`), we hand-roll:

1. **PKPass signing** uses `openssl_pkcs7_sign()` from PHP's built-in `openssl` extension (already required). The algorithm: compute SHA-1 of each asset into `manifest.json`, detach-sign the manifest with the Pass Type cert + WWDR intermediate, then zip everything.
2. **Google JWT** uses `openssl_sign()` with `OPENSSL_ALGO_SHA256` over a `base64url(header).base64url(payload)` string. ~30 LOC.

Infy's `GoogleWalletAPIController` was read for reference only; none of their module code is imported.

## Apple Wallet: What Ali Needs

Before Apple Wallet buttons can render, Ali must provision:

1. **Apple Developer Program membership** — $99/yr. If not enrolled: https://developer.apple.com/programs/enroll/
2. **Create a Pass Type ID** — developer.apple.com → Certificates, IDs & Profiles → Identifiers → "+" → "Pass Type IDs" → `pass.om.cardify.businesscard` (reverse-DNS)
3. **Generate a CSR on macOS** — Keychain Access → Certificate Assistant → "Request a Certificate from a Certificate Authority" → save `.certSigningRequest`
4. **Issue the Pass Type certificate** — upload CSR to the Pass Type ID, download `.cer`, double-click to install in Keychain
5. **Export as `.p12`** — Keychain Access → right-click the private key + cert pair → Export Items → `.p12` with a strong password
6. **Download the WWDR G4 intermediate** — https://www.apple.com/certificateauthority/AppleWWDRCAG4.cer → convert to PEM: `openssl x509 -inform DER -in AppleWWDRCAG4.cer -out wwdr.pem`
7. **Convert .p12 to PEM** — `openssl pkcs12 -in cardify_pass.p12 -out cardify_pass.pem -nodes`
8. **Upload both files to the VPS** at `/www/wwwroot/cardify.om/data/wallet/` (0600 perms, www-data:www-data)
9. **Find Team ID** — developer.apple.com → Membership (10-char string like `ABC1234DEF`)
10. **Set env vars in `config.php`:**

```php
define('APPLE_WALLET_ENABLED', true);
define('APPLE_WALLET_CERT_PATH', '/www/wwwroot/cardify.om/data/wallet/cardify_pass.pem');
define('APPLE_WALLET_CERT_PASSWORD', '<p12 password>');
define('APPLE_WALLET_WWDR_PATH', '/www/wwwroot/cardify.om/data/wallet/wwdr.pem');
define('APPLE_WALLET_PASS_TYPE_ID', 'pass.om.cardify.businesscard');
define('APPLE_WALLET_TEAM_ID', 'ABC1234DEF');
define('APPLE_WALLET_ORG_NAME', 'Cardify');
```

## Google Wallet: What Ali Needs

1. **Google Cloud project** — https://console.cloud.google.com → New Project "Cardify Wallet"
2. **Enable Google Wallet API** — console → APIs & Services → Library → "Google Wallet API" → Enable
3. **Apply for Wallet Issuer account** — https://pay.google.com/business/console → sign up for an Issuer ID (10-16 digit number, free)
4. **Create a service account** — console → IAM → Service Accounts → Create → role "Wallet Object Issuer" → download JSON key
5. **Grant Issuer access** — in pay.google.com/business/console, add the service account's email as a user for your Issuer ID
6. **Upload key to VPS** at `/www/wwwroot/cardify.om/data/wallet/google-wallet-sa.json` (0600)
7. **Set env vars in `config.php`:**

```php
define('GOOGLE_WALLET_ENABLED', true);
define('GOOGLE_WALLET_SERVICE_ACCOUNT_JSON', '/www/wwwroot/cardify.om/data/wallet/google-wallet-sa.json');
define('GOOGLE_WALLET_ISSUER_ID', '3388000000012345678');
define('GOOGLE_WALLET_CLASS_ID', 'cardify_business_card_v1'); // arbitrary — created lazily on first save
```

First pass save will auto-create the generic class if it doesn't exist (via REST API from the service account).

## Pass Content

Both platforms use a "generic" pass type (Apple) / "genericClass" (Google) since a business card is neither a ticket nor a coupon.

| Field | Source | Apple | Google |
|---|---|---|---|
| Primary | `employee.name` | `primaryFields[0]` | `header` + `cardTitle` |
| Secondary L | `employee.position` | `secondaryFields[0]` | `subheader` |
| Secondary R | `company.name` | `secondaryFields[1]` | `title` |
| Back: phone | `employee.phone/mobile` | `backFields` | `textModulesData` |
| Back: email | `employee.email` | `backFields` | `textModulesData` |
| Back: website | `company.website` | `backFields` | `linksModuleData` |
| QR | digital card URL | `barcodes[0]` QR | `barcode` QR |
| Logo | company theme logo | `logo.png` bundle | `logo.sourceUri` |
| Strip color | theme primary | `backgroundColor` | `hexBackgroundColor` |

## Endpoint Security

Both endpoints are public (no auth) — matching `vcf.php`. They only read by `employee_id + company_slug` (same as `digital_card.php`). Rate limit is inherited from nginx. No PII leaks: all fields already exposed on the public card page.

## Feature Flag & UI

Buttons render only if the corresponding `*_WALLET_ENABLED` constant is true. UA sniff in JS:
- iOS/macOS Safari → Apple button primary, Google secondary
- Android → Google primary, Apple secondary
- Desktop (non-Mac) → both side-by-side

If a user hits the endpoint when disabled, they see a friendly `503` page: "Wallet passes not yet configured. Admin: see docs/superpowers/plans/2026-04-16-wallet-passes.md".

## Testing

- Without certs: endpoints return 503 with clear message; buttons don't render on `digital_card.php`.
- With certs (post-deploy): `curl -I /wallet_apple.php?i=<eid>` → `Content-Type: application/vnd.apple.pkpass`
- Google: `curl -I /wallet_google.php?i=<eid>` → `302` to `pay.google.com/gp/v/save/<jwt>`
- `php -l` on every touched file.

## Rollout

1. Merge PR after Ali obtains certs + sets env vars.
2. On first Google pass save, the class is lazily created via `POST /walletobjects/v1/genericClass`.
3. Monitor error logs at `logs/php-errors.log` for signing failures.

## Non-goals

- Pass updates / push notifications (requires an APNs token + webservice endpoint — V2 feature).
- Multiple languages in pass (EN only for V1).
- Custom strip/background images beyond the logo.
