<?php
/**
 * One-click delivery-note signature.
 *
 * GET  -> a prefetch-safe page that submits itself. Email scanners follow
 *         links and MHD run Trend Micro, so a bare GET that signs would be
 *         signed by the scanner. Scanners do not run JavaScript.
 * POST -> CSRF checked, the token is consumed once, then the note is signed.
 *
 * The token carries purpose 'delivery_note', so an approval link cannot sign a
 * delivery note and a signing link cannot approve a card.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/SecurityHeaders.php';
SecurityHeaders::send();
require_once INCLUDES_DIR . '/AdminApprovalToken.php';
require_once INCLUDES_DIR . '/AdminApprovalView.php';
require_once INCLUDES_DIR . '/DeliverySignature.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $token = $_GET['t'] ?? '';
    if (!AdminApprovalToken::verify($token, DeliverySignature::PURPOSE)) {
        aat_expired_page();
        exit;
    }
    aat_page_open('Signing - Cardify');
    ?>
    <div class="card center">
        <div class="brandbar"></div>
        <div class="icon">&#9989;</div>
        <h1>Signing the delivery note</h1>
        <p class="sub">One moment, this page is signing it.</p>
        <p class="sub-ar">جارٍ توقيع إشعار التسليم</p>
        <div class="actions">
            <form method="POST" id="autoSignForm"
                  action="<?php echo htmlspecialchars(getBasePath() . 'admin/sign-delivery.php'); ?>">
                <?php echo csrfField(); ?>
                <input type="hidden" name="t" value="<?php echo htmlspecialchars($token); ?>">
                <noscript>
                    <button type="submit" class="btn btn-primary big-confirm">Confirm: Sign the delivery note</button>
                </noscript>
            </form>
        </div>
    </div>
    <script<?php echo function_exists('cspNonceAttr') ? cspNonceAttr() : ''; ?>>
        (function () {
            var f = document.getElementById('autoSignForm');
            if (!f) { return; }
            window.addEventListener('load', function () {
                if (typeof f.requestSubmit === 'function') { f.requestSubmit(); } else { f.submit(); }
            });
        })();
    </script>
    <?php
    aat_page_close();
    exit;
}

if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    aat_message_page(
        'Invalid request - Cardify', "\xE2\x9A\xA0",
        'Your session could not be verified',
        'Please open the signing link again and retry.',
        'تعذر التحقق من الجلسة',
        'يرجى فتح رابط التوقيع مرة أخرى والمحاولة من جديد'
    );
    exit;
}

$token = $_POST['t'] ?? '';
$row   = AdminApprovalToken::verify($token, DeliverySignature::PURPOSE);
if (!$row) {
    aat_expired_page();
    exit;
}

$db  = Database::getInstance();
$job = $db->fetchOne(
    "SELECT r.*, c.slug AS company_slug FROM card_requests r
      LEFT JOIN companies c ON c.id = r.company_id
     WHERE r.id = :id AND r.company_id = :cid",
    ['id' => $row['request_id'], 'cid' => $row['company_id']]
);
if (!$job) {
    aat_expired_page();
    exit;
}

// Consume before signing, so two clicks cannot file two signed copies. A
// signature that is already on file answers the second click kindly.
$fresh = AdminApprovalToken::consumeApprove($token);

$ip = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
if (strpos($ip, ',') !== false) { $ip = trim(explode(',', $ip)[0]); }

$r = ['ok' => false, 'error' => 'already signed', 'already' => true];
if ($fresh) {
    try {
        $r = DeliverySignature::sign($job, (string)$row['admin_email'], $ip);
    } catch (Throwable $e) {
        error_log('[mhd dn sign] ' . $e->getMessage());
        $r = ['ok' => false, 'error' => 'something went wrong on our side', 'already' => false];
    }
} else {
    $signed = $db->fetchOne(
        "SELECT id FROM card_request_events
          WHERE request_id = :r AND to_state = 'note:dn_signed' LIMIT 1",
        ['r' => $job['id']]);
    $r['ok'] = (bool)$signed;
}

if ($r['ok']) {
    aat_message_page(
        'Signed - Cardify', "\xE2\x9C\x94",
        !empty($r['already']) ? 'This delivery note is already signed' : 'Delivery note signed',
        'The signed copy has been emailed to you. There is nothing else to do.',
        !empty($r['already']) ? 'تم توقيع إشعار التسليم بالفعل' : 'تم توقيع إشعار التسليم',
        'تم إرسال النسخة الموقعة إليكم بالبريد، ولا حاجة لأي إجراء آخر'
    );
    exit;
}

aat_message_page(
    'Not signed - Cardify', "\xE2\x9A\xA0",
    'The delivery note could not be signed',
    ($r['error'] ?? 'Please try the link again') . '. BHD has been notified.',
    'تعذر توقيع إشعار التسليم',
    'يرجى المحاولة مرة أخرى، وقد تم إشعار BHD'
);
