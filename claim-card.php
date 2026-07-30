<?php
/**
 * /claim-card.php?t={claim_token}
 *
 * Public claim page for the Cardify Scan growth loop. An employee scans a
 * business card in the mobile app, invites the person via WhatsApp
 * (api/scan/invite.php), and that message links here. The page previews
 * the auto-built digital card, lets the person claim it by verifying a
 * WhatsApp OTP, then hands off to /company/register.php with a prefill.
 *
 * NOT the same file as claim.php (unrelated viral-footer lead capture) or
 * claim-lead.php (bulk-claim magic-link flow for pre-onboarded employees).
 * Distinct table (shadow_profiles), distinct token (43-char claim_token),
 * distinct verification (WhatsApp OTP, not a one-time magic link).
 *
 * GET  ?t=<token>                 -> preview + claim CTA (or "already
 *                                     claimed" state, or 404 for a bad
 *                                     token, or the terminal "messages
 *                                     stopped" screen if opted out).
 * GET  ?t=<token>&optout=1        -> confirmation screen only, does NOT
 *                                     mutate state (state-changing GET
 *                                     endpoints get prefetched by link
 *                                     scanners/WhatsApp preview bots, see
 *                                     memory feedback_state_changing_get_
 *                                     endpoints.md). The actual opt-out
 *                                     happens on the POST below.
 * POST ?t=<token>&optout=1        -> flips shadow_profiles.opted_out = 1
 *                                     (CSRF-protected, idempotent).
 *
 * The claim OTP send/verify itself is handled client-side by plain fetch()
 * against api/scan/claim-otp.php, no framework.
 */

ob_start();
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    require_once __DIR__ . '/config.php';
    require_once INCLUDES_DIR . '/SecurityHeaders.php';
    SecurityHeaders::send();
    require_once INCLUDES_DIR . '/functions.php';
    require_once INCLUDES_DIR . '/ShadowProfileService.php';
} catch (Throwable $e) {
    while (ob_get_level()) { ob_end_clean(); }
    http_response_code(500);
    echo 'Error loading /claim-card.';
    error_log('claim-card bootstrap: ' . $e->getMessage());
    exit;
}

$token = trim((string)($_GET['t'] ?? ''));
$profile = $token !== '' ? ShadowProfileService::findByToken($token) : null;

if (!$profile) {
    http_response_code(404);
    renderClaimCardError(
        'Invalid or expired link.',
        'Please check the link you received on WhatsApp, it may have been copied incompletely.',
        'رابط غير صالح أو منتهي الصلاحية.',
        'الرجاء التحقق من الرابط الذي وصلك عبر واتساب، فقد يكون نُسخ ناقصاً.'
    );
    exit;
}

$isOptOutRequest = isset($_GET['optout']);
$alreadyOptedOut = (int)$profile['opted_out'] === 1;

// ---------------- OPT-OUT ----------------
if ($isOptOutRequest) {
    // POST confirms and actually mutates. GET only ever renders a screen,
    // never flips the flag, so a link-preview crawler fetching the raw
    // WhatsApp URL cannot silently opt someone out.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            http_response_code(400);
            renderClaimCardError(
                'Invalid request.',
                'Please open the opt-out link again.',
                'طلب غير صالح.',
                'الرجاء فتح رابط إيقاف الرسائل مرة أخرى.'
            );
            exit;
        }
        // Idempotent: re-running this on an already opted-out row is a
        // harmless no-op UPDATE, not an error.
        Database::getInstance()->getConnection()->prepare(
            "UPDATE shadow_profiles SET opted_out = 1 WHERE id = ?"
        )->execute([(int)$profile['id']]);
        renderOptOutDone();
        exit;
    }

    if ($alreadyOptedOut) {
        renderOptOutDone();
        exit;
    }

    renderOptOutConfirm($token, generateCSRFToken());
    exit;
}

// Opted-out is terminal on EVERY path, not just ?optout=1: a plain
// GET ?t=<token> for an opted-out profile must never show the card
// preview or claim button again (the API already 404s such tokens).
if ($alreadyOptedOut) {
    renderOptOutDone();
    exit;
}

// ---------------- CLAIM PREVIEW / FLOW (GET) ----------------
$parsed = json_decode((string)($profile['best_parsed'] ?? ''), true) ?: [];
$claimed = !empty($profile['claimed_at']);

$nameEn    = (string)($parsed['name_en'] ?? '');
$nameAr    = (string)($parsed['name_ar'] ?? '');
$titleEn   = (string)($parsed['title_en'] ?? '');
$titleAr   = (string)($parsed['title_ar'] ?? '');
$companyEn = (string)($parsed['company_en'] ?? '');
$companyAr = (string)($parsed['company_ar'] ?? '');
$email     = (string)($profile['email_primary'] ?? '');
$maskedPhone = maskClaimPhone((string)($profile['phone_primary'] ?? ''));

