<?php
require_once __DIR__ . '/CardJob.php';
require_once __DIR__ . '/CardJobMailer.php';
require_once __DIR__ . '/ERPSync.php';
require_once __DIR__ . '/CardPDFRenderer.php';

/**
 * CardFulfilment, everything that happens once MHD's purchase order lands.
 *
 * The chain is: convert the ERP quote into an invoice, a sales order and a
 * delivery note; send MHD the three documents; hand the print-ready artwork to
 * production; move the job to in_production.
 *
 * Every step is best effort and says so in its result. A purchase order that
 * arrived must never be lost because the ERP was slow, so the job keeps its
 * po_received state and the retry queue picks the conversion up later.
 */
class CardFulfilment
{
    /**
     * Everything that happens the moment a division approves a card.
     *
     * Called from both approval paths, the one-tap link in the email and the
     * full review page, so a quotation goes out whichever way the approver
     * said yes. Does nothing for a department with no approver, which is every
     * tenant that is not MHD.
     *
     * @param  array $request the card_requests row
     * @param  array $chain   the approveRequestChain result
     * @param  string $actor  who approved
     * @return array ['quoted'=>bool,'order'=>?int,'quote'=>?string,'error'=>?string]
     */
    public static function afterApproval(array $request, array $chain, string $actor): array
    {
        $id = (string)($request['id'] ?? '');
        if ($id === '' || !CardJob::lock($id)) {
            return ['quoted' => false, 'order' => null, 'quote' => null,
                    'error' => 'job is being worked on by another process'];
        }
        try {
            return self::afterApprovalLocked($request, $chain, $actor);
        } finally {
            CardJob::unlock($id);
        }
    }

    /**
     * The division whose approver runs this flow for a request: the request's
     * own division, else the tenant's only division when exactly one has an
     * approver (OHB). MHD has many divisions, so a request without one is never
     * guessed onto one. Null when the flow does not apply.
     */
    public static function approvalDepartment(array $request): ?array
    {
        $db  = Database::getInstance();
        $cid = (string)($request['company_id'] ?? '');
        if (!empty($request['department_id'])) {
            $dept = $db->fetchOne(
                "SELECT * FROM departments WHERE id = :did AND company_id = :cid",
                ['did' => $request['department_id'], 'cid' => $cid]);
            return ($dept && !empty($dept['responsible_email'])) ? $dept : null;
        }
        $rows = $db->fetchAll(
            "SELECT * FROM departments WHERE company_id = :cid AND deleted_at IS NULL
                AND responsible_email IS NOT NULL AND responsible_email <> ''",
            ['cid' => $cid]);
        $all = (int)($db->fetchOne(
            "SELECT COUNT(*) n FROM departments WHERE company_id = :cid AND deleted_at IS NULL",
            ['cid' => $cid])['n'] ?? 0);
        return (count($rows) === 1 && $all === 1) ? $rows[0] : null;
    }

