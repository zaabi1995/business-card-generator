<?php
/**
 * Tenant OTP login. Single-page two-step flow.
 *  Step 1: identifier (email or phone) -> sends OTP via email or WhatsApp
 *  Step 2: 6-digit code -> Auth::loginUser() and redirect to /admin/
 *
 * Only reachable when the request host is a tenant subdomain
 * ({slug}.cardify.om). Renders branded with the tenant company.
 */

require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/WhatsApp.php';
require_once INCLUDES_DIR . '/Mailer.php';
require_once INCLUDES_DIR . '/OtpService.php';
require_once INCLUDES_DIR . '/TenantHost.php';
require_once INCLUDES_DIR . '/OgImage.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Defeat any intermediate caching: the form/verify pages must always be fresh.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$tenant = TenantHost::resolve();
if (!$tenant) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

$companyId   = $tenant['id'];
$companyName = $tenant['name'] ?? 'Cardify';
$companySlug = $tenant['slug'];

if (Auth::isLoggedIn()) {
    $sessionCompany = $_SESSION['user_company_id'] ?? $_SESSION['company_id'] ?? null;
    if ($sessionCompany === $companyId) {
        header('Location: /admin/');
        exit;
    }
    Auth::logout();
}

function tl_normalize_phone(string $raw): string {
    $digits = preg_replace('/\D+/', '', $raw);
    if ($digits === '') return '';
    // 8-digit Omani mobiles start with 7, 8, or 9. Prepend country code 968.
    if (strlen($digits) === 8 && in_array($digits[0], ['7', '8', '9'], true)) {
        $digits = '968' . $digits;
    }
    return $digits;
}

function tl_lookup_user(string $identifier, string $companyId): ?array {
    $db = Database::getInstance();
    if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
        return $db->fetchOne(
            "SELECT * FROM users WHERE LOWER(email) = LOWER(:e) AND company_id = :c LIMIT 1",
            ['e' => $identifier, 'c' => $companyId]
        ) ?: null;
    }
    $phone = tl_normalize_phone($identifier);
    if ($phone === '') return null;
    return $db->fetchOne(
        "SELECT * FROM users WHERE REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', '') = :p AND company_id = :c LIMIT 1",
        ['p' => $phone, 'c' => $companyId]
    ) ?: null;
}

/**
 * Atomic read-modify-write on a JSON rate-limit bucket file. Holds an
 * EXCLUSIVE lock across the whole read -> decide -> write so two concurrent
 * requests can't both read a stale count and each slip past the cap (the
 * TOCTOU the previous LOCK_EX-on-write-only pattern allowed). $mutator gets
 * the decoded array (or []) and must return [newStateArray, returnValue];
 * tl_bucket_rmw returns returnValue. Fails open (still runs $mutator once) if
 * the bucket file can't be opened.
 */
function tl_bucket_rmw(string $file, callable $mutator) {
    $fh = @fopen($file, 'c+');
    if ($fh === false) {
        [, $result] = $mutator([]);
        return $result;
    }
    try {
        @flock($fh, LOCK_EX);
        $raw   = stream_get_contents($fh);
        $state = (is_string($raw) && $raw !== '') ? json_decode($raw, true) : null;
        if (!is_array($state)) $state = [];
        [$newState, $result] = $mutator($state);
        rewind($fh);
        ftruncate($fh, 0);
        fwrite($fh, json_encode($newState));
        fflush($fh);
        return $result;
    } finally {
        @flock($fh, LOCK_UN);
        @fclose($fh);
    }
}

/**
 * Take one OTP send slot for an identifier. Token bucket, per OWASP Forgot
 * Password guidance: throttle abuse without ever locking a legitimate user
 * out for a fixed window. Burst of TL_OTP_BURST sends, then one new send
 * refills every TL_OTP_REFILL_SECS, with TL_OTP_COOLDOWN_SECS between
 * consecutive sends. Cooldown is 45s while the UI countdown shows 50s, so
 * the resend button can never race the server into a "please wait" error.
 */
