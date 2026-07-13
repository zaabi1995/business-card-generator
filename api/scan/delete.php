<?php
/**
 * POST /api/scan/delete.php, soft-delete a rolodex entry
 *
 * Body: {id} -> {success}. Sets status='deleted'; list.php already excludes
 * deleted rows, nothing else needs to change to hide it from the rolodex.
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';

header('Content-Type: application/json');
$ctx = ScanAuth::requireEmployee();
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$id = (int)($body['id'] ?? 0);

try {
    $stmt = Database::getInstance()->getConnection()->prepare(
        "UPDATE scans SET status = 'deleted' WHERE id = ? AND employee_id = ?");
    $stmt->execute([$id, $ctx['employee_id']]);
} catch (\Throwable $e) {
    error_log('[scan/delete] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
    exit;
}

echo json_encode(['success' => (bool)$stmt->rowCount()]);
