<?php
/**
 * Cardify — Omani Logo Library.
 *
 * Routes (via nginx rewrite):
 *   /logos                   → view=hub
 *   /logos/terms             → view=terms
 *   /logos/press             → view=press
 *   /logos/{sector}          → view=sector&sector={slug}
 *   /ar/logos[...]           → same with lang=ar
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/LogoLibrary.php';

$db   = Database::getInstance();
$view = $_GET['view'] ?? 'hub';
$lang = ($_GET['lang'] ?? '') === 'ar' ? 'ar' : 'en';
$isAr = $lang === 'ar';

$SECTORS = [
    'oil-gas'               => 'Oil & Gas',
    'construction'          => 'Construction',
    'trading'               => 'Trading',
    'finance'               => 'Finance & Banking',
    'real-estate'           => 'Real Estate',
    'manufacturing'         => 'Manufacturing',
    'logistics-shipping'    => 'Logistics & Shipping',
    'food-beverage'         => 'Food & Beverage',
    'healthcare'            => 'Healthcare',
    'education'             => 'Education',
    'hospitality-tourism'   => 'Hospitality & Tourism',
    'technology'            => 'Technology',
    'telecom'               => 'Telecommunications',
    'automotive'            => 'Automotive',
    'retail'                => 'Retail',
    'agriculture-fisheries' => 'Agriculture & Fisheries',
    'mining'                => 'Mining',
    'utilities'             => 'Utilities',
    'media-advertising'     => 'Media & Advertising',
    'professional-services' => 'Professional Services',
    'government-defense'    => 'Government & Defense',
    'conglomerate'          => 'Conglomerate',
    'other'                 => 'Other',
];

function esc($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

function fetchLogoRows(Database $db, array $filters, int $page = 1, int $perPage = 60): array {
    $where  = ["logo_status IN ('indexed','verified')"];
    $params = [];
    if (!empty($filters['sector']))        { $where[] = "sector = :s";  $params[':s'] = $filters['sector']; }
    if (!empty($filters['wilayat']))       { $where[] = "wilayat = :w"; $params[':w'] = $filters['wilayat']; }
    if (!empty($filters['verified_only'])) { $where[] = "logo_status = 'verified'"; }
    if (!empty($filters['q'])) {
        $where[] = "(name_en LIKE :q OR name_ar LIKE :q)";
        $params[':q'] = '%' . $filters['q'] . '%';
    }
    $whereSql = implode(' AND ', $where);
    $offset   = max(0, ($page - 1) * $perPage);
    $sort     = match ($filters['sort'] ?? 'alpha') {
        'newest'   => 'logo_updated_at DESC',
        'verified' => 'logo_verified_at DESC',
        default    => 'name_en ASC',
    };
    $rows = $db->fetchAll(
        "SELECT id, slug, name_en, name_ar, sector, wilayat, logo_status,
                logo_png_path, logo_png_512_path, logo_svg_path, logo_webp_path,
                logo_dominant_color, logo_width, logo_height
         FROM om_companies WHERE $whereSql ORDER BY $sort LIMIT $perPage OFFSET $offset",
        $params
    );
    $total = (int) ($db->fetchOne(
        "SELECT COUNT(*) c FROM om_companies WHERE $whereSql", $params
    )['c'] ?? 0);
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
}

function totalLogos(Database $db): int {
    return (int) ($db->fetchOne(
        "SELECT COUNT(*) c FROM om_companies WHERE logo_status IN ('indexed','verified')"
    )['c'] ?? 0);
}

function sectorCounts(Database $db): array {
    return $db->fetchAll(
        "SELECT sector, COUNT(*) c FROM om_companies
         WHERE logo_status IN ('indexed','verified')
         GROUP BY sector ORDER BY c DESC"
    );
}

// ---- terms ----
if ($view === 'terms') {
    $title = $isAr ? 'شروط استخدام مكتبة الشعارات' : 'Terms — Omani Logo Library';
    include __DIR__ . '/data/logo_library/terms_view.php';
    return;
}

// ---- press ----
if ($view === 'press') {
    $title = 'Press Kit — Omani Logo Library';
    include __DIR__ . '/data/logo_library/press_view.php';
    return;
}

// ---- sector ----
if ($view === 'sector') {
    $sectorSlug = $_GET['sector'] ?? '';
    if (!isset($SECTORS[$sectorSlug])) {
        http_response_code(404);
        include __DIR__ . '/404.php';
        return;
    }
    $sectorLabel = $SECTORS[$sectorSlug];
    $page        = max(1, (int) ($_GET['page'] ?? 1));
    $filters = [
        'sector'        => $sectorSlug,
        'q'             => trim($_GET['q'] ?? '') ?: null,
        'verified_only' => !empty($_GET['verified']),
        'sort'          => $_GET['sort'] ?? 'alpha',
    ];
    $data = fetchLogoRows($db, $filters, $page, 60);
    $title     = "Omani $sectorLabel Logos — {$data['total']} brands indexed";
    $canonical = "https://cardify.om/logos/" . $sectorSlug;
    include __DIR__ . '/views/logos_sector.php';
    return;
}

// ---- hub ----
$page    = max(1, (int) ($_GET['page'] ?? 1));
$filters = [
    'sector'        => $_GET['sector']  ?? null,
    'wilayat'       => $_GET['wilayat'] ?? null,
    'q'             => trim($_GET['q'] ?? '') ?: null,
    'verified_only' => !empty($_GET['verified']),
    'sort'          => $_GET['sort'] ?? 'alpha',
];
$data      = fetchLogoRows($db, $filters, $page, 60);
$total     = totalLogos($db);
$counts    = sectorCounts($db);
$title     = 'The Omani Logo Library — ' . number_format($total) . '+ Omani Brands';
$canonical = 'https://cardify.om/logos' . ($page > 1 ? "?page=$page" : '');
include __DIR__ . '/views/logos_hub.php';
