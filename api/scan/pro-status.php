<?php
/**
 * GET /api/scan/pro-status.php -> the signed-in account's cross-platform Pro
 * state (web Paymob OR in-app Apple, whichever granted it) plus the linked
 * identifiers. Bearer-auth.
 * -> {success, pro:bool, until, source, email, phone}
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';
header('Content-Type: application/json');
$ctx = ScanAuth::requireEmployee();
$db = Database::getInstance();
$row = $db->fetchOne(
    "SELECT valid_until AS scan_pro_until, source AS scan_pro_source
     FROM scan_account_entitlements
     WHERE account_id = :account_id
       AND entitlement = 'scan_pro'
       AND status = 'active'
     LIMIT 1",
    ['account_id' => $ctx['account_id']]
) ?: [];
$identifiers = ScanIdentity::linkedIdentifiers(
    $db,
    (string) $ctx['account_id']
);
$until = $row['scan_pro_until'] ?? null;
$pro = $until !== null && strtotime($until) > time();
echo json_encode([
    'success' => true,
    'pro'     => $pro,
    'until'   => $pro ? $until : null,
    'source'  => $pro ? ($row['scan_pro_source'] ?? null) : null,
    'email'   => $identifiers['email'],
    'phone'   => $identifiers['phone'],
], JSON_UNESCAPED_UNICODE);
