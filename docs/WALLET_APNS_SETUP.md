# Cardify Wallet pass auto-update — credential + setup

## Four DISTINCT credentials (do not conflate)
1. **Pass-signing certificate** — signs the `.pkpass`. ALREADY CONFIGURED:
   `data/wallet/cardify_pass.pem` + `data/wallet/wwdr.pem`. NOT used for push.
2. **Wallet update-push credential** — sends the "a new pass is available" push.
   Per Apple's official "Adding a web service to update passes" documentation,
   this is a **Pass Type ID APNs certificate** (a certificate issued for the
   Pass Type ID). This is the ONLY method Apple documents for Wallet update
   pushes; we implement exactly this. (An app APNs Auth Key `.p8` is NOT the
   documented Wallet credential and is not relied upon here.) **THIS IS THE
   REMAINING BLOCKER.**
3. **Standard app APNs certificate / Auth Key** — for the APP's own push
   notifications (register-push). Separate concern, different topic. Not used
   for Wallet.
4. **App Store Connect API key** (VW4KKUJU8Z) — ASC automation. Unrelated.

## Wallet push specifics (verified against Apple docs)
- **Topic** = the Pass Type Identifier `pass.om.cardify.businesscard` (NOT the
  app bundle id `om.cardify.scan`).
- **Host** = production `api.push.apple.com` for pass pushes (always).
- **Payload** = EMPTY. The push only signals Wallet to pull the latest pass.

## Create the Pass Type ID APNs certificate (Developer role: Admin)
1. developer.apple.com > Certificates, Identifiers & Profiles > Identifiers.
2. Confirm the Pass Type ID `pass.om.cardify.businesscard` EXISTS (it does - it
   is already the pass-signing identity).
3. Select it > under APNs / "Create Certificate", generate a **Pass Type ID
   Certificate**. This REQUIRES a CSR: on the Mac, Keychain Access > Certificate
   Assistant > Request a Certificate from a CA (saves `CertificateSigningRequest.certSigningRequest`).
4. Upload the CSR, download the issued `.cer`.
5. Import the `.cer` into Keychain, export the cert + private key as a `.p12`.
6. Convert to an unencrypted PEM for the server:
   `openssl pkcs12 -in wallet_push.p12 -out wallet_push.pem -nodes -legacy`
7. Validate: `openssl x509 -in wallet_push.pem -noout -subject -enddate`
   (subject should reference the Pass Type ID; note the expiry ~1 year).

## Environment variables (config.php) to activate real pushes
- APPLE_WALLET_PUSH_CERT_PATH = /www/wwwroot/cardify.om/data/wallet/wallet_push.pem
- APPLE_WALLET_TEAM_ID        = F436258VA2 (already defined)
- APPLE_WALLET_APNS_ENV       = production
File permissions: `chown www:www` + `chmod 600` (kept under data/wallet/, which
nginx denies at the edge; never committed to Git).
Until this exists, `apnsProvider()` returns the mock (no network, no false
"sent"); the production provider fails closed with an actionable error.

## Connectivity test (after the cert is in place)
`php wallet_apns_check.php` (to be added) opens an HTTP/2 APNs connection to
`api.push.apple.com` with the cert and reports auth success WITHOUT sending a
push to a real device.

## Rollback
Unset APPLE_WALLET_PUSH_CERT_PATH (or remove the PEM) -> the provider reverts to
the mock; pass GENERATION and the web service keep working; only live pushes
pause. No schema change to undo.

## Existing static passes
Passes added to Wallet BEFORE this change have serialNumber = employee id and NO
webServiceURL/authenticationToken, so they CANNOT become updatable by a backend
change alone. Users must re-add the card (a new, update-enabled pass). Do not
claim old passes auto-update.

## Token encryption at rest (added 17 Jul 2026)
PassKit embeds the CLEAR authenticationToken in every regenerated pass.json, so
a one-way hash is impossible. Tokens are therefore AES-256-GCM encrypted at rest:
- format `v<keyVersion>:<base64url(nonce||ciphertext||tag)>`, 12-byte nonce per value.
- key: `data/wallet/token_key.bin` (0600 www:www, outside the DB, outside git,
  nginx 404s the directory - verified). Env: APPLE_WALLET_TOKEN_KEY_PATH.
- key VERSIONS supported (`.v2` etc) so rotation never invalidates issued passes.
- a keyed HMAC column (`auth_token_hmac`) makes request auth a constant-time
  compare that NEVER decrypts.
- missing key => fail closed (throw), never silent plaintext.
Migration: `php wallet_encrypt_tokens.php --dry-run|--apply|--verify`. --apply
mysqldumps scan_passes first and round-trip-checks each value before writing.
Rollback: `mysql <db> < /root/backups/cardify/scan_passes-preencrypt-<ts>.sql`.
