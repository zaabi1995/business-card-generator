<?php
/**
 * POST /api/scan/login.php {email, password}
 *
 * Login aliases resolve to an immutable scan account. During the rollout only,
 * a profile email may locate legacy candidate rows, but a password must verify
 * and resolve to exactly one account. Profile data never creates a login alias.
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

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = [];
}
$email = strtolower(trim((string) ($body['email'] ?? '')));
$password = (string) ($body['password'] ?? '');
if ($email === '' || $password === '') {
    echo json_encode([
        'success' => false,
        'error' => 'Email and password required',
    ]);
    exit;
}

$ip = function_exists('getClientIp')
    ? getClientIp()
    : ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!RateLimiter::check('scan_login', $ip, 10, 900)) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'error' => 'Too many attempts, try again later',
    ]);
    exit;
}

try {
    $db = Database::getInstance();
    $account = ScanIdentity::findAccountByIdentifier(
        $db,
        $email,
        'email'
    );
    if ($account !== null) {
        $hash = (string) ($account['password_hash'] ?? '');
        if ($hash === '' || !password_verify($password, $hash)) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'error' => $hash === '' ? 'password_not_set' : 'invalid_credentials',
            ]);
            exit;
        }
        $employee = ScanIdentity::primaryEmployee(
            $db,
            (string) $account['account_id']
        );
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
                (string) $account['account_id']
            ),
            'employee_id' => (string) $employee['employee_id'],
        ]);
        exit;
    }

    $legacyRows = $db->fetchAll(
        "SELECT e.id, e.password_hash, m.account_id
         FROM employees e
         JOIN companies c
           ON c.id = e.company_id
          AND c.status = 'active'
         LEFT JOIN scan_account_memberships m ON m.employee_id = e.id
         WHERE e.email = :email
           AND e.status = 'active'
           AND e.deleted_at IS NULL
         ORDER BY e.created_at ASC, e.id ASC",
        ['email' => $email]
    );

    $matchedAccounts = [];
    $matchedEmployees = [];
    $unboundMatches = [];
    $hasAnyPassword = false;
    foreach ($legacyRows as $row) {
        $hash = (string) ($row['password_hash'] ?? '');
        if ($hash === '') {
            continue;
        }
        $hasAnyPassword = true;
        if (!password_verify($password, $hash)) {
            continue;
        }
        $accountId = trim((string) ($row['account_id'] ?? ''));
        if ($accountId === '') {
            $unboundMatches[] = $row;
            continue;
        }
        $matchedAccounts[] = $accountId;
        $matchedEmployees[$accountId] = [
            'employee_id' => (string) $row['id'],
            'password_hash' => $hash,
        ];
    }

    if ($unboundMatches) {
        if (count($unboundMatches) !== 1 || $matchedAccounts) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'ambiguous_identity']);
            exit;
        }
        $legacy = $unboundMatches[0];
        $accountId = ScanIdentity::createAccountForEmployee(
            $db,
            (string) $legacy['id'],
            (string) $legacy['password_hash'],
            null,
            null,
            false,
            'legacy_password_proof'
        );
        echo json_encode([
            'success' => true,
            'token' => ScanAuth::issueToken(
                (string) $legacy['id'],
                'mobile',
                $accountId
            ),
            'employee_id' => (string) $legacy['id'],
        ]);
        exit;
    }

    $accountId = ScanIdentity::uniqueAccountId($matchedAccounts);
    if ($accountId === null) {
        http_response_code($matchedAccounts ? 409 : 401);
        echo json_encode([
            'success' => false,
            'error' => $matchedAccounts
                ? 'ambiguous_identity'
                : ($legacyRows && !$hasAnyPassword
                    ? 'password_not_set'
                    : 'invalid_credentials'),
        ]);
        exit;
    }

    $matched = $matchedEmployees[$accountId];
    $db->update(
        'scan_accounts',
        ['password_hash' => $matched['password_hash']],
        'id = :account_id',
        ['account_id' => $accountId]
    );
    echo json_encode([
        'success' => true,
        'token' => ScanAuth::issueToken(
            (string) $matched['employee_id'],
            'mobile',
            $accountId
        ),
        'employee_id' => (string) $matched['employee_id'],
    ]);
} catch (Throwable $e) {
    error_log('[scan/login] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
}
