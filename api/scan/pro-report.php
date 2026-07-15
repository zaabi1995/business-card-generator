<?php
/**
 * POST /api/scan/pro-report.php {active:bool, jws?:string}. The app reports its
 * live Apple StoreKit entitlement so the ACCOUNT is marked Pro and the web
 * honours it. active=true REQUIRES a valid StoreKit 2 JWSTransaction (verified
 * signature + Apple-rooted chain + our bundle/product + unexpired); an
 * unverifiable claim is rejected (closes the free-Pro bypass). active=false
 * clears ONLY an apple-sourced grant (never a longer web/Paymob one). Bearer.
 * -> {success, pro:bool}
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';
require_once __DIR__ . '/AppleStoreKitVerify.php';
header('Content-Type: application/json');
$ctx = ScanAuth::requireEmployee();
$body = json_decode(file_get_contents('php://input'), true);
$active = is_array($body) && !empty($body['active']);
$jws = is_array($body) ? (string)($body['jws'] ?? '') : '';
$SKUS = ['om.cardify.scan.pro.monthly', 'om.cardify.scan.pro.yearly'];
$db = Database::getInstance();
$row = $db->fetchOne("SELECT scan_pro_until, scan_pro_source FROM employees WHERE id = :id", ['id' => $ctx['employee_id']]) ?: [];
$now = time();
$cur = !empty($row['scan_pro_until']) ? strtotime($row['scan_pro_until']) : 0;
$src = $row['scan_pro_source'] ?? null;
$pro = $cur > $now;

if ($active) {
    // Must present a verifiable Apple transaction. No valid receipt -> no grant.
    $payload = $jws !== '' ? AppleStoreKitVerify::verify($jws, $SKUS) : null;
    if ($payload === null) {
        echo json_encode(['success' => false, 'error' => 'invalid_receipt']);
        exit;
    }
    // Bind this Apple original transaction to ONE account so a valid JWS cannot
    // be replayed to unlock Pro on multiple accounts.
    $otid = (string)($payload['originalTransactionId'] ?? '');
    if ($otid !== '') {
        // Atomic claim: the FIRST account to report this receipt owns it
        // (INSERT IGNORE is a no-op for a later account); we then re-read the
        // owner and reject anyone else. No check-then-write race, and no
        // re-binding (which previously let two accounts share one receipt).
        $db->getConnection()
           ->prepare("INSERT IGNORE INTO scan_pro_receipts (original_transaction_id, employee_id, updated_at) VALUES (?, ?, NOW())")
           ->execute([$otid, $ctx['employee_id']]);
        $owner = $db->fetchOne("SELECT employee_id FROM scan_pro_receipts WHERE original_transaction_id = :o", ['o' => $otid]);
        if ($owner && (string)$owner['employee_id'] !== (string)$ctx['employee_id']) {
            echo json_encode(['success' => false, 'error' => 'receipt_in_use']);
            exit;
        }
    }
    // Trust the receipt's own expiry (bounded to <=40d rolling so a lapse is
    // caught even if the app stops reporting), never a client-supplied window.
    $recvExp = (int)($payload['expiresDate'] ?? 0) / 1000;
    $until = min($recvExp, $now + 40 * 86400);
    if (!(($src === 'web' || $src === 'paymob') && $cur > $until)) {
        $db->getConnection()
           ->prepare("UPDATE employees SET scan_pro_until = ?, scan_pro_source = 'apple' WHERE id = ?")
           ->execute([date('Y-m-d H:i:s', $until), $ctx['employee_id']]);
        $pro = true;
    }
} else {
    if ($src === 'apple') {
        $db->getConnection()
           ->prepare("UPDATE employees SET scan_pro_until = NULL, scan_pro_source = NULL WHERE id = ?")
           ->execute([$ctx['employee_id']]);
        $pro = false;
    }
}
echo json_encode(['success' => true, 'pro' => $pro]);
