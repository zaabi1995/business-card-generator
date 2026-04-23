<?php
/**
 * POST-only token-guarded endpoint for employee-initiated change
 * requests that need admin approval (currently only department).
 * Body JSON: { token, field, value }. On success returns the pending
 * request row so the UI can render a "Pending admin approval" badge.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/EmployeeEditToken.php';
require_once INCLUDES_DIR . '/RateLimiter.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!validateCSRFToken($csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid_csrf']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$token = trim((string) ($body['token'] ?? ''));
$field = strtolower(trim((string) ($body['field'] ?? '')));
$value = trim((string) ($body['value'] ?? ''));

$employee = EmployeeEditToken::verify($token);
if (!$employee) {
    http_response_code(410);
    echo json_encode(['ok' => false, 'error' => 'token_invalid_or_expired']);
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!RateLimiter::check('emp_req:' . substr(hash('sha256', $token), 0, 16), $ip, 5, 600)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'rate_limited']);
    exit;
}

$allowedFields = ['department', 'reprint', 'leave_company'];
if (!in_array($field, $allowedFields, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'field_not_requestable']);
    exit;
}

$db = Database::getInstance();
$label = null;
if ($field === 'reprint' || $field === 'leave_company') {
    $label = trim((string) ($body['note'] ?? ''));
    if (strlen($label) > 255) $label = substr($label, 0, 255);
    $value = 'pending';
} elseif ($field === 'department') {
    if ($value === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'value_required']);
        exit;
    }
    $dept = $db->fetchOne(
        "SELECT id, name FROM departments WHERE id = :id AND company_id = :cid LIMIT 1",
        ['id' => $value, 'cid' => $employee['company_id']]
    );
    if (!$dept) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'department_not_found']);
        exit;
    }
    $label = $dept['name'];
}

try {
    // Supersede any existing pending request for the same field
    $db->query(
        "UPDATE employee_edit_requests SET status = 'rejected', decided_at = NOW()
         WHERE employee_id = :eid AND field = :f AND status = 'pending'",
        ['eid' => $employee['id'], 'f' => $field]
    );

    $reqId = generateUUID();
    $db->insert('employee_edit_requests', [
        'id'              => $reqId,
        'employee_id'     => $employee['id'],
        'company_id'      => $employee['company_id'],
        'field'           => $field,
        'requested_value' => $value,
        'requested_label' => $label,
        'status'          => 'pending',
    ]);

    if (class_exists('AuditLog')) {
        try {
            AuditLog::log('employee_change_requested', 'employee_edit_request', $reqId, null,
                ['field' => $field, 'value' => $value, 'employee_id' => $employee['id']],
                $employee['company_id']);
        } catch (Throwable $_) { /* best effort */ }
    }

    echo json_encode([
        'ok' => true,
        'request' => [
            'id'     => $reqId,
            'field'  => $field,
            'label'  => $label,
            'status' => 'pending',
        ],
    ]);
} catch (Throwable $e) {
    error_log('[employee-edit-request] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'insert_failed']);
}
