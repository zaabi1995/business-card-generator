<?php
/**
 * Employee self-service door. Two steps, no password, no admin needed.
 *   Step 1: work email or mobile -> OTP by email or WhatsApp
 *   Step 2: 6-digit code -> mint an EmployeeEditToken -> the existing
 *           /portal/employee-edit page, where they edit their own card.
 *
 * Only reachable on a tenant subdomain ({slug}.cardify.om/my-card).
 * Routed by index.php. Anti-enumeration: the verify step always renders,
 * whether or not the identifier matched an employee.
 */

require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/SecurityHeaders.php';
require_once INCLUDES_DIR . '/WhatsApp.php';
require_once INCLUDES_DIR . '/Mailer.php';
require_once INCLUDES_DIR . '/OtpService.php';
require_once INCLUDES_DIR . '/TenantHost.php';
require_once INCLUDES_DIR . '/EmployeeEditToken.php';

if (class_exists('SecurityHeaders')) { SecurityHeaders::send(); }
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

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

const MYCARD_PURPOSE   = 'employee_self_edit';
const MYCARD_RESEND_S  = 60;   // seconds between sends
const MYCARD_HOURLY_MAX = 5;   // sends per identifier per hour

/** Read-modify-write the per-identifier send bucket (same shape as tenant_login). */
function mycard_bucket(string $file, callable $fn): array
{
    $data = @json_decode((string) @file_get_contents($file), true);
    if (!is_array($data)) { $data = ['last' => 0, 'window_start' => 0, 'count' => 0]; }
    $data = $fn($data);
    @file_put_contents($file, json_encode($data), LOCK_EX);
    return $data;
}
function mycard_bucket_path(string $id): string
{
    return '/tmp/cardify-otp-rate-' . hash('sha256', $id);
}

/** digits only, so +968 9232 7857 / 92327857 / 00968... all compare equal */
function mycard_digits(string $s): string
{
    return preg_replace('/\D+/', '', $s) ?? '';
}

/**
 * Find one active employee in THIS tenant by email or phone. Phone match is
 * on the last 8 digits so the country code and formatting never matter.
 * Returns null when ambiguous (more than one hit), never a guess.
 */
function mycard_find_employee(string $companyId, string $identifier): ?array
{
    $db = Database::getInstance();
    if (strpos($identifier, '@') !== false) {
        $row = $db->fetchOne(
            "SELECT id, email, name_en, mobile, phone FROM employees
              WHERE company_id = :cid AND LOWER(email) = :em
                AND deleted_at IS NULL AND status = 'active' LIMIT 1",
            ['cid' => $companyId, 'em' => strtolower($identifier)]
        );
        return $row ?: null;
    }

    $digits = mycard_digits($identifier);
    if (strlen($digits) < 8) { return null; }
    $tail = substr($digits, -8);
    $rows = $db->fetchAll(
        "SELECT id, email, name_en, mobile, phone FROM employees
          WHERE company_id = :cid AND deleted_at IS NULL AND status = 'active'
            AND (mobile <> '' OR phone <> '')",
        ['cid' => $companyId]
    );
    $hits = [];
    foreach ($rows as $r) {
        foreach ([$r['mobile'] ?? '', $r['phone'] ?? ''] as $cand) {
            $cd = mycard_digits((string) $cand);
            if ($cd !== '' && strlen($cd) >= 8 && substr($cd, -8) === $tail) {
                $hits[$r['id']] = $r;
                break;
            }
        }
    }
    // Two people sharing a number is a data problem, not a login. Refuse
    // rather than hand one person the other's card.
    return count($hits) === 1 ? array_values($hits)[0] : null;
}

$error  = null;
$step   = 'request';

// A GET straight after a send lands on the code screen (post/redirect/get).
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_SESSION['mycard_pending_verify'])) {
    $_SESSION['mycard_pending_verify'] = false;
    $step = 'verify';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $step = $_POST['step'] ?? 'request';
}

$notice = $_SESSION['mycard_notice'] ?? null;
unset($_SESSION['mycard_notice']);

