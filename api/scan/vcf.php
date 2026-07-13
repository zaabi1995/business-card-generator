<?php
/**
 * GET /api/scan/vcf.php?id=N, download a rolodex entry as vCard 3.0
 *
 * Query: id -> Content-Type: text/vcard, Content-Disposition: attachment
 *
 * Filename derived from parsed name: ASCII-transliterate, strip to [A-Za-z0-9 _-],
 * trim, fall back to 'contact', append .vcf.
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';
require_once INCLUDES_DIR . '/ScanVcf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Content-Type: application/json');
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'GET only']);
    exit;
}

$ctx = ScanAuth::requireEmployee();
$id = (int)($_GET['id'] ?? 0);

try {
    $db = Database::getInstance();
    $scan = $db->fetchOne(
        "SELECT parsed, met_where, met_at FROM scans WHERE id = :i AND employee_id = :e AND status != 'deleted'",
        ['i' => $id, 'e' => $ctx['employee_id']]
    );
} catch (\Throwable $e) {
    error_log('[scan/vcf] ' . $e->getMessage());
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
    exit;
}

if (!$scan) {
    header('Content-Type: application/json');
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Not found']);
    exit;
}

$parsed = json_decode($scan['parsed'], true) ?: [];
$note = trim(implode(' ', array_filter(['Met', $scan['met_where'] ? 'at ' . $scan['met_where'] : '', $scan['met_at'] ? 'on ' . $scan['met_at'] : ''])));
$vcf = ScanVcf::build($parsed, $note !== 'Met' ? $note : null);

// Derive filename from parsed name
$name = $parsed['name_en'] ?: ($parsed['name_ar'] ?? '');
// ASCII-transliterate and strip to [A-Za-z0-9 _-]
$filename = preg_replace('/[^A-Za-z0-9 _-]/', '', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name ?: ''));
$filename = trim($filename) ?: 'contact';
$filename .= '.vcf';

header('Content-Type: text/vcard; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo $vcf;
