<?php
/**
 * Cardify, Oman Business Index 2026 (Landing Page)
 *
 * Flagship press-ready landing page. Presents the 2,414 Omani enterprises
 * as a curated, research-grade index. Purpose: backlink magnet / press hook
 * (Oman Observer, Times of Oman, Muscat Daily, LinkedIn citations).
 *
 * Route: /oman-business-index
 * Public, cacheable, no session unless UTM params present.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';

$db = Database::getInstance();

// Locale detection for OBI chrome. Deep analytical prose below (executive
// summary, methodology, key findings) stays in its authored English for now;
// a banner directs Arabic readers to /companies for the fully bilingual
// searchable directory.
// URL-driven locale (EN default; /ar/oman-business-index or ?lang=ar for AR).
// Don't inherit cookie/Accept-Language here or this SEO landing ends up
// showing Arabic to English browsers.
$lang = ($_GET['lang'] ?? '') === 'ar' ? 'ar' : 'en';
$isAr = ($lang === 'ar');
if (class_exists('I18n')) { I18n::setLocale($lang); }

// --- Canonical sector + wilayat labels (kept in sync with companies.php) ---
$SECTORS = [
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
$WILAYATS = [
    'muscat'             => ['en' => 'Muscat',              'ar' => 'مسقط'],
    'dhofar'             => ['en' => 'Dhofar',              'ar' => 'ظفار'],
    'musandam'           => ['en' => 'Musandam',            'ar' => 'مسندم'],
    'al-buraimi'         => ['en' => 'Al Buraimi',          'ar' => 'البريمي'],
    'al-dakhiliyah'      => ['en' => 'Al Dakhiliyah',       'ar' => 'الداخلية'],
    'al-dhahirah'        => ['en' => 'Al Dhahirah',         'ar' => 'الظاهرة'],
    'al-sharqiyah-north' => ['en' => 'Ash Sharqiyah North', 'ar' => 'شمال الشرقية'],
    'al-sharqiyah-south' => ['en' => 'Ash Sharqiyah South', 'ar' => 'جنوب الشرقية'],
    'al-batinah-north'   => ['en' => 'Al Batinah North',    'ar' => 'شمال الباطنة'],
    'al-batinah-south'   => ['en' => 'Al Batinah South',    'ar' => 'جنوب الباطنة'],
    'al-wusta'           => ['en' => 'Al Wusta',            'ar' => 'الوسطى'],
];

function obiLabelOf($key, $dict) {
    return $dict[$key]['en'] ?? ucwords(str_replace('-', ' ', (string) $key));
}
function obiEscq($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

// --- Live aggregate stats (safe fallbacks if DB is unreachable) ---
$stats = [
    'total'          => 2414,
    'large_count'    => 0,
    'medium_count'   => 0,
    'sector_count'   => count($SECTORS),
    'wilayat_count'  => count($WILAYATS),
    'last_updated'   => null,
];

try {
    $row = $db->fetchOne(
        "SELECT COUNT(*) AS total,
                SUM(size_bucket = 'large')  AS large_count,
                SUM(size_bucket = 'medium') AS medium_count,
                MAX(updated_at) AS last_updated
           FROM om_companies"
    );
    if ($row && (int) $row['total'] > 0) {
        $stats['total']        = (int) $row['total'];
        $stats['large_count']  = (int) $row['large_count'];
        $stats['medium_count'] = (int) $row['medium_count'];
        $stats['last_updated'] = $row['last_updated'];
    }
    $secRow = $db->fetchOne("SELECT COUNT(DISTINCT sector) AS c FROM om_companies");
    if ($secRow && (int) $secRow['c'] > 0) $stats['sector_count']  = (int) $secRow['c'];
    $wilRow = $db->fetchOne("SELECT COUNT(DISTINCT wilayat) AS c FROM om_companies");
    if ($wilRow && (int) $wilRow['c'] > 0) $stats['wilayat_count'] = (int) $wilRow['c'];
} catch (Throwable $e) {
    error_log('oman-business-index stats query failed: ' . $e->getMessage());
}

// --- Top sectors by count (live) ---
$topSectors = [];
try {
    $topSectors = $db->fetchAll(
        "SELECT sector, COUNT(*) AS c
           FROM om_companies
          GROUP BY sector
          ORDER BY c DESC
          LIMIT 5"
    );
} catch (Throwable $e) { $topSectors = []; }

// --- Top wilayats by count (live) ---
$topWilayats = [];
try {
    $topWilayats = $db->fetchAll(
        "SELECT wilayat, COUNT(*) AS c
           FROM om_companies
          GROUP BY wilayat
          ORDER BY c DESC
          LIMIT 5"
    );
} catch (Throwable $e) { $topWilayats = []; }

// --- Sector with fewest companies (live, excluding 'other' when possible) ---
$smallestSector = null;
try {
    $smallestSector = $db->fetchOne(
        "SELECT sector, COUNT(*) AS c
           FROM om_companies
          WHERE sector <> 'other'
          GROUP BY sector
          ORDER BY c ASC
          LIMIT 1"
    );
} catch (Throwable $e) { $smallestSector = null; }

// --- Top 10 flagship enterprises (first 10 rows, seeded in order) ---
$top10 = [];
try {
    $top10 = $db->fetchAll(
        "SELECT id, slug, name_en, name_ar, sector, wilayat, size_bucket, website
           FROM om_companies
          ORDER BY id ASC
          LIMIT 10"
    );
} catch (Throwable $e) { $top10 = []; }

// --- Sector counts keyed by slug (for the "Explore by sector" block) ---
$sectorCounts = [];
try {
    $rows = $db->fetchAll("SELECT sector, COUNT(*) AS c FROM om_companies GROUP BY sector");
    foreach ($rows as $r) $sectorCounts[$r['sector']] = (int) $r['c'];
} catch (Throwable $e) { /* noop */ }

// --- Wilayat counts keyed by slug ---
$wilayatCounts = [];
try {
    $rows = $db->fetchAll("SELECT wilayat, COUNT(*) AS c FROM om_companies GROUP BY wilayat");
    foreach ($rows as $r) $wilayatCounts[$r['wilayat']] = (int) $r['c'];
} catch (Throwable $e) { /* noop */ }

// --- Logo Library sample (for the visual gallery section) ---
// Pull a curated sample across as many sectors as possible, preferring
// verified > indexed and recent updates. Cap at ~30 so the page stays fast.
$logoSample = [];
try {
    $logoSample = $db->fetchAll(
        "SELECT c.id, c.slug, c.name_en, c.name_ar, c.sector,
                c.logo_svg_path, c.logo_png_path, c.logo_webp_path,
                c.logo_png_512_path, c.logo_dominant_color, c.logo_status
           FROM om_companies c
          WHERE c.logo_status IN ('indexed','verified')
            AND (c.logo_svg_path IS NOT NULL
                 OR c.logo_png_path IS NOT NULL
                 OR c.logo_webp_path IS NOT NULL)
          ORDER BY FIELD(c.logo_status,'verified','indexed'),
                   c.logo_updated_at DESC
          LIMIT 30"
    );
} catch (Throwable $e) { $logoSample = []; }
$logoTotal = 0;
try {
    $logoTotal = (int) ($db->fetchOne(
        "SELECT COUNT(*) c FROM om_companies WHERE logo_status IN ('indexed','verified')"
    )['c'] ?? 0);
} catch (Throwable $e) {}

