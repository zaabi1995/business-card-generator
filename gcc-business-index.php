<?php
/**
 * Cardify — GCC Business Index 2026 (Flagship Landing Page)
 *
 * Research-grade overview of business infrastructure across the GCC:
 * Saudi Arabia, UAE, Qatar, Bahrain, Kuwait, Oman. Designed as a press /
 * backlink magnet — the kind of page Reuters, Bloomberg GCC, Gulf Business,
 * or The National link to when citing regional company counts.
 *
 * Route: /gcc-business-index
 * Public, cacheable.
 *
 * Current state (Apr 2026):
 *  - Oman fully indexed (2,415 companies, 79 logos, 31 sovereign curated)
 *  - Saudi / UAE / Qatar / Bahrain / Kuwait: scraping & curation in progress
 *
 * The page is built to keep shipping value even before other GCC data lands
 * by making Oman's depth the proof-of-method.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';

$db = Database::getInstance();

$pageTitle       = 'GCC Business Index 2026 — Companies, Sectors, and Logos Across the Gulf';
$pageDescription = 'Research-grade public index of Gulf Cooperation Council business infrastructure: company counts, sector mix, and brand logos across Saudi Arabia, UAE, Qatar, Bahrain, Kuwait, and Oman. Built by Cardify.';
$canonicalUrl    = 'https://cardify.om/gcc-business-index';
$bodyClass       = 'bg-white';
$showNavigation  = true;

// Live Oman stats (the anchor proof)
$omStats = [
    'companies' => 2415,
    'logos'     => 79,
    'sectors'   => 23,
    'governorates' => 11,
    'sovereign_curated' => 31,
];
try {
    $row = $db->fetchOne("SELECT COUNT(*) c FROM om_companies");
    if ($row) $omStats['companies'] = (int) $row['c'];
    $logoRow = $db->fetchOne("SELECT COUNT(*) c FROM om_companies WHERE logo_status IN ('indexed','verified')");
    if ($logoRow) $omStats['logos'] = (int) $logoRow['c'];
} catch (Throwable $e) {}

// Country status — hand-curated. 'data' marks what's shipped today.
$countries = [
    [
        'code'      => 'OM',
        'country_slug' => 'oman',
        'name_en'   => 'Oman',
        'name_ar'   => 'عُمان',
        'flag'      => '🇴🇲',
        'capital'   => 'Muscat',
        'gdp_usd_bn' => 114.7,   // 2023 World Bank nominal GDP
        'population_m' => 4.98,  // 2024 estimate
        'companies_approx' => $omStats['companies'],
        'sectors'   => 23,
        'regions'   => 11,
        'index_url' => '/oman-business-index',
        'logos_url' => '/logos',
        'logos_count' => $omStats['logos'],
        'status'    => 'live',
        'note'      => 'Fully indexed. 2,415 companies from the MoCIIP public register, 79 logos, 31 sovereign entities with curated Arabic+English summaries.',
    ],
    [
        'code'      => 'SA',
        'country_slug' => 'saudi-arabia',
        'name_en'   => 'Saudi Arabia',
        'name_ar'   => 'المملكة العربية السعودية',
        'flag'      => '🇸🇦',
        'capital'   => 'Riyadh',
        'gdp_usd_bn' => 1067.6,   // 2023 nominal
        'population_m' => 33.0,
        'companies_approx' => null, // 1.1M+ CRs per Monsha'at — not a like-for-like figure
        'sectors'   => null,
        'regions'   => 13,
        'index_url' => null,
        'logos_url' => null,
        'logos_count' => 0,
        'status'    => 'coming',
        'note'      => 'Scraping from the Ministry of Commerce open register + public SAMA / CMA licensees. Q2–Q3 2026.',
    ],
    [
        'code'      => 'AE',
        'country_slug' => 'uae',
        'name_en'   => 'United Arab Emirates',
        'name_ar'   => 'الإمارات العربية المتحدة',
        'flag'      => '🇦🇪',
        'capital'   => 'Abu Dhabi',
        'gdp_usd_bn' => 507.5,
        'population_m' => 10.2,
        'companies_approx' => null,
        'sectors'   => null,
        'regions'   => 7,
        'index_url' => null,
        'logos_url' => null,
        'logos_count' => 0,
        'status'    => 'coming',
        'note'      => '7 emirates, multiple free-zone authorities. Index will federate public data from DED Abu Dhabi + Dubai Economy + free-zone registers.',
    ],
    [
        'code'      => 'QA',
        'country_slug' => 'qatar',
        'name_en'   => 'Qatar',
        'name_ar'   => 'قطر',
        'flag'      => '🇶🇦',
        'capital'   => 'Doha',
        'gdp_usd_bn' => 221.4,
        'population_m' => 3.05,
        'companies_approx' => null,
        'sectors'   => null,
        'regions'   => 8,
        'index_url' => null,
        'logos_url' => null,
        'logos_count' => 0,
        'status'    => 'coming',
        'note'      => 'Will federate MoCI Qatar public register + Qatar Financial Centre + QFZA. Q3 2026.',
    ],
    [
        'code'      => 'BH',
        'country_slug' => 'bahrain',
        'name_en'   => 'Bahrain',
        'name_ar'   => 'البحرين',
        'flag'      => '🇧🇭',
        'capital'   => 'Manama',
        'gdp_usd_bn' => 43.2,
        'population_m' => 1.54,
        'companies_approx' => null,
        'sectors'   => null,
        'regions'   => 4,
        'index_url' => null,
        'logos_url' => null,
        'logos_count' => 0,
        'status'    => 'coming',
        'note'      => 'Sijilat CR platform is exceptionally open by regional standards — expected to be the fastest next country to fully index.',
    ],
    [
        'code'      => 'KW',
        'country_slug' => 'kuwait',
        'name_en'   => 'Kuwait',
        'name_ar'   => 'الكويت',
        'flag'      => '🇰🇼',
        'capital'   => 'Kuwait City',
        'gdp_usd_bn' => 161.8,
        'population_m' => 4.26,
        'companies_approx' => null,
        'sectors'   => null,
        'regions'   => 6,
        'index_url' => null,
        'logos_url' => null,
        'logos_count' => 0,
        'status'    => 'coming',
        'note'      => 'Ministry of Commerce and Industry (MOCI) Kuwait + KDIPA data. Q4 2026.',
    ],
];

// Aggregate stats
$totalGdp = 0; $totalPop = 0; $totalLogos = 0; $totalCompanies = 0; $liveCountries = 0;
foreach ($countries as $c) {
    $totalGdp += $c['gdp_usd_bn'] ?? 0;
    $totalPop += $c['population_m'] ?? 0;
    $totalLogos += $c['logos_count'] ?? 0;
    if (!empty($c['companies_approx'])) $totalCompanies += $c['companies_approx'];
    if ($c['status'] === 'live') $liveCountries++;
}
$lastUpdated = date('c');
$lastUpdatedHuman = date('M j, Y');

$faq = [
    [
        'q' => 'What is the GCC Business Index?',
        'a' => 'An open, research-grade index of business infrastructure across the six Gulf Cooperation Council states — Saudi Arabia, UAE, Qatar, Bahrain, Kuwait, and Oman. It federates public company registers, sovereign-entity directories, and logo archives into a single structured resource aimed at researchers, journalists, analysts, and regional businesses. The Oman section is fully indexed; other GCC countries are rolling out through 2026.',
    ],
    [
        'q' => 'Who publishes this?',
        'a' => 'Cardify, a business-identity platform built in the region. Cardify operates cardify.om (primary) and is expanding to GCC-wide coverage. The index is an open research product and is distinct from our commercial digital-business-card product.',
    ],
    [
        'q' => 'Where does the data come from?',
        'a' => 'Public national registers: for Oman the MoCIIP (Ministry of Commerce, Industry and Investment Promotion) register via business.gov.om; for other GCC states the equivalent open registers operated by each national commerce authority. Sovereign-entity logos in Oman are sourced from official releases, 2oman.net (attributed), and ministerial visual-identity packs.',
    ],
    [
        'q' => 'Can I cite this index in articles or reports?',
        'a' => 'Yes. Citations and backlinks are welcome. Suggested citation: "GCC Business Index 2026 — Cardify (https://cardify.om/gcc-business-index)". The dataset is published under Creative Commons BY 4.0.',
    ],
    [
        'q' => 'Is there an API?',
        'a' => 'A public JSON API for the Omani Logo Library is live at cardify.om/api/logos/{list,show,sectors,stats}. A unified GCC endpoint is on the roadmap and will expose the same shape keyed by country code.',
    ],
    [
        'q' => 'How can I request additions or corrections?',
        'a' => 'Company owners and brand representatives can claim their profile directly (domain-email verification auto-verifies). Researchers and journalists can email contact@cardify.om for dataset questions. Takedown and correction requests are logged and reviewed within 48 hours.',
    ],
    [
        'q' => 'Why is Oman complete and others are coming?',
        'a' => 'Oman is Cardify\'s home market — access to clean data is easiest there. The methodology proven on 2,415 Omani records is now being applied country-by-country, starting with Bahrain (which has the most open CR platform in the region, Sijilat).',
    ],
];

$faqLd = [
    '@context'   => 'https://schema.org',
    '@type'      => 'FAQPage',
    'mainEntity' => array_map(fn($q) => [
        '@type'          => 'Question',
        'name'           => $q['q'],
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q['a']],
    ], $faq),
];

$crumbLd = [
    '@context' => 'https://schema.org',
    '@type'    => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Cardify',             'item' => 'https://cardify.om'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'GCC Business Index', 'item' => $canonicalUrl],
    ],
];

$datasetLd = [
    '@context'       => 'https://schema.org',
    '@type'          => 'Dataset',
    'name'           => 'GCC Business Index 2026',
    'description'    => 'Federated open index of business infrastructure across the six Gulf Cooperation Council states: company registers, sovereign-entity directories, and brand logo archives.',
    'url'            => $canonicalUrl,
    'keywords'       => 'GCC, Saudi Arabia, UAE, Qatar, Bahrain, Kuwait, Oman, business register, companies, logos, sovereign, government, MENA',
    'license'        => 'https://creativecommons.org/licenses/by/4.0/',
    'creator'        => ['@type' => 'Organization', 'name' => 'Cardify', 'url' => 'https://cardify.om'],
    'temporalCoverage' => '2026/..',
    'spatialCoverage'  => [
        ['@type' => 'Country', 'name' => 'Saudi Arabia', 'address' => ['@type' => 'PostalAddress', 'addressCountry' => 'SA']],
        ['@type' => 'Country', 'name' => 'United Arab Emirates', 'address' => ['@type' => 'PostalAddress', 'addressCountry' => 'AE']],
        ['@type' => 'Country', 'name' => 'Qatar', 'address' => ['@type' => 'PostalAddress', 'addressCountry' => 'QA']],
        ['@type' => 'Country', 'name' => 'Bahrain', 'address' => ['@type' => 'PostalAddress', 'addressCountry' => 'BH']],
        ['@type' => 'Country', 'name' => 'Kuwait', 'address' => ['@type' => 'PostalAddress', 'addressCountry' => 'KW']],
        ['@type' => 'Country', 'name' => 'Oman', 'address' => ['@type' => 'PostalAddress', 'addressCountry' => 'OM']],
    ],
    'distribution'   => [
        ['@type' => 'DataDownload', 'encodingFormat' => 'application/json', 'contentUrl' => 'https://cardify.om/api/logos/list'],
        ['@type' => 'DataDownload', 'encodingFormat' => 'application/json', 'contentUrl' => 'https://cardify.om/api/logos/sectors'],
        ['@type' => 'DataDownload', 'encodingFormat' => 'application/json', 'contentUrl' => 'https://cardify.om/api/logos/stats'],
        ['@type' => 'DataDownload', 'encodingFormat' => 'text/html',        'contentUrl' => 'https://cardify.om/oman-business-index'],
    ],
    'dateModified'   => $lastUpdated,
    'publisher'      => ['@type' => 'Organization', 'name' => 'Cardify', 'url' => 'https://cardify.om'],
];

$orgLd = [
    '@context' => 'https://schema.org',
    '@type'    => 'Organization',
    'name'     => 'Cardify',
    'url'      => 'https://cardify.om',
    'logo'     => 'https://cardify.om/assets/images/cardify-logo.png',
    'areaServed' => [
        ['@type' => 'Country', 'name' => 'Oman'],
        ['@type' => 'Country', 'name' => 'Saudi Arabia'],
        ['@type' => 'Country', 'name' => 'United Arab Emirates'],
        ['@type' => 'Country', 'name' => 'Qatar'],
        ['@type' => 'Country', 'name' => 'Bahrain'],
        ['@type' => 'Country', 'name' => 'Kuwait'],
    ],
    'knowsAbout' => ['GCC business infrastructure', 'Omani company register', 'Brand logos', 'Business identity', 'Sovereign entities'],
];

$extraHead =
      '<script type="application/ld+json">' . json_encode($faqLd,     JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>'
    . '<script type="application/ld+json">' . json_encode($crumbLd,   JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>'
    . '<script type="application/ld+json">' . json_encode($datasetLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>'
    . '<script type="application/ld+json">' . json_encode($orgLd,     JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>'
    // English-only for now; /ar/ variant + hreflang will be added when the
    // page is localized. Advertising /ar/ before localization tells search
    // engines we have an Arabic page that doesn't exist.
    . '<link rel="alternate" hreflang="en" href="https://cardify.om/gcc-business-index">'
    . '<link rel="alternate" hreflang="x-default" href="https://cardify.om/gcc-business-index">';

function gccEsc($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

require_once INCLUDES_DIR . '/ui-header.php';
?>

<!-- ============ HERO ============ -->
<section class="relative overflow-hidden bg-gradient-to-b from-blue-50 via-white to-white border-b border-gray-100">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-20 lg:py-28">
        <div class="text-center max-w-3xl mx-auto">
            <p class="text-xs uppercase tracking-wider text-blue-700 font-semibold mb-4">
                Flagship research · GCC-wide · Free & open
            </p>
            <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold text-gray-900 mb-5 leading-tight">
                GCC Business Index <span class="text-blue-600">2026</span>
            </h1>
            <p class="text-lg sm:text-xl text-gray-600 mb-8 max-w-2xl mx-auto">
                An open, research-grade index of companies, sovereign entities, and brand logos across the six Gulf states. Oman is fully indexed. Saudi Arabia, UAE, Qatar, Bahrain, and Kuwait are rolling out through 2026.
            </p>

            <div class="flex flex-wrap gap-3 justify-center mb-10">
                <a href="#countries" class="inline-flex items-center gap-2 px-6 py-3 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-semibold shadow-lg shadow-blue-600/30 transition">
                    Explore by country
                    <i class="fa-solid fa-arrow-down text-xs"></i>
                </a>
                <a href="/oman-business-index" class="inline-flex items-center gap-2 px-6 py-3 rounded-lg bg-white border border-gray-300 text-gray-800 font-semibold hover:border-blue-300 hover:text-blue-700 transition">
                    Oman deep-dive
                    <i class="fa-solid fa-arrow-right text-xs"></i>
                </a>
            </div>

            <!-- Headline stats -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 sm:gap-6 max-w-3xl mx-auto">
                <div>
                    <div class="text-3xl sm:text-4xl font-extrabold text-blue-600">6</div>
                    <div class="text-xs sm:text-sm text-gray-500 mt-1 uppercase tracking-wide">Countries</div>
                </div>
                <div>
                    <div class="text-3xl sm:text-4xl font-extrabold text-blue-600"><?= number_format($totalGdp, 0) ?>B</div>
                    <div class="text-xs sm:text-sm text-gray-500 mt-1 uppercase tracking-wide">Combined GDP (USD)</div>
                </div>
                <div>
                    <div class="text-3xl sm:text-4xl font-extrabold text-blue-600"><?= number_format($totalPop, 1) ?>M</div>
                    <div class="text-xs sm:text-sm text-gray-500 mt-1 uppercase tracking-wide">Population</div>
                </div>
                <div>
                    <div class="text-3xl sm:text-4xl font-extrabold text-blue-600"><?= number_format($omStats['logos']) ?>+</div>
                    <div class="text-xs sm:text-sm text-gray-500 mt-1 uppercase tracking-wide">Logos indexed</div>
                </div>
            </div>

            <p class="mt-8 text-xs text-gray-400">
                <i class="fa-regular fa-calendar mr-1"></i>
                Last updated <time datetime="<?= gccEsc($lastUpdated) ?>"><?= gccEsc($lastUpdatedHuman) ?></time>
                · Dataset license: <a href="https://creativecommons.org/licenses/by/4.0/" class="underline hover:text-blue-600">CC BY 4.0</a>
            </p>
        </div>
    </div>
</section>

<!-- ============ COUNTRIES ============ -->
<section id="countries" class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-20 scroll-mt-24">
    <div class="max-w-3xl mb-10">
        <p class="text-xs uppercase tracking-wider text-blue-700 font-semibold mb-2">6 countries</p>
        <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-900 mb-3">Coverage by country</h2>
        <p class="text-lg text-gray-600">
            Each country card shows what's live today and what's coming. Oman is fully indexed; other GCC states are being added through 2026 via the same proven methodology (public CR scrape + sovereign-entity curation + logo archive).
        </p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        <?php foreach ($countries as $c):
            $isLive = $c['status'] === 'live';
            $borderCls = $isLive ? 'border-emerald-300 bg-gradient-to-br from-emerald-50 to-white' : 'border-gray-200 bg-white';
            $badgeCls  = $isLive ? 'bg-emerald-100 text-emerald-700 ring-emerald-200' : 'bg-gray-100 text-gray-600 ring-gray-200';
            $badgeText = $isLive ? 'Fully indexed' : 'Coming 2026';
        ?>
            <article class="rounded-2xl border-2 <?= $borderCls ?> p-6 flex flex-col">
                <div class="flex items-start justify-between mb-4">
                    <div class="flex items-center gap-3">
                        <div class="text-4xl leading-none"><?= $c['flag'] ?></div>
                        <div>
                            <h3 class="text-lg font-bold text-gray-900"><?= gccEsc($c['name_en']) ?></h3>
                            <p class="text-xs text-gray-500" dir="rtl"><?= gccEsc($c['name_ar']) ?></p>
                        </div>
                    </div>
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full ring-1 ring-inset text-[10px] font-semibold <?= $badgeCls ?>">
                        <?= gccEsc($badgeText) ?>
                    </span>
                </div>

                <dl class="grid grid-cols-2 gap-3 mb-4 text-sm">
                    <div>
                        <dt class="text-xs text-gray-500">Capital</dt>
                        <dd class="font-semibold text-gray-900"><?= gccEsc($c['capital']) ?></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">GDP (USD)</dt>
                        <dd class="font-semibold text-gray-900">$<?= number_format($c['gdp_usd_bn'], 1) ?>B</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Population</dt>
                        <dd class="font-semibold text-gray-900"><?= number_format($c['population_m'], 2) ?>M</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Logos</dt>
                        <dd class="font-semibold text-gray-900"><?= number_format($c['logos_count']) ?></dd>
                    </div>
                </dl>

                <p class="text-sm text-gray-600 leading-relaxed mb-5 flex-grow"><?= gccEsc($c['note']) ?></p>

                <?php $countrySlug = $c['country_slug'] ?? strtolower($c['code']); ?>
                <?php if ($isLive): ?>
                    <div class="flex flex-wrap gap-2">
                        <?php if ($c['index_url']): ?>
                            <a href="<?= gccEsc(getBasePath() . ltrim($c['index_url'], '/')) ?>" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold transition">
                                Open index <i class="fa-solid fa-arrow-right text-xs"></i>
                            </a>
                        <?php endif; ?>
                        <?php if ($c['logos_url']): ?>
                            <a href="<?= gccEsc(getBasePath() . ltrim($c['logos_url'], '/')) ?>" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-white border border-gray-200 text-gray-700 hover:border-blue-300 hover:text-blue-600 text-sm font-semibold transition">
                                Logo library
                            </a>
                        <?php endif; ?>
                        <a href="<?= gccEsc(getBasePath() . 'gcc/' . $countrySlug) ?>" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-white border border-gray-200 text-gray-700 hover:border-blue-300 hover:text-blue-600 text-sm font-semibold transition">
                            Country page
                        </a>
                    </div>
                <?php else: ?>
                    <div class="flex flex-wrap gap-2 items-center">
                        <a href="<?= gccEsc(getBasePath() . 'gcc/' . $countrySlug) ?>" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold transition">
                            <?= gccEsc($c['name_en']) ?> preview <i class="fa-solid fa-arrow-right text-xs"></i>
                        </a>
                        <a href="mailto:contact@cardify.om?subject=<?= urlencode('Early access: ' . $c['name_en'] . ' Business Index') ?>" class="inline-flex items-center gap-1.5 text-sm font-semibold text-blue-600 hover:text-blue-700">
                            <i class="fa-solid fa-envelope text-xs"></i> Get notified
                        </a>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<!-- ============ METHODOLOGY ============ -->
<section id="methodology" class="bg-gray-50 border-y border-gray-100 scroll-mt-24">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-20">
        <p class="text-xs uppercase tracking-wider text-blue-700 font-semibold mb-2">Methodology</p>
        <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-900 mb-5">How the index is built</h2>
        <p class="text-lg text-gray-600 mb-8">
            Every GCC country has an open or semi-open national commercial register. Cardify federates them into a consistent schema. The full pipeline is four stages:
        </p>
        <ol class="space-y-5">
            <?php foreach ([
                ['Register scrape',
                 'Pull the public company register from the national authority (MoCIIP for Oman, SAMA / MoC for Saudi, DED + free zones for UAE, etc.). Normalize names, bilingual Arabic+English, addresses, and classification.'],
                ['Sovereign + authority curation',
                 'Overlay a hand-curated layer of ministries, sovereign funds, regulators, and statutory authorities — each with a bilingual summary, official logo, and cross-links to sector peers.'],
                ['Logo archive',
                 'Source brand marks from official releases, press kits, national visual-identity packs, and attributed public aggregators (e.g. 2oman.net). Every logo is published under nominative fair use with a 48-hour takedown SLA.'],
                ['Structured publication',
                 'Every entity gets canonical URLs, Schema.org structured data (Organization, ImageObject, DataCatalog), bilingual hreflang, and a public JSON API. The full dataset is CC BY 4.0 for research, journalism, and analysis.'],
            ] as $i => [$title, $body]): ?>
                <li class="flex gap-5 bg-white rounded-xl border border-gray-200 p-5">
                    <div class="shrink-0 w-10 h-10 rounded-lg bg-blue-600 text-white flex items-center justify-center font-bold"><?= $i + 1 ?></div>
                    <div>
                        <h3 class="font-bold text-gray-900 mb-1"><?= gccEsc($title) ?></h3>
                        <p class="text-gray-600 leading-relaxed"><?= gccEsc($body) ?></p>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>
</section>

<!-- ============ USE CASES ============ -->
<section class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-20">
    <div class="max-w-3xl mb-10">
        <p class="text-xs uppercase tracking-wider text-blue-700 font-semibold mb-2">Who uses it</p>
        <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-900 mb-3">Research-grade, used by real teams</h2>
        <p class="text-lg text-gray-600">
            The index is built to be cited, not just browsed. Free, CC BY 4.0, machine-readable.
        </p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5">
        <?php foreach ([
            ['fa-newspaper', 'Journalists & editors', 'Cite verified Omani company counts, sector breakdowns, and sovereign-entity profiles. Each page carries a suggested citation and CC-BY licensing.'],
            ['fa-chart-line',  'Analysts & researchers', 'Pull data via JSON API, download CSVs (rolling out), and run comparative studies across GCC markets with consistent schema.'],
            ['fa-briefcase',   'Corporate strategy',      'Map competitors, identify sovereign-entity counterparties, and build bottom-up addressable-market models with real, refreshed data.'],
            ['fa-pen-ruler',   'Designers & agencies',    'Download clean SVG + PNG logos with dimensions and dominant-color metadata. Attributed, takedown-ready, fair-use posture.'],
        ] as [$icon, $title, $body]): ?>
            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <div class="w-10 h-10 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center mb-3">
                    <i class="fa-solid <?= $icon ?>"></i>
                </div>
                <h3 class="font-bold text-gray-900 mb-1"><?= gccEsc($title) ?></h3>
                <p class="text-sm text-gray-600 leading-relaxed"><?= gccEsc($body) ?></p>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- ============ CITE ============ -->
<section class="bg-gray-900 text-white border-y border-gray-800">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
        <h2 class="text-2xl sm:text-3xl font-extrabold mb-4">Cite this index</h2>
        <p class="text-gray-400 mb-6">For papers, articles, and reports — use the citation below. Direct URL backlinks are appreciated and help the index grow.</p>
        <pre class="bg-black/40 rounded-xl p-5 text-sm text-gray-100 overflow-x-auto"><code>Cardify (2026). <em>GCC Business Index 2026.</em>
Retrieved from https://cardify.om/gcc-business-index
License: CC BY 4.0 — https://creativecommons.org/licenses/by/4.0/</code></pre>
        <div class="mt-5 flex flex-wrap gap-3 text-sm">
            <a href="mailto:press@cardify.om" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-white text-gray-900 font-semibold hover:bg-gray-100 transition">
                <i class="fa-solid fa-envelope text-xs"></i>
                Press contact
            </a>
            <a href="/api/logos/stats" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-white/10 border border-white/20 text-white font-semibold hover:bg-white/20 transition">
                <i class="fa-solid fa-code text-xs"></i>
                JSON API
            </a>
            <a href="/logos/press" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-white/10 border border-white/20 text-white font-semibold hover:bg-white/20 transition">
                Press kit
            </a>
        </div>
    </div>
</section>

<!-- ============ FAQ ============ -->
<section class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-20">
    <p class="text-xs uppercase tracking-wider text-blue-700 font-semibold mb-2">FAQ</p>
    <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-900 mb-2">Frequently asked</h2>
    <p class="text-gray-600 mb-8">Licensing, data provenance, API access, country roadmap.</p>
    <div class="space-y-3">
        <?php foreach ($faq as $i => $q): ?>
            <details class="group bg-white border border-gray-200 rounded-xl hover:border-blue-300 transition overflow-hidden"<?= $i === 0 ? ' open' : '' ?>>
                <summary class="flex items-center justify-between gap-4 px-5 py-4 cursor-pointer list-none">
                    <span class="font-semibold text-gray-900"><?= gccEsc($q['q']) ?></span>
                    <i class="fa-solid fa-chevron-down text-gray-400 text-sm transition group-open:rotate-180"></i>
                </summary>
                <div class="px-5 pb-4 text-gray-600 leading-relaxed">
                    <?= gccEsc($q['a']) ?>
                </div>
            </details>
        <?php endforeach; ?>
    </div>
</section>

<!-- ============ FOOTER META ============ -->
<section class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-10 text-center text-sm text-gray-500">
    <p>
        <span class="inline-flex items-center gap-1.5">
            <i class="fa-regular fa-calendar"></i>
            Last verified <time datetime="<?= gccEsc($lastUpdated) ?>"><?= gccEsc($lastUpdatedHuman) ?></time>
        </span>
        <span class="mx-3 text-gray-300">·</span>
        <span>Published by Cardify · CC BY 4.0</span>
        <span class="mx-3 text-gray-300">·</span>
        <a href="/logo-takedown" class="hover:text-gray-700 underline underline-offset-2">Correction or takedown</a>
    </p>
</section>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
