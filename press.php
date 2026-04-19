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
    <section class="bg-gradient-to-br from-gray-900 via-blue-900 to-gray-900 text-white pt-28 pb-16">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            <nav class="text-sm text-white/70 mb-3" aria-label="Breadcrumb">
                <a href="<?= getBasePath() ?>" class="hover:text-white">Home</a>
                <span class="mx-2">/</span>
                <span class="text-white">Press</span>
            </nav>
            <div class="inline-flex items-center gap-2 bg-blue-500/20 border border-blue-400/30 rounded-full px-3 py-1 mb-4 text-xs font-semibold text-blue-200">
                <i class="fa-solid fa-newspaper"></i> Press & Media Kit
            </div>
            <h1 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold leading-tight mb-4">Cardify — business identity infrastructure for the GCC</h1>
            <p class="text-white/90 text-lg max-w-3xl leading-relaxed mb-6">Facts, assets, data, quotes, and a press contact — everything a journalist, researcher, or analyst needs to cover or cite Cardify accurately. Updated <?= $esc($asOfDate) ?>.</p>
            <div class="flex flex-wrap gap-3">
                <a href="#facts" class="bg-white/10 backdrop-blur border border-white/30 text-white font-semibold px-5 py-2.5 rounded-lg hover:bg-white/20 transition">Key facts</a>
                <a href="#assets" class="bg-white/10 backdrop-blur border border-white/30 text-white font-semibold px-5 py-2.5 rounded-lg hover:bg-white/20 transition">Brand assets</a>
                <a href="#datasets" class="bg-white/10 backdrop-blur border border-white/30 text-white font-semibold px-5 py-2.5 rounded-lg hover:bg-white/20 transition">Public datasets</a>
                <a href="#contact" class="bg-white text-gray-900 font-bold px-5 py-2.5 rounded-lg hover:bg-blue-50 transition">Press contact</a>
            </div>
        </div>
    </section>

    <section id="facts" class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-14">
        <h2 class="text-2xl sm:text-3xl font-extrabold text-gray-900 mb-6">One-line description</h2>
        <blockquote class="bg-blue-50 border-l-4 border-blue-600 rounded-r-xl p-6 text-gray-800 text-lg leading-relaxed">
            Cardify is a business-identity platform for the Gulf, built in Oman. It ships digital and printed business cards, a public library of verified Omani logos, and an open index of GCC companies — with expansion into Saudi Arabia, UAE, Qatar, Bahrain, and Kuwait rolling out through 2026.
        </blockquote>

        <h2 class="text-2xl sm:text-3xl font-extrabold text-gray-900 mt-12 mb-6">Key facts (quotable)</h2>
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <div class="bg-gray-50 border border-gray-200 rounded-xl p-5">
                <div class="text-3xl font-extrabold text-gray-900"><?= number_format($companiesCount) ?></div>
                <div class="text-sm text-gray-600 mt-1">Omani companies in the public index, sourced from MoCIIP.</div>
            </div>
            <div class="bg-gray-50 border border-gray-200 rounded-xl p-5">
                <div class="text-3xl font-extrabold text-gray-900"><?= number_format($logosCount) ?>+</div>
                <div class="text-sm text-gray-600 mt-1">Verified Omani brand logos, free to download.</div>
            </div>
            <div class="bg-gray-50 border border-gray-200 rounded-xl p-5">
                <div class="text-3xl font-extrabold text-gray-900">31</div>
                <div class="text-sm text-gray-600 mt-1">Sovereign / ministerial entities with curated bilingual profiles.</div>
            </div>
            <div class="bg-gray-50 border border-gray-200 rounded-xl p-5">
                <div class="text-3xl font-extrabold text-gray-900">6</div>
                <div class="text-sm text-gray-600 mt-1">GCC countries covered by the GCC Business Index roadmap.</div>
            </div>
            <div class="bg-gray-50 border border-gray-200 rounded-xl p-5">
                <div class="text-3xl font-extrabold text-gray-900">EN + AR</div>
                <div class="text-sm text-gray-600 mt-1">Bilingual platform — every card, page, and asset.</div>
            </div>
            <div class="bg-gray-50 border border-gray-200 rounded-xl p-5">
                <div class="text-3xl font-extrabold text-gray-900">2024</div>
                <div class="text-sm text-gray-600 mt-1">Year Cardify launched, based in Muscat, Oman.</div>
            </div>
        </div>
    </section>

    <section id="datasets" class="bg-gray-50 border-y border-gray-100 py-14">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            <h2 class="text-2xl sm:text-3xl font-extrabold text-gray-900 mb-2">Public datasets & pages to cite</h2>
            <p class="text-gray-600 mb-8 max-w-3xl">Everything below is public, free, and built to be referenced. CC-BY 4.0 where marked; nominative fair use for individual trademarks in the logo library.</p>

            <div class="space-y-4">
                <article class="bg-white rounded-xl border border-gray-200 p-6">
                    <div class="flex items-start justify-between gap-4 mb-3">
                        <div>
                            <h3 class="text-xl font-bold text-gray-900">Oman Business Index</h3>
                            <p class="text-gray-500 text-sm">2,414 companies, sector + wilayat + CR metadata. English + Arabic.</p>
                        </div>
                        <span class="bg-emerald-50 text-emerald-700 rounded-full px-3 py-1 text-xs font-semibold">CC-BY 4.0</span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="<?= getBasePath() ?>oman-business-index" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold transition">View page</a>
                        <a href="<?= getBasePath() ?>companies" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white border border-gray-200 hover:border-blue-300 text-gray-700 hover:text-blue-700 text-sm font-semibold transition">Browse companies</a>
                    </div>
                </article>

                <article class="bg-white rounded-xl border border-gray-200 p-6">
                    <div class="flex items-start justify-between gap-4 mb-3">
                        <div>
                            <h3 class="text-xl font-bold text-gray-900">Omani Logo Library</h3>
                            <p class="text-gray-500 text-sm">80+ Omani brand logos — ministries, sovereign bodies, corporates. SVG + PNG.</p>
                        </div>
                        <span class="bg-amber-50 text-amber-700 rounded-full px-3 py-1 text-xs font-semibold">Fair use per entry</span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="<?= getBasePath() ?>logos" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold transition">Browse library</a>
                        <a href="<?= getBasePath() ?>logos/terms" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white border border-gray-200 hover:border-blue-300 text-gray-700 hover:text-blue-700 text-sm font-semibold transition">Terms of use</a>
                        <a href="<?= getBasePath() ?>api/logos" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white border border-gray-200 hover:border-blue-300 text-gray-700 hover:text-blue-700 text-sm font-semibold transition">JSON API</a>
                    </div>
                </article>

                <article class="bg-white rounded-xl border border-gray-200 p-6">
                    <div class="flex items-start justify-between gap-4 mb-3">
                        <div>
                            <h3 class="text-xl font-bold text-gray-900">GCC Business Index</h3>
                            <p class="text-gray-500 text-sm">Federated overview of business infrastructure across Saudi Arabia, UAE, Qatar, Bahrain, Kuwait, and Oman.</p>
                        </div>
                        <span class="bg-emerald-50 text-emerald-700 rounded-full px-3 py-1 text-xs font-semibold">CC-BY 4.0</span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="<?= getBasePath() ?>gcc-business-index" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold transition">View flagship</a>
                        <a href="<?= getBasePath() ?>gcc/oman" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white border border-gray-200 hover:border-blue-300 text-gray-700 hover:text-blue-700 text-sm font-semibold transition">Oman 🇴🇲</a>
                        <a href="<?= getBasePath() ?>gcc/saudi-arabia" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white border border-gray-200 hover:border-blue-300 text-gray-700 hover:text-blue-700 text-sm font-semibold transition">Saudi 🇸🇦</a>
                        <a href="<?= getBasePath() ?>gcc/uae" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white border border-gray-200 hover:border-blue-300 text-gray-700 hover:text-blue-700 text-sm font-semibold transition">UAE 🇦🇪</a>
                        <a href="<?= getBasePath() ?>gcc/qatar" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white border border-gray-200 hover:border-blue-300 text-gray-700 hover:text-blue-700 text-sm font-semibold transition">Qatar 🇶🇦</a>
                        <a href="<?= getBasePath() ?>gcc/bahrain" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white border border-gray-200 hover:border-blue-300 text-gray-700 hover:text-blue-700 text-sm font-semibold transition">Bahrain 🇧🇭</a>
                        <a href="<?= getBasePath() ?>gcc/kuwait" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white border border-gray-200 hover:border-blue-300 text-gray-700 hover:text-blue-700 text-sm font-semibold transition">Kuwait 🇰🇼</a>
                    </div>
                </article>
            </div>
        </div>
    </section>

    <section id="assets" class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-14">
        <h2 class="text-2xl sm:text-3xl font-extrabold text-gray-900 mb-6">Cardify brand assets</h2>
        <p class="text-gray-600 mb-6 max-w-3xl">For articles and reviews covering Cardify. Please don't alter the marks.</p>
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <div class="bg-white border border-gray-200 rounded-xl p-6 flex flex-col items-center text-center">
                <div class="h-20 flex items-center justify-center mb-4 bg-gray-50 w-full rounded-lg">
                    <img src="<?= getBasePath() ?>assets/images/logo.svg" alt="Cardify logo" class="max-h-12">
                </div>
                <div class="text-sm font-semibold text-gray-900 mb-2">Primary mark (SVG)</div>
                <a href="<?= getBasePath() ?>assets/images/logo.svg" download class="text-sm font-semibold text-blue-700 hover:text-blue-800">Download .svg</a>
            </div>
            <div class="bg-white border border-gray-200 rounded-xl p-6 flex flex-col items-center text-center">
                <div class="h-20 flex items-center justify-center mb-4 bg-gray-900 w-full rounded-lg">
                    <img src="<?= getBasePath() ?>assets/images/logo-light.svg" alt="Cardify logo (light)" class="max-h-12" onerror="this.src='<?= getBasePath() ?>assets/images/logo.svg';this.onerror=null;">
                </div>
                <div class="text-sm font-semibold text-gray-900 mb-2">Light variant (SVG)</div>
                <a href="<?= getBasePath() ?>assets/images/logo-light.svg" download class="text-sm font-semibold text-blue-700 hover:text-blue-800">Download .svg</a>
            </div>
            <div class="bg-white border border-gray-200 rounded-xl p-6 flex flex-col items-center text-center">
                <div class="h-20 flex items-center justify-center mb-4 bg-gray-50 w-full rounded-lg overflow-hidden">
                    <img src="<?= getBasePath() ?>assets/images/cardify-og.png" alt="Cardify Open Graph" class="max-h-20 object-cover">
                </div>
                <div class="text-sm font-semibold text-gray-900 mb-2">OG image (1200×630)</div>
                <a href="<?= getBasePath() ?>assets/images/cardify-og.png" download class="text-sm font-semibold text-blue-700 hover:text-blue-800">Download .png</a>
            </div>
        </div>
    </section>

    <section class="bg-gray-50 border-y border-gray-100 py-14">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            <h2 class="text-2xl sm:text-3xl font-extrabold text-gray-900 mb-6">Citation format</h2>
            <div class="grid md:grid-cols-2 gap-5">
                <div class="bg-white border border-gray-200 rounded-xl p-6">
                    <h3 class="font-bold text-gray-900 mb-2">MLA (9th edition)</h3>
                    <pre class="bg-gray-50 text-gray-800 text-sm p-3 rounded-lg overflow-x-auto leading-relaxed whitespace-pre-wrap">Cardify. "GCC Business Index 2026." <em>cardify.om</em>, <?= $esc($asOfDate) ?>, cardify.om/gcc-business-index. Accessed <?= $esc(date('j M Y')) ?>.</pre>
                </div>
                <div class="bg-white border border-gray-200 rounded-xl p-6">
                    <h3 class="font-bold text-gray-900 mb-2">APA (7th edition)</h3>
                    <pre class="bg-gray-50 text-gray-800 text-sm p-3 rounded-lg overflow-x-auto leading-relaxed whitespace-pre-wrap">Cardify. (2026). <em>GCC Business Index 2026</em>. Retrieved <?= $esc(date('F j, Y')) ?>, from https://cardify.om/gcc-business-index</pre>
                </div>
            </div>
        </div>
    </section>

    <section id="contact" class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-14 text-center">
        <h2 class="text-2xl sm:text-3xl font-extrabold text-gray-900 mb-3">Press contact</h2>
        <p class="text-gray-600 mb-6 max-w-2xl mx-auto">For interviews, comment, or custom data queries, reach out. We try to respond within 24 hours for press.</p>
        <div class="inline-flex flex-col sm:flex-row gap-3">
            <a href="mailto:press@cardify.om?subject=Press%20inquiry" class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-6 py-3 rounded-lg transition">
                <i class="fa-solid fa-envelope mr-2"></i> press@cardify.om
            </a>
            <a href="<?= getBasePath() ?>contact" class="bg-gray-100 hover:bg-gray-200 text-gray-900 font-semibold px-6 py-3 rounded-lg transition">
                General contact form
            </a>
        </div>
        <p class="text-gray-500 text-sm mt-6">Based in Muscat, Oman · Operating hours GMT+4 · English + العربية</p>
    </section>
</div>
<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