// Display name falls back across every direction so the card is never blank.
$displayName = $nameEn !== '' ? $nameEn : ($nameAr !== '' ? $nameAr : '');
$optOutUrl = 'claim-card.php?t=' . urlencode($token) . '&optout=1';
?><!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Your digital card is ready, Cardify</title>
    <link rel="icon" href="/favicon.ico">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.bhd.om">
    <link rel="preconnect" href="https://fonts.bhd.om" crossorigin>
    <link href="https://fonts.bhd.om/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', system-ui, -apple-system, 'Segoe UI', sans-serif; background: #f5f7fb; }
        [lang="ar"] { font-family: 'IBM Plex Sans Arabic', 'Plus Jakarta Sans', system-ui, sans-serif; letter-spacing: 0 !important; }
        .card-preview { box-shadow: 0 25px 50px -12px rgba(0, 155, 193, 0.35); }
        .otp-input { letter-spacing: 0.5em; text-indent: 0.5em; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
    <div class="max-w-md w-full" data-token="<?= htmlspecialchars($token, ENT_QUOTES) ?>">

        <div class="text-center mb-6">
            <h1 class="text-2xl font-bold text-gray-900">Your digital card is ready</h1>
            <h1 lang="ar" dir="rtl" class="text-2xl font-bold text-gray-900 mt-1">بطاقتك الرقمية جاهزة</h1>
        </div>

        <!-- Card preview -->
        <div class="card-preview bg-gradient-to-br from-[#009bc1] to-[#824598] rounded-2xl p-6 text-white mb-6">
            <div class="flex items-start justify-between mb-6">
                <div class="min-w-0">
                    <div class="text-xs uppercase tracking-widest opacity-70">Digital business card</div>
                    <?php if ($displayName !== ''): ?>
                        <div class="text-2xl font-bold mt-1 leading-tight truncate"><?= htmlspecialchars($displayName, ENT_QUOTES) ?></div>
                    <?php endif; ?>
                    <?php if ($nameAr !== '' && $nameAr !== $displayName): ?>
                        <div lang="ar" dir="rtl" class="text-lg font-semibold opacity-90 leading-tight truncate"><?= htmlspecialchars($nameAr, ENT_QUOTES) ?></div>
                    <?php endif; ?>
                </div>
                <div class="bg-white/15 backdrop-blur rounded-lg w-10 h-10 flex items-center justify-center shrink-0">
                    <i class="fa-solid fa-id-card"></i>
                </div>
            </div>

            <?php if ($titleEn !== '' || $titleAr !== ''): ?>
                <div class="text-sm font-medium opacity-95">
                    <?= htmlspecialchars($titleEn, ENT_QUOTES) ?>
                    <?php if ($titleAr !== ''): ?>
                        <span lang="ar" dir="rtl"><?= $titleEn !== '' ? ' &middot; ' : '' ?><?= htmlspecialchars($titleAr, ENT_QUOTES) ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ($companyEn !== '' || $companyAr !== ''): ?>
                <div class="text-sm opacity-80">
                    <?= htmlspecialchars($companyEn, ENT_QUOTES) ?>
                    <?php if ($companyAr !== ''): ?>
                        <span lang="ar" dir="rtl"><?= $companyEn !== '' ? ' &middot; ' : '' ?><?= htmlspecialchars($companyAr, ENT_QUOTES) ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="mt-6 pt-4 border-t border-white/20 text-xs opacity-80 space-y-1">
                <?php if ($maskedPhone !== ''): ?>
                    <div dir="ltr"><i class="fa-solid fa-phone mr-2 opacity-60"></i><?= htmlspecialchars($maskedPhone, ENT_QUOTES) ?></div>
                <?php endif; ?>
                <?php if ($email !== ''): ?>
                    <div dir="ltr"><i class="fa-solid fa-envelope mr-2 opacity-60"></i><?= htmlspecialchars($email, ENT_QUOTES) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
            <?php if ($claimed): ?>
                <div class="text-center">
                    <div class="w-12 h-12 mx-auto bg-green-50 rounded-full flex items-center justify-center mb-4">
                        <i class="fa-solid fa-check text-green-600 text-xl"></i>
                    </div>
                    <h2 class="text-lg font-bold text-gray-900">This card was already claimed</h2>
                    <h2 lang="ar" dir="rtl" class="text-base font-semibold text-gray-700 mt-1">تم استلام هذه البطاقة مسبقاً</h2>
                    <a href="/login.php" class="inline-block mt-5 w-full bg-gradient-to-r from-[#009bc1] to-[#824598] text-white font-semibold py-3 rounded-xl shadow hover:shadow-lg transition">
                        Sign in <span lang="ar" dir="rtl">&middot; تسجيل الدخول</span>
                    </a>
                </div>
            <?php else: ?>
                <div id="claim-intro">
                    <p class="text-sm text-gray-600 leading-relaxed">
                        Claim it free in one step. We will send a 6-digit code on WhatsApp to confirm it is you.
                    </p>
                    <p lang="ar" dir="rtl" class="text-sm text-gray-500 leading-relaxed mt-2">
                        استلمها مجاناً بخطوة واحدة. سنرسل رمزاً من 6 أرقام عبر واتساب للتأكد من أنك أنت.
                    </p>
                    <button id="claim-btn" type="button" class="mt-5 w-full bg-gradient-to-r from-[#009bc1] to-[#824598] text-white font-semibold py-3 rounded-xl shadow hover:shadow-lg transition disabled:opacity-60">
                        <i class="fa-solid fa-wand-magic-sparkles mr-2"></i>Claim it free
                        <span lang="ar" dir="rtl" class="ml-1">&middot; استلمها مجاناً</span>
                    </button>
                    <p id="claim-error" class="mt-3 text-sm text-red-600 hidden"></p>
                </div>

                <div id="claim-otp" class="hidden">
                    <p class="text-sm text-gray-600">Enter the 6-digit code we sent on WhatsApp.</p>
                    <p lang="ar" dir="rtl" class="text-sm text-gray-500 mt-1">أدخل الرمز المكون من 6 أرقام الذي أرسلناه عبر واتساب.</p>
                    <input id="otp-code" type="tel" inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                           class="otp-input mt-4 w-full text-center text-2xl font-bold border border-gray-200 rounded-xl py-3 focus:outline-none focus:ring-2 focus:ring-[#009bc1]"
                           placeholder="000000">
                    <button id="verify-btn" type="button" class="mt-4 w-full bg-gradient-to-r from-[#009bc1] to-[#824598] text-white font-semibold py-3 rounded-xl shadow hover:shadow-lg transition disabled:opacity-60">
                        Verify <span lang="ar" dir="rtl">&middot; تحقق</span>
                    </button>
                    <button id="resend-btn" type="button" class="mt-3 w-full text-sm text-gray-500 hover:text-gray-700">
                        Didn't get it? Resend <span lang="ar" dir="rtl">&middot; إعادة الإرسال</span>
                    </button>
                    <p id="otp-error" class="mt-3 text-sm text-red-600 hidden"></p>
                </div>
            <?php endif; ?>
        </div>

        <div class="mt-6 text-center text-xs text-gray-400 space-y-1">
            <div>
                Made with
                <a href="/" class="text-[#00718c] font-medium">Cardify</a>
                &middot; BHD Printing &amp; Designing
            </div>
            <div>
                <a href="<?= htmlspecialchars($optOutUrl, ENT_QUOTES) ?>" class="hover:underline">Don't want this? Opt out permanently.</a>
                <span lang="ar" dir="rtl" class="block mt-0.5">
                    <a href="<?= htmlspecialchars($optOutUrl, ENT_QUOTES) ?>" class="hover:underline">لا ترغب بهذا؟ أوقف الرسائل نهائياً.</a>
                </span>
            </div>
        </div>
    </div>

    <link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/fontawesome.min.css?v=7.2.0">
    <link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/solid.min.css?v=7.2.0">
    <link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/brands.min.css?v=7.2.0">

<?php if (!$claimed): ?>
    <?php /* SecurityHeaders' CSP nonce keeps this inline script alive
             once the policy is promoted from report-only to enforcing. */ ?>
    <script nonce="<?= htmlspecialchars(SecurityHeaders::nonce(), ENT_QUOTES) ?>">
    (function () {
        var container = document.querySelector('[data-token]');
        var token = container ? container.getAttribute('data-token') : '';
        var API = 'api/scan/claim-otp.php';

        var introEl   = document.getElementById('claim-intro');
        var otpEl      = document.getElementById('claim-otp');
        var claimBtn   = document.getElementById('claim-btn');
        var verifyBtn  = document.getElementById('verify-btn');
        var resendBtn  = document.getElementById('resend-btn');
        var codeInput  = document.getElementById('otp-code');
        var claimError = document.getElementById('claim-error');
        var otpError   = document.getElementById('otp-error');

        function showError(el, msg) {
            el.textContent = msg;
            el.classList.remove('hidden');
        }
        function hideError(el) {
            el.classList.add('hidden');
        }
        function errorMessage(code) {
            var map = {
                'rate_limited': 'Too many attempts, please wait a few minutes and try again.',
                'already_claimed': 'This card has already been claimed.',
                'no_phone': 'No phone number on file for this card.',
                'invalid_token': 'This link is invalid or expired.',
                'delivery_failed': 'Could not send the code, please try again shortly.',
                'wrong_code': 'That code is not right, please try again.',
                'expired_or_missing': 'That code has expired, request a new one.',
                'too_many_attempts': 'Too many wrong attempts, request a new code.',
                'invalid_code_format': 'Enter the 6-digit code exactly as sent.'
            };
            return map[code] || 'Something went wrong, please try again.';
        }

        function post(action, extra) {
            var body = Object.assign({ token: token, action: action }, extra || {});
            return fetch(API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            }).then(function (r) { return r.json(); });
        }

        function sendCode(btn) {
            btn.disabled = true;
            hideError(claimError);
            hideError(otpError);
            post('send').then(function (data) {
                btn.disabled = false;
                if (!data.success) {
                    showError(introEl.classList.contains('hidden') ? otpError : claimError, errorMessage(data.error));
                    return;
                }
                introEl.classList.add('hidden');
                otpEl.classList.remove('hidden');
                codeInput.focus();
            }).catch(function () {
                btn.disabled = false;
                showError(claimError, 'Network error, please try again.');
            });
        }

        claimBtn.addEventListener('click', function () { sendCode(claimBtn); });
        resendBtn.addEventListener('click', function () { sendCode(resendBtn); });

        verifyBtn.addEventListener('click', function () {
            var code = (codeInput.value || '').trim();
            if (!/^\d{6}$/.test(code)) {
                showError(otpError, 'Enter the 6-digit code.');
                return;
            }
            verifyBtn.disabled = true;
            hideError(otpError);
            post('verify', { code: code }).then(function (data) {
                verifyBtn.disabled = false;
                if (!data.success) {
                    showError(otpError, errorMessage(data.error));
                    return;
                }
                window.location.href = data.redirect || '/company/register.php';
            }).catch(function () {
                verifyBtn.disabled = false;
                showError(otpError, 'Network error, please try again.');
            });
        });
    })();
    </script>
