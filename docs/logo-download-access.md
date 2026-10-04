# Logo download access

Guests receive five distinct entities per Oman calendar day. Email-verified members receive ten, including guest selections carried across signup. All formats and colour variants of a selected entity remain available that day. Guests are identified by hashed client IP, so a shared network shares its guest allowance; registered accounts have their own allowance.

Unlimited access defaults to OMR 2.000 for 30 days, prepaid with no automatic renewal. `LOGO_PASS_PRICE_OMR` and `LOGO_PASS_DAYS` can override these defaults in runtime config. `LOGO_ACCESS_FREE_PERIOD = 'lifetime'` switches the server counter to a one-time allowance; update public FAQ and terms copy if enabling that setting.

Signup and login use the existing email OTP service with a separate `logo_access` purpose. Logo membership has its own remembered, revocable token and grants no company-admin access. Authenticated Cardify users can use their existing identity. Signup does not create marketing consent.

`POST /api/logo-access.php` checks CSRF and creates a five-minute, single-use, format-specific ticket. `GET /logo-download` consumes that ticket before serving a file. The old lead cookie does not authorize downloads, and the old unlock endpoint returns 410. Public previews remain copyable; this service controls the download workflow and is not DRM or a trademark licence.

The Paymob checkout uses the existing merchant configuration. Apple Pay uses the merchant ID and integration already configured on Cardify's print checkout. Billing requires actual name, verified email and mobile, with no fabricated contact fallback. The logo pass has no recurring agreement or saved-card request. Paid access activates only through a valid HMAC callback with matching gateway order ID, amount and OMR currency. Pending transactions and authorization-only transactions do not activate access. Repeated callbacks do not extend a pass twice. Refunds revoke the current pass.

Rewarded ads are disabled until `LOGO_REWARDED_AD_UNIT` contains a genuine approved Google Ad Manager unit path. Do not put the AdSense display slot there. When configured, the optional button is available to free members after the allowance is exhausted. GPT's `rewardedSlotGranted` event grants one additional entity, capped at three per period. A one-use server challenge binds the claim to the account; GPT web events are client-side signals, not cryptographic server-side view verification. Do not represent this as fraud-proof. Unfilled, unsupported, blocked, cancelled and failed ads leave registration, paid access and browsing available.

Before enabling rewards, verify the unit's eligibility, Google consent integration and reward policy with the real account. Relevant official documentation:

- https://developers.google.com/publisher-tag/samples/display-rewarded-ad
- https://support.google.com/admanager/answer/7496282

Run `php tests/php/logo_access_test.php` against disposable local MySQL, plus existing payment callback and CSP tests. The integration test creates and removes a private test database, exercises concurrent quotas, and never sends mail or calls Paymob.

Run `scripts/prune-logo-access.php` daily as `www` using the aaPanel PHP binary. It deletes expired ticket/session records and daily quota history older than 90 days, retaining paid purchase records.