    private static function afterApprovalLocked(array $request, array $chain, string $actor): array
    {
        require_once __DIR__ . '/CardPrice.php';
        require_once __DIR__ . '/CardThumb.php';

        $db  = Database::getInstance();
        $out = ['quoted' => false, 'order' => null, 'quote' => null, 'error' => null];

        $dept = self::approvalDepartment($request);
        if (!$dept) {
            return $out;   // not a division that runs this flow
        }
        if (empty($request['department_id'])) {
            // Single-division tenant (OHB): its portal has no division picker,
            // so the request arrives without one. Record the division it ran on.
            $db->query("UPDATE card_requests SET department_id = ? WHERE id = ?",
                       [$dept['id'], $request['id']]);
            $request['department_id'] = $dept['id'];
        }

        // If this job is not the one moving out of submitted, another caller is
        // already doing this work: a double click, or a retry. Stop, rather than
        // raise a second quote and send a second quotation.
        if (!CardJob::transition((string)$request['id'], 'approved', [
                'actor' => $actor, 'employee' => $chain['employee_id'] ?? null])) {
            $state = (string)(Database::getInstance()->fetchOne(
                "SELECT fulfilment_state s FROM card_requests WHERE id = :id",
                ['id' => $request['id']])['s'] ?? '');
            if ($state !== 'approved') {
                $out['error'] = 'already past approval (' . $state . ')';
                return $out;
            }
        }

        $qty = (int)($request['quantity_ordered'] ?? CardPrice::DEFAULT_QTY);
        if (!CardPrice::isStandardQuantity($qty)) { $qty = CardPrice::DEFAULT_QTY; }
        $price = CardPrice::quote($qty, (float)($dept['card_unit_price'] ?? CardPrice::UNIT_PRICE));

        // erp_client_name carries the DIVISION's account: ERPSync resolves the
        // client from the order, and without it every division would quote
        // against the parent company.
        //
        // The approval chain has already placed a print order for BHD when the
        // approver chose "send to print". Correct that one rather than adding a
        // second: two orders meant two ERP quotes for one card, at two prices.
        // Every money column on the order, not just the total: the approval
        // chain fills subtotal, setup_fee and shipping_fee from BHD's own
        // print-shop price list, and leaving those behind made the order read
        // "subtotal 9.000 plus shipping 2.000 equals total 6.300".
        $fields = [
            'department_id'     => $request['department_id'],
            'quantity'          => $price['qty'],
            'paper_type'        => 'Art 300 GSM',
            'finish'            => 'matte',
            'subtotal'          => $price['net'],
            'setup_fee'         => 0,
            'shipping_fee'      => 0,
            'total'             => $price['gross'],
            'subtotal_excl_vat' => $price['net'],
            'tax_rate'          => CardPrice::VAT_RATE,
            'tax_amount'        => $price['vat'],
            'erp_client_name'   => $dept['erp_client_name'],
        ];
        // Only the columns this install actually has.
        $db2 = Database::getInstance();
        foreach (['subtotal', 'setup_fee', 'shipping_fee'] as $col) {
            if (!$db2->columnExists('print_orders', $col)) { unset($fields[$col]); }
        }
        $orderId = (int)($chain['print_order_id'] ?? 0) ?: (int)($request['print_order_id'] ?? 0);
        if ($orderId) {
            $set = [];
            $args = [];
            foreach ($fields as $k => $v) { $set[] = "`{$k}` = ?"; $args[] = $v; }
            $args[] = $orderId;
            $db->query("UPDATE print_orders SET " . implode(', ', $set) . " WHERE id = ?", $args);
        } else {
            $db->insert('print_orders', $fields + [
                'company_id'   => $request['company_id'],
                'order_number' => $request['job_ref'] ?: ('MHD-' . substr((string)$request['id'], 0, 6)),
                'status'       => 'pending',
            ]);
            $orderId = (int)$db->getConnection()->lastInsertId();
        }
        $db->query("UPDATE card_requests SET erp_order_id = ? WHERE id = ?", [$orderId, $request['id']]);
        $out['order'] = $orderId;

        $quote = ERPSync::isEnabled()
            ? ERPSync::createQuote($orderId)
            : ['success' => false, 'message' => 'erp disabled'];

        if (!empty($quote['success'])) {
            // Ali, 16 Sep 2026: show the card on the quotation. The ERP
            // propagates the picture to the invoice, the sales order, the
            // delivery note and the manufacturing order on its own.
            try {
                $qid = (string)($quote['data']['quoteId'] ?? '');
                // array_merge, not +: the request row already HAS an
                // employee_id key holding NULL, and the union operator keeps
                // the left side's key, so the picture silently fell back to
                // nothing whenever the portal had not saved a preview.
                $forThumb = array_merge($request, ['employee_id' => $chain['employee_id'] ?? '']);
                $thumb = $qid !== '' ? CardThumb::forRequest($forThumb) : null;
                if ($thumb) {
                    ERPSync::setQuoteItemImage($qid, $thumb);
                    @unlink($thumb);
                }
            } catch (Throwable $e) {
                error_log('[mhd quote image] ' . $e->getMessage());
            }
            CardJob::transition((string)$request['id'], 'quoted', [
                'actor' => 'system', 'order' => $orderId,
                'quote' => $quote['data']['quoteId'] ?? null,
            ]);
            $out['quoted'] = true;
            $out['quote']  = $quote['data']['quoteNumber'] ?? null;
            CardJobMailer::sendQuotation($request, $dept, $price, $quote['data'] ?? []);

            // A division that is invoiced on approval (OHB) sends no purchase
            // order, so there is nothing to wait for: go straight on to the
            // invoice, the documents email and production. The Cardify order
            // number stands in for the PO reference.
            if (!empty($dept['invoice_without_po'])) {
                $ref = (string)($request['job_ref'] ?: $orderId);
                if (CardJob::transition((string)$request['id'], 'po_received', [
                        'actor' => 'invoice_without_po', 'po' => $ref])) {
                    $db->query("UPDATE card_requests SET po_number = ? WHERE id = ?", [$ref, $request['id']]);
                    $job = $db->fetchOne("SELECT * FROM card_requests WHERE id = :id", ['id' => $request['id']]);
                    CardJob::unlock((string)$request['id']);
                    $out['invoiced'] = self::afterPo($job ?: $request, true);
                }
            }
        } else {
            // No quote, so no quotation. Asking for a purchase order against a
            // quote that does not exist is worse than saying nothing: the reply
            // would arrive, the intake would refuse it because the job never
            // reached 'quoted', and MHD would be waiting on an order nobody
            // holds. BHD is told instead, and cron/mhd-flow-heal.php raises the
            // quote as soon as the cause is cleared.
            $out['error'] = $quote['message'] ?? 'unknown';
            error_log('[mhd quote] order ' . $orderId . ': ' . $out['error']);
            CardJobMailer::sendQuoteHeldAlert($request, $dept, $out['error']);
        }

        return $out;
    }

