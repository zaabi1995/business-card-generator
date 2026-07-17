# Cardify Wallet pass auto-update — credential + setup

## Four DISTINCT credentials (do not conflate)
1. **Pass-signing certificate** — signs the `.pkpass`. ALREADY CONFIGURED:
   `data/wallet/cardify_pass.pem` + `data/wallet/wwdr.pem`. Not used for push.
2. **Wallet update-push credential** — sends the "a new pass is available" push.
   Per Apple docs this is a **Pass Type ID APNs certificate** (created for the
   Pass Type ID). A token-based APNs Auth Key (.p8) for the team also works in
   practice; the certificate is the documented path. **THIS IS THE BLOCKER.**
3. **App APNs credential** — for the app's own notifications (register-push).
   Separate; may be the same .p8 with a different topic.
4. **App Store Connect API key** (VW4KKUJU8Z) — ASC automation. Unrelated.

## Wallet push specifics (verified against Apple docs)
- **Topic** = the Pass Type Identifier `pass.om.cardify.businesscard` (NOT the
  app bundle id `om.cardify.scan`).
- **Host** = production `api.push.apple.com` for pass pushes (always).
- **Payload** = EMPTY. The push only signals Wallet to pull the latest pass.

## Where to create it (Apple Developer account, role: Admin)
Certificates path: developer.apple.com > Certificates, IDs & Profiles >
Identifiers > (select `pass.om.cardify.businesscard`) > create a **Pass Type ID
Certificate**. Download, export to PEM (cert+key). Renew before expiry (~1 yr).

## Environment variables (config.php) to activate real pushes
- APPLE_WALLET_APNS_KEY_PATH   = /www/wwwroot/cardify.om/data/wallet/<apns>.pem (or .p8)
- APPLE_WALLET_APNS_KEY_ID     = <key id, if using a .p8 auth key>
- APPLE_WALLET_TEAM_ID         = F436258VA2 (already defined)
- APPLE_WALLET_APNS_ENV        = production
Until these exist, `apnsProvider()` returns the mock (no network, no false
"sent"); `TokenApnsProvider` fails closed with result=error.

## Existing static passes
Passes added to Wallet BEFORE this change have serialNumber = employee id and NO
webServiceURL/authenticationToken, so they CANNOT become updatable by a backend
change alone. Users must re-add the card (new pass, update-enabled). Do not claim
old passes auto-update.
