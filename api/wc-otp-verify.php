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
$ref   = substr(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($in['ref'] ?? ''))), 0, 12);
$nhour = max(0, min(23, (int)($in['notify_hour'] ?? 10)));
if (!in_array($tz, timezone_identifiers_list(), true)) $tz = 'Asia/Muscat';
if (strlen($phone) < 8) out(['ok'=>false,'error'=>'err_phone']);

$db = Database::getInstance();
$existing = $db->fetchOne("SELECT * FROM wc_users WHERE phone = :p LIMIT 1", ['p'=>$phone]);
// A new number must supply a name; a returning number logs in with the code alone.
if (!$existing && $name === '') out(['ok'=>false,'error'=>'err_name']);

$v = OtpService::verify($phone, $code, 'wc_signup');
if (!$v['ok']) {
    $err = ($v['error'] ?? '') === 'too_many_attempts' ? 'err_rate' : 'err_otp';
    out(['ok'=>false,'error'=>$err]);
}

$cc = WcHub::detectCountry();
try {
    if ($existing && $name === '') {
        // Returning login: do not overwrite their profile, just refresh + sign in.
        $db->update('wc_users', ['status'=>'active','verified_at'=>date('Y-m-d H:i:s')], 'id=:id', ['id'=>$existing['id']]);
        $user = $db->fetchOne("SELECT * FROM wc_users WHERE id = :id", ['id'=>$existing['id']]);
    } else {
        $leadId = $existing['lead_id'] ?? WcHub::mirrorLead($phone, $name, $lang, $cc);
        $user = WcHub::upsertUser($phone, $name, $lang, $tz, $cc, $leadId, $ref ?: null, $nhour);
    }
    WcHub::login($user);
} catch (Throwable $e) {
    error_log('wc-otp-verify upsert failed: ' . $e->getMessage());
    out(['ok'=>false,'error'=>'err_generic']);
}

// Respond instantly; send the welcome (new signups only) after flushing.
$isNew = !$existing;
echo json_encode(['ok'=>true,'points'=>(int)($user['points_cache'] ?? 0),'ref'=>$user['ref_code'] ?? ''], JSON_UNESCAPED_UNICODE);
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
if ($isNew) {
    try {
        $S = WcHub::strings($lang);
        $unsub = 'https://wc.cardify.om/u?t=' . urlencode($user['unsub_token'] ?? '');
        $rtl = ($lang==='ar' || $lang==='ur');
        $stop = $rtl ? "لإيقاف الإشعارات: {$unsub}" : "Stop notifications: {$unsub}";
        $welcome = "⚽ {$S['success_title']}\n{$S['success_sub']}\n"
            . ($rtl ? "" : "Predict: ") . "https://wc.cardify.om/predictions\n\n"
            . "{$stop}\n{$S['brand']}";
        WcHub::waSend($phone, $welcome); // from Kabir 96891117795
    } catch (Throwable $e) { /* ignore */ }
}
exit;
