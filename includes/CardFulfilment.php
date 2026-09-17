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
        require_once __DIR__ . '/CardPrice.php';
        require_once __DIR__ . '/CardThumb.php';

        $db  = Database::getInstance();
        $out = ['quoted' => false, 'order' => null, 'quote' => null, 'error' => null];

        $dept = !empty($request['department_id']) ? $db->fetchOne(
            "SELECT * FROM departments WHERE id = :did AND company_id = :cid",
            ['did' => $request['department_id'], 'cid' => $request['company_id']]) : null;
        if (!$dept || empty($dept['responsible_email'])) {
            return $out;   // not a division that runs this flow
        }

        CardJob::transition((string)$request['id'], 'approved', [
            'actor' => $actor, 'employee' => $chain['employee_id'] ?? null,
        ]);

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
        $fields = [
            'department_id'     => $request['department_id'],
            'quantity'          => $price['qty'],
            'paper_type'        => 'Art 300 GSM',
            'finish'            => 'matte',
            'total'             => $price['gross'],
            'subtotal_excl_vat' => $price['net'],
            'tax_rate'          => CardPrice::VAT_RATE,
            'tax_amount'        => $price['vat'],
            'erp_client_name'   => $dept['erp_client_name'],
        ];
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
        } else {
            // Log and carry on. The division is asked for the purchase order
            // either way: a quote that has not reached the ERP is BHD's problem,
            // not theirs, and the order stays on print_orders with no quote id.
            $out['error'] = $quote['message'] ?? 'unknown';
            error_log('[mhd quote] order ' . $orderId . ': ' . $out['error']);
        }

        CardJobMailer::sendQuotation($request, $dept, $price, $quote['data'] ?? []);
        return $out;
    }

    /**
     * @param  array $job a card_requests row already at po_received
     * @return array ['invoice'=>?string,'documents'=>bool,'production'=>bool,'state'=>string,'errors'=>array]
     */
    public static function afterPo(array $job, bool $announce = true): array
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

        // 2. MHD get the quotation, the invoice and the delivery note, as files,
        //    with a link that signs the delivery note in one click.
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

        // 3. Production get the print-ready artwork.
        $prod = self::handToProduction($job, $dept ?: []);
        $out['production'] = !empty($prod['ok']);
        if (!$out['production']) { $out['errors'][] = 'production: ' . ($prod['error'] ?? 'unknown'); }

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
        $pdf = CardPDFRenderer::render($employeeId, 'print', ['include_qr' => true]);
        if (empty($pdf['success'])) {
            return ['ok' => false, 'error' => 'render: ' . ($pdf['error'] ?? 'unknown')];
        }
        return CardJobMailer::sendToProduction($job, $dept, (string)$pdf['path']);
    }
}
