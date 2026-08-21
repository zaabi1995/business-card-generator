<?php
/**
 * Cardify Oman Business Index
 *
 * Free public directory of the large/medium Omani enterprises held in
 * om_companies. The size is counted at render time, never stated here.
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
require_once INCLUDES_DIR . '/LogoLibrary.php'; // shouldUseDarkVariantOnLight + helpers used by every view branch
require_once INCLUDES_DIR . '/ArTwins.php';

$db = Database::getInstance();
$view = $_GET['view'] ?? 'index';
$slug = $_GET['slug'] ?? null;
$lang = ($_GET['lang'] ?? '') === 'ar' ? 'ar' : 'en';
$isAr = $lang === 'ar';
// Public SEO pages are URL-driven (/companies = EN, /ar/companies = AR) and
// must NOT inherit the cardify_lang cookie; the shared chrome (ui-header /
// footer) reads currentLocale(), so force I18n to match $lang here.
if (class_exists('I18n')) { I18n::setLocale($lang); }

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
    $lastMod = dbTs($company['updated_at'] ?? 'now');
    // r250, bhd-r6-95: this row's own date is what the sitemap publishes for
    // this URL, so it is what the page must publish too. Without this the page
    // channel reports companies.php's render closure for every company alike,
    // which after r250 is the shared layout date and therefore AHEAD of the
    // row date on 57 of 60 sampled URLs. r66 settled which way that goes:
    // behind is stale and honest, ahead is the lie. Only set when the row
    // actually carries a date; 'now' above is a fallback for the header, not
    // a freshness claim.
    if (!empty($company['updated_at'])) {
        $GLOBALS['pageContentDate'] = $lastMod;
    }
    $lastModHttp = gmdate('D, d M Y H:i:s \G\M\T', $lastMod);
    header('Last-Modified: ' . $lastModHttp);
    $ifModSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
    if ($ifModSince && strtotime($ifModSince) >= $lastMod) {
        header('HTTP/1.1 304 Not Modified');
        exit;
    }

    // Related brands: same sector, prefer ones with logos. Skip when this
    // entry's sector is missing or 'other' (too noisy a bucket).
    $relatedBrands = [];
    if (!empty($company['sector']) && $company['sector'] !== 'other') {
        try {
            $relatedBrands = $db->fetchAll(
                "SELECT slug, name_en, name_ar, logo_status,
                        logo_svg_path, logo_png_path, logo_png_512_path, logo_webp_path,
                        logo_svg_dark_path, logo_png_dark_path, logo_webp_dark_path,
                        logo_dominant_color, logo_palette, logo_updated_at
                 FROM om_companies
                 WHERE sector = :s
                   AND slug != :slug
                 ORDER BY FIELD(logo_status,'verified','indexed','pending','none'),
                          size_bucket ASC, name_en ASC, slug ASC
                 LIMIT 6",
                [':s' => $company['sector'], ':slug' => $slug]
            );
        } catch (Throwable $e) {
            $relatedBrands = [];
        }
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
    // Index view, search + paginated listing
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
    // llm27-26: max(1, ...) clamped the floor and nothing clamped the ceiling,
    // so ?page=999 served an empty listing under a SELF-canonical, i.e. an
    // unbounded indexable space that a crawler has no reason to stop walking.
    // The last real page still answers 200; the first one past it is gone.
    $lastPage = max(1, (int) ceil($totalCount / $perPage));
    if ($page > $lastPage) {
        http_response_code(404);
        header('Cache-Control: no-store');
        include __DIR__ . '/404.php';
        exit;
    }
    $companies = $db->fetchAll(
        "SELECT slug, name_en, name_ar, sector, wilayat, size_bucket,
                logo_status, logo_svg_path, logo_png_path, logo_png_512_path, logo_webp_path,
                logo_svg_dark_path, logo_png_dark_path, logo_webp_dark_path,
                logo_dominant_color, logo_palette, logo_updated_at
           FROM om_companies WHERE $whereSql
          ORDER BY (logo_status = 'verified') DESC,
                   (logo_status = 'indexed') DESC,
                   size_bucket ASC, name_en ASC
          LIMIT $perPage OFFSET $offset",
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
    require_once INCLUDES_DIR . '/seo_title.php';
    // Longest form first; seo_pick_title() drops to a shorter tail rather than
    // cutting the company name, which is what anyone searches for.
    $pageTitle = seo_pick_title([
        t('companies.company_page_title',       ['name' => $displayName]),
        t('companies.company_page_title_mid',   ['name' => $displayName]),
        t('companies.company_page_title_short', ['name' => $displayName]),
    ], 'Cardify');

    // Per-company unique meta description. Curated summaries keep their full
    // authored text until seo_fit_desc() applies a clean word-boundary fit.
    // Uncurated rows use only fields present in the directory record.
    $curatedSummaryEn = (string) ($company['summary_en'] ?? '');
    $curatedSummaryAr = (string) ($company['summary_ar'] ?? '');
    if ($isAr) {
        if ($curatedSummaryAr !== '') {
            $pageDescription = preg_replace('/\s+/', ' ', strip_tags($curatedSummaryAr));
        } else {
            $pageDescription = t('companies.company_page_desc_fallback', ['name' => $displayName, 'sector' => $secLabel, 'wilayat' => $wilLabel]);
        }
    } else {
        if ($curatedSummaryEn !== '') {
            $pageDescription = preg_replace('/\s+/', ' ', strip_tags($curatedSummaryEn));
        } else {
            $pageDescription = t('companies.company_page_desc_fallback', ['name' => $displayName, 'sector' => $secLabel, 'wilayat' => $wilLabel]);
        }
    }
    // A composed description is the only place the length can be asserted: the
    // authored strings all fit, the interpolated legal name is what overran.
    $pageDescription = seo_fit_desc($pageDescription);
    $canonicalUrl = $baseUrl . $basePrefix . '/' . $company['slug'];
    // Open Graph's profile type is for people. Organization directory pages
    // use the generic website type while JSON-LD carries the precise entity.
    $ogType = 'website';
    // Per-company composed OG image (sector background + name overlay)
    $ogImage = $baseUrl . '/og/company/' . $company['slug'] . '.jpg';

    // --- Logo-focused SEO overrides ---
    // When a company has an indexed/verified logo, bend title + description to
    // target high-intent long-tail queries ("{Company} logo", "{Company} logo
    // download", "{Company} logo svg"). This is what pulls Google Image /
    // organic traffic to the library, without this the pages compete with
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
        // Keep the searched company name and the word "logo" at the front.
        // Format details belong in the description and visible download panel,
        // not in a title where they used to truncate the entity name.
        $pageTitle = seo_pick_title([
            t('companies.logo_page_title',       ['name' => $displayName]),
            t('companies.logo_page_title_mid',   ['name' => $displayName]),
            t('companies.logo_page_title_short', ['name' => $displayName]),
        ], 'Cardify');
        $pageDescription = seo_fit_desc(t('companies.logo_desc_en', ['name' => $displayName, 'formats' => $formatsStr, 'sector' => $secLabel, 'wilayat' => $wilLabel]));
    }

    $companyEntityUrl = $baseUrl . '/companies/' . $company['slug'];
    $companyEntityId = $companyEntityUrl . '#organization';
    $orgLd = [
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        '@id' => $companyEntityId,
        'name' => $company['name_en'],
        'url' => $company['website'] ?: $companyEntityUrl,
        'address' => [
            '@type' => 'PostalAddress',
            'addressCountry' => 'OM',
            'addressRegion' => labelOf($company['wilayat'], $WILAYATS, false),
        ],
    ];
    if (!empty($company['name_ar'])) $orgLd['alternateName'] = $company['name_ar'];
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
        $currentYear = date('Y');
        $imageLd = [
            '@context'            => 'https://schema.org',
            '@type'               => 'ImageObject',
            'contentUrl'          => $baseUrl . $libraryLogo,
            'url'                 => $baseUrl . $libraryLogo,
            'caption'             => $company['name_en'] . ' logo',
            'name'                => $company['name_en'] . ' logo',
            'creator'             => ['@type' => 'Organization', 'name' => $company['name_en']],
            'copyrightHolder'     => ['@type' => 'Organization', 'name' => $company['name_en']],
            'copyrightNotice'     => '© ' . $currentYear . ' ' . $company['name_en'] . '. All rights reserved. Displayed by Cardify under nominative fair use.',
            'creditText'          => $company['name_en'],
            'license'             => $baseUrl . '/logos/terms',
            'acquireLicensePage'  => $baseUrl . '/logos/terms',
        ];
    }

    $crumbLd = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => t('companies.breadcrumb_home'), 'item' => $baseUrl . ($isAr ? '/ar' : '/')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => t('companies.breadcrumb_obi'), 'item' => $baseUrl . $basePrefix],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $secLabel, 'item' => $baseUrl . $basePrefix . '/sector/' . $company['sector']],
            ['@type' => 'ListItem', 'position' => 4, 'name' => $displayName, 'item' => $canonicalUrl],
        ],
    ];
    $extraHead = '<script type="application/ld+json">' . json_encode($orgLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>'
               . '<script type="application/ld+json">' . json_encode($crumbLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>'
               . ($imageLd ? '<script type="application/ld+json">' . json_encode($imageLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' : '')
               . ArTwins::pairLinks('/companies/' . $company['slug']);
    $GLOBALS['pageSchemaType'] = 'ProfilePage';
    $GLOBALS['pageSchemaName'] = $displayName;
    $GLOBALS['pageSchemaDescription'] = $pageDescription;
    $GLOBALS['pageSchemaMainEntity'] = ['@id' => $companyEntityId];
} elseif ($hubSector) {
    $secLabel = labelOf($hubSector, $SECTORS, $isAr);
    $pageTitle = t('companies.hub_sector_page_title', ['label' => $secLabel]);
    $pageDescription = t('companies.hub_sector_page_desc', ['count' => $totalCount, 'label' => $secLabel]);
    $canonicalUrl = $baseUrl . $basePrefix . '/sector/' . $hubSector;
    $extraHead = ArTwins::pairLinks('/companies/sector/' . $hubSector);
} elseif ($hubWilayat) {
    $wilLabel = labelOf($hubWilayat, $WILAYATS, $isAr);
    $pageTitle = t('companies.hub_wilayat_page_title', ['label' => $wilLabel]);
    $pageDescription = t('companies.hub_wilayat_page_desc', ['count' => $totalCount, 'label' => $wilLabel]);
    $canonicalUrl = $baseUrl . $basePrefix . '/wilayat/' . $hubWilayat;
    $extraHead = ArTwins::pairLinks('/companies/wilayat/' . $hubWilayat);
} else {
    $pageTitle = t('companies.index_title');
    $pageDescription = t('companies.index_desc');
    // 20-7: r6-64 gave pages 2..N their own canonical but left them sharing one
    // title and one meta description, so 41 self-canonical URLs described
    // themselves identically. Name the slice each page actually carries: the
    // page number, and the alphabetical range of the companies on it.
    $lastPage = max(1, (int) ceil($totalCount / $perPage));
    if ($page > 1) {
        $firstName = $companies ? ($isAr ? ($companies[0]['name_ar'] ?: $companies[0]['name_en']) : $companies[0]['name_en']) : '';
        $lastName  = $companies ? ($isAr ? (end($companies)['name_ar'] ?: end($companies)['name_en']) : end($companies)['name_en']) : '';
        $pageTitle = t('companies.index_title_paged', ['page' => $page, 'last' => $lastPage]);
        if ($firstName !== '' && $lastName !== '') {
            $pageDescription = t('companies.index_desc_paged', [
                'page'  => $page,
                'last'  => $lastPage,
                'first' => $firstName,
                'to'    => $lastName,
            ]);
        }
    }
    // r6-64: pages 2..N used to canonicalise to page 1, which told Google the
    // deeper pages were duplicates and cut the only crawl path to the 5,004
    // company pages below them. A paginated page is its own canonical. Search
    // and filter results are NOT: they are a slice of the same set and keep
    // pointing at page 1, which is what rel=canonical is actually for.
    $isFilteredView = ($q !== '' || $filterSector !== '' || $filterWilayat !== '');
    $pageSuffix = (!$isFilteredView && $page > 1) ? '?page=' . $page : '';
    $canonicalUrl = $baseUrl . $basePrefix . $pageSuffix;
    $seqHead = '';
    if (!$isFilteredView) {
        if ($page > 1) {
            $seqHead .= '<link rel="prev" href="' . $baseUrl . $basePrefix
                     . ($page > 2 ? '?page=' . ($page - 1) : '') . '">';
        }
        if ($page < $lastPage) {
            $seqHead .= '<link rel="next" href="' . $baseUrl . $basePrefix . '?page=' . ($page + 1) . '">';
        }
    }
    $siteLd = [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => 'Cardify Oman Business Index',
        'url' => $baseUrl . '/companies',
    ];
    $extraHead = '<script type="application/ld+json">' . json_encode($siteLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>'
               . $seqHead
               // The hreflang pair carries the SAME page as the canonical,
               // otherwise page 2 advertises page 1 as its own translation.
               . ArTwins::pairLinks('/companies' . $pageSuffix);
}
$suppressDefaultHreflang = true;
require_once INCLUDES_DIR . '/ui-header.php';

function escq($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
?>

<div class="<?= $isAr ? 'font-arabic ' : '' ?>min-h-screen bg-gray-50 pt-24 pb-16">
<?php if ($company): ?>
    <!-- ============ COMPANY PROFILE ============ -->
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
        <nav aria-label="Breadcrumb" class="text-sm text-gray-500 mb-6 flex items-center gap-2 flex-wrap">
            <a href="<?= $basePrefix ?>" class="hover:text-blue-700"><?= escq(t('companies.breadcrumb_obi')) ?></a>
            <span>/</span>
            <a href="<?= $basePrefix ?>/sector/<?= escq($company['sector']) ?>" class="hover:text-blue-700"><?= escq(labelOf($company['sector'], $SECTORS, $isAr)) ?></a>
            <span>/</span>
            <span class="text-gray-700"><?= escq($isAr ? ($company['name_ar'] ?: $company['name_en']) : $company['name_en']) ?></span>
        </nav>

        <article class="bg-white rounded-2xl shadow-sm overflow-hidden">
            <!-- Hero banner (sector-themed background + company name burned in) -->
            <div class="relative aspect-[1200/630] bg-gray-900">
                <img src="/og/company/<?= escq($company['slug']) ?>.jpg"
                     alt="<?= escq($company['name_en']) ?>, <?= escq(labelOf($company['sector'], $SECTORS, false)) ?> in <?= escq(labelOf($company['wilayat'], $WILAYATS, false)) ?>, Oman"
                     class="absolute inset-0 w-full h-full object-cover"
                     loading="eager" fetchpriority="high" width="1200" height="630">
            </div>
            <div class="p-8 lg:p-10">
            <div class="flex items-start gap-5 flex-wrap">
                <?php
                    /* Legacy logo_url header avatar. Suppress when the Logo Library
                       has flagged the logo as taken down so the takedown actually hides
                       the mark. Library logos take precedence and auto-flip to the
                       dark monochrome variant when the original would be invisible on
                       the light bg-gray-50 thumb (white wordmark on white surface). */
                    $headerLogoUrl = null;
                    if (($company['logo_status'] ?? 'none') !== 'takedown') {
                        if (!class_exists('LogoLibrary')) {
                            require_once INCLUDES_DIR . '/LogoLibrary.php';
                        }
                        $_headerPalette = json_decode((string) ($company['logo_palette'] ?? ''), true) ?: null;
                        $_headerFlip = LogoLibrary::shouldUseDarkVariantOnLight($_headerPalette)
                                       && !empty($company['logo_webp_dark_path'] ?? $company['logo_png_dark_path'] ?? $company['logo_svg_dark_path']);
                        if ($_headerFlip) {
                            $headerLogoUrl = $company['logo_svg_dark_path']
                                          ?: $company['logo_webp_dark_path']
                                          ?: $company['logo_png_dark_path'];
                        } else {
                            $headerLogoUrl = ($company['logo_svg_path'] ?? null)
                                          ?: ($company['logo_png_path'] ?? null)
                                          ?: ($company['logo_webp_path'] ?? null)
                                          ?: ($company['logo_url'] ?? null);
                        }
                        if ($headerLogoUrl && !empty($company['logo_updated_at'])
                            && !str_contains($headerLogoUrl, '?')) {
                            $headerLogoUrl .= '?v=' . dbTs($company['logo_updated_at']);
                        }
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
                    <?php
                        // 20-40: a company with no name_ar used to put a purely Latin
                        // string in the H1 of an Arabic page, so the page's single
                        // strongest heading carried no Arabic at all. Keep the Latin
                        // legal name (it is the registered one) and qualify it in
                        // Arabic so the heading is readable in the page's language.
                        // The test is "does the rendered heading contain Arabic",
                        // not "is name_ar empty": ~1 in 20 om_companies rows
                        // stores the Latin legal name in name_ar, which is
                        // non-empty and still leaves the H1 without a letter of
                        // the page's own script.
                        $h1Text = $isAr ? ($company['name_ar'] ?: $company['name_en']) : $company['name_en'];
                        $h1HasArabic = (bool) preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $h1Text);
                        $h1Qualifier = ($isAr && !$h1HasArabic)
                            ? ' (' . $secLabel . ' في ' . $wilLabel . ')'
                            : '';
                    ?>
                    <h1 class="text-3xl sm:text-4xl font-bold text-gray-900 leading-tight"><?= escq($h1Text) ?><?php if ($h1Qualifier): ?><span class="text-xl sm:text-2xl font-semibold text-gray-500"><?= escq($h1Qualifier) ?></span><?php endif; ?></h1>
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
                            <?= escq($company['size_bucket'] === 'large' ? t('companies.size_large_enterprise') : t('companies.size_medium_enterprise')) ?>
                        </span>
                    </div>
                </div>
            </div>

            <?php
                /* Logo Library hero, status badge, download / claim CTA, takedown link */
                if (!empty($company['logo_status']) && $company['logo_status'] !== 'takedown') {
                    include __DIR__ . '/views/partials/company_logo_hero.php';
                }
            ?>

            <?php
                $curatedSummary = $isAr ? ($company['summary_ar'] ?: '') : ($company['summary_en'] ?: '');
                $secKey   = $company['sector'];
                $wilKey   = $company['wilayat'];
                $secLabelEn = labelOf($secKey, $SECTORS, $isAr);
                $wilLabelEn = labelOf($wilKey, $WILAYATS, $isAr);
                $sizeText = $company['size_bucket'] === 'large'
                    ? t('companies.size_large_inline')
                    : t('companies.size_medium_inline');
                $displayName = $isAr ? ($company['name_ar'] ?: $company['name_en']) : $company['name_en'];

                // Detect sovereign / ministerial / authority entities. They
                // are state-sector bodies, none of them "roll out business
                // cards for their team" via Cardify. The separate Cardify
                // product section is omitted for these records.
                $sovereignNamePatterns = [
                    '/^ministry\s+of\b/i',
                    '/\bauthority\b/i',
                    '/\bcentral\s+bank\b/i',
                    '/\bchamber\s+of\s+commerce\b/i',
                    '/\bvision\s+2040\b/i',
                    '/\bnational\s+cent(re|er)\b/i',
                    '/\bgeneral\s+secretariat\b/i',
                    '/\bmedical\s+specialty\s+board\b/i',
                    '/\basyad\s+group\b/i',
                    '/\bsohar\s+port\b/i',
                ];
                $isSovereign = false;
                foreach ($sovereignNamePatterns as $re) {
                    if (preg_match($re, (string) ($company['name_en'] ?? ''))) { $isSovereign = true; break; }
                }
                if (!$isSovereign) {
                    // Also treat the MoCIIP ministerial pack rows as sovereign.
                    $srcUrl = (string) ($company['logo_source_url'] ?? '');
                    if (strpos($srcUrl, 'MoCIIP ministerial pack') === 0) $isSovereign = true;
                }

                // Curated summaries are rendered as authored. Uncurated rows
                // use the source-backed snapshot below and do not repeat it in
                // a second About section.
                $aboutParas = [];
                if ($curatedSummary) {
                    foreach (preg_split("/\n\s*\n/", trim($curatedSummary)) as $p) {
                        $p = trim($p);
                        if ($p !== '') $aboutParas[] = $p;
                    }
                }
            ?>

            <section class="mt-8 rounded-xl border border-blue-100 bg-blue-50/70 p-5" aria-labelledby="profile-snapshot-heading">
                <h2 id="profile-snapshot-heading" class="text-lg font-bold text-gray-900">
                    <?= escq(t('companies.profile_snapshot_heading', ['name' => $displayName])) ?>
                </h2>
                <p class="mt-2 text-gray-700 leading-relaxed">
                    <?= escq(t('companies.profile_snapshot', [
                        'name' => $displayName,
                        'sector' => $secLabelEn,
                        'wilayat' => $wilLabelEn,
                        'size' => $sizeText,
                    ])) ?>
                </p>
                <p class="mt-2 text-sm text-gray-600 leading-relaxed">
                    <?= escq(t('companies.profile_independence')) ?>
                    <a href="<?= escq(ArTwins::navLink('contact', '/', $isAr)) ?>" class="inline-block mt-1 font-medium text-blue-700 underline hover:text-blue-800">
                        <?= escq(t('companies.profile_correction')) ?>
                    </a>
                </p>
                <?php if (!empty($company['website'])): ?>
                    <p class="mt-3">
                        <a href="<?= escq($company['website']) ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 text-blue-700 hover:text-blue-800 font-medium">
                            <i class="fa-solid fa-up-right-from-square text-sm"></i>
                            <?= escq(t('companies.visit_website')) ?>
                        </a>
                    </p>
                <?php endif; ?>
            </section>

            <?php if ($aboutParas): ?>
            <!-- About section: rendered only when the company has curated source text -->
            <section class="mt-8">
                <h2 class="text-xl font-bold text-gray-900 mb-3"><?= escq(t('companies.about_heading', ['name' => $displayName])) ?></h2>
                <div class="space-y-4 text-gray-700 leading-relaxed">
                    <?php foreach ($aboutParas as $p): ?>
                        <p><?= escq($p) ?></p>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>

            <?php
                /* "More logos from {sector}" strip, only for rows with a public logo. */
                include __DIR__ . '/views/partials/company_logo_related.php';
            ?>

            <?php if (!$isSovereign): /* Commercial upsell only for private-sector companies */ ?>
            <!-- Cardify product information, explicitly separate from the directory record -->
            <section class="mt-10 pt-8 border-t border-gray-200">
                <h2 class="text-xl font-bold text-gray-900 mb-3">
                    <?= escq(t('companies.business_card_tools_heading', ['sector' => $secLabelEn])) ?>
                </h2>
                <p class="text-gray-700 leading-relaxed mb-5">
                    <?= escq(t('companies.cardify_tools_body')) ?>
                </p>
                <div class="flex flex-wrap gap-3">
                    <?php /* llm79-2 reopen: a hand-rolled ($isAr ? '/ar' : '') prefix CANNOT
                       return null, so on the Arabic side it manufactured /ar/get-started, a URL
                       the twin map denies and the edge answers 301. r79 repaired seven such call
                       sites from a hand-walked list of 60 string literals and reported 7 -> 0;
                       this one was outside that enumeration and is the LARGEST page family on the
                       host, 2,502 Arabic company URLs, each emitting it as its primary conversion
                       CTA. ArTwins::navLink() returns the English URL when there is no Arabic
                       twin, which is the honest link. */ ?>
                    <a href="<?= htmlspecialchars(ArTwins::navLink('get-started', '/', $isAr)) ?>" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-blue-600 text-white font-semibold hover:bg-blue-700">
                        <?= escq(t('companies.create_cardify_team')) ?>
                        <i class="fa-solid fa-arrow-right text-xs"></i>
                    </a>
                    <a href="/tools/vcard-qr-generator" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-gray-100 text-gray-800 font-semibold hover:bg-gray-200">
                        <?= escq(t('companies.try_qr_tool')) ?>
                    </a>
                </div>
            </section>
            <?php endif; /* !$isSovereign */ ?>

            <!-- Compact quick-facts panel -->
            <section class="mt-10 pt-8 border-t border-gray-200">
                <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-4"><?= escq(t('companies.quick_facts')) ?></h2>
                <dl class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <div>
                        <dt class="text-xs text-gray-500"><?= escq(t('companies.qf_sector')) ?></dt>
                        <dd class="mt-1 font-semibold text-gray-900 text-sm"><?= escq(labelOf($company['sector'], $SECTORS, $isAr)) ?></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500"><?= escq(t('companies.qf_governorate')) ?></dt>
                        <dd class="mt-1 font-semibold text-gray-900 text-sm"><?= escq(labelOf($company['wilayat'], $WILAYATS, $isAr)) ?></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500"><?= escq(t('companies.qf_size')) ?></dt>
                        <dd class="mt-1 font-semibold text-gray-900 text-sm"><?= escq($company['size_bucket'] === 'large' ? t('companies.size_large') : t('companies.size_medium')) ?></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500"><?= escq(t('companies.qf_country')) ?></dt>
                        <dd class="mt-1 font-semibold text-gray-900 text-sm"><?= escq(t('companies.qf_sultanate')) ?></dd>
                    </div>
                </dl>
            </section>
            </div><!-- /p-8 wrapper -->
        </article>

        <!-- Related: other companies in same sector + wilayat -->
        <?php
            $related = $db->fetchAll(
                "SELECT slug, name_en, name_ar, wilayat FROM om_companies WHERE sector = ? AND slug != ? ORDER BY size_bucket ASC, name_en ASC, slug ASC LIMIT 6",
                [$company['sector'], $company['slug']]
            );
            $relatedWilayat = $db->fetchAll(
                "SELECT slug, name_en, name_ar, sector FROM om_companies WHERE wilayat = ? AND slug != ? ORDER BY size_bucket ASC, name_en ASC, slug ASC LIMIT 6",
                [$company['wilayat'], $company['slug']]
            );
        ?>
        <?php if (!empty($related)): ?>
        <section class="mt-10">
            <h2 class="text-xl font-semibold text-gray-900 mb-4"><?= escq(t('companies.other_in_sector', ['label' => labelOf($company['sector'], $SECTORS, $isAr)])) ?></h2>
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
            <h2 class="text-xl font-semibold text-gray-900 mb-4"><?= escq(t('companies.other_in_wilayat', ['label' => labelOf($company['wilayat'], $WILAYATS, $isAr)])) ?></h2>
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

        <?php if (!empty($relatedBrands)):
            $_secLabel = labelOf($company['sector'], $SECTORS, $isAr);
        ?>
        <section class="mt-10 bg-white rounded-2xl border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
                <div>
                    <p class="text-xs uppercase tracking-wider text-blue-700 font-semibold mb-1">
                        <i class="fa-solid fa-arrow-right-arrow-left text-[10px] <?= $isAr ? 'ml-1' : 'mr-1' ?>"></i>
                        <?= escq($isAr ? 'علامات ذات صلة' : 'Related brands') ?>
                    </p>
                    <h2 class="text-lg font-bold text-gray-900">
                        <?= escq($isAr ? "المزيد من قطاع: $_secLabel" : "More from $_secLabel") ?>
                    </h2>
                </div>
                <a href="<?= $basePrefix ?>/sector/<?= escq($company['sector']) ?>"
                   class="inline-flex items-center gap-1.5 text-sm font-medium text-blue-600 hover:text-blue-700">
                    <?= escq($isAr ? "كل شركات: $_secLabel" : "All in $_secLabel") ?>
                    <i class="fa-solid fa-arrow-<?= $isAr ? 'left' : 'right' ?> text-xs"></i>
                </a>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                <?php foreach ($relatedBrands as $rb):
                    $_rbPal  = json_decode((string) ($rb['logo_palette'] ?? ''), true) ?: null;
                    $_rbFlip = LogoLibrary::shouldUseDarkVariantOnLight($_rbPal)
                               && !empty($rb['logo_webp_dark_path'] ?? $rb['logo_png_dark_path'] ?? $rb['logo_svg_dark_path']);
                    if ($_rbFlip) {
                        $_rbSrc = $rb['logo_webp_dark_path']
                            ?: $rb['logo_png_dark_path']
                            ?: $rb['logo_svg_dark_path'];
                    } else {
                        $_rbSrc = $rb['logo_webp_path'] ?: $rb['logo_png_512_path']
                            ?: $rb['logo_png_path'] ?: $rb['logo_svg_path'];
                    }
                    if ($_rbSrc && !empty($rb['logo_updated_at'])) {
                        $_rbSrc .= '?v=' . dbTs($rb['logo_updated_at']);
                    }
                    $_rbBg = $rb['logo_dominant_color'] ?: '#f9fafb';
                ?>
                    <a href="<?= $basePrefix ?>/<?= escq($rb['slug']) ?>"
                       class="cardify-logo-card group bg-white border border-gray-200 rounded-xl overflow-hidden transition-all duration-200 hover:border-gray-300 hover:-translate-y-0.5 hover:shadow-[0_8px_24px_-8px_rgba(15,23,42,0.18)]"
                       style="--brand-bg: <?= escq($_rbBg) ?>"
                       title="<?= escq($rb['name_en']) ?>">
                        <div class="aspect-square flex items-center justify-center p-3 bg-gradient-to-br from-gray-50 to-white border-b border-gray-100 transition-colors duration-200 group-hover:bg-[var(--brand-bg)] group-hover:bg-none">
                            <?php if ($_rbSrc): ?>
                                <img src="<?= escq($_rbSrc) ?>" alt="<?= escq($rb['name_en']) ?> logo"
                                     loading="lazy"
                                     class="max-h-[70%] max-w-[80%] w-auto h-auto object-contain object-center transition-transform duration-200 group-hover:scale-105">
                            <?php else: ?>
                                <div class="text-gray-300 text-xl font-bold">
                                    <?= escq(mb_substr($rb['name_en'], 0, 2)) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="px-2.5 py-1.5">
                            <p class="text-[11px] font-semibold text-gray-900 truncate">
                                <?= escq($isAr ? ($rb['name_ar'] ?: $rb['name_en']) : $rb['name_en']) ?>
                            </p>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <p class="mt-10 text-xs text-gray-400 text-center">
            <?= escq(t('companies.source_footer')) ?>
            <?php $sourceUpdatedTs = dbTs($company['updated_at']); ?>
            <time datetime="<?= date('Y-m-d', $sourceUpdatedTs) ?>"><?= escq(I18n::formatDate($sourceUpdatedTs, $lang)) ?></time>
            · <a href="<?= escq(ArTwins::navLink('contact', '/', $isAr)) ?>" class="underline hover:text-gray-600"><?= escq(t('companies.request_edit_takedown')) ?></a>
        </p>
    </div>

