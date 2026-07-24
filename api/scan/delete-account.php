<?php
/**
 * POST /api/scan/delete-account.php {confirm:true}
 *
 * Permanently removes only the authenticated immutable scan account and its
 * native-app data. Company, employee, and linked web-user records are preserved.
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
scanRateLimit($ctx, 'delete_account', 5, 86400);

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body) || empty($body['confirm'])) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'confirm_required']);
    exit;
}

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    $accountId = (string) $ctx['account_id'];
    $account = $db->fetchOne(
        "SELECT id, user_id
         FROM scan_accounts
         WHERE id = :account_id
         LIMIT 1",
        ['account_id' => $accountId]
    );
    if (!is_array($account)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'account_not_found']);
        exit;
    }

    $memberships = $db->fetchAll(
        "SELECT employee_id
         FROM scan_account_memberships
         WHERE account_id = :account_id
         ORDER BY created_at ASC",
        ['account_id' => $accountId]
    );
    $employeeIds = [];
    foreach ($memberships as $membership) {
        $employeeId = trim((string) ($membership['employee_id'] ?? ''));
        if ($employeeId !== '') {
            $employeeIds[$employeeId] = true;
        }
    }

    $pdo->beginTransaction();
    try {
        foreach (array_keys($employeeIds) as $employeeId) {
            $passes = $db->fetchAll(
                "SELECT serial_number
                 FROM scan_passes
                 WHERE employee_id = :employee_id",
                ['employee_id' => $employeeId]
            );
            foreach ($passes as $pass) {
                $serialNumber = (string) ($pass['serial_number'] ?? '');
                if ($serialNumber === '') {
                    continue;
                }
                $pdo->prepare(
                    'DELETE FROM scan_pass_registrations WHERE serial_number = ?'
                )->execute([$serialNumber]);
                $pdo->prepare(
                    'DELETE FROM scan_pass_changes WHERE serial_number = ?'
                )->execute([$serialNumber]);
            }

            $pdo->prepare('DELETE FROM scan_claim_tickets WHERE claimed_employee_id = ?')
                ->execute([$employeeId]);
            $pdo->prepare('DELETE FROM push_tokens WHERE employee_id = ?')
                ->execute([$employeeId]);
            $pdo->prepare('DELETE FROM card_designs WHERE employee_id = ?')
                ->execute([$employeeId]);
            $pdo->prepare('DELETE FROM scans WHERE employee_id = ?')
                ->execute([$employeeId]);
            $pdo->prepare('DELETE FROM scan_passes WHERE employee_id = ?')
                ->execute([$employeeId]);
            $pdo->prepare(
                'UPDATE employees
                 SET scan_pro_until = NULL, scan_pro_source = NULL
                 WHERE id = ?'
            )->execute([$employeeId]);
        }

        $pdo->prepare('DELETE FROM scan_pro_receipts WHERE account_id = ?')
            ->execute([$accountId]);
        $pdo->prepare('DELETE FROM scan_account_entitlements WHERE account_id = ?')
            ->execute([$accountId]);
        $pdo->prepare('DELETE FROM scan_api_tokens WHERE account_id = ?')
            ->execute([$accountId]);
        $pdo->prepare('DELETE FROM scan_account_identifiers WHERE account_id = ?')
            ->execute([$accountId]);
        $pdo->prepare('DELETE FROM scan_identity_user_link_audit WHERE account_id = ?')
            ->execute([$accountId]);
        $pdo->prepare(
            'DELETE FROM scan_identity_migration_audit
             WHERE canonical_account_id = ? OR merged_account_id = ?'
        )->execute([$accountId, $accountId]);
        $pdo->prepare('DELETE FROM scan_account_memberships WHERE account_id = ?')
            ->execute([$accountId]);
        $pdo->prepare('DELETE FROM scan_accounts WHERE id = ?')
            ->execute([$accountId]);
        $pdo->commit();
    } catch (Throwable $deleteError) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $deleteError;
    }

    echo json_encode([
        'success' => true,
        'account_deleted' => true,
        'companies_preserved' => true,
        'employees_preserved' => true,
        'web_user_preserved' => !empty($account['user_id']),
    ]);
} catch (Throwable $e) {
    error_log('[scan/delete-account] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
}
