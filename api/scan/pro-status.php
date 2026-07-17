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
    "SELECT email, mobile, phone, scan_pro_until, scan_pro_source FROM employees WHERE id = :id",
    ['id' => $ctx['employee_id']]
) ?: [];
$until = $row['scan_pro_until'] ?? null;
$pro = $until !== null && strtotime($until) > time();
echo json_encode([
    'success' => true,
    'pro'     => $pro,
    'until'   => $pro ? $until : null,
    'source'  => $pro ? ($row['scan_pro_source'] ?? null) : null,
    'email'   => !empty($row['email']) ? $row['email'] : null,
    'phone'   => !empty($row['mobile']) ? $row['mobile'] : (!empty($row['phone']) ? $row['phone'] : null),
], JSON_UNESCAPED_UNICODE);