<?php elseif ($hubSector || $hubWilayat): ?>
    <!-- ============ HUB PAGE (sector or wilayat) ============ -->
    <?php
        $hubLabel = $hubSector ? labelOf($hubSector, $SECTORS, $isAr) : labelOf($hubWilayat, $WILAYATS, $isAr);
        $hubKind  = $hubSector ? t('companies.sector_label_word') : t('companies.governorate_label_word');
    ?>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        <nav aria-label="Breadcrumb" class="text-sm text-gray-500 mb-4">
            <a href="<?= $basePrefix ?>" class="hover:text-blue-700"><?= escq(t('companies.breadcrumb_obi')) ?></a>
            <span class="mx-2">/</span>
            <span class="text-gray-700"><?= escq($hubLabel) ?></span>
        </nav>
        <h1 class="text-3xl sm:text-4xl font-bold text-gray-900 mb-3">
            <?= escq($hubSector ? t('companies.hub_sector_heading', ['label' => $hubLabel]) : t('companies.hub_wilayat_heading', ['label' => $hubLabel])) ?>
        </h1>
        <p class="text-gray-600 mb-8 max-w-3xl">
            <?= escq($hubSector
                ? t('companies.hub_sector_subheading', ['count' => $totalCount, 'label' => $hubLabel])
                : t('companies.hub_wilayat_subheading', ['count' => $totalCount, 'label' => $hubLabel]))
            ?>
        </p>

        <?php
            /* Logo strip, only on sector hubs. Shows indexed/verified logos
               scoped to this sector with a CTA to /logos/{sector}. */
            if ($hubSector) {
                $hubLogoSample = [];
                try {
                    $hubLogoSample = $db->fetchAll(
                        "SELECT slug, name_en, logo_svg_path, logo_png_path,
                                logo_webp_path, logo_png_512_path,
                                logo_svg_dark_path, logo_png_dark_path, logo_webp_dark_path,
                                logo_palette, logo_updated_at
                           FROM om_companies
                          WHERE sector = :s
                            AND logo_status IN ('indexed','verified')
                            AND (logo_svg_path IS NOT NULL
                                 OR logo_png_path IS NOT NULL
                                 OR logo_webp_path IS NOT NULL)
                          ORDER BY FIELD(logo_status,'verified','indexed'),
                                   logo_updated_at DESC
                          LIMIT 12",
                        [':s' => $hubSector]
                    );
                    $hubLogoCount = (int) ($db->fetchOne(
                        "SELECT COUNT(*) c FROM om_companies
                          WHERE sector = :s AND logo_status IN ('indexed','verified')",
                        [':s' => $hubSector]
                    )['c'] ?? 0);
                } catch (Throwable $e) { $hubLogoSample = []; $hubLogoCount = 0; }
            }
        ?>

        <?php if ($hubSector && !empty($hubLogoSample)): ?>
            <section class="mb-10 bg-white border border-gray-200 rounded-2xl p-6">
                <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
                    <div>
                        <p class="text-xs uppercase tracking-wider text-blue-700 font-semibold mb-1"><?= escq(t('companies.logos_eyebrow')) ?></p>
                        <h2 class="text-lg font-bold text-gray-900">
                            <?= escq(t('companies.logos_available', ['count' => $hubLogoCount, 'label' => $hubLabel])) ?>
                        </h2>
                    </div>
                    <a href="<?= $isAr ? '/ar' : '' ?>/logos/<?= escq($hubSector) ?>"
                       class="inline-flex items-center gap-1.5 text-sm font-medium text-blue-600 hover:text-blue-700">
                        <?= escq(t('companies.open_sector_library')) ?>
                        <i class="fa-solid fa-arrow-<?= $isAr ? 'left' : 'right' ?> text-xs"></i>
                    </a>
                </div>
                <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 lg:grid-cols-8 gap-2.5">
                    <?php foreach ($hubLogoSample as $l):
                        $_lPal  = json_decode((string) ($l['logo_palette'] ?? ''), true) ?: null;
                        $_lFlip = LogoLibrary::shouldUseDarkVariantOnLight($_lPal)
                                  && !empty($l['logo_webp_dark_path'] ?? $l['logo_png_dark_path'] ?? $l['logo_svg_dark_path']);
                        if ($_lFlip) {
                            $src = $l['logo_webp_dark_path']
                                ?: $l['logo_png_dark_path']
                                ?: $l['logo_svg_dark_path'];
                        } else {
                            $src = $l['logo_webp_path'] ?: $l['logo_png_512_path']
                                ?: $l['logo_png_path'] ?: $l['logo_svg_path'];
                        }
                        if (!$src) continue;
                        if (!empty($l['logo_updated_at'])) {
                            $src .= '?v=' . dbTs($l['logo_updated_at']);
                        }
                    ?>
                        <a href="<?= $basePrefix ?>/<?= escq($l['slug']) ?>"
                           class="group bg-gradient-to-br from-gray-50 to-white rounded-lg border border-gray-200 hover:border-gray-300 hover:shadow-md transition p-3 aspect-square flex items-center justify-center">
                            <img src="<?= escq($src) ?>" alt="<?= escq($l['name_en']) ?> logo"
                                 loading="lazy" class="max-h-[70%] max-w-[80%] w-auto h-auto object-contain object-center">
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

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
                            <?= escq($c['size_bucket'] === 'large' ? t('companies.size_large') : t('companies.size_medium')) ?>
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
                <?= escq(t('companies.heading')) ?>
            </h1>
            <p class="text-lg text-gray-600 max-w-2xl mx-auto">
                <?= escq(t('companies.subheading', ['count' => $totalCount])) ?>
            </p>
        </div>

        <!-- Search + filters -->
        <form method="get" action="<?= $basePrefix ?>" class="bg-white rounded-2xl shadow-sm p-6 mb-8 grid grid-cols-1 md:grid-cols-4 gap-3">
            <?php if ($isAr): ?><input type="hidden" name="lang" value="ar"><?php endif; ?>
            <div class="md:col-span-2">
                <label class="block text-xs font-semibold text-gray-600 mb-1"><?= escq(t('companies.search_label')) ?></label>
                <input type="search" name="q" value="<?= escq($_GET['q'] ?? '') ?>" placeholder="<?= escq(t('companies.search_placeholder')) ?>" class="w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-blue-500 focus:ring-2 focus:ring-blue-100 outline-none">
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1"><?= escq(t('companies.sector_label')) ?></label>
                <select name="sector" class="w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-blue-500 outline-none">
                    <option value=""><?= escq(t('companies.all_sectors')) ?></option>
                    <?php foreach ($SECTORS as $k => $labels): ?>
                        <option value="<?= escq($k) ?>" <?= ($_GET['sector'] ?? '') === $k ? 'selected' : '' ?>><?= escq($labels[$isAr ? 'ar' : 'en']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1"><?= escq(t('companies.governorate_label')) ?></label>
                <select name="wilayat" class="w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-blue-500 outline-none">
                    <option value=""><?= escq(t('companies.all_governorates')) ?></option>
                    <?php foreach ($WILAYATS as $k => $labels): ?>
                        <option value="<?= escq($k) ?>" <?= ($_GET['wilayat'] ?? '') === $k ? 'selected' : '' ?>><?= escq($labels[$isAr ? 'ar' : 'en']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="md:col-span-4 flex justify-end">
                <button type="submit" class="px-6 py-2.5 rounded-lg bg-blue-600 text-white font-semibold hover:bg-blue-700">
                    <?= escq(t('companies.filter_btn')) ?>
                </button>
            </div>
        </form>

        <p class="text-sm text-gray-500 mb-6"><?= escq(t('companies.results_count', ['count' => $totalCount])) ?></p>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            <?php foreach ($companies as $c):
                // Index thumbnail when the company has a Logo Library entry.
                // Same auto-flip + ?v= cache buster as the rest of the site.
                $_idxPal  = json_decode((string) ($c['logo_palette'] ?? ''), true) ?: null;
                $_idxFlip = LogoLibrary::shouldUseDarkVariantOnLight($_idxPal)
                            && !empty($c['logo_webp_dark_path'] ?? $c['logo_png_dark_path'] ?? $c['logo_svg_dark_path']);
                if ($_idxFlip) {
                    $_idxSrc = $c['logo_webp_dark_path'] ?: $c['logo_png_dark_path'] ?: $c['logo_svg_dark_path'];
                } else {
                    $_idxSrc = $c['logo_webp_path'] ?: $c['logo_png_512_path'] ?: $c['logo_png_path'] ?: $c['logo_svg_path'];
                }
                if ($_idxSrc && !empty($c['logo_updated_at'])) {
                    $_idxSrc .= '?v=' . dbTs($c['logo_updated_at']);
                }
                $_idxBg = $c['logo_dominant_color'] ?: '#f3f4f6';
                $_idxVerified = ($c['logo_status'] ?? null) === 'verified';
            ?>
                <a href="<?= $basePrefix ?>/<?= escq($c['slug']) ?>"
                   class="cardify-idx-card group flex items-center gap-3 p-3 sm:p-4 bg-white rounded-xl border border-gray-200 hover:border-gray-300 transition-all duration-200 hover:-translate-y-0.5 hover:shadow-[0_8px_24px_-8px_rgba(15,23,42,0.18)]"
                   style="--brand-bg: <?= escq($_idxBg) ?>">
                    <?php if ($_idxSrc): ?>
                        <div class="shrink-0 w-12 h-12 sm:w-14 sm:h-14 rounded-lg bg-gradient-to-br from-gray-50 to-white border border-gray-100 flex items-center justify-center p-1.5 transition-colors duration-200 group-hover:bg-[var(--brand-bg)] group-hover:bg-none">
                            <img src="<?= escq($_idxSrc) ?>" alt="<?= escq($c['name_en']) ?> logo"
                                 loading="lazy"
                                 class="max-h-[80%] max-w-[85%] w-auto h-auto object-contain object-center">
                        </div>
                    <?php else: ?>
                        <div class="shrink-0 w-12 h-12 sm:w-14 sm:h-14 rounded-lg bg-gradient-to-br from-gray-50 to-white border border-gray-100 flex items-center justify-center text-gray-300 font-extrabold text-base">
                            <?= escq(mb_substr($c['name_en'], 0, 2)) ?>
                        </div>
                    <?php endif; ?>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-1.5 min-w-0">
                            <div class="font-semibold text-gray-900 truncate"><?= escq($isAr ? ($c['name_ar'] ?: $c['name_en']) : $c['name_en']) ?></div>
                            <?php if ($_idxVerified): ?>
                                <i class="fa-solid fa-circle-check text-emerald-500 text-xs shrink-0" title="Verified"></i>
                            <?php endif; ?>
                        </div>
                        <?php if (!$isAr && !empty($c['name_ar'])): ?>
                            <div class="text-xs text-gray-500 font-arabic truncate mt-0.5" dir="rtl"><?= escq($c['name_ar']) ?></div>
                        <?php endif; ?>
                        <div class="mt-1 flex items-center gap-2 text-xs text-gray-500">
                            <i class="fa-solid fa-industry text-[10px]"></i>
                            <span class="truncate"><?= escq(labelOf($c['sector'], $SECTORS, $isAr)) ?></span>
                            <span class="text-gray-300">·</span>
                            <i class="fa-solid fa-location-dot text-[10px]"></i>
                            <span class="truncate"><?= escq(labelOf($c['wilayat'], $WILAYATS, $isAr)) ?></span>
                        </div>
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
            <h2 class="text-2xl font-bold text-gray-900 mb-4"><?= escq(t('companies.browse_by_sector')) ?></h2>
            <div class="flex flex-wrap gap-2">
                <?php foreach ($SECTORS as $k => $labels): ?>
                    <a href="<?= $basePrefix ?>/sector/<?= escq($k) ?>" class="inline-flex items-center px-3 py-1.5 rounded-full bg-blue-50 text-blue-700 text-sm font-medium hover:bg-blue-100">
                        <?= escq($labels[$isAr ? 'ar' : 'en']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
        <section class="mt-10">
            <h2 class="text-2xl font-bold text-gray-900 mb-4"><?= escq(t('companies.browse_by_governorate')) ?></h2>
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
            <h2 class="text-2xl font-bold text-gray-900 mb-4"><?= escq(t('companies.about_directory')) ?></h2>
            <div class="prose prose-gray max-w-none">
                <p><?= escq(t('companies.about_body_p1')) ?></p>
                <p><?= escq(t('companies.about_body_p2')) ?></p>
            </div>
        </section>
    </div>
<?php endif; ?>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
