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
    <p class="sub">Enter your email or phone. We'll send a one-time code.</p>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <input type="hidden" name="step" value="request">
    <label for="identifier">Email or phone</label>
    <input type="text" name="identifier" id="identifier" autocomplete="username" required value="<?= htmlspecialchars($identifier) ?>" placeholder="you@example.com or 9XXXXXXX">
    <button type="submit">Send code</button>
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
</body>
</html>
