<?php
/**
 * /api/scan/designs.php - the per-person "wallet" of card designs (card_designs).
 * These are PERSONAL designs, independent of the shared company brand, so they
 * are always editable by their owner and never gated by the brand lock. Reuses
 * the templates fields_json/settings_json format so the same render pipeline
 * (generate_card_html.php) draws them. Bearer-auth; every row is scoped to the
 * signed-in employee.
 *
 *   GET                       -> {success, designs:[...]}   (this employee's wallet)
 *   POST {action:'save', id?, name?, fields_json?, settings_json?, side?, pair_id?,
 *         source?, background_image_path?} -> {success, id}  (create or update)
 *   POST {action:'activate', id} -> {success}   (make this the rendered design)
 *   POST {action:'delete', id}   -> {success}
 *
 * See docs/superpowers/specs/2026-07-16-card-designs-brand-model-design.md.
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';

header('Content-Type: application/json');
$ctx = ScanAuth::requireEmployee();
require_once __DIR__ . '/_ratelimit.php';
scanRateLimit($ctx, 'designs', 600);

$db = Database::getInstance();
$emp = $ctx['employee_id'];
$company = $ctx['company_id'];

// ---- GET: list this employee's wallet ----
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $rows = $db->fetchAll(
            "SELECT id, name, side, pair_id, source, is_active, background_image_path,
                    fields_json, settings_json, updated_at
               FROM card_designs WHERE employee_id = :e ORDER BY is_active DESC, updated_at DESC",
            ['e' => $emp]
        );
    } catch (\Throwable $ex) {
        error_log('[scan/designs] list: ' . $ex->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'server_error']);
        exit;
    }
    echo json_encode(['success' => true, 'designs' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) $body = [];
$action = (string) ($body['action'] ?? '');

// Validate JSON payloads before they touch the CHECK(json_valid()) columns.
$validJson = function ($v): ?string {
    if ($v === null) return null;
    $s = is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE);
    json_decode($s);
    return json_last_error() === JSON_ERROR_NONE ? $s : null;
};

try {
    if ($action === 'save') {
        $id = trim((string) ($body['id'] ?? ''));
        $fields = array_key_exists('fields_json', $body) ? $validJson($body['fields_json']) : null;
        $settings = array_key_exists('settings_json', $body) ? $validJson($body['settings_json']) : null;
        $name = substr(trim((string) ($body['name'] ?? 'My design')), 0, 120) ?: 'My design';
        $side = ($body['side'] ?? 'front'); if (!in_array($side, ['front', 'back'], true)) $side = 'front';
        $source = ($body['source'] ?? 'app'); if (!in_array($source, ['app', 'web', 'preset', 'upload'], true)) $source = 'app';
        $pairId = trim((string) ($body['pair_id'] ?? '')) ?: null;
        $bg = substr(trim((string) ($body['background_image_path'] ?? '')), 0, 500) ?: null;

        if ($id !== '') {
            // Update, scoped to owner (a foreign id updates nothing).
            $owned = $db->fetchOne("SELECT id FROM card_designs WHERE id = :id AND employee_id = :e", ['id' => $id, 'e' => $emp]);
            if (!$owned) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'not_found']);
                exit;
            }
            $sets = ['name = :name', 'side = :side', 'source = :source', 'pair_id = :pair', 'background_image_path = :bg'];
            $params = [':name' => $name, ':side' => $side, ':source' => $source, ':pair' => $pairId, ':bg' => $bg, ':id' => $id, ':e' => $emp];
            if ($fields !== null) { $sets[] = 'fields_json = :fields'; $params[':fields'] = $fields; }
            if ($settings !== null) { $sets[] = 'settings_json = :settings'; $params[':settings'] = $settings; }
            $db->getConnection()->prepare(
                "UPDATE card_designs SET " . implode(', ', $sets) . " WHERE id = :id AND employee_id = :e"
            )->execute($params);
            echo json_encode(['success' => true, 'id' => $id]);
            exit;
        }

        // Create.
        $newId = generateUUID();
        $db->insert('card_designs', [
            'id' => $newId,
            'employee_id' => $emp,
            'company_id' => $company,
            'name' => $name,
            'side' => $side,
            'pair_id' => $pairId,
            'source' => $source,
            'background_image_path' => $bg,
            'fields_json' => $fields,
            'settings_json' => $settings,
            'is_active' => 0,
        ]);
        echo json_encode(['success' => true, 'id' => $newId]);
        exit;
    }

    if ($action === 'activate') {
        $id = trim((string) ($body['id'] ?? ''));
        $owned = $db->fetchOne("SELECT pair_id FROM card_designs WHERE id = :id AND employee_id = :e", ['id' => $id, 'e' => $emp]);
        if (!$owned) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'not_found']);
            exit;
        }
        // Exactly one active design per employee: clear all, then set this one (+ its pair).
        $db->getConnection()->prepare("UPDATE card_designs SET is_active = 0 WHERE employee_id = :e")->execute([':e' => $emp]);
        if (!empty($owned['pair_id'])) {
            $db->getConnection()->prepare("UPDATE card_designs SET is_active = 1 WHERE employee_id = :e AND pair_id = :p")
               ->execute([':e' => $emp, ':p' => $owned['pair_id']]);
        } else {
            $db->getConnection()->prepare("UPDATE card_designs SET is_active = 1 WHERE id = :id AND employee_id = :e")
               ->execute([':id' => $id, ':e' => $emp]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'delete') {
        $id = trim((string) ($body['id'] ?? ''));
        $db->getConnection()->prepare("DELETE FROM card_designs WHERE id = :id AND employee_id = :e")
           ->execute([':id' => $id, ':e' => $emp]);
        echo json_encode(['success' => true]);
        exit;
    }
} catch (\Throwable $ex) {
    error_log('[scan/designs] ' . $action . ': ' . $ex->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'unknown_action']);
