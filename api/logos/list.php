<?php
/**
 * GET /api/logos/list, public paginated list of indexed+verified logos.
 *
 * Query params: page, per_page (max 100), sector, wilayat, verified (1)
 * Rate limit: 60 req/min/IP via rate_limits table.
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/UrlSafety.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('X-Attribution: cardify.om/logos');

$db  = Database::getInstance();
$pdo = $db->getConnection();

// Rate limit, use real client IP (handles Cloudflare / reverse proxies).
$ip     = getClientIp();
$bucket = (int) floor(time() / 60);
$pdo->prepare(
    "INSERT INTO rate_limits (action, ip, bucket, count, window_sec)
     VALUES ('api_logos', :ip, :b, 1, 60)
     ON DUPLICATE KEY UPDATE count = count + 1"
)->execute([':ip' => $ip, ':b' => $bucket]);
$count = (int) ($db->fetchOne(
    "SELECT count FROM rate_limits WHERE action = 'api_logos' AND ip = :ip AND bucket = :b",
    [':ip' => $ip, ':b' => $bucket]
)['count'] ?? 0);
if ($count > 60) {
    http_response_code(429);
    header('Retry-After: 60');
    echo json_encode(['error' => 'rate_limit']);
    exit;
}

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(10, (int) ($_GET['per_page'] ?? 50)));

$where  = ["logo_status IN ('indexed','verified')"];
$params = [];
if (!empty($_GET['sector']))   { $where[] = 'sector = :s';  $params[':s']  = $_GET['sector']; }
if (!empty($_GET['wilayat']))  { $where[] = 'wilayat = :w'; $params[':w'] = $_GET['wilayat']; }
if (!empty($_GET['verified'])) { $where[] = "logo_status = 'verified'"; }

$whereSql = implode(' AND ', $where);
$offset   = ($page - 1) * $perPage;

$rows = $db->fetchAll(
    "SELECT id, slug, name_en, name_ar, sector, wilayat, logo_status,
            logo_svg_path, logo_png_path, logo_png_512_path, logo_png_2048_path, logo_webp_path,
            logo_dominant_color, logo_verified_at
       FROM om_companies
      WHERE $whereSql
      ORDER BY name_en ASC
      LIMIT $perPage OFFSET $offset",
    $params
);
$total = (int) ($db->fetchOne(
    "SELECT COUNT(*) c FROM om_companies WHERE $whereSql",
    $params
)['c'] ?? 0);

$base = 'https://cardify.om';
$shaped = array_map(function ($r) use ($base) {
    return [
        'slug'            => $r['slug'],
        'name_en'         => $r['name_en'],
        'name_ar'         => $r['name_ar'],
        'sector'          => $r['sector'],
        'wilayat'         => $r['wilayat'],
        'status'          => $r['logo_status'],
        'verified_at'     => $r['logo_verified_at'],
        'dominant_color'  => $r['logo_dominant_color'],
        'urls' => [
            'svg'      => $r['logo_svg_path']      ? $base . $r['logo_svg_path']      : null,
            'png_512'  => $r['logo_png_512_path']  ? $base . $r['logo_png_512_path']  : null,
            'png_1024' => $r['logo_png_path']      ? $base . $r['logo_png_path']      : null,
            'png_2048' => $r['logo_png_2048_path'] ? $base . $r['logo_png_2048_path'] : null,
            'webp'     => $r['logo_webp_path']     ? $base . $r['logo_webp_path']     : null,
        ],
        'profile_url'     => "$base/companies/{$r['slug']}",
    ];
}, $rows);

echo json_encode([
    'total'       => $total,
    'page'        => $page,
    'per_page'    => $perPage,
    'results'     => $shaped,
    'attribution' => 'https://cardify.om/logos',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
