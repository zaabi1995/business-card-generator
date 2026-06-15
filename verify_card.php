<?php
/**
 * verify_card.php, confirms inbox ownership for an instant demo card.
 * Valid token -> mark the demo card verified -> redirect to the magic-link
 * self-edit page so the owner can upload a logo / edit (auto-updates the card).
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/SecurityHeaders.php';
SecurityHeaders::send();
require_once INCLUDES_DIR . '/functions.php';
require_once INCLUDES_DIR . '/Database.php';
require_once INCLUDES_DIR . '/DatabaseAdapter.php';
require_once INCLUDES_DIR . '/EmployeeEditToken.php';
require_once INCLUDES_DIR . '/InstantCard.php';

$t   = (string)($_GET['t'] ?? '');
$emp = $t !== '' ? EmployeeEditToken::verify($t) : null;

// Only demo-tenant tokens are valid here.
if (!$emp || ($emp['company_id'] ?? '') !== InstantCard::DEMO_COMPANY_ID) {
    http_response_code(400);
    $isAr = (function_exists('currentLocale') && currentLocale() === 'ar');
    $msg  = $isAr ? 'انتهت صلاحية الرابط أو أنه غير صحيح. اطلب بطاقة جديدة من cardify.om.'
                  : 'This link has expired or is invalid. Create a fresh card at cardify.om.';
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>Cardify</title><body style="font-family:system-ui,sans-serif;background:#f7f8f7;color:#0c1418;'
       . 'display:grid;place-items:center;min-height:100dvh;margin:0;padding:24px;text-align:center">'
       . '<div><h1 style="color:#009bc1;margin:0 0 12px">Cardify</h1><p style="max-width:30em">'
       . htmlspecialchars($msg) . '</p><p><a href="https://cardify.om" style="color:#009bc1;font-weight:600">cardify.om</a></p></div>';
    exit;
}

InstantCard::markVerified($emp['id']);

// Hand them straight into editing. Pass NO employee slug: demo slugs are
// multi-dot (ali.bhd.om) and not bare-routable, so buildUrl falls back to the
// always-routable token form (/portal/employee-edit.php?token=...).
$editUrl = EmployeeEditToken::buildUrl($t, InstantCard::DEMO_SLUG, null);
header('Location: ' . $editUrl, true, 302);
exit;
