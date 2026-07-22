<?php
/**
 * GET|POST /api/scan/card-render.php - the logged-in employee's card rendered by
 * the WEB engine (render-preset.py / CardPresets), so the Cardify Scan app can
 * DISPLAY the exact render the public cardify.om page would use instead of its
 * own client-side preview. One render engine => the app card and the public card
 * are identical by construction (no two-renderer drift).
 *
 *   GET            -> {success, preset, front_url, back_url, aspect, presets:[id...]}
 *   POST {preset}  -> set the employee's active server preset, re-render, return it.
 *
 * Bearer-authenticated (ScanAuth), rate limited. Renders are cached per
 * employee, keyed by preset + brand + data, so repeat GETs do no work.
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';
require_once INCLUDES_DIR . '/CardPresets.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET' && $method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'GET or POST only']);
    exit;
}

$ctx = ScanAuth::requireEmployee();
require_once __DIR__ . '/_ratelimit.php';
scanRateLimit($ctx, 'card_render', 600);

try {
    $db = Database::getInstance();
    $employee = $db->fetchOne(
        'SELECT * FROM employees WHERE id = :id AND company_id = :cid',
        ['id' => $ctx['employee_id'], 'cid' => $ctx['company_id']]
    );
    if (!is_array($employee)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'not_found']);
        exit;
    }
    $company = $db->fetchOne('SELECT * FROM companies WHERE id = :cid', ['cid' => $ctx['company_id']]);
    if (!is_array($company)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'company_not_found']);
        exit;
    }
    $themeRow = $db->fetchOne('SELECT * FROM company_themes WHERE company_id = :cid LIMIT 1', ['cid' => $company['id']]);
    $theme = is_array($themeRow) ? $themeRow : null;

    // POST: the app picked a server preset for this employee.
    if ($method === 'POST') {
        $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $preset = (string) ($body['preset'] ?? '');
        if (!CardPresets::exists($preset)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'invalid_preset']);
            exit;
        }
        $db->update('employees', ['card_template_id' => $preset], ['id' => $employee['id']]);
        $employee['card_template_id'] = $preset;
        // Bust the public-render cache so the web card follows the same choice.
        require_once INCLUDES_DIR . '/CardRenderer.php';
        try { CardRenderer::invalidateForEmployee((string) $employee['id']); } catch (Throwable $e) {}
    }

    // Which preset to render: the employee's chosen one, else a sensible default.
    $presetId = (string) ($employee['card_template_id'] ?? '');
    if (!CardPresets::exists($presetId)) {
        $ids = array_keys(CardPresets::all());
        $presetId = (string) ($ids[0] ?? '0');
    }

    // Render (cached) front + back with this employee's data + company brand.
    $brand = CardPresets::employeeBrand($company, $theme, $employee);
    $dir = UPLOADS_DIR . '/companies/' . $company['id'] . '/app-cards';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $ver = substr(md5(json_encode(
        [$presetId, $brand, ($theme['updated_at'] ?? ''), ($theme['logo_path'] ?? '')],
        JSON_UNESCAPED_UNICODE
    )), 0, 10);
    $renderSide = function (string $side) use ($brand, $presetId, $dir, $ver, $employee) {
        $file = $dir . '/' . $employee['id'] . '_' . $ver . '_' . $side . '.png';
        if (!is_file($file) || filesize($file) < 256) {
            CardPresets::render($brand, $presetId, $side, $file);
        }
        return (is_file($file) && filesize($file) >= 256)
            ? '/uploads/companies/' . $employee['company_id'] . '/app-cards/' . basename($file)
            : null;
    };
    $frontPath = $renderSide('front');
    $backPath  = $renderSide('back');

    echo json_encode([
        'success'   => true,
        'preset'    => $presetId,
        'front_url' => $frontPath ? 'https://' . cardifyApexHost() . $frontPath : null,
        'back_url'  => $backPath ? 'https://' . cardifyApexHost() . $backPath : null,
        'aspect'    => 1.545,
        'presets'   => array_map('strval', array_keys(CardPresets::all())),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('card-render: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
}