// Seconds left on the resend timer, so the button matches the server.
$resendWait = 0;
if ($step === 'verify' && !empty($_SESSION['mycard_identifier'])) {
    $b = @json_decode((string) @file_get_contents(mycard_bucket_path($_SESSION['mycard_identifier'])), true);
    if (is_array($b) && !empty($b['last'])) {
        $resendWait = max(0, MYCARD_RESEND_S - (time() - (int) $b['last']));
    }
}

$identifier = trim($_POST['identifier'] ?? $_SESSION['mycard_identifier_raw'] ?? '');

/**
 * Remember who is verifying and move them to the code screen. Always runs,
 * even when the send itself is throttled: a throttled user still has a live
 * code in their inbox, so bouncing them back to step 1 would strand them.
 */
function mycard_begin_verify(string $deliveryId, string $channel, string $employeeId, string $rawIdentifier): void
{
    $_SESSION['mycard_identifier']     = $deliveryId;
    $_SESSION['mycard_identifier_raw'] = $rawIdentifier;
    $_SESSION['mycard_channel']        = $channel;
    $_SESSION['mycard_employee_id']    = $employeeId;
    $_SESSION['mycard_pending_verify'] = true;
    $_SESSION['mycard_notice']         = $channel === 'email'
        ? t('mycard.sent_email') : t('mycard.sent_whatsapp');
}

/** Claim a send slot. False when the 60s or hourly cap says no. */
function mycard_may_send(string $deliveryId): bool
{
    $now = time();
    $allowed = true;
    mycard_bucket(mycard_bucket_path($deliveryId), function ($d) use ($now, &$allowed) {
        if ($now - (int) ($d['last'] ?? 0) < MYCARD_RESEND_S) { $allowed = false; return $d; }
        if ($now - (int) ($d['window_start'] ?? 0) > 3600) { $d['window_start'] = $now; $d['count'] = 0; }
        if ((int) ($d['count'] ?? 0) >= MYCARD_HOURLY_MAX) { $allowed = false; return $d; }
        $d['last'] = $now;
        $d['count'] = (int) ($d['count'] ?? 0) + 1;
        return $d;
    });
    return $allowed;
}

// ---------------------------------------------------------------- step 1
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'request') {
    validateCSRFToken($_POST['csrf_token'] ?? '');
    $raw = trim($_POST['identifier'] ?? '');
    if ($raw === '') {
        $error = t('mycard.err_enter_something');
    } else {
        $emp = mycard_find_employee($companyId, $raw);
        $isEmail = strpos($raw, '@') !== false;

        $mayDeliver = false;
        if ($emp) {
            // Deliver to the address ON FILE, never to what was typed: a typo
            // that still matched (last 8 digits) must not leak a code elsewhere.
            $channel    = $isEmail ? 'email' : 'whatsapp';
            $deliveryId = $isEmail
                ? strtolower((string) $emp['email'])
                : '968' . substr(mycard_digits((string) ($emp['mobile'] ?: $emp['phone'])), -8);
            mycard_begin_verify($deliveryId, $channel, (string) $emp['id'], $raw);
            $mayDeliver = mycard_may_send($deliveryId);
        } else {
            // No match: still show the code screen, still cost the attempt.
            $_SESSION['mycard_identifier']     = '';
            $_SESSION['mycard_identifier_raw'] = $raw;
            $_SESSION['mycard_employee_id']    = '';
            $_SESSION['mycard_channel']        = $isEmail ? 'email' : 'whatsapp';
            $_SESSION['mycard_pending_verify'] = true;
            $_SESSION['mycard_notice']         = $isEmail
                ? t('mycard.sent_email') : t('mycard.sent_whatsapp');
        }

        // Answer immediately, deliver after. The user sees the code screen in
        // ~50ms instead of waiting on WhatsApp/SMTP.
        $pendingSend = ($emp && $mayDeliver)
            ? [$_SESSION['mycard_identifier'], $_SESSION['mycard_channel']]
            : null;
        header('Location: /my-card');
        if (function_exists('fastcgi_finish_request')) {
            @session_write_close();
            fastcgi_finish_request();
        }
        if ($pendingSend && $pendingSend[0] !== '') {
            try {
                $res = OtpService::send($pendingSend[0], $pendingSend[1], MYCARD_PURPOSE);
                if (empty($res['ok'])) {
                    error_log('[my-card] background OTP send failed: ' . ($res['error'] ?? '?'));
                }
            } catch (Throwable $e) {
                error_log('[my-card] background OTP send threw: ' . $e->getMessage());
            }
        }
        exit;
    }
}

