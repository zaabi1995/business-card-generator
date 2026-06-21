<?php
/**
 * POST /api/wc-otp-request.php  {name, phone, language, tz, turnstile}
 * Validates, optional Turnstile, generates+stores an OTP and sends it FROM
 * Kabir (96891117795). Verified later via OtpService::verify (same otp_codes table).
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
$lang  = WcHub::lang((string)($in['language'] ?? 'en'));
if ($name === '' || mb_strlen($name) > 120) out(['ok'=>false,'error'=>'err_name']);
if (strlen($phone) < 8 || strlen($phone) > 15) out(['ok'=>false,'error'=>'err_phone']);

// Cloudflare Turnstile (only enforced if configured)
if (defined('TURNSTILE_SECRET') && TURNSTILE_SECRET) {
    $token = (string)($in['turnstile'] ?? '');
    if ($token === '') out(['ok'=>false,'error'=>'err_generic']);
    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>http_build_query(['secret'=>TURNSTILE_SECRET,'response'=>$token,'remoteip'=>$_SERVER['REMOTE_ADDR'] ?? '']),
        CURLOPT_TIMEOUT=>10]);
    $resp = json_decode((string)curl_exec($ch), true); curl_close($ch);
    if (empty($resp['success'])) out(['ok'=>false,'error'=>'err_generic']);
}

// Rate limit (same policy as OtpService): 3/hr per number, 10/day per IP.
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (class_exists('RateLimiter')) {
    if (!RateLimiter::check('wc_otp_ident:'.$phone, $ip, OtpService::RATE_PER_IDENTIFIER, OtpService::RATE_PER_IDENTIFIER_WINDOW))
        out(['ok'=>false,'error'=>'err_rate']);
    if (!RateLimiter::check('wc_otp_ip', $ip, OtpService::RATE_PER_IP, OtpService::RATE_PER_IP_WINDOW))
        out(['ok'=>false,'error'=>'err_rate']);
}

// Generate + store the OTP (otp_codes; verified by OtpService::verify).
$code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
try {
    Database::getInstance()->insert('otp_codes', [
        'identifier' => $phone, 'channel' => 'whatsapp', 'code_hash' => hash('sha256', $code),
        'purpose' => 'wc_signup', 'expires_at' => date('Y-m-d H:i:s', time() + OtpService::TTL_SECONDS),
        'ip' => substr($ip, 0, 45), 'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);
} catch (Throwable $e) { error_log('wc-otp-request store: '.$e->getMessage()); out(['ok'=>false,'error'=>'err_generic']); }

// Send FROM Kabir (96891117795).
$msg = [
  'en' => "Your Cardify World Cup code is *$code*\nValid 10 minutes. Do not share it.",
  'ar' => "رمز كأس العالم من Cardify هو *$code*\nصالح لمدة 10 دقائق. لا تشاركه.",
  'hi' => "आपका Cardify वर्ल्ड कप कोड *$code* है\n10 मिनट के लिए वैध। किसी से साझा न करें।",
  'bn' => "আপনার Cardify বিশ্বকাপ কোড *$code*\n১০ মিনিট বৈধ। কারো সাথে শেয়ার করবেন না।",
  'ur' => "آپ کا Cardify ورلڈ کپ کوڈ *$code* ہے\n10 منٹ کے لیے کارآمد۔ کسی کے ساتھ شیئر نہ کریں۔",
][$lang] ?? "Your Cardify World Cup code is *$code*\nValid 10 minutes. Do not share it.";

if (!WcHub::waSend($phone, $msg)) out(['ok'=>false,'error'=>'err_generic']);
out(['ok'=>true,'masked'=>WcHub::maskPhone($phone)]);
