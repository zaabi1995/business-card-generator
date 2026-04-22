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

$step = $_POST['step'] ?? 'request';
$error = null;
$notice = null;
$identifier = trim($_POST['identifier'] ?? $_SESSION['tlogin_identifier'] ?? '');
$channel = null;
$displayIdentifier = $identifier;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'request') {
    if ($identifier === '') {
        $error = 'Enter your email or phone number.';
    } else {
        $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false;
        $channel = $isEmail ? 'email' : 'whatsapp';
        $deliveryId = $isEmail ? strtolower($identifier) : tl_normalize_phone($identifier);
        if (!$isEmail && strlen($deliveryId) < 8) {
            $error = 'Enter a valid phone number.';
        } else {
            $user = tl_lookup_user($identifier, $companyId);
            if (!$user) {
                $error = 'No account found for that ' . ($isEmail ? 'email' : 'phone') . '. Contact your admin.';
            } else {
                $purpose = 'tlogin:' . substr(hash('sha1', $companyId), 0, 12);
                $res = OtpService::send($deliveryId, $channel, $purpose);
                if (!empty($res['ok'])) {
                    $_SESSION['tlogin_identifier'] = $deliveryId;
                    $_SESSION['tlogin_channel']    = $channel;
                    $_SESSION['tlogin_user_id']    = $user['id'];
                    $_SESSION['tlogin_purpose']    = $purpose;
                    $step = 'verify';
                    $notice = ($channel === 'email')
                        ? 'Code sent to your email.'
                        : 'Code sent via WhatsApp.';
                    $displayIdentifier = $deliveryId;
                } else {
                    $err = $res['error'] ?? 'unknown';
                    $error = ($err === 'rate_limited_identifier' || $err === 'rate_limited_ip')
                        ? 'Too many attempts. Try again later.'
                        : 'Could not send code. Try the other channel.';
                }
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
        $error = 'Session expired. Start over.';
    } elseif (!preg_match('/^\d{6}$/', $code)) {
        $error = 'Enter the 6-digit code.';
    } else {
        $res = OtpService::verify($deliveryId, $code, $purpose);
        if (!empty($res['ok'])) {
            $db = Database::getInstance();
            $user = $db->fetchOne("SELECT * FROM users WHERE id = :id LIMIT 1", ['id' => $userId]);
            if (!$user || $user['company_id'] !== $companyId) {
                $error = 'Account not found.';
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
                'expired_or_missing' => 'Code expired. Request a new one.',
                'too_many_attempts'  => 'Too many tries. Request a new code.',
                default              => 'Invalid code.',
            };
        }
    }
}

$logoUrl = null;
if (!empty($tenant['id'])) {
    $candidate = '/uploads/companies/' . $tenant['id'] . '/logo.png';
    if (is_file(__DIR__ . $candidate)) $logoUrl = $candidate;
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($companyName) ?> — Cardify Login</title>
<link rel="icon" href="/favicon.svg">
<?php $detectedPhone = $step === 'request' && $identifier !== '' && !filter_var($identifier, FILTER_VALIDATE_EMAIL); ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@25.3.1/build/css/intlTelInput.min.css">
<style>
:root { --brand: #009bc1; --bg: #f8fafc; --ink: #0f172a; --muted: #64748b; --line: #e2e8f0; }
*{box-sizing:border-box} body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:var(--bg);color:var(--ink);min-height:100vh;display:grid;place-items:center;padding:24px}
.card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:32px;width:100%;max-width:420px;box-shadow:0 8px 24px rgba(15,23,42,.06)}
.logo{display:flex;align-items:center;gap:12px;margin-bottom:24px}
.logo img{width:48px;height:48px;border-radius:10px;object-fit:contain;background:#fff;border:1px solid var(--line)}
.logo .badge{width:48px;height:48px;border-radius:10px;background:var(--brand);color:#fff;display:grid;place-items:center;font-weight:700;font-size:18px}
h1{font-size:20px;margin:0 0 4px;font-weight:700} .sub{color:var(--muted);font-size:14px;margin:0 0 20px}
label{display:block;font-size:13px;font-weight:600;margin:14px 0 6px}
input[type=email],input[type=tel],input[type=text]{width:100%;padding:12px 14px;border:1px solid var(--line);border-radius:10px;font-size:15px;outline:none;transition:border .15s}
input:focus{border-color:var(--brand);box-shadow:0 0 0 3px rgba(0,155,193,.15)}
.code{letter-spacing:8px;text-align:center;font-size:22px;font-family:ui-monospace,monospace}
button{width:100%;margin-top:16px;padding:12px 14px;background:var(--brand);color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:600;cursor:pointer}
button:hover{filter:brightness(0.95)} button.ghost{background:transparent;color:var(--muted);font-weight:500;margin-top:8px}
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
</style>
</head>
<body>
<form class="card" method="post" novalidate>
  <div class="logo">
    <?php if ($logoUrl): ?>
      <img src="<?= htmlspecialchars($logoUrl) ?>" alt="<?= htmlspecialchars($companyName) ?>">
    <?php else: ?>
      <div class="badge"><?= htmlspecialchars(strtoupper(substr($companyName, 0, 1))) ?></div>
    <?php endif; ?>
    <div>
      <div style="font-weight:700;font-size:16px"><?= htmlspecialchars($companyName) ?></div>
      <div style="font-size:12px;color:var(--muted)">Powered by Cardify</div>
    </div>
  </div>

  <?php if ($step === 'request'): ?>
    <h1>Sign in</h1>
    <p class="sub">We'll send a one-time code to your phone or email.</p>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <input type="hidden" name="step" value="request">
    <input type="hidden" name="identifier" id="identifier-hidden" value="">

    <div class="tabs" role="tablist" aria-label="Sign-in method">
      <button type="button" id="tab-phone" role="tab" aria-selected="<?= $detectedPhone || !$identifier ? 'true' : 'false' ?>" aria-controls="pane-phone">Phone</button>
      <button type="button" id="tab-email" role="tab" aria-selected="<?= $detectedPhone || !$identifier ? 'false' : 'true' ?>" aria-controls="pane-email">Email</button>
    </div>

    <div id="pane-phone" class="pane" data-active="<?= $detectedPhone || !$identifier ? 'true' : 'false' ?>" role="tabpanel" aria-labelledby="tab-phone">
      <label for="phone">Phone number</label>
      <input type="tel" id="phone" autocomplete="tel" value="<?= htmlspecialchars($detectedPhone ? $identifier : '') ?>">
      <div class="field-err" id="phone-err" role="alert"></div>
    </div>

    <div id="pane-email" class="pane" data-active="<?= $detectedPhone || !$identifier ? 'false' : 'true' ?>" role="tabpanel" aria-labelledby="tab-email">
      <label for="email">Email address</label>
      <input type="email" id="email" autocomplete="email" value="<?= htmlspecialchars(!$detectedPhone ? $identifier : '') ?>" placeholder="you@example.com">
    </div>

    <button type="submit" id="submit-btn">Send code</button>
  <?php else: ?>
    <h1>Enter code</h1>
    <p class="sub">We sent a 6-digit code to <strong><?= htmlspecialchars($displayIdentifier) ?></strong>.</p>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($notice): ?><div class="notice"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
    <input type="hidden" name="step" value="verify">
    <label for="code">6-digit code</label>
    <input type="text" name="code" id="code" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" class="code" required autofocus>
    <button type="submit">Verify & sign in</button>
    <a href="/login" style="text-decoration:none"><button type="button" class="ghost">Use a different email or phone</button></a>
  <?php endif; ?>

  <div class="foot">
    Trouble signing in? Email <a href="mailto:support@cardify.om">support@cardify.om</a>
  </div>
</form>

<?php if ($step === 'request'): ?>
<script src="https://cdn.jsdelivr.net/npm/intl-tel-input@25.3.1/build/js/intlTelInput.min.js"></script>
<script>
(function () {
  try {
    const form       = document.querySelector('form.card');
    const hidden     = document.getElementById('identifier-hidden');
    const phoneInput = document.getElementById('phone');
    const emailInput = document.getElementById('email');
    const phonePane  = document.getElementById('pane-phone');
    const emailPane  = document.getElementById('pane-email');
    const phoneTab   = document.getElementById('tab-phone');
    const emailTab   = document.getElementById('tab-email');
    const phoneErr   = document.getElementById('phone-err');

    const iti = window.intlTelInput(phoneInput, {
      initialCountry: "om",
      countryOrder: ["om","ae","sa","qa","bh","kw","eg","jo","lb","iq"],
      separateDialCode: true,
      strictMode: true,
      formatAsYouType: true,
      loadUtilsOnInit: "https://cdn.jsdelivr.net/npm/intl-tel-input@25.3.1/build/js/utils.js",
    });

    const ERR_MAP = {0:'Enter a phone number',1:'Invalid country code',2:'Too short',3:'Too long',4:'Invalid number'};

    function activate(which) {
      const isPhone = which === 'phone';
      phonePane.dataset.active = isPhone ? 'true' : 'false';
      emailPane.dataset.active = isPhone ? 'false' : 'true';
      phoneTab.setAttribute('aria-selected', isPhone ? 'true' : 'false');
      emailTab.setAttribute('aria-selected', isPhone ? 'false' : 'true');
      phoneErr.textContent = '';
      setTimeout(() => { (isPhone ? phoneInput : emailInput).focus(); }, 50);
    }
    phoneTab.addEventListener('click', () => activate('phone'));
    emailTab.addEventListener('click', () => activate('email'));

    form.addEventListener('submit', function (e) {
      const usingPhone = phonePane.dataset.active === 'true';
      if (usingPhone) {
        let valid = false;
        try { valid = iti.isValidNumber(); } catch (_) { valid = !!phoneInput.value; }
        if (!valid) {
          e.preventDefault();
          let code = -1;
          try { code = iti.getValidationError(); } catch (_) {}
          phoneErr.textContent = ERR_MAP[code] || 'Invalid phone number';
          phoneInput.focus();
          return;
        }
        // getNumber() returns E.164 by default. Strip the leading "+" so the
        // server-side phone matcher (digits-only) finds the right user row.
        let num = '';
        try { num = iti.getNumber() || phoneInput.value; } catch (_) { num = phoneInput.value; }
        hidden.value = num.replace(/^\+/, '').replace(/[^0-9]/g, '');
      } else {
        const v = (emailInput.value || '').trim();
        if (!v || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) {
          e.preventDefault();
          emailInput.focus();
          return;
        }
        hidden.value = v;
      }
    });

    setTimeout(() => {
      (phonePane.dataset.active === 'true' ? phoneInput : emailInput).focus();
    }, 100);
  } catch (err) {
    // If the country selector library fails to load for any reason, fall back
    // to a plain text input so the user can still log in.
    console.error('tenant_login init failed:', err);
    const phoneInput = document.getElementById('phone');
    const hidden     = document.getElementById('identifier-hidden');
    const form       = document.querySelector('form.card');
    if (phoneInput) phoneInput.placeholder = '+968XXXXXXXX or your email';
    if (form && hidden) {
      form.addEventListener('submit', function () {
        const phoneVal = (phoneInput && phoneInput.value || '').trim();
        const emailVal = (document.getElementById('email')?.value || '').trim();
        hidden.value = phoneVal.replace(/[^0-9]/g, '') || emailVal;
      });
    }
  }
})();
</script>
<?php endif; ?>
</body>
</html>
