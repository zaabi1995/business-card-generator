<?php
/**
 * Serve one document of one card job.
 *
 * The purchase orders and signed delivery notes live in private/, which nginx
 * refuses to serve, and the ERP documents need a token nobody else should hold.
 * So every file goes through here, where the caller is an authenticated admin of
 * the company that owns the job, and nothing else.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/ERPSync.php';
require_once INCLUDES_DIR . '/DeliverySignature.php';
require_once INCLUDES_DIR . '/CardPDFRenderer.php';

requireAdmin();
$companyId = getCurrentCompanyId();
if (!$companyId) { http_response_code(403); exit('Forbidden'); }

$id   = (string)($_GET['id'] ?? '');
$kind = (string)($_GET['kind'] ?? '');
$db   = Database::getInstance();

$job = $db->fetchOne(
    "SELECT cr.*, po.erp_quote_id, po.erp_invoice_id, po.delivery_note_external_id
       FROM card_requests cr
       LEFT JOIN print_orders po ON po.id = cr.erp_order_id
      WHERE cr.id = :id AND cr.company_id = :cid",
    ['id' => $id, 'cid' => $companyId]);
if (!$job) { http_response_code(404); exit('Not found'); }

$ref  = (string)($job['job_ref'] ?? 'card-job');
$path = null;
$name = $ref . '-' . $kind . '.pdf';
$temp = false;

switch ($kind) {
    case 'po':
        $path = (string)($job['po_file'] ?? '');
        $name = $ref . '-purchase-order.pdf';
        break;

    case 'signed':
        $dir  = DeliverySignature::dir();
        foreach (glob($dir . '/*/*/' . $ref . '-delivery-note-signed.pdf') ?: [] as $f) { $path = $f; }
        $name = $ref . '-delivery-note-signed.pdf';
        break;

    case 'quote':
    case 'invoice':
    case 'deliverynote':
        $map = ['quote' => 'erp_quote_id', 'invoice' => 'erp_invoice_id',
                'deliverynote' => 'delivery_note_external_id'];
        $docId = (string)($job[$map[$kind]] ?? '');
        if ($docId !== '') {
            $path = ERPSync::fetchDocumentPdf($kind, $docId, $ref . '-' . $kind);
            $temp = true;
        }
        break;

    case 'artwork':
        $employeeId = (string)($job['employee_id'] ?? '');
        if ($employeeId !== '') {
            $pdf = CardPDFRenderer::render($employeeId, 'print', ['include_qr' => true]);
            if (!empty($pdf['success'])) { $path = (string)$pdf['path']; }
        }
        $name = $ref . '-print-ready.pdf';
        break;
}

if (!$path || !is_file($path)) {
    http_response_code(404);
    exit('That document is not available yet');
}

header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
if ($temp) { @unlink($path); }
