<?php
/**
 * Pick up card jobs whose invoice could not be raised.
 *
 * A purchase order is filed the moment it arrives, then the ERP is asked for
 * the invoice. That call can refuse: the ERP was down, or the division's
 * account is credit blocked, which three MHD accounts are. The job holds at
 * po_received, and this brings it back every quarter of an hour so it completes
 * itself as soon as the cause is cleared, without anyone re-sending anything.
 *
 * afterPo() is safe to repeat: the ERP conversion is idempotent, and a job that
 * has moved past po_received is not picked up at all.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/CardFulfilment.php';

$db   = Database::getInstance();
$jobs = $db->fetchAll(
    "SELECT * FROM card_requests
      WHERE fulfilment_state = 'po_received'
        AND erp_order_id IS NOT NULL
        AND submitted_at > DATE_SUB(NOW(), INTERVAL 60 DAY)
      ORDER BY submitted_at ASC
      LIMIT 20");

foreach ($jobs as $job) {
    // announce=false: the division was told once, when the order arrived.
    $r = CardFulfilment::afterPo($job, false);
    echo date('c') . ' ' . json_encode([
        'ref'     => $job['job_ref'],
        'state'   => $r['state'],
        'invoice' => $r['invoice'],
        'errors'  => $r['errors'],
    ], JSON_UNESCAPED_UNICODE) . "\n";
}
