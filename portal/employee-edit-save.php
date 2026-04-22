<?php
/**
 * POST-only save endpoint for the passwordless employee-edit page.
 * Body: { token, fields: {...} }.
 * Rate limit: 10 saves per minute per token.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/EmployeeEditToken.php';
require_once INCLUDES_DIR . '/RateLimiter.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method not allowed']);
    exit;
}

$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!validateCSRFToken($csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid csrf']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$token = $body['token'] ?? '';
$fields = $body['fields'] ?? [];
if (!is_array($fields)) $fields = [];

$employee = EmployeeEditToken::verify($token);
if (!$employee) {
    http_response_code(410);
    echo json_encode(['ok' => false, 'error' => 'token_invalid_or_expired']);
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!RateLimiter::check('emp_edit:' . substr(hash('sha256', $token), 0, 16), $ip, 10, 60)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'rate_limited']);
    exit;
}

// Whitelist editable fields. Server trusts no client-provided schema.
$allowed = ['name_en','name_ar','position_en','position_ar','phone','mobile','email','website'];
$update = [];
foreach ($allowed as $k) {
    if (!array_key_exists($k, $fields)) continue;
    $v = $fields[$k];
    if ($v === null) continue;
    if (!is_string($v)) continue;
    $v = trim($v);
    // Field-specific validation.
    if ($k === 'email' && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) continue;
    if ($k === 'website' && $v !== '' && !filter_var($v, FILTER_VALIDATE_URL)) continue;
    if (strlen($v) > 255) $v = substr($v, 0, 255);
    $update[$k] = $v;
}

if (empty($update)) {
    echo json_encode(['ok' => true, 'noop' => true]);
    exit;
}

try {
    $db = Database::getInstance();
    $db->update('employees', $update, 'id = :id', ['id' => $employee['id']]);

    // Audit log, best-effort.
    if (class_exists('AuditLog')) {
        try {
            AuditLog::record('employee_self_edit', [
                'employee_id' => $employee['id'],
                'company_id'  => $employee['company_id'],
                'fields'      => array_keys($update),
                'ip'          => $ip,
            ]);
        } catch (Throwable $_) { /* best-effort */ }
    }

    echo json_encode(['ok' => true, 'updated' => array_keys($update)]);
} catch (Throwable $e) {
    error_log('[employee-edit-save] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'save_failed']);
}