// ------------------------------------------------------- step 2 + resend
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'verify') {
    validateCSRFToken($_POST['csrf_token'] ?? '');
    $deliveryId = $_SESSION['mycard_identifier']  ?? '';
    $channel    = $_SESSION['mycard_channel']     ?? 'whatsapp';
    $employeeId = $_SESSION['mycard_employee_id'] ?? '';
    $rawIdent   = $_SESSION['mycard_identifier_raw'] ?? '';

    if (($_POST['do'] ?? '') === 'resend') {
        if ($deliveryId === '') {
            // Unknown identifier: pretend, so a resend cannot probe either.
            $_SESSION['mycard_pending_verify'] = true;
            $_SESSION['mycard_notice'] = $channel === 'email'
                ? t('mycard.sent_email') : t('mycard.sent_whatsapp');
            header('Location: /my-card');
            exit;
        }
        if (!mycard_may_send($deliveryId)) {
            $error = t('mycard.err_wait');
            $step  = 'verify';
        } else {
            mycard_begin_verify($deliveryId, $channel, $employeeId, $rawIdent);
            header('Location: /my-card');
            if (function_exists('fastcgi_finish_request')) {
                @session_write_close();
                fastcgi_finish_request();
            }
            try {
                $res = OtpService::send($deliveryId, $channel, MYCARD_PURPOSE);
                if (empty($res['ok'])) {
                    error_log('[my-card] background OTP resend failed: ' . ($res['error'] ?? '?'));
                }
            } catch (Throwable $e) {
                error_log('[my-card] background OTP resend threw: ' . $e->getMessage());
            }
            exit;
        }
    } else {
        $code = trim($_POST['code'] ?? '');
        if ($deliveryId === '' || $employeeId === '') {
            // No employee behind this session: the code can never be right.
            $error = t('mycard.err_code_invalid');
        } else {
            $res = OtpService::verify($deliveryId, $code, MYCARD_PURPOSE);
            if (!empty($res['ok'])) {
                $emp = Database::getInstance()->fetchOne(
                    "SELECT id, email FROM employees
                      WHERE id = :id AND company_id = :cid AND deleted_at IS NULL LIMIT 1",
                    ['id' => $employeeId, 'cid' => $companyId]
                );
                if ($emp) {
                    unset(
                        $_SESSION['mycard_identifier'], $_SESSION['mycard_identifier_raw'],
                        $_SESSION['mycard_channel'], $_SESSION['mycard_employee_id'],
                        $_SESSION['mycard_pending_verify']
                    );
                    $plain = EmployeeEditToken::mint(
                        (string) $emp['id'], 'self-service', $_SERVER['REMOTE_ADDR'] ?? null
                    );
                    header('Location: /portal/employee-edit.php?t=' . urlencode($plain));
                    exit;
                }
                $error = t('mycard.err_code_invalid');
            } else {
                $error = match ($res['error'] ?? '') {
                    'expired_or_missing' => t('mycard.err_code_expired'),
                    'too_many_attempts'  => t('mycard.err_too_many'),
                    default              => t('mycard.err_code_invalid'),
                };
            }
        }
        $step = 'verify';
    }
}

// ------------------------------------------------------------- branding
$logoUrl = null;
$brandPrimary = '#009bc1';
foreach (['logo.svg', 'logo.png', 'logo.jpg'] as $_f) {
    $candidate = '/uploads/companies/' . $companyId . '/' . $_f;
    if (is_file(__DIR__ . $candidate)) { $logoUrl = $candidate; break; }
}
try {
    $theme = Database::getInstance()->fetchOne(
        'SELECT primary_color, logo_path FROM company_themes WHERE company_id = :id LIMIT 1',
        ['id' => $companyId]
    );
    if ($theme) {
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $theme['primary_color'] ?? '')) {
            $brandPrimary = $theme['primary_color'];
        }
        if (!$logoUrl && !empty($theme['logo_path']) && is_file(__DIR__ . $theme['logo_path'])) {
            $logoUrl = $theme['logo_path'];
        }
    }
} catch (Throwable $_) { /* legacy installs may not have company_themes */ }

