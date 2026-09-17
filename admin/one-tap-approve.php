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
    $row = AdminApprovalToken::verify($token, 'card_request');
    if (!$row) {
        if (AdminApprovalToken::wasUsed($token)) {
            aat_message_page(
                'Already actioned - Cardify', "\xE2\x9C\x94",
                'This request was already actioned',
                'No further action is needed.',
                'تمت معالجة هذا الطلب بالفعل',
                'لا حاجة لأي إجراء إضافي'
            );
            exit;
        }
        aat_expired_page();
        exit;
    }

    $reviewUrl = getBasePath() . 'admin/approve-request.php?t=' . urlencode($token);

    // Ali, 16 Sep 2026: clicking the link should approve, with nothing further
    // to press. It cannot be a bare GET that mutates, because email scanners
    // follow links and MHD runs Trend Micro, so requests would approve
    // themselves in the scanner. Instead the page submits itself on load:
    // one click for a person, and nothing for a scanner, which does not run JS.
    // The noscript path keeps the old button for anyone with JS disabled.
    aat_page_open('Approving - Cardify');
    ?>
    <div class="card center">
        <div class="brandbar"></div>
        <div class="icon">&#9989;</div>
        <h1>Approving this request</h1>
        <p class="sub">One moment, this page is approving the card and sending it to print.</p>
        <p class="sub-ar">جارٍ الموافقة على البطاقة وإرسالها للطباعة</p>
        <div class="actions">
            <form method="POST" id="autoApproveForm"
                  action="<?php echo htmlspecialchars(getBasePath() . 'admin/one-tap-approve.php'); ?>">
                <?php echo csrfField(); ?>
                <input type="hidden" name="t" value="<?php echo htmlspecialchars($token); ?>">
                <input type="hidden" name="send_to_print" value="1">
                <noscript>
                    <button type="submit" class="btn btn-primary big-confirm">Confirm: Approve &amp; Send to Print</button>
                </noscript>
            </form>
        </div>
        <p class="note">
            <a class="btn-link" href="<?php echo htmlspecialchars($reviewUrl); ?>">Open the full request instead</a>
        </p>
    </div>
    <script<?php echo function_exists('cspNonceAttr') ? cspNonceAttr() : ''; ?>>
        // Submit on load. requestSubmit falls back to submit on older engines.
        (function () {
            var f = document.getElementById('autoApproveForm');
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
// With the purpose, so a delivery-note token cannot approve a card. Migration
// 160 added the column for exactly this and only half of it was enforced.
$row = AdminApprovalToken::verify($token, 'card_request');
if (!$row) {
    if (AdminApprovalToken::wasUsed($token)) {
        aat_message_page(
            'Already actioned - Cardify', "\xE2\x9C\x94",
            'This request was already actioned',
            'No further action is needed.',
            'تمت معالجة هذا الطلب بالفعل',
            'لا حاجة لأي إجراء إضافي'
        );
        exit;
    }
    aat_expired_page();
    exit;
}

$db = Database::getInstance();
$action = $_POST['action'] ?? 'approve';

// The request may already be decided elsewhere (BHD in admin/requests.php, or a
// second link). A reject then left the job running on to an invoice, and an
// approve could revive a rejected card. Only a request still waiting is acted on.
$__current = $db->fetchOne(
    "SELECT status, fulfilment_state FROM card_requests WHERE id = :id AND company_id = :cid",
    ['id' => $row['request_id'], 'cid' => $row['company_id']]);
if ($__current && (($__current['status'] ?? '') !== 'pending'
        || !in_array((string)($__current['fulfilment_state'] ?? 'submitted'), ['', 'submitted'], true))) {
    aat_message_page(
        'Already actioned - Cardify', "\xE2\x9C\x94",
        'This request was already actioned',
        'No further action is needed.',
        'تمت معالجة هذا الطلب بالفعل',
        'لا حاجة لأي إجراء إضافي'
    );
    exit;
}

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
            getTenantUrl($_SESSION['company_slug'] ?? null, '/admin/card-jobs'),
            'Open card jobs'
        );
        exit;
    }

    // The consume is won, so this caller is the one acting: now a session.
    AdminApprovalToken::startAdminSession($row);

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

        // The flow has its own state. Without this a declined job stayed at
        // submitted for ever: the console read it as waiting for approval and
        // everything downstream kept treating it as live.
        require_once INCLUDES_DIR . '/CardJob.php';
        CardJob::transition((string)$row['request_id'], 'rejected', [
            'actor' => (string)$row['admin_email'], 'reason' => $notes,
        ]);
        CardJob::restoreEmployee((string)$row['request_id']);

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
        getTenantUrl($_SESSION['company_slug'] ?? null, '/admin/card-jobs'),
        'Open card jobs'
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
        getTenantUrl($_SESSION['company_slug'] ?? null, '/admin/card-jobs'),
        'Open card jobs'
    );
    exit;
}

AdminApprovalToken::startAdminSession($row);

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

// The division is needed before the chain runs, not after: a division that
// carries an approver runs the MHD flow, which prices the job itself and
// quotes it once. Letting the chain quote as well raised two ERP quotes for
// one card, at two different prices.
$db   = Database::getInstance();
$dept = !empty($request['department_id']) ? $db->fetchOne(
    "SELECT * FROM departments WHERE id = :did AND company_id = :cid",
    ['did' => $request['department_id'], 'cid' => $row['company_id']]) : null;
$mhdFlow = $dept && !empty($dept['responsible_email']);

$r = approveRequestChain($request, $company, $row['company_id'], $row['admin_email'], $sendToPrint, $mhdFlow);

if ($r['success'] && $r['employee_id']) {
    // The link session may generate and send only this employee's card.
    $_SESSION['magic_link_employee'] = (string)$r['employee_id'];
    $_SESSION['magic_link_employee_email'] = strtolower((string)($request['email'] ?? ''));
    // Approval raises the quotation and asks for the purchase order. Wrapped
    // whole: the token is already consumed by this point, so a failure here must
    // never cost the approval itself.
    try {
        require_once INCLUDES_DIR . '/CardFulfilment.php';
        CardFulfilment::afterApproval($request, $r, (string)$row['admin_email']);
    } catch (Throwable $e) {
        error_log('[mhd post-approve] ' . $e->getMessage());
    }

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
    getTenantUrl($_SESSION['company_slug'] ?? null, '/admin/card-jobs'),
    'Open card jobs'
);
