<?php
/**
 * Backfill BHD-ERP with historical card-credit purchases (Cat S 453).
 *
 * Finds `payments.type = 'card_order' AND status = 'paid'` rows whose
 * callback_data JSON doesn't yet contain an erp_invoice_number and
 * calls ERPSync::recordCardCreditPurchase() on each. 409 paths make
 * this safe to re-run; new rows pick up the sync on their way in via
 * Payment::confirmCardOrder() from here on.
 *
 * Usage: php scripts/erp-card-credit-backfill.php [--limit=100] [--dry]
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/ERPSync.php';

$limit = 100;
$dry   = false;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--dry') $dry = true;
    elseif (preg_match('/^--limit=(\d+)$/', $a, $m)) $limit = max(1, (int) $m[1]);
}

$db = Database::getInstance();
$rows = $db->fetchAll(
    "SELECT id, company_id, amount, created_at
       FROM payments
      WHERE type = 'card_order'
        AND status = 'paid'
        AND (callback_data IS NULL OR callback_data NOT LIKE '%erp_invoice_number%')
   ORDER BY created_at ASC
      LIMIT " . (int) $limit
);

$ok = 0; $fail = 0; $skip = 0;
foreach ($rows as $r) {
    echo "- payment={$r['id']} company={$r['company_id']} amount={$r['amount']} ";
    if ($dry) { echo "[dry]\n"; $skip++; continue; }
    try {
        $res = ERPSync::recordCardCreditPurchase($r['id']);
        if (!empty($res['success'])) { echo "OK\n"; $ok++; }
        else { echo "FAIL " . ($res['message'] ?? 'unknown') . "\n"; $fail++; }
    } catch (Throwable $e) {
        echo "EXC " . $e->getMessage() . "\n"; $fail++;
    }
}
echo "\nDone: ok={$ok} fail={$fail} skip={$skip} (of " . count($rows) . " candidates)\n";
