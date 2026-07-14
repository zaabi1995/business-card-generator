<?php
/**
 * POST /api/scan/otp-verify.php, passwordless login/signup step 2.
 *
 * Body: {identifier, code, name?}
 *   -> {success:true, token, employee_id, is_new:bool}
 *
 * Verifies the 6-digit OTP (purpose 'scan_login') issued by
 * api/scan/otp-request.php. On a bad/expired code returns 401 invalid_code.
 *
 * On success:
 *   - Find an ACTIVE employee (in an ACTIVE company) whose email matches the
 *     identifier (email case) OR whose mobile/phone normalises to the
 *     identifier (phone case). Found -> issue a token, is_new=false.
 *   - Not found -> passwordless signup: create a personal company + one active
 *     employee (email set for an email identifier, mobile set for a phone),
 *     via the same createCompany()/addEmployee() path signup.php uses, with a
 *     random password (never used, the app authenticates by token). is_new=true.
 *
 * Idempotent-ish: two racing verifies re-run the lookup after each other, so
 * the second finds the just-created employee instead of duplicating it.
 *
 * Reuses: OtpService, ScanAuth::issueToken, Phone::normalize, createCompany,
 * addEmployee, getClientIp.
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
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $raw = trim((string)($body['identifier'] ?? ''));
    $code = trim((string)($body['code'] ?? ''));
    $name = trim((string)($body['name'] ?? ''));
    if ($raw === '' || $code === '') {
        echo json_encode(['success' => false, 'error' => 'identifier_and_code_required']);
        exit;
    }

    $ip = getClientIp();

    // Resolve the same canonical identifier the request step used.
    $isEmail = strpos($raw, '@') !== false;
    if ($isEmail) {
        $identifier = strtolower($raw);
        if (!filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'error' => 'invalid_identifier']);
            exit;
        }
    } else {
        $identifier = Phone::normalize($raw);
        if ($identifier === null) {
            echo json_encode(['success' => false, 'error' => 'invalid_identifier']);
            exit;
        }
    }

    // Defense-in-depth on top of OtpService's per-code attempt cap (5), whose
    // read-then-update counter is racy; RateLimiter's counter is atomic.
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

    // 1) Try to find an existing active employee in an active company.
    $employeeId = findActiveEmployee($db, $identifier, $isEmail);

    if ($employeeId !== null) {
        echo json_encode([
            'success' => true,
            'token' => ScanAuth::issueToken($employeeId),
            'employee_id' => $employeeId,
            'is_new' => false,
        ]);
        exit;
    }

    // 2) Passwordless signup: personal company + one active employee.
    $displayName = $name !== '' ? $name : ($isEmail ? emailLocalName($identifier) : 'My Card');
    $companyName = $displayName;
    // Random password, never surfaced. The app authenticates by bearer token;
    // the user can set a real password later via the normal flow if desired.
    $randomPassword = bin2hex(random_bytes(16));
    $adminEmail = $isEmail ? $identifier : '';

    $companyResult = createCompany($companyName, $adminEmail, $randomPassword);
    if (empty($companyResult['success'])) {
        error_log('[scan/otp-verify] createCompany: ' . ($companyResult['error'] ?? 'unknown'));
        echo json_encode(['success' => false, 'error' => 'account_create_failed']);
        exit;
    }
    $companyId = $companyResult['company']['id'];

    $empData = [
        'name_en'       => $displayName,
        'status'        => 'active',
        'company_en'    => $companyName,
        'skip_invite'   => true,
    ];
    if ($isEmail) {
        $empData['email'] = $identifier;
        // Give the passwordless account a random hash so it exists but cannot
        // be logged into by guessing; the app uses the token, not a password.
        $empData['password_hash'] = password_hash($randomPassword, PASSWORD_DEFAULT);
    } else {
        $empData['mobile'] = $identifier;
    }

    $empResult = addEmployee($empData, $companyId);
    if (empty($empResult['success'])) {
        // A concurrent verify may have created the employee first: re-run the
        // lookup so the loser of the race still logs into the same account.
        $employeeId = findActiveEmployee($db, $identifier, $isEmail);
        if ($employeeId !== null) {
            echo json_encode([
                'success' => true,
                'token' => ScanAuth::issueToken($employeeId),
                'employee_id' => $employeeId,
                'is_new' => false,
            ]);
            exit;
        }
        error_log('[scan/otp-verify] addEmployee: ' . ($empResult['error'] ?? 'unknown'));
        echo json_encode(['success' => false, 'error' => 'account_create_failed']);
        exit;
    }

    $employeeId = (string) $empResult['id'];
    echo json_encode([
        'success' => true,
        'token' => ScanAuth::issueToken($employeeId),
        'employee_id' => $employeeId,
        'is_new' => true,
    ]);
} catch (\Throwable $e) {
    error_log('[scan/otp-verify] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
    exit;
}

/**
 * Find an active employee (in an active company) by email or by a phone that
 * normalises to $identifier. Returns the employee id (string) or null.
 */
function findActiveEmployee($db, string $identifier, bool $isEmail): ?string
{
    if ($isEmail) {
        $row = $db->fetchOne(
            "SELECT e.id
             FROM employees e JOIN companies c ON c.id = e.company_id
             WHERE e.email = :email AND e.status = 'active' AND c.status = 'active'
             ORDER BY e.created_at ASC LIMIT 1",
            ['email' => $identifier]
        );
        return $row ? (string)$row['id'] : null;
    }

    // Phone: narrow by the last 8 digits (the Omani subscriber number), then
    // confirm each candidate by re-normalising its stored mobile/phone. Stored
    // values vary in format ('71616161', '+968 7161 6161', ...), so an exact
    // string match would miss them.
    $digits = preg_replace('/\D/', '', $identifier);
    $tail = strlen($digits) >= 8 ? substr($digits, -8) : $digits;
    // Distinct placeholders: PDO emulated-prepares are OFF, so reusing one
    // named placeholder twice raises HY093.
    $rows = $db->fetchAll(
        "SELECT e.id, e.mobile, e.phone
         FROM employees e JOIN companies c ON c.id = e.company_id
         WHERE e.status = 'active' AND c.status = 'active'
           AND ( (e.mobile <> '' AND e.mobile LIKE :m)
              OR (e.phone  <> '' AND e.phone  LIKE :p) )
         ORDER BY e.created_at ASC",
        ['m' => '%' . $tail, 'p' => '%' . $tail]
    );
    foreach ($rows as $r) {
        foreach (['mobile', 'phone'] as $col) {
            if (!empty($r[$col]) && Phone::normalize($r[$col]) === $identifier) {
                return (string)$r['id'];
            }
        }
    }
    return null;
}

/**
 * Friendly display name from an email local part (ali.adnan -> Ali Adnan).
 */
function emailLocalName(string $email): string
{
    $local = substr($email, 0, strpos($email, '@') ?: strlen($email));
    $local = str_replace(['.', '_', '-', '+'], ' ', $local);
    $local = trim(preg_replace('/\s+/', ' ', $local));
    return $local !== '' ? ucwords($local) : 'My Card';
}
