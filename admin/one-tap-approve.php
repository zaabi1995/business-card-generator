<?php
/**
 * Scoped magic-link ACTION endpoint + email one-tap target.
 *
 * GET  -> prefetch-safe confirm interstitial (verify only, no consume).
 * POST -> CSRF-checked. The token is consumed exactly once BEFORE the
 *         approve chain runs so a double-submit can never double-create
 *         an employee. Reject mirrors admin/requests.php.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/SecurityHeaders.php';
SecurityHeaders::send();
require_once INCLUDES_DIR . '/AdminApprovalToken.php';
require_once INCLUDES_DIR . '/RequestApproval.php';
require_once INCLUDES_DIR . '/AdminApprovalView.php';
require_once INCLUDES_DIR . '/Mailer.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$adminBase = defined('COMPANY_ADMIN_BASE') ? COMPANY_ADMIN_BASE : getBasePath() . 'admin/';

// ---------------------------------------------------------------------
// GET: render a minimal, prefetch-safe confirmation interstitial.
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $token = $_GET['t'] ?? '';
    $row = AdminApprovalToken::verify($token);
    if (!$row) {
        aat_expired_page();
        exit;
    }

    $reviewUrl = getBasePath() . 'admin/approve-request.php?t=' . urlencode($token);

    aat_page_open('Confirm approval - Cardify');
    ?>
    <div class="card center">
        <div class="brandbar"></div>
        <div class="icon">&#9989;</div>
        <h1>Confirm this approval</h1>
        <p class="sub">Approve the card request and send it to print.</p>
        <p class="sub-ar">الموافقة على طلب البطاقة وإرساله للطباعة</p>
        <div class="actions">
            <form method="POST" action="<?php echo htmlspecialchars(getBasePath() . 'admin/one-tap-approve.php'); ?>">
                <?php echo csrfField(); ?>
                <input type="hidden" name="t" value="<?php echo htmlspecialchars($token); ?>">
                <input type="hidden" name="send_to_print" value="1">
                <button type="submit" class="btn btn-primary big-confirm" autofocus>Confirm: Approve &amp; Send to Print</button>
            </form>
        </div>
        <p class="note">
            <a class="btn-link" href="<?php echo htmlspecialchars($reviewUrl); ?>">Review the design first</a>
        </p>
    </div>
    <?php
    aat_page_close();
    exit;
}

// ---------------------------------------------------------------------
// POST: CSRF, then single-use consume, then act.
// ---------------------------------------------------------------------
if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    aat_message_page(
        'Invalid request - Cardify',
        "\xE2\x9A\xA0", // warning
        'Your session could not be verified',
        'Please open the approval link again and retry.',
        'تعذر التحقق من الجلسة',
        'يرجى فتح رابط الموافقة مرة أخرى والمحاولة من جديد'
    );
    exit;
}

$token = $_POST['t'] ?? '';
$row = AdminApprovalToken::verify($token);
if (!$row) {
    aat_expired_page();
    exit;
}

AdminApprovalToken::startAdminSession($row);

$db = Database::getInstance();
$action = $_POST['action'] ?? 'approve';

// ---- Reject branch -------------------------------------------------
if ($action === 'reject') {
    if (!AdminApprovalToken::consumeApprove($token)) {
        aat_message_page(
            'Already actioned - Cardify',
            "\xE2\x84\xB9", // info
            'This request was already actioned',
            'No further action is needed.',
            'تمت معالجة هذا الطلب بالفعل',
            'لا حاجة لأي إجراء إضافي',
            getTenantUrl($_SESSION['company_slug'] ?? null, '/admin/requests'),
            'Open requests'
        );
        exit;
    }

    $request = $db->fetchOne(
        "SELECT * FROM card_requests WHERE id = :id AND company_id = :cid",
        ['id' => $row['request_id'], 'cid' => $row['company_id']]
    );
    if (!$request) {
        aat_expired_page();
        exit;
    }

    $company = findCompanyById($row['company_id']);
    $companyName = $company['name_en'] ?? $company['name'] ?? 'Company';
    $notes = trim($_POST['reason'] ?? '');

    try {
        $db->query(
            "UPDATE card_requests SET status = 'rejected', admin_notes = :notes, reviewed_at = NOW(), reviewed_by = :uid WHERE id = :id",
            ['notes' => $notes, 'uid' => $row['admin_email'], 'id' => $row['request_id']]
        );

        $employeeName = $request['name_en'] ?: $request['name_ar'];
        Mailer::sendTemplate($request['email'], 'request_rejected', [
            'employee_name'    => $employeeName,
            'company_name'     => $companyName,
            'rejection_reason' => $notes ?: 'No specific reason provided. Please contact your administrator for more information.',
        ]);
    } catch (Exception $e) {
        error_log('one-tap reject error: ' . $e->getMessage());
    }

    aat_message_page(
        'Request rejected - Cardify',
        "\xE2\x9C\x96", // heavy multiplication x
        'Request rejected',
        'The employee has been notified by email.',
        'تم رفض الطلب',
        'تم إشعار الموظف عبر البريد الإلكتروني',
        getTenantUrl($_SESSION['company_slug'] ?? null, '/admin/requests'),
        'Open requests'
    );
    exit;
}

// ---- Approve branch ------------------------------------------------
// Consume BEFORE the chain so a concurrent/double submit cannot create
// two employees. consumeApprove() succeeds for exactly one caller.
if (!AdminApprovalToken::consumeApprove($token)) {
    aat_message_page(
        'Already approved - Cardify',
        "\xE2\x9C\x94", // check mark
        'This request was already approved',
        'The card is being generated. No further action is needed.',
        'تمت الموافقة على هذا الطلب بالفعل',
        'جاري إنشاء البطاقة، لا حاجة لأي إجراء إضافي',
        getTenantUrl($_SESSION['company_slug'] ?? null, '/admin/requests'),
        'Open requests'
    );
    exit;
}

$request = $db->fetchOne(
    "SELECT * FROM card_requests WHERE id = :id AND company_id = :cid",
    ['id' => $row['request_id'], 'cid' => $row['company_id']]
);
if (!$request) {
    aat_expired_page();
    exit;
}

$company = findCompanyById($row['company_id']);
$sendToPrint = (bool)($_POST['send_to_print'] ?? false);

$r = approveRequestChain($request, $company, $row['company_id'], $row['admin_email'], $sendToPrint);

if ($r['success'] && $r['employee_id']) {
    $target = $adminBase . 'batch_generate?employee_id=' . urlencode($r['employee_id'])
        . '&auto_generate=1&send_email=1';
    if (!headers_sent()) {
        header('Location: ' . $target);
        exit;
    }
    // Fallback if headers already flushed.
    echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($target, ENT_QUOTES) . '">';
    exit;
}

error_log('one-tap approve failed: ' . ($r['error'] ?? 'unknown'));
http_response_code(500);
aat_message_page(
    'Approval failed - Cardify',
    "\xE2\x9A\xA0", // warning
    'We could not complete the approval',
    'Please try again from the admin dashboard.',
    'تعذر إتمام الموافقة',
    'يرجى المحاولة مرة أخرى من لوحة التحكم',
    getTenantUrl($_SESSION['company_slug'] ?? null, '/admin/requests'),
    'Open requests'
);
