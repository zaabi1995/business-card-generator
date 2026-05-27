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
$q       = trim((string) ($_GET['q'] ?? ''));
$sort    = (string) ($_GET['sort'] ?? 'alpha');

$where  = ["logo_status IN ('indexed','verified')"];
$params = [];
if (!empty($_GET['sector']))   { $where[] = 'sector = :s';  $params[':s']  = $_GET['sector']; }
if (!empty($_GET['wilayat']))  { $where[] = 'wilayat = :w'; $params[':w'] = $_GET['wilayat']; }
if (!empty($_GET['verified'])) { $where[] = "logo_status = 'verified'"; }
if ($q !== '') {
    // PDO native prepares ban reused placeholders, split into _en/_ar/_slug
    $where[] = '(name_en LIKE :q_en OR name_ar LIKE :q_ar OR slug LIKE :q_slug)';
    $params[':q_en']   = '%' . $q . '%';
    $params[':q_ar']   = '%' . $q . '%';
    $params[':q_slug'] = '%' . $q . '%';
}

$whereSql = implode(' AND ', $where);
$offset   = ($page - 1) * $perPage;
$orderSql = match ($sort) {
    'newest'   => 'logo_updated_at DESC, name_en ASC',
    'verified' => "FIELD(logo_status,'verified','indexed'), logo_verified_at DESC, name_en ASC",
    default    => 'name_en ASC',
};

$rows = $db->fetchAll(
    "SELECT id, slug, name_en, name_ar, sector, wilayat, logo_status,
            logo_svg_path, logo_png_path, logo_png_512_path, logo_png_2048_path, logo_webp_path,
            logo_svg_dark_path, logo_png_dark_path, logo_webp_dark_path,
            logo_svg_white_path, logo_png_white_path, logo_webp_white_path,
            logo_dominant_color, logo_palette, logo_updated_at, logo_verified_at
       FROM om_companies
      WHERE $whereSql
      ORDER BY $orderSql
      LIMIT $perPage OFFSET $offset",
    $params
);
$total = (int) ($db->fetchOne(
    "SELECT COUNT(*) c FROM om_companies WHERE $whereSql",
    $params
)['c'] ?? 0);

require_once INCLUDES_DIR . '/LogoLibrary.php';

$base = 'https://cardify.om';
$shaped = array_map(function ($r) use ($base) {
    $palette = json_decode((string) ($r['logo_palette'] ?? ''), true) ?: [];
    $ver     = !empty($r['logo_updated_at']) ? '?v=' . strtotime($r['logo_updated_at']) : '';
    $abs = fn(?string $p) => $p ? $base . $p . $ver : null;
    $useDark = LogoLibrary::shouldUseDarkVariantOnLight($palette)
               && !empty($r['logo_webp_dark_path'] ?? $r['logo_png_dark_path'] ?? $r['logo_svg_dark_path']);
    $displayUrl = $useDark
        ? ($abs($r['logo_webp_dark_path']) ?: $abs($r['logo_png_dark_path']) ?: $abs($r['logo_svg_dark_path']))
        : ($abs($r['logo_webp_path']) ?: $abs($r['logo_png_512_path']) ?: $abs($r['logo_png_path']) ?: $abs($r['logo_svg_path']));
    return [
        'slug'            => $r['slug'],
        'name_en'         => $r['name_en'],
        'name_ar'         => $r['name_ar'],
        'sector'          => $r['sector'],
        'wilayat'         => $r['wilayat'],
        'status'          => $r['logo_status'],
        'verified_at'     => $r['logo_verified_at'],
        'updated_at'      => $r['logo_updated_at'],
        'dominant_color'  => $r['logo_dominant_color'],
        'palette'         => $palette,
        'display_url'     => $displayUrl, // auto-flipped for light-leaning logos
        'urls' => [
            'svg'        => $abs($r['logo_svg_path']),
            'png_512'    => $abs($r['logo_png_512_path']),
            'png_1024'   => $abs($r['logo_png_path']),
            'png_2048'   => $abs($r['logo_png_2048_path']),
            'webp'       => $abs($r['logo_webp_path']),
            'svg_dark'   => $abs($r['logo_svg_dark_path']),
            'png_dark'   => $abs($r['logo_png_dark_path']),
            'webp_dark'  => $abs($r['logo_webp_dark_path']),
            'svg_white'  => $abs($r['logo_svg_white_path']),
            'png_white'  => $abs($r['logo_png_white_path']),
            'webp_white' => $abs($r['logo_webp_white_path']),
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
