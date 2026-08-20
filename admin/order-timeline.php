<?php
/**
 * Unified order tracking timeline. One place that shows a card order from the
 * employee request through approval, print, the BHD hand-off, and the full ERP
 * document chain (quote, PO, invoice, sales order, delivery note). Company
 * scoped. Read-only.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';

requireAdmin();
$companyId = getCurrentCompanyId();
if (!$companyId) { header('Location: ' . getBasePath() . 'login.php'); exit; }

$db = Database::getInstance();
$orderId = trim($_GET['order'] ?? '');

// Load the order, scoped to this company (cross-tenant guard).
$order = $orderId !== ''
    ? $db->fetchOne(
        "SELECT po.*, e.name_en AS emp_name, e.email AS emp_email
         FROM print_orders po
         LEFT JOIN employees e ON e.id = po.employee_id
         WHERE po.id = :id AND po.company_id = :cid",
        ['id' => $orderId, 'cid' => $companyId]
    )
    : null;

// The request that spawned it (for the submitted/approved timestamps).
$req = ($order)
    ? $db->fetchOne(
        "SELECT * FROM card_requests WHERE print_order_id = :oid AND company_id = :cid ORDER BY submitted_at DESC LIMIT 1",
        ['oid' => $orderId, 'cid' => $companyId]
    )
    : null;

// Helper: absolute URL for an uploaded doc path.
$docUrl = function (?string $p): string {
    $p = trim((string)$p);
    if ($p === '') return '';
    if (strpos($p, 'http') === 0) return $p;
    $host = defined('APP_HOST') ? APP_HOST : 'cardify.om';
    return 'https://' . $host . '/' . ltrim($p, '/');
};
$fmt = function (?string $ts): string {
    $ts = trim((string)$ts);
    if ($ts === '' || $ts === '0000-00-00 00:00:00') return '';
    $t = dbTs($ts);
    return $t ? date('d M Y, H:i', $t) : '';
};

// Build the stage list from the columns. Each: label, done(bool), when, meta, link.
$stages = [];
if ($order) {
    $paid = ($order['payment_status'] ?? '') === 'paid';
    $stages = [
        [
            'label' => 'Request submitted',
            'when'  => $fmt($req['submitted_at'] ?? ''),
            'done'  => !empty($req),
            'meta'  => $order['emp_name'] ?? ($req['name_en'] ?? ''),
        ],
        [
            'label' => 'Approved',
            'when'  => $fmt($req['reviewed_at'] ?? ''),
            'done'  => !empty($req['reviewed_at']),
            'meta'  => !empty($req['reviewed_by']) ? ('by ' . $req['reviewed_by']) : '',
        ],
        [
            'label' => 'Print order placed',
            'when'  => $fmt($order['created_at'] ?? ''),
            'done'  => true,
            'meta'  => $order['order_number'] . ' - ' . (int)$order['quantity'] . ' pcs',
        ],
        [
            'label' => 'Sent to BHD (info@bhdoman.com)',
            'when'  => $fmt($order['created_at'] ?? ''),
            'done'  => ((int)($order['print_shop_id'] ?? 0) === 2),
            'meta'  => ((int)($order['print_shop_id'] ?? 0) === 2) ? 'Print-ready sheet emailed' : 'Other print shop',
        ],
        [
            'label' => 'ERP quote raised',
            'when'  => '',
            'done'  => !empty($order['erp_quote_id']),
            'meta'  => $order['quotation_number'] ?? ($order['erp_quote_id'] ?? ''),
        ],
        [
            'label' => $paid ? 'Paid' : 'Purchase order attached',
            'when'  => $paid ? $fmt($order['invoice_paid_at'] ?? $order['balance_paid_at'] ?? '') : $fmt($order['po_received_at'] ?? ''),
            'done'  => $paid || !empty($order['po_number']),
            'meta'  => $paid ? ('via ' . ($order['payment_method'] ?? 'online')) : ($order['po_number'] ?? ''),
            'link'  => $paid ? '' : $docUrl($order['po_file_path'] ?? ''),
        ],
        [
            'label' => 'Invoice',
            'when'  => $fmt($order['invoice_issued_at'] ?? ''),
            'done'  => !empty($order['erp_invoice_id']) || !empty($order['invoice_number']),
            'meta'  => $order['erp_invoice_number'] ?? ($order['invoice_number'] ?? ''),
            'link'  => $docUrl($order['invoice_file_path'] ?? ''),
        ],
        [
            'label' => 'Sales order',
            'when'  => '',
            'done'  => !empty($order['erp_order_id']),
            'meta'  => $order['erp_order_id'] ?? '',
        ],
        [
            'label' => 'Delivery note',
            'when'  => $fmt($order['delivery_note_issued_at'] ?? ''),
            'done'  => !empty($order['delivery_note_external_id']) || !empty($order['delivery_note_number']),
            'meta'  => $order['delivery_note_number'] ?? ($order['delivery_note_external_id'] ?? ''),
            'link'  => $docUrl($order['delivery_note_file_path'] ?? ''),
        ],
        [
            'label' => 'Delivered',
            'when'  => $fmt($order['delivered_at'] ?? ''),
            'done'  => ($order['status'] ?? '') === 'delivered',
            'meta'  => ucfirst($order['status'] ?? ''),
        ],
    ];
}

adminHeader('Order tracking', 'orders');
?>
<style>
    .otl-wrap { max-width: 720px; margin: 0 auto; }
    .otl-head { margin-bottom: 20px; }
    .otl-head h1 { font-size: 20px; font-weight: 700; color: #0f172a; margin: 0 0 4px; }
    .otl-head .sub { color: #64748b; font-size: 14px; }
    .otl-steps { position: relative; padding-left: 8px; }
    .otl-step { position: relative; padding: 0 0 22px 34px; }
    .otl-step:before { content: ''; position: absolute; left: 10px; top: 20px; bottom: -4px; width: 2px; background: #e2e8f0; }
    .otl-step:last-child:before { display: none; }
    .otl-dot { position: absolute; left: 2px; top: 2px; width: 18px; height: 18px; border-radius: 50%;
        background: #fff; border: 2px solid #cbd5e1; display: flex; align-items: center; justify-content: center; }
    .otl-step.done .otl-dot { background: #009bc1; border-color: #00718c; }
    .otl-step.done .otl-dot:after { content: ''; width: 6px; height: 6px; border-radius: 50%; background: #fff; }
    .otl-label { font-weight: 600; color: #0f172a; font-size: 15px; }
    .otl-step:not(.done) .otl-label { color: #94a3b8; }
    .otl-meta { color: #475569; font-size: 13px; margin-top: 2px; }
    .otl-when { color: #94a3b8; font-size: 12px; margin-top: 1px; }
    .otl-link { display: inline-block; margin-top: 4px; font-size: 13px; color: #00718c; text-decoration: none; }
    .otl-empty { color: #64748b; padding: 24px 0; }
</style>
<div class="otl-wrap">
<?php if (!$order): ?>
    <div class="otl-head"><h1>Order tracking</h1></div>
    <p class="otl-empty">Order not found. Open it from the Orders list.</p>
<?php else: ?>
    <div class="otl-head">
        <h1><?= htmlspecialchars($order['order_number']) ?></h1>
        <div class="sub">
            <?= htmlspecialchars($order['emp_name'] ?: ($order['emp_email'] ?? '')) ?>
            &middot; <?= (int)$order['quantity'] ?> pcs
            &middot; <?= htmlspecialchars(number_format((float)$order['total'], 3)) ?> <?= htmlspecialchars($order['currency'] ?? 'OMR') ?>
        </div>
    </div>
    <div class="otl-steps">
        <?php foreach ($stages as $s): ?>
        <div class="otl-step<?= !empty($s['done']) ? ' done' : '' ?>">
            <span class="otl-dot"></span>
            <div class="otl-label"><?= htmlspecialchars($s['label']) ?></div>
            <?php if (!empty($s['meta'])): ?><div class="otl-meta"><?= htmlspecialchars($s['meta']) ?></div><?php endif; ?>
            <?php if (!empty($s['when'])): ?><div class="otl-when"><?= htmlspecialchars($s['when']) ?></div><?php endif; ?>
            <?php if (!empty($s['link'])): ?><a class="otl-link" href="<?= htmlspecialchars($s['link']) ?>" target="_blank" rel="noopener">View document</a><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
</div>
<?php adminFooter(); ?>