$_hex = ltrim($brandPrimary, '#');
$_lum = 0.2126 * (hexdec(substr($_hex, 0, 2)) / 255)
      + 0.7152 * (hexdec(substr($_hex, 2, 2)) / 255)
      + 0.0722 * (hexdec(substr($_hex, 4, 2)) / 255);
$brandText = $_lum > 0.6 ? '#0f172a' : '#ffffff';

$locale      = currentLocale();
$dir         = isRtl() ? 'rtl' : 'ltr';
$otherLocale = $locale === 'ar' ? 'en' : 'ar';
$csrf        = generateCSRFToken();
$maskedHint  = '';
if ($step === 'verify') {
    $raw = (string) ($_SESSION['mycard_identifier_raw'] ?? '');
    if (strpos($raw, '@') !== false) {
        $parts = explode('@', $raw, 2);
        $maskedHint = substr($parts[0], 0, 2) . str_repeat('*', max(1, strlen($parts[0]) - 2)) . '@' . $parts[1];
    } elseif ($raw !== '') {
        $d = mycard_digits($raw);
        $maskedHint = str_repeat('*', max(0, strlen($d) - 4)) . substr($d, -4);
    }
}
?><!doctype html>
<html lang="<?= htmlspecialchars($locale) ?>" dir="<?= $dir ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= htmlspecialchars(t('mycard.title')) ?> · <?= htmlspecialchars($companyName) ?></title>
<link rel="icon" href="<?= $logoUrl ? htmlspecialchars($logoUrl, ENT_QUOTES) : '/favicon.svg' ?>">
<link rel="stylesheet" href="https://fonts.bhd.om/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap">
<style>
:root{--brand:<?= $brandPrimary ?>;--brand-text:<?= $brandText ?>;
      --ease-out:cubic-bezier(0.23,1,0.32,1);}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;min-height:100dvh;display:flex;flex-direction:column;
     background:#f5f7fb;color:#0f172a;
     font-family:'IBM Plex Sans Arabic',system-ui,-apple-system,'Segoe UI',sans-serif;}
.wrap{margin:auto;width:100%;max-width:420px;padding:24px 18px 40px}
.card{background:#fff;border:1px solid #eef1f6;border-radius:20px;padding:26px 22px;
      box-shadow:0 1px 2px rgba(15,23,42,.04),0 12px 32px -18px rgba(15,23,42,.25)}
