<?php
/**
 * POST /api/scan/delete.php, soft-delete a rolodex entry
 *
 * Body: {id} -> {success}. Sets status='deleted'; list.php already excludes
 * deleted rows, nothing else needs to change to hide it from the rolodex.
 *
 * Idempotent: deleting an already-deleted scan returns success:true, so an
 * offline mobile client retrying after a dropped response gets the same
 * answer both times. Only an id that was never this employee's (or never
 * existed) gets a 404. rowCount() alone can't distinguish the two cases,
 * MySQL reports 0 changed rows for a no-op re-delete as well.
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST only']);
    exit;
}
$ctx = ScanAuth::requireEmployeeMutation();
require_once __DIR__ . '/_ratelimit.php';
scanRateLimit($ctx, 'delete', 300);
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$id = (int)($body['id'] ?? 0);

try {
    $db = Database::getInstance();
    $scan = $db->fetchOne(
        "SELECT id, status FROM scans WHERE id = :i AND employee_id = :e",
        ['i' => $id, 'e' => $ctx['employee_id']]
    );
    if (!$scan) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'not_found']);
        exit;
    }
    if ($scan['status'] !== 'deleted') {
        $db->getConnection()->prepare(
            "UPDATE scans SET status = 'deleted' WHERE id = ? AND employee_id = ?"
        )->execute([$id, $ctx['employee_id']]);
    }
} catch (\Throwable $e) {
    error_log('[scan/delete] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
    exit;
}

echo json_encode(['success' => true]);
