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

    // Profile content carries into the new card, while account credentials and
    // verified aliases come only from the immutable scan account.
    $me = $db->fetchOne(
        "SELECT name_en, name_ar, email, mobile, mobile_ar, phone, phone_ar
         FROM employees WHERE id = :id",
        ['id' => $ctx['employee_id']]
    );
    if (!is_array($me)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'employee_not_found']);
        exit;
    }
    $profileEmail = strtolower(trim((string) ($me['email'] ?? '')));
    $account = $db->fetchOne(
        "SELECT password_hash
         FROM scan_accounts
         WHERE id = :account_id AND status = 'active'
         LIMIT 1",
        ['account_id' => $ctx['account_id']]
    );
    if (!is_array($account)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'account_unavailable']);
        exit;
    }
    $linkedIdentifiers = ScanIdentity::linkedIdentifiers(
        $db,
        (string) $ctx['account_id']
    );
    $loginEmail = (string) ($linkedIdentifiers['email'] ?? '');
    $accountPasswordHash = (string) ($account['password_hash'] ?? '');

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
    $owned = $db->fetchOne(
        "SELECT COUNT(*) AS n
         FROM scan_account_memberships m
         JOIN companies c ON c.id = m.company_id AND c.status = 'active'
         WHERE m.account_id = :account_id",
        ['account_id' => $ctx['account_id']]
    );
    if (((int) ($owned['n'] ?? 0)) >= 20) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'company_limit_reached']);
        exit;
    }

    // createCompany needs a plaintext to hash onto the companies row; the caller
    // is already authenticated so we never expose or require a password here. We
    // seed a random one, then (below) overwrite companies.password_hash with the
    // caller's existing hash so their known password logs into this company too.
    $seedPw = bin2hex(random_bytes(18));
    $adminEmail = $loginEmail !== ''
        ? $loginEmail
        : ('scan_' . substr(hash('sha256', (string) $ctx['account_id']), 0, 12) . '@cardify.local');
    $companyResult = createCompany(
        $companyName,
        $adminEmail,
        $seedPw,
        null,
        $slug !== '' ? $slug : null
    );
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
    if ($accountPasswordHash !== '') {
        try {
            $db->update(
                'companies',
                ['password_hash' => $accountPasswordHash],
                'id = :id',
                ['id' => $companyId]
            );
        } catch (Throwable $e) { /* non-fatal: app auth uses the employee row */ }
    }

    $empResult = addEmployee([
        'name_en'       => (string) ($me['name_en'] ?? ''),
        'name_ar'       => (string) ($me['name_ar'] ?? ''),
        'email'         => $profileEmail,
        'mobile'        => (string) ($me['mobile'] ?? ''),
        'mobile_ar'     => (string) ($me['mobile_ar'] ?? ''),
        'phone'         => (string) ($me['phone'] ?? ''),
        'phone_ar'      => (string) ($me['phone_ar'] ?? ''),
        'password_hash' => $accountPasswordHash,
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
    $attached = ScanIdentity::attachEmployee(
        $db,
        (string) $ctx['account_id'],
        $employeeId,
        'created_company',
        'owner'
    );
    if (empty($attached['success'])) {
        error_log(
            '[scan/create-company] membership: '
            . ($attached['error'] ?? 'unknown')
        );
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
        } catch (Throwable $rollbackError) {
            error_log(
                '[scan/create-company] membership rollback: '
                . $rollbackError->getMessage()
            );
        }
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'error' => $attached['error'] ?? 'membership_conflict',
        ]);
        exit;
    }

    echo json_encode([
        'success'     => true,
        'token'       => ScanAuth::issueToken(
            $employeeId,
            'mobile',
            (string) $ctx['account_id']
        ),
        'employee_id' => $employeeId,
        'company_id'  => $companyId,
        'slug'        => $newSlug,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[scan/create-company] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
}
