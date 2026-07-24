<?php
/**
 * POST /api/scan/create-company.php  {company_name, slug?, operation_id?}
 * -> An already-authenticated person creates ANOTHER company (a new brand /
 * card identity) and is added to it as an active employee carrying their own
 * name + contact. Mirrors signup.php's createCompany + addEmployee, but for a
 * logged-in user (no new password: the new company reuses their known one).
 * Issues a fresh token bound to the new employee so the app switches straight
 * into it (same response shape as switch-company.php). Bearer-auth, rate limited.
 *
 * Response:
 * {success, token, employee_id, company_id, slug, operation_id, recovered}
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST only']);
    exit;
}

$ctx = ScanAuth::requireEmployeeMutation();
require_once __DIR__ . '/_ratelimit.php';
// Deliberately tight: creating companies is rare + expensive (slug, theme rows).
scanRateLimit($ctx, 'create_company', 12);

$pdo = null;
try {
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) { $body = []; }
    $rawCompanyName = $body['company_name'] ?? '';
    $rawSlug = $body['slug'] ?? '';
    $rawOperationId = $body['operation_id'] ?? '';
    if (
        !is_string($rawCompanyName)
        || !is_string($rawSlug)
        || !is_string($rawOperationId)
    ) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'invalid_company_request']);
        exit;
    }
    $companyName = trim($rawCompanyName);
    $slug = trim($rawSlug);
    $operationId = trim($rawOperationId);

    if (mb_strlen($companyName) < 2 || mb_strlen($companyName) > 100) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'invalid_company_name']);
        exit;
    }
    if (
        $operationId !== ''
        && preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di',
            $operationId
        ) !== 1
    ) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'invalid_operation_id']);
        exit;
    }

    $db = Database::getInstance();
    $pdo = $db->getConnection();
    $pdo->beginTransaction();
    $account = $db->fetchOne(
        "SELECT password_hash
         FROM scan_accounts
         WHERE id = :account_id AND status = 'active'
         LIMIT 1
         FOR UPDATE",
        ['account_id' => $ctx['account_id']]
    );
    if (!is_array($account)) {
        $pdo->rollBack();
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'account_unavailable']);
        exit;
    }

    // Profile content carries into the new card, while account credentials and
    // verified aliases come only from the immutable scan account.
    $me = $db->fetchOne(
        "SELECT name_en, name_ar, email, mobile, mobile_ar, phone, phone_ar
         FROM employees WHERE id = :id",
        ['id' => $ctx['employee_id']]
    );
    if (!is_array($me)) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'employee_not_found']);
        exit;
    }
    $profileEmail = strtolower(trim((string) ($me['email'] ?? '')));
    $linkedIdentifiers = ScanIdentity::linkedIdentifiers(
        $db,
        (string) $ctx['account_id']
    );
    $loginEmail = (string) ($linkedIdentifiers['email'] ?? '');
    $accountPasswordHash = (string) ($account['password_hash'] ?? '');
    if ($operationId !== '') {
        $insertOperation = $pdo->prepare(
            'INSERT IGNORE INTO scan_company_create_operations
                (account_id, operation_id, company_name, requested_slug, status)
             VALUES
                (:account_id, :operation_id, :company_name, :requested_slug, :status)'
        );
        $insertOperation->execute([
            'account_id' => $ctx['account_id'],
            'operation_id' => strtolower($operationId),
            'company_name' => $companyName,
            'requested_slug' => $slug !== '' ? $slug : null,
            'status' => 'pending',
        ]);
        $createdOperation = $insertOperation->rowCount() === 1;
        $operation = $db->fetchOne(
            "SELECT account_id, company_name, requested_slug,
                    company_id, employee_id, status
             FROM scan_company_create_operations
             WHERE operation_id = :operation_id
             FOR UPDATE",
            [
                'operation_id' => strtolower($operationId),
            ]
        );
        if (!is_array($operation)) {
            throw new RuntimeException('company_operation_unavailable');
        }
        if ((string) $operation['status'] === 'account_deleted') {
            $pdo->rollBack();
            http_response_code(410);
            echo json_encode([
                'success' => false,
                'error' => 'operation_account_deleted',
            ]);
            exit;
        }
        $operationAccountId = $operation['account_id'];
        if (
            !is_string($operationAccountId)
            || !hash_equals((string) $ctx['account_id'], $operationAccountId)
        ) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'error' => 'operation_owner_conflict',
            ]);
            exit;
        }
        if (
            (string) $operation['company_name'] !== $companyName
            || (string) ($operation['requested_slug'] ?? '') !== $slug
        ) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'error' => 'operation_conflict',
            ]);
            exit;
        }
        if (!$createdOperation) {
            if (
                (string) $operation['status'] !== 'completed'
                || empty($operation['company_id'])
                || empty($operation['employee_id'])
            ) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode([
                    'success' => false,
                    'error' => 'operation_in_progress',
                ]);
                exit;
            }
            $recovered = $db->fetchOne(
                "SELECT e.id AS employee_id, e.company_id, c.slug
                 FROM employees e
                 JOIN companies c
                   ON c.id = e.company_id
                  AND c.status = 'active'
                 JOIN scan_account_memberships m
                   ON m.employee_id = e.id
                  AND m.company_id = e.company_id
                  AND m.account_id = :account_id
                 WHERE e.id = :employee_id
                   AND e.company_id = :company_id
                   AND e.status = 'active'
                   AND e.deleted_at IS NULL
                 LIMIT 1",
                [
                    'account_id' => $ctx['account_id'],
                    'employee_id' => $operation['employee_id'],
                    'company_id' => $operation['company_id'],
                ]
            );
            if (!is_array($recovered)) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode([
                    'success' => false,
                    'error' => 'operation_target_unavailable',
                ]);
                exit;
            }
            $recoveredToken = ScanAuth::issueToken(
                (string) $recovered['employee_id'],
                'mobile',
                (string) $ctx['account_id']
            );
            $pdo->commit();
            echo json_encode([
                'success' => true,
                'token' => $recoveredToken,
                'employee_id' => (string) $recovered['employee_id'],
                'account_id' => (string) $ctx['account_id'],
                'company_id' => (string) $recovered['company_id'],
                'slug' => (string) ($recovered['slug'] ?? ''),
                'operation_id' => strtolower($operationId),
                'recovered' => true,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
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
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
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
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'company_create_failed']);
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
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
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
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'error' => $attached['error'] ?? 'membership_conflict',
        ]);
        exit;
    }

    if ($operationId !== '') {
        $completeOperation = $pdo->prepare(
            "UPDATE scan_company_create_operations
             SET company_id = :company_id,
                 employee_id = :employee_id,
                 status = 'completed'
             WHERE account_id = :account_id
               AND operation_id = :operation_id"
        );
        $completeOperation->execute([
            'company_id' => $companyId,
            'employee_id' => $employeeId,
            'account_id' => $ctx['account_id'],
            'operation_id' => strtolower($operationId),
        ]);
        if ($completeOperation->rowCount() !== 1) {
            throw new RuntimeException('company_operation_completion_failed');
        }
    }
    $newToken = ScanAuth::issueToken(
        $employeeId,
        'mobile',
        (string) $ctx['account_id']
    );
    $pdo->commit();
    echo json_encode([
        'success'     => true,
        'token'       => $newToken,
        'employee_id' => $employeeId,
        'account_id'  => (string) $ctx['account_id'],
        'company_id'  => $companyId,
        'slug'        => $newSlug,
        'operation_id' => $operationId !== ''
            ? strtolower($operationId)
            : null,
        'recovered' => false,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[scan/create-company] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
}
