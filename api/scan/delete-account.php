<?php
/**
 * POST /api/scan/delete-account.php  {confirm:true}
 * -> Permanently delete the signed-in person's Cardify account and ALL their
 * data. Required by App Store Guideline 5.1.1(v): an app that supports account
 * creation must also let the user DELETE the account (not merely deactivate).
 * This is a hard delete, there is no recovery.
 *
 * Strategy (self-maintaining as the schema grows): sweep every table that has
 * an `employee_id` column and remove this employee's rows (scans, tokens, push
 * tokens, card designs, card sub-records, ...), delete the employee row itself,
 * then, if their company is left with no other active employees (a personal /
 * solo company), delete the company and everything scoped to it.
 *
 * Bearer-authenticated (ScanAuth), rate limited, requires an explicit confirm.
 * Response: {success, account_deleted, company_deleted}
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
scanRateLimit($ctx, 'delete_account', 20);

// Two-step: the app shows a destructive confirmation, and the endpoint refuses
// to run without an explicit confirm so a stray call can never wipe an account.
$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body) || empty($body['confirm'])) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'confirm_required']);
    exit;
}

try {
    $db  = Database::getInstance();
    $me  = (string) $ctx['employee_id'];
    $cid = (string) ($ctx['company_id'] ?? '');

    // 1) Delete this employee's rows from every table keyed by employee_id
    //    (except the employees table itself, removed explicitly in step 2).
    $empTables = $db->fetchAll(
        "SELECT table_name AS t FROM information_schema.columns
         WHERE table_schema = DATABASE() AND column_name = 'employee_id'
           AND table_name <> 'employees'"
    );
    foreach ((array) $empTables as $row) {
        $t = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($row['t'] ?? ''));
        if ($t === '') continue;
        try {
            $db->query("DELETE FROM `$t` WHERE employee_id = :me", ['me' => $me]);
        } catch (Throwable $e) {
            error_log("[delete-account] $t: " . $e->getMessage());
        }
    }

    // 2) Delete the account (employee) row itself.
    $db->query('DELETE FROM employees WHERE id = :me', ['me' => $me]);

    // 3) If the company now has no other active employees it was a personal /
    //    solo company: delete the company and everything scoped to it.
    $companyDeleted = false;
    if ($cid !== '') {
        $rest = $db->fetchOne(
            "SELECT COUNT(*) AS n FROM employees
             WHERE company_id = :cid AND (status IS NULL OR status <> 'deleted')",
            ['cid' => $cid]
        );
        if (((int) ($rest['n'] ?? 0)) === 0) {
            $coTables = $db->fetchAll(
                "SELECT table_name AS t FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND column_name = 'company_id'
                   AND table_name <> 'companies'"
            );
            foreach ((array) $coTables as $row) {
                $t = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($row['t'] ?? ''));
                if ($t === '') continue;
                try {
                    $db->query("DELETE FROM `$t` WHERE company_id = :cid", ['cid' => $cid]);
                } catch (Throwable $e) {
                    error_log("[delete-account co] $t: " . $e->getMessage());
                }
            }
            try {
                $db->query('DELETE FROM companies WHERE id = :cid', ['cid' => $cid]);
                $companyDeleted = true;
            } catch (Throwable $e) {
                error_log('[delete-account co-row]: ' . $e->getMessage());
            }
        }
    }

    echo json_encode([
        'success'         => true,
        'account_deleted' => true,
        'company_deleted' => $companyDeleted,
    ]);
} catch (Throwable $e) {
    error_log('[delete-account] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
}