<?php endif; ?>
</body>
</html>
<?php

/**
 * Mask a phone number for public display: keeps a short leading prefix
 * (country code-ish) and the last 2 digits, masks the rest. Never render
 * the full number on this page, ownership is proven by the OTP flow, not
 * by recognizing the number.
 */
function maskClaimPhone(string $e164): string {
    $digits = preg_replace('/\D/', '', $e164);
    $len = strlen($digits);
    if ($len === 0) return '';
    if ($len <= 4) return str_repeat('x', $len);
    $headLen = (int) min(4, $len - 2);
    $head = substr($digits, 0, $headLen);
    $tail = substr($digits, -2);
    $maskLen = $len - $headLen - 2;
    return '+' . $head . ' ' . str_repeat('x', max($maskLen, 0)) . $tail;
}

/**
 * Minimal inline error renderer, mirrors claim-lead.php's renderClaimError
 * so this page stays resilient even if the includes tree shifts.
 */
function renderClaimCardError($titleEn, $detailEn, $titleAr, $detailAr) {
    http_response_code(http_response_code() >= 400 ? http_response_code() : 400);
    ?><!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= htmlspecialchars($titleEn, ENT_QUOTES) ?>, Cardify</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="preconnect" href="https://fonts.bhd.om">
<link rel="preconnect" href="https://fonts.bhd.om" crossorigin>
<link href="https://fonts.bhd.om/css2?family=IBM+Plex+Sans+Arabic:wght@400;600;700&display=swap" rel="stylesheet">
<style>
body{font-family:system-ui,-apple-system,'Segoe UI',sans-serif;background:#f5f7fb;}
[lang="ar"]{font-family:'IBM Plex Sans Arabic',system-ui,sans-serif;letter-spacing:0 !important;}
</style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
    <div class="max-w-md w-full bg-white rounded-2xl shadow-sm border border-gray-100 p-8 text-center">
        <div class="w-12 h-12 mx-auto bg-amber-50 rounded-full flex items-center justify-center mb-4">
            <span class="text-amber-500 text-2xl">!</span>
        </div>
        <h1 class="text-lg font-bold text-gray-900"><?= htmlspecialchars($titleEn, ENT_QUOTES) ?></h1>
        <h1 lang="ar" dir="rtl" class="text-base font-semibold text-gray-700 mt-1"><?= htmlspecialchars($titleAr, ENT_QUOTES) ?></h1>
        <p class="text-sm text-gray-500 mt-3"><?= htmlspecialchars($detailEn, ENT_QUOTES) ?></p>
        <p lang="ar" dir="rtl" class="text-sm text-gray-400 mt-1"><?= htmlspecialchars($detailAr, ENT_QUOTES) ?></p>
        <a href="/" class="inline-block mt-5 text-sm font-medium text-purple-600 hover:underline">Go to Cardify <span lang="ar" dir="rtl">&middot; الانتقال إلى كارديفاي</span></a>
    </div>
</body>
</html><?php
}

/**
 * Opt-out confirmation screen (GET, no mutation). One extra click on this
 * page performs the actual POST, so a WhatsApp/email link-preview crawler
 * fetching the raw ?optout=1 URL cannot silently opt someone out.
 */
function renderOptOutConfirm(string $token, string $csrf) {
    $action = 'claim-card.php?t=' . urlencode($token) . '&optout=1';
    ?><!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Opt out, Cardify</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="preconnect" href="https://fonts.bhd.om">
<link rel="preconnect" href="https://fonts.bhd.om" crossorigin>
<link href="https://fonts.bhd.om/css2?family=IBM+Plex+Sans+Arabic:wght@400;600;700&display=swap" rel="stylesheet">
<style>
body{font-family:system-ui,-apple-system,'Segoe UI',sans-serif;background:#f5f7fb;}
[lang="ar"]{font-family:'IBM Plex Sans Arabic',system-ui,sans-serif;letter-spacing:0 !important;}
</style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
    <div class="max-w-md w-full bg-white rounded-2xl shadow-sm border border-gray-100 p-8 text-center">
        <h1 class="text-lg font-bold text-gray-900">Stop messages from Cardify?</h1>
        <h1 lang="ar" dir="rtl" class="text-base font-semibold text-gray-700 mt-1">إيقاف رسائل كارديفاي؟</h1>
        <p class="text-sm text-gray-500 mt-3">You will not receive any more WhatsApp messages about this card. This cannot be undone.</p>
        <p lang="ar" dir="rtl" class="text-sm text-gray-400 mt-1">لن تصلك رسائل واتساب بخصوص هذه البطاقة بعد الآن. لا يمكن التراجع عن هذا الإجراء.</p>
        <form method="POST" action="<?= htmlspecialchars($action, ENT_QUOTES) ?>" class="mt-5">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
            <button type="submit" class="w-full bg-red-600 text-white font-semibold py-3 rounded-xl shadow hover:bg-red-700 transition">
                Yes, opt me out <span lang="ar" dir="rtl">&middot; نعم، أوقف الرسائل</span>
            </button>
        </form>
        <a href="/" class="inline-block mt-4 text-sm font-medium text-gray-400 hover:underline">Cancel <span lang="ar" dir="rtl">&middot; إلغاء</span></a>
    </div>
</body>
</html><?php
}

/** Opt-out done screen, also shown when the profile was already opted out. */
function renderOptOutDone() {
    ?><!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Opted out, Cardify</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="preconnect" href="https://fonts.bhd.om">
<link rel="preconnect" href="https://fonts.bhd.om" crossorigin>
<link href="https://fonts.bhd.om/css2?family=IBM+Plex+Sans+Arabic:wght@400;600;700&display=swap" rel="stylesheet">
<style>
body{font-family:system-ui,-apple-system,'Segoe UI',sans-serif;background:#f5f7fb;}
[lang="ar"]{font-family:'IBM Plex Sans Arabic',system-ui,sans-serif;letter-spacing:0 !important;}
</style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
    <div class="max-w-md w-full bg-white rounded-2xl shadow-sm border border-gray-100 p-8 text-center">
        <div class="w-12 h-12 mx-auto bg-gray-100 rounded-full flex items-center justify-center mb-4">
            <i class="fa-solid fa-bell-slash text-gray-500"></i>
        </div>
        <h1 class="text-lg font-bold text-gray-900">You will not receive any more messages from Cardify.</h1>
        <h1 lang="ar" dir="rtl" class="text-base font-semibold text-gray-700 mt-2">تم إيقاف الرسائل نهائياً.</h1>
        <a href="/" class="inline-block mt-5 text-sm font-medium text-purple-600 hover:underline">Go to Cardify <span lang="ar" dir="rtl">&middot; الانتقال إلى كارديفاي</span></a>
    </div>
    <link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/fontawesome.min.css?v=7.2.0">
    <link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/solid.min.css?v=7.2.0">
</body>
</html><?php
}
