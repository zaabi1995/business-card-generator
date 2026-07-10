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
$allowed = ['name_en','name_ar','position_en','position_ar','phone','mobile','email','website','preferred_contact_action'];
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
    if ($k === 'preferred_contact_action' && !in_array($v, ['save_contact','whatsapp','call'], true)) continue;
    if (strlen($v) > 255) $v = substr($v, 0, 255);
    $update[$k] = $v;
}

if (empty($update) && !isset($body['socials']) && !isset($body['custom_fields'])) {
    echo json_encode(['ok' => true, 'noop' => true]);
    exit;
}

try {
    $db = Database::getInstance();
    if (!empty($update)) {
        $db->update('employees', $update, 'id = :id', ['id' => $employee['id']]);
    }

    // Socials: client sends a full ordered list; we replace. Whitelist
    // platform keys + validate URLs (allow empty-after-trim = drop).
    if (isset($body['socials']) && is_array($body['socials'])) {
        $allowedPlatforms = ['linkedin','instagram','twitter','tiktok','youtube','facebook','snapchat','whatsapp','telegram','github','other'];
        $clean = [];
        foreach ($body['socials'] as $s) {
            if (!is_array($s)) continue;
            $p = strtolower(trim((string) ($s['platform'] ?? '')));
            $u = trim((string) ($s['url'] ?? ''));
            if ($p === '' || !in_array($p, $allowedPlatforms, true)) continue;
            if ($u === '') continue;
            if (!filter_var($u, FILTER_VALIDATE_URL)) continue;
            if (strlen($u) > 512) $u = substr($u, 0, 512);
            $clean[] = ['platform' => $p, 'url' => $u];
            if (count($clean) >= 20) break;
        }
        try {
            $db->query("DELETE FROM employee_socials WHERE employee_id = :eid", ['eid' => $employee['id']]);
            $pos = 0;
            foreach ($clean as $s) {
                $db->insert('employee_socials', [
                    'employee_id' => $employee['id'],
                    'company_id'  => $employee['company_id'],
                    'platform'    => $s['platform'],
                    'url'         => $s['url'],
                    'position'    => $pos++,
                ]);
            }
        } catch (Throwable $e) {
            error_log('[employee-edit-save] socials write failed: ' . $e->getMessage());
        }
    }

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

    // Custom fields: whitelist keys against the company's custom_fields
    // definitions, drop the rest. Values are trimmed strings, capped 255.
    if (isset($body['custom_fields']) && is_array($body['custom_fields'])) {
        try {
            $row = $db->fetchOne("SELECT custom_fields FROM companies WHERE id = :id", ['id' => $employee['company_id']]);
            $defs = $row && !empty($row['custom_fields']) ? json_decode($row['custom_fields'], true) : [];
            $allowedKeys = [];
            if (is_array($defs)) {
                foreach ($defs as $d) {
                    if (!empty($d['key'])) $allowedKeys[] = (string) $d['key'];
                }
            }
            $kept = [];
            foreach ($body['custom_fields'] as $k => $v) {
                if (!is_string($k) || !in_array($k, $allowedKeys, true)) continue;
                if (!is_scalar($v) && $v !== null) continue;
                $val = trim((string) ($v ?? ''));
                if ($val === '') continue;
                if (strlen($val) > 255) $val = substr($val, 0, 255);
                $kept[$k] = $val;
                if (count($kept) >= 20) break;
            }
            $db->update('employees', ['custom_fields' => json_encode($kept, JSON_UNESCAPED_UNICODE)], 'id = :id', ['id' => $employee['id']]);
        } catch (Throwable $e) {
            error_log('[employee-edit-save] custom_fields write failed: ' . $e->getMessage());
        }
    }

    // Notify admin by email when company_settings.notify_on_employee_edit is
    // on (default 1). Silent-fail so slow mail transport never blocks save.
    $changedFields = array_keys($update);
    if (isset($body['socials'])) $changedFields[] = 'socials';
    if ($changedFields) {
        try {
            $cs = $db->fetchOne(
                "SELECT notify_on_employee_edit FROM company_settings WHERE company_id = :cid",
                ['cid' => $employee['company_id']]
            );
            $optedIn = $cs === null ? true : ((int) ($cs['notify_on_employee_edit'] ?? 1) === 1);
            // Coalesce notifications: one email per employee per 5 minutes so
            // an autosave keystroke burst doesn't turn into 20 emails.
            $gateKey = 'emp_edit_notify:' . $employee['id'];
            $canSend = RateLimiter::check($gateKey, $ip, 1, 300);
            if ($optedIn && $canSend) {
                $company = $db->fetchOne(
                    "SELECT id, name, slug, admin_email FROM companies WHERE id = :id",
                    ['id' => $employee['company_id']]
                );
                $adminEmail = $company['admin_email'] ?? '';
                if ($adminEmail) {
                    require_once INCLUDES_DIR . '/Notifier.php';
                    $host = defined('APP_HOST') ? APP_HOST : 'cardify.om';
                    $empUrl = 'https://' . $host . '/' . ($company['slug'] ?? '') . '/admin/employees';
                    Notifier::send('employee_self_edit',
                        ['name' => $company['name'] ?? 'Admin', 'email' => $adminEmail, 'company_id' => $employee['company_id']],
                        [
                            'adminName'     => $company['name'] ?? 'Admin',
                            'employeeName'  => $employee['name_en'] ?? 'An employee',
                            'companyName'   => $company['name'] ?? ($company['name_en'] ?? 'your company'),
                            'changedFields' => $changedFields,
                            'employeeUrl'   => $empUrl,
                        ],
                        ['email']
                    );
                }
            }
        } catch (Throwable $e) {
            error_log('[employee-edit-save] admin notify failed: ' . $e->getMessage());
        }
    }

    echo json_encode(['ok' => true, 'updated' => array_keys($update)]);
} catch (Throwable $e) {
    error_log('[employee-edit-save] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'save_failed']);
}
