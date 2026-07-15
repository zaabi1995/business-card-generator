<?php
/**
 * POST /api/scan/link-verify.php {identifier, code}. Auth'd: verify the OTP and
 * ATTACH the email/phone to the current account (only if the matching column is
 * empty, never overwriting an existing one, and never claiming another account's
 * identifier). Bearer-auth. -> {success, email, phone}
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';
require_once INCLUDES_DIR . '/OtpService.php';
require_once INCLUDES_DIR . '/Phone.php';
require_once __DIR__ . '/_link_common.php';
header('Content-Type: application/json');
$ctx = ScanAuth::requireEmployee();
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$raw = trim((string)($body['identifier'] ?? ''));
$code = trim((string)($body['code'] ?? ''));
if ($raw === '' || $code === '') { echo json_encode(['success' => false, 'error' => 'identifier_and_code_required']); exit; }
[$identifier, $isEmail] = linkParseIdentifier($raw);
if ($identifier === null) { echo json_encode(['success' => false, 'error' => 'invalid_identifier']); exit; }
$verify = OtpService::verify($identifier, $code, 'scan_link');
if (empty($verify['success'])) { echo json_encode(['success' => false, 'error' => $verify['error'] ?? 'invalid_code']); exit; }
$db = Database::getInstance();
// Re-check both guards after the OTP (state may have changed since request).
if (linkAccountHasType($db, $ctx['employee_id'], $isEmail, $identifier)) {
    echo json_encode(['success' => false, 'error' => $isEmail ? 'already_have_email' : 'already_have_phone']); exit; }
$owner = linkFindOwner($db, $identifier, $isEmail);
if ($owner !== null && $owner !== (string)$ctx['employee_id']) {
    echo json_encode(['success' => false, 'error' => 'identifier_taken']); exit; }
$col = $isEmail ? 'email' : 'mobile';
$db->getConnection()->prepare("UPDATE employees SET $col = ? WHERE id = ?")->execute([$identifier, $ctx['employee_id']]);
$row = $db->fetchOne("SELECT email, mobile, phone FROM employees WHERE id = :id", ['id' => $ctx['employee_id']]) ?: [];
echo json_encode(['success' => true,
    'email' => !empty($row['email']) ? $row['email'] : null,
    'phone' => !empty($row['mobile']) ? $row['mobile'] : (!empty($row['phone']) ? $row['phone'] : null),
], JSON_UNESCAPED_UNICODE);
