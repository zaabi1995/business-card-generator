# Wallet Pass Install — Manual QA

Cat U actions 491 + 492 (physical-device portion). Runs whenever
Apple Wallet certs rotate, Google Wallet issuer config changes, or
the `pkpass` template structure is touched. Takes 5 minutes each.

**Required hardware**
- iPhone running iOS 17+ with Apple Wallet (for 491).
- Android phone with Google Wallet installed (for 492).
- A Cardify admin account + one real employee card on the target env.

## 491 — Apple Wallet (iPhone)

1. On the iPhone, open `https://cardify.om/{slug}/card/{eid}` (use
   KNOWN_CARD from `tests/e2e/fixtures.ts` for a sanity run on prod).
2. Tap the **Add to Apple Wallet** button.
3. Expect:
   - Safari downloads a file ending `.pkpass`.
   - iOS shows the **Add to Wallet** system sheet.
   - Pass preview shows employee name + title + logo + QR on back.
   - Tap **Add** (top-right). Pass lands in the Wallet app.
4. Open Wallet. Front: name + title, logo top-right. Back: tap (i).
   - URL field links to the digital card.
   - NFC field, if set, encodes the vCard link.
5. Negative: delete the pass, re-open the card URL, tap **Add to
   Wallet** again. Must not 500. Must mint a pass with the SAME
   serialNumber (pass replacement, not duplicate).

**When it fails**
- "Cannot add pass" → cert expired or WWDR missing. Check
  `APPLE_WALLET_CERT_PATH`, `APPLE_WALLET_KEY_PATH`, `APPLE_WALLET_WWDR_PATH`
  in `config.php` and `openssl x509 -in … -text -noout` the cert for
  expiry.
- 503 on `/wallet_apple.php?i=…` → feature flag off; see
  `includes/AppleWalletPass.php` for the env vars it needs.

## 492 — Google Wallet (Android)

Google Wallet uses signed JWT-encoded Save links, not pkpass files.

1. On Chrome Android, open `https://cardify.om/{slug}/card/{eid}`.
2. Tap **Add to Google Wallet**.
3. Expect:
   - Browser hands off to Google Wallet app.
   - Preview: name + title + logo + QR.
   - Tap **Add**. Pass lands under "Passes" in Wallet.
4. Tap the pass in Wallet:
   - QR scans back to the digital card URL.
   - "View card" link opens the browser to the card.
5. Negative: same re-issue check — add twice, expect Google Wallet
   to merge by the pass's objectId (not create a duplicate).

**When it fails**
- "Something went wrong" on Save → Google Wallet Issuer ID not set
  up (needs a Google Pay & Wallet Console account). Queue the
  follow-up with Ali.
- Save button missing from the card page → `GOOGLE_WALLET_ENABLED`
  flag is off; confirm via `config.php`.

## After each pass

Record in `ops/qa-wallet-log.md` (create if missing):
  - Date | env (prod/stage) | iPhone iOS ver | Android OS ver |
    pkpass bytes | serialNumber | tester initials.
A rolling 12-month log of passing runs is the evidence we show
enterprise clients reviewing our wallet reliability.
