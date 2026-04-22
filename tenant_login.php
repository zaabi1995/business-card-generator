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
    if (strlen($digits) === 8 && $digits[0] === '9') {
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
            $user = tl_lookup_user($identifier, $companyId);
            if (!$user) {
                $error = t('auth.tenant_err_no_account', [
                    'kind' => t($isEmail ? 'auth.tenant_kind_email' : 'auth.tenant_kind_phone'),
                ]);
            } else {
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
                $res = OtpService::send($deliveryId, $channel, $purpose);
                if (empty($res['ok'])) {
                    error_log('[tenant_login] background OTP send failed for ' . $deliveryId . ' ch=' . $channel . ': ' . ($res['error'] ?? 'unknown'));
                }
                exit;
            }
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
    $candidate = '/uploads/companies/' . $tenant['id'] . '/logo.png';
    if (is_file(__DIR__ . $candidate)) $logoUrl = $candidate;
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
<title><?= htmlspecialchars($companyName) ?> — Cardify</title>
<link rel="icon" href="/favicon.svg">
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
</body>
</html>
