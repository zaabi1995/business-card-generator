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
// card_page_layout: whether this person's page leads with their PHOTO or with
// the printed card. It was settable by an admin in admin/employees.php and by
// nobody else, so an employee could upload a photo but not decide what their
// own page shows. Validated against the same three values the column accepts.
$allowed = ['name_en','name_ar','position_en','position_ar','phone','mobile','email','website','preferred_contact_action','card_page_layout'];
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
    if ($k === 'card_page_layout' && !in_array($v, ['auto','card','photo'], true)) continue;
    if (strlen($v) > 255) $v = substr($v, 0, 255);
    $update[$k] = $v;
}

// Moderation (TypeSafe Jev): a changed name or title goes straight onto a
// public card and into print, so an insult, a joke or a spam link is held
// instead of saved. Real but unusual titles pass ("Chief Happiness Officer"
// scored 0.34 in testing, held values 0.93-0.99). Only CHANGED name/title
// fields of 3+ characters are asked, so autosave keystrokes stay cheap; Jev
// down = saved as before.
$held = [];
$toCheck = [];
foreach (['name_en', 'name_ar', 'position_en', 'position_ar'] as $k) {
    if (!isset($update[$k]) || mb_strlen($update[$k]) < 3) continue;
    if ((string) ($employee[$k] ?? '') === $update[$k]) continue;
    $toCheck[$k] = $update[$k];
}
if ($toCheck) {
    require_once INCLUDES_DIR . '/JevClient.php';
    $qs = [];
    foreach ($toCheck as $k => $v) {
        $qs[$k] = ['type' => 'noul', 'instructions' => "`$k` is offensive, obscene or insulting, or is plainly not a real name or job title for a business card: a joke, an insult, a slogan, a web link or random characters. Unusual but real job titles are fine."];
    }
    $verdict = JevClient::ask($toCheck, $qs, 'jev:self-edit');
    if (is_array($verdict)) {
        foreach ($toCheck as $k => $v) {
            if ((float) ($verdict[$k]['noul'] ?? 0) >= 0.9) {
                $held[] = $k;
                unset($update[$k]);
            }
        }
    }
    if ($held && class_exists('AuditLog')) {
        try {
            AuditLog::log('employee_edit_held', 'employee', $employee['id'], null,
                ['fields' => $held, 'values' => array_intersect_key($toCheck, array_flip($held))],
                $employee['company_id']);
        } catch (Throwable $_) { /* best effort */ }
    }
}

if ($held && empty($update) && !isset($body['socials']) && !isset($body['custom_fields'])) {
    echo json_encode(['ok' => false, 'error' => 'value_not_allowed', 'held' => $held]);
    exit;
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

    echo json_encode($held
        ? ['ok' => false, 'error' => 'value_not_allowed', 'held' => $held, 'updated' => array_keys($update)]
        : ['ok' => true, 'updated' => array_keys($update)]);

    // Re-bake the printed card when a field that is ON the card changed. The
    // page has no canvas, so nothing else refreshes generated_cards, and the
    // public card kept showing the old title next to the new text. Runs after
    // the response is flushed so autosave stays instant; never nulls or
    // deletes the old image, the swap happens only once a new PNG decodes.
    $cardFields = ['name_en','name_ar','position_en','position_ar','phone','mobile','email','website'];
    if (array_intersect(array_keys($update), $cardFields)) {
        if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
        try {
            require_once INCLUDES_DIR . '/CardPDFRenderer.php';
            require_once INCLUDES_DIR . '/functions.php';
            $pdftoppm = trim((string)@shell_exec('command -v pdftoppm 2>/dev/null'));
            $r = $pdftoppm !== '' ? CardPDFRenderer::render((string)$employee['id'], 'web') : ['success' => false];
            if (!empty($r['success']) && !empty($r['path']) && is_file($r['path'])) {
                $cid = (string)$employee['company_id'];
                $cardsDir = rtrim(UPLOADS_DIR, '/') . '/companies/' . $cid . '/cards';
                if (!is_dir($cardsDir)) @mkdir($cardsDir, 0755, true);
                $uniq = time() . '_' . bin2hex(random_bytes(3));
                $fPre = $cardsDir . '/card_front_' . $uniq;
                $bPre = $cardsDir . '/card_back_'  . $uniq;
                @exec(escapeshellarg($pdftoppm) . ' -r 300 -png -f 1 -l 1 -singlefile ' . escapeshellarg($r['path']) . ' ' . escapeshellarg($fPre) . ' 2>/dev/null');
                @exec(escapeshellarg($pdftoppm) . ' -r 300 -png -f 2 -l 2 -singlefile ' . escapeshellarg($r['path']) . ' ' . escapeshellarg($bPre) . ' 2>/dev/null');
                $frontOk = is_file($fPre . '.png') && @getimagesize($fPre . '.png') !== false;
                $backOk  = is_file($bPre . '.png') && @getimagesize($bPre . '.png') !== false;
                if ($frontOk) {
                    foreach ([$fPre . '.png', $bPre . '.png'] as $pf) { if (is_file($pf)) @chmod($pf, 0644); }
                    logGeneratedCard((string)$employee['id'], null, null,
                        'card_front_' . $uniq . '.png', $backOk ? ('card_back_' . $uniq . '.png') : null, null, $cid);
                } else {
                    @unlink($fPre . '.png'); @unlink($bPre . '.png');
                }
            }
        } catch (Throwable $e) {
            error_log('[employee-edit-save] card re-bake failed: ' . $e->getMessage());
        }
    }
} catch (Throwable $e) {
    error_log('[employee-edit-save] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'save_failed']);
}
