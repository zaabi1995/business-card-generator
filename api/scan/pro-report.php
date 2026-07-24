<?php
/**
 * POST /api/scan/pro-report.php
 * {active:bool, jws?:string, renewal_info_jws?:string}
 *
 * StoreKit entitlement belongs to the immutable account, so it follows the
 * person across company memberships and is honored by the website mirror.
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';
require_once __DIR__ . '/AppleStoreKitVerify.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST only']);
    exit;
}

$ctx = ScanAuth::requireEmployeeMutation();
require_once __DIR__ . '/_ratelimit.php';
scanRateLimit($ctx, 'pro_report', 120);

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = [];
}
$active = !empty($body['active']);
$jws = (string) ($body['jws'] ?? '');
$renewalInfoJws = (string) ($body['renewal_info_jws'] ?? '');
$allowedProducts = [
    'om.cardify.scan.pro.monthly',
    'om.cardify.scan.pro.yearly',
];
$accountId = (string) $ctx['account_id'];
$db = Database::getInstance();
$current = $db->fetchOne(
    "SELECT source, status, valid_until, original_transaction_id
     FROM scan_account_entitlements
     WHERE account_id = :account_id
       AND entitlement = 'scan_pro'
     LIMIT 1",
    ['account_id' => $accountId]
) ?: [];
$currentUntil = !empty($current['valid_until'])
    ? (int) strtotime((string) $current['valid_until'])
    : 0;
$currentSource = (string) ($current['source'] ?? '');
$pro = ($current['status'] ?? '') === 'active'
    && $currentUntil > time();

if ($active) {
    $payload = $jws !== ''
        ? AppleStoreKitVerify::verifyTransaction($jws, $allowedProducts, false)
        : null;
    $renewalPayload = $renewalInfoJws !== ''
        ? AppleStoreKitVerify::verifyRenewalInfo($renewalInfoJws, $allowedProducts)
        : null;
    $originalTransactionId = (string) (
        $payload['originalTransactionId'] ?? ''
    );
    if (
        $payload === null
        || $originalTransactionId === ''
        || ($renewalInfoJws !== '' && $renewalPayload === null)
    ) {
        echo json_encode(['success' => false, 'error' => 'invalid_receipt']);
        exit;
    }
    if ($renewalPayload !== null) {
        $renewalOriginalId = (string) (
            $renewalPayload['originalTransactionId'] ?? ''
        );
        $transactionProductId = (string) ($payload['productId'] ?? '');
        $renewalProductId = (string) ($renewalPayload['productId'] ?? '');
        $transactionEnvironment = (string) ($payload['environment'] ?? '');
        $renewalEnvironment = (string) ($renewalPayload['environment'] ?? '');
        if (
            $renewalOriginalId === ''
            || !hash_equals($originalTransactionId, $renewalOriginalId)
            || $transactionProductId !== $renewalProductId
            || $transactionEnvironment !== $renewalEnvironment
        ) {
            echo json_encode(['success' => false, 'error' => 'invalid_receipt']);
            exit;
        }
    }

    $primaryEmployee = ScanIdentity::primaryEmployee($db, $accountId);
    if ($primaryEmployee === null) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'account_unavailable']);
        exit;
    }
    $receiptEmployeeId = (string) $primaryEmployee['employee_id'];
    $pdo = $db->getConnection();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "INSERT IGNORE INTO scan_pro_receipts
                (original_transaction_id, employee_id, account_id, updated_at)
             VALUES (?, ?, ?, NOW())"
        )->execute([
            $originalTransactionId,
            $receiptEmployeeId,
            $accountId,
        ]);
        $owner = $db->fetchOne(
            "SELECT employee_id, account_id
             FROM scan_pro_receipts
             WHERE original_transaction_id = :transaction_id
             FOR UPDATE",
            ['transaction_id' => $originalTransactionId]
        );
        $ownerAccountId = is_array($owner)
            ? trim((string) ($owner['account_id'] ?? ''))
            : '';
        if ($ownerAccountId === '' && is_array($owner)) {
            $legacyMembership = $db->fetchOne(
                "SELECT account_id
                 FROM scan_account_memberships
                 WHERE employee_id = :employee_id
                 LIMIT 1",
                ['employee_id' => (string) $owner['employee_id']]
            );
            $legacyAccountId = is_array($legacyMembership)
                ? (string) $legacyMembership['account_id']
                : '';
            if ($legacyAccountId !== '' && hash_equals($accountId, $legacyAccountId)) {
                $db->update(
                    'scan_pro_receipts',
                    ['account_id' => $accountId],
                    'original_transaction_id = :where_transaction_id',
                    ['where_transaction_id' => $originalTransactionId]
                );
                $ownerAccountId = $accountId;
            }
        }
        if ($ownerAccountId === '' || !hash_equals($accountId, $ownerAccountId)) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'receipt_in_use']);
            exit;
        }

        $transactionExpiresAt = (int) (($payload['expiresDate'] ?? 0) / 1000);
        $productId = (string) ($payload['productId'] ?? '');
        $maximumWindow = $productId === 'om.cardify.scan.pro.yearly'
            ? 375 * 86400
            : 40 * 86400;
        $expiresAt = $transactionExpiresAt;
        if ($expiresAt <= time()) {
            $graceExpiresAt = $renewalPayload !== null
                ? (int) (($renewalPayload['gracePeriodExpiresDate'] ?? 0) / 1000)
                : 0;
            $inBillingRetry = $renewalPayload !== null
                && !empty($renewalPayload['isInBillingRetryPeriod']);
            $graceWindowIsValid = $graceExpiresAt > time()
                && $graceExpiresAt <= $transactionExpiresAt + (60 * 86400);
            if (!$inBillingRetry || !$graceWindowIsValid) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'invalid_receipt']);
                exit;
            }
            $expiresAt = $graceExpiresAt;
        }
        $until = min($expiresAt, time() + $maximumWindow);
        if ($until <= time()) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'invalid_receipt']);
            exit;
        }

        $keepLongerWebGrant = in_array(
            $currentSource,
            ['web', 'paymob'],
            true
        ) && $currentUntil > $until;
        if (!$keepLongerWebGrant) {
            $latestTransactionId = (string) ($payload['transactionId'] ?? '');
            $environment = (string) ($payload['environment'] ?? '');
            $statement = $pdo->prepare(
                "INSERT INTO scan_account_entitlements
                    (account_id, entitlement, source, status, valid_until,
                     original_transaction_id, latest_transaction_id,
                     environment, verified_at)
                 VALUES
                    (:account_id, 'scan_pro', 'apple', 'active', :valid_until,
                     :original_transaction_id, :latest_transaction_id,
                     :environment, NOW())
                 ON DUPLICATE KEY UPDATE
                    source = VALUES(source),
                    status = VALUES(status),
                    valid_until = VALUES(valid_until),
                    original_transaction_id = VALUES(original_transaction_id),
                    latest_transaction_id = VALUES(latest_transaction_id),
                    environment = VALUES(environment),
                    verified_at = VALUES(verified_at)"
            );
            $statement->execute([
                'account_id' => $accountId,
                'valid_until' => date('Y-m-d H:i:s', $until),
                'original_transaction_id' => $originalTransactionId,
                'latest_transaction_id' => $latestTransactionId !== ''
                    ? $latestTransactionId
                    : null,
                'environment' => $environment !== '' ? $environment : null,
            ]);
            $pdo->prepare(
                "UPDATE employees e
                 JOIN scan_account_memberships m ON m.employee_id = e.id
                 SET e.scan_pro_until = ?,
                     e.scan_pro_source = 'apple'
                 WHERE m.account_id = ?"
            )->execute([
                date('Y-m-d H:i:s', $until),
                $accountId,
            ]);
            $currentUntil = $until;
            $currentSource = 'apple';
        }
        $pdo->commit();
        $pro = true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[scan/pro-report] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'server_error']);
        exit;
    }
} elseif ($currentSource === 'apple') {
    $pdo = $db->getConnection();
    $pdo->beginTransaction();
    try {
        $db->update(
            'scan_account_entitlements',
            [
                'status' => 'inactive',
                'valid_until' => null,
                'verified_at' => date('Y-m-d H:i:s'),
            ],
            "account_id = :where_account_id AND entitlement = 'scan_pro'",
            ['where_account_id' => $accountId]
        );
        $pdo->prepare(
            "UPDATE employees e
             JOIN scan_account_memberships m ON m.employee_id = e.id
             SET e.scan_pro_until = NULL,
                 e.scan_pro_source = NULL
             WHERE m.account_id = ?
               AND e.scan_pro_source = 'apple'"
        )->execute([$accountId]);
        $pdo->commit();
        $pro = false;
        $currentUntil = 0;
        $currentSource = '';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[scan/pro-report] deactivate: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'server_error']);
        exit;
    }
}

echo json_encode([
    'success' => true,
    'pro' => $pro,
    'until' => $pro && $currentUntil > 0
        ? date('Y-m-d H:i:s', $currentUntil)
        : null,
    'source' => $pro ? $currentSource : null,
]);