.logo{display:block;max-height:46px;max-width:170px;margin:0 auto 18px}
h1{font-size:1.3rem;line-height:1.35;margin:0 0 6px;font-weight:700;text-align:center}
.sub{font-size:.9rem;color:#64748b;margin:0 0 22px;text-align:center;line-height:1.6}
label{display:block;font-size:.82rem;font-weight:600;color:#334155;margin:0 0 7px}
input[type=text],input[type=email],input[type=tel]{
  width:100%;padding:14px 15px;font-size:1rem;border:1.5px solid #dfe5ee;border-radius:12px;
  background:#fff;transition:border-color .18s var(--ease-out),box-shadow .18s var(--ease-out);
  font-family:inherit}
input:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 4px <?= $brandPrimary ?>22}
.code-input{text-align:center;letter-spacing:.55em;font-size:1.5rem;font-weight:700;
            padding-inline-start:.55em}
button{width:100%;margin-top:16px;padding:14px 16px;font-size:1rem;font-weight:600;
       border:0;border-radius:12px;background:var(--brand);color:var(--brand-text);
       cursor:pointer;font-family:inherit;
       transition:transform .18s var(--ease-out),opacity .18s var(--ease-out)}
button:active{transform:scale(.97)}
button[disabled]{opacity:.5;cursor:not-allowed}
.ghost{background:transparent;color:#64748b;font-weight:500;font-size:.86rem;margin-top:10px;
       padding:10px;border:0}
.msg{padding:11px 13px;border-radius:11px;font-size:.85rem;margin-bottom:16px;line-height:1.55}
.msg.err{background:#fef2f2;color:#b91c1c}
.msg.ok{background:#f0fdf4;color:#15803d}
.hint{font-size:.8rem;color:#94a3b8;text-align:center;margin-top:16px;line-height:1.6}
.hint a{color:#64748b}
.foot{text-align:center;margin-top:20px;font-size:.78rem;color:#94a3b8}
.foot a{color:#94a3b8;text-decoration:none}
@media (prefers-reduced-motion:reduce){*{transition:none!important}button:active{transform:none}}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <?php if ($logoUrl): ?>
      <img class="logo" src="<?= htmlspecialchars($logoUrl, ENT_QUOTES) ?>" alt="<?= htmlspecialchars($companyName) ?>">
    <?php endif; ?>

    <?php if ($step === 'request'): ?>
      <h1><?= htmlspecialchars(t('mycard.title')) ?></h1>
      <p class="sub"><?= htmlspecialchars(t('mycard.subtitle')) ?></p>

      <?php if ($error): ?><div class="msg err" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>

      <form method="post" action="/my-card">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
        <input type="hidden" name="step" value="request">
        <label for="identifier"><?= htmlspecialchars(t('mycard.identifier_label')) ?></label>
        <input id="identifier" name="identifier" type="text" inputmode="email"
               autocomplete="email" autocapitalize="off" autocorrect="off" spellcheck="false"
               required aria-required="true"
               placeholder="<?= htmlspecialchars(t('mycard.identifier_placeholder')) ?>"
               value="<?= htmlspecialchars($identifier, ENT_QUOTES) ?>">
        <button type="submit"><?= htmlspecialchars(t('mycard.send_code')) ?></button>
      </form>
      <p class="hint"><?= htmlspecialchars(t('mycard.help')) ?></p>

    <?php else: ?>
      <h1><?= htmlspecialchars(t('mycard.verify_title')) ?></h1>
      <p class="sub">
        <?= htmlspecialchars(t('mycard.verify_subtitle')) ?>
        <?php if ($maskedHint): ?><br><strong dir="ltr"><?= htmlspecialchars($maskedHint) ?></strong><?php endif; ?>
      </p>

      <?php if ($notice && !$error): ?><div class="msg ok" role="status"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="msg err" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>

      <form method="post" action="/my-card" id="verifyForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
        <input type="hidden" name="step" value="verify">
        <label for="code"><?= htmlspecialchars(t('mycard.code_label')) ?></label>
        <input id="code" name="code" class="code-input" type="text" inputmode="numeric"
               pattern="[0-9]*" maxlength="6" autocomplete="one-time-code"
               required aria-required="true" dir="ltr" autofocus>
        <button type="submit"><?= htmlspecialchars(t('mycard.verify_btn')) ?></button>
        <button type="submit" name="do" value="resend" class="ghost" id="resendBtn" formnovalidate
                <?= $resendWait > 0 ? 'disabled' : '' ?>>
          <?= htmlspecialchars(t('mycard.resend')) ?><span id="resendCounter"></span>
        </button>
      </form>
      <p class="hint"><a href="/my-card?restart=1"><?= htmlspecialchars(t('mycard.use_another')) ?></a></p>
    <?php endif; ?>
  </div>

  <p class="foot">
    <a href="/?lang=<?= $otherLocale ?>"><?= $otherLocale === 'ar' ? 'العربية' : 'English' ?></a>
    &middot; <?= htmlspecialchars($companyName) ?>
  </p>
</div>

<script>
(function () {
  // Auto-submit as soon as six digits are in, so nobody hunts for the button.
  var code = document.getElementById('code');
  if (code) {
    code.addEventListener('input', function () {
      this.value = this.value.replace(/\D/g, '').slice(0, 6);
      if (this.value.length === 6) { this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit(); }
    });
  }
  // Mirror the server-side resend throttle so the button never lies.
  var wait = <?= (int) $resendWait ?>;
  var btn = document.getElementById('resendBtn');
  var counter = document.getElementById('resendCounter');
  if (btn && wait > 0) {
    var tick = function () {
      if (wait <= 0) { btn.disabled = false; counter.textContent = ''; return; }
      counter.textContent = ' (' + wait + ')';
      wait--; setTimeout(tick, 1000);
    };
    tick();
  }
})();
</script>
</body>
</html>
