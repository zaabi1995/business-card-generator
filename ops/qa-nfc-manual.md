# NFC Tag Write — Manual QA Procedure

Cat U action 490 (physical-device portion). Runs whenever you get a new
batch of NTAG stickers or the NFC-write code changes. Takes 5 minutes.

**Required hardware**
- Chrome-for-Android phone (NFC built in).
- One unprogrammed NTAG213/215/216 sticker (Cardify order-confirmation
  packs include a test tag labelled "QA").
- A Cardify admin account on the target environment (stage or prod).

**One-time per device**
- Enable NFC in system Settings if it's off.
- Open Chrome → `chrome://flags` → set **Experimental Web Platform features** to **Enabled** if a prompt tells you Web NFC is off.

## Happy path

1. Sign in on the Android phone as an admin.
2. Open `/admin/nfc/batch.php`. Pick any employee with a live card.
3. Tap **Program tag**.
4. Hold a test tag to the back of the phone. Expect:
   - Browser prompt "write to NFC tag?" → accept.
   - Toast "Tag programmed" inside ≤2s.
5. Tap the same tag against an unlocked Android screen (no app open).
   - Expect the browser to open `https://cardify.om/{slug}/card/{eid}`.
6. On the admin side, refresh `/admin/nfc/batch.php`. The row flips to
   **Programmed** with today's timestamp.

## Negative paths to spot-check

- **Read-only tag**: held, phone says "can't write". Admin row stays
  unprogrammed. No row inserted into `nfc_cards` until write succeeds.
- **Wrong tag standard** (e.g. ISO 14443-A non-NDEF): phone emits a
  "tag type not supported" browser error, admin stays unprogrammed.
- **Double-program the same tag**: `POST /admin/nfc/mark-programmed.php`
  is idempotent via `tag_uid UNIQUE`. Second attempt just updates the
  timestamp, does NOT insert a duplicate row.
- **Unauth access**: log out, visit `/admin/nfc/write.php?eid=…` — must
  redirect to `/login.php`. Covered by `tests/e2e/nfc-flow.spec.ts`.

## When a regression shows

- Re-check this memory note: cardify `includes/CardAnalytics.php`
  attaches the NFC "source=nfc" tag-click event; if that stops firing
  the attribution break is the first smell.
- Chrome Web NFC requires **HTTPS**. A tag programmed on stage
  (`https://stage.cardify.om`) will open stage on the second tap;
  prod uses prod. Don't mix tags across envs.
- If `NDEFReader` is undefined on the phone, Chrome Android is the
  only supported browser today (Safari iOS never shipped Web NFC).
  Document that under `/tools/nfc-business-card-guide.php` but don't
  treat it as a bug.

## When this procedure passes

Tick the row in `ops/qa-nfc-log.md` with date + tag UID + tester
initials. A 12-week rolling history of passing runs is what we show
to enterprise clients auditing our NFC reliability.
