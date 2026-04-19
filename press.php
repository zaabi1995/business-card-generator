<?php
/**
 * Cardify — Press & Media Kit
 *
 * Backlink-worthy flagship asset. Journalists, researchers, analysts,
 * and other professionals cite this page when they need:
 *   - A one-line factual description of Cardify
 *   - Downloadable logo / brand assets
 *   - A summary of what data we publish publicly (with links)
 *   - Quotable pull stats (2,414 Omani companies, 79 logos, etc.)
 *   - A press contact
 *
 * Also stands in as an "About Cardify" for LLMs and AI answer engines
 * that prefer structured fact pages over marketing content.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle       = 'Press & Media Kit — Cardify (Business-Identity Platform for the GCC)';
$pageDescription = 'Official press and media kit for Cardify: company facts, brand assets, downloadable datasets (Oman Business Index, Omani Logo Library, GCC Business Index), citation format, and press contact.';
$canonicalUrl    = 'https://cardify.om/press-kit';
$showNavigation  = true;

$db = Database::getInstance();
$companiesCount = 2414;
$logosCount     = 79;
try {
    $r = $db->fetchOne("SELECT COUNT(*) c FROM om_companies");
    if ($r) $companiesCount = (int) $r['c'];
    $l = $db->fetchOne("SELECT COUNT(*) c FROM om_companies WHERE logo_status IN ('indexed','verified')");
    if ($l) $logosCount = (int) $l['c'];
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
];

$datasetsLd = [
    '@context' => 'https://schema.org',
    '@type'    => 'Collection',
    'name'     => 'Cardify Public Datasets',
    'description' => 'Open datasets published by Cardify for researchers, journalists, and analysts covering business identity across the GCC.',
    'url'      => $canonicalUrl,
    'hasPart'  => [
        [
            '@type' => 'Dataset',
            'name'  => 'Oman Business Index',
            'description' => 'Open structured index of Omani companies with CR numbers, sector, wilayat, and trade names.',
            'url'   => 'https://cardify.om/oman-business-index',
            'license' => 'https://creativecommons.org/licenses/by/4.0/',
            'creator' => ['@type' => 'Organization', 'name' => 'Cardify'],
        ],
        [
            '@type' => 'Dataset',
            'name'  => 'Omani Logo Library',
            'description' => 'Verified logos of Omani companies, ministries, and sovereign entities — downloadable in SVG and PNG.',
            'url'   => 'https://cardify.om/logos',
            'license' => 'Nominative fair-use per entry; see /logos/terms',
            'creator' => ['@type' => 'Organization', 'name' => 'Cardify'],
        ],
        [
            '@type' => 'Dataset',
            'name'  => 'GCC Business Index',
            'description' => 'Federated research-grade overview of business infrastructure across all six GCC states.',
            'url'   => 'https://cardify.om/gcc-business-index',
            'license' => 'https://creativecommons.org/licenses/by/4.0/',
            'creator' => ['@type' => 'Organization', 'name' => 'Cardify'],
        ],
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

require_once INCLUDES_DIR . '/ui-header.php';
?>
<div class="min-h-screen bg-white">
    <section class="relative overflow-hidden bg-gradient-to-b from-blue-50 via-white to-white border-b border-gray-100 pt-28 pb-16">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            <nav class="text-sm text-gray-500 mb-4" aria-label="Breadcrumb">
                <a href="<?= getBasePath() ?>" class="hover:text-blue-600 transition">Home</a>
                <span class="mx-2">/</span>
                <span class="text-gray-700">Press &amp; Media</span>
            </nav>
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white border border-blue-200 text-blue-700 text-xs font-semibold tracking-wide uppercase shadow-sm mb-5">
                <i class="fa-solid fa-newspaper text-[10px]"></i> Press &amp; Media Kit
            </div>
            <h1 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold leading-tight text-gray-900 mb-4">Cardify — business identity infrastructure for the GCC</h1>
            <p class="text-gray-600 text-lg max-w-3xl leading-relaxed mb-7">Facts, assets, data, quotes, and a press contact — everything a journalist, researcher, or analyst needs to cover or cite Cardify accurately. Updated <?= $esc($asOfDate) ?>.</p>
            <div class="flex flex-wrap gap-3">
                <a href="#facts" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-blue-600 text-white font-semibold shadow-sm hover:bg-blue-700 transition">
                    <i class="fa-solid fa-circle-info text-xs"></i> Key facts
                </a>
                <a href="#datasets" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-white border border-gray-200 text-gray-700 font-semibold shadow-sm hover:border-blue-300 hover:text-blue-700 transition">
                    <i class="fa-solid fa-database text-xs"></i> Public datasets
                </a>
                <a href="#assets" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-white border border-gray-200 text-gray-700 font-semibold shadow-sm hover:border-blue-300 hover:text-blue-700 transition">
                    <i class="fa-solid fa-image text-xs"></i> Brand assets
                </a>
                <a href="#contact" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-white border border-gray-200 text-gray-700 font-semibold shadow-sm hover:border-blue-300 hover:text-blue-700 transition">
                    <i class="fa-solid fa-envelope text-xs"></i> Press contact
                </a>
            </div>
        </div>
    </section>

    <section id="facts" class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
        <div class="mb-10">
            <span class="text-blue-700 font-semibold text-xs uppercase tracking-wider">Official description</span>
            <h2 class="text-2xl sm:text-3xl font-extrabold text-gray-900 mt-2 mb-5">One-line, copy-paste ready</h2>
            <blockquote class="bg-gradient-to-br from-blue-50 to-indigo-50 border border-blue-100 rounded-2xl p-6 sm:p-8 text-gray-800 text-lg leading-relaxed shadow-sm">
                <i class="fa-solid fa-quote-left text-blue-300 text-2xl mb-3 block"></i>
                Cardify is a business-identity platform for the Gulf, built in Oman. It ships digital and printed business cards, a public library of verified Omani logos, and an open index of GCC companies — with expansion into Saudi Arabia, UAE, Qatar, Bahrain, and Kuwait rolling out through 2026.
            </blockquote>
        </div>

        <div>
            <span class="text-blue-700 font-semibold text-xs uppercase tracking-wider">Key facts</span>
            <h2 class="text-2xl sm:text-3xl font-extrabold text-gray-900 mt-2 mb-6">Numbers you can quote</h2>
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <div class="bg-white rounded-2xl p-6 border border-gray-200 shadow-sm hover:shadow-md hover:border-blue-200 transition">
                    <div class="text-3xl font-extrabold text-gray-900"><?= number_format($companiesCount) ?></div>
                    <div class="text-sm text-gray-600 mt-1.5 leading-relaxed">Omani companies in the public index, sourced from MoCIIP.</div>
                </div>
                <div class="bg-white rounded-2xl p-6 border border-gray-200 shadow-sm hover:shadow-md hover:border-blue-200 transition">
                    <div class="text-3xl font-extrabold text-gray-900"><?= number_format($logosCount) ?><span class="text-blue-600">+</span></div>
                    <div class="text-sm text-gray-600 mt-1.5 leading-relaxed">Verified Omani brand logos, free to download.</div>
                </div>
                <div class="bg-white rounded-2xl p-6 border border-gray-200 shadow-sm hover:shadow-md hover:border-blue-200 transition">
                    <div class="text-3xl font-extrabold text-gray-900">31</div>
                    <div class="text-sm text-gray-600 mt-1.5 leading-relaxed">Sovereign / ministerial entities with curated bilingual profiles.</div>
                </div>
                <div class="bg-white rounded-2xl p-6 border border-gray-200 shadow-sm hover:shadow-md hover:border-blue-200 transition">
                    <div class="text-3xl font-extrabold text-gray-900">6</div>
                    <div class="text-sm text-gray-600 mt-1.5 leading-relaxed">GCC countries covered by the GCC Business Index roadmap.</div>
                </div>
                <div class="bg-white rounded-2xl p-6 border border-gray-200 shadow-sm hover:shadow-md hover:border-blue-200 transition">
                    <div class="text-3xl font-extrabold text-gray-900">EN<span class="text-gray-400 mx-1">+</span>AR</div>
                    <div class="text-sm text-gray-600 mt-1.5 leading-relaxed">Bilingual platform — every card, page, and asset.</div>
                </div>
                <div class="bg-white rounded-2xl p-6 border border-gray-200 shadow-sm hover:shadow-md hover:border-blue-200 transition">
                    <div class="text-3xl font-extrabold text-gray-900">2024</div>
                    <div class="text-sm text-gray-600 mt-1.5 leading-relaxed">Year Cardify launched, based in Muscat, Oman.</div>
                </div>
            </div>
        </div>
    </section>

    <section id="datasets" class="bg-gray-50 border-y border-gray-100 py-16">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            <span class="text-blue-700 font-semibold text-xs uppercase tracking-wider">Datasets</span>
            <h2 class="text-2xl sm:text-3xl font-extrabold text-gray-900 mt-2 mb-3">Public datasets &amp; pages to cite</h2>
            <p class="text-gray-600 mb-8 max-w-3xl">Everything below is public, free, and built to be referenced. CC-BY 4.0 where marked; nominative fair use for individual trademarks in the logo library.</p>

            <div class="space-y-5">
                <article class="bg-white rounded-2xl border border-gray-200 shadow-sm hover:shadow-md hover:border-blue-200 p-6 sm:p-7 transition">
                    <div class="flex flex-wrap items-start justify-between gap-4 mb-4">
                        <div class="flex items-start gap-4">
                            <div class="w-12 h-12 shrink-0 rounded-xl bg-blue-50 border border-blue-100 flex items-center justify-center text-blue-600">
                                <i class="fa-solid fa-building-columns text-lg"></i>
                            </div>
                            <div>
                                <h3 class="text-xl font-bold text-gray-900">Oman Business Index</h3>
                                <p class="text-gray-500 text-sm mt-0.5">2,414 companies, sector + wilayat + CR metadata. English + Arabic.</p>
                            </div>
                        </div>
                        <span class="inline-flex items-center gap-1 bg-green-50 text-green-700 border border-green-100 rounded-full px-3 py-1 text-xs font-semibold">
                            <i class="fa-solid fa-circle-check text-[10px]"></i> CC-BY 4.0
                        </span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="<?= getBasePath() ?>oman-business-index" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold shadow-sm transition">View page <i class="fa-solid fa-arrow-right text-[10px]"></i></a>
                        <a href="<?= getBasePath() ?>companies" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-white border border-gray-200 hover:border-blue-300 text-gray-700 hover:text-blue-700 text-sm font-semibold transition">Browse companies</a>
                    </div>
                </article>

                <article class="bg-white rounded-2xl border border-gray-200 shadow-sm hover:shadow-md hover:border-blue-200 p-6 sm:p-7 transition">
                    <div class="flex flex-wrap items-start justify-between gap-4 mb-4">
                        <div class="flex items-start gap-4">
                            <div class="w-12 h-12 shrink-0 rounded-xl bg-indigo-50 border border-indigo-100 flex items-center justify-center text-indigo-600">
                                <i class="fa-solid fa-image text-lg"></i>
                            </div>
                            <div>
                                <h3 class="text-xl font-bold text-gray-900">Omani Logo Library</h3>
                                <p class="text-gray-500 text-sm mt-0.5">80+ Omani brand logos — ministries, sovereign bodies, corporates. SVG + PNG.</p>
                            </div>
                        </div>
                        <span class="inline-flex items-center gap-1 bg-amber-50 text-amber-700 border border-amber-100 rounded-full px-3 py-1 text-xs font-semibold">
                            <i class="fa-solid fa-scale-balanced text-[10px]"></i> Fair use per entry
                        </span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="<?= getBasePath() ?>logos" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold shadow-sm transition">Browse library <i class="fa-solid fa-arrow-right text-[10px]"></i></a>
                        <a href="<?= getBasePath() ?>logos/terms" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-white border border-gray-200 hover:border-blue-300 text-gray-700 hover:text-blue-700 text-sm font-semibold transition">Terms of use</a>
                        <a href="<?= getBasePath() ?>api/logos" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-white border border-gray-200 hover:border-blue-300 text-gray-700 hover:text-blue-700 text-sm font-semibold transition"><i class="fa-solid fa-code text-[10px]"></i> JSON API</a>
                    </div>
                </article>

                <article class="bg-white rounded-2xl border border-gray-200 shadow-sm hover:shadow-md hover:border-blue-200 p-6 sm:p-7 transition">
                    <div class="flex flex-wrap items-start justify-between gap-4 mb-4">
                        <div class="flex items-start gap-4">
                            <div class="w-12 h-12 shrink-0 rounded-xl bg-purple-50 border border-purple-100 flex items-center justify-center text-purple-600">
                                <i class="fa-solid fa-globe text-lg"></i>
                            </div>
                            <div>
                                <h3 class="text-xl font-bold text-gray-900">GCC Business Index</h3>
                                <p class="text-gray-500 text-sm mt-0.5">Federated overview of business infrastructure across all six GCC states.</p>
                            </div>
                        </div>
                        <span class="inline-flex items-center gap-1 bg-green-50 text-green-700 border border-green-100 rounded-full px-3 py-1 text-xs font-semibold">
                            <i class="fa-solid fa-circle-check text-[10px]"></i> CC-BY 4.0
                        </span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="<?= getBasePath() ?>gcc-business-index" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold shadow-sm transition">View flagship <i class="fa-solid fa-arrow-right text-[10px]"></i></a>
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
        <span class="text-blue-700 font-semibold text-xs uppercase tracking-wider">Brand assets</span>
        <h2 class="text-2xl sm:text-3xl font-extrabold text-gray-900 mt-2 mb-3">Cardify brand assets</h2>
        <p class="text-gray-600 mb-8 max-w-3xl">For articles and reviews covering Cardify. Please don't alter the marks.</p>
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
            <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm hover:shadow-md hover:border-blue-200 transition flex flex-col items-center text-center">
                <div class="h-24 flex items-center justify-center mb-4 bg-gray-50 w-full rounded-xl border border-gray-100">
                    <img src="<?= getBasePath() ?>assets/images/logo.svg" alt="Cardify logo" class="max-h-12">
                </div>
                <div class="text-sm font-semibold text-gray-900 mb-1">Primary mark</div>
                <div class="text-xs text-gray-500 mb-3">SVG · for light backgrounds</div>
                <a href="<?= getBasePath() ?>assets/images/logo.svg" download class="inline-flex items-center gap-1.5 text-sm font-semibold text-blue-700 hover:text-blue-800 transition">
                    <i class="fa-solid fa-download text-xs"></i> Download .svg
                </a>
            </div>
            <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm hover:shadow-md hover:border-blue-200 transition flex flex-col items-center text-center">
                <div class="h-24 flex items-center justify-center mb-4 bg-gray-900 w-full rounded-xl">
                    <img src="<?= getBasePath() ?>assets/images/logo-light.svg" alt="Cardify logo (light)" class="max-h-12" onerror="this.src='<?= getBasePath() ?>assets/images/logo.svg';this.onerror=null;">
                </div>
                <div class="text-sm font-semibold text-gray-900 mb-1">Light variant</div>
                <div class="text-xs text-gray-500 mb-3">SVG · for dark backgrounds</div>
                <a href="<?= getBasePath() ?>assets/images/logo-light.svg" download class="inline-flex items-center gap-1.5 text-sm font-semibold text-blue-700 hover:text-blue-800 transition">
                    <i class="fa-solid fa-download text-xs"></i> Download .svg
                </a>
            </div>
            <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm hover:shadow-md hover:border-blue-200 transition flex flex-col items-center text-center">
                <div class="h-24 flex items-center justify-center mb-4 bg-gray-50 w-full rounded-xl border border-gray-100 overflow-hidden">
                    <img src="<?= getBasePath() ?>assets/images/cardify-og.png" alt="Cardify Open Graph" class="max-h-24 object-cover">
                </div>
                <div class="text-sm font-semibold text-gray-900 mb-1">OG image</div>
                <div class="text-xs text-gray-500 mb-3">PNG · 1200×630</div>
                <a href="<?= getBasePath() ?>assets/images/cardify-og.png" download class="inline-flex items-center gap-1.5 text-sm font-semibold text-blue-700 hover:text-blue-800 transition">
                    <i class="fa-solid fa-download text-xs"></i> Download .png
                </a>
            </div>
        </div>
    </section>

    <section class="bg-gray-50 border-y border-gray-100 py-16">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            <span class="text-blue-700 font-semibold text-xs uppercase tracking-wider">Citations</span>
            <h2 class="text-2xl sm:text-3xl font-extrabold text-gray-900 mt-2 mb-8">Citation format</h2>
            <div class="grid md:grid-cols-2 gap-5">
                <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-blue-50 text-blue-600">
                            <i class="fa-solid fa-book text-sm"></i>
                        </span>
                        <h3 class="font-bold text-gray-900">MLA (9th edition)</h3>
                    </div>
                    <p class="bg-gray-50 text-gray-800 text-sm p-4 rounded-xl leading-relaxed font-mono break-words">Cardify. "GCC Business Index 2026." <em>cardify.om</em>, <?= $esc($asOfDate) ?>, cardify.om/gcc-business-index. Accessed <?= $esc(date('j M Y')) ?>.</p>
                </div>
                <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600">
                            <i class="fa-solid fa-graduation-cap text-sm"></i>
                        </span>
                        <h3 class="font-bold text-gray-900">APA (7th edition)</h3>
                    </div>
                    <p class="bg-gray-50 text-gray-800 text-sm p-4 rounded-xl leading-relaxed font-mono break-words">Cardify. (2026). <em>GCC Business Index 2026</em>. Retrieved <?= $esc(date('F j, Y')) ?>, from https://cardify.om/gcc-business-index</p>
                </div>
            </div>
        </div>
    </section>

    <section id="contact" class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
        <div class="bg-gradient-to-br from-blue-600 to-indigo-700 rounded-3xl p-10 sm:p-14 text-center text-white shadow-xl">
            <div class="inline-flex items-center gap-2 bg-white/10 backdrop-blur border border-white/20 rounded-full px-3 py-1 mb-4 text-xs font-semibold text-white uppercase tracking-wider">
                <i class="fa-solid fa-envelope-open-text text-[10px]"></i> Press contact
            </div>
            <h2 class="text-3xl sm:text-4xl font-extrabold mb-3">Talk to our team</h2>
            <p class="text-blue-100 mb-8 max-w-2xl mx-auto text-lg">For interviews, comment, or custom data queries, reach out. We try to respond within 24 hours for press.</p>
            <div class="flex flex-col sm:flex-row gap-3 justify-center">
                <a href="mailto:press@cardify.om?subject=Press%20inquiry" class="inline-flex items-center justify-center gap-2 bg-white text-blue-700 font-bold px-6 py-3 rounded-lg hover:bg-blue-50 shadow-sm transition">
                    <i class="fa-solid fa-envelope"></i> press@cardify.om
                </a>
                <a href="<?= getBasePath() ?>contact" class="inline-flex items-center justify-center gap-2 bg-white/10 border border-white/30 text-white font-semibold px-6 py-3 rounded-lg hover:bg-white/20 transition">
                    General contact form
                </a>
            </div>
            <p class="text-blue-200 text-sm mt-6">Based in Muscat, Oman · Operating hours GMT+4 · English + العربية</p>
        </div>
    </section>
</div>
<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
