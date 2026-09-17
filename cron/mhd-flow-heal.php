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
require_once INCLUDES_DIR . '/CardJob.php';

require_once INCLUDES_DIR . '/CardJobMailer.php';
require_once INCLUDES_DIR . '/CardPrice.php';
require_once INCLUDES_DIR . '/ERPSync.php';
require_once INCLUDES_DIR . '/CardThumb.php';

$db = Database::getInstance();

// 1. Approved, but the ERP never raised the quotation. The division has not
//    been asked for anything yet, so raising it now completes the step exactly
//    as approval would have.
$stuck = $db->fetchAll(
    "SELECT cr.* FROM card_requests cr
       JOIN print_orders po ON po.id = cr.erp_order_id
      WHERE cr.fulfilment_state = 'approved'
        AND (po.erp_quote_id IS NULL OR po.erp_quote_id = '')
        AND cr.submitted_at > DATE_SUB(NOW(), INTERVAL 60 DAY)
      ORDER BY cr.submitted_at ASC
      LIMIT 20");

foreach ($stuck as $job) {
    $dept = $db->fetchOne("SELECT * FROM departments WHERE id = :d", ['d' => $job['department_id']]);
    if (!$dept || empty($dept['responsible_email'])) { continue; }
    // Approval may be quoting this job right now. Take the job, then check it
    // still needs a quote, or both would raise one and send two quotations.
    if (!CardJob::lock((string)$job['id'])) { continue; }
    $still = $db->fetchOne(
        "SELECT cr.fulfilment_state s, po.erp_quote_id q FROM card_requests cr
           JOIN print_orders po ON po.id = cr.erp_order_id WHERE cr.id = :id",
        ['id' => $job['id']]);
    if (($still['s'] ?? '') !== 'approved' || !empty($still['q'])) {
        CardJob::unlock((string)$job['id']);
        continue;
    }
    $orderId = (int)$job['erp_order_id'];
    $quote   = ERPSync::isEnabled() ? ERPSync::createQuote($orderId) : ['success' => false, 'message' => 'erp disabled'];
    $line    = ['ref' => $job['job_ref'], 'step' => 'quote'];
    if (!empty($quote['success'])) {
        try {
            $qid   = (string)($quote['data']['quoteId'] ?? '');
            $thumb = $qid !== '' ? CardThumb::forRequest($job) : null;
            if ($thumb) { ERPSync::setQuoteItemImage($qid, $thumb); @unlink($thumb); }
        } catch (Throwable $e) {
            error_log('[mhd heal image] ' . $e->getMessage());
        }
        $moved = CardJob::transition((string)$job['id'], 'quoted', [
            'actor' => 'heal', 'order' => $orderId, 'quote' => $quote['data']['quoteId'] ?? null,
        ]);
        // Only the worker that moved the job sends the quotation.
        if ($moved) {
            $qty   = (int)($job['quantity_ordered'] ?? CardPrice::DEFAULT_QTY);
            if (!CardPrice::isStandardQuantity($qty)) { $qty = CardPrice::DEFAULT_QTY; }
            $price = CardPrice::quote($qty, (float)($dept['card_unit_price'] ?? CardPrice::UNIT_PRICE));
            CardJobMailer::sendQuotation($job, $dept, $price, $quote['data'] ?? []);
        }
        $line['state'] = $moved ? 'quoted' : 'not moved';
        $line['quote'] = $quote['data']['quoteNumber'] ?? null;
    } else {
        $line['state'] = 'approved';
        $line['error'] = $quote['message'] ?? 'unknown';
    }
    CardJob::unlock((string)$job['id']);
    echo date('c') . ' ' . json_encode($line, JSON_UNESCAPED_UNICODE) . "\n";
}

// 2. The purchase order arrived and the invoice did not.
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
        'step'    => 'invoice',
        'state'   => $r['state'],
        'invoice' => $r['invoice'],
        'errors'  => $r['errors'],
    ], JSON_UNESCAPED_UNICODE) . "\n";
}
