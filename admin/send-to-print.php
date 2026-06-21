<?php
/**
 * "Send to Print" — push one employee's business card to BHD's production Kanban.
 *
 * POST { employee_id, quantity } (CSRF + admin, company-scoped).
 * Validates the quantity against the company's PO remaining (Print Tracking),
 * calls ERPSync::createProductionOrder (production-only SO + MO, no invoice/JE),
 * logs the run against the PO, and returns the ERP SO/MO numbers.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/ERPSync.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST only']);
    exit;
}

requireAdmin();
$companyId = getCurrentCompanyId();
if (!$companyId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'No company context']);
    exit;
}
try {
    validateCSRFToken($_POST['csrf_token'] ?? '');
} catch (Throwable $e) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid session, refresh and retry']);
    exit;
}

$employeeId = trim($_POST['employee_id'] ?? '');
$quantity   = (int)($_POST['quantity'] ?? 0);
if ($employeeId === '' || $quantity <= 0) {
    echo json_encode(['success' => false, 'message' => 'Employee and a quantity > 0 are required']);
    exit;
}

$db = Database::getInstance();

// Employee must belong to this company.
$emp = $db->fetchOne(
    "SELECT id, name_en, name_ar FROM employees WHERE id = :e AND company_id = :c AND deleted_at IS NULL",
    ['e' => $employeeId, 'c' => $companyId]
);
if (!$emp) {
    echo json_encode(['success' => false, 'message' => 'Employee not found']);
    exit;
}

$company = $db->fetchOne(
    "SELECT id, name, slug, erp_client_name FROM companies WHERE id = :c",
    ['c' => $companyId]
);

// PO remaining = ordered - printed (Print Tracking).
$po = $db->fetchOne(
    "SELECT COALESCE(SUM(quantity),0) AS ordered, MAX(po_number) AS po_number
       FROM company_print_pos WHERE company_id = :c",
    ['c' => $companyId]
);
$printed = $db->fetchOne(
    "SELECT COALESCE(SUM(quantity),0) AS printed FROM company_print_runs WHERE company_id = :c",
    ['c' => $companyId]
);
$ordered   = (int)($po['ordered'] ?? 0);
$remaining = $ordered - (int)($printed['printed'] ?? 0);

// Per-card selling price from the uploaded PO (data-driven): the unit_price
// column, else parsed from the PO note ("@ 0.040 OMR"), else a safe default.
$poRow = $db->fetchOne(
    "SELECT unit_price, note FROM company_print_pos
       WHERE company_id = :c AND po_number = :p ORDER BY created_at DESC LIMIT 1",
    ['c' => $companyId, 'p' => (string)($po['po_number'] ?? '')]
);
$poUnitPrice = 0.0;
if ($poRow && $poRow['unit_price'] !== null && (float)$poRow['unit_price'] > 0) {
    $poUnitPrice = (float)$poRow['unit_price'];
} elseif ($poRow && preg_match('/@\s*([0-9]*\.?[0-9]+)\s*OMR/i', (string)($poRow['note'] ?? ''), $m)) {
    $poUnitPrice = (float)$m[1];
}
$poNumber  = (string)($po['po_number'] ?? '');

if ($ordered <= 0) {
    echo json_encode(['success' => false, 'message' => 'No purchase order on file for this company. Add a PO in Print Tracking first.']);
    exit;
}
if ($quantity > $remaining) {
    echo json_encode([
        'success' => false,
        'message' => "Quantity ($quantity) exceeds the PO remaining ($remaining). Adjust or add a PO.",
        'remaining' => $remaining,
    ]);
    exit;
}

// Resolve the ERP client. Prefer the company's explicit erp_client_name; the ERP
// also fuzzy-matches, but an explicit name is safest.
$clientName = trim((string)($company['erp_client_name'] ?? '')) ?: trim((string)($company['name'] ?? ''));

$nameEn = trim((string)($emp['name_en'] ?? '')) ?: 'Employee';
$cardLabel = 'Business Cards - ' . $nameEn;
$printReadyUrl = 'https://' . ($company['slug'] ?? 'app') . '.cardify.om/card-sheet.php?i=' . urlencode($employeeId);
$orderRef = 'CARDPROD-' . $employeeId . '-' . time();

// Selling price per card (ex-VAT) straight from the client's PO; the ERP adds
// 5% VAT on top. COGS is BOM-driven in the ERP, so the line cost is left 0.
$unitPrice = $poUnitPrice > 0 ? $poUnitPrice : 0.040;
$unitCost  = 0.0;

$erp = ERPSync::createProductionOrder([
    'clientName'    => $clientName,
    'orderRef'      => $orderRef,
    'cardLabel'     => $cardLabel,
    'quantity'      => $quantity,
    'unitPrice'     => $unitPrice,
    'unitCost'      => $unitCost,
    'printReadyUrl' => $printReadyUrl,
    'poNumber'      => $poNumber,
    'productName'   => 'Business Card',
    'notes'         => 'Cardify Send to Print',
]);

if (empty($erp['success'])) {
    echo json_encode(['success' => false, 'message' => $erp['message'] ?? 'Could not send to production']);
    exit;
}

$d = $erp['data'] ?? [];
$soNumber = (string)($d['soNumber'] ?? '');

// Log the run against the PO (decrements remaining) + record the ERP refs.
try {
    $db->insert('company_print_runs', [
        'id'          => generateUUID(),
        'company_id'  => $companyId,
        'employee_id' => $employeeId,
        'quantity'    => $quantity,
        'note'        => 'Sent to production' . ($soNumber ? " ($soNumber)" : ''),
        'created_by'  => (string)(Auth::getCurrentUser()['id'] ?? 'admin'),
        'created_at'  => date('Y-m-d H:i:s'),
    ]);
} catch (Throwable $e) {
    error_log('send-to-print: print-run log failed: ' . $e->getMessage());
}

echo json_encode([
    'success'  => true,
    'message'  => 'Sent to production: ' . ($d['message'] ?? $soNumber),
    'soNumber' => $soNumber,
    'mos'      => $d['manufacturingOrders'] ?? [],
    'remaining' => $remaining - $quantity,
]);