const TL_OTP_BURST = 5;
const TL_OTP_REFILL_SECS = 600;
const TL_OTP_COOLDOWN_SECS = 45;
function tl_otp_take_slot(string $deliveryId): array {
    $file = '/tmp/cardify-otp-rate-' . hash('sha256', $deliveryId);
    $now = time();
    return tl_bucket_rmw($file, function ($d) use ($now) {
        if (!isset($d['tokens'], $d['tokens_at'])) {
            // Fresh bucket, or migration from the old fixed-window shape.
            $d['tokens']    = TL_OTP_BURST;
            $d['tokens_at'] = $now;
        }
        $d['tokens']    = min(TL_OTP_BURST, (float) $d['tokens'] + ($now - (int) $d['tokens_at']) / TL_OTP_REFILL_SECS);
        $d['tokens_at'] = $now;
        $cooldown = TL_OTP_COOLDOWN_SECS - ($now - (int) ($d['last'] ?? 0));
        if ($cooldown > 0) {
            return [$d, ['status' => 'cooldown', 'cooldown' => $cooldown]];
        }
        if ($d['tokens'] < 1) {
            return [$d, ['status' => 'drained', 'secs' => (int) ceil((1 - $d['tokens']) * TL_OTP_REFILL_SECS)]];
        }
        $d['tokens'] -= 1;
        $d['last']    = $now;
        unset($d['send_fail'], $d['window_start'], $d['count']); // fresh attempt + drop legacy keys
        return [$d, ['status' => 'ok']];
    });
}

