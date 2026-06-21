<?php
/**
 * POST /api/wc-otp-request.php  {name, phone, language, tz, turnstile}
 * Validates, optional Turnstile, sends a WhatsApp OTP (Cardify line).
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/WhatsApp.php';
require_once INCLUDES_DIR . '/OtpService.php';
require_once INCLUDES_DIR . '/WcHub.php';

header('Content-Type: application/json; charset=utf-8');
function out($a){ echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(['ok'=>false,'error'=>'err_generic']);
$in = json_decode(file_get_contents('php://input'), true) ?: [];

$name  = trim((string)($in['name'] ?? ''));
$phone = WhatsApp::normalizePhone((string)($in['phone'] ?? ''));
if ($name === '' || mb_strlen($name) > 120) out(['ok'=>false,'error'=>'err_name']);
if (strlen($phone) < 8 || strlen($phone) > 15) out(['ok'=>false,'error'=>'err_phone']);

// Cloudflare Turnstile (only enforced if configured)
if (defined('TURNSTILE_SECRET') && TURNSTILE_SECRET) {
    $token = (string)($in['turnstile'] ?? '');
    if ($token === '') out(['ok'=>false,'error'=>'err_generic']);
    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>http_build_query(['secret'=>TURNSTILE_SECRET,'response'=>$token,
            'remoteip'=>$_SERVER['REMOTE_ADDR'] ?? '']),
        CURLOPT_TIMEOUT=>10,
    ]);
    $resp = json_decode((string)curl_exec($ch), true); curl_close($ch);
    if (empty($resp['success'])) out(['ok'=>false,'error'=>'err_generic']);
}

$res = OtpService::send($phone, 'whatsapp', 'wc_signup');
if (!$res['ok']) {
    $err = in_array($res['error'] ?? '', ['rate_limited_identifier','rate_limited_ip'], true) ? 'err_rate' : 'err_generic';
    out(['ok'=>false,'error'=>$err]);
}
out(['ok'=>true,'masked'=>WcHub::maskPhone($phone)]);
