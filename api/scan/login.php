<?php
/**
 * POST /api/scan/login.php, email+password -> bearer token
 *
 * Body: {email, password} -> {success, token, employee_id}
 *
 * Wraps Auth::unifiedLogin(), which also logs in company admins and plain
 * users (not just employees). Those cases return success=true but never
 * set $_SESSION['employee_id'], so we reject them explicitly instead of
 * falling through to a confusing "Invalid credentials" or a PHP notice.
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/ScanAuth.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST only']);
    exit;
}
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$email = trim($body['email'] ?? '');
$password = $body['password'] ?? '';
if ($email === '' || $password === '') {
    echo json_encode(['success' => false, 'error' => 'Email and password required']);
    exit;
}
$result = Auth::unifiedLogin($email, $password);
if (empty($result['success'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => $result['error'] ?? 'Invalid credentials']);
    exit;
}
if (empty($_SESSION['employee_id'])) {
    // unifiedLogin succeeded but logged in a company admin or a plain user
    // account (users table), neither of which sets employee_id. The Scan
    // mobile app is employee-only.
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'company_login_not_supported']);
    exit;
}
$employeeId = (string)$_SESSION['employee_id'];
echo json_encode([
    'success' => true,
    'token' => ScanAuth::issueToken($employeeId),
    'employee_id' => $employeeId,
]);