// PRG state: when a previous POST set up an OTP send, force the verify step
// on the subsequent GET so the page visibly transitions and the back/forward
// buttons don't replay the form submit.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_SESSION['tlogin_pending_verify'])) {
    $_SESSION['tlogin_pending_verify'] = false;
    $step = 'verify';
} else {
    $step = $_POST['step'] ?? 'request';
}
$error = null;
$notice = $_SESSION['tlogin_notice'] ?? null;
unset($_SESSION['tlogin_notice']);
// Honest delivery feedback: the OTP send runs in the background after the
// redirect, so a failure can't be shown synchronously. The send path stamps
// 'send_fail' into the rate bucket; surface it on the next render instead of
// letting the green 'Code sent' notice stand unchallenged.
if ($step === 'verify' && $_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_SESSION['tlogin_identifier'])) {
    $dlvBucket = @json_decode((string) @file_get_contents('/tmp/cardify-otp-rate-' . hash('sha256', $_SESSION['tlogin_identifier'])), true);
    if (is_array($dlvBucket) && !empty($dlvBucket['send_fail']) && time() - (int) $dlvBucket['send_fail'] < 600) {
        $error  = t('auth.tenant_err_delivery');
        $notice = null;
    }
}
$identifier = trim($_POST['identifier'] ?? $_SESSION['tlogin_identifier'] ?? '');
$channel = null;
$displayIdentifier = $identifier;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'request') {
    if ($identifier === '') {
        $error = t('auth.tenant_err_enter_id');
    } else {
        $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false;
        $channel = $isEmail ? 'email' : 'whatsapp';
        $deliveryId = $isEmail ? strtolower($identifier) : tl_normalize_phone($identifier);
        if (!$isEmail && strlen($deliveryId) < 8) {
            $error = t('auth.tenant_err_phone_invalid');
        } else {
            // IP-based pre-lookup rate limit (anti-enumeration). Without this,
            // an attacker can probe arbitrary identifiers without ever
            // tripping the per-identifier limit (which only fires when an
            // account actually exists).
            $clientIp = $_SERVER['HTTP_CF_CONNECTING_IP']
                ?? trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0])
                ?: ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            $ipBucketFile = '/tmp/cardify-otp-ip-' . hash('sha256', $clientIp);
            $now = time();
            // Atomic: count this probe and learn whether the IP was already
            // over 20/hour, all under one exclusive lock (no concurrent slip).
            $ipCapped = tl_bucket_rmw($ipBucketFile, function ($b) use ($now) {
                if (!isset($b['window_start'], $b['count']) || $now - (int) $b['window_start'] > 3600) {
                    $b = ['window_start' => $now, 'count' => 0];
                }
                $capped = ((int) $b['count'] >= 20);
                $b['count'] = (int) $b['count'] + 1; // count every probe, capped or not
                return [$b, $capped];
            });
            if ($ipCapped) {
                // Quietly cap probing per IP at 20/hour. Use the same generic
                // success-page redirect as a real send, so the attacker can't
                // tell whether they hit the cap, the account exists, or the
                // OTP was actually sent.
                $error = '';
                $user = false;
            } else {
                $user = tl_lookup_user($identifier, $companyId);
                $isRealUser = (bool) $user;
                // Per-identifier token bucket: burst of 5, +1 send every 10
                // min, 45s between sends. The slot is consumed for real AND
                // unknown identifiers alike: a uniform cooldown leaks nothing
                // about account existence (OWASP: keep messages consistent for
                // existent/non-existent accounts), while an explicit "wait Ns"
                // beats silently pretending the code was sent (which is how a
                // wedged Dardasha hid behind a fake 'Code sent' page on
                // 9-10 Jun 2026).
                $rateOutcome = tl_otp_take_slot($deliveryId);
                if (($rateOutcome['status'] ?? '') === 'cooldown') {
                    $error = t('auth.tenant_resend_wait', ['s' => $rateOutcome['cooldown']]);
                } elseif (($rateOutcome['status'] ?? '') === 'drained') {
                    $error = t('auth.tenant_err_hourly_limit', ['s' => max(30, $rateOutcome['secs'])]);
                }
                unset($isRealUser);
            }
            if ($error === null || $error === '') {
            // Always show the SAME post-submit page regardless of whether the
            // identifier resolves to a real user. Otherwise the error message
            // divergence (account exists / doesn't exist) is an account-
            // enumeration vector. Real users receive the OTP via WhatsApp/email
            // shortly after; attackers see the same page either way and only
            // learn the result on the verify step.
            $sendActual = (bool) $user;
            if (!$user) {
                $user = ['id' => '__noop__'];  // placeholder so the redirect-out branch fires
            }
            {
                // (The per-identifier slot was already consumed atomically in
                // the tl_bucket_rmw above when $sendActual is true.)

                $purpose = 'tlogin:' . substr(hash('sha1', $companyId), 0, 12);
                // Optimistic UX: stash session, issue the 302 immediately,
                // then call OtpService::send AFTER the response is flushed.
                // The user sees the "Enter code" page in <300ms while the
                // 5-10s Dardasha round-trip happens in the background.
                $_SESSION['tlogin_identifier']     = $deliveryId;
                $_SESSION['tlogin_channel']        = $channel;
                $_SESSION['tlogin_user_id']        = $user['id'];
                $_SESSION['tlogin_purpose']        = $purpose;
                $_SESSION['tlogin_pending_verify'] = true;
                $_SESSION['tlogin_notice']         = $channel === 'email'
                    ? t('auth.tenant_notice_email')
                    : t('auth.tenant_notice_wa');
                session_write_close();
                header('Location: /login');
                header('Content-Length: 0');
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                } else {
                    @ob_end_flush(); flush();
                }
                ignore_user_abort(true);
                if ($sendActual) {
                    $res = OtpService::send($deliveryId, $channel, $purpose);
                    // Record the outcome in the rate bucket so the verify page
                    // can stop claiming 'Code sent' when delivery actually
                    // failed (the user is told to resend or switch channel).
                    tl_bucket_rmw('/tmp/cardify-otp-rate-' . hash('sha256', $deliveryId), function ($d) use ($res) {
                        if (empty($res['ok'])) { $d['send_fail'] = time(); } else { unset($d['send_fail']); }
                        return [$d, null];
                    });
                    if (empty($res['ok'])) {
                        error_log('[tenant_login] background OTP send failed for ' . substr($deliveryId, 0, 4) . '**** ch=' . $channel . ': ' . ($res['error'] ?? 'unknown'));
                    }
                } else {
                    // No-op: identifier did not resolve to a real user, OR per-IP
                    // /per-identifier rate limit tripped. We already showed the
                    // generic 'code sent' page via the redirect above; do not
                    // burn SMS/WhatsApp budget on probing.
                    error_log('[tenant_login] no-op send for ' . substr(hash('sha256', $deliveryId), 0, 12) . ' (anti-enumeration)');
                }
                exit;
            }
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'resend') {
    // Resend a code to the identifier already stashed in the session, without
    // making the user re-type it. Stays on the verify (enter-code) screen.
    $deliveryId = $_SESSION['tlogin_identifier'] ?? '';
    $channel    = $_SESSION['tlogin_channel'] ?? 'whatsapp';
    $purpose    = $_SESSION['tlogin_purpose'] ?? '';
    $userId     = $_SESSION['tlogin_user_id'] ?? '';
    $displayIdentifier = $deliveryId;
    $step = 'verify';
    if ($deliveryId === '' || $purpose === '' || $userId === '') {
        $step = 'request';
        $error = t('auth.tenant_err_session');
    } else {
        // Same token bucket as the request step (burst 5, +1 per 10 min,
        // 45s between sends), atomic under one lock.
        $rateBucket = '/tmp/cardify-otp-rate-' . hash('sha256', $deliveryId);
        $rateOutcome = tl_otp_take_slot($deliveryId);
        if (($rateOutcome['status'] ?? '') === 'cooldown') {
            $error = t('auth.tenant_resend_wait', ['s' => $rateOutcome['cooldown']]);
        } elseif (($rateOutcome['status'] ?? '') === 'drained') {
            $error = t('auth.tenant_err_hourly_limit', ['s' => max(30, $rateOutcome['secs'])]);
        } else {
            $_SESSION['tlogin_pending_verify'] = true;
            $_SESSION['tlogin_notice'] = $channel === 'email'
                ? t('auth.tenant_notice_email')
                : t('auth.tenant_notice_wa');
            session_write_close();
            header('Location: /login');
            header('Content-Length: 0');
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } else {
                @ob_end_flush(); flush();
            }
            ignore_user_abort(true);
            $res = OtpService::send($deliveryId, $channel, $purpose);
            tl_bucket_rmw($rateBucket, function ($d) use ($res) {
                if (empty($res['ok'])) { $d['send_fail'] = time(); } else { unset($d['send_fail']); }
                return [$d, null];
            });
            if (empty($res['ok'])) {
                error_log('[tenant_login] background OTP resend failed for ' . substr($deliveryId, 0, 4) . '**** ch=' . $channel . ': ' . ($res['error'] ?? 'unknown'));
            }
            exit;
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'verify') {
    $code = trim($_POST['code'] ?? '');
    $deliveryId = $_SESSION['tlogin_identifier'] ?? '';
    $purpose = $_SESSION['tlogin_purpose'] ?? '';
    $userId = $_SESSION['tlogin_user_id'] ?? '';
    $displayIdentifier = $deliveryId;
    if ($deliveryId === '' || $purpose === '' || $userId === '') {
        $step = 'request';
        $error = t('auth.tenant_err_session');
    } elseif (!preg_match('/^\d{6}$/', $code)) {
        $error = t('auth.tenant_err_code_format');
    } else {
        $res = OtpService::verify($deliveryId, $code, $purpose);
        if (!empty($res['ok'])) {
            $db = Database::getInstance();
            $user = $db->fetchOne("SELECT * FROM users WHERE id = :id LIMIT 1", ['id' => $userId]);
            if (!$user || $user['company_id'] !== $companyId) {
                $error = t('auth.tenant_err_account_404');
                $step = 'request';
            } else {
                unset($_SESSION['tlogin_identifier'], $_SESSION['tlogin_channel'], $_SESSION['tlogin_user_id'], $_SESSION['tlogin_purpose']);
                Auth::loginUser($user);
                header('Location: /admin/');
                exit;
            }
        } else {
            $err = $res['error'] ?? 'invalid';
            $error = match ($err) {
                'expired_or_missing' => t('auth.tenant_err_code_expired'),
                'too_many_attempts'  => t('auth.tenant_err_too_many'),
                default              => t('auth.tenant_err_code_invalid'),
            };
        }
    }
}

$logoUrl = null;
$brandPrimary = '#009bc1';
$brandSecondary = '#0f172a';
if (!empty($tenant['id'])) {
    // Prefer SVG (sharp at every size) over PNG.
    foreach (['logo.svg', 'logo.png', 'logo.jpg'] as $_f) {
        $candidate = '/uploads/companies/' . $tenant['id'] . '/' . $_f;
        if (is_file(__DIR__ . $candidate)) { $logoUrl = $candidate; break; }
    }
    try {
        $theme = Database::getInstance()->fetchOne(
            'SELECT primary_color, secondary_color, logo_path FROM company_themes WHERE company_id = :id LIMIT 1',
            ['id' => $tenant['id']]
        );
        if ($theme) {
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $theme['primary_color'] ?? '')) {
                $brandPrimary = $theme['primary_color'];
            }
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $theme['secondary_color'] ?? '')) {
                $brandSecondary = $theme['secondary_color'];
            }
            if (!$logoUrl && !empty($theme['logo_path']) && is_file(__DIR__ . $theme['logo_path'])) {
                $logoUrl = $theme['logo_path'];
            }
        }
    } catch (Throwable $_) { /* company_themes may not exist on legacy installs */ }
}
// Compute a semi-transparent focus-ring colour from the brand primary.
$brandRing = $brandPrimary . '26'; // ~15% alpha (8-digit hex)

