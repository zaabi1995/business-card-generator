<?php
/**
 * Cardify, GCC Business Index 2026 (Flagship Landing Page)
 *
 * Research-grade overview of business infrastructure across the GCC:
 * Saudi Arabia, UAE, Qatar, Bahrain, Kuwait, Oman. Designed as a press /
 * backlink magnet, the kind of page Reuters, Bloomberg GCC, Gulf Business,
 * or The National link to when citing regional company counts.
 *
 * Route: /gcc-business-index
 * Public, cacheable.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Datasets.php';
require_once INCLUDES_DIR . '/Auth.php';

$db = Database::getInstance();

$pageTitle       = t('gccbi.page_title');
$pageDescription = t('gccbi.page_desc');
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

// Country status, hand-curated. 'data' marks what's shipped today.
$countries = [
    [
        'code'      => 'OM',
        'country_slug' => 'oman',
        'name_key'  => 'gccbi.country_om_name',
        'name_en'   => 'Oman',
        'name_ar'   => 'عُمان',
        'flag'      => '🇴🇲',
        'capital_key' => 'gccbi.country_om_capital',
        'gdp_usd_bn' => 114.7,
        'population_m' => 4.98,
        'companies_approx' => $omStats['companies'],
        'sectors'   => 23,
        'regions'   => 11,
        'index_url' => '/oman-business-index',
        'logos_url' => '/logos',
        'logos_count' => $omStats['logos'],
        'status'    => 'live',
        'note_key'  => 'gccbi.note_om',
    ],
    [
        'code'      => 'SA',
        'country_slug' => 'saudi-arabia',
        'name_key'  => 'gccbi.country_sa_name',
        'name_en'   => 'Saudi Arabia',
        'name_ar'   => 'المملكة العربية السعودية',
        'flag'      => '🇸🇦',
        'capital_key' => 'gccbi.country_sa_capital',
        'gdp_usd_bn' => 1067.6,
        'population_m' => 33.0,
        'companies_approx' => null,
        'sectors'   => null,
        'regions'   => 13,
        'index_url' => null,
        'logos_url' => null,
        'logos_count' => 0,
        'status'    => 'coming',
        'note_key'  => 'gccbi.note_sa',
    ],
    [
        'code'      => 'AE',
        'country_slug' => 'uae',
        'name_key'  => 'gccbi.country_ae_name',
        'name_en'   => 'United Arab Emirates',
        'name_ar'   => 'الإمارات العربية المتحدة',
        'flag'      => '🇦🇪',
        'capital_key' => 'gccbi.country_ae_capital',
        'gdp_usd_bn' => 507.5,
        'population_m' => 10.2,
        'companies_approx' => null,
        'sectors'   => null,
        'regions'   => 7,
        'index_url' => null,
        'logos_url' => null,
        'logos_count' => 0,
        'status'    => 'coming',
        'note_key'  => 'gccbi.note_ae',
    ],
    [
        'code'      => 'QA',
        'country_slug' => 'qatar',
        'name_key'  => 'gccbi.country_qa_name',
        'name_en'   => 'Qatar',
        'name_ar'   => 'قطر',
        'flag'      => '🇶🇦',
        'capital_key' => 'gccbi.country_qa_capital',
        'gdp_usd_bn' => 221.4,
        'population_m' => 3.05,
        'companies_approx' => null,
        'sectors'   => null,
        'regions'   => 8,
        'index_url' => null,
        'logos_url' => null,
        'logos_count' => 0,
        'status'    => 'coming',
        'note_key'  => 'gccbi.note_qa',
    ],
    [
        'code'      => 'BH',
        'country_slug' => 'bahrain',
        'name_key'  => 'gccbi.country_bh_name',
        'name_en'   => 'Bahrain',
        'name_ar'   => 'البحرين',
        'flag'      => '🇧🇭',
        'capital_key' => 'gccbi.country_bh_capital',
        'gdp_usd_bn' => 43.2,
        'population_m' => 1.54,
        'companies_approx' => null,
        'sectors'   => null,
        'regions'   => 4,
        'index_url' => null,
        'logos_url' => null,
        'logos_count' => 0,
        'status'    => 'coming',
        'note_key'  => 'gccbi.note_bh',
    ],
    [
        'code'      => 'KW',
        'country_slug' => 'kuwait',
        'name_key'  => 'gccbi.country_kw_name',
        'name_en'   => 'Kuwait',
        'name_ar'   => 'الكويت',
        'flag'      => '🇰🇼',
        'capital_key' => 'gccbi.country_kw_capital',
        'gdp_usd_bn' => 161.8,
        'population_m' => 4.26,
        'companies_approx' => null,
        'sectors'   => null,
        'regions'   => 6,
        'index_url' => null,
        'logos_url' => null,
        'logos_count' => 0,
        'status'    => 'coming',
        'note_key'  => 'gccbi.note_kw',
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
    ['q' => t('gccbi.faq1_q'), 'a' => t('gccbi.faq1_a')],
    ['q' => t('gccbi.faq2_q'), 'a' => t('gccbi.faq2_a')],
    ['q' => t('gccbi.faq3_q'), 'a' => t('gccbi.faq3_a')],
    ['q' => t('gccbi.faq4_q'), 'a' => t('gccbi.faq4_a')],
    ['q' => t('gccbi.faq5_q'), 'a' => t('gccbi.faq5_a')],
    ['q' => t('gccbi.faq6_q'), 'a' => t('gccbi.faq6_a')],
    ['q' => t('gccbi.faq7_q'), 'a' => t('gccbi.faq7_a')],
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

// r59/llm27-37: @id, name, licence, creator, publisher and distribution are
// owned by includes/Datasets.php. Before r59 this node was anonymous and /press
// described the same dataset under the shorter name "GCC Business Index", so
// the two could not be joined.
$datasetLd = Datasets::node('gcc', [
    'description'    => 'Federated open index of business infrastructure across the six Gulf Cooperation Council states: company registers, sovereign-entity directories, and brand logo archives.',
    'keywords'       => 'GCC, Saudi Arabia, UAE, Qatar, Bahrain, Kuwait, Oman, business register, companies, logos, sovereign, government, MENA',
    'temporalCoverage' => '2026/..',
    'spatialCoverage'  => [
        ['@type' => 'Country', 'name' => 'Saudi Arabia', 'address' => ['@type' => 'PostalAddress', 'addressCountry' => 'SA']],
        ['@type' => 'Country', 'name' => 'United Arab Emirates', 'address' => ['@type' => 'PostalAddress', 'addressCountry' => 'AE']],
        ['@type' => 'Country', 'name' => 'Qatar', 'address' => ['@type' => 'PostalAddress', 'addressCountry' => 'QA']],
        ['@type' => 'Country', 'name' => 'Bahrain', 'address' => ['@type' => 'PostalAddress', 'addressCountry' => 'BH']],
        ['@type' => 'Country', 'name' => 'Kuwait', 'address' => ['@type' => 'PostalAddress', 'addressCountry' => 'KW']],
        ['@type' => 'Country', 'name' => 'Oman', 'address' => ['@type' => 'PostalAddress', 'addressCountry' => 'OM']],
    ],
    'dateModified'   => $lastUpdated,
]);

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
    . '<script type="application/ld+json">' . json_encode($orgLd,     JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';

function gccEsc($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

require_once INCLUDES_DIR . '/ui-header.php';
?>

<!-- ============ HERO ============ -->
<section class="relative overflow-hidden bg-gradient-to-b from-blue-50 via-white to-white border-b border-gray-100">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-20 lg:py-28">
        <div class="text-center max-w-3xl mx-auto">
            <p class="text-xs uppercase tracking-wider text-blue-700 font-semibold mb-4">
                <?= gccEsc(t('gccbi.hero_eyebrow')) ?>
            </p>
            <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold text-gray-900 mb-5 leading-tight">
                <?= gccEsc(t('gccbi.hero_h1')) ?> <span class="text-blue-600"><?= gccEsc(t('gccbi.hero_year')) ?></span>
            </h1>
            <p class="text-lg sm:text-xl text-gray-600 mb-8 max-w-2xl mx-auto">
                <?= gccEsc(t('gccbi.hero_sub')) ?>
            </p>

            <div class="flex flex-wrap gap-3 justify-center mb-10">
                <a href="#countries" class="inline-flex items-center gap-2 px-6 py-3 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-semibold shadow-lg shadow-blue-600/30 transition">
                    <?= gccEsc(t('gccbi.hero_cta_explore')) ?>
                    <i class="fa-solid fa-arrow-down text-xs"></i>
                </a>
                <a href="/oman-business-index" class="inline-flex items-center gap-2 px-6 py-3 rounded-lg bg-white border border-gray-300 text-gray-800 font-semibold hover:border-blue-300 hover:text-blue-700 transition">
                    <?= gccEsc(t('gccbi.hero_cta_oman')) ?>
                    <i class="fa-solid fa-arrow-right text-xs"></i>
                </a>
            </div>

            <!-- Headline stats -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 sm:gap-6 max-w-3xl mx-auto">
                <div>
                    <div class="text-3xl sm:text-4xl font-extrabold text-blue-600">6</div>
                    <div class="text-xs sm:text-sm text-gray-500 mt-1 uppercase tracking-wide"><?= gccEsc(t('gccbi.stat_countries')) ?></div>
                </div>
                <div>
                    <div class="text-3xl sm:text-4xl font-extrabold text-blue-600"><?= number_format($totalGdp, 0) ?>B</div>
                    <div class="text-xs sm:text-sm text-gray-500 mt-1 uppercase tracking-wide"><?= gccEsc(t('gccbi.stat_gdp')) ?></div>
                </div>
                <div>
                    <div class="text-3xl sm:text-4xl font-extrabold text-blue-600"><?= number_format($totalPop, 1) ?>M</div>
                    <div class="text-xs sm:text-sm text-gray-500 mt-1 uppercase tracking-wide"><?= gccEsc(t('gccbi.stat_population')) ?></div>
                </div>
                <div>
                    <div class="text-3xl sm:text-4xl font-extrabold text-blue-600"><?= number_format($omStats['logos']) ?>+</div>
                    <div class="text-xs sm:text-sm text-gray-500 mt-1 uppercase tracking-wide"><?= gccEsc(t('gccbi.stat_logos')) ?></div>
                </div>
            </div>

            <p class="mt-8 text-xs text-gray-400">
                <i class="fa-regular fa-calendar mr-1"></i>
                <?= gccEsc(t('gccbi.last_updated')) ?> <time datetime="<?= gccEsc($lastUpdated) ?>"><?= gccEsc($lastUpdatedHuman) ?></time>
                · <?= gccEsc(t('gccbi.license')) ?> <a href="https://creativecommons.org/licenses/by/4.0/" class="underline hover:text-blue-600">CC BY 4.0</a>
            </p>
        </div>
    </div>
</section>

<!-- ============ COUNTRIES ============ -->
<section id="countries" class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-20 scroll-mt-24">
    <div class="max-w-3xl mb-10">
        <p class="text-xs uppercase tracking-wider text-blue-700 font-semibold mb-2"><?= gccEsc(t('gccbi.countries_eyebrow')) ?></p>
        <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-900 mb-3"><?= gccEsc(t('gccbi.countries_heading')) ?></h2>
        <p class="text-lg text-gray-600">
            <?= gccEsc(t('gccbi.countries_sub')) ?>
        </p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        <?php foreach ($countries as $c):
            $isLive = $c['status'] === 'live';
            $borderCls = $isLive ? 'border-emerald-300 bg-gradient-to-br from-emerald-50 to-white' : 'border-gray-200 bg-white';
            $badgeCls  = $isLive ? 'bg-emerald-100 text-emerald-700 ring-emerald-200' : 'bg-gray-100 text-gray-600 ring-gray-200';
            $badgeText = $isLive ? t('gccbi.badge_live') : t('gccbi.badge_coming');
            $countryName = t($c['name_key']);
        ?>
            <article class="rounded-2xl border-2 <?= $borderCls ?> p-6 flex flex-col">
                <div class="flex items-start justify-between mb-4">
                    <div class="flex items-center gap-3">
                        <div class="text-4xl leading-none"><?= $c['flag'] ?></div>
                        <div>
                            <h3 class="text-lg font-bold text-gray-900"><?= gccEsc($countryName) ?></h3>
                            <p class="text-xs text-gray-500" dir="rtl"><?= gccEsc($c['name_ar']) ?></p>
                        </div>
                    </div>
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full ring-1 ring-inset text-[10px] font-semibold <?= $badgeCls ?>">
                        <?= gccEsc($badgeText) ?>
                    </span>
                </div>

                <dl class="grid grid-cols-2 gap-3 mb-4 text-sm">
                    <div>
                        <dt class="text-xs text-gray-500"><?= gccEsc(t('gccbi.lbl_capital')) ?></dt>
                        <dd class="font-semibold text-gray-900"><?= gccEsc(t($c['capital_key'])) ?></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500"><?= gccEsc(t('gccbi.lbl_gdp')) ?></dt>
                        <dd class="font-semibold text-gray-900">$<?= number_format($c['gdp_usd_bn'], 1) ?>B</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500"><?= gccEsc(t('gccbi.lbl_population')) ?></dt>
                        <dd class="font-semibold text-gray-900"><?= number_format($c['population_m'], 2) ?>M</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500"><?= gccEsc(t('gccbi.lbl_logos')) ?></dt>
                        <dd class="font-semibold text-gray-900"><?= number_format($c['logos_count']) ?></dd>
                    </div>
                </dl>

                <p class="text-sm text-gray-600 leading-relaxed mb-5 flex-grow"><?= gccEsc(t($c['note_key'])) ?></p>

                <?php $countrySlug = $c['country_slug'] ?? strtolower($c['code']); ?>
                <?php if ($isLive): ?>
                    <div class="flex flex-wrap gap-2">
                        <?php if ($c['index_url']): ?>
                            <a href="<?= gccEsc(getBasePath() . ltrim($c['index_url'], '/')) ?>" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold transition">
                                <?= gccEsc(t('gccbi.btn_open_index')) ?> <i class="fa-solid fa-arrow-right text-xs"></i>
                            </a>
                        <?php endif; ?>
                        <?php if ($c['logos_url']): ?>
                            <a href="<?= gccEsc(getBasePath() . ltrim($c['logos_url'], '/')) ?>" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-white border border-gray-200 text-gray-700 hover:border-blue-300 hover:text-blue-600 text-sm font-semibold transition">
                                <?= gccEsc(t('gccbi.btn_logo_library')) ?>
                            </a>
                        <?php endif; ?>
                        <a href="<?= gccEsc(getBasePath() . 'gcc/' . $countrySlug) ?>" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-white border border-gray-200 text-gray-700 hover:border-blue-300 hover:text-blue-600 text-sm font-semibold transition">
                            <?= gccEsc(t('gccbi.btn_country_page')) ?>
                        </a>
                    </div>
                <?php else: ?>
                    <div class="flex flex-wrap gap-2 items-center">
                        <a href="<?= gccEsc(getBasePath() . 'gcc/' . $countrySlug) ?>" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold transition">
                            <?= gccEsc(t('gccbi.btn_country_preview', ['name' => $countryName])) ?> <i class="fa-solid fa-arrow-right text-xs"></i>
                        </a>
                        <a href="mailto:contact@cardify.om?subject=<?= urlencode(t('gccbi.email_subject_prefix', ['name' => $c['name_en']])) ?>" class="inline-flex items-center gap-1.5 text-sm font-semibold text-blue-600 hover:text-blue-700">
                            <i class="fa-solid fa-envelope text-xs"></i> <?= gccEsc(t('gccbi.btn_get_notified')) ?>
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
        <p class="text-xs uppercase tracking-wider text-blue-700 font-semibold mb-2"><?= gccEsc(t('gccbi.method_eyebrow')) ?></p>
        <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-900 mb-5"><?= gccEsc(t('gccbi.method_heading')) ?></h2>
        <p class="text-lg text-gray-600 mb-8">
            <?= gccEsc(t('gccbi.method_sub')) ?>
        </p>
        <ol class="space-y-5">
            <?php foreach ([
                ['gccbi.method_step1_title', 'gccbi.method_step1_body'],
                ['gccbi.method_step2_title', 'gccbi.method_step2_body'],
                ['gccbi.method_step3_title', 'gccbi.method_step3_body'],
                ['gccbi.method_step4_title', 'gccbi.method_step4_body'],
            ] as $i => [$titleKey, $bodyKey]): ?>
                <li class="flex gap-5 bg-white rounded-xl border border-gray-200 p-5">
                    <div class="shrink-0 w-10 h-10 rounded-lg bg-blue-600 text-white flex items-center justify-center font-bold"><?= $i + 1 ?></div>
                    <div>
                        <h3 class="font-bold text-gray-900 mb-1"><?= gccEsc(t($titleKey)) ?></h3>
                        <p class="text-gray-600 leading-relaxed"><?= gccEsc(t($bodyKey)) ?></p>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>
</section>

<!-- ============ USE CASES ============ -->
<section class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-20">
    <div class="max-w-3xl mb-10">
        <p class="text-xs uppercase tracking-wider text-blue-700 font-semibold mb-2"><?= gccEsc(t('gccbi.uc_eyebrow')) ?></p>
        <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-900 mb-3"><?= gccEsc(t('gccbi.uc_heading')) ?></h2>
        <p class="text-lg text-gray-600">
            <?= gccEsc(t('gccbi.uc_sub')) ?>
        </p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5">
        <?php foreach ([
            ['fa-newspaper',  'gccbi.uc1_title', 'gccbi.uc1_body'],
            ['fa-chart-line', 'gccbi.uc2_title', 'gccbi.uc2_body'],
            ['fa-briefcase',  'gccbi.uc3_title', 'gccbi.uc3_body'],
            ['fa-pen-ruler',  'gccbi.uc4_title', 'gccbi.uc4_body'],
        ] as [$icon, $titleKey, $bodyKey]): ?>
            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <div class="w-10 h-10 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center mb-3">
                    <i class="fa-solid <?= $icon ?>"></i>
                </div>
                <h3 class="font-bold text-gray-900 mb-1"><?= gccEsc(t($titleKey)) ?></h3>
                <p class="text-sm text-gray-600 leading-relaxed"><?= gccEsc(t($bodyKey)) ?></p>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- ============ CITE ============ -->
<section class="bg-gray-900 text-white border-y border-gray-800">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
        <h2 class="text-2xl sm:text-3xl font-extrabold mb-4"><?= gccEsc(t('gccbi.cite_heading')) ?></h2>
        <p class="text-gray-400 mb-6"><?= gccEsc(t('gccbi.cite_sub')) ?></p>
        <pre class="bg-black/40 rounded-xl p-5 text-sm text-gray-100 overflow-x-auto"><code>Cardify (2026). <em>GCC Business Index 2026.</em>
Retrieved from https://cardify.om/gcc-business-index
License: CC BY 4.0 - https://creativecommons.org/licenses/by/4.0/</code></pre>
        <div class="mt-5 flex flex-wrap gap-3 text-sm">
            <a href="mailto:press@cardify.om" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-white text-gray-900 font-semibold hover:bg-gray-100 transition">
                <i class="fa-solid fa-envelope text-xs"></i>
                <?= gccEsc(t('gccbi.cite_press')) ?>
            </a>
            <a href="/api/logos/stats" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-white/10 border border-white/20 text-white font-semibold hover:bg-white/20 transition">
                <i class="fa-solid fa-code text-xs"></i>
                <?= gccEsc(t('gccbi.cite_api')) ?>
            </a>
            <a href="/logos/press" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-white/10 border border-white/20 text-white font-semibold hover:bg-white/20 transition">
                <?= gccEsc(t('gccbi.cite_press_kit')) ?>
            </a>
        </div>
    </div>
</section>

<!-- ============ FAQ ============ -->
<section class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-20">
    <p class="text-xs uppercase tracking-wider text-blue-700 font-semibold mb-2"><?= gccEsc(t('gccbi.faq_eyebrow')) ?></p>
    <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-900 mb-2"><?= gccEsc(t('gccbi.faq_heading')) ?></h2>
    <p class="text-gray-600 mb-8"><?= gccEsc(t('gccbi.faq_sub')) ?></p>
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
            <?= gccEsc(t('gccbi.footer_last_verified')) ?> <time datetime="<?= gccEsc($lastUpdated) ?>"><?= gccEsc($lastUpdatedHuman) ?></time>
        </span>
        <span class="mx-3 text-gray-300">·</span>
        <span><?= gccEsc(t('gccbi.footer_published_by')) ?></span>
        <span class="mx-3 text-gray-300">·</span>
        <a href="/logo-takedown" class="hover:text-gray-700 underline underline-offset-2"><?= gccEsc(t('gccbi.footer_correction')) ?></a>
    </p>
</section>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
