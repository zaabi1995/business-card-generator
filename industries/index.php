<?php
/**
 * Cardify — Industries Hub (landing page for all /industries/*)
 *
 * SEO landing for "digital business cards by industry" that links out
 * to every /industries/* page. Also cross-links to the GCC Business
 * Index, Oman Business Index, and Omani Logo Library for topical density.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle       = 'Digital Business Cards by Industry — Banking, Oil & Gas, Real Estate, Tourism | Cardify';
$pageDescription = 'Industry-specific digital business card solutions for teams in Oman and the GCC. Banking, logistics, oil & gas, government, construction, real estate, healthcare, tourism, restaurants.';
$canonicalUrl    = 'https://cardify.om/industries';
$showNavigation  = true;

$industries = [
    ['slug' => 'banking',       'name' => 'Banking & Finance', 'desc' => 'Relationship managers, advisors, private banking, Islamic finance.', 'icon' => 'fa-solid fa-building-columns', 'tint' => 'bg-emerald-50 text-emerald-700'],
    ['slug' => 'oil-gas',        'name' => 'Oil & Gas',          'desc' => 'Operations managers, field engineers, EPC account leads.',        'icon' => 'fa-solid fa-oil-well',         'tint' => 'bg-amber-50 text-amber-700'],
    ['slug' => 'logistics',      'name' => 'Logistics & Shipping','desc' => 'Freight forwarders, customs brokers, port agents, 3PL teams.',    'icon' => 'fa-solid fa-truck-ramp-box',   'tint' => 'bg-sky-50 text-sky-700'],
    ['slug' => 'government',     'name' => 'Government & Defense','desc' => 'Ministry delegations, diplomatic staff, protocol officers.',      'icon' => 'fa-solid fa-landmark',         'tint' => 'bg-slate-100 text-slate-700'],
    ['slug' => 'real-estate',    'name' => 'Real Estate',         'desc' => 'Brokers, leasing agents, development teams, property managers.', 'icon' => 'fa-solid fa-city',             'tint' => 'bg-orange-50 text-orange-700'],
    ['slug' => 'construction',   'name' => 'Construction',        'desc' => 'Project managers, site engineers, contractor account leads.',    'icon' => 'fa-solid fa-helmet-safety',    'tint' => 'bg-yellow-50 text-yellow-700'],
    ['slug' => 'healthcare',     'name' => 'Healthcare',          'desc' => 'Doctors, clinics, pharma reps, hospital admin teams.',           'icon' => 'fa-solid fa-stethoscope',      'tint' => 'bg-rose-50 text-rose-700'],
    ['slug' => 'tourism',        'name' => 'Tourism & Hospitality','desc' => 'Hotels, DMCs, concierges, travel agents, event planners.',      'icon' => 'fa-solid fa-plane-departure',  'tint' => 'bg-purple-50 text-purple-700'],
    ['slug' => 'restaurants',    'name' => 'Restaurants & F&B',   'desc' => 'Chefs, restaurant owners, catering teams, F&B managers.',       'icon' => 'fa-solid fa-utensils',         'tint' => 'bg-red-50 text-red-700'],
];

$breadcrumbLd = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://cardify.om/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Industries', 'item' => $canonicalUrl],
    ],
];

$itemListLd = [
    '@context' => 'https://schema.org',
    '@type' => 'ItemList',
    'name' => 'Industries served by Cardify',
    'numberOfItems' => count($industries),
    'itemListElement' => array_map(function ($i, $it) {
        return [
            '@type' => 'ListItem',
            'position' => $i + 1,
            'url' => 'https://cardify.om/industries/' . $it['slug'],
            'name' => $it['name'],
        ];
    }, array_keys($industries), $industries),
];

$extraHead = '<script type="application/ld+json">' . json_encode($breadcrumbLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n"
           . '<script type="application/ld+json">' . json_encode($itemListLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';

require_once INCLUDES_DIR . '/ui-header.php';
?>
<div class="min-h-screen bg-gray-50">
    <section class="bg-gradient-to-b from-white to-gray-50 pt-28 pb-12 border-b border-gray-100">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
            <nav class="text-sm text-gray-500 mb-3" aria-label="Breadcrumb">
                <a href="<?= getBasePath() ?>" class="hover:text-blue-600">Home</a>
                <span class="mx-2">/</span>
                <span class="text-gray-700">Industries</span>
            </nav>
            <span class="text-blue-700 font-semibold text-sm uppercase tracking-wider">Industries</span>
            <h1 class="text-3xl sm:text-4xl lg:text-5xl font-bold text-gray-900 mt-2 mb-4">Digital business cards, tuned to every industry</h1>
            <p class="text-gray-600 text-lg max-w-3xl">Bilingual EN/AR cards that actually fit how banks, oil companies, logistics firms, ministries, and restaurants in Oman and the GCC introduce themselves. Brand-locked templates. Bulk provisioning. Zero reprints when someone's title changes.</p>
        </div>
    </section>

    <section class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-14">
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
            <?php foreach ($industries as $it): ?>
                <a href="<?= getBasePath() ?>industries/<?= htmlspecialchars($it['slug']) ?>" class="group block bg-white rounded-2xl border border-gray-200 p-6 hover:border-blue-300 hover:shadow-md transition">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-11 h-11 rounded-xl <?= $it['tint'] ?> flex items-center justify-center text-lg">
                            <i class="<?= $it['icon'] ?>"></i>
                        </div>
                        <h2 class="text-lg font-bold text-gray-900 group-hover:text-blue-700"><?= htmlspecialchars($it['name']) ?></h2>
                    </div>
                    <p class="text-gray-600 text-sm leading-relaxed mb-4"><?= htmlspecialchars($it['desc']) ?></p>
                    <span class="text-sm font-semibold text-blue-700 inline-flex items-center gap-1">
                        View solution <i class="fa-solid fa-arrow-right text-xs transition group-hover:translate-x-1"></i>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="bg-white border-y border-gray-100 py-14">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <h2 class="text-2xl sm:text-3xl font-extrabold text-gray-900 mb-3">Looking for data, logos, or tools?</h2>
            <p class="text-gray-600 mb-8 max-w-2xl mx-auto">Cardify also runs the <strong>GCC Business Index</strong>, the <strong>Omani Logo Library</strong>, and free <strong>QR / signature tools</strong> used by hundreds of teams across the region.</p>
            <div class="grid sm:grid-cols-3 gap-4 max-w-3xl mx-auto">
                <a href="<?= getBasePath() ?>gcc-business-index" class="bg-blue-50 border border-blue-100 rounded-xl p-5 hover:bg-blue-100 transition text-left">
                    <div class="font-bold text-gray-900 mb-1">GCC Business Index</div>
                    <div class="text-sm text-gray-600">Six-country directory.</div>
                </a>
                <a href="<?= getBasePath() ?>logos" class="bg-emerald-50 border border-emerald-100 rounded-xl p-5 hover:bg-emerald-100 transition text-left">
                    <div class="font-bold text-gray-900 mb-1">Omani Logo Library</div>
                    <div class="text-sm text-gray-600">80+ verified logos, free to download.</div>
                </a>
                <a href="<?= getBasePath() ?>tools" class="bg-purple-50 border border-purple-100 rounded-xl p-5 hover:bg-purple-100 transition text-left">
                    <div class="font-bold text-gray-900 mb-1">Free Tools</div>
                    <div class="text-sm text-gray-600">QR, vCard, email signature.</div>
                </a>
            </div>
        </div>
    </section>
</div>
<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
