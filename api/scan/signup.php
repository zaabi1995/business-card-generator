<?php
/**
 * POST /api/scan/signup.php, create a NEW Cardify account from the mobile
 * scan app and return a bearer token.
 *
 * Body: {name, email, password, company_name?}
 *   -> {success, token, employee_id}
 *
 * The scan app is employee-scoped (scans + tokens belong to an employee),
 * so signup provisions a company plus one active employee whose password
 * verifies via api/scan/login.php. Reuses the same canonical creation path
 * as the web signup:
 *   - createCompany()            includes/functions.php -> DatabaseAdapter::createCompany
 *   - addEmployee()              includes/functions.php -> DatabaseAdapter::addEmployee
 *   - ScanIdentity              immutable account + login alias
 * When company_name is omitted we attach the person to a lightweight
 * personal company named after them, so a solo user needs no employer.
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';
require_once INCLUDES_DIR . '/RateLimiter.php';
require_once INCLUDES_DIR . '/UrlSafety.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST only']);
    exit;
}

try {
    // Brute-force / abuse guard, per proxy-aware IP (the CF edge is a shared
    // origin IP). Mirrors api/scan/login.php's scan_login limiter.
    $ip = function_exists('getClientIp') ? getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (!RateLimiter::check('scan_signup', $ip, 10, 900)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Too many attempts, try again later']);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $name        = trim($body['name'] ?? '');
    $email       = trim(strtolower($body['email'] ?? ''));
    $password    = (string) ($body['password'] ?? '');
    $companyName = trim($body['company_name'] ?? '');

    // Validate.
    if ($name === '') {
        echo json_encode(['success' => false, 'error' => 'name_required']);
        exit;
    }
    if ($email === '' || !isValidEmail($email)) {
        echo json_encode(['success' => false, 'error' => 'invalid_email']);
        exit;
    }
    if (strlen($password) < 8) {
        echo json_encode(['success' => false, 'error' => 'weak_password']);
        exit;
    }

    $db = Database::getInstance();
    if (ScanIdentity::findAccountByIdentifier($db, $email, 'email') !== null) {
        echo json_encode(['success' => false, 'error' => 'email_taken']);
        exit;
    }

    // A solo user with no employer gets a personal company named after them.
    $companyName = $companyName !== '' ? $companyName : $name;

    // Create the company (active, generated slug + uuid, default brand theme).
    $companyResult = createCompany($companyName, $email, $password);
    if (empty($companyResult['success'])) {
        error_log('[scan/signup] createCompany: ' . ($companyResult['error'] ?? 'unknown'));
        echo json_encode(['success' => false, 'error' => 'company_create_failed']);
        exit;
    }
    $companyId = $companyResult['company']['id'];

    // Create the employee (active, own password, no invite email: the app
    // already hands the user a token below).
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $empResult = addEmployee([
        'name_en'       => $name,
        'email'         => $email,
        'password_hash' => $passwordHash,
        'status'        => 'active',
        'company_en'    => $companyName,
        'skip_invite'   => true,
    ], $companyId);
    if (empty($empResult['success'])) {
        error_log('[scan/signup] addEmployee: ' . ($empResult['error'] ?? 'unknown'));
        echo json_encode(['success' => false, 'error' => 'account_create_failed']);
        exit;
    }
    $employeeId = (string) $empResult['id'];
    try {
        $accountId = ScanIdentity::createAccountForEmployee(
            $db,
            $employeeId,
            $passwordHash,
            null,
            null,
            false,
            'password_signup',
            'owner'
        );
    } catch (Throwable $identityError) {
        error_log('[scan/signup] identity: ' . $identityError->getMessage());
        try {
            $db->query('DELETE FROM employees WHERE id = :employee_id', [
                'employee_id' => $employeeId,
            ]);
            $db->query('DELETE FROM company_themes WHERE company_id = :company_id', [
                'company_id' => $companyId,
            ]);
            $db->query('DELETE FROM companies WHERE id = :company_id', [
                'company_id' => $companyId,
            ]);
        } catch (Throwable $cleanupError) {
            error_log('[scan/signup] cleanup: ' . $cleanupError->getMessage());
        }
        echo json_encode(['success' => false, 'error' => 'account_create_failed']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'token' => ScanAuth::issueToken($employeeId, 'mobile', $accountId),
        'employee_id' => $employeeId,
        'account_id' => $accountId,
    ]);
} catch (Throwable $e) {
    error_log('[scan/signup] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
}
