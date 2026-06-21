# World Cup campaign (wc.cardify.om) status

Living status for the campaign build. Records what shipped and what is
genuinely blocked on an external account/credential, so we do not re-spend
effort or fake the blocked pieces.

## Shipped

- Landing, predictions, leaderboard, settings, OTP signup, scorer cron.
- Gamification (Jun 2026): daily streak check-in + derived badges.
  - `wc_checkins(user_id, checkin_date)` UNIQUE(user_id, checkin_date),
    + `wc_users.streak_count / streak_best / last_checkin` (migration 122).
  - `POST /api/wc-checkin.php`: reserves the unique row first, then awards
    +1/day into `bonus_points` + `points_cache` (so it survives the scorer
    cron, which recomputes `points_cache = SUM(pred points) + bonus_points`);
    +5 one-time at a 7-day streak. Idempotent: a second tap the same day is
    a no-op (verified: two POSTs award once, one check-in row).
  - Badges derived live from `wc_predictions` + `wc_users`
    (`WcHub::badges()`): first prediction, 10 predictions, 5 correct
    results, 7-day streak, referred a friend. Earned/locked strip on
    `predictions.php`.
  - Strings extended in `WcHub::pstrings()` (en + ar).
- Phase 6 social ad set: `assets/wc/ads/{og,square,story}.jpg`
  (1200x630 / 1080x1080 / 1080x1920, rendered 2x). Single CTA
  WC.cardify.om, $10,000 predict-and-win secondary line, "Sponsored"
  marked, fonts.bhd.om + FA7. Rebuild with `node scripts/wc-ads/render.mjs`.
  The 1200x630 is also wired as the real landing OG image
  (`assets/wc/og.jpg`, referenced from `world-cup.php`).
- Google Wallet pass (low-risk piece, shipped): `/wc-wallet-google`
  builds a generic Wallet object (points, rank, streak, QR to
  /predictions) under the existing live `GOOGLE_WALLET_CLASS_ID` and
  302s to the pay.google.com save URL. Surfaced from `/wc-wallet`.
  Reflects the player's state AT SAVE TIME.

## Blocked (needs an account/credential from Ali, not a code change)

### 1. Apple Wallet daily-updating pass = BLOCKED
- Needs an APNs certificate for the Pass Type ID under Ali's Apple
  Developer account, plus the Pass Type ID + WWDR cert.
- Without the APNs push channel there is no way to push the daily
  match/points refresh to an installed `.pkpass`. A static pass alone is
  not worth shipping for the "daily update" promise.
- Unblock: create/download the Pass Type ID + APNs auth key (or cert) in
  the Apple Developer portal, drop them in `data/wallet/`, then we wire
  the `.pkpass` build + APNs pusher.

### 2. Cloudflare Turnstile anti-bot on OTP = BLOCKED
- The code already supports it: `api/wc-otp-request.php` enforces
  Turnstile only when `TURNSTILE_SECRET` is defined, and
  `world-cup.php` renders the widget when `TURNSTILE_SITE` is set.
- Unblock: create a Turnstile widget in Ali's Cloudflare dashboard for
  `wc.cardify.om`, then define `TURNSTILE_SITE_KEY` + `TURNSTILE_SECRET`
  in `config.php` on the VPS. No deploy required; it activates on next
  request.

### 3. Google Wallet LIVE daily auto-refresh = partially blocked
- The save-to-wallet path works today (see Shipped). What is NOT yet
  wired is server-side push so the SAVED pass updates itself daily
  (new fixtures, new points) without the user re-saving.
- This needs a small server job that PATCHes the Google Wallet object via
  the Wallet REST API on the same cron cadence as the daily digest. The
  service account already has the credentials; this is build work, not an
  external blocker. Tracked here so it is not mistaken for "done".
