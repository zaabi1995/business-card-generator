<?php
/**
 * Monthly BHD-ERP reconciliation (Cat S action 454).
 *
 * Compares Cardify's payments + print_orders against what we have
 * synced to BHD-ERP for a calendar month and reports drift. Intended
 * to run on the 2nd of each month for the prior month.
 *
 * Usage:
 *   php scripts/erp-reconcile.php                  # last month, email Ali
 *   php scripts/erp-reconcile.php --month=2026-04  # specific month
 *   php scripts/erp-reconcile.php --no-email       # stdout only
 *
 * What it checks:
 *   1. print_orders paid in the window that lack erp_invoice_number.
 *   2. payments.type=card_order paid rows whose callback_data has no
 *      erp_invoice_number (the flag ERPSync::recordCardCreditPurchase
 *      sets).
 *   3. Totals: OMR paid vs OMR synced for each stream.
 *   4. Any erp_sync_retries rows still pending from the window.
 *
 * Output: human-readable report + JSON block. Email subject includes
 * the drift count so an inbox rule can escalate non-zero mornings.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Mailer.php';

$month    = null;
$sendMail = true;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--no-email')                      $sendMail = false;
    elseif (preg_match('/^--month=(\d{4}-\d{2})$/', $a, $m)) $month = $m[1];
}
if (!$month) {
    $month = date('Y-m', strtotime('first day of -1 month'));
}

$from = $month . '-01 00:00:00';
$to   = date('Y-m-t 23:59:59', strtotime($from));

$db = Database::getInstance();
$report = ['month' => $month, 'generated_at' => date('c'), 'drift_count' => 0];

// 1. print_orders paid but not synced
$printOrdersDrift = $db->fetchAll(
    "SELECT po.id, po.order_number, po.total, po.currency,
            po.erp_sync_status, po.erp_sync_error, po.payment_status,
            c.name AS company_name
       FROM print_orders po
  LEFT JOIN companies c ON c.id = po.company_id
      WHERE po.payment_status = 'paid'
        AND COALESCE(po.balance_paid_at, po.deposit_paid_at, po.updated_at) BETWEEN :f AND :t
        AND (po.erp_invoice_number IS NULL OR po.erp_invoice_number = '')
   ORDER BY po.updated_at ASC",
    ['f' => $from, 't' => $to]
);
$report['print_orders_unsynced'] = count($printOrdersDrift);
$report['drift_count'] += count($printOrdersDrift);

// 2. card_order payments paid but not synced
$cardOrdersDrift = $db->fetchAll(
    "SELECT p.id, p.amount, p.currency, p.callback_data, p.created_at,
            c.name AS company_name
       FROM payments p
  LEFT JOIN companies c ON c.id = p.company_id
      WHERE p.type = 'card_order' AND p.status = 'paid'
        AND p.updated_at BETWEEN :f AND :t
        AND (p.callback_data IS NULL OR p.callback_data NOT LIKE '%erp_invoice_number%')
   ORDER BY p.updated_at ASC",
    ['f' => $from, 't' => $to]
);
$report['card_orders_unsynced'] = count($cardOrdersDrift);
$report['drift_count'] += count($cardOrdersDrift);

// 3. Stream totals
$printPaid = $db->fetchOne(
    "SELECT COALESCE(SUM(total), 0) AS total_paid, COUNT(*) AS n
       FROM print_orders
      WHERE payment_status = 'paid'
        AND COALESCE(balance_paid_at, deposit_paid_at, updated_at) BETWEEN :f AND :t",
    ['f' => $from, 't' => $to]
);
$printSynced = $db->fetchOne(
    "SELECT COALESCE(SUM(total), 0) AS total_synced, COUNT(*) AS n
       FROM print_orders
      WHERE payment_status = 'paid'
        AND COALESCE(balance_paid_at, deposit_paid_at, updated_at) BETWEEN :f AND :t
        AND erp_invoice_number IS NOT NULL AND erp_invoice_number != ''",
    ['f' => $from, 't' => $to]
);
$cardPaid = $db->fetchOne(
    "SELECT COALESCE(SUM(amount), 0) AS total_paid, COUNT(*) AS n
       FROM payments
      WHERE type = 'card_order' AND status = 'paid'
        AND updated_at BETWEEN :f AND :t",
    ['f' => $from, 't' => $to]
);
$cardSynced = $db->fetchOne(
    "SELECT COALESCE(SUM(amount), 0) AS total_synced, COUNT(*) AS n
       FROM payments
      WHERE type = 'card_order' AND status = 'paid'
        AND updated_at BETWEEN :f AND :t
        AND callback_data LIKE '%erp_invoice_number%'",
    ['f' => $from, 't' => $to]
);
$report['streams'] = [
    'print_orders' => [
        'paid_count'   => (int) $printPaid['n'],
        'paid_omr'     => number_format((float) $printPaid['total_paid'], 3),
        'synced_count' => (int) $printSynced['n'],
        'synced_omr'   => number_format((float) $printSynced['total_synced'], 3),
        'drift_omr'    => number_format((float) $printPaid['total_paid'] - (float) $printSynced['total_synced'], 3),
    ],
    'card_orders' => [
        'paid_count'   => (int) $cardPaid['n'],
        'paid_omr'     => number_format((float) $cardPaid['total_paid'], 3),
        'synced_count' => (int) $cardSynced['n'],
        'synced_omr'   => number_format((float) $cardSynced['total_synced'], 3),
        'drift_omr'    => number_format((float) $cardPaid['total_paid'] - (float) $cardSynced['total_synced'], 3),
    ],
];

// 4. Open retries from the window
$retries = [];
try {
    $retries = $db->fetchAll(
        "SELECT id, order_id, attempts, status, last_error, next_retry_at
           FROM erp_sync_retries
          WHERE status IN ('pending','exhausted')
            AND created_at BETWEEN :f AND :t
       ORDER BY status DESC, next_retry_at ASC",
        ['f' => $from, 't' => $to]
    );
} catch (Throwable $_) { /* table may not exist on legacy installs */ }
$report['pending_retries'] = count(array_filter($retries, static fn($r) => $r['status'] === 'pending'));
$report['exhausted_retries'] = count(array_filter($retries, static fn($r) => $r['status'] === 'exhausted'));
$report['drift_count'] += $report['exhausted_retries'];

