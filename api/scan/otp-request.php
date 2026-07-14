<?php
/**
 * POST /api/scan/otp-request.php, passwordless login/signup step 1.
 *
 * Body: {identifier}  where identifier is an email OR a phone number.
 *   -> {success:true, channel:'email'|'whatsapp', identifier_masked:'...'}
 *
 * Detects the identifier type: email when it contains '@' and validates,
 * otherwise normalises it as a phone (E.164, +968 default). Sends a 6-digit
 * OTP via OtpService with purpose 'scan_login', channel 'email' for an
 * email and 'whatsapp' for a phone.
 *
 * Privacy: NEVER reveals whether the identifier already has an account. The
 * response shape is identical for existing and new identifiers, so an
 * attacker cannot enumerate accounts. api/scan/otp-verify.php does the
 * find-or-create.
 *
 * Reuses:
 *   - OtpService::send()   includes/OtpService.php (hashed codes, throttles)
 *   - Phone::normalize()   includes/Phone.php (E.164, +968 default)
 *   - getClientIp()        includes/UrlSafety.php (CF-aware client IP)
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/OtpService.php';
require_once INCLUDES_DIR . '/RateLimiter.php';
require_once INCLUDES_DIR . '/UrlSafety.php';
require_once INCLUDES_DIR . '/Phone.php';
require_once INCLUDES_DIR . '/WhatsApp.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST only']);
    exit;
}

try {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $raw = trim((string)($body['identifier'] ?? ''));
    if ($raw === '') {
        echo json_encode(['success' => false, 'error' => 'identifier_required']);
        exit;
    }

    // Proxy-aware client IP (CF-Connecting-IP first): behind Cloudflare
    // REMOTE_ADDR is the edge node, collapsing every visitor into one bucket.
    $ip = getClientIp();

    // Detect type: email if it has '@' and validates, else treat as a phone.
    $isEmail = strpos($raw, '@') !== false;
    if ($isEmail) {
        $identifier = strtolower($raw);
        if (!filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'error' => 'invalid_identifier']);
            exit;
        }
        $channel = 'email';
        $masked = maskEmailIdentifier($identifier);
    } else {
        $identifier = Phone::normalize($raw); // E.164, +968 default
        if ($identifier === null) {
            echo json_encode(['success' => false, 'error' => 'invalid_identifier']);
            exit;
        }
        $channel = 'whatsapp';
        $masked = Phone::mask($identifier);
    }

    // Tight per-surface guard: 5 sends / 15 min keyed on identifier+IP. This
    // sits on top of OtpService's own per-identifier (12/hr) and per-IP
    // (60/day) backstops so a single source cannot spam one target.
    if (!RateLimiter::check('scan_otp_request:' . $identifier, $ip, 5, 900)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'rate_limited']);
        exit;
    }

    // For a phone, WhatsApp must actually be wired up (Meta Cloud OR the
    // Dardasha/Baileys line). If neither is configured, tell the app so it
    // can suggest email instead of dropping the OTP into a black hole.
    if ($channel === 'whatsapp' && !whatsappConfiguredForOtp()) {
        echo json_encode(['success' => false, 'error' => 'whatsapp_unavailable']);
        exit;
    }

    $res = OtpService::send($identifier, $channel, 'scan_login');
    if (empty($res['ok'])) {
        // Map a delivery failure on the WhatsApp channel to the actionable
        // "unavailable" hint; keep the generic code for everything else.
        $err = $res['error'] ?? 'send_failed';
        if ($channel === 'whatsapp' && $err === 'delivery_failed') {
            echo json_encode(['success' => false, 'error' => 'whatsapp_unavailable']);
            exit;
        }
        // Rate-limit errors from OtpService surface as a 429 too.
        if (in_array($err, ['rate_limited_identifier', 'rate_limited_ip'], true)) {
            http_response_code(429);
        }
        echo json_encode(['success' => false, 'error' => $err]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'channel' => $channel,
        'identifier_masked' => $masked,
    ]);
} catch (\Throwable $e) {
    error_log('[scan/otp-request] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
    exit;
}

/**
 * True when at least one WhatsApp transport is configured: the Meta Cloud
 * auth-code line (WACLOUD_*) or the Dardasha/Baileys line (WhatsApp::isEnabled).
 */
function whatsappConfiguredForOtp(): bool
{
    $cloud = defined('WACLOUD_TOKEN') && WACLOUD_TOKEN
        && defined('WACLOUD_PHONE_ID') && WACLOUD_PHONE_ID;
    if ($cloud) return true;
    return class_exists('WhatsApp') && WhatsApp::isEnabled();
}

/**
 * Mask an email to a***@x.om form: first char of the local part, then ***,
 * then the full domain. Never leaks the rest of the local part.
 */
function maskEmailIdentifier(string $email): string
{
    $at = strpos($email, '@');
    if ($at === false) return '***';
    $local = substr($email, 0, $at);
    $domain = substr($email, $at + 1);
    $first = $local !== '' ? $local[0] : '*';
    return $first . '***@' . $domain;
}
