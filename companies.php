<?php
/**
 * Cardify Oman Business Index
 *
 * Free public directory of 2,414 large/medium Omani enterprises
 * sourced from the MoCIIP public register. Utility-first content;
 * footer CTA nudges employees to create a Cardify digital card.
 *
 * Routes (via .htaccess):
 *   /companies                            → this file, view=index
 *   /companies/{slug}                     → view=company
 *   /companies/sector/{sector}            → view=sector
 *   /companies/wilayat/{wilayat}          → view=wilayat
 *   /ar/companies[...]                    → same with lang=ar
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';

$db = Database::getInstance();
$view = $_GET['view'] ?? 'index';
$slug = $_GET['slug'] ?? null;
$lang = ($_GET['lang'] ?? '') === 'ar' ? 'ar' : 'en';
$isAr = $lang === 'ar';

// Sector + wilayat content libraries (populated by content agents)
$SECTOR_CONTENT  = is_file(__DIR__ . '/data/company_content/sectors.php')
    ? (require __DIR__ . '/data/company_content/sectors.php') : [];
$WILAYAT_CONTENT = is_file(__DIR__ . '/data/company_content/wilayats.php')
    ? (require __DIR__ . '/data/company_content/wilayats.php') : [];

$baseUrl = 'https://cardify.om';
$basePrefix = $isAr ? '/ar/companies' : '/companies';
$altPrefix  = $isAr ? '/companies'    : '/ar/companies';

// --- Sector + wilayat canonical labels (matches enrich_companies.py) ---
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

function t($en, $ar, $isAr) { return $isAr ? $ar : $en; }
function labelOf($key, $dict, $isAr) {
    return $dict[$key][$isAr ? 'ar' : 'en'] ?? ucwords(str_replace('-', ' ', $key));
}

// --- Route dispatch ---
$company = null;
$hubSector = null;
$hubWilayat = null;
$companies = [];
$totalCount = 0;

if ($view === 'company' && $slug) {
    $company = $db->fetchOne("SELECT * FROM om_companies WHERE slug = ?", [$slug]);
    if (!$company) {
        http_response_code(404);
        header('Cache-Control: no-store');
        include __DIR__ . '/404.php';
        exit;
    }
    // Conditional crawl support
    $lastMod = strtotime($company['updated_at'] ?? 'now');
    $lastModHttp = gmdate('D, d M Y H:i:s \G\M\T', $lastMod);
    header('Last-Modified: ' . $lastModHttp);
    $ifModSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
    if ($ifModSince && strtotime($ifModSince) >= $lastMod) {
        header('HTTP/1.1 304 Not Modified');
        exit;
    }
} elseif ($view === 'sector' && $slug) {
    if (!isset($SECTORS[$slug])) {
        http_response_code(404);
        include __DIR__ . '/404.php';
        exit;
    }
    $hubSector = $slug;
    $companies = $db->fetchAll(
        "SELECT slug, name_en, name_ar, wilayat, size_bucket FROM om_companies WHERE sector = ? ORDER BY size_bucket ASC, name_en ASC LIMIT 500",
        [$slug]
    );
    $totalCount = (int) $db->fetchOne("SELECT COUNT(*) AS c FROM om_companies WHERE sector = ?", [$slug])['c'];
} elseif ($view === 'wilayat' && $slug) {
    if (!isset($WILAYATS[$slug])) {
        http_response_code(404);
        include __DIR__ . '/404.php';
        exit;
    }
    $hubWilayat = $slug;
    $companies = $db->fetchAll(
        "SELECT slug, name_en, name_ar, sector, size_bucket FROM om_companies WHERE wilayat = ? ORDER BY size_bucket ASC, name_en ASC LIMIT 500",
        [$slug]
    );
    $totalCount = (int) $db->fetchOne("SELECT COUNT(*) AS c FROM om_companies WHERE wilayat = ?", [$slug])['c'];
} else {
    // Index view — search + paginated listing
    $view = 'index';
    $q = trim((string) ($_GET['q'] ?? ''));
    $filterSector  = (string) ($_GET['sector']  ?? '');
    $filterWilayat = (string) ($_GET['wilayat'] ?? '');
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = 60;
    $offset = ($page - 1) * $perPage;

    $where = ['1=1'];
    $params = [];
    if ($q !== '' && mb_strlen($q) >= 2) {
        $where[] = '(name_en LIKE ? OR name_ar LIKE ?)';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
    }
    if ($filterSector !== '' && isset($SECTORS[$filterSector])) {
        $where[] = 'sector = ?';
        $params[] = $filterSector;
    }
    if ($filterWilayat !== '' && isset($WILAYATS[$filterWilayat])) {
        $where[] = 'wilayat = ?';
        $params[] = $filterWilayat;
    }
    $whereSql = implode(' AND ', $where);
    $totalCount = (int) $db->fetchOne("SELECT COUNT(*) AS c FROM om_companies WHERE $whereSql", $params)['c'];
    $companies = $db->fetchAll(
        "SELECT slug, name_en, name_ar, sector, wilayat, size_bucket FROM om_companies WHERE $whereSql ORDER BY size_bucket ASC, name_en ASC LIMIT $perPage OFFSET $offset",
        $params
    );
    $indexQuery = compact('q', 'filterSector', 'filterWilayat', 'page', 'perPage');
}

// --- Page metadata ---
$htmlClass = 'scroll-smooth' . ($isAr ? ' ' : '');
$bodyClass = 'bg-white' . ($isAr ? ' font-arabic' : '');
$bodyAttributes = $isAr ? 'dir="rtl" lang="ar"' : '';
$brandName = 'Cardify';
$showNavigation = true;

if ($company) {
    $displayName = $isAr ? ($company['name_ar'] ?: $company['name_en']) : $company['name_en'];
    $secLabel = labelOf($company['sector'], $SECTORS, $isAr);
    $wilLabel = labelOf($company['wilayat'], $WILAYATS, $isAr);
    $pageTitle = $isAr
        ? "$displayName — دليل الشركات العمانية | Cardify"
        : "$displayName — Oman Business Index | Cardify";

    // Per-company unique meta description (Google dedupes near-identical descriptions).
    // Priority: curated summary (first ~160 chars) → sector "what_they_do" personalised → factual fallback.
    $curatedSummaryEn = (string) ($company['summary_en'] ?? '');
    $curatedSummaryAr = (string) ($company['summary_ar'] ?? '');
    $secBlockMeta = $SECTOR_CONTENT[$company['sector']] ?? null;
    if ($isAr) {
        if ($curatedSummaryAr !== '') {
            $pageDescription = mb_substr(preg_replace('/\s+/', ' ', strip_tags($curatedSummaryAr)), 0, 155);
        } else {
            $pageDescription = sprintf('%s — %s في %s، عُمان. كيف يستخدم فريقها Cardify لبطاقات العمل الرقمية والمطبوعة.',
                $displayName, $secLabel, $wilLabel);
        }
    } else {
        if ($curatedSummaryEn !== '') {
            $first = mb_substr(preg_replace('/\s+/', ' ', strip_tags($curatedSummaryEn)), 0, 155);
            $pageDescription = $first;
        } elseif ($secBlockMeta && !empty($secBlockMeta['what_they_do'])) {
            // Sector-specific composed description (first sentence of what_they_do, prefixed with company)
            $wtd = $secBlockMeta['what_they_do'];
            $firstSent = preg_split('/(?<=[.!?])\s+/', $wtd, 2)[0] ?? $wtd;
            $pageDescription = mb_substr("{$displayName} ({$secLabel}, {$wilLabel}). {$firstSent}", 0, 155);
        } else {
            $pageDescription = sprintf('%s — %s enterprise in %s governorate, Oman. Profile from the Cardify business index.',
                $displayName, $secLabel, $wilLabel);
        }
    }
    $canonicalUrl = $baseUrl . $basePrefix . '/' . $company['slug'];
    $ogType = 'profile';
    // Per-company composed OG image (sector background + name overlay)
    $ogImage = $baseUrl . '/og/company/' . $company['slug'] . '.jpg';

    // --- Logo-focused SEO overrides ---
    // When a company has an indexed/verified logo, bend title + description to
    // target high-intent long-tail queries ("{Company} logo", "{Company} logo
    // download", "{Company} logo svg"). This is what pulls Google Image /
    // organic traffic to the library — without this the pages compete with
    // the OBI profile for generic keywords.
    $hasPublicLogo = !empty($company['logo_status'])
        && in_array($company['logo_status'], ['indexed', 'verified'], true)
        && (!empty($company['logo_svg_path']) || !empty($company['logo_png_path']) || !empty($company['logo_webp_path']));
    if ($hasPublicLogo) {
        $availableFormats = [];
        if (!empty($company['logo_svg_path']))      $availableFormats[] = 'SVG';
        if (!empty($company['logo_png_path']))      $availableFormats[] = 'PNG 1024';
        if (!empty($company['logo_png_2048_path'])) $availableFormats[] = 'PNG 2048';
        if (!empty($company['logo_webp_path']))     $availableFormats[] = 'WebP';
        $formatsStr = implode(' + ', $availableFormats);
        $verifiedTag = $company['logo_status'] === 'verified'
            ? ($isAr ? ' (موثَّق)' : ' (verified)')
            : '';
        $pageTitle = $isAr
            ? "شعار $displayName — تنزيل {$formatsStr}{$verifiedTag} | مكتبة الشعارات العمانية"
            : "$displayName Logo — Download {$formatsStr}{$verifiedTag} | Omani Logo Library";
        $pageDescription = $isAr
            ? mb_substr("حمّل شعار $displayName بصيغة {$formatsStr} من مكتبة الشعارات العمانية على Cardify. مفهرس للتعريف والبحث فقط. " . ($wilLabel ? "{$secLabel}، {$wilLabel}." : ''), 0, 155)
            : mb_substr("Download the $displayName logo in {$formatsStr} from the Omani Logo Library by Cardify. Indexed for identification and research. " . ($secLabel ? "{$secLabel} sector, {$wilLabel} governorate, Oman." : ''), 0, 155);
    }

    $orgLd = [
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        'name' => $company['name_en'],
        'alternateName' => $company['name_ar'],
        'url' => $company['website'] ?: $canonicalUrl,
        'address' => [
            '@type' => 'PostalAddress',
            'addressCountry' => 'OM',
            'addressRegion' => labelOf($company['wilayat'], $WILAYATS, false),
        ],
    ];
    if (!empty($company['logo_url'])) $orgLd['logo'] = $company['logo_url'];

    // Prefer Logo Library sources over deprecated logo_url
    $libraryLogo = $company['logo_svg_path'] ?? null
                ?: $company['logo_png_path'] ?? null
                ?: $company['logo_webp_path'] ?? null;
    if ($libraryLogo) {
        $orgLd['logo'] = $baseUrl . $libraryLogo;
    }

    // ImageObject for the logo itself (nominative/reference-use disclosure)
    $imageLd = null;
    if ($libraryLogo) {
        $imageLd = [
            '@context'            => 'https://schema.org',
            '@type'               => 'ImageObject',
            'contentUrl'          => $baseUrl . $libraryLogo,
            'caption'             => $company['name_en'] . ' logo',
            'creator'             => ['@type' => 'Organization', 'name' => $company['name_en']],
            'copyrightHolder'     => ['@type' => 'Organization', 'name' => $company['name_en']],
            'acquireLicensePage'  => $baseUrl . '/logos/terms',
        ];
    }

    $crumbLd = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => t('Home', 'الرئيسية', $isAr), 'item' => $baseUrl . ($isAr ? '/ar' : '/')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => t('Oman Business Index', 'دليل الشركات العمانية', $isAr), 'item' => $baseUrl . $basePrefix],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $secLabel, 'item' => $baseUrl . $basePrefix . '/sector/' . $company['sector']],
            ['@type' => 'ListItem', 'position' => 4, 'name' => $displayName, 'item' => $canonicalUrl],
        ],
    ];
    $extraHead = '<script type="application/ld+json">' . json_encode($orgLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>'
               . '<script type="application/ld+json">' . json_encode($crumbLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>'
               . ($imageLd ? '<script type="application/ld+json">' . json_encode($imageLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' : '')
               . '<link rel="alternate" hreflang="en" href="' . $baseUrl . '/companies/' . $company['slug'] . '">'
               . '<link rel="alternate" hreflang="ar" href="' . $baseUrl . '/ar/companies/' . $company['slug'] . '">'
               . '<link rel="alternate" hreflang="x-default" href="' . $baseUrl . '/companies/' . $company['slug'] . '">';
} elseif ($hubSector) {
    $secLabel = labelOf($hubSector, $SECTORS, $isAr);
    $pageTitle = $isAr
        ? "{$secLabel} في عُمان — دليل الشركات | Cardify"
        : "{$secLabel} Companies in Oman — Business Index | Cardify";
    $pageDescription = $isAr
        ? "قائمة بأكبر {$totalCount} شركة في قطاع {$secLabel} بسلطنة عُمان. جزء من دليل Cardify."
        : "Directory of {$totalCount} leading {$secLabel} enterprises in the Sultanate of Oman, part of the Cardify Oman Business Index.";
    $canonicalUrl = $baseUrl . $basePrefix . '/sector/' . $hubSector;
    $extraHead = '<link rel="alternate" hreflang="en" href="' . $baseUrl . '/companies/sector/' . $hubSector . '">'
               . '<link rel="alternate" hreflang="ar" href="' . $baseUrl . '/ar/companies/sector/' . $hubSector . '">';
} elseif ($hubWilayat) {
    $wilLabel = labelOf($hubWilayat, $WILAYATS, $isAr);
    $pageTitle = $isAr
        ? "شركات في {$wilLabel} — دليل الشركات العمانية | Cardify"
        : "Companies in {$wilLabel} — Oman Business Index | Cardify";
    $pageDescription = $isAr
        ? "قائمة بأكبر {$totalCount} شركة في محافظة {$wilLabel} بسلطنة عُمان."
        : "Directory of {$totalCount} leading enterprises in {$wilLabel}, part of the Cardify Oman Business Index.";
    $canonicalUrl = $baseUrl . $basePrefix . '/wilayat/' . $hubWilayat;
    $extraHead = '<link rel="alternate" hreflang="en" href="' . $baseUrl . '/companies/wilayat/' . $hubWilayat . '">'
               . '<link rel="alternate" hreflang="ar" href="' . $baseUrl . '/ar/companies/wilayat/' . $hubWilayat . '">';
} else {
    $pageTitle = $isAr
        ? 'دليل الشركات العمانية — أكبر 2,414 شركة | Cardify'
        : 'Oman Business Index — 2,414 Largest Enterprises | Cardify';
    $pageDescription = $isAr
        ? 'دليل مجاني لأكبر 2,414 شركة كبيرة ومتوسطة في سلطنة عُمان، مصنفة حسب القطاع والمحافظة. بيانات من السجل التجاري العام.'
        : 'Free public directory of the 2,414 largest and medium-sized enterprises in the Sultanate of Oman. Filter by sector, wilayat, and size — sourced from the MoCIIP public register.';
    $canonicalUrl = $baseUrl . $basePrefix;
    $siteLd = [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => 'Cardify Oman Business Index',
        'url' => $baseUrl . '/companies',
    ];
    $extraHead = '<script type="application/ld+json">' . json_encode($siteLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>'
               . '<link rel="alternate" hreflang="en" href="' . $baseUrl . '/companies">'
               . '<link rel="alternate" hreflang="ar" href="' . $baseUrl . '/ar/companies">';
}

require_once INCLUDES_DIR . '/ui-header.php';

function escq($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
?>

<div class="<?= $isAr ? 'font-arabic ' : '' ?>min-h-screen bg-gray-50 pt-24 pb-16">
<?php if ($company): ?>
    <!-- ============ COMPANY PROFILE ============ -->
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
        <nav aria-label="Breadcrumb" class="text-sm text-gray-500 mb-6 flex items-center gap-2 flex-wrap">
            <a href="<?= $basePrefix ?>" class="hover:text-blue-700"><?= t('Oman Business Index', 'دليل الشركات العمانية', $isAr) ?></a>
            <span>/</span>
            <a href="<?= $basePrefix ?>/sector/<?= escq($company['sector']) ?>" class="hover:text-blue-700"><?= escq(labelOf($company['sector'], $SECTORS, $isAr)) ?></a>
            <span>/</span>
            <span class="text-gray-700"><?= escq($isAr ? ($company['name_ar'] ?: $company['name_en']) : $company['name_en']) ?></span>
        </nav>

        <article class="bg-white rounded-2xl shadow-sm overflow-hidden">
            <!-- Hero banner (sector-themed background + company name burned in) -->
            <div class="relative aspect-[1200/630] bg-gray-900">
                <img src="/og/company/<?= escq($company['slug']) ?>.jpg"
                     alt="<?= escq($company['name_en']) ?> — <?= escq(labelOf($company['sector'], $SECTORS, false)) ?> in <?= escq(labelOf($company['wilayat'], $WILAYATS, false)) ?>, Oman"
                     class="absolute inset-0 w-full h-full object-cover"
                     loading="eager" fetchpriority="high" width="1200" height="630">
            </div>
            <div class="p-8 lg:p-10">
            <div class="flex items-start gap-5 flex-wrap">
                <?php
                    /* Legacy logo_url header avatar. Suppress when the Logo Library
                       has flagged the logo as taken down so the takedown actually hides
                       the mark. Library logos (logo_svg_path etc.) also take precedence. */
                    $headerLogoUrl = null;
                    if (($company['logo_status'] ?? 'none') !== 'takedown') {
                        $headerLogoUrl = $company['logo_svg_path']
                                      ?? null;
                        $headerLogoUrl = $headerLogoUrl
                                      ?: ($company['logo_png_path'] ?? null)
                                      ?: ($company['logo_webp_path'] ?? null)
                                      ?: ($company['logo_url'] ?? null);
                    }
                ?>
                <?php if ($headerLogoUrl): ?>
                    <img src="<?= escq($headerLogoUrl) ?>" alt="<?= escq($company['name_en']) ?> logo" class="w-20 h-20 rounded-xl object-contain bg-gray-50 border border-gray-200 flex-shrink-0 -mt-16 shadow-lg ring-4 ring-white">
                <?php else: ?>
                    <div class="w-20 h-20 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 text-white flex items-center justify-center text-3xl font-bold flex-shrink-0 -mt-16 shadow-lg ring-4 ring-white">
                        <?= escq(mb_substr($company['name_en'], 0, 1)) ?>
                    </div>
                <?php endif; ?>
                <div class="flex-1 min-w-0">
                    <h1 class="text-3xl sm:text-4xl font-bold text-gray-900 leading-tight"><?= escq($isAr ? ($company['name_ar'] ?: $company['name_en']) : $company['name_en']) ?></h1>
                    <?php if (!$isAr && $company['name_ar']): ?>
                        <p class="mt-2 text-lg text-gray-500 font-arabic" dir="rtl"><?= escq($company['name_ar']) ?></p>
                    <?php elseif ($isAr && $company['name_en']): ?>
                        <p class="mt-2 text-lg text-gray-500" dir="ltr"><?= escq($company['name_en']) ?></p>
                    <?php endif; ?>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <a href="<?= $basePrefix ?>/sector/<?= escq($company['sector']) ?>" class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-blue-50 text-blue-700 text-sm font-medium hover:bg-blue-100">
                            <i class="fa-solid fa-industry text-xs"></i>
                            <?= escq(labelOf($company['sector'], $SECTORS, $isAr)) ?>
                        </a>
                        <a href="<?= $basePrefix ?>/wilayat/<?= escq($company['wilayat']) ?>" class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-emerald-50 text-emerald-700 text-sm font-medium hover:bg-emerald-100">
                            <i class="fa-solid fa-location-dot text-xs"></i>
                            <?= escq(labelOf($company['wilayat'], $WILAYATS, $isAr)) ?>
                        </a>
                        <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-gray-100 text-gray-700 text-sm font-medium">
                            <i class="fa-solid fa-building text-xs"></i>
                            <?= $company['size_bucket'] === 'large' ? t('Large enterprise', 'شركة كبيرة', $isAr) : t('Medium enterprise', 'شركة متوسطة', $isAr) ?>
                        </span>
                    </div>
                </div>
            </div>

            <?php
                /* Logo Library hero — status badge, download / claim CTA, takedown link */
                if (!empty($company['logo_status']) && $company['logo_status'] !== 'takedown') {
                    include __DIR__ . '/views/partials/company_logo_hero.php';
                }
            ?>

            <?php
                $curatedSummary = $isAr ? ($company['summary_ar'] ?: '') : ($company['summary_en'] ?: '');
                $secKey   = $company['sector'];
                $wilKey   = $company['wilayat'];
                $secBlock = $SECTOR_CONTENT[$secKey] ?? null;
                $wilBlock = $WILAYAT_CONTENT[$wilKey] ?? null;
                $secLabelEn = labelOf($secKey, $SECTORS, false);
                $wilLabelEn = labelOf($wilKey, $WILAYATS, false);
                $sizeTextEn = $company['size_bucket'] === 'large' ? 'large' : 'medium';
                $displayName = $company['name_en'];

                // Build "About" paragraphs
                $aboutParas = [];
                if ($curatedSummary) {
                    foreach (preg_split("/\n\s*\n/", trim($curatedSummary)) as $p) {
                        $p = trim($p);
                        if ($p !== '') $aboutParas[] = $p;
                    }
                } else {
                    if ($secBlock && !empty($secBlock['what_they_do'])) {
                        $aboutParas[] = str_replace(['{company}', '{name}'], $displayName, $secBlock['what_they_do']);
                    }
                    if ($secBlock && !empty($secBlock['team_reality'])) {
                        $aboutParas[] = $secBlock['team_reality'];
                    }
                    if ($wilBlock && !empty($wilBlock['snapshot'])) {
                        $aboutParas[] = sprintf(
                            '%s is based in %s. %s',
                            $displayName,
                            $wilLabelEn,
                            $wilBlock['snapshot']
                        );
                    }
                    $aboutParas[] = sprintf(
                        '%s is listed as a %s enterprise on the MoCIIP public register of the Sultanate of Oman, classified under the %s sector in %s governorate.',
                        $displayName, $sizeTextEn, $secLabelEn, $wilLabelEn
                    );
                }
            ?>

            <!-- About section (curated summary or composed from sector/wilayat libraries) -->
            <section class="mt-8">
                <h2 class="text-xl font-bold text-gray-900 mb-3"><?= sprintf(t('About %s', 'نبذة عن %s', $isAr), escq($displayName)) ?></h2>
                <div class="space-y-4 text-gray-700 leading-relaxed">
                    <?php foreach ($aboutParas as $p): ?>
                        <p><?= escq($p) ?></p>
                    <?php endforeach; ?>
                </div>
                <?php if (!empty($company['website'])): ?>
                    <p class="mt-4">
                        <a href="<?= escq($company['website']) ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 text-blue-600 hover:text-blue-700 font-medium">
                            <i class="fa-solid fa-up-right-from-square text-sm"></i>
                            <?= t('Visit official website', 'زيارة الموقع الرسمي', $isAr) ?>
                        </a>
                    </p>
                <?php endif; ?>
            </section>

            <!-- Why Cardify fits this team (sector-specific) -->
            <section class="mt-10 pt-8 border-t border-gray-200">
                <h2 class="text-xl font-bold text-gray-900 mb-3">
                    <?= sprintf(t('How Cardify fits %s teams', 'كيف تخدم Cardify فرق %s', $isAr), escq($displayName)) ?>
                </h2>
                <?php if ($secBlock && !empty($secBlock['cardify_fit'])): ?>
                    <p class="text-gray-700 leading-relaxed mb-5"><?= escq($secBlock['cardify_fit']) ?></p>
                <?php else: ?>
                    <p class="text-gray-700 leading-relaxed mb-5">
                        <?= t(
                            'Cardify helps teams at companies like this one roll out consistent, branded digital business cards across every employee — with QR codes that save contacts straight to any phone, bilingual EN/AR fields, and bulk printed cards from local Omani print shops.',
                            'تساعد Cardify فرق الشركات مثل هذه على إطلاق بطاقات عمل رقمية موحدة ومُصممة حسب الهوية البصرية، مع رموز QR تحفظ جهات الاتصال مباشرة في الهاتف، وحقول ثنائية اللغة، وطباعة من مطابع عُمانية محلية.',
                            $isAr
                        ) ?>
                    </p>
                <?php endif; ?>
                <?php if ($secBlock && !empty($secBlock['use_cases']) && is_array($secBlock['use_cases'])): ?>
                    <ul class="space-y-2.5 mb-6">
                        <?php foreach ($secBlock['use_cases'] as $uc): ?>
                            <li class="flex items-start gap-3 text-gray-700">
                                <i class="fa-solid fa-check-circle text-blue-600 mt-1 flex-shrink-0"></i>
                                <span><?= escq($uc) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <div class="flex flex-wrap gap-3">
                    <a href="<?= $isAr ? '/ar/get-started' : '/get-started' ?>" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-blue-600 text-white font-semibold hover:bg-blue-700">
                        <?= sprintf(t('Start free for %s team', 'ابدأ مجاناً لفريق %s', $isAr), escq($displayName)) ?>
                        <i class="fa-solid fa-arrow-right text-xs"></i>
                    </a>
                    <a href="/tools/vcard-qr-generator" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-gray-100 text-gray-800 font-semibold hover:bg-gray-200">
                        <?= t('Try the free QR card tool', 'جرّب أداة رمز QR المجانية', $isAr) ?>
                    </a>
                </div>
            </section>

            <!-- Compact quick-facts panel -->
            <section class="mt-10 pt-8 border-t border-gray-200">
                <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-4"><?= t('Quick facts', 'معلومات سريعة', $isAr) ?></h2>
                <dl class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <div>
                        <dt class="text-xs text-gray-500"><?= t('Sector', 'القطاع', $isAr) ?></dt>
                        <dd class="mt-1 font-semibold text-gray-900 text-sm"><?= escq(labelOf($company['sector'], $SECTORS, $isAr)) ?></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500"><?= t('Governorate', 'المحافظة', $isAr) ?></dt>
                        <dd class="mt-1 font-semibold text-gray-900 text-sm"><?= escq(labelOf($company['wilayat'], $WILAYATS, $isAr)) ?></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500"><?= t('Size band', 'الحجم', $isAr) ?></dt>
                        <dd class="mt-1 font-semibold text-gray-900 text-sm"><?= $company['size_bucket'] === 'large' ? t('Large', 'كبيرة', $isAr) : t('Medium', 'متوسطة', $isAr) ?></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500"><?= t('Country', 'الدولة', $isAr) ?></dt>
                        <dd class="mt-1 font-semibold text-gray-900 text-sm"><?= t('Sultanate of Oman', 'سلطنة عُمان', $isAr) ?></dd>
                    </div>
                </dl>
            </section>
            </div><!-- /p-8 wrapper -->
        </article>

        <!-- Related: other companies in same sector + wilayat -->
        <?php
            $related = $db->fetchAll(
                "SELECT slug, name_en, name_ar, wilayat FROM om_companies WHERE sector = ? AND slug != ? ORDER BY RAND() LIMIT 6",
                [$company['sector'], $company['slug']]
            );
            $relatedWilayat = $db->fetchAll(
                "SELECT slug, name_en, name_ar, sector FROM om_companies WHERE wilayat = ? AND slug != ? ORDER BY RAND() LIMIT 6",
                [$company['wilayat'], $company['slug']]
            );
        ?>
        <?php if (!empty($related)): ?>
        <section class="mt-10">
            <h2 class="text-xl font-semibold text-gray-900 mb-4"><?= sprintf(t('Other %s companies in Oman', 'شركات أخرى في قطاع %s', $isAr), escq(labelOf($company['sector'], $SECTORS, $isAr))) ?></h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                <?php foreach ($related as $r): ?>
                    <a href="<?= $basePrefix ?>/<?= escq($r['slug']) ?>" class="p-4 bg-white rounded-lg border border-gray-200 hover:border-blue-300 hover:shadow-sm transition">
                        <div class="font-medium text-gray-900 truncate"><?= escq($isAr ? ($r['name_ar'] ?: $r['name_en']) : $r['name_en']) ?></div>
                        <div class="text-xs text-gray-500 mt-1"><?= escq(labelOf($r['wilayat'], $WILAYATS, $isAr)) ?></div>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if (!empty($relatedWilayat)): ?>
        <section class="mt-8">
            <h2 class="text-xl font-semibold text-gray-900 mb-4"><?= sprintf(t('Other companies in %s', 'شركات أخرى في %s', $isAr), escq(labelOf($company['wilayat'], $WILAYATS, $isAr))) ?></h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                <?php foreach ($relatedWilayat as $r): ?>
                    <a href="<?= $basePrefix ?>/<?= escq($r['slug']) ?>" class="p-4 bg-white rounded-lg border border-gray-200 hover:border-blue-300 hover:shadow-sm transition">
                        <div class="font-medium text-gray-900 truncate"><?= escq($isAr ? ($r['name_ar'] ?: $r['name_en']) : $r['name_en']) ?></div>
                        <div class="text-xs text-gray-500 mt-1"><?= escq(labelOf($r['sector'], $SECTORS, $isAr)) ?></div>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <p class="mt-10 text-xs text-gray-400 text-center">
            <?= t('Source: MoCIIP public register · Last verified: ', 'المصدر: السجل العام لوزارة التجارة · آخر تحديث: ', $isAr) ?>
            <?= date('F Y', strtotime($company['updated_at'])) ?>
            · <a href="/contact" class="underline hover:text-gray-600"><?= t('Request edit / takedown', 'طلب تعديل / إزالة', $isAr) ?></a>
        </p>
    </div>