    /**
     * @param  array $job a card_requests row already at po_received
     * @return array ['invoice'=>?string,'documents'=>bool,'production'=>bool,'state'=>string,'errors'=>array]
     */
    public static function afterPo(array $job, bool $announce = true): array
    {
        $id = (string)($job['id'] ?? '');
        if ($id === '' || !CardJob::lock($id)) {
            return ['invoice' => null, 'documents' => false, 'production' => false,
                    'state' => (string)($job['fulfilment_state'] ?? ''),
                    'errors' => ['job is being worked on by another process']];
        }
        try {
            // Re-read under the lock: the other worker may have finished it.
            $fresh = Database::getInstance()->fetchOne(
                "SELECT * FROM card_requests WHERE id = :id", ['id' => $id]);
            if (!$fresh || ($fresh['fulfilment_state'] ?? '') !== 'po_received') {
                return ['invoice' => null, 'documents' => false, 'production' => false,
                        'state' => (string)($fresh['fulfilment_state'] ?? ''),
                        'errors' => []];
            }
            return self::afterPoLocked($fresh, $announce);
        } finally {
            CardJob::unlock($id);
        }
    }

    private static function afterPoLocked(array $job, bool $announce): array
    {
        $out = ['invoice' => null, 'documents' => false, 'production' => false,
                'state' => (string)($job['fulfilment_state'] ?? ''), 'errors' => []];
        $db = Database::getInstance();

        $dept = !empty($job['department_id']) ? $db->fetchOne(
            "SELECT * FROM departments WHERE id = :d", ['d' => $job['department_id']]) : null;
        $orderId = (int)($job['erp_order_id'] ?? 0);
        if (!$orderId) {
            $out['errors'][] = 'no print order on the job';
            return $out;
        }

        // 1. The ERP raises the invoice, the sales order and the delivery note.
        $inv = ERPSync::convertQuoteToInvoice($orderId, 'po');
        if (empty($inv['success'])) {
            $reason = (string)($inv['message'] ?? 'unknown');
            $out['errors'][] = 'invoice: ' . $reason;
            error_log('[mhd afterPo] order ' . $orderId . ' invoice failed: ' . $reason);
            // Say so. Silence here is the one outcome nobody can act on: the
            // division has sent a purchase order and hears nothing, and BHD
            // never learns the invoice is held. cron/mhd-flow-heal.php picks
            // the job up again every quarter of an hour.
            if ($announce && $dept) {
                CardJobMailer::sendPoAcknowledgement($job, $dept, (string)($job['po_number'] ?? ''), $reason);
            }
            return $out;
        }
        $order = $db->fetchOne(
            "SELECT erp_quote_id, erp_invoice_id, erp_invoice_number, delivery_note_external_id
               FROM print_orders WHERE id = :id", ['id' => $orderId]);
        $out['invoice'] = $order['erp_invoice_number'] ?? null;

        // 2. Production get the print-ready artwork. Before the documents,
        //    because the documents email tells MHD the card is in production
        //    and that has to be true when they read it.
        $prod = self::handToProduction($job, $dept ?: []);
        $out['production'] = !empty($prod['ok']);
        if (!$out['production']) { $out['errors'][] = 'production: ' . ($prod['error'] ?? 'unknown'); }

        // in_production has to mean it: the job only moves when the artwork has
        // actually reached production. It used to move either way, so a render
        // that failed left a job reading "in production" with nothing printing
        // and nothing retrying.
        if (!$out['production']) {
            error_log('[mhd afterPo] job ' . $job['id'] . ' held: ' . ($prod['error'] ?? 'unknown'));
            return $out;
        }
        // 3. MHD get the quotation, the invoice and the delivery note, as
        //    files, with a link that signs the delivery note in one click.
        $signUrl = null;
        if ($dept && !empty($dept['responsible_email'])) {
            require_once __DIR__ . '/DeliverySignature.php';
            $slug = $db->fetchOne("SELECT slug FROM companies WHERE id = :c",
                                  ['c' => $job['company_id']])['slug'] ?? 'mhd';
            $signUrl = DeliverySignature::mintLink($job + ['company_slug' => $slug],
                                                   (string)$dept['responsible_email']);
        }
        $docs = CardJobMailer::sendDocuments($job, $dept ?: [], [
            'quoteId'       => $order['erp_quote_id'] ?? '',
            'invoiceId'     => $order['erp_invoice_id'] ?? '',
            'invoiceNumber' => $order['erp_invoice_number'] ?? '',
            'deliveryId'    => $order['delivery_note_external_id'] ?? '',
            'po'            => $job['po_number'] ?? '',
        ], $signUrl);
        $out['documents'] = !empty($docs['ok']);
        if (!$out['documents']) { $out['errors'][] = 'documents: ' . ($docs['error'] ?? 'unknown'); }

        if (CardJob::transition((string)$job['id'], 'in_production', [
            'actor' => 'system', 'invoice' => $out['invoice'], 'po' => $job['po_number'] ?? null,
        ])) {
            $out['state'] = 'in_production';
        }
        return $out;
    }

    /**
     * Render the card exactly as it will print and send it to production.
     *
     * By email, which is what Ali chose on 16 Sep 2026 when asked whether the
     * artwork should reach production on WhatsApp instead.
     */
    public static function handToProduction(array $job, array $dept): array
    {
        $employeeId = trim((string)($job['employee_id'] ?? ''));
        if ($employeeId === '') {
            return ['ok' => false, 'error' => 'the job has no employee to render'];
        }
        // The card that prints is the card that was approved: if the employee
        // unticked the QR, production must not get one. include_qr is null on
        // requests made before it was recorded, and those keep the old default.
        $wantQr = array_key_exists('include_qr', $job) && $job['include_qr'] !== null
                ? (bool)$job['include_qr']
                : true;
        $pdf = CardPDFRenderer::render($employeeId, 'print', [
            'include_qr'       => $wantQr,
            'qr_force_allowed' => $wantQr,
        ]);
        if (empty($pdf['success'])) {
            return ['ok' => false, 'error' => 'render: ' . ($pdf['error'] ?? 'unknown')];
        }

        // Ali, 17 Sep 2026: production want it on A4. Both sides at exact size
        // on one sheet with crop marks, so what is measured on the sheet is what
        // prints. If the imposition fails, the press-size file still goes: a
        // printable card beats no card.
        $sheet = self::a4Sheet((string)$pdf['path'], $job, $dept);
        $send  = CardJobMailer::sendToProduction($job, $dept, $sheet ?: (string)$pdf['path']);
        if ($sheet) { @unlink($sheet); }
        return $send;
    }

    /** Lay the card out on A4 for the press. Returns null if it cannot. */
    private static function a4Sheet(string $cardPdf, array $job, array $dept): ?string
    {
        $script = BASE_DIR . '/scripts/mhd/impose-a4.py';
        if (!is_file($script) || !is_file($cardPdf)) { return null; }

        $ref     = (string)($job['job_ref'] ?? 'card');
        $out     = sys_get_temp_dir() . '/a4-' . preg_replace('/[^A-Za-z0-9._-]/', '-', $ref) . '.pdf';
        $caption = sprintf('%s  %s  %s  %d cards  Art 300 GSM matte, double sided',
            $ref,
            trim((string)($job['name_en'] ?? '')),
            (string)($dept['name'] ?? ''),
            (int)($job['quantity_ordered'] ?? 0));

        shell_exec('python3 ' . escapeshellarg($script) . ' ' . escapeshellarg($cardPdf) . ' '
                   . escapeshellarg($out) . ' ' . escapeshellarg($caption) . ' 2>/dev/null');
        return is_file($out) ? $out : null;
    }
}
