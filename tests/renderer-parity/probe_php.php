<?php
/**
 * Renderer-parity PHP probe.
 *
 * Runs the REAL production PHP coordinate code against a fixture and prints
 * JSON on stdout. No database, no HTTP: the two functions under test are pure.
 *
 *   mode=pagespec  -> includes/CardPDFRenderer.php::pageSpec() (private, via
 *                     Reflection). This is the exact adapter R2 uses to turn a
 *                     templates row into the page spec render-card-pdf.py eats.
 *   mode=fabric    -> includes/functions.php::convertLegacyFieldPositions()
 *                     called with the literal 1050,600 every caller passes
 *                     (generate_card_html.php:44,47 etc), plus the canvas the
 *                     Fabric editor is actually sized to, computed by the REAL
 *                     getTemplatePixelDims() body lifted verbatim out of
 *                     generate_card_html.php:302-319 and ported 1:1 to PHP.
 *                     Transform level only: no browser is involved.
 *
 * Usage: php probe_php.php <mode> <fixture.json>
 */

$mode    = $argv[1] ?? '';
$fixture = json_decode(file_get_contents($argv[2]), true);
if (!is_array($fixture)) { fwrite(STDERR, "bad fixture\n"); exit(1); }

$root = dirname(__DIR__, 2);

if ($mode === 'pagespec') {
    require_once $root . '/includes/CardPDFRenderer.php';
    // Fake a `templates` row exactly as CardPDFRenderer::render() fetches it.
    $row = [
        'id'                    => 'parity-fixture',
        'side'                  => $fixture['side'] ?? 'back',
        'settings_json'         => json_encode($fixture['settings'], JSON_UNESCAPED_UNICODE),
        'fields_json'           => json_encode($fixture['fields'],   JSON_UNESCAPED_UNICODE),
        'background_image_path' => '',
    ];
    $m = new ReflectionMethod('CardPDFRenderer', 'pageSpec');
    if (PHP_VERSION_ID < 80100) { $m->setAccessible(true); }
    // pageSpec already returns the qr_code slot (CardPDFRenderer.php:589);
    // nothing here may overwrite it or the probe stops measuring the real code.
    $spec = $m->invoke(null, $row, $fixture['side'] ?? 'back');
    echo json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
    exit(0);
}

if ($mode === 'fabric') {
    // Minimal constant bootstrap; functions.php is normally reached through
    // config.php, which needs a DB. None of that is touched by the function
    // under test.
    if (!defined('ROOT_DIR'))     define('ROOT_DIR', $root);
    if (!defined('INCLUDES_DIR')) define('INCLUDES_DIR', $root . '/includes');
    if (!defined('UPLOADS_DIR'))  define('UPLOADS_DIR', $root . '/uploads');
    if (!defined('STORAGE_DIR'))  define('STORAGE_DIR', $root . '/storage');
    if (!defined('CACHE_DIR'))    define('CACHE_DIR', $root . '/cache');
    if (!defined('LOGS_DIR'))     define('LOGS_DIR', $root . '/logs');
    if (!defined('DATA_DIR'))     define('DATA_DIR', $root . '/data');
    if (!defined('LANG_DIR'))     define('LANG_DIR', $root . '/lang');
    require_once $root . '/includes/functions.php';

    $fmt = $fixture['settings']['fields_format'] ?? null;
    // The literal 1050,600 every caller passes.
    $converted = convertLegacyFieldPositions($fixture['fields'], 1050, 600, $fmt);

    // getTemplatePixelDims(), ported verbatim from generate_card_html.php:302-319.
    $s = $fixture['settings'] ?? [];
    $dims = ['w' => 1050, 'h' => 600];
    $cw = isset($s['customWidth'])  ? (float)$s['customWidth']  : 0.0;
    $ch = isset($s['customHeight']) ? (float)$s['customHeight'] : 0.0;
    $dpi = isset($s['dpi']) ? (float)$s['dpi'] : 300.0;
    if ($dpi == 0.0) $dpi = 300.0;
    if ($cw && $ch) {
        $unit = strtolower($s['customUnit'] ?? 'mm');
        $toIn = $unit === 'mm' ? 1 / 25.4 : ($unit === 'pt' ? 1 / 72 : ($unit === 'in' ? 1 : 1 / 25.4));
        $dims = ['w' => (int)round($cw * $toIn * $dpi), 'h' => (int)round($ch * $toIn * $dpi)];
    }

    echo json_encode([
        'convert_canvas' => ['w' => 1050, 'h' => 600],
        'fabric_canvas'  => $dims,
        'fields'         => $converted,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
    exit(0);
}

fwrite(STDERR, "unknown mode\n");
exit(1);
