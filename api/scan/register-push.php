<?php
/**
 * POST /api/scan/register-push.php, register an Expo push token for a device
 *
 * Body: {token, platform} -> {success}. Bearer-auth (ScanAuth). Upserts on the
 * unique token, so a token that moves to a different employee (shared device,
 * re-login) is re-pointed rather than duplicated. Infrastructure only: the
 * SEND side (server -> device) is out of scope here.
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
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$token = trim((string)($body['token'] ?? ''));
$platform = substr(trim((string)($body['platform'] ?? '')), 0, 20) ?: null;

if ($token === '' || strlen($token) > 255) {
    echo json_encode(['success' => false, 'error' => 'invalid_token']);
    exit;
}

try {
    $db = Database::getInstance();
    $db->getConnection()->prepare(
        "INSERT INTO push_tokens (employee_id, token, platform)
         VALUES (:e, :t, :p)
         ON DUPLICATE KEY UPDATE
            employee_id = VALUES(employee_id),
            platform = VALUES(platform),
            updated_at = CURRENT_TIMESTAMP"
    )->execute(['e' => $ctx['employee_id'], 't' => $token, 'p' => $platform]);
} catch (\Throwable $e) {
    error_log('[scan/register-push] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
    exit;
}

echo json_encode(['success' => true]);
