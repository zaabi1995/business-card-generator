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
require_once INCLUDES_DIR . '/ReviewAccess.php';

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

    $reviewDecision = ReviewAccess::verificationDecision($identifier, $code);
    $verified = $reviewDecision === null
        ? !empty(OtpService::verify($identifier, $code, 'scan_login')['ok'])
        : $reviewDecision;
    if (!$verified) {
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
        $releaseAccountLock = ScanAuth::acquireAccountMutationLock($accountId);
        $freshAccount = ScanIdentity::findAccountByIdentifier(
            $db,
            $identifier,
            $identifierType
        );
        if (
            $freshAccount === null
            || !hash_equals(
                $accountId,
                (string) ($freshAccount['account_id'] ?? '')
            )
        ) {
            $releaseAccountLock();
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'account_unavailable']);
            exit;
        }
        $employee = ScanIdentity::primaryEmployee($db, $accountId);
        if ($employee === null) {
            $releaseAccountLock();
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'account_unavailable']);
            exit;
        }
        $linked = ScanIdentity::linkVerifiedIdentifier(
            $db,
            $accountId,
            $identifier,
            $identifierType,
            'scan_login_otp'
        );
        if (empty($linked['success'])) {
            $releaseAccountLock();
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'error' => $linked['error'] ?? 'identity_conflict',
            ]);
            exit;
        }
        $token = ScanAuth::issueToken(
            (string) $employee['employee_id'],
            'mobile',
            $accountId
        );
        $releaseAccountLock();
        echo json_encode([
            'success' => true,
            'token' => $token,
            'employee_id' => (string) $employee['employee_id'],
            'account_id' => $accountId,
            'is_new' => false,
        ]);
        exit;
    }

    $displayName = $name !== ''
        ? $name
        : ($isEmail ? emailLocalName($identifier) : 'My Card');
    $randomPassword = bin2hex(random_bytes(24));
    $pdo = $db->getConnection();
    $startedTransaction = !$pdo->inTransaction();
    if ($startedTransaction) {
        $pdo->beginTransaction();
    }
    $releaseProvisionedAccountLock = null;
    try {
        $companyResult = createCompany(
            $displayName,
            $isEmail ? $identifier : '',
            $randomPassword
        );
        if (empty($companyResult['success'])) {
            throw new RuntimeException(
                'createCompany: ' . ($companyResult['error'] ?? 'unknown')
            );
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
            throw new RuntimeException(
                'addEmployee: ' . ($employeeResult['error'] ?? 'unknown')
            );
        }

        $employeeId = (string) $employeeResult['id'];
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
        $releaseProvisionedAccountLock =
            ScanAuth::acquireAccountMutationLock($accountId);
        $provisionedMembership = ScanIdentity::membershipForEmployee(
            $db,
            $accountId,
            $employeeId
        );
        if ($provisionedMembership === null) {
            throw new RuntimeException('account_membership_unavailable');
        }
        $token = ScanAuth::issueToken($employeeId, 'mobile', $accountId);
        if ($startedTransaction) {
            $pdo->commit();
        }
        $releaseProvisionedAccountLock();
        $releaseProvisionedAccountLock = null;
    } catch (Throwable $provisionError) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (is_callable($releaseProvisionedAccountLock)) {
            $releaseProvisionedAccountLock();
            $releaseProvisionedAccountLock = null;
        }
        error_log('[scan/otp-verify] provisioning: ' . $provisionError->getMessage());
        $existing = ScanIdentity::findAccountByIdentifier(
            $db,
            $identifier,
            $identifierType
        );
        if ($existing !== null) {
            $existingAccountId = (string) $existing['account_id'];
            $releaseAccountLock = ScanAuth::acquireAccountMutationLock(
                $existingAccountId
            );
            $freshExisting = ScanIdentity::findAccountByIdentifier(
                $db,
                $identifier,
                $identifierType
            );
            if (
                $freshExisting === null
                || !hash_equals(
                    $existingAccountId,
                    (string) ($freshExisting['account_id'] ?? '')
                )
            ) {
                $releaseAccountLock();
                echo json_encode([
                    'success' => false,
                    'error' => 'account_create_failed',
                ]);
                exit;
            }
            $employee = ScanIdentity::primaryEmployee(
                $db,
                $existingAccountId
            );
            if ($employee !== null) {
                $token = ScanAuth::issueToken(
                    (string) $employee['employee_id'],
                    'mobile',
                    $existingAccountId
                );
                $releaseAccountLock();
                echo json_encode([
                    'success' => true,
                    'token' => $token,
                    'employee_id' => (string) $employee['employee_id'],
                    'account_id' => $existingAccountId,
                    'is_new' => false,
                ]);
                exit;
            }
            $releaseAccountLock();
        }

        echo json_encode(['success' => false, 'error' => 'account_create_failed']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'token' => $token,
        'employee_id' => $employeeId,
        'account_id' => $accountId,
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
