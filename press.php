<?php
/**
 * Cardify, Press & Media Kit
 *
 * Backlink-worthy flagship asset. Journalists, researchers, analysts,
 * and other professionals cite this page when they need:
 *   - A one-line factual description of Cardify
 *   - Downloadable logo / brand assets
 *   - A summary of what data we publish publicly (with links)
 *   - Quotable pull stats, every figure read from the live tables at render
 *     time. No count is hardcoded here or in a comment: a number frozen in
 *     prose is an unversioned claim that rots on the next import.
 *   - A press contact
 *
 * Also stands in as an "About Cardify" for LLMs and AI answer engines
 * that prefer structured fact pages over marketing content.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Datasets.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle       = t('press.page_title');
$pageDescription = t('press.page_desc');
$canonicalUrl    = 'https://cardify.om/press-kit';
$showNavigation  = true;

$db = Database::getInstance();
// r20-27: this page was outside the fix's surface list and published a
// hardcoded 79/80+ against a real 106. A fallback literal IS a hardcoded
// public stat, so both start null and the copy loses the number if the
// query cannot run.
// r103: the sovereign tile was the third stat on this grid and the only one
// still typed into the markup as a literal 31, against a real 26. Two counts
// beside it were already live, which is what made the literal invisible: the
// row reads as a set of measured figures. Same null-not-fallback rule -- if
// the query cannot run the copy loses the number rather than inventing one.
$companiesCount = null;
$logosCount     = null;
$sovereignCount = null;
try {
    $r = $db->fetchOne("SELECT COUNT(*) c FROM om_companies");
    if ($r) $companiesCount = (int) $r['c'];
    $l = $db->fetchOne("SELECT COUNT(*) c FROM om_companies WHERE logo_status IN ('indexed','verified')");
    if ($l) $logosCount = (int) $l['c'];
    $s = $db->fetchOne("SELECT COUNT(*) c FROM om_companies WHERE sector='government-defense' AND summary_en <> '' AND summary_ar <> ''");
    if ($s) $sovereignCount = (int) $s['c'];
} catch (Throwable $e) {}

$esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

$orgLd = [
    '@context' => 'https://schema.org',
    '@type'    => 'Organization',
    'name'     => 'Cardify',
    'url'      => 'https://cardify.om/',
    'logo'     => 'https://cardify.om/assets/images/logo.svg',
    'description' => 'Business-identity platform for the GCC: digital and printed business cards, public logo libraries, and the GCC Business Index. Built in Oman, expanding across Saudi Arabia, UAE, Qatar, Bahrain, and Kuwait.',
    'foundingDate' => '2024',
    'foundingLocation' => [
        '@type' => 'Place',
        'address' => ['@type' => 'PostalAddress', 'addressCountry' => 'OM', 'addressLocality' => 'Muscat'],
    ],
    'contactPoint' => [
        '@type' => 'ContactPoint',
        'contactType' => 'public relations',
        'email' => 'press@cardify.om',
        'url' => 'https://cardify.om/contact',
        'availableLanguage' => ['en', 'ar'],
    ],
    'sameAs' => ['https://instagram.com/cardifyom'],
    // Same entity as the homepage node, so it carries the same @id and the
    // same parent edge. Two unlinked Organization nodes for one company read
    // as two companies.
    '@id' => 'https://cardify.om/#organization',
    'parentOrganization' => ['@id' => 'https://bhd.om/#organization'],
];

$datasetsLd = [
    '@context' => 'https://schema.org',
    '@type'    => 'Collection',
    'name'     => 'Cardify Public Datasets',
    'description' => 'Open datasets published by Cardify for researchers, journalists, and analysts covering business identity across the GCC.',
    'url'      => $canonicalUrl,
    // r59/llm27-37: these three were full re-definitions with no @id, under
    // names ("Oman Business Index", "GCC Business Index") that did not match
    // the names their own home pages published, so each dataset existed twice
    // with nothing to join the halves. They now carry the @id of the node on
    // the dataset's home page: one entity, described twice. The description
    // stays local because it is written for this page's audience; every key
    // that carries identity comes from includes/Datasets.php.
    // r59/llm27-36: the logo-library entry declared no distribution while its
    // own description promised SVG and PNG downloads. brief() carries the
    // measured distribution, so the promise now resolves here too.
    'hasPart'  => [
        Datasets::brief('obi', [
            'description' => 'Open structured index of Omani companies with CR numbers, sector, wilayat, and trade names.',
        ]),
        Datasets::brief('logos', [
            'description' => 'Logos of Omani companies, ministries, and sovereign entities, downloadable in SVG, PNG and WebP.',
        ]),
        Datasets::brief('gcc', [
            'description' => 'Federated research-grade overview of business infrastructure across all six GCC states.',
        ]),
    ],
];

$breadcrumbLd = [
    '@context' => 'https://schema.org',
    '@type'    => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://cardify.om/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Press', 'item' => $canonicalUrl],
    ],
];

$extraHead = ''
    . '<script type="application/ld+json">' . json_encode($orgLd,        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n"
    . '<script type="application/ld+json">' . json_encode($datasetsLd,   JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n"
    . '<script type="application/ld+json">' . json_encode($breadcrumbLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';

$asOfDate = date('F j, Y');
$accessedDate = date('j M Y');

require_once INCLUDES_DIR . '/ui-header.php';
?>
<div class="min-h-screen bg-white">
    <section class="relative overflow-hidden bg-gradient-to-b from-blue-50 via-white to-white border-b border-gray-100 pt-28 pb-16">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            <nav class="text-sm text-gray-500 mb-4" aria-label="Breadcrumb">
                <a href="<?= getBasePath() ?>" class="hover:text-blue-600 transition"><?= htmlspecialchars(t('press.crumb_home')) ?></a>
                <span class="mx-2">/</span>
                <span class="text-gray-700"><?= htmlspecialchars(t('press.crumb_press')) ?></span>
            </nav>
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white border border-blue-200 text-blue-700 text-xs font-semibold tracking-wide uppercase shadow-sm mb-5">
                <i class="fa-solid fa-newspaper text-[10px]"></i> <?= htmlspecialchars(t('press.badge')) ?>
            </div>
            <h1 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold leading-tight text-gray-900 mb-4"><?= htmlspecialchars(t('press.h1')) ?></h1>
            <p class="text-gray-600 text-lg max-w-3xl leading-relaxed mb-7"><?= htmlspecialchars(t('press.hero_sub', ['date' => $asOfDate])) ?></p>
            <div class="flex flex-wrap gap-3">
                <a href="#facts" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-blue-600 text-white font-semibold shadow-sm hover:bg-blue-700 transition">
                    <i class="fa-solid fa-circle-info text-xs"></i> <?= htmlspecialchars(t('press.nav_facts')) ?>
                </a>
                <a href="#datasets" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-white border border-gray-200 text-gray-700 font-semibold shadow-sm hover:border-blue-300 hover:text-blue-700 transition">
                    <i class="fa-solid fa-database text-xs"></i> <?= htmlspecialchars(t('press.nav_datasets')) ?>
                </a>
                <a href="#assets" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-white border border-gray-200 text-gray-700 font-semibold shadow-sm hover:border-blue-300 hover:text-blue-700 transition">
                    <i class="fa-solid fa-image text-xs"></i> <?= htmlspecialchars(t('press.nav_assets')) ?>
                </a>
                <a href="#contact" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-white border border-gray-200 text-gray-700 font-semibold shadow-sm hover:border-blue-300 hover:text-blue-700 transition">
                    <i class="fa-solid fa-envelope text-xs"></i> <?= htmlspecialchars(t('press.nav_contact')) ?>
                </a>
            </div>
        </div>
    </section>

    <section id="facts" class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
        <div class="mb-10">
            <span class="text-blue-700 font-semibold text-xs uppercase tracking-wider"><?= htmlspecialchars(t('press.section_official')) ?></span>
            <h2 class="text-2xl sm:text-3xl font-extrabold text-gray-900 mt-2 mb-5"><?= htmlspecialchars(t('press.h2_oneliner')) ?></h2>
            <blockquote class="bg-gradient-to-br from-blue-50 to-indigo-50 border border-blue-100 rounded-2xl p-6 sm:p-8 text-gray-800 text-lg leading-relaxed shadow-sm">
                <i class="fa-solid fa-quote-left text-blue-300 text-2xl mb-3 block"></i>
                <?= htmlspecialchars(t('press.oneliner_body')) ?>
            </blockquote>
        </div>

        <div>
            <span class="text-blue-700 font-semibold text-xs uppercase tracking-wider"><?= htmlspecialchars(t('press.section_keyfacts')) ?></span>
            <h2 class="text-2xl sm:text-3xl font-extrabold text-gray-900 mt-2 mb-6"><?= htmlspecialchars(t('press.h2_numbers')) ?></h2>
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <div class="bg-white rounded-2xl p-6 border border-gray-200 shadow-sm hover:shadow-md hover:border-blue-200 transition">
                    <div class="text-3xl font-extrabold text-gray-900"><?= $companiesCount !== null ? number_format($companiesCount) : '&mdash;' ?></div>
                    <div class="text-sm text-gray-600 mt-1.5 leading-relaxed"><?= htmlspecialchars(t('press.stat_companies')) ?></div>
                </div>
                <div class="bg-white rounded-2xl p-6 border border-gray-200 shadow-sm hover:shadow-md hover:border-blue-200 transition">
                    <div class="text-3xl font-extrabold text-gray-900"><?= $logosCount !== null ? number_format($logosCount) : '&mdash;' ?></div>
                    <div class="text-sm text-gray-600 mt-1.5 leading-relaxed"><?= htmlspecialchars(t('press.stat_logos')) ?></div>
                </div>
                <div class="bg-white rounded-2xl p-6 border border-gray-200 shadow-sm hover:shadow-md hover:border-blue-200 transition">
                    <div class="text-3xl font-extrabold text-gray-900"><?= $sovereignCount !== null ? number_format($sovereignCount) : '&mdash;' ?></div>
                    <div class="text-sm text-gray-600 mt-1.5 leading-relaxed"><?= htmlspecialchars(t('press.stat_sovereign')) ?></div>
                </div>
                <div class="bg-white rounded-2xl p-6 border border-gray-200 shadow-sm hover:shadow-md hover:border-blue-200 transition">
                    <div class="text-3xl font-extrabold text-gray-900">6</div>
                    <div class="text-sm text-gray-600 mt-1.5 leading-relaxed"><?= htmlspecialchars(t('press.stat_gcc')) ?></div>
                </div>
                <div class="bg-white rounded-2xl p-6 border border-gray-200 shadow-sm hover:shadow-md hover:border-blue-200 transition">
                    <div class="text-3xl font-extrabold text-gray-900">EN<span class="text-gray-400 mx-1">+</span>AR</div>
                    <div class="text-sm text-gray-600 mt-1.5 leading-relaxed"><?= htmlspecialchars(t('press.stat_bilingual')) ?></div>
                </div>
                <div class="bg-white rounded-2xl p-6 border border-gray-200 shadow-sm hover:shadow-md hover:border-blue-200 transition">
                    <div class="text-3xl font-extrabold text-gray-900">2024</div>
                    <div class="text-sm text-gray-600 mt-1.5 leading-relaxed"><?= htmlspecialchars(t('press.stat_year')) ?></div>
                </div>
            </div>
        </div>
    </section>

    <section id="datasets" class="bg-gray-50 border-y border-gray-100 py-16">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            <span class="text-blue-700 font-semibold text-xs uppercase tracking-wider"><?= htmlspecialchars(t('press.section_datasets')) ?></span>
            <h2 class="text-2xl sm:text-3xl font-extrabold text-gray-900 mt-2 mb-3"><?= htmlspecialchars(t('press.h2_datasets')) ?></h2>
            <p class="text-gray-600 mb-8 max-w-3xl"><?= htmlspecialchars(t('press.datasets_intro')) ?></p>

            <div class="space-y-5">
                <article class="bg-white rounded-2xl border border-gray-200 shadow-sm hover:shadow-md hover:border-blue-200 p-6 sm:p-7 transition">
                    <div class="flex flex-wrap items-start justify-between gap-4 mb-4">
                        <div class="flex items-start gap-4">
                            <div class="w-12 h-12 shrink-0 rounded-xl bg-blue-50 border border-blue-100 flex items-center justify-center text-blue-600">
                                <i class="fa-solid fa-building-columns text-lg"></i>
                            </div>
                            <div>
                                <h3 class="text-xl font-bold text-gray-900"><?= htmlspecialchars(t('press.ds_oman_title')) ?></h3>
                                <p class="text-gray-500 text-sm mt-0.5"><?= htmlspecialchars(t('press.ds_oman_sub')) ?></p>
                            </div>
                        </div>
                        <span class="inline-flex items-center gap-1 bg-green-50 text-green-700 border border-green-100 rounded-full px-3 py-1 text-xs font-semibold">
                            <i class="fa-solid fa-circle-check text-[10px]"></i> <?= htmlspecialchars(t('press.ds_cc')) ?>
                        </span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="<?= getBasePath() ?>oman-business-index" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold shadow-sm transition"><?= htmlspecialchars(t('press.btn_view_page')) ?> <i class="fa-solid fa-arrow-right text-[10px]"></i></a>
                        <a href="<?= getBasePath() ?>companies" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-white border border-gray-200 hover:border-blue-300 text-gray-700 hover:text-blue-700 text-sm font-semibold transition"><?= htmlspecialchars(t('press.btn_browse_companies')) ?></a>
                    </div>
                </article>

                <article class="bg-white rounded-2xl border border-gray-200 shadow-sm hover:shadow-md hover:border-blue-200 p-6 sm:p-7 transition">
                    <div class="flex flex-wrap items-start justify-between gap-4 mb-4">
                        <div class="flex items-start gap-4">
                            <div class="w-12 h-12 shrink-0 rounded-xl bg-indigo-50 border border-indigo-100 flex items-center justify-center text-indigo-600">
                                <i class="fa-solid fa-image text-lg"></i>
                            </div>
                            <div>
                                <h3 class="text-xl font-bold text-gray-900"><?= htmlspecialchars(t('press.ds_logos_title')) ?></h3>
                                <p class="text-gray-500 text-sm mt-0.5"><?= htmlspecialchars($logosCount !== null ? t('press.ds_logos_sub', ['logos' => number_format($logosCount)]) : t('press.ds_logos_sub_nc')) ?></p>
                            </div>
                        </div>
                        <span class="inline-flex items-center gap-1 bg-amber-50 text-amber-700 border border-amber-100 rounded-full px-3 py-1 text-xs font-semibold">
                            <i class="fa-solid fa-scale-balanced text-[10px]"></i> <?= htmlspecialchars(t('press.ds_fairuse')) ?>
                        </span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="<?= getBasePath() ?>logos" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold shadow-sm transition"><?= htmlspecialchars(t('press.btn_browse_library')) ?> <i class="fa-solid fa-arrow-right text-[10px]"></i></a>
                        <a href="<?= getBasePath() ?>logos/terms" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-white border border-gray-200 hover:border-blue-300 text-gray-700 hover:text-blue-700 text-sm font-semibold transition"><?= htmlspecialchars(t('press.btn_terms')) ?></a>
                        <a href="<?= getBasePath() ?>api/logos" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-white border border-gray-200 hover:border-blue-300 text-gray-700 hover:text-blue-700 text-sm font-semibold transition"><i class="fa-solid fa-code text-[10px]"></i> <?= htmlspecialchars(t('press.btn_json')) ?></a>
                    </div>
                </article>

                <article class="bg-white rounded-2xl border border-gray-200 shadow-sm hover:shadow-md hover:border-blue-200 p-6 sm:p-7 transition">
                    <div class="flex flex-wrap items-start justify-between gap-4 mb-4">
                        <div class="flex items-start gap-4">
                            <div class="w-12 h-12 shrink-0 rounded-xl bg-purple-50 border border-purple-100 flex items-center justify-center text-purple-600">
                                <i class="fa-solid fa-globe text-lg"></i>
                            </div>
                            <div>
                                <h3 class="text-xl font-bold text-gray-900"><?= htmlspecialchars(t('press.ds_gcc_title')) ?></h3>
                                <p class="text-gray-500 text-sm mt-0.5"><?= htmlspecialchars(t('press.ds_gcc_sub')) ?></p>
                            </div>
                        </div>
                        <span class="inline-flex items-center gap-1 bg-green-50 text-green-700 border border-green-100 rounded-full px-3 py-1 text-xs font-semibold">
                            <i class="fa-solid fa-circle-check text-[10px]"></i> <?= htmlspecialchars(t('press.ds_cc')) ?>
                        </span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="<?= getBasePath() ?>gcc-business-index" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold shadow-sm transition"><?= htmlspecialchars(t('press.btn_view_flagship')) ?> <i class="fa-solid fa-arrow-right text-[10px]"></i></a>
                        <a href="<?= getBasePath() ?>gcc/oman" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-white border border-gray-200 hover:border-blue-300 text-gray-700 hover:text-blue-700 text-sm font-semibold transition">🇴🇲 Oman</a>
                        <a href="<?= getBasePath() ?>gcc/saudi-arabia" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-white border border-gray-200 hover:border-blue-300 text-gray-700 hover:text-blue-700 text-sm font-semibold transition">🇸🇦 Saudi</a>
                        <a href="<?= getBasePath() ?>gcc/uae" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-white border border-gray-200 hover:border-blue-300 text-gray-700 hover:text-blue-700 text-sm font-semibold transition">🇦🇪 UAE</a>
                        <a href="<?= getBasePath() ?>gcc/qatar" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-white border border-gray-200 hover:border-blue-300 text-gray-700 hover:text-blue-700 text-sm font-semibold transition">🇶🇦 Qatar</a>
                        <a href="<?= getBasePath() ?>gcc/bahrain" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-white border border-gray-200 hover:border-blue-300 text-gray-700 hover:text-blue-700 text-sm font-semibold transition">🇧🇭 Bahrain</a>
                        <a href="<?= getBasePath() ?>gcc/kuwait" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-white border border-gray-200 hover:border-blue-300 text-gray-700 hover:text-blue-700 text-sm font-semibold transition">🇰🇼 Kuwait</a>
                    </div>
                </article>
            </div>
        </div>
    </section>

    <section id="assets" class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
        <span class="text-blue-700 font-semibold text-xs uppercase tracking-wider"><?= htmlspecialchars(t('press.section_assets')) ?></span>
        <h2 class="text-2xl sm:text-3xl font-extrabold text-gray-900 mt-2 mb-3"><?= htmlspecialchars(t('press.h2_assets')) ?></h2>
        <p class="text-gray-600 mb-8 max-w-3xl"><?= htmlspecialchars(t('press.assets_intro')) ?></p>
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
            <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm hover:shadow-md hover:border-blue-200 transition flex flex-col items-center text-center">
                <div class="h-24 flex items-center justify-center mb-4 bg-gray-50 w-full rounded-xl border border-gray-100">
                    <img src="<?= getBasePath() ?>assets/images/logo.svg" alt="<?= t('press.alt_primary_logo') ?>" class="max-h-12">
                </div>
                <div class="text-sm font-semibold text-gray-900 mb-1"><?= htmlspecialchars(t('press.asset_primary')) ?></div>
                <div class="text-xs text-gray-500 mb-3"><?= htmlspecialchars(t('press.asset_primary_sub')) ?></div>
                <a href="<?= getBasePath() ?>assets/images/logo.svg" download class="inline-flex items-center gap-1.5 text-sm font-semibold text-blue-700 hover:text-blue-800 transition">
                    <i class="fa-solid fa-download text-xs"></i> <?= htmlspecialchars(t('press.download_svg')) ?>
                </a>
            </div>
            <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm hover:shadow-md hover:border-blue-200 transition flex flex-col items-center text-center">
                <div class="h-24 flex items-center justify-center mb-4 bg-gray-900 w-full rounded-xl">
                    <img src="<?= getBasePath() ?>assets/images/logo-light.svg" alt="<?= t('press.alt_light_logo') ?>" class="max-h-12" onerror="this.src='<?= getBasePath() ?>assets/images/logo.svg';this.onerror=null;">
                </div>
                <div class="text-sm font-semibold text-gray-900 mb-1"><?= htmlspecialchars(t('press.asset_light')) ?></div>
                <div class="text-xs text-gray-500 mb-3"><?= htmlspecialchars(t('press.asset_light_sub')) ?></div>
                <a href="<?= getBasePath() ?>assets/images/logo-light.svg" download class="inline-flex items-center gap-1.5 text-sm font-semibold text-blue-700 hover:text-blue-800 transition">
                    <i class="fa-solid fa-download text-xs"></i> <?= htmlspecialchars(t('press.download_svg')) ?>
                </a>
            </div>
            <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm hover:shadow-md hover:border-blue-200 transition flex flex-col items-center text-center">
                <div class="h-24 flex items-center justify-center mb-4 bg-gray-50 w-full rounded-xl border border-gray-100 overflow-hidden">
                    <img src="<?= getBasePath() ?>assets/images/cardify-og.png" alt="<?= t('press.alt_og') ?>" class="max-h-24 object-cover">
                </div>
                <div class="text-sm font-semibold text-gray-900 mb-1"><?= htmlspecialchars(t('press.asset_og')) ?></div>
                <div class="text-xs text-gray-500 mb-3"><?= htmlspecialchars(t('press.asset_og_sub')) ?></div>
                <a href="<?= getBasePath() ?>assets/images/cardify-og.png" download class="inline-flex items-center gap-1.5 text-sm font-semibold text-blue-700 hover:text-blue-800 transition">
                    <i class="fa-solid fa-download text-xs"></i> <?= htmlspecialchars(t('press.download_png')) ?>
                </a>
            </div>
        </div>
    </section>

    <section class="bg-gray-50 border-y border-gray-100 py-16">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            <span class="text-blue-700 font-semibold text-xs uppercase tracking-wider"><?= htmlspecialchars(t('press.section_citations')) ?></span>
            <h2 class="text-2xl sm:text-3xl font-extrabold text-gray-900 mt-2 mb-8"><?= htmlspecialchars(t('press.h2_citations')) ?></h2>
            <div class="grid md:grid-cols-2 gap-5">
                <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-blue-50 text-blue-600">
                            <i class="fa-solid fa-book text-sm"></i>
                        </span>
                        <h3 class="font-bold text-gray-900"><?= htmlspecialchars(t('press.cite_mla')) ?></h3>
                    </div>
                    <p class="bg-gray-50 text-gray-800 text-sm p-4 rounded-xl leading-relaxed font-mono break-words"><?= htmlspecialchars(t('press.cite_mla_body', ['site' => 'cardify.om', 'updated' => $asOfDate, 'accessed' => $accessedDate])) ?></p>
                </div>
                <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600">
                            <i class="fa-solid fa-graduation-cap text-sm"></i>
                        </span>
                        <h3 class="font-bold text-gray-900"><?= htmlspecialchars(t('press.cite_apa')) ?></h3>
                    </div>
                    <p class="bg-gray-50 text-gray-800 text-sm p-4 rounded-xl leading-relaxed font-mono break-words"><?= htmlspecialchars(t('press.cite_apa_body', ['accessed' => $asOfDate])) ?></p>
                </div>
            </div>
        </div>
    </section>

    <section id="contact" class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
        <div class="bg-gradient-to-br from-blue-600 to-indigo-700 rounded-3xl p-10 sm:p-14 text-center text-white shadow-xl">
            <div class="inline-flex items-center gap-2 bg-white/10 backdrop-blur border border-white/20 rounded-full px-3 py-1 mb-4 text-xs font-semibold text-white uppercase tracking-wider">
                <i class="fa-solid fa-envelope-open-text text-[10px]"></i> <?= htmlspecialchars(t('press.section_contact')) ?>
            </div>
            <h2 class="text-3xl sm:text-4xl font-extrabold mb-3"><?= htmlspecialchars(t('press.h2_contact')) ?></h2>
            <p class="text-blue-100 mb-8 max-w-2xl mx-auto text-lg"><?= htmlspecialchars(t('press.contact_body')) ?></p>
            <div class="flex flex-col sm:flex-row gap-3 justify-center">
                <a href="mailto:press@cardify.om?subject=Press%20inquiry" class="inline-flex items-center justify-center gap-2 bg-white text-blue-700 font-bold px-6 py-3 rounded-lg hover:bg-blue-50 shadow-sm transition">
                    <i class="fa-solid fa-envelope"></i> <?= htmlspecialchars(t('press.contact_email_btn')) ?>
                </a>
                <a href="<?= getBasePath() ?>contact" class="inline-flex items-center justify-center gap-2 bg-white/10 border border-white/30 text-white font-semibold px-6 py-3 rounded-lg hover:bg-white/20 transition">
                    <?= htmlspecialchars(t('press.contact_general')) ?>
                </a>
            </div>
            <p class="text-blue-200 text-sm mt-6"><?= htmlspecialchars(t('press.contact_footer')) ?></p>
        </div>
    </section>
</div>
<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
