<?php
/**
 * Scoped magic-link REVIEW landing (GET, token via ?t=).
 *
 * Prefetch-safe: verify() only, never consume. The token is spent solely
 * on an explicit POST to one-tap-approve.php. Renders the card design
 * preview, the employee summary and the requested quantity, with three
 * actions (approve+print, approve, reject).
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/SecurityHeaders.php';
SecurityHeaders::send();
require_once INCLUDES_DIR . '/AdminApprovalToken.php';
require_once INCLUDES_DIR . '/RequestApproval.php';
require_once INCLUDES_DIR . '/AdminApprovalView.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$token = $_GET['t'] ?? '';
$row = AdminApprovalToken::verify($token);

if (!$row) {
    aat_expired_page();
    exit;
}

// Scope the session to this token's company before touching admin data.
AdminApprovalToken::startAdminSession($row);

$db = Database::getInstance();
$request = $db->fetchOne(
    "SELECT * FROM card_requests WHERE id = :id AND company_id = :cid",
    ['id' => $row['request_id'], 'cid' => $row['company_id']]
);

if (!$request) {
    // Token valid but the request is gone (deleted). Neutral expired copy.
    aat_expired_page();
    exit;
}

$company = findCompanyById($row['company_id']);
$companyName = $company['name_en'] ?? $company['name'] ?? 'Your company';

$employeeName = $request['name_en'] ?: ($request['name_ar'] ?: 'Employee');
$quantity = max(1, (int)($request['quantity_requested'] ?? 1));

$frontUrl = aat_asset_url($request['preview_front_path'] ?: ($request['preview_front'] ?? ''));
$backUrl  = aat_asset_url($request['preview_back_path'] ?: ($request['preview_back'] ?? ''));

$requestType = $request['request_type'] ?? 'new';
$typeLabel = ['new' => 'New card', 'update' => 'Update', 'reprint' => 'Reprint'][$requestType] ?? 'New card';

// Build the employee summary rows (label => value), skipping empties.
$summary = [
    'Name (EN)'     => $request['name_en'] ?? '',
    'Name (AR)'     => $request['name_ar'] ?? '',
    'Position (EN)' => $request['position_en'] ?? '',
    'Position (AR)' => $request['position_ar'] ?? '',
    'Email'         => $request['email'] ?? '',
    'Phone'         => $request['phone'] ?? '',
    'Mobile'        => $request['mobile'] ?? '',
];

$actionUrl = getBasePath() . 'admin/one-tap-approve.php';

aat_page_open('Review card request - Cardify');
?>
<div class="card">
    <div class="brandbar"></div>
    <h1>Review card request</h1>
    <p class="sub">Approve or reject the business card request below.</p>
    <p class="sub-ar">مراجعة طلب بطاقة العمل والموافقة عليه أو رفضه</p>
    <p class="company"><?php echo htmlspecialchars($companyName); ?> &middot; <?php echo htmlspecialchars($typeLabel); ?></p>

    <?php if ($frontUrl || $backUrl): ?>
    <div class="section-title">Card design</div>
    <div class="previews<?php echo ($frontUrl && $backUrl) ? ' two' : ''; ?>">
        <?php if ($frontUrl): ?>
        <div class="preview">
            <img src="<?php echo htmlspecialchars($frontUrl); ?>" alt="Card front preview" loading="lazy">
            <div class="tag">Front</div>
        </div>
        <?php endif; ?>
        <?php if ($backUrl): ?>
        <div class="preview">
            <img src="<?php echo htmlspecialchars($backUrl); ?>" alt="Card back preview" loading="lazy">
            <div class="tag">Back</div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="section-title">Employee</div>
    <div class="summary">
        <?php foreach ($summary as $label => $value): if (trim((string)$value) === '') continue; ?>
        <div class="row">
            <span class="k"><?php echo htmlspecialchars($label); ?></span>
            <span class="v"<?php echo (strpos($label, '(AR)') !== false) ? ' dir="rtl"' : ''; ?>><?php echo htmlspecialchars($value); ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <div><span class="qty"><?php echo $quantity; ?> pcs</span></div>

    <div class="actions">
        <!-- (a) Approve and send to print -->
        <form method="POST" action="<?php echo htmlspecialchars($actionUrl); ?>">
            <?php echo csrfField(); ?>
            <input type="hidden" name="t" value="<?php echo htmlspecialchars($token); ?>">
            <input type="hidden" name="send_to_print" value="1">
            <button type="submit" class="btn btn-primary">Approve &amp; Send to Print</button>
        </form>

        <!-- (b) Approve card only -->
        <form method="POST" action="<?php echo htmlspecialchars($actionUrl); ?>">
            <?php echo csrfField(); ?>
            <input type="hidden" name="t" value="<?php echo htmlspecialchars($token); ?>">
            <input type="hidden" name="send_to_print" value="0">
            <button type="submit" class="btn btn-secondary">Approve (card only)</button>
        </form>

        <!-- (c) Reject -->
        <details>
            <summary>Reject this request</summary>
            <form method="POST" action="<?php echo htmlspecialchars($actionUrl); ?>" style="margin-top:10px;">
                <?php echo csrfField(); ?>
                <input type="hidden" name="t" value="<?php echo htmlspecialchars($token); ?>">
                <input type="hidden" name="action" value="reject">
                <textarea name="reason" class="reject-reason" placeholder="Reason (optional) - included in the email to the employee"></textarea>
                <button type="submit" class="btn btn-danger">Reject Request</button>
            </form>
        </details>
    </div>

    <p class="note">This is a secure single-use approval link scoped to one request. رابط آمن للاستخدام مرة واحدة.</p>
</div>
<?php
aat_page_close();