// Pick legible button text: white on dark brand, near-black on light brand.
// Standard relative-luminance (sRGB) threshold of 0.6.
$_hex = ltrim($brandPrimary, '#');
$_r = hexdec(substr($_hex, 0, 2)) / 255;
$_g = hexdec(substr($_hex, 2, 2)) / 255;
$_b = hexdec(substr($_hex, 4, 2)) / 255;
$_lum = 0.2126 * $_r + 0.7152 * $_g + 0.0722 * $_b;
$brandText = $_lum > 0.6 ? '#0f172a' : '#ffffff';

$locale      = currentLocale();
$dir         = isRtl() ? 'rtl' : 'ltr';
$otherLocale = $locale === 'ar' ? 'en' : 'ar';
$switchUrl   = '/login?lang=' . $otherLocale;
?><!doctype html>
<html lang="<?= htmlspecialchars($locale) ?>" dir="<?= $dir ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($companyName) ?> · Cardify</title>
<?php
$__loginTheme = class_exists('TenantHost') ? TenantHost::theme() : null;
if (!empty($__loginTheme['favicon'])):
    $__favType = preg_match('/\.svg(\?|$)/i', $__loginTheme['favicon']) ? 'image/svg+xml'
                : (preg_match('/\.png(\?|$)/i', $__loginTheme['favicon']) ? 'image/png' : 'image/png');
