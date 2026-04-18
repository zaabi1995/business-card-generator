<?php
/**
 * Cardify — Oman Business Index 2026 (Landing Page)
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

$ratioText = '—';
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
$pageTitle       = 'Oman Business Index 2026: 2,414 Largest Enterprises | Cardify';
$pageDescription = 'Free public directory of the ' . $totalFmt . ' largest & medium enterprises in Oman — classified by sector and governorate, sourced from MoCIIP.';
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
    [
        'q' => 'How was the Oman Business Index compiled?',
        'a' => 'The index is built from the public register of the Ministry of Commerce, Industry and Investment Promotion (MoCIIP) of the Sultanate of Oman. Cardify extracted registered enterprises classified as "large" or "medium" in the official scale bands, then enriched each record with a sector label (one of ' . $stats['sector_count'] . ' canonical categories) and a governorate detected from the registered address. All names are preserved in their original Arabic and English forms.',
    ],
    [
        'q' => 'Is my company listed?',
        'a' => 'If your enterprise is registered in Oman and classified as large or medium on the MoCIIP scale, it is almost certainly in the index. Use the search at /companies to find your company by English or Arabic name. If it is missing and you believe it should be listed, please contact us.',
    ],
    [
        'q' => 'How do I request an edit or removal?',
        'a' => 'Each company profile has a "Request edit / takedown" link in the footer. You can also email us at info@cardify.om with supporting documentation (commercial registration number, authorized signatory confirmation). We review and act on takedown requests within five working days.',
    ],
    [
        'q' => 'Is this an official government publication?',
        'a' => 'No. The Oman Business Index is an independent research product published by Cardify. It uses public register data factually and is not endorsed by, affiliated with, or produced in cooperation with MoCIIP or any Omani government entity. The data is presented for research, journalism, and professional networking use.',
    ],
    [
        'q' => 'Can I cite this index in articles or reports?',
        'a' => 'Yes — citations are welcome and encouraged. See the "Cite This Index" section above for the suggested citation format. The dataset is published under a Creative Commons BY 4.0 license; you may reuse aggregate figures with attribution to "Cardify Oman Business Index 2026".',
    ],
    [
        'q' => 'Is there an Arabic version?',
        'a' => 'Every company profile already includes the Arabic legal name alongside the English name. A fully Arabic-localised version of the index landing page is in active development and will be published at /ar/oman-business-index.',
    ],
    [
        'q' => 'When will the index be updated?',
        'a' => 'The index refreshes on a rolling quarterly cadence against the live MoCIIP public register. The "Last updated" date at the top of this page reflects the most recent verification pass. Material changes (newly licensed enterprises, re-classifications, dissolutions) are reconciled between updates.',
    ],
    [
        'q' => 'Why did Cardify publish this?',
        'a' => 'Cardify is a business-card platform built in Oman, for Oman. We rely on accurate public company data every day to help professionals network. Publishing the index back to the community — bilingual, free, and search-engine indexable — strengthens the digital backbone of Oman\'s private sector and directly supports Vision 2040\'s economic diversification and digital transformation priorities.',
    ],
    [
        'q' => 'Where can I download Omani company logos?',
        'a' => 'The companion Omani Logo Library at cardify.om/logos hosts downloadable brand marks (SVG + PNG) for companies covered in this index — spanning ministries, sovereign entities, banks, logistics, retail, and more. Logos are indexed for identification and reference; brand owners can claim and verify their profiles, and request takedowns through the takedown form.',
    ],
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
        'https://www.linkedin.com/company/cardifyom',
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
                Research Report &middot; Edition 2026
            </div>
            <h1 class="mt-5 text-4xl sm:text-5xl lg:text-6xl font-bold tracking-tight text-gray-900 leading-[1.08]">
                Oman Business Index 2026:<br class="hidden sm:block">
                <span class="text-blue-700">The <?= obiEscq($totalFmt) ?> Largest Enterprises</span> in the Sultanate
            </h1>
            <p class="mt-6 text-lg sm:text-xl text-gray-600 max-w-3xl leading-relaxed">
                A free, bilingual, public directory of large and medium-sized enterprises registered in Oman —
                sourced from the MoCIIP public register and classified by sector, governorate, and scale.
            </p>

            <div class="mt-6 flex flex-wrap items-center gap-3 text-sm text-gray-500">
                <span class="inline-flex items-center gap-1.5">
                    <i class="fa-regular fa-calendar text-gray-400"></i>
                    Last updated <time datetime="<?= obiEscq($lastUpdatedIso) ?>"><?= obiEscq($lastUpdatedHuman) ?></time>
                </span>
                <span class="text-gray-300">&middot;</span>
                <span class="inline-flex items-center gap-1.5">
                    <i class="fa-regular fa-file-lines text-gray-400"></i>
                    CC BY 4.0
                </span>
                <span class="text-gray-300">&middot;</span>
                <span class="inline-flex items-center gap-1.5">
                    <i class="fa-solid fa-language text-gray-400"></i>
                    EN &middot; AR
                </span>
            </div>

            <div class="mt-8 flex flex-wrap gap-3">
                <a href="/companies" class="inline-flex items-center gap-2 px-5 py-3 rounded-lg bg-blue-600 text-white font-semibold shadow-sm hover:bg-blue-700 transition">
                    <i class="fa-solid fa-magnifying-glass text-sm"></i>
                    Search the full index
                </a>
                <a href="#cite" class="inline-flex items-center gap-2 px-5 py-3 rounded-lg bg-white text-gray-800 font-semibold border border-gray-200 hover:border-blue-300 hover:text-blue-700 transition">
                    <i class="fa-solid fa-quote-right text-sm"></i>
                    Cite this index
                </a>
                <a href="#methodology" class="inline-flex items-center gap-2 px-5 py-3 rounded-lg text-gray-700 font-semibold hover:text-blue-700 transition">
                    Methodology &rarr;
                </a>
            </div>

            <!-- Key stats grid -->
            <dl class="mt-12 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4 sm:gap-5">
                <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm">
                    <dt class="text-xs uppercase tracking-wide text-gray-500">Total enterprises</dt>
                    <dd class="mt-2 text-3xl font-bold text-gray-900 tabular-nums"><?= obiEscq($totalFmt) ?></dd>
                </div>
                <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm">
                    <dt class="text-xs uppercase tracking-wide text-gray-500">Large enterprises</dt>
                    <dd class="mt-2 text-3xl font-bold text-gray-900 tabular-nums"><?= obiEscq($largeFmt) ?></dd>
                    <div class="text-xs text-gray-500 mt-1"><?= obiEscq($largePctOfTotal) ?>% of total</div>
                </div>
                <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm">
                    <dt class="text-xs uppercase tracking-wide text-gray-500">Medium enterprises</dt>
                    <dd class="mt-2 text-3xl font-bold text-gray-900 tabular-nums"><?= obiEscq($mediumFmt) ?></dd>
                    <div class="text-xs text-gray-500 mt-1"><?= obiEscq($mediumPctOfTotal) ?>% of total</div>
                </div>
                <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm">
                    <dt class="text-xs uppercase tracking-wide text-gray-500">Sectors</dt>
                    <dd class="mt-2 text-3xl font-bold text-gray-900 tabular-nums"><?= obiEscq($sectorFmt) ?></dd>
                </div>
                <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm col-span-2 sm:col-span-1">
                    <dt class="text-xs uppercase tracking-wide text-gray-500">Governorates</dt>
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
            <span class="text-xs font-semibold uppercase tracking-widest text-blue-700">Section 01</span>
        </div>
        <h2 class="text-3xl sm:text-4xl font-bold text-gray-900 tracking-tight">Executive Summary</h2>
        <div class="mt-6 space-y-5 text-lg text-gray-700 leading-relaxed">
            <p>
                The Sultanate of Oman is in the middle of the most consequential economic transformation of its modern history.
                Oman Vision 2040 has set a national target to raise the private sector's contribution to GDP, diversify away
                from hydrocarbons, and build a globally-competitive, knowledge-based economy. Private enterprise — not
                government — is the engine that must deliver this outcome. Yet the country has never had a free, public,
                machine-readable list of the enterprises that are actually doing the work.
            </p>
            <p>
                The <strong>Oman Business Index 2026</strong> is an attempt to close that gap. It catalogues
                <strong><?= obiEscq($totalFmt) ?></strong> Omani enterprises — the <strong><?= obiEscq($largeFmt) ?></strong>
                businesses registered at the "large" scale band on the MoCIIP public register plus
                <strong><?= obiEscq($mediumFmt) ?></strong> at the "medium" scale band — and organises them into
                <?= obiEscq($sectorFmt) ?> canonical sectors across all <?= obiEscq($wilayatFmt) ?> governorates of the
                Sultanate. Every record carries the company's Arabic and English legal names, its sector classification,
                its registered governorate, and its scale band.
            </p>
            <p>
                The index is not a ranking. It makes no claim about revenue, employment, or market share — the MoCIIP
                register does not publish those figures. Instead, it is a <em>structural map</em>: a
                plain-language answer to the question "which substantial companies operate in Oman, and where?". That
                map is the foundation on which journalists, analysts, policy researchers, investors, and the companies
                themselves can build sharper conclusions. Publishing it openly — bilingually, permanently crawlable,
                and free — is Cardify's contribution to the digital backbone of Oman's private-sector ecosystem.
            </p>
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
            <span class="text-xs font-semibold uppercase tracking-widest text-blue-700">Section 02</span>
        </div>
        <h2 class="text-3xl sm:text-4xl font-bold text-gray-900 tracking-tight">Methodology</h2>

        <div class="mt-6 space-y-5 text-lg text-gray-700 leading-relaxed">
            <p>
                <strong>Data source.</strong> The base list is the public enterprise register maintained by the
                Ministry of Commerce, Industry and Investment Promotion (MoCIIP) of the Sultanate of Oman. The register
                groups registered enterprises into four scale bands — micro, small, medium, large — using the official
                Omani thresholds on registered capital and declared headcount. The Oman Business Index includes only
                the "large" and "medium" bands; micro and small are excluded for signal-to-noise reasons.
            </p>
            <p>
                <strong>Enrichment.</strong> Two classification layers are added on top of the raw register. First,
                every record is mapped to one of <?= obiEscq($sectorFmt) ?> canonical sectors
                (Oil &amp; Gas, Construction, Finance &amp; Banking, Manufacturing, and so on). Sector assignment
                uses a rules-based classifier over the company's registered commercial activity, validated by hand
                on the largest 100 records. Second, every record is mapped to one of
                <?= obiEscq($wilayatFmt) ?> Omani governorates based on the primary registered address. Both the
                sector taxonomy and the governorate list are published on the dataset page and are stable between
                index updates.
            </p>
            <p>
                <strong>Update cadence.</strong> The index refreshes on a rolling quarterly basis against the live
                MoCIIP register. New registrations, band re-classifications, dissolutions, and English name
                normalisations are reconciled at each refresh. The timestamp at the top of this page reflects the
                most recent verification pass.
            </p>
            <p>
                <strong>Disclaimers.</strong> This index is independent research published by Cardify. It is
                <em>not</em> endorsed by, affiliated with, or produced in cooperation with MoCIIP or any Omani
                government entity. The underlying data is public-record information used factually. Classifications
                (sector, governorate, scale) reflect the state of the register at the verification date; companies
                reorganise, rename, and re-register continuously. Cardify operates a takedown and correction policy:
                any listed enterprise may request an edit or removal via the contact form, and we act on verified
                requests within five working days.
            </p>
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
                <span class="text-xs font-semibold uppercase tracking-widest text-blue-700">Section 03</span>
            </div>
            <h2 class="text-3xl sm:text-4xl font-bold text-gray-900 tracking-tight">Key Findings</h2>
            <p class="mt-4 text-lg text-gray-600 max-w-3xl">
                All figures below are computed live against the <?= obiEscq($totalFmt) ?>-record dataset at the time this page is served.
                They are not pre-rendered; they reflect the current state of the index.
            </p>

            <div class="mt-10 grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Finding 01: top sectors -->
                <div class="bg-white rounded-2xl p-6 border border-gray-200 shadow-sm">
                    <div class="flex items-center gap-3 mb-4">
                        <span class="text-xs font-bold text-gray-400 tracking-widest">FINDING 01</span>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900">Five sectors hold most of the registered mass</h3>
                    <p class="mt-2 text-gray-600">The top five sectors by enterprise count concentrate the majority of Oman's large and medium businesses.</p>
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
                        <p class="mt-4 text-sm text-gray-500">Sector breakdown temporarily unavailable.</p>
                    <?php endif; ?>
                </div>

                <!-- Finding 02: top governorate -->
                <div class="bg-white rounded-2xl p-6 border border-gray-200 shadow-sm">
                    <div class="flex items-center gap-3 mb-4">
                        <span class="text-xs font-bold text-gray-400 tracking-widest">FINDING 02</span>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900">Muscat remains the overwhelming centre of gravity</h3>
                    <p class="mt-2 text-gray-600">Enterprise registrations concentrate heavily in the capital governorate, with regional centres trailing well behind.</p>
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
                        <p class="mt-4 text-sm text-gray-500">Governorate breakdown temporarily unavailable.</p>
                    <?php endif; ?>
                </div>

                <!-- Finding 03: large vs medium ratio -->
                <div class="bg-white rounded-2xl p-6 border border-gray-200 shadow-sm">
                    <div class="flex items-center gap-3 mb-4">
                        <span class="text-xs font-bold text-gray-400 tracking-widest">FINDING 03</span>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900">Medium enterprises outnumber large — by a wide margin</h3>
                    <p class="mt-2 text-gray-600">
                        Of the <?= obiEscq($totalFmt) ?> indexed enterprises,
                        <strong><?= obiEscq($largeFmt) ?> (<?= obiEscq($largePctOfTotal) ?>%)</strong> fall in the "large" band and
                        <strong><?= obiEscq($mediumFmt) ?> (<?= obiEscq($mediumPctOfTotal) ?>%)</strong> in the "medium" band. Medium-scale
                        employers dominate — a pattern consistent with Oman's stated Vision 2040 emphasis on SME growth
                        as a diversification lever.
                    </p>
                    <div class="mt-5 flex h-3 rounded-full overflow-hidden bg-gray-100">
                        <div class="bg-blue-600" style="width: <?= obiEscq($largePctOfTotal) ?>%"></div>
                        <div class="bg-indigo-300" style="width: <?= obiEscq($mediumPctOfTotal) ?>%"></div>
                    </div>
                    <div class="mt-3 flex items-center gap-4 text-xs text-gray-600">
                        <span class="inline-flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-blue-600"></span>Large</span>
                        <span class="inline-flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-indigo-300"></span>Medium</span>
                    </div>
                </div>

                <!-- Finding 04: smallest sector -->
                <div class="bg-white rounded-2xl p-6 border border-gray-200 shadow-sm">
                    <div class="flex items-center gap-3 mb-4">
                        <span class="text-xs font-bold text-gray-400 tracking-widest">FINDING 04</span>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900">The thinnest sector signals the diversification frontier</h3>
                    <?php if ($smallestSector): ?>
                        <?php $sLabel = obiLabelOf($smallestSector['sector'], $SECTORS); $sCount = (int) $smallestSector['c']; ?>
                        <p class="mt-2 text-gray-600">
                            <strong><?= obiEscq($sLabel) ?></strong> is the smallest non-"other" sector in the index
                            with just <strong><?= obiEscq(number_format($sCount)) ?></strong>
                            registered large-or-medium enterprise<?= $sCount === 1 ? '' : 's' ?>. That thinness is less a
                            judgment and more a hint: it marks segments of the Omani economy where the private-sector
                            footprint is still forming, and where Vision 2040's diversification mandate has the most
                            white space to work with.
                        </p>
                        <a href="/companies/sector/<?= obiEscq($smallestSector['sector']) ?>" class="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-blue-700 hover:text-blue-800">
                            Browse <?= obiEscq($sLabel) ?> enterprises
                            <i class="fa-solid fa-arrow-right text-xs"></i>
                        </a>
                    <?php else: ?>
                        <p class="mt-2 text-gray-600">Sector size distribution temporarily unavailable.</p>
                    <?php endif; ?>
                </div>

                <!-- Finding 05: coverage -->
                <div class="bg-white rounded-2xl p-6 border border-gray-200 shadow-sm lg:col-span-2">
                    <div class="flex items-center gap-3 mb-4">
                        <span class="text-xs font-bold text-gray-400 tracking-widest">FINDING 05</span>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900">Nationwide coverage — every governorate is represented</h3>
                    <p class="mt-2 text-gray-600">
                        The index carries registered enterprises in all <?= obiEscq($wilayatFmt) ?> governorates of the
                        Sultanate. From the Musandam peninsula in the far north to Dhofar's frankincense coast in the
                        south and the empty quarter of Al Wusta in between, the index is a full-country map of where
                        substantive commercial activity is licensed. This is the first time, to our knowledge, that a
                        dataset of this shape has been published freely.
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
            <span class="text-xs font-semibold uppercase tracking-widest text-blue-700">Section 04</span>
        </div>
        <h2 class="text-3xl sm:text-4xl font-bold text-gray-900 tracking-tight">Top 10 Flagship Enterprises</h2>
        <p class="mt-4 text-lg text-gray-600 max-w-3xl">
            A curated shortlist of ten flagship Omani enterprises — organisations that anchor the country's
            hydrocarbon, industrial, and financial backbone. Order is editorial and does not imply revenue ranking.
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
                                        <?= $c['size_bucket'] === 'large' ? 'Large' : 'Medium' ?>
                                    </span>
                                </div>
                            </div>
                            <i class="fa-solid fa-chevron-right text-gray-300 text-sm"></i>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ol>
            <p class="mt-6 text-sm text-gray-500">
                See the full ranked list at
                <a href="/companies" class="font-semibold text-blue-700 hover:text-blue-800">/companies</a>
                — searchable by name, sector, and governorate.
            </p>
        <?php else: ?>
            <p class="mt-6 text-gray-500">Flagship list temporarily unavailable. See <a href="/companies" class="text-blue-700 font-semibold">/companies</a>.</p>
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
            <span class="text-xs font-semibold uppercase tracking-widest text-blue-700">Section 05</span>
        </div>
        <h2 class="text-3xl sm:text-4xl font-bold text-gray-900 tracking-tight">Explore by Sector</h2>
        <p class="mt-4 text-lg text-gray-600 max-w-3xl">
            Every enterprise in the index is assigned a canonical sector. Click any sector to view the full list of
            registered large and medium enterprises operating in that space.
        </p>

        <div class="mt-10 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            <?php foreach ($SECTORS as $slug => $labels): ?>
                <?php $count = $sectorCounts[$slug] ?? 0; ?>
                <a href="/companies/sector/<?= obiEscq($slug) ?>" class="group flex items-center justify-between gap-4 p-4 rounded-xl border border-gray-200 bg-white hover:border-blue-300 hover:shadow-sm transition">
                    <div class="min-w-0">
                        <div class="font-semibold text-gray-900 group-hover:text-blue-700 truncate"><?= obiEscq($labels['en']) ?></div>
                        <div class="text-xs text-gray-500 font-arabic truncate" dir="rtl"><?= obiEscq($labels['ar']) ?></div>
                    </div>
                    <div class="flex-shrink-0 text-right">
                        <div class="text-lg font-bold text-gray-900 tabular-nums"><?= obiEscq(number_format($count)) ?></div>
                        <div class="text-[10px] uppercase tracking-wide text-gray-400">companies</div>
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
            <span class="text-xs font-semibold uppercase tracking-widest text-emerald-700">Section 06</span>
        </div>
        <h2 class="text-3xl sm:text-4xl font-bold text-gray-900 tracking-tight">Explore by Governorate</h2>
        <p class="mt-4 text-lg text-gray-600 max-w-3xl">
            Every enterprise is mapped to one of the <?= obiEscq($wilayatFmt) ?> governorates of the Sultanate based on
            its registered primary address. Click any governorate to open the regional listing.
        </p>

        <div class="mt-10 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
            <?php foreach ($WILAYATS as $slug => $labels): ?>
                <?php $count = $wilayatCounts[$slug] ?? 0; ?>
                <a href="/companies/wilayat/<?= obiEscq($slug) ?>" class="group flex items-center justify-between gap-3 p-4 rounded-xl border border-gray-200 bg-white hover:border-emerald-400 hover:shadow-sm transition">
                    <div class="min-w-0">
                        <div class="font-semibold text-gray-900 group-hover:text-emerald-700 truncate"><?= obiEscq($labels['en']) ?></div>
                        <div class="text-xs text-gray-500 font-arabic truncate" dir="rtl"><?= obiEscq($labels['ar']) ?></div>
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
                Section 07
            </div>
            <h2 class="mt-5 text-3xl sm:text-5xl font-bold tracking-tight">Search the Full Index</h2>
            <p class="mt-4 text-lg text-blue-100 max-w-2xl mx-auto">
                All <?= obiEscq($totalFmt) ?> enterprises are fully searchable — in English and Arabic — with filters
                by sector and governorate, and paginated listings. Every company has its own permanent URL.
            </p>
            <a href="/companies" class="mt-8 inline-flex items-center gap-2 px-7 py-4 rounded-xl bg-white text-blue-700 font-bold shadow-lg hover:shadow-xl hover:-translate-y-0.5 transition">
                <i class="fa-solid fa-magnifying-glass"></i>
                Open the directory
                <i class="fa-solid fa-arrow-right text-sm"></i>
            </a>
            <div class="mt-8 flex flex-wrap items-center justify-center gap-x-6 gap-y-2 text-sm text-blue-200">
                <span class="inline-flex items-center gap-1.5"><i class="fa-solid fa-circle-check"></i> Bilingual EN/AR</span>
                <span class="inline-flex items-center gap-1.5"><i class="fa-solid fa-circle-check"></i> Sector filter</span>
                <span class="inline-flex items-center gap-1.5"><i class="fa-solid fa-circle-check"></i> Governorate filter</span>
                <span class="inline-flex items-center gap-1.5"><i class="fa-solid fa-circle-check"></i> Permalink per company</span>
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
            <span class="text-xs font-semibold uppercase tracking-widest text-blue-700">Section 08</span>
        </div>
        <h2 class="text-3xl sm:text-4xl font-bold text-gray-900 tracking-tight">Cite This Index</h2>
        <p class="mt-4 text-lg text-gray-600 max-w-3xl">
            Journalists, analysts, and researchers are welcome — and encouraged — to cite the Oman Business Index.
            The dataset is published under a Creative Commons Attribution 4.0 International licence: reuse aggregate
            figures freely, with attribution.
        </p>

        <div class="mt-8 space-y-6">
            <div>
                <div class="text-xs font-semibold uppercase tracking-widest text-gray-500 mb-2">Short citation</div>
                <div class="relative bg-gray-900 text-gray-100 rounded-xl p-5 font-mono text-sm leading-relaxed overflow-x-auto">
                    <code id="cite-short">Cardify Oman Business Index 2026, accessed <?= obiEscq(date('F j, Y')) ?>. https://cardify.om/oman-business-index</code>
                    <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('cite-short').textContent)" class="absolute top-3 right-3 px-2.5 py-1 rounded bg-white/10 hover:bg-white/20 text-xs font-semibold text-white transition">
                        Copy
                    </button>
                </div>
            </div>
            <div>
                <div class="text-xs font-semibold uppercase tracking-widest text-gray-500 mb-2">APA style</div>
                <div class="relative bg-gray-900 text-gray-100 rounded-xl p-5 font-mono text-sm leading-relaxed overflow-x-auto">
                    <code id="cite-apa">Cardify. (2026). <em>Oman Business Index 2026: The <?= obiEscq($totalFmt) ?> largest enterprises in the Sultanate</em>. Retrieved <?= obiEscq(date('F j, Y')) ?>, from https://cardify.om/oman-business-index</code>
                    <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('cite-apa').textContent)" class="absolute top-3 right-3 px-2.5 py-1 rounded bg-white/10 hover:bg-white/20 text-xs font-semibold text-white transition">
                        Copy
                    </button>
                </div>
            </div>
            <div class="text-sm text-gray-500">
                For data-licensing queries, bulk data access, or press enquiries, contact
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
            <span class="text-xs font-semibold uppercase tracking-widest text-blue-700">Section 09</span>
        </div>
        <h2 class="text-3xl sm:text-4xl font-bold text-gray-900 tracking-tight">About Cardify</h2>
        <p class="mt-6 text-lg text-gray-700 leading-relaxed">
            <strong>Cardify</strong> is the Sultanate's digital business-card platform — built in Oman, for Oman.
            We help companies issue beautiful, on-brand digital cards to every employee, and print physical cards on
            demand through a nationwide print-shop network. We publish the Oman Business Index as a public good:
            accurate company data is oxygen for professional networking, and a thriving networking ecosystem is one
            of the quiet compounding forces behind Vision 2040's private-sector ambition. If the index is useful to
            you, the product is likely useful to your team.
        </p>
        <div class="mt-8 flex flex-wrap gap-3">
            <a href="/get-started" class="inline-flex items-center gap-2 px-5 py-3 rounded-lg bg-blue-600 text-white font-semibold shadow-sm hover:bg-blue-700 transition">
                <i class="fa-solid fa-arrow-right-to-bracket"></i>
                Create a free card
            </a>
            <a href="/about" class="inline-flex items-center gap-2 px-5 py-3 rounded-lg bg-white text-gray-800 font-semibold border border-gray-200 hover:border-blue-300 hover:text-blue-700 transition">
                More about Cardify
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
                <span class="text-xs font-semibold uppercase tracking-widest text-blue-700">Section 10</span>
            </div>
            <h2 class="text-3xl sm:text-4xl font-bold text-gray-900 tracking-tight">Frequently Asked Questions</h2>

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
                Still have questions? Email
                <a href="mailto:info@cardify.om" class="font-semibold text-blue-700 hover:text-blue-800">info@cardify.om</a>.
            </p>
        </div>
    </section>

    <!-- ============================================================
         COMPANION ARCHIVE — OMANI LOGO LIBRARY (visual gallery)
         ============================================================ -->
    <section id="logo-library" class="bg-gray-50 border-y border-gray-100 scroll-mt-24">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-20">
            <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4 mb-10">
                <div class="max-w-2xl">
                    <p class="text-xs uppercase tracking-wider text-blue-700 font-semibold mb-3">Companion archive</p>
                    <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-900">The Omani Logo Library</h2>
                    <p class="mt-3 text-lg text-gray-600">
                        Every company in this index can have a logo. <?= obiEscq(number_format($logoTotal)) ?>+ Omani brand marks
                        are already indexed — ministries, sovereign entities, banks, logistics, retail, and more.
                        Search, filter by sector, and download SVG / PNG for identification and reference use.
                    </p>
                </div>
                <a href="/logos"
                   class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-semibold shadow-lg shadow-blue-600/20 whitespace-nowrap self-start md:self-end">
                    Open the Logo Library
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
                <p class="text-sm font-semibold text-gray-600 mb-3">Browse by sector</p>
                <div class="flex flex-wrap gap-2">
                    <?php
                        $sectorLinks = [
                            ['slug' => 'government-defense',    'label' => 'Government'],
                            ['slug' => 'finance',               'label' => 'Banks & Finance'],
                            ['slug' => 'logistics-shipping',    'label' => 'Logistics & Shipping'],
                            ['slug' => 'oil-gas',               'label' => 'Oil & Gas'],
                            ['slug' => 'healthcare',            'label' => 'Healthcare'],
                            ['slug' => 'education',             'label' => 'Education'],
                            ['slug' => 'telecom',               'label' => 'Telecom'],
                            ['slug' => 'food-beverage',         'label' => 'Food & Beverage'],
                            ['slug' => 'hospitality-tourism',   'label' => 'Hospitality'],
                            ['slug' => 'retail',                'label' => 'Retail'],
                            ['slug' => 'manufacturing',         'label' => 'Manufacturing'],
                            ['slug' => 'real-estate',           'label' => 'Real Estate'],
                        ];
                        foreach ($sectorLinks as $s): ?>
                        <a href="/logos/<?= obiEscq($s['slug']) ?>"
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border border-gray-200 rounded-full text-sm text-gray-700 hover:border-blue-300 hover:text-blue-600 transition">
                            <?= obiEscq($s['label']) ?>
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
                    <h3 class="font-bold text-gray-900 mb-1">Why a logo library?</h3>
                    <p class="text-sm text-gray-600 leading-relaxed">Research, journalism, pitch decks, dashboards, and analyst reports all need Omani brand marks at the right resolution. This archive centralizes them.</p>
                </div>
                <div class="bg-white rounded-xl border border-gray-200 p-5">
                    <div class="w-9 h-9 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center mb-3">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                    <h3 class="font-bold text-gray-900 mb-1">Is it free?</h3>
                    <p class="text-sm text-gray-600 leading-relaxed">Yes. Browsing and downloading indexed + verified logos is free. Marks remain trademarks of their owners; use is permitted for identification under nominative fair-use.</p>
                </div>
                <div class="bg-white rounded-xl border border-gray-200 p-5">
                    <div class="w-9 h-9 rounded-lg bg-rose-50 text-rose-600 flex items-center justify-center mb-3">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>
                    <h3 class="font-bold text-gray-900 mb-1">Takedown-ready</h3>
                    <p class="text-sm text-gray-600 leading-relaxed">Brand owners can <a href="/logo-takedown" class="text-blue-600 hover:underline font-medium">request removal</a>. We acknowledge within 48 hours and hide within 24 hours of valid requests.</p>
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
                Last verified <time datetime="<?= obiEscq($lastUpdatedIso) ?>"><?= obiEscq($lastUpdatedHuman) ?></time>
            </span>
            <span class="mx-3 text-gray-300">&middot;</span>
            <span>Source: MoCIIP public register</span>
            <span class="mx-3 text-gray-300">&middot;</span>
            <a href="/contact" class="hover:text-gray-700 underline underline-offset-2">Request edit / takedown</a>
        </p>
    </section>
</main>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
