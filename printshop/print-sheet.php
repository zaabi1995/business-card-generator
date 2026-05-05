<?php
/**
 * Internal-provider mode: generate an A4 print sheet (5x2 = 10 cards
 * per side) for any employee. Bypasses the order-bound flow in
 * `api/print-ready.php` so operators can produce a sample sheet
 * without first placing an order. Uses the same engines under the
 * hood: CardPDFRenderer + scripts/imposition-vector.py.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/PrintShopAuth.php';
require_once INCLUDES_DIR . '/CardPDFRenderer.php';

$ctx = PrintShopAuth::requireInternalProvider();
$shop = $ctx['shop'];

$employeeId = trim($_GET['employee'] ?? '');
$companyId  = trim($_GET['company']  ?? '');
$rows = max(1, min(10, (int)($_GET['rows'] ?? 5)));
$cols = max(1, min(5,  (int)($_GET['cols'] ?? 2)));
$paper = in_array(($_GET['paper'] ?? 'A4'), ['A4', 'A3'], true) ? $_GET['paper'] : 'A4';

if ($employeeId === '' || $companyId === '') {
    http_response_code(400);
    echo 'employee + company required';
    exit;
}

$db  = Database::getInstance();
$pdo = $db->getConnection();

$check = $pdo->prepare("SELECT 1 FROM employees WHERE id = ? AND company_id = ? LIMIT 1");
$check->execute([$employeeId, $companyId]);
if (!$check->fetchColumn()) {
    http_response_code(404);
    echo 'employee not found';
    exit;
}

// Render the per-employee vector PDF (front + back), full font embed
// for production. CardPDFRenderer handles caching + per-version sha1.
$rendered = CardPDFRenderer::render($employeeId, 'print');
if (empty($rendered['success']) || empty($rendered['path']) || !is_file($rendered['path'])) {
    http_response_code(503);
    echo 'card PDF unavailable, vector source missing';
    exit;
}

// Imposition: rows x cols copies on a single A4
$outputDir = BASE_DIR . '/data/print-sheets';
if (!is_dir($outputDir)) {
    @mkdir($outputDir, 0775, true);
}
$slug = preg_replace('/[^a-z0-9]+/i', '-', $employeeId);
$filename = sprintf('print-sheet-%s-%s-%s.pdf', $slug, $paper, date('Ymd-His'));
$outputPath = $outputDir . '/' . $filename;

$py = trim((string)@shell_exec('command -v python3 2>/dev/null')) ?: 'python3';
$cmd = escapeshellarg($py)
     . ' ' . escapeshellarg(BASE_DIR . '/scripts/imposition-vector.py')
     . ' --card '  . escapeshellarg($rendered['path'])
     . ' --paper ' . escapeshellarg($paper)
     . ' --rows '  . $rows
     . ' --cols '  . $cols
     . ' --out '   . escapeshellarg($outputPath)
     . ' 2>&1';

if (trim((string)@shell_exec('command -v timeout 2>/dev/null')) !== '') {
    $cmd = 'timeout 30 ' . $cmd;
}

$rc = 0; $out = [];
exec($cmd, $out, $rc);

if ($rc !== 0 || !is_file($outputPath) || filesize($outputPath) < 1024) {
    error_log('printshop/print-sheet imposition failed rc=' . $rc . ' out=' . implode("\n", $out));
    http_response_code(500);
    echo 'imposition failed';
    exit;
}

// Stream the PDF
$downloadName = sprintf('%s-%s-%dx%d.pdf', $shop['slug'] ?? 'print-shop', $slug, $rows, $cols);
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($outputPath));
readfile($outputPath);
exit;