<?php elseif ($hubSector || $hubWilayat): ?>
    <!-- ============ HUB PAGE (sector or wilayat) ============ -->
    <?php
        $hubLabel = $hubSector ? labelOf($hubSector, $SECTORS, $isAr) : labelOf($hubWilayat, $WILAYATS, $isAr);
        $hubKind  = $hubSector ? t('sector', 'قطاع', $isAr) : t('governorate', 'محافظة', $isAr);
    ?>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        <nav aria-label="Breadcrumb" class="text-sm text-gray-500 mb-4">
            <a href="<?= $basePrefix ?>" class="hover:text-blue-700"><?= t('Oman Business Index', 'دليل الشركات العمانية', $isAr) ?></a>
            <span class="mx-2">/</span>
            <span class="text-gray-700"><?= escq($hubLabel) ?></span>
        </nav>
        <h1 class="text-3xl sm:text-4xl font-bold text-gray-900 mb-3">
            <?= $hubSector ? escq(sprintf(t('%s Companies in Oman', 'شركات %s في عُمان', $isAr), $hubLabel)) : escq(sprintf(t('Companies in %s', 'الشركات في %s', $isAr), $hubLabel)) ?>
        </h1>
        <p class="text-gray-600 mb-8 max-w-3xl">
            <?= $hubSector
                ? sprintf(t('%d companies in the %s sector of the Sultanate of Oman, listed in the Cardify Business Index.', '%d شركة في قطاع %s بسلطنة عُمان ضمن دليل Cardify.', $isAr), $totalCount, $hubLabel)
                : sprintf(t('%d companies operating in %s governorate, listed in the Cardify Business Index.', '%d شركة تعمل في محافظة %s ضمن دليل Cardify.', $isAr), $totalCount, $hubLabel)
            ?>
        </p>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            <?php foreach ($companies as $c): ?>
                <a href="<?= $basePrefix ?>/<?= escq($c['slug']) ?>" class="p-4 bg-white rounded-lg border border-gray-200 hover:border-blue-300 hover:shadow-sm transition">
                    <div class="font-semibold text-gray-900 truncate"><?= escq($isAr ? ($c['name_ar'] ?: $c['name_en']) : $c['name_en']) ?></div>
                    <?php if (!$isAr && !empty($c['name_ar'])): ?>
                        <div class="text-xs text-gray-500 font-arabic truncate mt-0.5" dir="rtl"><?= escq($c['name_ar']) ?></div>
                    <?php endif; ?>
                    <div class="mt-2 flex items-center gap-2 text-xs text-gray-500">
                        <?php if ($hubSector && !empty($c['wilayat'])): ?>
                            <i class="fa-solid fa-location-dot"></i>
                            <span><?= escq(labelOf($c['wilayat'], $WILAYATS, $isAr)) ?></span>
                        <?php elseif ($hubWilayat && !empty($c['sector'])): ?>
                            <i class="fa-solid fa-industry"></i>
                            <span><?= escq(labelOf($c['sector'], $SECTORS, $isAr)) ?></span>
                        <?php endif; ?>
                        <span class="ml-auto inline-flex items-center px-2 py-0.5 rounded bg-gray-100 text-gray-600 text-[10px] uppercase tracking-wide">
                            <?= $c['size_bucket'] === 'large' ? t('Large', 'كبيرة', $isAr) : t('Medium', 'متوسطة', $isAr) ?>
                        </span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

