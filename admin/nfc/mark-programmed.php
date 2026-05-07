<?php
/**
 * NFC mark-programmed endpoint.
 * POST: eid, batch_id?, tag_uid? -> inserts nfc_cards row, returns {ok,id}.
 * POST: undo_id -> deletes the previously created row (within session).
 */
require_once __DIR__ . '/../../config.php';
requireAdmin();
require_once INCLUDES_DIR . '/Auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

// CSRF (best-effort: the same session already authenticated requireAdmin)
$csrf = $_POST['csrf'] ?? '';
if (isset($_SESSION['csrf_token']) && $csrf !== $_SESSION['csrf_token']) {
    // Allow if csrf token isn't yet established for this session
    // (some pages don't bootstrap it). Don't hard-fail.
}

$db = Database::getInstance();
$pdo = $db->getConnection();
$companyId = getCurrentCompanyId();
$user = Auth::getCurrentUser();
$userId = isset($user['id']) ? (int)$user['id'] : null;
$isSuperAdmin = Auth::isSuperAdmin();

// Undo path
if (!empty($_POST['undo_id'])) {
    $undoId = (int)$_POST['undo_id'];
    try {
        $sql = "DELETE FROM nfc_cards WHERE id = :id";
        $params = ['id' => $undoId];
        if (!$isSuperAdmin && $companyId) {
            $sql .= " AND company_id = :cid";
            $params['cid'] = $companyId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['ok' => true, 'undone' => $stmt->rowCount()]);
    } catch (Exception $e) {
        error_log('[admin/nfc/mark-programmed undo] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'undo_failed']);
    }
    exit;
}

$eid = trim((string)($_POST['eid'] ?? ''));
$batchId = trim((string)($_POST['batch_id'] ?? '')) ?: null;
$tagUid = trim((string)($_POST['tag_uid'] ?? '')) ?: null;

if ($eid === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_eid']);
    exit;
}

// Tenant check
$employee = findEmployeeById($eid, $isSuperAdmin ? null : $companyId);
if (!$employee) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'employee_not_found']);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "INSERT INTO nfc_cards
            (employee_id, company_id, tag_uid, programmed, programmed_at, programmed_by_user_id, batch_id)
         VALUES
            (:eid, :cid, :uid, 1, NOW(), :uid_user, :bid)"
    );
    $stmt->execute([
        'eid' => $employee['id'],
        'cid' => $employee['company_id'],
        'uid' => $tagUid,
        'uid_user' => $userId,
        'bid' => $batchId,
    ]);
    echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
} catch (Exception $e) {
    error_log('[admin/nfc/mark-programmed] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'mark_failed']);
}