?>
<link rel="icon" href="<?= htmlspecialchars($__loginTheme['favicon'], ENT_QUOTES) ?>" type="<?= $__favType ?>">
<link rel="apple-touch-icon" href="<?= htmlspecialchars($__loginTheme['favicon'], ENT_QUOTES) ?>">
<?php else: ?>
<link rel="icon" href="/favicon.svg">
<?php endif; ?>
<?php
    $__ogImage = OgImage::url($tenant, ['variant' => 'login', 'locale' => $locale]);
    $__ogDesc  = ($locale === 'ar')
        ? ('سجّل الدخول إلى بوابة ' . $companyName . ' على Cardify.')
        : ('Sign in to the ' . $companyName . ' portal on Cardify.');
    $__ogScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $__ogUrl = $__ogScheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'cardify.om') . '/login';
?>
<meta name="description" content="<?= htmlspecialchars($__ogDesc) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= htmlspecialchars($companyName . ' · Cardify') ?>">
<meta property="og:description" content="<?= htmlspecialchars($__ogDesc) ?>">
<meta property="og:url" content="<?= htmlspecialchars($__ogUrl) ?>">
<meta property="og:site_name" content="<?= htmlspecialchars($companyName . ' · Cardify') ?>">
<meta property="og:image" content="<?= htmlspecialchars($__ogImage) ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="<?= htmlspecialchars($companyName . ' sign-in') ?>">
<meta property="og:locale" content="<?= $locale === 'ar' ? 'ar_OM' : 'en_US' ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= htmlspecialchars($companyName . ' · Cardify') ?>">
<meta name="twitter:description" content="<?= htmlspecialchars($__ogDesc) ?>">
<meta name="twitter:image" content="<?= htmlspecialchars($__ogImage) ?>">
<?php $detectedPhone = $step === 'request' && $identifier !== '' && !filter_var($identifier, FILTER_VALIDATE_EMAIL); ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@24.6.0/build/css/intlTelInput.min.css">
<style>
:root { --brand: <?= htmlspecialchars($brandPrimary) ?>; --brand-text: <?= htmlspecialchars($brandText) ?>; --brand-ring: <?= htmlspecialchars($brandRing) ?>; --brand-secondary: <?= htmlspecialchars($brandSecondary) ?>; --bg: #f8fafc; --ink: #0f172a; --muted: #64748b; --line: #e2e8f0; }
*{box-sizing:border-box} body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:var(--bg);color:var(--ink);min-height:100vh;display:grid;place-items:center;padding:24px}
.card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:32px;width:100%;max-width:420px;box-shadow:0 8px 24px rgba(15,23,42,.06)}
.logo{display:flex;align-items:center;gap:12px;margin-bottom:24px}
.logo img{width:48px;height:48px;border-radius:10px;object-fit:contain;background:#fff;border:1px solid var(--line)}
.logo .badge{width:48px;height:48px;border-radius:10px;background:var(--brand);color:var(--brand-text);display:grid;place-items:center;font-weight:700;font-size:18px}
h1{font-size:20px;margin:0 0 4px;font-weight:700} .sub{color:var(--muted);font-size:14px;margin:0 0 20px}
label{display:block;font-size:13px;font-weight:600;margin:14px 0 6px}
input[type=email],input[type=tel],input[type=text]{width:100%;padding:12px 14px;border:1px solid var(--line);border-radius:10px;font-size:15px;outline:none;transition:border .15s}
input:focus{border-color:var(--brand);box-shadow:0 0 0 3px var(--brand-ring)}
.code{letter-spacing:8px;text-align:center;font-size:22px;font-family:ui-monospace,monospace}
button{width:100%;margin-top:16px;padding:12px 14px;background:var(--brand);color:var(--brand-text);border:none;border-radius:10px;font-size:15px;font-weight:600;cursor:pointer}
button:hover{filter:brightness(0.95)} button.ghost{background:transparent;color:var(--muted);font-weight:500;margin-top:8px}
button[disabled]{opacity:.7;cursor:wait}
.spinner{display:inline-block;width:14px;height:14px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:sp .7s linear infinite;vertical-align:-2px;margin-inline-end:8px;opacity:.85}
@keyframes sp{to{transform:rotate(360deg)}}
.error{background:#fef2f2;color:#b91c1c;padding:10px 12px;border-radius:8px;font-size:13px;margin-bottom:8px}
.notice{background:#ecfdf5;color:#065f46;padding:10px 12px;border-radius:8px;font-size:13px;margin-bottom:8px}
.foot{margin-top:24px;font-size:12px;color:var(--muted);text-align:center}
.foot a{color:var(--brand);text-decoration:none}
.tabs{display:grid;grid-template-columns:1fr 1fr;background:#f1f5f9;border-radius:10px;padding:4px;gap:4px;margin-bottom:4px}
.tabs button{margin:0;padding:8px 12px;background:transparent;color:var(--muted);font-size:14px;border-radius:7px;transition:all .15s}
.tabs button[aria-selected=true]{background:#fff;color:var(--ink);box-shadow:0 1px 2px rgba(15,23,42,.06)}
.pane{display:none} .pane[data-active=true]{display:block}
.iti{width:100%} .iti__tel-input{width:100%}
.iti__country-list{font-size:14px}
.field-err{color:#b91c1c;font-size:12px;margin-top:6px;min-height:16px}
.lang-switch{position:absolute;top:16px;<?= $dir === 'rtl' ? 'left' : 'right' ?>:16px;background:#fff;border:1px solid var(--line);border-radius:999px;padding:6px 14px;font-size:13px;color:var(--muted);text-decoration:none;font-weight:500}
.lang-switch:hover{color:var(--ink);border-color:var(--muted)}
html[dir=rtl] .code{letter-spacing:8px;direction:ltr}
.phone-wrap{direction:ltr}
.phone-wrap .iti,.phone-wrap .iti *{direction:ltr !important;text-align:left}
.phone-wrap input[type=tel]{direction:ltr !important;text-align:left;unicode-bidi:plaintext}
</style>
</head>
<body>
<a href="<?= htmlspecialchars($switchUrl) ?>" class="lang-switch" rel="nofollow"><?= htmlspecialchars(t('auth.tenant_lang_switch')) ?></a>
<form class="card" method="post" novalidate>
  <div class="logo">
    <?php if ($logoUrl): ?>
      <img src="<?= htmlspecialchars($logoUrl) ?>" alt="<?= htmlspecialchars($companyName) ?>">
    <?php else: ?>
      <div class="badge"><?= htmlspecialchars(strtoupper(substr($companyName, 0, 1))) ?></div>
    <?php endif; ?>
    <div>
      <div style="font-weight:700;font-size:16px"><?= htmlspecialchars($companyName) ?></div>
      <div style="font-size:12px;color:var(--muted)"><?= htmlspecialchars(t('auth.tenant_powered_by')) ?></div>
    </div>
  </div>

  <?php if ($step === 'request'): ?>
    <h1><?= htmlspecialchars(t('auth.sign_in')) ?></h1>
    <p class="sub"><?= htmlspecialchars(t('auth.tenant_subtitle')) ?></p>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <input type="hidden" name="step" value="request">
    <input type="hidden" name="identifier" id="identifier-hidden" value="">

    <div class="tabs" role="tablist" aria-label="<?= htmlspecialchars(t('auth.email_or_phone')) ?>">
      <button type="button" id="tab-phone" role="tab" aria-selected="<?= $detectedPhone || !$identifier ? 'true' : 'false' ?>" aria-controls="pane-phone"><?= htmlspecialchars(t('auth.phone')) ?></button>
      <button type="button" id="tab-email" role="tab" aria-selected="<?= $detectedPhone || !$identifier ? 'false' : 'true' ?>" aria-controls="pane-email"><?= htmlspecialchars(t('auth.email')) ?></button>
    </div>

    <div id="pane-phone" class="pane" data-active="<?= $detectedPhone || !$identifier ? 'true' : 'false' ?>" role="tabpanel" aria-labelledby="tab-phone">
      <label for="phone"><?= htmlspecialchars(t('auth.tenant_phone_label')) ?></label>
      <div dir="ltr" class="phone-wrap">
        <input type="tel" id="phone" dir="ltr" autocomplete="tel" value="<?= htmlspecialchars($detectedPhone ? $identifier : '') ?>">
      </div>
      <div class="field-err" id="phone-err" role="alert"></div>
    </div>

    <div id="pane-email" class="pane" data-active="<?= $detectedPhone || !$identifier ? 'false' : 'true' ?>" role="tabpanel" aria-labelledby="tab-email">
      <label for="email"><?= htmlspecialchars(t('auth.tenant_email_label')) ?></label>
      <input type="email" id="email" autocomplete="email" value="<?= htmlspecialchars(!$detectedPhone ? $identifier : '') ?>" placeholder="<?= htmlspecialchars(t('auth.tenant_email_placeholder')) ?>">
    </div>

    <button type="submit" id="submit-btn"><?= htmlspecialchars(t('auth.tenant_send_code')) ?></button>
  <?php else: ?>
    <h1><?= htmlspecialchars(t('auth.tenant_enter_code_h1')) ?></h1>
    <p class="sub"><?= str_replace(':ident', '<strong>' . htmlspecialchars($displayIdentifier) . '</strong>', htmlspecialchars(t('auth.tenant_enter_code_sub', ['ident' => ':ident']))) ?></p>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($notice): ?><div class="notice"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
    <input type="hidden" name="step" value="verify">
    <label for="code"><?= htmlspecialchars(t('auth.tenant_code_label')) ?></label>
    <input type="text" name="code" id="code" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" class="code" required autofocus>
    <button type="submit"><?= htmlspecialchars(t('auth.tenant_verify_btn')) ?></button>
    <button type="submit" name="do" value="resend" formnovalidate class="ghost" id="resend-btn"
            data-label="<?= htmlspecialchars(t('auth.tenant_resend_btn')) ?>"
            data-wait="<?= htmlspecialchars(t('auth.tenant_resend_in', ['s' => ':s'])) ?>"><?= htmlspecialchars(t('auth.tenant_resend_btn')) ?></button>
    <a href="/login" style="text-decoration:none"><button type="button" class="ghost"><?= htmlspecialchars(t('auth.tenant_use_different')) ?></button></a>
  <?php endif; ?>

  <div class="foot">
    <?= str_replace(':email', '<a href="mailto:support@cardify.om">support@cardify.om</a>', htmlspecialchars(t('auth.tenant_support', ['email' => ':email']))) ?>
  </div>
</form>

<?php if ($step === 'request'): ?>
<script src="https://cdn.jsdelivr.net/npm/intl-tel-input@24.6.0/build/js/intlTelInput.min.js"></script>
<script>
(function () {
  const form       = document.querySelector('form.card');
  const hidden     = document.getElementById('identifier-hidden');
  const phoneInput = document.getElementById('phone');
  const emailInput = document.getElementById('email');
  const phonePane  = document.getElementById('pane-phone');
  const emailPane  = document.getElementById('pane-email');
  const phoneTab   = document.getElementById('tab-phone');
  const emailTab   = document.getElementById('tab-email');
  const phoneErr   = document.getElementById('phone-err');

  let iti = null;
  try {
    iti = window.intlTelInput(phoneInput, {
      initialCountry: "om",
      preferredCountries: ["om","ae","sa","qa","bh","kw","eg","jo","lb","iq"],
      separateDialCode: true,
      formatAsYouType: true,
      utilsScript: "https://cdn.jsdelivr.net/npm/intl-tel-input@24.6.0/build/js/utils.js",
    });
  } catch (err) {
    console.error('intl-tel-input init failed:', err);
  }

  function activate(which) {
    const isPhone = which === 'phone';
    phonePane.dataset.active = isPhone ? 'true' : 'false';
    emailPane.dataset.active = isPhone ? 'false' : 'true';
    phoneTab.setAttribute('aria-selected', isPhone ? 'true' : 'false');
    emailTab.setAttribute('aria-selected', isPhone ? 'false' : 'true');
    if (phoneErr) phoneErr.textContent = '';
    setTimeout(() => { (isPhone ? phoneInput : emailInput).focus(); }, 50);
  }
  phoneTab.addEventListener('click', () => activate('phone'));
  emailTab.addEventListener('click', () => activate('email'));

  // Submit-handler logic: always succeed in producing a hidden identifier.
  // We do NOT call iti.isValidNumber() because the utils script can fail to
  // load or change shape across versions, and we already have a robust
  // server-side normalizer + per-tenant lookup.
  form.addEventListener('submit', function (e) {
    const usingPhone = phonePane.dataset.active === 'true';
    if (usingPhone) {
      let raw = '';
      // Try the library's E.164 number first; fall back to the raw input.
      try {
        if (iti && typeof iti.getNumber === 'function') {
          raw = iti.getNumber() || '';
        }
      } catch (_) { raw = ''; }
      if (!raw) raw = phoneInput.value || '';
      // Prepend the dial code if the user typed a local-only number AND the
      // library exposed the selected country, but the library left the +.
      const digits = raw.replace(/[^0-9]/g, '');
      if (!digits) {
        e.preventDefault();
        if (phoneErr) phoneErr.textContent = <?= json_encode(t('auth.tenant_err_enter_phone'), JSON_UNESCAPED_UNICODE) ?>;
        phoneInput.focus();
        return;
      }
      hidden.value = digits;
    } else {
      const v = (emailInput.value || '').trim();
      if (!v || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) {
        e.preventDefault();
        emailInput.focus();
        return;
      }
      hidden.value = v;
    }
    // Instant feedback so the 5-10s Dardasha round-trip can't be double-clicked.
    const btn = document.getElementById('submit-btn');
    if (btn) {
      btn.disabled = true;
      btn.setAttribute('aria-busy', 'true');
      const label = <?= json_encode(t('auth.tenant_send_code'), JSON_UNESCAPED_UNICODE) ?>;
      btn.innerHTML = '<span class="spinner" aria-hidden="true"></span>' + label;
    }
    if (phoneTab) phoneTab.disabled = true;
    if (emailTab) emailTab.disabled = true;
  });

  setTimeout(() => {
    (phonePane.dataset.active === 'true' ? phoneInput : emailInput).focus();
  }, 100);
})();
</script>
<?php endif; ?>
<?php if ($step === 'verify'): ?>
<script>
(function () {
  // 50s resend countdown over a 45s server cooldown: when the button enables,
  // the server is guaranteed to accept, never a "please wait" after clicking.
  var btn = document.getElementById('resend-btn');
  if (!btn) return;
  var label = btn.getAttribute('data-label');
  var waitTpl = btn.getAttribute('data-wait');
  var left = 50;
  function tick() {
    if (left <= 0) {
      btn.disabled = false;
      btn.textContent = label;
      return;
    }
    btn.disabled = true;
    btn.textContent = waitTpl.replace(':s', left);
    left -= 1;
    setTimeout(tick, 1000);
  }
  tick();
  btn.addEventListener('click', function () {
    // Show a spinner on the real submit so the background send isn't double-fired.
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner" aria-hidden="true"></span>' + label;
  });
})();
</script>
<?php endif; ?>
</body>
</html>
