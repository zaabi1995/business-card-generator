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
     * @param  array $job a card_requests row already at po_received
     * @return array ['invoice'=>?string,'documents'=>bool,'production'=>bool,'state'=>string,'errors'=>array]
     */
    public static function afterPo(array $job): array
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
            $out['errors'][] = 'invoice: ' . ($inv['message'] ?? 'unknown');
            error_log('[mhd afterPo] order ' . $orderId . ' invoice failed: ' . ($inv['message'] ?? ''));
            return $out;   // no documents to send yet; the retry queue owns it now
        }
        $order = $db->fetchOne(
            "SELECT erp_quote_id, erp_invoice_id, erp_invoice_number, delivery_note_external_id
               FROM print_orders WHERE id = :id", ['id' => $orderId]);
        $out['invoice'] = $order['erp_invoice_number'] ?? null;

        // 2. MHD get the quotation, the invoice and the delivery note, as files.
        $docs = CardJobMailer::sendDocuments($job, $dept ?: [], [
            'quoteId'       => $order['erp_quote_id'] ?? '',
            'invoiceId'     => $order['erp_invoice_id'] ?? '',
            'invoiceNumber' => $order['erp_invoice_number'] ?? '',
            'deliveryId'    => $order['delivery_note_external_id'] ?? '',
            'po'            => $job['po_number'] ?? '',
        ]);
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
     * WhatsApp is where BHD's production team work, but the number is not
     * configured yet and a phone number is never guessed, so this goes by email
     * until mhd_production_whatsapp is set.
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
