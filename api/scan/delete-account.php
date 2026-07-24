<?php
/**
 * POST /api/scan/delete-account.php
 *
 * Build 51 sends {confirm:true, operation_id:"uuid-v4"}. Build 50 sends only
 * {confirm:true}, so the server creates its operation after authentication.
 * Company, employee, and linked web-user records are preserved.
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';
require_once INCLUDES_DIR . '/ScanAccountDeletionCleanup.php';
require_once INCLUDES_DIR . '/RateLimiter.php';
require_once INCLUDES_DIR . '/UrlSafety.php';
require_once __DIR__ . '/_request.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST only']);
    exit;
}

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body) || !scanRequestHasExactTrue($body, 'confirm')) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'confirm_required']);
    exit;
}

$operationIdProvided = array_key_exists('operation_id', $body);
$operationId = null;
if ($operationIdProvided) {
    if (!is_string($body['operation_id'])) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'error' => 'invalid_operation_id',
        ]);
        exit;
    }
    $operationId = strtolower(trim($body['operation_id']));
    if (
        preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-'
                . '[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
            $operationId
        ) !== 1
    ) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'error' => 'invalid_operation_id',
        ]);
        exit;
    }
}

$presentedTokenHash = ScanAuth::presentedBearerTokenHash();
if ($presentedTokenHash !== null) {
    $confirmationIp = getClientIp();
    if (
        !RateLimiter::check(
            'scan_delete_account_confirmation',
            $confirmationIp,
            30,
            900
        )
    ) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'rate_limited']);
        exit;
    }
}

$db = Database::getInstance();

if ($presentedTokenHash !== null) {
    try {
        if ($operationIdProvided) {
            $completed = $db->fetchOne(
                "SELECT operation_id, status,
                        deleted_account_id, deleted_employee_ids
                 FROM scan_account_delete_operations
                 WHERE operation_id = :operation_id
                   AND confirmation_token_hash = :confirmation_token_hash
                   AND status = 'completed'
                 LIMIT 1",
                [
                    'operation_id' => $operationId,
                    'confirmation_token_hash' => $presentedTokenHash,
                ]
            );
        } else {
            $completed = $db->fetchOne(
                "SELECT operation_id, status,
                        deleted_account_id, deleted_employee_ids
                 FROM scan_account_delete_operations
                 WHERE confirmation_token_hash = :confirmation_token_hash
                   AND status = 'completed'
                 ORDER BY updated_at DESC
                 LIMIT 1",
                ['confirmation_token_hash' => $presentedTokenHash]
            );
        }
        if (
            is_array($completed)
            && (string) ($completed['status'] ?? '') === 'completed'
        ) {
            $operationId = (string) $completed['operation_id'];
            $cleanupComplete = false;
            for (
                $cleanupAttempt = 0;
                $cleanupAttempt < 2 && !$cleanupComplete;
                $cleanupAttempt++
            ) {
                $cleanupComplete =
                    ScanAccountDeletionCleanup::processOperation(
                        $db,
                        (string) $operationId,
                        50
                    );
            }
            if (!$cleanupComplete) {
                http_response_code(503);
                echo json_encode([
                    'success' => false,
                    'error' => 'deletion_cleanup_pending',
                    'operation_id' => $operationId,
                ]);
                exit;
            }
            $deletedAccountId = trim(
                (string) ($completed['deleted_account_id'] ?? '')
            );
            $decodedEmployeeIds = json_decode(
                (string) ($completed['deleted_employee_ids'] ?? ''),
                true
            );
            $deletedEmployeeIds = [];
            if (is_array($decodedEmployeeIds)) {
                foreach ($decodedEmployeeIds as $employeeId) {
                    if (!is_string($employeeId)) {
                        continue;
                    }
                    $employeeId = trim($employeeId);
                    if ($employeeId !== '') {
                        $deletedEmployeeIds[$employeeId] = true;
                    }
                }
            }
            $deletedEmployeeIds = array_keys($deletedEmployeeIds);
            if ($deletedAccountId === '' || $deletedEmployeeIds === []) {
                throw new RuntimeException(
                    'account_delete_confirmation_identity_unavailable'
                );
            }
            echo json_encode([
                'success' => true,
                'account_deleted' => true,
                'deletion_confirmed' => true,
                'operation_id' => $operationId,
                'account_id' => $deletedAccountId,
                'deleted_employee_ids' => $deletedEmployeeIds,
                'companies_preserved' => true,
                'employees_preserved' => true,
            ]);
            exit;
        }
    } catch (Throwable $e) {
        error_log(
            '[scan/delete-account] deletion confirmation: '
            . $e->getMessage()
        );
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'server_error']);
        exit;
    }
}

$ctx = ScanAuth::requireEmployeeMutation();
require_once __DIR__ . '/_ratelimit.php';
scanRateLimit($ctx, 'delete_account', 5, 86400);
$authenticatedTokenHash = $ctx['token_hash'] ?? null;
if (
    !is_string($authenticatedTokenHash)
    || $authenticatedTokenHash === ''
    || $presentedTokenHash === null
    || !hash_equals($authenticatedTokenHash, $presentedTokenHash)
) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}
$presentedTokenHash = $authenticatedTokenHash;

if (!$operationIdProvided) {
    $operationId =
        ScanAccountDeletionCleanup::generateOperationId();
}

try {
    ScanAccountDeletionCleanup::processBacklog($db, 2);

    $pdo = $db->getConnection();
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is unavailable');
    }
    $accountId = (string) $ctx['account_id'];
    $deletedEmployeeIds = [];
    $account = null;

    $pdo->beginTransaction();
    try {
        $account = $db->fetchOne(
            "SELECT id, user_id
             FROM scan_accounts
             WHERE id = :account_id
             LIMIT 1
             FOR UPDATE",
            ['account_id' => $accountId]
        );
        if (!is_array($account)) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'error' => 'account_not_found',
            ]);
            exit;
        }

        $reserveOperation = $pdo->prepare(
            'INSERT IGNORE INTO scan_account_delete_operations
                (
                    operation_id,
                    confirmation_token_hash,
                    account_id,
                    status
                )
             VALUES (
                 :operation_id,
                 :confirmation_token_hash,
                 :account_id,
                 :status
             )'
        );
        $reserveOperation->execute([
            'operation_id' => $operationId,
            'confirmation_token_hash' => $presentedTokenHash,
            'account_id' => $accountId,
            'status' => 'pending',
        ]);

        $operation = $db->fetchOne(
            "SELECT confirmation_token_hash, account_id, status
             FROM scan_account_delete_operations
             WHERE operation_id = :operation_id
             FOR UPDATE",
            ['operation_id' => $operationId]
        );
        if (!is_array($operation)) {
            throw new RuntimeException(
                'account_delete_operation_unavailable'
            );
        }
        if (
            !is_string($operation['account_id'])
            || !hash_equals($accountId, $operation['account_id'])
            || !is_string($operation['confirmation_token_hash'])
            || !hash_equals(
                $presentedTokenHash,
                $operation['confirmation_token_hash']
            )
            || (string) $operation['status'] !== 'pending'
        ) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'error' => 'operation_owner_conflict',
            ]);
            exit;
        }

        $memberships = $db->fetchAll(
            "SELECT employee_id
             FROM scan_account_memberships
             WHERE account_id = :account_id
             ORDER BY created_at ASC, employee_id ASC",
            ['account_id' => $accountId]
        );
        $employeeIdSet = [];
        foreach ($memberships as $membership) {
            $employeeId = trim(
                (string) ($membership['employee_id'] ?? '')
            );
            if ($employeeId !== '') {
                $employeeIdSet[$employeeId] = true;
            }
        }
        $deletedEmployeeIds = array_keys($employeeIdSet);
        if ($deletedEmployeeIds === []) {
            throw new RuntimeException(
                'account_delete_memberships_unavailable'
            );
        }

        $shadowProfileIds = [];
        foreach ($deletedEmployeeIds as $employeeId) {
            $scans = $db->fetchAll(
                "SELECT image_path, image_path_back, shadow_profile_id
                 FROM scans
                 WHERE employee_id = ?
                 FOR UPDATE",
                [$employeeId]
            );
            foreach ($scans as $scan) {
                ScanAccountDeletionCleanup::queueOwnedPath(
                    $pdo,
                    (string) $operationId,
                    $employeeId,
                    isset($scan['image_path'])
                        ? (string) $scan['image_path']
                        : null
                );
                ScanAccountDeletionCleanup::queueOwnedPath(
                    $pdo,
                    (string) $operationId,
                    $employeeId,
                    isset($scan['image_path_back'])
                        ? (string) $scan['image_path_back']
                        : null
                );
                $shadowProfileId = (int) (
                    $scan['shadow_profile_id'] ?? 0
                );
                if ($shadowProfileId > 0) {
                    $shadowProfileIds[$shadowProfileId] = true;
                }
            }

            $passes = $db->fetchAll(
                "SELECT serial_number
                 FROM scan_passes
                 WHERE employee_id = :employee_id",
                ['employee_id' => $employeeId]
            );
            foreach ($passes as $pass) {
                $serialNumber = (string) (
                    $pass['serial_number'] ?? ''
                );
                if ($serialNumber === '') {
                    continue;
                }
                $pdo->prepare(
                    'DELETE FROM scan_pass_registrations '
                        . 'WHERE serial_number = ?'
                )->execute([$serialNumber]);
                $pdo->prepare(
                    'DELETE FROM scan_pass_changes '
                        . 'WHERE serial_number = ?'
                )->execute([$serialNumber]);
            }
            $pdo->prepare(
                'DELETE FROM scan_claim_tickets '
                    . 'WHERE claimed_employee_id = ?'
            )->execute([$employeeId]);
            $pdo->prepare(
                'DELETE FROM push_tokens WHERE employee_id = ?'
            )->execute([$employeeId]);
            ScanAccountDeletionCleanup::queueRenderInvalidation(
                $pdo,
                (string) $operationId,
                $employeeId
            );
            $pdo->prepare(
                'DELETE FROM card_designs WHERE employee_id = ?'
            )->execute([$employeeId]);
            $pdo->prepare(
                'DELETE FROM scans WHERE employee_id = ?'
            )->execute([$employeeId]);
            $pdo->prepare(
                'DELETE FROM scan_passes WHERE employee_id = ?'
            )->execute([$employeeId]);
            $pdo->prepare(
                'UPDATE employees
                 SET scan_pro_until = NULL, scan_pro_source = NULL
                 WHERE id = ?'
            )->execute([$employeeId]);
        }

        foreach (array_keys($shadowProfileIds) as $shadowProfileId) {
            $remainingScans = $db->fetchAll(
                "SELECT id AS remaining_scan
                 FROM scans
                 WHERE shadow_profile_id = ?
                 FOR UPDATE",
                [$shadowProfileId]
            );
            if ($remainingScans !== []) {
                continue;
            }
            $profile = $db->fetchOne(
                "SELECT id, claimed_at, claimed_company_id
                 FROM shadow_profiles
                 WHERE id = ?
                 FOR UPDATE",
                [$shadowProfileId]
            );
            if (!is_array($profile)) {
                continue;
            }
            $legitimatelyClaimed =
                !empty($profile['claimed_at'])
                && trim(
                    (string) ($profile['claimed_company_id'] ?? '')
                ) !== '';
            if ($legitimatelyClaimed) {
                continue;
            }
            $anonymize = $pdo->prepare(
                "UPDATE shadow_profiles
                 SET phone_primary = NULL,
                     email_primary = NULL,
                     best_parsed = NULL,
                     claim_token = ?,
                     claimed_at = NULL,
                     claimed_company_id = NULL,
                     invite_sent_at = NULL,
                     invited_by_employee_id = NULL,
                     opted_out = 1
                 WHERE id = ?
                   AND NOT (
                       claimed_at IS NOT NULL
                       AND claimed_company_id IS NOT NULL
                       AND TRIM(claimed_company_id) <> ''
                   )
                   AND NOT EXISTS (
                       SELECT 1 AS remaining_scan
                       FROM scans
                       WHERE shadow_profile_id = ?
                   )"
            );
            $anonymize->execute([
                ScanAuth::generateToken(),
                $shadowProfileId,
                $shadowProfileId,
            ]);
            if ($anonymize->rowCount() === 1) {
                $pdo->prepare(
                    'DELETE FROM scan_claim_tickets '
                        . 'WHERE shadow_profile_id = ?'
                )->execute([$shadowProfileId]);
            }
        }

        $pdo->prepare(
            'DELETE FROM scan_pro_receipts WHERE account_id = ?'
        )->execute([$accountId]);
        $pdo->prepare(
            "UPDATE scan_company_create_operations
             SET account_id = NULL,
                 company_name = NULL,
                 requested_slug = NULL,
                 company_id = NULL,
                 employee_id = NULL,
                 status = 'account_deleted'
             WHERE account_id = ?"
        )->execute([$accountId]);
        $pdo->prepare(
            'DELETE FROM scan_account_entitlements WHERE account_id = ?'
        )->execute([$accountId]);
        $pdo->prepare(
            'DELETE FROM scan_api_tokens WHERE account_id = ?'
        )->execute([$accountId]);
        $pdo->prepare(
            'DELETE FROM scan_account_identifiers WHERE account_id = ?'
        )->execute([$accountId]);
        $pdo->prepare(
            'DELETE FROM scan_identity_user_link_audit '
                . 'WHERE account_id = ?'
        )->execute([$accountId]);
        $pdo->prepare(
            'DELETE FROM scan_identity_migration_audit
             WHERE canonical_account_id = ? OR merged_account_id = ?'
        )->execute([$accountId, $accountId]);
        $pdo->prepare(
            'DELETE FROM scan_account_memberships WHERE account_id = ?'
        )->execute([$accountId]);
        $pdo->prepare(
            'DELETE FROM scan_accounts WHERE id = ?'
        )->execute([$accountId]);

        $deletedEmployeeIdsJson = json_encode(
            $deletedEmployeeIds,
            JSON_UNESCAPED_SLASHES
        );
        if (!is_string($deletedEmployeeIdsJson)) {
            throw new RuntimeException(
                'account_delete_identity_encoding_failed'
            );
        }
        $completeOperation = $pdo->prepare(
            "UPDATE scan_account_delete_operations
             SET account_id = NULL,
                 deleted_account_id = ?,
                 deleted_employee_ids = ?,
                 status = 'completed'
             WHERE operation_id = ?
               AND account_id = ?"
        );
        $completeOperation->execute([
            $accountId,
            $deletedEmployeeIdsJson,
            $operationId,
            $accountId,
        ]);
        if ($completeOperation->rowCount() !== 1) {
            throw new RuntimeException(
                'account_delete_operation_completion_failed'
            );
        }
        $pdo->commit();
    } catch (Throwable $deleteError) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $deleteError;
    }

    $cleanupComplete = false;
    for (
        $cleanupAttempt = 0;
        $cleanupAttempt < 2 && !$cleanupComplete;
        $cleanupAttempt++
    ) {
        $cleanupComplete = ScanAccountDeletionCleanup::processOperation(
            $db,
            (string) $operationId,
            50
        );
    }
    if (!$cleanupComplete) {
        http_response_code(503);
        echo json_encode([
            'success' => false,
            'error' => 'deletion_cleanup_pending',
            'operation_id' => $operationId,
        ]);
        exit;
    }

    $deletedAccountId = $accountId;
    echo json_encode([
        'success' => true,
        'account_deleted' => true,
        'deletion_confirmed' => true,
        'operation_id' => $operationId,
        'account_id' => $deletedAccountId,
        'deleted_employee_ids' => $deletedEmployeeIds,
        'companies_preserved' => true,
        'employees_preserved' => true,
        'web_user_preserved' => !empty($account['user_id']),
    ]);
} catch (Throwable $e) {
    error_log('[scan/delete-account] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
}
