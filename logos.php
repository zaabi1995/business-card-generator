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
// Respect the global locale cookie/session set by the header language pill,
// while still honouring legacy ?lang=ar and /ar/logos URL paths.
$lang = function_exists('currentLocale') ? currentLocale() : 'en';
if (($_GET['lang'] ?? '') === 'ar') $lang = 'ar';
$isAr = $lang === 'ar';

// Bilingual sector labels, mirrors the $SECTORS map in companies.php.
// Keep both files in sync until these labels are unified into a shared data file.
$SECTORS_I18N = [
    'oil-gas'               => ['en' => 'Oil & Gas',              'ar' => 'النفط والغاز'],
    'construction'          => ['en' => 'Construction',           'ar' => 'الإنشاءات'],
    'trading'               => ['en' => 'Trading',                'ar' => 'التجارة'],
    'finance'               => ['en' => 'Finance & Banking',      'ar' => 'المالية والمصرفية'],
    'real-estate'           => ['en' => 'Real Estate',            'ar' => 'العقارات'],
    'manufacturing'         => ['en' => 'Manufacturing',          'ar' => 'التصنيع'],
    'logistics-shipping'    => ['en' => 'Logistics & Shipping',   'ar' => 'الخدمات اللوجستية'],
    'food-beverage'         => ['en' => 'Food & Beverage',        'ar' => 'الأغذية والمشروبات'],
    'healthcare'            => ['en' => 'Healthcare',             'ar' => 'الرعاية الصحية'],
    'education'             => ['en' => 'Education',              'ar' => 'التعليم'],
    'hospitality-tourism'   => ['en' => 'Hospitality & Tourism',  'ar' => 'الضيافة والسياحة'],
    'technology'            => ['en' => 'Technology',             'ar' => 'التكنولوجيا'],
    'telecom'               => ['en' => 'Telecommunications',     'ar' => 'الاتصالات'],
    'automotive'            => ['en' => 'Automotive',             'ar' => 'السيارات'],
    'retail'                => ['en' => 'Retail',                 'ar' => 'تجارة التجزئة'],
    'agriculture-fisheries' => ['en' => 'Agriculture & Fisheries','ar' => 'الزراعة والأسماك'],
    'mining'                => ['en' => 'Mining',                 'ar' => 'التعدين'],
    'utilities'             => ['en' => 'Utilities',              'ar' => 'المرافق'],
    'media-advertising'     => ['en' => 'Media & Advertising',    'ar' => 'الإعلام والإعلان'],
    'professional-services' => ['en' => 'Professional Services',  'ar' => 'الخدمات المهنية'],
    'government-defense'    => ['en' => 'Government & Defense',   'ar' => 'الحكومة والدفاع'],
    'conglomerate'          => ['en' => 'Conglomerate',           'ar' => 'مجموعة شركات'],
    'other'                 => ['en' => 'Other',                  'ar' => 'أخرى'],
];
// Legacy flat map for code paths that only need a slug -> English label.
$SECTORS = array_map(fn($s) => $s['en'], $SECTORS_I18N);
// Locale-resolved label map for rendering.
$SECTOR_LABELS = array_map(fn($s) => $s[$lang] ?? $s['en'], $SECTORS_I18N);

function esc($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

function fetchLogoRows(Database $db, array $filters, int $page = 1, int $perPage = 60): array {
    $where  = ["logo_status IN ('indexed','verified')"];
    $params = [];
    if (!empty($filters['sector']))        { $where[] = "sector = :s";  $params[':s'] = $filters['sector']; }
    if (!empty($filters['wilayat']))       { $where[] = "wilayat = :w"; $params[':w'] = $filters['wilayat']; }
    if (!empty($filters['verified_only'])) { $where[] = "logo_status = 'verified'"; }
    if (!empty($filters['q'])) {
        $where[] = "(name_en LIKE :q_en OR name_ar LIKE :q_ar)";
        $params[':q_en'] = '%' . $filters['q'] . '%';
        $params[':q_ar'] = '%' . $filters['q'] . '%';
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
    $title = $isAr ? 'الملف الإعلامي، مكتبة الشعارات العمانية' : 'Press Kit, Omani Logo Library';
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
    $sectorLabel = $SECTOR_LABELS[$sectorSlug];
    $page        = max(1, (int) ($_GET['page'] ?? 1));
    $filters = [
        'sector'        => $sectorSlug,
        'q'             => trim($_GET['q'] ?? '') ?: null,
        'verified_only' => !empty($_GET['verified']),
        'sort'          => $_GET['sort'] ?? 'alpha',
    ];
    $data = fetchLogoRows($db, $filters, $page, 60);
    $title     = $isAr
        ? "شعارات {$sectorLabel} العمانية، {$data['total']} علامة مفهرسة"
        : "Omani {$sectorLabel} Logos, {$data['total']} brands indexed";
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
$title     = $isAr
    ? 'مكتبة الشعارات العمانية، ' . number_format($total) . '+ علامة عُمانية'
    : 'The Omani Logo Library, ' . number_format($total) . '+ Omani Brands';
$canonical = 'https://cardify.om/logos' . ($page > 1 ? "?page=$page" : '');
include __DIR__ . '/views/logos_hub.php';