// --- Derived helpers ---
$totalFmt    = number_format($stats['total']);
$largeFmt    = number_format($stats['large_count']);
$mediumFmt   = number_format($stats['medium_count']);
$sectorFmt   = number_format($stats['sector_count']);
$wilayatFmt  = number_format($stats['wilayat_count']);

$ratioText = ',';
if ($stats['medium_count'] > 0) {
    $ratio = $stats['large_count'] / $stats['medium_count'];
    $ratioText = '1 : ' . number_format(1 / max($ratio, 0.0001), 2);
    // More intuitive: "large are X% of medium"
}
$largePctOfTotal = $stats['total'] > 0
    ? round(($stats['large_count'] / $stats['total']) * 100, 1)
    : 0;
$mediumPctOfTotal = $stats['total'] > 0
    ? round(($stats['medium_count'] / $stats['total']) * 100, 1)
    : 0;

// --- Last updated date (from MAX(updated_at)) ---
$lastUpdatedTs = $stats['last_updated'] ? strtotime($stats['last_updated']) : time();
$lastUpdatedHuman = date('F j, Y', $lastUpdatedTs);
$lastUpdatedIso   = date('c', $lastUpdatedTs);

// --- Page metadata ---
$baseUrl         = 'https://cardify.om';
$pageTitle       = t('obi.page_title');
$pageDescription = t('obi.page_desc', ['count' => $totalFmt]);
// Ensure under 155 chars; trim if needed.
if (strlen($pageDescription) > 155) {
    $pageDescription = substr($pageDescription, 0, 152) . '...';
}
$canonicalUrl    = $baseUrl . '/oman-business-index';
$ogType          = 'website';
$brandName       = 'Cardify';
$showNavigation  = true;
$htmlClass       = 'scroll-smooth';
$bodyClass       = 'bg-white';

// --- JSON-LD structured data ---
$datasetLd = [
    '@context'      => 'https://schema.org',
    '@type'         => 'Dataset',
    'name'          => 'Oman Business Index 2026',
    'alternateName' => 'Cardify Oman Business Index',
    'description'   => 'A free, bilingual, public index of ' . $stats['total']
        . ' large and medium-sized enterprises registered in the Sultanate of Oman, classified by sector and governorate. Derived from the MoCIIP public register.',
    'url'           => $canonicalUrl,
    'keywords'      => [
        'Oman', 'Sultanate of Oman', 'business directory', 'companies',
        'MoCIIP', 'Vision 2040', 'enterprises', 'SMEs', 'large companies',
        'private sector', 'Oman economy'
    ],
    'license'       => 'https://creativecommons.org/licenses/by/4.0/',
    'inLanguage'    => ['en', 'ar'],
    'isAccessibleForFree' => true,
    'dateModified'  => $lastUpdatedIso,
    'datePublished' => '2026-04-01',
    'creator'       => [
        '@type' => 'Organization',
        'name'  => 'Cardify',
        'url'   => 'https://cardify.om',
    ],
    'publisher'     => [
        '@type' => 'Organization',
        'name'  => 'Cardify',
        'url'   => 'https://cardify.om',
        'logo'  => [
            '@type' => 'ImageObject',
            'url'   => 'https://cardify.om/assets/images/cardify-logo.png',
            'creditText' => 'Cardify',
            'copyrightNotice' => '© Cardify',
            'license' => 'https://cardify.om/terms',
        ],
    ],
    'distribution'  => [
        [
            '@type'       => 'DataDownload',
            'encodingFormat' => 'text/html',
            'contentUrl'  => $baseUrl . '/companies',
        ],
    ],
    'variableMeasured' => [
        ['@type' => 'PropertyValue', 'name' => 'Sector',      'value' => $stats['sector_count']  . ' sectors'],
        ['@type' => 'PropertyValue', 'name' => 'Governorate', 'value' => $stats['wilayat_count'] . ' governorates'],
        ['@type' => 'PropertyValue', 'name' => 'Size bucket', 'value' => 'large, medium'],
    ],
    'spatialCoverage' => [
        '@type' => 'Country',
        'name'  => 'Oman',
    ],
];

$faq = [
    ['q' => t('obi.faq1_q'), 'a' => t('obi.faq1_a', ['sectors' => $stats['sector_count']])],
    ['q' => t('obi.faq2_q'), 'a' => t('obi.faq2_a')],
    ['q' => t('obi.faq3_q'), 'a' => t('obi.faq3_a')],
    ['q' => t('obi.faq4_q'), 'a' => t('obi.faq4_a')],
    ['q' => t('obi.faq5_q'), 'a' => t('obi.faq5_a')],
    ['q' => t('obi.faq6_q'), 'a' => t('obi.faq6_a')],
    ['q' => t('obi.faq7_q'), 'a' => t('obi.faq7_a')],
    ['q' => t('obi.faq8_q'), 'a' => t('obi.faq8_a')],
    ['q' => t('obi.faq9_q'), 'a' => t('obi.faq9_a')],
];

$faqLd = [
    '@context'   => 'https://schema.org',
    '@type'      => 'FAQPage',
    'mainEntity' => array_map(function ($item) {
        return [
            '@type'          => 'Question',
            'name'           => $item['q'],
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text'  => $item['a'],
            ],
        ];
    }, $faq),
];

$crumbLd = [
    '@context'        => 'https://schema.org',
    '@type'           => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home',                  'item' => $baseUrl . '/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Oman Business Index',   'item' => $canonicalUrl],
    ],
];

$orgLd = [
    '@context'    => 'https://schema.org',
    '@type'       => 'Organization',
    'name'        => 'Cardify',
    'url'         => 'https://cardify.om',
    'logo'        => 'https://cardify.om/assets/images/cardify-logo.png',
    'sameAs'      => [
        'https://www.linkedin.com/company/cardify-om',
    ],
    'address'     => [
        '@type'          => 'PostalAddress',
        'addressCountry' => 'OM',
        'addressLocality'=> 'Muscat',
    ],
];