<?php else: ?>
    <!-- ============ INDEX (searchable directory) ============ -->
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-10">
            <h1 class="text-3xl sm:text-5xl font-bold text-gray-900 mb-4">
                <?= t('Oman Business Index', 'دليل الشركات العمانية', $isAr) ?>
            </h1>
            <p class="text-lg text-gray-600 max-w-2xl mx-auto">
                <?= sprintf(t('A free public directory of the %d largest and medium-sized enterprises in the Sultanate of Oman.', 'دليل عام مجاني لأكبر %d شركة كبيرة ومتوسطة في سلطنة عُمان.', $isAr), $totalCount ?: 2414) ?>
            </p>
        </div>

        <!-- Search + filters -->
        <form method="get" action="<?= $basePrefix ?>" class="bg-white rounded-2xl shadow-sm p-6 mb-8 grid grid-cols-1 md:grid-cols-4 gap-3">
            <?php if ($isAr): ?><input type="hidden" name="lang" value="ar"><?php endif; ?>
            <div class="md:col-span-2">
                <label class="block text-xs font-semibold text-gray-600 mb-1"><?= t('Search', 'بحث', $isAr) ?></label>
                <input type="search" name="q" value="<?= escq($_GET['q'] ?? '') ?>" placeholder="<?= t('Company name (EN or AR)…', 'اسم الشركة (عربي أو إنجليزي)…', $isAr) ?>" class="w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-blue-500 focus:ring-2 focus:ring-blue-100 outline-none">
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1"><?= t('Sector', 'القطاع', $isAr) ?></label>
                <select name="sector" class="w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-blue-500 outline-none">
                    <option value=""><?= t('All sectors', 'كل القطاعات', $isAr) ?></option>
                    <?php foreach ($SECTORS as $k => $labels): ?>
                        <option value="<?= escq($k) ?>" <?= ($_GET['sector'] ?? '') === $k ? 'selected' : '' ?>><?= escq($labels[$isAr ? 'ar' : 'en']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1"><?= t('Governorate', 'المحافظة', $isAr) ?></label>
                <select name="wilayat" class="w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-blue-500 outline-none">
                    <option value=""><?= t('All governorates', 'كل المحافظات', $isAr) ?></option>
                    <?php foreach ($WILAYATS as $k => $labels): ?>
                        <option value="<?= escq($k) ?>" <?= ($_GET['wilayat'] ?? '') === $k ? 'selected' : '' ?>><?= escq($labels[$isAr ? 'ar' : 'en']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="md:col-span-4 flex justify-end">
                <button type="submit" class="px-6 py-2.5 rounded-lg bg-blue-600 text-white font-semibold hover:bg-blue-700">
                    <?= t('Filter', 'تصفية', $isAr) ?>
                </button>
            </div>
        </form>

        <p class="text-sm text-gray-500 mb-6"><?= sprintf(t('%d results', '%d نتيجة', $isAr), $totalCount) ?></p>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            <?php foreach ($companies as $c): ?>
                <a href="<?= $basePrefix ?>/<?= escq($c['slug']) ?>" class="p-4 bg-white rounded-lg border border-gray-200 hover:border-blue-300 hover:shadow-sm transition">
                    <div class="font-semibold text-gray-900 truncate"><?= escq($isAr ? ($c['name_ar'] ?: $c['name_en']) : $c['name_en']) ?></div>
                    <?php if (!$isAr && !empty($c['name_ar'])): ?>
                        <div class="text-xs text-gray-500 font-arabic truncate mt-0.5" dir="rtl"><?= escq($c['name_ar']) ?></div>
                    <?php endif; ?>
                    <div class="mt-2 flex items-center gap-2 text-xs text-gray-500">
                        <i class="fa-solid fa-industry"></i>
                        <span class="truncate"><?= escq(labelOf($c['sector'], $SECTORS, $isAr)) ?></span>
                        <span class="text-gray-300">·</span>
                        <i class="fa-solid fa-location-dot"></i>
                        <span class="truncate"><?= escq(labelOf($c['wilayat'], $WILAYATS, $isAr)) ?></span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

        <?php
            $totalPages = max(1, (int) ceil($totalCount / ($indexQuery['perPage'] ?? 60)));
            if ($totalPages > 1):
                $currentPage = $indexQuery['page'] ?? 1;
                $qs = http_build_query(array_filter([
                    'q' => $indexQuery['q'] ?? null,
                    'sector' => $indexQuery['filterSector'] ?? null,
                    'wilayat' => $indexQuery['filterWilayat'] ?? null,
                    'lang' => $isAr ? 'ar' : null,
                ]));
                $qsPrefix = $qs ? "&{$qs}" : '';
        ?>
        <nav class="mt-10 flex items-center justify-center gap-1 flex-wrap">
            <?php for ($p = max(1, $currentPage - 3); $p <= min($totalPages, $currentPage + 3); $p++): ?>
                <a href="<?= $basePrefix ?>?page=<?= $p ?><?= $qsPrefix ?>" class="px-3 py-1.5 rounded border <?= $p === $currentPage ? 'bg-blue-600 text-white border-blue-600' : 'text-gray-700 border-gray-200 hover:border-blue-300' ?>"><?= $p ?></a>
            <?php endfor; ?>
        </nav>
        <?php endif; ?>

        <!-- Browse by sector + wilayat -->
        <section class="mt-16">
            <h2 class="text-2xl font-bold text-gray-900 mb-4"><?= t('Browse by sector', 'تصفح حسب القطاع', $isAr) ?></h2>
            <div class="flex flex-wrap gap-2">
                <?php foreach ($SECTORS as $k => $labels): ?>
                    <a href="<?= $basePrefix ?>/sector/<?= escq($k) ?>" class="inline-flex items-center px-3 py-1.5 rounded-full bg-blue-50 text-blue-700 text-sm font-medium hover:bg-blue-100">
                        <?= escq($labels[$isAr ? 'ar' : 'en']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
        <section class="mt-10">
            <h2 class="text-2xl font-bold text-gray-900 mb-4"><?= t('Browse by governorate', 'تصفح حسب المحافظة', $isAr) ?></h2>
            <div class="flex flex-wrap gap-2">
                <?php foreach ($WILAYATS as $k => $labels): ?>
                    <a href="<?= $basePrefix ?>/wilayat/<?= escq($k) ?>" class="inline-flex items-center px-3 py-1.5 rounded-full bg-emerald-50 text-emerald-700 text-sm font-medium hover:bg-emerald-100">
                        <?= escq($labels[$isAr ? 'ar' : 'en']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- About the directory -->
        <section class="mt-16 bg-white rounded-2xl p-8 lg:p-10 shadow-sm">
            <h2 class="text-2xl font-bold text-gray-900 mb-4"><?= t('About this directory', 'عن هذا الدليل', $isAr) ?></h2>
            <div class="prose prose-gray max-w-none">
                <p><?= t(
                    'The Cardify Oman Business Index is a free, bilingual directory of large and medium enterprises registered in the Sultanate of Oman. Data is derived from the public register of the Ministry of Commerce, Industry and Investment Promotion (MoCIIP), supplemented with sector and governorate classifications generated by Cardify.',
                    'دليل Cardify للشركات العمانية هو دليل مجاني ثنائي اللغة للشركات الكبيرة والمتوسطة المسجلة في سلطنة عُمان. البيانات مشتقة من السجل العام لوزارة التجارة والصناعة وترويج الاستثمار، مع إضافة تصنيف قطاعي وجغرافي من Cardify.',
                    $isAr
                ) ?></p>
                <p><?= t(
                    'Cardify does not claim any commercial relationship with the listed companies. If you represent a listed company and wish to request an edit or removal, please contact us.',
                    'لا تدعي Cardify وجود أي علاقة تجارية مع الشركات المدرجة. إذا كنت تمثل إحدى هذه الشركات وترغب في التعديل أو الإزالة، يرجى التواصل معنا.',
                    $isAr
                ) ?></p>
            </div>
        </section>
    </div>
<?php endif; ?>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