// --- Text render ---
$lines = [];
$lines[] = "Cardify × BHD-ERP reconciliation — $month";
$lines[] = str_repeat('=', 64);
$lines[] = "Generated: {$report['generated_at']}";
$lines[] = "Drift count: {$report['drift_count']}";
$lines[] = '';
$lines[] = 'Print-order stream';
$lines[] = sprintf('  paid:   %s OMR across %d orders',
    $report['streams']['print_orders']['paid_omr'],
    $report['streams']['print_orders']['paid_count']);
$lines[] = sprintf('  synced: %s OMR across %d orders',
    $report['streams']['print_orders']['synced_omr'],
    $report['streams']['print_orders']['synced_count']);
$lines[] = sprintf('  drift:  %s OMR', $report['streams']['print_orders']['drift_omr']);
$lines[] = '';
$lines[] = 'Card-credit stream';
$lines[] = sprintf('  paid:   %s OMR across %d payments',
    $report['streams']['card_orders']['paid_omr'],
    $report['streams']['card_orders']['paid_count']);
$lines[] = sprintf('  synced: %s OMR across %d payments',
    $report['streams']['card_orders']['synced_omr'],
    $report['streams']['card_orders']['synced_count']);
$lines[] = sprintf('  drift:  %s OMR', $report['streams']['card_orders']['drift_omr']);
$lines[] = '';
if (!empty($printOrdersDrift)) {
    $lines[] = 'Unsynced print orders:';
    foreach ($printOrdersDrift as $r) {
        $lines[] = sprintf('  - %s  %s  %s OMR  status=%s',
            $r['order_number'], $r['company_name'] ?? '—', number_format((float) $r['total'], 3), $r['erp_sync_status'] ?? '—');
    }
    $lines[] = '';
}
if (!empty($cardOrdersDrift)) {
    $lines[] = 'Unsynced card-credit payments:';
    foreach ($cardOrdersDrift as $r) {
        $lines[] = sprintf('  - %s  %s  %s OMR  %s',
            substr($r['id'], 0, 8), $r['company_name'] ?? '—', number_format((float) $r['amount'], 3), $r['created_at']);
    }
    $lines[] = '';
}
if (!empty($retries)) {
    $lines[] = 'Open retries:';
    foreach ($retries as $r) {
        $lines[] = sprintf('  - order=%d attempts=%d status=%s next=%s',
            (int) $r['order_id'], (int) $r['attempts'], $r['status'], $r['next_retry_at']);
    }
    $lines[] = '';
}
$lines[] = str_repeat('-', 64);
$lines[] = 'JSON: ' . json_encode($report, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
$text = implode("\n", $lines);
echo $text . "\n";

// --- Email ---
if ($sendMail) {
    $subject = sprintf('[Cardify × ERP] Reconciliation %s — drift=%d', $month, $report['drift_count']);
    $html    = '<pre style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;white-space:pre-wrap;">'
             . htmlspecialchars($text) . '</pre>';
    try {
        Mailer::send('ali@bhd.om', $subject, $html);
        echo "Email sent to ali@bhd.om\n";
    } catch (Throwable $e) {
        echo "Email failed: " . $e->getMessage() . "\n";
    }
}