$extraHead =
      '<script type="application/ld+json">' . json_encode($datasetLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>'
    . '<script type="application/ld+json">' . json_encode($faqLd,     JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>'
    . '<script type="application/ld+json">' . json_encode($crumbLd,   JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>'
    . '<script type="application/ld+json">' . json_encode($orgLd,     JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>'
    . '<link rel="alternate" hreflang="en" href="' . $baseUrl . '/oman-business-index">'
    . '<link rel="alternate" hreflang="ar" href="' . $baseUrl . '/ar/oman-business-index">'
    . '<link rel="alternate" hreflang="x-default" href="' . $baseUrl . '/oman-business-index">'
    . '<meta name="article:published_time" content="2026-04-01">'
    . '<meta name="article:modified_time" content="' . obiEscq($lastUpdatedIso) . '">';
$suppressDefaultHreflang = true;
require_once INCLUDES_DIR . '/ui-header.php';
?>

<main class="bg-white text-gray-900">
    <!-- ============================================================
         HERO
         ============================================================ -->
    <section class="relative overflow-hidden bg-gradient-to-b from-blue-50 via-white to-white border-b border-gray-100">
        <div class="absolute inset-0 pointer-events-none" aria-hidden="true">
            <div class="absolute -top-32 -left-32 w-96 h-96 bg-blue-100 rounded-full blur-3xl opacity-60"></div>
            <div class="absolute -bottom-32 -right-32 w-96 h-96 bg-indigo-100 rounded-full blur-3xl opacity-50"></div>
        </div>
        <div class="relative max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 pt-28 pb-16 sm:pt-32 sm:pb-20">
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white border border-blue-200 text-blue-700 text-xs font-semibold tracking-wide uppercase shadow-sm">
                <span class="w-1.5 h-1.5 rounded-full bg-blue-600"></span>
                <?= t('obi.hero_badge') ?>
            </div>
            <h1 class="mt-5 text-4xl sm:text-5xl lg:text-6xl font-bold tracking-tight text-gray-900 leading-[1.08]">
                <?= htmlspecialchars(t('obi.hero_h1_line1')) ?><br class="hidden sm:block">
                <span class="text-blue-700"><?= htmlspecialchars(t('obi.hero_h1_line2', ['count' => $totalFmt])) ?></span> <?= htmlspecialchars(t('obi.hero_h1_suffix')) ?>
            </h1>
            <p class="mt-6 text-lg sm:text-xl text-gray-600 max-w-3xl leading-relaxed">
                <?= htmlspecialchars(t('obi.hero_sub')) ?>
            </p>

            <div class="mt-6 flex flex-wrap items-center gap-3 text-sm text-gray-500">
                <span class="inline-flex items-center gap-1.5">
                    <i class="fa-regular fa-calendar text-gray-400"></i>
                    <?= htmlspecialchars(t('obi.hero_last_upd')) ?>
                    <time datetime="<?= obiEscq($lastUpdatedIso) ?>"><?= obiEscq($isAr ? I18n::formatDate($lastUpdatedTs, 'ar') : $lastUpdatedHuman) ?></time>
                </span>
                <span class="text-gray-300">&middot;</span>
                <span class="inline-flex items-center gap-1.5">
                    <i class="fa-regular fa-file-lines text-gray-400"></i>
                    CC BY 4.0
                </span>
                <span class="text-gray-300">&middot;</span>
                <span class="inline-flex items-center gap-1.5">
                    <i class="fa-solid fa-language text-gray-400"></i>
                    <?= t('obi.hero_langs') ?>
                </span>
            </div>

            <div class="mt-8 flex flex-wrap gap-3">
                <a href="/companies" class="inline-flex items-center gap-2 px-5 py-3 rounded-lg bg-blue-600 text-white font-semibold shadow-sm hover:bg-blue-700 transition">
                    <i class="fa-solid fa-magnifying-glass text-sm"></i>
                    <?= htmlspecialchars(t('obi.hero_cta_search')) ?>
                </a>
                <a href="#cite" class="inline-flex items-center gap-2 px-5 py-3 rounded-lg bg-white text-gray-800 font-semibold border border-gray-200 hover:border-blue-300 hover:text-blue-700 transition">
                    <i class="fa-solid fa-quote-right text-sm"></i>
                    <?= htmlspecialchars(t('obi.hero_cta_cite')) ?>
                </a>
                <a href="#methodology" class="inline-flex items-center gap-2 px-5 py-3 rounded-lg text-gray-700 font-semibold hover:text-blue-700 transition">
                    <?= t('obi.hero_cta_methodology') ?>
                </a>
            </div>

            <?php if ($isAr): ?>
            <div class="mt-8 rounded-xl bg-amber-50 border border-amber-200 text-amber-900 text-sm p-4">
                <i class="fa-solid fa-circle-info mr-1.5"></i>
                <?= htmlspecialchars(t('obi.ar_banner')) ?>,
                <a href="/companies" class="font-semibold underline"><?= htmlspecialchars(t('obi.ar_banner_link')) ?></a>.
            </div>
            <?php endif; ?>

            <!-- Key stats grid -->
            <dl class="mt-12 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4 sm:gap-5">
                <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm">
                    <dt class="text-xs uppercase tracking-wide text-gray-500"><?= htmlspecialchars(t('obi.stat_total')) ?></dt>
                    <dd class="mt-2 text-3xl font-bold text-gray-900 tabular-nums"><?= obiEscq($totalFmt) ?></dd>
                </div>
                <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm">
                    <dt class="text-xs uppercase tracking-wide text-gray-500"><?= htmlspecialchars(t('obi.stat_large')) ?></dt>
                    <dd class="mt-2 text-3xl font-bold text-gray-900 tabular-nums"><?= obiEscq($largeFmt) ?></dd>
                    <div class="text-xs text-gray-500 mt-1"><?= obiEscq($largePctOfTotal) ?>% <?= htmlspecialchars(t('obi.stat_of_total')) ?></div>
                </div>
                <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm">
                    <dt class="text-xs uppercase tracking-wide text-gray-500"><?= htmlspecialchars(t('obi.stat_medium')) ?></dt>
                    <dd class="mt-2 text-3xl font-bold text-gray-900 tabular-nums"><?= obiEscq($mediumFmt) ?></dd>
                    <div class="text-xs text-gray-500 mt-1"><?= obiEscq($mediumPctOfTotal) ?>% <?= htmlspecialchars(t('obi.stat_of_total')) ?></div>
                </div>
                <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm">
                    <dt class="text-xs uppercase tracking-wide text-gray-500"><?= htmlspecialchars(t('obi.stat_sectors')) ?></dt>
                    <dd class="mt-2 text-3xl font-bold text-gray-900 tabular-nums"><?= obiEscq($sectorFmt) ?></dd>
                </div>
                <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm col-span-2 sm:col-span-1">
                    <dt class="text-xs uppercase tracking-wide text-gray-500"><?= htmlspecialchars(t('obi.stat_governorates')) ?></dt>
                    <dd class="mt-2 text-3xl font-bold text-gray-900 tabular-nums"><?= obiEscq($wilayatFmt) ?></dd>
                </div>
            </dl>
        </div>
    </section>

    <!-- ============================================================
         EXECUTIVE SUMMARY
         ============================================================ -->
    <section id="executive-summary" class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-20 scroll-mt-24">
        <div class="flex items-center gap-3 mb-6">
            <div class="w-10 h-10 rounded-lg bg-blue-50 text-blue-700 flex items-center justify-center">
                <i class="fa-solid fa-chart-line"></i>
            </div>
            <span class="text-xs font-semibold uppercase tracking-widest text-blue-700"><?= htmlspecialchars(t('obi.section')) ?> 01</span>
        </div>
        <h2 class="text-3xl sm:text-4xl font-bold text-gray-900 tracking-tight"><?= htmlspecialchars(t('obi.exec_summary')) ?></h2>
        <div class="mt-6 space-y-5 text-lg text-gray-700 leading-relaxed">
            <p><?= t('obi.exec_p1') ?></p>
            <p><?= t('obi.exec_p2', ['total' => $totalFmt, 'large' => $largeFmt, 'medium' => $mediumFmt, 'sectors' => $sectorFmt, 'wilayats' => $wilayatFmt]) ?></p>
            <p><?= t('obi.exec_p3') ?></p>
        </div>
    </section>

    <div class="border-t border-gray-100 max-w-6xl mx-auto"></div>

    <!-- ============================================================
         METHODOLOGY
         ============================================================ -->
    <section id="methodology" class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-20 scroll-mt-24">
        <div class="flex items-center gap-3 mb-6">
            <div class="w-10 h-10 rounded-lg bg-blue-50 text-blue-700 flex items-center justify-center">
                <i class="fa-solid fa-flask"></i>
            </div>
            <span class="text-xs font-semibold uppercase tracking-widest text-blue-700"><?= htmlspecialchars(t('obi.section')) ?> 02</span>
        </div>
        <h2 class="text-3xl sm:text-4xl font-bold text-gray-900 tracking-tight"><?= htmlspecialchars(t('obi.methodology')) ?></h2>

        <div class="mt-6 space-y-5 text-lg text-gray-700 leading-relaxed">
            <p><?= t('obi.meth_p1') ?></p>
            <p><?= t('obi.meth_p2', ['sectors' => $sectorFmt, 'wilayats' => $wilayatFmt]) ?></p>
            <p><?= t('obi.meth_p3') ?></p>
            <p><?= t('obi.meth_p4') ?></p>
        </div>
    </section>

    <div class="border-t border-gray-100 max-w-6xl mx-auto"></div>

    <!-- ============================================================
         KEY FINDINGS
         ============================================================ -->
    <section id="key-findings" class="bg-gray-50 border-y border-gray-100 scroll-mt-24">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-20">
            <div class="flex items-center gap-3 mb-6">
                <div class="w-10 h-10 rounded-lg bg-blue-50 text-blue-700 flex items-center justify-center">
                    <i class="fa-solid fa-lightbulb"></i>
                </div>
                <span class="text-xs font-semibold uppercase tracking-widest text-blue-700"><?= htmlspecialchars(t('obi.section')) ?> 03</span>
            </div>
            <h2 class="text-3xl sm:text-4xl font-bold text-gray-900 tracking-tight"><?= htmlspecialchars(t('obi.key_findings')) ?></h2>
            <p class="mt-4 text-lg text-gray-600 max-w-3xl">
                <?= htmlspecialchars(t('obi.key_findings_sub', ['total' => $totalFmt])) ?>
            </p>

            <div class="mt-10 grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Finding 01: top sectors -->
                <div class="bg-white rounded-2xl p-6 border border-gray-200 shadow-sm">
                    <div class="flex items-center gap-3 mb-4">
                        <span class="text-xs font-bold text-gray-400 tracking-widest">FINDING 01</span>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900"><?= htmlspecialchars(t('obi.finding01_title')) ?></h3>
                    <p class="mt-2 text-gray-600"><?= htmlspecialchars(t('obi.finding01_body')) ?></p>
                    <?php if (!empty($topSectors)): ?>
                        <ol class="mt-5 space-y-3">
                            <?php
                            $maxC = max(array_map(fn($r) => (int) $r['c'], $topSectors));
                            foreach ($topSectors as $idx => $s):
                                $secSlug  = $s['sector'];
                                $secLabel = obiLabelOf($secSlug, $SECTORS);
                                $secCount = (int) $s['c'];
                                $barPct   = $maxC > 0 ? ($secCount / $maxC) * 100 : 0;
                                $sectorPct = $stats['total'] > 0 ? round(($secCount / $stats['total']) * 100, 1) : 0;
                            ?>
                                <li>
                                    <a href="/companies/sector/<?= obiEscq($secSlug) ?>" class="group block">
                                        <div class="flex items-baseline justify-between gap-3">
                                            <span class="text-sm font-semibold text-gray-900 group-hover:text-blue-700">
                                                <?= ($idx + 1) ?>. <?= obiEscq($secLabel) ?>
                                            </span>
                                            <span class="text-sm text-gray-500 tabular-nums">
                                                <?= obiEscq(number_format($secCount)) ?>
                                                <span class="text-xs text-gray-400">(<?= obiEscq($sectorPct) ?>%)</span>
                                            </span>
                                        </div>
                                        <div class="mt-1.5 h-2 rounded-full bg-gray-100 overflow-hidden">
                                            <div class="h-full bg-blue-600 rounded-full" style="width: <?= obiEscq($barPct) ?>%"></div>
                                        </div>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php else: ?>
                        <p class="mt-4 text-sm text-gray-500"><?= htmlspecialchars(t('obi.finding01_unavail')) ?></p>
                    <?php endif; ?>
                </div>

                <!-- Finding 02: top governorate -->
                <div class="bg-white rounded-2xl p-6 border border-gray-200 shadow-sm">
                    <div class="flex items-center gap-3 mb-4">
                        <span class="text-xs font-bold text-gray-400 tracking-widest">FINDING 02</span>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900"><?= htmlspecialchars(t('obi.finding02_title')) ?></h3>
                    <p class="mt-2 text-gray-600"><?= htmlspecialchars(t('obi.finding02_body')) ?></p>
                    <?php if (!empty($topWilayats)): ?>
                        <ol class="mt-5 space-y-3">
                            <?php
                            $maxW = max(array_map(fn($r) => (int) $r['c'], $topWilayats));
                            foreach ($topWilayats as $idx => $w):
                                $wSlug  = $w['wilayat'];
                                $wLabel = obiLabelOf($wSlug, $WILAYATS);
                                $wCount = (int) $w['c'];
                                $barPct = $maxW > 0 ? ($wCount / $maxW) * 100 : 0;
                                $wPct   = $stats['total'] > 0 ? round(($wCount / $stats['total']) * 100, 1) : 0;
                            ?>
                                <li>
                                    <a href="/companies/wilayat/<?= obiEscq($wSlug) ?>" class="group block">
                                        <div class="flex items-baseline justify-between gap-3">
                                            <span class="text-sm font-semibold text-gray-900 group-hover:text-blue-700">
                                                <?= ($idx + 1) ?>. <?= obiEscq($wLabel) ?>
                                            </span>
                                            <span class="text-sm text-gray-500 tabular-nums">
                                                <?= obiEscq(number_format($wCount)) ?>
                                                <span class="text-xs text-gray-400">(<?= obiEscq($wPct) ?>%)</span>
                                            </span>
                                        </div>
                                        <div class="mt-1.5 h-2 rounded-full bg-gray-100 overflow-hidden">
                                            <div class="h-full bg-emerald-600 rounded-full" style="width: <?= obiEscq($barPct) ?>%"></div>
                                        </div>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php else: ?>
                        <p class="mt-4 text-sm text-gray-500"><?= htmlspecialchars(t('obi.finding02_unavail')) ?></p>
                    <?php endif; ?>
                </div>

                <!-- Finding 03: large vs medium ratio -->
                <div class="bg-white rounded-2xl p-6 border border-gray-200 shadow-sm">
                    <div class="flex items-center gap-3 mb-4">
                        <span class="text-xs font-bold text-gray-400 tracking-widest">FINDING 03</span>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900"><?= htmlspecialchars(t('obi.finding03_title')) ?></h3>
                    <p class="mt-2 text-gray-600">
                        <?= t('obi.finding03_body', ['total' => $totalFmt, 'large' => $largeFmt, 'largePct' => $largePctOfTotal, 'medium' => $mediumFmt, 'mediumPct' => $mediumPctOfTotal]) ?>
                    </p>
                    <div class="mt-5 flex h-3 rounded-full overflow-hidden bg-gray-100">
                        <div class="bg-blue-600" style="width: <?= obiEscq($largePctOfTotal) ?>%"></div>
                        <div class="bg-indigo-300" style="width: <?= obiEscq($mediumPctOfTotal) ?>%"></div>
                    </div>
                    <div class="mt-3 flex items-center gap-4 text-xs text-gray-600">
                        <span class="inline-flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-blue-600"></span><?= htmlspecialchars(t('obi.finding03_large')) ?></span>
                        <span class="inline-flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-indigo-300"></span><?= htmlspecialchars(t('obi.finding03_medium')) ?></span>
                    </div>
                </div>

                <!-- Finding 04: smallest sector -->
                <div class="bg-white rounded-2xl p-6 border border-gray-200 shadow-sm">
                    <div class="flex items-center gap-3 mb-4">
                        <span class="text-xs font-bold text-gray-400 tracking-widest">FINDING 04</span>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900"><?= htmlspecialchars(t('obi.finding04_title')) ?></h3>
                    <?php if ($smallestSector): ?>
                        <?php
                            $sSlug = $smallestSector['sector'];
                            $sLabel = $SECTORS[$sSlug][$isAr ? 'ar' : 'en'] ?? obiLabelOf($sSlug, $SECTORS);
                            $sCount = (int) $smallestSector['c'];
                        ?>
                        <p class="mt-2 text-gray-600">
                            <?= t('obi.finding04_body', ['sector' => htmlspecialchars($sLabel), 'count' => number_format($sCount), 'plural' => ($sCount === 1 ? '' : 's')]) ?>
                        </p>
                        <a href="/companies/sector/<?= obiEscq($sSlug) ?>" class="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-blue-700 hover:text-blue-800">
                            <?= htmlspecialchars(t('obi.finding04_browse', ['sector' => $sLabel])) ?>
                            <i class="fa-solid fa-arrow-right text-xs"></i>
                        </a>
                    <?php else: ?>
                        <p class="mt-2 text-gray-600"><?= htmlspecialchars(t('obi.finding04_unavail')) ?></p>
                    <?php endif; ?>
                </div>

                <!-- Finding 05: coverage -->
                <div class="bg-white rounded-2xl p-6 border border-gray-200 shadow-sm lg:col-span-2">
                    <div class="flex items-center gap-3 mb-4">
                        <span class="text-xs font-bold text-gray-400 tracking-widest">FINDING 05</span>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900"><?= htmlspecialchars(t('obi.finding05_title')) ?></h3>
                    <p class="mt-2 text-gray-600">
                        <?= htmlspecialchars(t('obi.finding05_body', ['wilayats' => $wilayatFmt])) ?>
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================
         TOP 10 LARGEST ENTERPRISES
         ============================================================ -->
    <section id="top-10" class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-20 scroll-mt-24">
        <div class="flex items-center gap-3 mb-6">
            <div class="w-10 h-10 rounded-lg bg-blue-50 text-blue-700 flex items-center justify-center">
                <i class="fa-solid fa-trophy"></i>
            </div>
            <span class="text-xs font-semibold uppercase tracking-widest text-blue-700"><?= htmlspecialchars(t('obi.section')) ?> 04</span>
        </div>
        <h2 class="text-3xl sm:text-4xl font-bold text-gray-900 tracking-tight"><?= htmlspecialchars(t('obi.top10_heading')) ?></h2>
        <p class="mt-4 text-lg text-gray-600 max-w-3xl">
            <?= htmlspecialchars(t('obi.top10_sub')) ?>
        </p>

        <?php if (!empty($top10)): ?>
            <ol class="mt-10 divide-y divide-gray-100 border border-gray-200 rounded-2xl overflow-hidden bg-white">
                <?php foreach ($top10 as $idx => $c): ?>
                    <li>
                        <a href="/companies/<?= obiEscq($c['slug']) ?>" class="flex items-center gap-5 p-5 hover:bg-gray-50 transition">
                            <div class="flex-shrink-0 w-10 text-center text-2xl font-bold text-gray-300 tabular-nums">
                                <?= str_pad((string) ($idx + 1), 2, '0', STR_PAD_LEFT) ?>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="font-semibold text-gray-900 truncate"><?= obiEscq($c['name_en']) ?></div>
                                <?php if (!empty($c['name_ar'])): ?>
                                    <div class="text-sm text-gray-500 font-arabic truncate mt-0.5" dir="rtl"><?= obiEscq($c['name_ar']) ?></div>
                                <?php endif; ?>
                                <div class="mt-1.5 flex items-center gap-3 text-xs text-gray-500 flex-wrap">
                                    <span class="inline-flex items-center gap-1">
                                        <i class="fa-solid fa-industry text-gray-400"></i>
                                        <?= obiEscq(obiLabelOf($c['sector'], $SECTORS)) ?>
                                    </span>
                                    <span class="inline-flex items-center gap-1">
                                        <i class="fa-solid fa-location-dot text-gray-400"></i>
                                        <?= obiEscq(obiLabelOf($c['wilayat'], $WILAYATS)) ?>
                                    </span>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded bg-blue-50 text-blue-700 font-semibold uppercase tracking-wide text-[10px]">
                                        <?= $c['size_bucket'] === 'large' ? htmlspecialchars(t('obi.finding03_large')) : htmlspecialchars(t('obi.finding03_medium')) ?>
                                    </span>
                                </div>
                            </div>
                            <i class="fa-solid fa-chevron-right text-gray-300 text-sm"></i>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ol>
            <p class="mt-6 text-sm text-gray-500">
                <?= htmlspecialchars(t('obi.top10_fulllist')) ?>
                <a href="/companies" class="font-semibold text-blue-700 hover:text-blue-800">/companies</a><?= htmlspecialchars(t('obi.top10_fulllist_suffix')) ?>
            </p>
        <?php else: ?>
            <p class="mt-6 text-gray-500"><?= htmlspecialchars(t('obi.top10_unavail')) ?> <a href="/companies" class="text-blue-700 font-semibold">/companies</a>.</p>
        <?php endif; ?>
    </section>

    <div class="border-t border-gray-100 max-w-6xl mx-auto"></div>

    <!-- ============================================================
         EXPLORE BY SECTOR
         ============================================================ -->
    <section id="by-sector" class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-20 scroll-mt-24">
        <div class="flex items-center gap-3 mb-6">
            <div class="w-10 h-10 rounded-lg bg-blue-50 text-blue-700 flex items-center justify-center">
                <i class="fa-solid fa-layer-group"></i>
            </div>
            <span class="text-xs font-semibold uppercase tracking-widest text-blue-700"><?= htmlspecialchars(t('obi.section')) ?> 05</span>
        </div>
        <h2 class="text-3xl sm:text-4xl font-bold text-gray-900 tracking-tight"><?= htmlspecialchars(t('obi.by_sector')) ?></h2>
        <p class="mt-4 text-lg text-gray-600 max-w-3xl">
            <?= htmlspecialchars(t('obi.by_sector_sub')) ?>
        </p>

        <div class="mt-10 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            <?php foreach ($SECTORS as $slug => $labels): ?>
                <?php $count = $sectorCounts[$slug] ?? 0; ?>
                <a href="/companies/sector/<?= obiEscq($slug) ?>" class="group flex items-center justify-between gap-4 p-4 rounded-xl border border-gray-200 bg-white hover:border-blue-300 hover:shadow-sm transition">
                    <div class="min-w-0">
                        <div class="font-semibold text-gray-900 group-hover:text-blue-700 truncate"><?= obiEscq($isAr ? $labels['ar'] : $labels['en']) ?></div>
                        <div class="text-xs text-gray-500 <?= $isAr ? '' : 'font-arabic' ?> truncate" <?= $isAr ? 'dir="ltr"' : 'dir="rtl"' ?>><?= obiEscq($isAr ? $labels['en'] : $labels['ar']) ?></div>
                    </div>
                    <div class="flex-shrink-0 text-right">
                        <div class="text-lg font-bold text-gray-900 tabular-nums"><?= obiEscq(number_format($count)) ?></div>
                        <div class="text-[10px] uppercase tracking-wide text-gray-400"><?= htmlspecialchars(t('obi.sector_count_suffix')) ?></div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="border-t border-gray-100 max-w-6xl mx-auto"></div>

    <!-- ============================================================
         EXPLORE BY GOVERNORATE
         ============================================================ -->
    <section id="by-governorate" class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-20 scroll-mt-24">
        <div class="flex items-center gap-3 mb-6">
            <div class="w-10 h-10 rounded-lg bg-emerald-50 text-emerald-700 flex items-center justify-center">
                <i class="fa-solid fa-map-location-dot"></i>
            </div>
            <span class="text-xs font-semibold uppercase tracking-widest text-emerald-700"><?= htmlspecialchars(t('obi.section')) ?> 06</span>
        </div>
        <h2 class="text-3xl sm:text-4xl font-bold text-gray-900 tracking-tight"><?= htmlspecialchars(t('obi.by_gov')) ?></h2>
        <p class="mt-4 text-lg text-gray-600 max-w-3xl">
            <?= htmlspecialchars(t('obi.by_gov_sub', ['count' => $wilayatFmt])) ?>
        </p>

        <div class="mt-10 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
            <?php foreach ($WILAYATS as $slug => $labels): ?>
                <?php $count = $wilayatCounts[$slug] ?? 0; ?>
                <a href="/companies/wilayat/<?= obiEscq($slug) ?>" class="group flex items-center justify-between gap-3 p-4 rounded-xl border border-gray-200 bg-white hover:border-emerald-400 hover:shadow-sm transition">
                    <div class="min-w-0">
                        <div class="font-semibold text-gray-900 group-hover:text-emerald-700 truncate"><?= obiEscq($isAr ? $labels['ar'] : $labels['en']) ?></div>
                        <div class="text-xs text-gray-500 <?= $isAr ? '' : 'font-arabic' ?> truncate" <?= $isAr ? 'dir="ltr"' : 'dir="rtl"' ?>><?= obiEscq($isAr ? $labels['en'] : $labels['ar']) ?></div>
                    </div>
                    <div class="flex-shrink-0 text-right">
                        <div class="text-lg font-bold text-gray-900 tabular-nums"><?= obiEscq(number_format($count)) ?></div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- ============================================================
         SEARCH THE FULL INDEX
         ============================================================ -->
    <section id="search" class="bg-gradient-to-br from-blue-600 to-indigo-700 text-white scroll-mt-24">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-20 text-center">
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/10 backdrop-blur border border-white/20 text-xs font-semibold uppercase tracking-widest">
                <?= htmlspecialchars(t('obi.section')) ?> 07
            </div>
            <h2 class="mt-5 text-3xl sm:text-5xl font-bold tracking-tight"><?= htmlspecialchars(t('obi.search_heading')) ?></h2>
            <p class="mt-4 text-lg text-blue-100 max-w-2xl mx-auto">
                <?= htmlspecialchars(t('obi.search_sub', ['total' => $totalFmt])) ?>
            </p>
            <a href="/companies" class="mt-8 inline-flex items-center gap-2 px-7 py-4 rounded-xl bg-white text-blue-700 font-bold shadow-lg hover:shadow-xl hover:-translate-y-0.5 transition">
                <i class="fa-solid fa-magnifying-glass"></i>
                <?= htmlspecialchars(t('obi.search_cta')) ?>
                <i class="fa-solid fa-arrow-right text-sm"></i>
            </a>
            <div class="mt-8 flex flex-wrap items-center justify-center gap-x-6 gap-y-2 text-sm text-blue-200">
                <span class="inline-flex items-center gap-1.5"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars(t('obi.search_chk_bilingual')) ?></span>
                <span class="inline-flex items-center gap-1.5"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars(t('obi.search_chk_sector')) ?></span>
                <span class="inline-flex items-center gap-1.5"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars(t('obi.search_chk_gov')) ?></span>
                <span class="inline-flex items-center gap-1.5"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars(t('obi.search_chk_permalink')) ?></span>
            </div>
        </div>
    </section>

    <!-- ============================================================
         CITE THIS INDEX
         ============================================================ -->
    <section id="cite" class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-20 scroll-mt-24">
        <div class="flex items-center gap-3 mb-6">
            <div class="w-10 h-10 rounded-lg bg-blue-50 text-blue-700 flex items-center justify-center">
                <i class="fa-solid fa-quote-right"></i>
            </div>
            <span class="text-xs font-semibold uppercase tracking-widest text-blue-700"><?= htmlspecialchars(t('obi.section')) ?> 08</span>
        </div>
        <h2 class="text-3xl sm:text-4xl font-bold text-gray-900 tracking-tight"><?= htmlspecialchars(t('obi.cite_heading')) ?></h2>
        <p class="mt-4 text-lg text-gray-600 max-w-3xl">
            <?= htmlspecialchars(t('obi.cite_sub')) ?>
        </p>

        <div class="mt-8 space-y-6">
            <div>
                <div class="text-xs font-semibold uppercase tracking-widest text-gray-500 mb-2"><?= htmlspecialchars(t('obi.cite_short_label')) ?></div>
                <div class="relative bg-gray-900 text-gray-100 rounded-xl p-5 font-mono text-sm leading-relaxed overflow-x-auto">
                    <code id="cite-short">Cardify Oman Business Index 2026, accessed <?= obiEscq(date('F j, Y')) ?>. https://cardify.om/oman-business-index</code>
                    <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('cite-short').textContent)" class="absolute top-3 right-3 px-2.5 py-1 rounded bg-white/10 hover:bg-white/20 text-xs font-semibold text-white transition">
                        <?= htmlspecialchars(t('obi.cite_copy')) ?>
                    </button>
                </div>
            </div>
            <div>
                <div class="text-xs font-semibold uppercase tracking-widest text-gray-500 mb-2"><?= htmlspecialchars(t('obi.cite_apa_label')) ?></div>
                <div class="relative bg-gray-900 text-gray-100 rounded-xl p-5 font-mono text-sm leading-relaxed overflow-x-auto">
                    <code id="cite-apa">Cardify. (2026). <em>Oman Business Index 2026: The <?= obiEscq($totalFmt) ?> largest enterprises in the Sultanate</em>. Retrieved <?= obiEscq(date('F j, Y')) ?>, from https://cardify.om/oman-business-index</code>
                    <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('cite-apa').textContent)" class="absolute top-3 right-3 px-2.5 py-1 rounded bg-white/10 hover:bg-white/20 text-xs font-semibold text-white transition">
                        <?= htmlspecialchars(t('obi.cite_copy')) ?>
                    </button>
                </div>
            </div>
            <div class="text-sm text-gray-500">
                <?= htmlspecialchars(t('obi.cite_contact')) ?>
                <a href="mailto:info@cardify.om" class="font-semibold text-blue-700 hover:text-blue-800">info@cardify.om</a>.
            </div>
        </div>
    </section>

    <div class="border-t border-gray-100 max-w-6xl mx-auto"></div>

    <!-- ============================================================
         ABOUT CARDIFY
         ============================================================ -->
    <section id="about-cardify" class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-20 scroll-mt-24">
        <div class="flex items-center gap-3 mb-6">
            <div class="w-10 h-10 rounded-lg bg-blue-50 text-blue-700 flex items-center justify-center">
                <i class="fa-solid fa-id-card"></i>
            </div>
            <span class="text-xs font-semibold uppercase tracking-widest text-blue-700"><?= htmlspecialchars(t('obi.section')) ?> 09</span>
        </div>
        <h2 class="text-3xl sm:text-4xl font-bold text-gray-900 tracking-tight"><?= htmlspecialchars(t('obi.about_cardify')) ?></h2>
        <p class="mt-6 text-lg text-gray-700 leading-relaxed">
            <?= t('obi.about_cardify_body') ?>
        </p>
        <div class="mt-8 flex flex-wrap gap-3">
            <a href="/get-started" class="inline-flex items-center gap-2 px-5 py-3 rounded-lg bg-blue-600 text-white font-semibold shadow-sm hover:bg-blue-700 transition">
                <i class="fa-solid fa-arrow-right-to-bracket"></i>
                <?= htmlspecialchars(t('obi.about_cta_create')) ?>
            </a>
            <a href="/about" class="inline-flex items-center gap-2 px-5 py-3 rounded-lg bg-white text-gray-800 font-semibold border border-gray-200 hover:border-blue-300 hover:text-blue-700 transition">
                <?= htmlspecialchars(t('obi.about_cta_more')) ?>
            </a>
        </div>
    </section>

    <!-- ============================================================
         FAQ
         ============================================================ -->
    <section id="faq" class="bg-gray-50 border-t border-gray-100 scroll-mt-24">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-20">
            <div class="flex items-center gap-3 mb-6">
                <div class="w-10 h-10 rounded-lg bg-blue-50 text-blue-700 flex items-center justify-center">
                    <i class="fa-solid fa-circle-question"></i>
                </div>
                <span class="text-xs font-semibold uppercase tracking-widest text-blue-700"><?= htmlspecialchars(t('obi.section')) ?> 10</span>
            </div>
            <h2 class="text-3xl sm:text-4xl font-bold text-gray-900 tracking-tight"><?= htmlspecialchars(t('obi.faq_heading')) ?></h2>

            <div class="mt-10 space-y-3">
                <?php foreach ($faq as $i => $item): ?>
                    <details class="group bg-white rounded-xl border border-gray-200 open:shadow-sm">
                        <summary class="flex items-center justify-between gap-4 p-5 cursor-pointer list-none">
                            <h3 class="font-semibold text-gray-900 pr-4"><?= obiEscq($item['q']) ?></h3>
                            <span class="flex-shrink-0 w-6 h-6 rounded-full bg-gray-100 text-gray-600 flex items-center justify-center group-open:bg-blue-100 group-open:text-blue-700 transition">
                                <i class="fa-solid fa-plus text-xs group-open:hidden"></i>
                                <i class="fa-solid fa-minus text-xs hidden group-open:inline"></i>
                            </span>
                        </summary>
                        <div class="px-5 pb-5 text-gray-700 leading-relaxed">
                            <?= obiEscq($item['a']) ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>

            <p class="mt-10 text-sm text-gray-500 text-center">
                <?= htmlspecialchars(t('obi.faq_footer')) ?>
                <a href="mailto:info@cardify.om" class="font-semibold text-blue-700 hover:text-blue-800">info@cardify.om</a>.
            </p>
        </div>
    </section>

    <!-- ============================================================
         UPSTREAM, GCC Business Index (part of a larger federation)
         ============================================================ -->
    <section class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div class="rounded-2xl p-6 md:p-8 bg-gradient-to-br from-blue-600 to-indigo-700 text-white flex flex-col md:flex-row md:items-center md:justify-between gap-5">
            <div class="max-w-xl">
                <p class="text-xs uppercase tracking-wider text-blue-200 font-semibold mb-2"><?= htmlspecialchars(t('obi.gcc_eyebrow')) ?></p>
                <h2 class="text-2xl sm:text-3xl font-extrabold mb-2"><?= htmlspecialchars(t('obi.gcc_heading')) ?></h2>
                <p class="text-blue-100">
                    <?= htmlspecialchars(t('obi.gcc_body')) ?>
                </p>
            </div>
            <a href="/gcc-business-index" class="inline-flex items-center gap-2 px-6 py-3 rounded-lg bg-white text-blue-700 font-semibold hover:bg-blue-50 transition self-start md:self-center whitespace-nowrap">
                <?= htmlspecialchars(t('obi.gcc_cta')) ?>
                <i class="fa-solid fa-arrow-right text-xs"></i>
            </a>
        </div>
    </section>

    <!-- ============================================================
         COMPANION ARCHIVE, OMANI LOGO LIBRARY (visual gallery)
         ============================================================ -->
    <section id="logo-library" class="bg-gray-50 border-y border-gray-100 scroll-mt-24">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-20">
            <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4 mb-10">
                <div class="max-w-2xl">
                    <p class="text-xs uppercase tracking-wider text-blue-700 font-semibold mb-3"><?= htmlspecialchars(t('obi.lib_eyebrow')) ?></p>
                    <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-900"><?= htmlspecialchars(t('obi.lib_heading')) ?></h2>
                    <p class="mt-3 text-lg text-gray-600">
                        <?= htmlspecialchars(t('obi.lib_body', ['count' => number_format($logoTotal)])) ?>
                    </p>
                </div>
                <a href="/logos"
                   class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-semibold shadow-lg shadow-blue-600/20 whitespace-nowrap self-start md:self-end">
                    <?= htmlspecialchars(t('obi.lib_cta')) ?>
                    <i class="fa-solid fa-arrow-right text-xs"></i>
                </a>
            </div>

            <?php if ($logoSample): ?>
                <div class="grid grid-cols-3 sm:grid-cols-5 md:grid-cols-6 lg:grid-cols-10 gap-2.5">
                    <?php foreach ($logoSample as $l):
                        $src = $l['logo_webp_path']
                            ?: $l['logo_png_512_path']
                            ?: $l['logo_png_path']
                            ?: $l['logo_svg_path'];
                        if (!$src) continue;
                    ?>
                        <a href="/companies/<?= obiEscq($l['slug']) ?>"
                           class="group bg-white rounded-xl border border-gray-200 hover:border-blue-300 hover:shadow-md transition p-3 aspect-square flex items-center justify-center"
                           title="<?= obiEscq($l['name_en']) ?> logo">
                            <img src="<?= obiEscq($src) ?>"
                                 alt="<?= obiEscq($l['name_en']) ?> logo"
                                 loading="lazy"
                                 class="max-h-full max-w-full object-contain group-hover:scale-105 transition-transform">
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Sector shortcuts -->
            <div class="mt-8">
                <p class="text-sm font-semibold text-gray-600 mb-3"><?= htmlspecialchars(t('obi.lib_browse')) ?></p>
                <div class="flex flex-wrap gap-2">
                    <?php
                        $sectorLinks = [
                            ['slug' => 'government-defense',    'key' => 'obi.sc_government'],
                            ['slug' => 'finance',               'key' => 'obi.sc_finance'],
                            ['slug' => 'logistics-shipping',    'key' => 'obi.sc_logistics'],
                            ['slug' => 'oil-gas',               'key' => 'obi.sc_oil_gas'],
                            ['slug' => 'healthcare',            'key' => 'obi.sc_healthcare'],
                            ['slug' => 'education',             'key' => 'obi.sc_education'],
                            ['slug' => 'telecom',               'key' => 'obi.sc_telecom'],
                            ['slug' => 'food-beverage',         'key' => 'obi.sc_food_bev'],
                            ['slug' => 'hospitality-tourism',   'key' => 'obi.sc_hospitality'],
                            ['slug' => 'retail',                'key' => 'obi.sc_retail'],
                            ['slug' => 'manufacturing',         'key' => 'obi.sc_manufacturing'],
                            ['slug' => 'real-estate',           'key' => 'obi.sc_real_estate'],
                        ];
                        foreach ($sectorLinks as $s): ?>
                        <a href="/logos/<?= obiEscq($s['slug']) ?>"
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border border-gray-200 rounded-full text-sm text-gray-700 hover:border-blue-300 hover:text-blue-600 transition">
                            <?= obiEscq(t($s['key'])) ?>
                            <i class="fa-solid fa-arrow-up-right-from-square text-[9px] text-gray-400"></i>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Micro-FAQ for search engines + users -->
            <div class="mt-10 grid grid-cols-1 md:grid-cols-3 gap-5">
                <div class="bg-white rounded-xl border border-gray-200 p-5">
                    <div class="w-9 h-9 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center mb-3">
                        <i class="fa-solid fa-circle-info"></i>
                    </div>
                    <h3 class="font-bold text-gray-900 mb-1"><?= htmlspecialchars(t('obi.lib_why_title')) ?></h3>
                    <p class="text-sm text-gray-600 leading-relaxed"><?= htmlspecialchars(t('obi.lib_why_body')) ?></p>
                </div>
                <div class="bg-white rounded-xl border border-gray-200 p-5">
                    <div class="w-9 h-9 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center mb-3">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                    <h3 class="font-bold text-gray-900 mb-1"><?= htmlspecialchars(t('obi.lib_free_title')) ?></h3>
                    <p class="text-sm text-gray-600 leading-relaxed"><?= htmlspecialchars(t('obi.lib_free_body')) ?></p>
                </div>
                <div class="bg-white rounded-xl border border-gray-200 p-5">
                    <div class="w-9 h-9 rounded-lg bg-rose-50 text-rose-600 flex items-center justify-center mb-3">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>
                    <h3 class="font-bold text-gray-900 mb-1"><?= htmlspecialchars(t('obi.lib_td_title')) ?></h3>
                    <p class="text-sm text-gray-600 leading-relaxed"><?= htmlspecialchars(t('obi.lib_td_body_prefix')) ?> <a href="/logo-takedown" class="text-blue-600 hover:underline font-medium"><?= htmlspecialchars(t('obi.lib_td_request')) ?></a><?= htmlspecialchars(t('obi.lib_td_body_suffix')) ?></p>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================
         FOOTER META
         ============================================================ -->
    <section class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-10 text-center text-sm text-gray-500">
        <p>
            <span class="inline-flex items-center gap-1.5">
                <i class="fa-regular fa-calendar"></i>
                <?= htmlspecialchars(t('obi.last_verified')) ?> <time datetime="<?= obiEscq($lastUpdatedIso) ?>"><?= obiEscq($lastUpdatedHuman) ?></time>
            </span>
            <span class="mx-3 text-gray-300">&middot;</span>
            <span><?= htmlspecialchars(t('obi.source_label')) ?></span>
            <span class="mx-3 text-gray-300">&middot;</span>
            <a href="/contact" class="hover:text-gray-700 underline underline-offset-2"><?= htmlspecialchars(t('obi.request_edit')) ?></a>
        </p>
    </section>
</main>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
