<?php
/**
 * POST /api/scan/claim-otp.php, WhatsApp OTP claim step for the public
 * claim-card.php page (not claim.php, that's the unrelated lead-capture
 * landing page).
 *
 * Body: {token, action:"send"}                -> {success, error}
 *       {token, action:"verify", code}         -> {success, redirect, error}
 *
 * Stateless JSON endpoint, no session/cookie involved, the claim_token
 * itself (43-char random, validated in ShadowProfileService::findByToken)
 * is the only authority, so this deliberately skips CSRF like the sibling
 * api/scan/*.php bearer-token endpoints.
 *
 * purpose='scan_claim' keeps these OTP codes in their own otp_codes bucket,
 * isolated from tenant login/signup codes for the same phone number.
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ShadowProfileService.php';
require_once INCLUDES_DIR . '/OtpService.php';
require_once INCLUDES_DIR . '/RateLimiter.php';
require_once INCLUDES_DIR . '/UrlSafety.php';
require_once INCLUDES_DIR . '/ScanClaimTicket.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST only']);
    exit;
}

try {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $token = trim((string)($body['token'] ?? ''));
    $action = (string)($body['action'] ?? '');
    // Proxy-aware client IP (CF-Connecting-IP first): behind Cloudflare,
    // REMOTE_ADDR is the edge node, which would collapse every visitor
    // into a shared rate-limit bucket.
    $ip = getClientIp();

    // findByToken regex-validates the 43-char shape itself; opted_out
    // profiles are treated as not-found so a stale/shared link can't be
    // used to re-engage someone who already said stop.
    $profile = ShadowProfileService::findByToken($token);
    if (!$profile || (int)$profile['opted_out'] === 1) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'invalid_token']);
        exit;
    }
    // Already claimed: never issue or accept another OTP for this profile.
    if (!empty($profile['claimed_at'])) {
        echo json_encode(['success' => false, 'error' => 'already_claimed']);
        exit;
    }
    $phone = $profile['phone_primary'];
    if (!$phone) {
        echo json_encode(['success' => false, 'error' => 'no_phone']);
        exit;
    }

    if ($action === 'send') {
        // 3 sends per 15 minutes, keyed on token+IP. OtpService::send() has
        // its own per-identifier/per-IP backstops (12/hr, 60/day), this is a
        // tighter guard specific to the public claim surface so a leaked or
        // guessed token can't be used to spam one phone number with WhatsApp
        // OTPs from a single source.
        if (!RateLimiter::check('scan_claim_otp:' . $token, $ip, 3, 900)) {
            echo json_encode(['success' => false, 'error' => 'rate_limited']);
            exit;
        }
        $r = OtpService::send($phone, 'whatsapp', 'scan_claim');
        echo json_encode(['success' => !empty($r['ok']), 'error' => $r['error'] ?? null]);
        exit;
    }

    if ($action === 'verify') {
        // 10 verifies per 15 minutes per token+IP: defense-in-depth on top
        // of OtpService's per-code attempt cap (5), whose read-then-update
        // counter is racy under parallel requests. RateLimiter's atomic
        // INSERT..ON DUPLICATE KEY counter isn't.
        if (!RateLimiter::check('scan_claim_verify:' . $token, $ip, 10, 900)) {
            echo json_encode(['success' => false, 'error' => 'rate_limited']);
            exit;
        }
        $code = trim((string)($body['code'] ?? ''));
        $r = OtpService::verify($phone, $code, 'scan_claim');
        if (empty($r['ok'])) {
            echo json_encode(['success' => false, 'error' => $r['error'] ?? 'bad_code']);
            exit;
        }

        $claimTicket = ScanClaimTicket::issue(
            Database::getInstance(),
            (int) $profile['id'],
            (string) $phone
        );

        $parsed = json_decode((string)($profile['best_parsed'] ?? ''), true) ?: [];
        // Real signup entry point is /company/register.php (verified: no
        // register.php/signup.php exists at the repo root). It only reads
        // ?email=, ?name=, ?ref= on GET (phone/company have no GET prefill,
        // company creation collects them on the form itself), so only those
        // three are forwarded here with the one-time registration ticket.
        $qs = http_build_query([
            'ref' => 'scan_claim',
            'name' => $parsed['name_en'] ?? '',
            'email' => $profile['email_primary'] ?? '',
            'claim_ticket' => $claimTicket,
        ]);
        echo json_encode(['success' => true, 'redirect' => '/company/register.php?' . $qs]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'bad_action']);
} catch (\Throwable $e) {
    error_log('[scan/claim-otp] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
    exit;
}
