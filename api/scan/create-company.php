<?php
/**
 * POST /api/scan/create-company.php  {company_name, slug?}
 * -> An already-authenticated person creates ANOTHER company (a new brand /
 * card identity) and is added to it as an active employee carrying their own
 * name + contact. Mirrors signup.php's createCompany + addEmployee, but for a
 * logged-in user (no new password: the new company reuses their known one).
 * Issues a fresh token bound to the new employee so the app switches straight
 * into it (same response shape as switch-company.php). Bearer-auth, rate limited.
 *
 * Response: {success, token, employee_id, company_id, slug}
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
// Deliberately tight: creating companies is rare + expensive (slug, theme rows).
scanRateLimit($ctx, 'create_company', 12);

try {
    $db = Database::getInstance();

    // The caller's own identity carries into the new company's employee row so
    // the card is not blank (name + contact), and login keeps working via their
    // existing credential (copied hash, never a new/guessable password).
    $me = $db->fetchOne(
        "SELECT name_en, name_ar, email, mobile, mobile_ar, phone, phone_ar, password_hash
         FROM employees WHERE id = :id",
        ['id' => $ctx['employee_id']]
    );
    if (!is_array($me)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'employee_not_found']);
        exit;
    }
    $email = strtolower(trim((string) ($me['email'] ?? '')));

    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) { $body = []; }
    $companyName = trim((string) ($body['company_name'] ?? ''));
    $slug = trim((string) ($body['slug'] ?? ''));

    if (mb_strlen($companyName) < 2 || mb_strlen($companyName) > 100) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'invalid_company_name']);
        exit;
    }

    // Anti-abuse: cap how many companies one identity may own from the app.
    if ($email !== '') {
        $owned = $db->fetchOne(
            "SELECT COUNT(*) AS n FROM companies WHERE LOWER(TRIM(admin_email)) = :e AND status = 'active'",
            ['e' => $email]
        );
        if (((int) ($owned['n'] ?? 0)) >= 20) {
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => 'company_limit_reached']);
            exit;
        }
    }

    // createCompany needs a plaintext to hash onto the companies row; the caller
    // is already authenticated so we never expose or require a password here. We
    // seed a random one, then (below) overwrite companies.password_hash with the
    // caller's existing hash so their known password logs into this company too.
    $seedPw = bin2hex(random_bytes(18));
    $companyResult = createCompany($companyName, $email !== '' ? $email : ('user_' . substr(md5($ctx['employee_id']), 0, 10) . '@cardify.local'), $seedPw, null, $slug !== '' ? $slug : null);
    if (empty($companyResult['success']) || empty($companyResult['company']['id'])) {
        // Surface slug-taken / reserved so the app can ask for another abbreviation.
        $err = (string) ($companyResult['error'] ?? 'company_create_failed');
        error_log('[scan/create-company] createCompany: ' . $err);
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'company_create_failed', 'detail' => $err]);
        exit;
    }
    // createCompany() returns ['success'=>true,'company'=>['id'=>..,'slug'=>..]].
    $companyId = (string) $companyResult['company']['id'];
    $newSlug = (string) ($companyResult['company']['slug'] ?? '');

    // Reuse the caller's login credential for the new company's web admin so
    // their email + known password authenticates everywhere (no orphan password).
    if (!empty($me['password_hash'])) {
        try {
            $db->update('companies', ['password_hash' => $me['password_hash']], 'id = :id', ['id' => $companyId]);
        } catch (Throwable $e) { /* non-fatal: app auth uses the employee row */ }
    }

    $empResult = addEmployee([
        'name_en'       => (string) ($me['name_en'] ?? ''),
        'name_ar'       => (string) ($me['name_ar'] ?? ''),
        'email'         => $email,
        'mobile'        => (string) ($me['mobile'] ?? ''),
        'mobile_ar'     => (string) ($me['mobile_ar'] ?? ''),
        'phone'         => (string) ($me['phone'] ?? ''),
        'phone_ar'      => (string) ($me['phone_ar'] ?? ''),
        'password_hash' => (string) ($me['password_hash'] ?? ''),
        'status'        => 'active',
        'company_en'    => $companyName,
        'skip_invite'   => true,
    ], $companyId);
    if (empty($empResult['success'])) {
        error_log('[scan/create-company] addEmployee: ' . ($empResult['error'] ?? 'unknown'));
        // Roll the half-built company back so a failed create leaves no orphan.
        try {
            $db->query('DELETE FROM company_themes WHERE company_id = :c', ['c' => $companyId]);
            $db->query('DELETE FROM companies WHERE id = :c', ['c' => $companyId]);
        } catch (Throwable $e) { error_log('[scan/create-company] rollback: ' . $e->getMessage()); }
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'account_create_failed']);
        exit;
    }
    $employeeId = (string) $empResult['id'];

    echo json_encode([
        'success'     => true,
        'token'       => ScanAuth::issueToken($employeeId),
        'employee_id' => $employeeId,
        'company_id'  => $companyId,
        'slug'        => $newSlug,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[scan/create-company] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
}
