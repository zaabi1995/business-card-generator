<?php
/**
 * POST /api/scan/switch-company.php {employee_id}
 *
 * A target is available only through an explicit immutable account membership,
 * unless the account is linked by users.id to an active super-admin.
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST only']);
    exit;
}

$ctx = ScanAuth::requireEmployee();
require_once __DIR__ . '/_ratelimit.php';
scanRateLimit($ctx, 'switch_company', 120);

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = [];
}
$targetEmployeeId = trim((string) ($body['employee_id'] ?? ''));
if ($targetEmployeeId === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'missing_employee_id']);
    exit;
}

$db = Database::getInstance();
$accountId = (string) $ctx['account_id'];
$target = $db->fetchOne(
    "SELECT e.id, e.company_id
     FROM employees e
     JOIN companies c
       ON c.id = e.company_id
      AND c.status = 'active'
     WHERE e.id = :employee_id
       AND e.status = 'active'
       AND e.deleted_at IS NULL
     LIMIT 1",
    ['employee_id' => $targetEmployeeId]
);
if (!is_array($target)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'target_not_available']);
    exit;
}

$membership = ScanIdentity::membershipForEmployee(
    $db,
    $accountId,
    $targetEmployeeId
);
$isSuperAdmin = !empty($ctx['is_super_admin']);
if (!ScanIdentity::membershipAuthorizes($accountId, $membership, $isSuperAdmin)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'not_your_company']);
    exit;
}

try {
    $token = ScanAuth::issueToken(
        $targetEmployeeId,
        $isSuperAdmin && $membership === null ? 'mobile-super-admin' : 'mobile',
        $accountId
    );
} catch (Throwable $e) {
    error_log('[scan/switch-company] ' . $e->getMessage());
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'switch_not_authorized']);
    exit;
}

echo json_encode([
    'success' => true,
    'token' => $token,
    'employee_id' => $targetEmployeeId,
    'company_id' => (string) $target['company_id'],
]);
