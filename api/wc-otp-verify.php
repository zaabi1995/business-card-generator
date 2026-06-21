<?php
/**
 * POST /api/wc-otp-verify.php  {name, phone, language, tz, code}
 * Verifies the OTP, creates/updates the wc_users row, and mirrors the
 * signup into the Cardify backend (cardify_signup_leads, source='world-cup').
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
$tz    = (string)($in['tz'] ?? 'Asia/Muscat');
$code  = trim((string)($in['code'] ?? ''));
if (!in_array($tz, timezone_identifiers_list(), true)) $tz = 'Asia/Muscat';
if ($name === '') out(['ok'=>false,'error'=>'err_name']);
if (strlen($phone) < 8) out(['ok'=>false,'error'=>'err_phone']);

$v = OtpService::verify($phone, $code, 'wc_signup');
if (!$v['ok']) {
    $err = ($v['error'] ?? '') === 'too_many_attempts' ? 'err_rate' : 'err_otp';
    out(['ok'=>false,'error'=>$err]);
}

$cc = WcHub::detectCountry();
try {
    $db = Database::getInstance();
    $exists = $db->fetchOne("SELECT lead_id FROM wc_users WHERE phone = :p LIMIT 1", ['p'=>$phone]);
    $leadId = $exists['lead_id'] ?? WcHub::mirrorLead($phone, $name, $lang, $cc);
    $user = WcHub::upsertUser($phone, $name, $lang, $tz, $cc, $leadId);
    WcHub::login($user);
} catch (Throwable $e) {
    error_log('wc-otp-verify upsert failed: ' . $e->getMessage());
    out(['ok'=>false,'error'=>'err_generic']);
}

// Best-effort branded welcome message (non-blocking), with an unsubscribe link.
try {
    $S = WcHub::strings($lang);
    $unsub = 'https://wc.cardify.om/u?t=' . urlencode($user['unsub_token'] ?? '');
    $rtl = ($lang==='ar' || $lang==='ur');
    $stop = $rtl ? "لإيقاف الإشعارات: {$unsub}" : "Stop notifications: {$unsub}";
    $welcome = "⚽ {$S['success_title']}\n{$S['success_sub']}\n"
        . ($rtl ? "" : "Predict: ") . "https://wc.cardify.om/predictions\n\n"
        . "{$stop}\n{$S['brand']}";
    WhatsApp::sendMessage($phone, $welcome, ['bypassAntiBan'=>true]);
} catch (Throwable $e) { /* ignore */ }

out(['ok'=>true,'points'=>(int)($user['points_cache'] ?? 0)]);
