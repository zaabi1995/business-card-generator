<?php
/**
 * POST /api/scan/otp-verify.php {identifier, code, name?}
 *
 * A verified login alias resolves directly to an immutable scan account.
 * Editable employee profile contacts never select an existing account.
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';
require_once INCLUDES_DIR . '/OtpService.php';
require_once INCLUDES_DIR . '/RateLimiter.php';
require_once INCLUDES_DIR . '/UrlSafety.php';
require_once INCLUDES_DIR . '/Phone.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST only']);
    exit;
}

try {
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        $body = [];
    }
    $raw = trim((string) ($body['identifier'] ?? ''));
    $code = trim((string) ($body['code'] ?? ''));
    $name = trim((string) ($body['name'] ?? ''));
    if ($raw === '' || $code === '') {
        echo json_encode([
            'success' => false,
            'error' => 'identifier_and_code_required',
        ]);
        exit;
    }

    $isEmail = strpos($raw, '@') !== false;
    if ($isEmail) {
        $identifier = strtolower($raw);
        if (!filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'error' => 'invalid_identifier']);
            exit;
        }
        $identifierType = 'email';
    } else {
        $identifier = Phone::normalize($raw);
        if ($identifier === null) {
            echo json_encode(['success' => false, 'error' => 'invalid_identifier']);
            exit;
        }
        $identifierType = 'phone';
    }

    $ip = getClientIp();
    if (!RateLimiter::check('scan_otp_verify:' . $identifier, $ip, 10, 900)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'rate_limited']);
        exit;
    }

    $verify = OtpService::verify($identifier, $code, 'scan_login');
    if (empty($verify['ok'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'invalid_code']);
        exit;
    }

    $db = Database::getInstance();
    $account = ScanIdentity::findAccountByIdentifier(
        $db,
        $identifier,
        $identifierType
    );
    if ($account !== null) {
        $accountId = (string) $account['account_id'];
        $linked = ScanIdentity::linkVerifiedIdentifier(
            $db,
            $accountId,
            $identifier,
            $identifierType,
            'scan_login_otp'
        );
        if (empty($linked['success'])) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'error' => $linked['error'] ?? 'identity_conflict',
            ]);
            exit;
        }
        $employee = ScanIdentity::primaryEmployee($db, $accountId);
        if ($employee === null) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'account_unavailable']);
            exit;
        }
        echo json_encode([
            'success' => true,
            'token' => ScanAuth::issueToken(
                (string) $employee['employee_id'],
                'mobile',
                $accountId
            ),
            'employee_id' => (string) $employee['employee_id'],
            'is_new' => false,
        ]);
        exit;
    }

    $displayName = $name !== ''
        ? $name
        : ($isEmail ? emailLocalName($identifier) : 'My Card');
    $randomPassword = bin2hex(random_bytes(24));
    $companyResult = createCompany(
        $displayName,
        $isEmail ? $identifier : '',
        $randomPassword
    );
    if (empty($companyResult['success'])) {
        error_log(
            '[scan/otp-verify] createCompany: '
            . ($companyResult['error'] ?? 'unknown')
        );
        echo json_encode(['success' => false, 'error' => 'account_create_failed']);
        exit;
    }

    $companyId = (string) $companyResult['company']['id'];
    $employeeData = [
        'name_en' => $displayName,
        'status' => 'active',
        'company_en' => $displayName,
        'skip_invite' => true,
    ];
    if ($isEmail) {
        $employeeData['email'] = $identifier;
    } else {
        $employeeData['mobile'] = $identifier;
    }
    $employeeResult = addEmployee($employeeData, $companyId);
    if (empty($employeeResult['success'])) {
        error_log(
            '[scan/otp-verify] addEmployee: '
            . ($employeeResult['error'] ?? 'unknown')
        );
        echo json_encode(['success' => false, 'error' => 'account_create_failed']);
        exit;
    }

    $employeeId = (string) $employeeResult['id'];
    try {
        $accountId = ScanIdentity::createAccountForEmployee(
            $db,
            $employeeId,
            null,
            $identifier,
            $identifierType,
            true,
            'otp_signup',
            'owner'
        );
    } catch (Throwable $identityError) {
        error_log('[scan/otp-verify] identity: ' . $identityError->getMessage());
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
            error_log('[scan/otp-verify] cleanup: ' . $cleanupError->getMessage());
        }

        $existing = ScanIdentity::findAccountByIdentifier(
            $db,
            $identifier,
            $identifierType
        );
        if ($existing !== null) {
            $employee = ScanIdentity::primaryEmployee(
                $db,
                (string) $existing['account_id']
            );
            if ($employee !== null) {
                echo json_encode([
                    'success' => true,
                    'token' => ScanAuth::issueToken(
                        (string) $employee['employee_id'],
                        'mobile',
                        (string) $existing['account_id']
                    ),
                    'employee_id' => (string) $employee['employee_id'],
                    'is_new' => false,
                ]);
                exit;
            }
        }

        echo json_encode(['success' => false, 'error' => 'account_create_failed']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'token' => ScanAuth::issueToken($employeeId, 'mobile', $accountId),
        'employee_id' => $employeeId,
        'is_new' => true,
    ]);
} catch (Throwable $e) {
    error_log('[scan/otp-verify] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
}

function emailLocalName(string $email): string
{
    $separator = strpos($email, '@');
    $local = substr($email, 0, $separator === false ? strlen($email) : $separator);
    $local = str_replace(['.', '_', '-', '+'], ' ', $local);
    $local = trim((string) preg_replace('/\s+/', ' ', $local));
    return $local !== '' ? ucwords($local) : 'My Card';
}
