<?php
/**
 * Re-bake a template's background PNG + SVG with a modified set of
 * baked vs live fields. Tier 3 of the design-editor plan.
 *
 * POST: csrf_token, template_id, field_changes (JSON)
 *   field_changes = { "name_ar": {"is_static": true, "render_in_bg": true},
 *                     "static_3": {"is_static": true, "render_in_bg": false}, ... }
 *
 * For every changed field we update fields_json, then build the redaction
 * list (every dynamic field + every static-but-not-baked field) and call
 * scripts/rebake_template_bg.py to regenerate bg-page-N.png + .svg from
 * the original source PDF. On success, bumps current_version and
 * invalidates company-wide PNG + vector PDF cache. On failure, rolls
 * back fields_json to the pre-change snapshot.
 *
 * Per-template advisory lock (GET_LOCK) prevents concurrent re-bakes.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Database.php';
require_once INCLUDES_DIR . '/CardRenderer.php';

header('Content-Type: application/json');

requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}
if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF check failed']);
    exit;
}

$templateId = trim($_POST['template_id'] ?? '');
$changesRaw = $_POST['field_changes'] ?? '';
if ($templateId === '' || $changesRaw === '') {
    echo json_encode(['success' => false, 'error' => 'template_id + field_changes required']);
    exit;
}
$changes = json_decode($changesRaw, true);
if (!is_array($changes) || empty($changes)) {
    echo json_encode(['success' => false, 'error' => 'field_changes must be a non-empty JSON object']);
    exit;
}

$companyId = getCurrentCompanyId();
if (!$companyId) {
    echo json_encode(['success' => false, 'error' => 'No company context']);
    exit;
}

$db = Database::getInstance();

// Per-template advisory lock so two admins clicking Apply at once don't
// stomp each other's bg PNG. 30s wait, returns 0 if someone else holds.
$lockKey = 'rebake_template_' . $templateId;
$got = $db->fetchOne("SELECT GET_LOCK(:k, 30) AS got", ['k' => $lockKey]);
if (empty($got['got'])) {
    echo json_encode(['success' => false, 'error' => 'Another re-bake is in progress, try again in a moment']);
    exit;
}

try {
    $row = $db->fetchOne(
        "SELECT id, fields_json, original_pdf_path, original_pdf_page,
                background_image_path, current_version
         FROM templates WHERE id = :id AND company_id = :cid",
        ['id' => $templateId, 'cid' => $companyId]
    );
    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'Template not found']);
        exit;
    }
    $pdfRel = $row['original_pdf_path'] ?? '';
    if (!$pdfRel) {
        echo json_encode(['success' => false, 'error' => 'Template has no original PDF, cannot re-bake']);
        exit;
    }
    $pdfAbs = realpath(__DIR__ . '/..' . (strpos($pdfRel, '/') === 0 ? '' : '/') . $pdfRel);
    if (!$pdfAbs || !is_file($pdfAbs)) {
        echo json_encode(['success' => false, 'error' => 'Source PDF not found on disk']);
        exit;
    }

    $bgRel = $row['background_image_path'] ?? '';
    if (!$bgRel) {
        echo json_encode(['success' => false, 'error' => 'Template has no background PNG path']);
        exit;
    }
    $bgAbs = __DIR__ . '/..' . (strpos($bgRel, '/') === 0 ? '' : '/') . $bgRel;
    $bgAbs = str_replace('\\', '/', $bgAbs);

    // SVG sibling: same base name, .svg extension.
    $svgAbs = preg_replace('/\.png$/i', '.svg', $bgAbs);

    // Backup snapshot of fields_json so we can roll back on Python failure.
    $fieldsBefore = $row['fields_json'];
    $fields = json_decode($fieldsBefore ?: '{}', true) ?: [];

    // Apply each requested change (validate keys + value types).
    $changedKeys = [];
    foreach ($changes as $key => $patch) {
        if (!is_string($key) || !preg_match('/^[a-zA-Z0-9_]+$/', $key)) continue;
        if (!isset($fields[$key]) || !is_array($fields[$key])) continue;
        if (!is_array($patch)) continue;
        if (array_key_exists('is_static', $patch))   $fields[$key]['is_static']   = (bool)$patch['is_static'];
        if (array_key_exists('render_in_bg', $patch)) $fields[$key]['render_in_bg'] = (bool)$patch['render_in_bg'];
        // Constraint: render_in_bg=true only valid when is_static=true.
        if (!empty($fields[$key]['render_in_bg']) && empty($fields[$key]['is_static'])) {
            $fields[$key]['is_static'] = true;
        }
        $changedKeys[] = $key;
    }
    if (empty($changedKeys)) {
        echo json_encode(['success' => false, 'error' => 'No valid field changes']);
        exit;
    }

    // Build the redaction list: every field that should NOT be baked into
    // the bg, in PDF points. Editor scale = 300 dpi / 72 = 4.1667 px/pt.
    $EDITOR_SCALE = 300.0 / 72.0;
    $redact = [];
    foreach ($fields as $k => $f) {
        if (!is_array($f) || $k === 'qr_code') continue;
        if (empty($f['enabled']) && !isset($f['enabled'])) {
            // 'enabled' may be missing -> default true; only skip when
            // explicitly false.
        } elseif (isset($f['enabled']) && $f['enabled'] === false) {
            continue;
        }
        $isBaked = !empty($f['is_static']) && !empty($f['render_in_bg']);
        if ($isBaked) continue; // keep baked
        // Need a bbox in pt to redact. Fall back to fontSize*1.4 height
        // when width/height aren't stored.
        $x = (float)($f['x'] ?? 0);
        $y = (float)($f['y'] ?? 0);
        $w = (float)($f['width']  ?? 0);
        $h = (float)($f['height'] ?? 0);
        if ($w <= 0) $w = 200; // wide guess in editor px
        if ($h <= 0) $h = max(16, (float)($f['fontSize'] ?? 16)) * 1.4;
        $redact[] = [
            'x_pt' => $x / $EDITOR_SCALE,
            'y_pt' => $y / $EDITOR_SCALE,
            'w_pt' => $w / $EDITOR_SCALE,
            'h_pt' => $h / $EDITOR_SCALE,
            '_field' => $k,
        ];
    }
    // Strip the debug _field key for the actual call (Python ignores extras
    // but cleaner json on the wire).
    $redactPayload = array_map(function ($r) {
        unset($r['_field']);
        return $r;
    }, $redact);

    $page = (int)($row['original_pdf_page'] ?? 1);
    $cli = escapeshellarg(__DIR__ . '/../scripts/rebake_template_bg.py');
    $cmd = sprintf(
        'timeout 60 /usr/bin/env python3 %s --pdf %s --page %d --out-png %s --out-svg %s --redact-json %s 2>&1',
        $cli,
        escapeshellarg($pdfAbs),
        $page,
        escapeshellarg($bgAbs),
        escapeshellarg($svgAbs),
        escapeshellarg(json_encode($redactPayload, JSON_UNESCAPED_UNICODE))
    );
    $output = shell_exec($cmd);
    $jsonStart = $output ? strpos($output, '{') : false;
    if ($jsonStart === false) {
        echo json_encode(['success' => false, 'error' => 'Re-bake script returned no output', 'output' => $output]);
        exit;
    }
    $res = json_decode(substr($output, $jsonStart), true);
    if (!is_array($res) || empty($res['ok'])) {
        echo json_encode([
            'success' => false,
            'error' => $res['error'] ?? 'Re-bake script failed',
            'output' => $output,
        ]);
        exit;
    }

    // Bg files are now overwritten. Set perms so PHP-FPM (www) can read.
    @chown($bgAbs, 'www');
    @chgrp($bgAbs, 'www');
    @chmod($bgAbs, 0644);
    if (is_file($svgAbs)) {
        @chown($svgAbs, 'www');
        @chgrp($svgAbs, 'www');
        @chmod($svgAbs, 0644);
    }

    // Persist updated fields_json + bump version.
    $newVersion = (int)$row['current_version'] + 1;
    $db->update(
        'templates',
        [
            'fields_json' => json_encode($fields, JSON_UNESCAPED_UNICODE),
            'current_version' => $newVersion,
        ],
        'id = :id',
        ['id' => $row['id']]
    );

    // Audit log: who flipped which fields. Best-effort (skip if table missing).
    try {
        $userId = $_SESSION['user_id'] ?? null;
        $userEmail = $_SESSION['user_email'] ?? null;
        $userRole = $_SESSION['user_role'] ?? 'admin';
        $db->execute(
            "INSERT INTO audit_logs (id, actor_id, actor_email, actor_role, company_id, action, entity_type, entity_id, before_data, after_data, ip_address, user_agent, created_at)
             VALUES (UUID(), :aid, :aem, :arl, :cid, 'rebake_template_bg', 'template', :tid, :before, :after, :ip, :ua, NOW())",
            [
                'aid'    => $userId,
                'aem'    => $userEmail,
                'arl'    => $userRole,
                'cid'    => $companyId,
                'tid'    => $templateId,
                'before' => $fieldsBefore,
                'after'  => json_encode([
                    'changed_keys' => $changedKeys,
                    'changes' => $changes,
                    'rects_redacted' => $res['rects_redacted'] ?? null,
                    'backup_png' => $res['backup_png'] ?? '',
                    'new_version' => $newVersion,
                ], JSON_UNESCAPED_UNICODE),
                'ip'     => $_SERVER['REMOTE_ADDR'] ?? null,
                'ua'     => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 1000),
            ]
        );
    } catch (Exception $e) { /* non-fatal */ }

    // Cache nuke: delete every cached card PNG + vector PDF for the company
    // so the new bg shows up on the next render.
    try {
        CardRenderer::invalidateForCompany($companyId, 'rebake_template_bg:' . $templateId);
    } catch (Exception $e) { /* non-fatal */ }

    echo json_encode([
        'success' => true,
        'fields' => $fields,
        'changed_keys' => $changedKeys,
        'current_version' => $newVersion,
        'bg_url' => $bgRel . '?v=' . $newVersion,
        'rects_redacted' => $res['rects_redacted'] ?? 0,
    ]);
} finally {
    $db->fetchOne("SELECT RELEASE_LOCK(:k)", ['k' => $lockKey]);
}
