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
        if (AdminApprovalToken::wasUsed($token)) {
            aat_message_page(
                'Already signed - Cardify', "\xE2\x9C\x94",
                'This delivery note is already signed',
                'The signed copy was emailed to you. There is nothing else to do.',
                'تم توقيع إشعار التسليم بالفعل',
                'تم إرسال النسخة الموقعة إليكم بالبريد'
            );
            exit;
        }
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
    if (AdminApprovalToken::wasUsed($token)) {
        aat_message_page(
            'Already signed - Cardify', "\xE2\x9C\x94",
            'This delivery note is already signed',
            'The signed copy was emailed to you. There is nothing else to do.',
            'تم توقيع إشعار التسليم بالفعل',
            'تم إرسال النسخة الموقعة إليكم بالبريد'
        );
        exit;
    }
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

// The signing work runs first and the token is spent only when it worked.
// Consuming first burnt the link on any transient failure, an ERP timeout or a
// stamper error, and the division had no way to get another one.
//
// Signing twice is prevented by the file itself: sign() returns the copy that
// is already on disk rather than making a second one.
$ip = (string)($_SERVER['HTTP_CF_CONNECTING_IP']
    ?? $_SERVER['REMOTE_ADDR']
    ?? '');
if (strpos($ip, ',') !== false) { $ip = trim(explode(',', $ip)[0]); }

try {
    $r = DeliverySignature::sign($job, (string)$row['admin_email'], $ip);
} catch (Throwable $e) {
    error_log('[mhd dn sign] ' . $e->getMessage());
    $r = ['ok' => false, 'error' => 'something went wrong on our side', 'already' => false];
}
if (!empty($r['ok'])) {
    AdminApprovalToken::consumeApprove($token);
    // The signed copy is no longer emailed to them, so give them a way to take
    // it from here. The session this opens is scoped to their own division.
    AdminApprovalToken::startAdminSession($row);
}

if ($r['ok']) {
    $copy = getTenantUrl($job['company_slug'] ?? 'mhd',
        '/admin/card-job-file?id=' . urlencode((string)$job['id']) . '&kind=signed');
    aat_message_page(
        'Signed - Cardify', "\xE2\x9C\x94",
        !empty($r['already']) ? 'This delivery note is already signed' : 'Delivery note signed',
        'There is nothing else to do. Your signed copy is here whenever you need it.',
        !empty($r['already']) ? 'تم توقيع إشعار التسليم بالفعل' : 'تم توقيع إشعار التسليم',
        'لا حاجة لأي إجراء آخر، ونسختكم الموقعة متاحة هنا في أي وقت',
        $copy,
        'Download the signed delivery note'
    );
    exit;
}

// The page used to promise that BHD had been notified while nothing notified
// anyone. Now it is true before it is said, and the link still works, because
// the token is only spent on success.
try {
    require_once INCLUDES_DIR . '/MhdMailer.php';
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
    MhdMailer::sendRaw(['sales@bhdoman.com'], [],
        '[' . ($job['job_ref'] ?? '?') . '] Delivery note could not be signed',
        '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222;line-height:1.6">'
        . '<p>' . $e($row['admin_email']) . ' clicked the signing link and it failed.</p>'
        . '<p>Job <strong>' . $e($job['job_ref'] ?? '') . '</strong><br>'
        . 'Reason: <strong>' . $e($r['error'] ?? 'unknown') . '</strong></p>'
        . '<p style="color:#6b7280;font-size:13px">Their link still works: it is only spent once a '
        . 'signature is filed, so they can click it again once the cause is cleared.</p></div>');
} catch (Throwable $e) {
    error_log('[mhd dn sign alert] ' . $e->getMessage());
}

aat_message_page(
    'Not signed - Cardify', "\xE2\x9A\xA0",
    'The delivery note could not be signed',
    ($r['error'] ?? 'Please try the link again') . '. BHD has been told, and this link still works.',
    'تعذر توقيع إشعار التسليم',
    'تم إشعار BHD، والرابط ما زال صالحًا للمحاولة مرة أخرى'
);
