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
require_once INCLUDES_DIR . '/AuditLog.php';

$ctx = PrintShopAuth::requireInternalProvider();
$shop = $ctx['shop'];
$shopId = (int) $shop['id'];

$employeeId = trim($_GET['employee'] ?? '');
$companyId  = trim($_GET['company']  ?? '');
$orderId    = isset($_GET['order']) ? (int) $_GET['order'] : 0;
// Detect "caller didn't pass rows/cols" BEFORE the clamp (max(1,...)
// would otherwise rewrite an absent param to 1 and look like an
// explicit choice). Auto-fit walks densest-to-sparsest layouts that
// fit the card; explicit choice is honoured verbatim.
$autoFit = !isset($_GET['rows']) && !isset($_GET['cols']);
$rows = max(1, min(10, (int)($_GET['rows'] ?? 5)));
$cols = max(1, min(5,  (int)($_GET['cols'] ?? 2)));
$paper = $_GET['paper'] ?? 'A4';
if (!in_array($paper, ['A4', 'A3'], true)) $paper = 'A4';

if ($employeeId === '' || $companyId === '') {
    http_response_code(400);
    echo 'employee + company required';
    exit;
}

$db  = Database::getInstance();
$pdo = $db->getConnection();

// Sanity: employee must belong to the requested company
$check = $pdo->prepare("SELECT 1 FROM employees WHERE id = ? AND company_id = ? LIMIT 1");
$check->execute([$employeeId, $companyId]);
if (!$check->fetchColumn()) {
    http_response_code(404);
    echo 'employee not found';
    exit;
}

// Ownership gate: when the caller passes ?order=N, that order must
// belong to this print shop. This prevents one shop from generating
// production sheets for another shop's order. Internal-provider shops
// can still generate sample sheets for any company without ?order.
if ($orderId > 0) {
    $own = $pdo->prepare(
        "SELECT 1 FROM print_orders
         WHERE id = ? AND print_shop_id = ?
           AND employee_id = ? AND company_id = ?
         LIMIT 1"
    );
    $own->execute([$orderId, $shopId, $employeeId, $companyId]);
    if (!$own->fetchColumn()) {
        http_response_code(403);
        echo 'order does not belong to this shop';
        exit;
    }
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
$timeoutPrefix = (trim((string)@shell_exec('command -v timeout 2>/dev/null')) !== '') ? 'timeout 30 ' : '';

// Auto-fit: when caller didn't specify rows/cols, try the densest layout
// that fits the card. Falls back from 5x2 → 4x2 → 3x2 → 2x2 → 2x1 → 1x1.
$layouts = $autoFit ? [[5,2],[4,2],[3,2],[2,2],[2,1],[1,1]] : [[$rows,$cols]];
$rc = -1; $out = []; $finalRows = $rows; $finalCols = $cols;
foreach ($layouts as [$r, $c]) {
    $cmd = $timeoutPrefix . escapeshellarg($py)
         . ' ' . escapeshellarg(BASE_DIR . '/scripts/imposition-vector.py')
         . ' --card '  . escapeshellarg($rendered['path'])
         . ' --paper ' . escapeshellarg($paper)
         . ' --rows '  . $r
         . ' --cols '  . $c
         . ' --out '   . escapeshellarg($outputPath)
         . ' 2>&1';
    $out = []; $rc = 0;
    exec($cmd, $out, $rc);
    if ($rc === 0 && is_file($outputPath) && filesize($outputPath) >= 1024) {
        $finalRows = $r; $finalCols = $c;
        break;
    }
    @unlink($outputPath);
}

if ($rc !== 0 || !is_file($outputPath) || filesize($outputPath) < 1024) {
    $stderr = implode("\n", $out);
    error_log('printshop/print-sheet imposition failed rc=' . $rc . ' out=' . $stderr);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    // Surface the script's own error so the operator sees the real cause.
    // argparse prints multi-line usage + an `error:` summary on the LAST
    // line; the dimension-fit SystemExit prints a single line. Pull the
    // last non-empty line which is most informative either way.
    $lines = array_values(array_filter(array_map('trim', explode("\n", $stderr)), 'strlen'));
    $lastLine = end($lines) ?: 'imposition failed';
    echo 'imposition failed: ' . htmlspecialchars($lastLine, ENT_QUOTES);
    exit;
}
$rows = $finalRows; $cols = $finalCols;

AuditLog::log('print_sheet', 'print_order', $orderId > 0 ? (string) $orderId : null, null, [
    'shop_id'     => $shopId,
    'company_id'  => $companyId,
    'employee_id' => $employeeId,
    'paper'       => $paper,
    'rows'        => $rows,
    'cols'        => $cols,
]);

// Stream the PDF
$downloadName = sprintf('%s-%s-%dx%d.pdf', $shop['slug'] ?? 'print-shop', $slug, $rows, $cols);
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($outputPath));
readfile($outputPath);
exit;
