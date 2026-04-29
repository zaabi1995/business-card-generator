<?php
/**
 * GET /api/logos/show?slug=omantel, single company logo metadata.
 */
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('X-Attribution: cardify.om/logos');

$db   = Database::getInstance();
$slug = $_GET['slug'] ?? '';
if (!$slug) {
    http_response_code(400);
    echo json_encode(['error' => 'missing_slug']);
    exit;
}

$r = $db->fetchOne("SELECT * FROM om_companies WHERE slug = :s", [':s' => $slug]);
if (!$r || !in_array($r['logo_status'], ['indexed', 'verified'], true)) {
    http_response_code(404);
    echo json_encode(['error' => 'not_found']);
    exit;
}

$base = 'https://cardify.om';
echo json_encode([
    'slug'        => $r['slug'],
    'name_en'     => $r['name_en'],
    'name_ar'     => $r['name_ar'],
    'sector'      => $r['sector'],
    'wilayat'     => $r['wilayat'],
    'status'      => $r['logo_status'],
    'verified_at' => $r['logo_verified_at'],
    'urls' => [
        'svg'      => $r['logo_svg_path']      ? $base . $r['logo_svg_path']      : null,
        'png_1024' => $r['logo_png_path']      ? $base . $r['logo_png_path']      : null,
        'png_512'  => $r['logo_png_512_path']  ? $base . $r['logo_png_512_path']  : null,
        'png_2048' => $r['logo_png_2048_path'] ? $base . $r['logo_png_2048_path'] : null,
        'webp'     => $r['logo_webp_path']     ? $base . $r['logo_webp_path']     : null,
    ],
    'profile_url' => "$base/companies/{$r['slug']}",
    'attribution' => 'https://cardify.om/logos',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
