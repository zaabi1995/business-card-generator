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
require_once INCLUDES_DIR . '/ScanAuth.php';
require_once INCLUDES_DIR . '/RateLimiter.php';

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

// Brute-force guard, per proxy-aware IP (the CF edge is a shared origin IP).
require_once INCLUDES_DIR . '/UrlSafety.php';
$ip = function_exists('getClientIp') ? getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!RateLimiter::check('scan_login', $ip, 10, 900)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many attempts, try again later']);
    exit;
}

// Employee-DIRECT authentication. The Scan app is employee-scoped (scans
// belong to an employee), so we resolve the employee by email ourselves
// rather than Auth::unifiedLogin(). unifiedLogin checks the users table
// first and short-circuits, so a person who is BOTH a company owner (users
// row) and an employee could never log in with their employee password.
// A single email can map to more than one employee row (duplicates across
// companies); accept the first active row whose password verifies.
try {
    $db = Database::getInstance();
    $rows = $db->fetchAll(
        "SELECT e.id, e.password_hash
         FROM employees e JOIN companies c ON c.id = e.company_id
         WHERE e.email = :email AND e.status = 'active' AND c.status = 'active'",
        ['email' => $email]
    );
    $employeeId = null;
    $hasAny = false;
    $hasPassword = false;
    foreach ($rows as $r) {
        $hasAny = true;
        if (empty($r['password_hash'])) continue;
        $hasPassword = true;
        if (password_verify($password, $r['password_hash'])) {
            $employeeId = (string)$r['id'];
            break;
        }
    }
    if ($employeeId === null) {
        http_response_code(401);
        // Distinguish "you have no password yet" from "wrong password" only
        // when the email maps to employee rows that all lack a password, so
        // the app can nudge the user to set one. Never leak existence for a
        // truly unknown email.
        $error = ($hasAny && !$hasPassword) ? 'password_not_set' : 'invalid_credentials';
        echo json_encode(['success' => false, 'error' => $error]);
        exit;
    }
    echo json_encode([
        'success' => true,
        'token' => ScanAuth::issueToken($employeeId),
        'employee_id' => $employeeId,
    ]);
} catch (Throwable $e) {
    error_log('[scan/login] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
}
