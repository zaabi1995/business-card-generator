<?php
/**
 * Omani Logo Library, Hub view.
 * Uses canonical Cardify chrome (ui-header / ui-footer) + Techwind Tailwind
 * + Flowbite classes exactly like design-showcase.php / tools.php.
 *
 * @var array  $data       ['rows'=>…, 'total'=>…, 'page'=>…, 'per_page'=>…]
 * @var int    $total      Total indexed+verified logos
 * @var string $title
 * @var string $canonical
 * @var array  $SECTORS    slug => label
 * @var bool   $isAr
 */

if (!defined('INCLUDES_DIR')) { http_response_code(404); exit; }
$pageTitle       = $title;
$pageDescription = t('logos.hub_desc_prefix') . number_format($total) . t('logos.hub_desc_suffix');
$canonicalUrl    = $canonical;
$bodyClass       = 'bg-white';
$showNavigation  = true;
$ogImage         = 'https://cardify.om/storage/og/logos/hub.png';

// JSON-LD: CollectionPage + BreadcrumbList + FAQPage + DataCatalog.
// Google commonly surfaces FAQPage rich results and image carousels from these.
$faqQuestions = [
    ['q' => t('logos.faq_q1'), 'a' => t('logos.faq_a1')],
    ['q' => t('logos.faq_q2'), 'a' => t('logos.faq_a2')],
    ['q' => t('logos.faq_q3'), 'a' => t('logos.faq_a3')],
    ['q' => t('logos.faq_q4'), 'a' => t('logos.faq_a4')],
    ['q' => t('logos.faq_q5'), 'a' => t('logos.faq_a5')],
    ['q' => t('logos.faq_q6'), 'a' => t('logos.faq_a6')],
];

$jsonLdBlocks = [];

$jsonLdBlocks[] = [
    "@context"      => "https://schema.org",
    "@type"         => "CollectionPage",
    "name"          => $title,
    "url"           => $canonical,
    "description"   => "Public archive of Omani company logos, indexed from public sources and verified by owners.",
    "inLanguage"    => $isAr ? 'ar' : 'en',
    "numberOfItems" => $total,
    "isPartOf"      => ["@type" => "WebSite", "name" => "Cardify", "url" => "https://cardify.om"],
    "about"         => ["@type" => "Thing", "name" => "Omani companies and brand marks"],
];

$jsonLdBlocks[] = [
    "@context" => "https://schema.org",
    "@type"    => "BreadcrumbList",
    "itemListElement" => [
        ["@type" => "ListItem", "position" => 1, "name" => "Cardify",       "item" => "https://cardify.om"],
        ["@type" => "ListItem", "position" => 2, "name" => "Logo Library",  "item" => "https://cardify.om/logos"],
    ],
];

$jsonLdBlocks[] = [
    "@context"    => "https://schema.org",
    "@type"       => "FAQPage",
    "mainEntity"  => array_map(fn($q) => [
        "@type"          => "Question",
        "name"           => $q['q'],
        "acceptedAnswer" => [
            "@type" => "Answer",
            "text"  => $q['a'],
        ],
    ], $faqQuestions),
];

$jsonLdBlocks[] = [
    "@context"   => "https://schema.org",
    "@type"      => "DataCatalog",
    "name"       => "Omani Logo Library, Public API",
    "url"        => "https://cardify.om/logos/press",
    "license"    => "https://cardify.om/logos/terms",
    "isAccessibleForFree" => true,
    "distribution" => [
        ["@type" => "DataDownload", "encodingFormat" => "application/json", "contentUrl" => "https://cardify.om/api/logos/list"],
        ["@type" => "DataDownload", "encodingFormat" => "application/json", "contentUrl" => "https://cardify.om/api/logos/sectors"],
        ["@type" => "DataDownload", "encodingFormat" => "application/json", "contentUrl" => "https://cardify.om/api/logos/stats"],
    ],
];

// ItemList of the first page of verified logos. Surfaces as image rich-results in Google.
$itemListItems = [];
$position = 1;
foreach (($data['rows'] ?? []) as $row) {
    if ($position > 20) break;
    $slug = $row['slug'] ?? '';
    if ($slug === '') continue;
    $name = $isAr && !empty($row['name_ar']) ? $row['name_ar'] : ($row['name_en'] ?? $row['name'] ?? $slug);
    $itemUrl  = 'https://cardify.om' . ($isAr ? '/ar' : '') . '/companies/' . $slug;
    $imagePath = $row['logo_webp_path'] ?? $row['logo_png_path'] ?? $row['logo_png_512_path'] ?? null;
    $itemListItems[] = [
        "@type"    => "ListItem",
        "position" => $position,
        "item"     => array_filter([
            "@type" => "Brand",
            "name"  => $name,
            "url"   => $itemUrl,
            "logo"  => $imagePath ? 'https://cardify.om' . $imagePath : null,
        ]),
    ];
    $position++;
}
if (!empty($itemListItems)) {
    $jsonLdBlocks[] = [
        "@context"        => "https://schema.org",
        "@type"           => "ItemList",
        "name"            => $title,
        "url"             => $canonical,
        "numberOfItems"   => count($itemListItems),
        "itemListOrder"   => "https://schema.org/ItemListOrderAscending",
        "itemListElement" => $itemListItems,
    ];
}

$extraHead = '';
foreach ($jsonLdBlocks as $block) {
    $extraHead .= '<script type="application/ld+json">'
               . json_encode($block, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
               . '</script>';
}
// Hreflang alternates
$extraHead .= '<link rel="alternate" hreflang="en" href="https://cardify.om/logos">';
$extraHead .= '<link rel="alternate" hreflang="ar" href="https://cardify.om/ar/logos">';
$extraHead .= '<link rel="alternate" hreflang="x-default" href="https://cardify.om/logos">';
$suppressDefaultHreflang = true;
require_once INCLUDES_DIR . '/ui-header.php';

function logos_esc($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
?>

<div class="bg-gradient-to-b from-gray-50 to-white pt-28 pb-16" <?= $isAr ? 'dir="rtl"' : '' ?>>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Hero -->
        <div class="text-center mb-10">
            <span class="inline-block px-3 py-1 rounded-full bg-blue-100 text-blue-700 text-xs font-semibold uppercase tracking-wide mb-4">
                <?= logos_esc(t('logos.hero_badge')) ?>
            </span>
            <h1 class="text-4xl sm:text-5xl font-extrabold text-gray-900 mb-4">
                <?= logos_esc(t('logos.hero_title')) ?>
            </h1>
            <p class="text-lg text-gray-600 max-w-2xl mx-auto">
                <?= logos_esc(t('logos.hero_subtitle', ['count' => number_format($total)])) ?>
            </p>
        </div>

        <!-- Filter bar (form fallback for no-JS; JS upgrades to instant search) -->
        <form id="logos-filter-form" method="get" class="flex flex-wrap gap-2 mb-6 p-3 bg-white border border-gray-200 rounded-xl shadow-sm">
            <div class="relative flex-1 min-w-[220px]">
                <i class="fa-solid fa-magnifying-glass absolute <?= $isAr ? 'right-3' : 'left-3' ?> top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                <input type="search" name="q" id="logos-q" value="<?= logos_esc($_GET['q'] ?? '') ?>"
                       placeholder="<?= logos_esc(t('logos.filter_search_placeholder')) ?>"
                       autocomplete="off"
                       class="w-full <?= $isAr ? 'pr-10 pl-3' : 'pl-10 pr-3' ?> py-2.5 text-sm bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
            </div>
            <select name="sector" id="logos-sector" class="px-3 py-2.5 text-sm bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                <option value=""><?= logos_esc(t('logos.filter_all_sectors')) ?></option>
                <?php foreach (($SECTOR_LABELS ?? $SECTORS) as $slug => $label): ?>
                    <option value="<?= logos_esc($slug) ?>" <?= ($_GET['sector'] ?? '') === $slug ? 'selected' : '' ?>>
                        <?= logos_esc($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <label class="inline-flex items-center gap-2 px-3 py-2.5 text-sm bg-gray-50 border border-gray-200 rounded-lg cursor-pointer hover:bg-gray-100">
                <input type="checkbox" name="verified" id="logos-verified" value="1" <?= !empty($_GET['verified']) ? 'checked' : '' ?>
                       class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                <span class="text-gray-700 font-medium"><?= logos_esc(t('logos.filter_verified_only')) ?></span>
            </label>
            <button id="logos-filter-submit" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold rounded-lg shadow shadow-blue-600/20 transition">
                <?= logos_esc(t('logos.filter_submit')) ?>
            </button>
            <span id="logos-search-indicator" class="hidden inline-flex items-center gap-1.5 text-xs text-gray-500 px-2">
                <i class="fa-solid fa-spinner fa-spin"></i>
                <?= logos_esc($isAr ? 'يبحث…' : 'Searching…') ?>
            </span>
            <input type="hidden" name="sort" id="logos-sort" value="<?= logos_esc($_GET['sort'] ?? 'alpha') ?>">
        </form>

        <!-- Sort + quick-status chips. Iconify-style pill row; clicking
             updates the hidden sort input + verified checkbox and reruns
             the instant-search filter via the existing JS hook. -->
        <div class="flex flex-wrap items-center gap-2 mb-5" id="logos-chip-bar">
            <span class="text-xs font-semibold text-gray-500 uppercase tracking-wide <?= $isAr ? 'ml-1' : 'mr-1' ?>">
                <?= logos_esc($isAr ? 'الفرز' : 'Sort') ?>:
            </span>
            <?php
                $_currentSort = $_GET['sort'] ?? 'alpha';
                $_sortChips = [
                    'alpha'    => ['label' => $isAr ? 'الترتيب الأبجدي' : 'A–Z',         'icon' => 'fa-arrow-down-a-z'],
                    'newest'   => ['label' => $isAr ? 'الأحدث' : 'Newest',                'icon' => 'fa-clock'],
                    'verified' => ['label' => $isAr ? 'الموثَّقة أولاً' : 'Verified first', 'icon' => 'fa-circle-check'],
                ];
            ?>
            <?php foreach ($_sortChips as $key => $meta): $active = $_currentSort === $key; ?>
                <button type="button"
                        data-sort-chip="<?= logos_esc($key) ?>"
                        class="cardify-chip group inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold border transition <?=
                            $active
                                ? 'bg-blue-600 text-white border-blue-600 shadow-sm shadow-blue-600/20'
                                : 'bg-white text-gray-700 border-gray-200 hover:border-gray-400 hover:text-gray-900'
                        ?>">
                    <i class="fa-solid <?= logos_esc($meta['icon']) ?> text-[10px]"></i>
                    <?= logos_esc($meta['label']) ?>
                </button>
            <?php endforeach; ?>

            <span class="hidden sm:inline-block w-px h-5 bg-gray-200 mx-1"></span>

            <span class="text-xs font-semibold text-gray-500 uppercase tracking-wide <?= $isAr ? 'ml-1' : 'mr-1' ?>">
                <?= logos_esc($isAr ? 'الحالة' : 'Status') ?>:
            </span>
            <?php $verifiedActive = !empty($_GET['verified']); ?>
            <button type="button" data-status-chip="all"
                    class="cardify-chip inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold border transition <?=
                        $verifiedActive
                            ? 'bg-white text-gray-700 border-gray-200 hover:border-gray-400'
                            : 'bg-gray-900 text-white border-gray-900 shadow-sm'
                    ?>">
                <i class="fa-solid fa-layer-group text-[10px]"></i>
                <?= logos_esc($isAr ? 'جميع الشعارات' : 'All logos') ?>
            </button>
            <button type="button" data-status-chip="verified"
                    class="cardify-chip inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold border transition <?=
                        $verifiedActive
                            ? 'bg-emerald-600 text-white border-emerald-600 shadow-sm shadow-emerald-600/20'
                            : 'bg-white text-emerald-700 border-emerald-200 hover:border-emerald-400'
                    ?>">
                <i class="fa-solid fa-circle-check text-[10px]"></i>
                <?= logos_esc($isAr ? 'الموثَّقة فقط' : 'Verified only') ?>
            </button>
        </div>

        <p class="text-sm text-gray-500 mb-5" id="logos-result-count">
            <?= number_format($data['total']) ?>
            <?= logos_esc($data['total'] === 1 ? t('logos.result_singular') : t('logos.result_plural')) ?>
        </p>

        <!-- Grid (JS instant-search swaps innerHTML on filter; SSR pass on first load for SEO) -->
        <div id="logos-grid" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3"
             data-default-rendered="1">
            <?php foreach ($data['rows'] as $r):
                $status = $r['logo_status'];
                $badgeColor = $status === 'verified' ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-gray-50 text-gray-600 ring-gray-200';
                $badgeLabel = $status === 'verified' ? t('logos.badge_verified') : t('logos.badge_indexed');

                // Auto-flip to the dark monochrome variant when the original
                // logo is light-leaning (e.g. white wordmark) and would render
                // invisible on the white card. Heuristic in LogoLibrary.
                $palette = json_decode((string) ($r['logo_palette'] ?? ''), true) ?: null;
                $useDarkVar = LogoLibrary::shouldUseDarkVariantOnLight($palette)
                              && !empty($r['logo_webp_dark_path'] ?? $r['logo_png_dark_path'] ?? $r['logo_svg_dark_path']);
                if ($useDarkVar) {
                    $src = $r['logo_webp_dark_path']
                        ?: $r['logo_png_dark_path']
                        ?: $r['logo_svg_dark_path'];
                } else {
                    $src = $r['logo_webp_path'] ?: $r['logo_png_512_path'] ?: $r['logo_png_path'] ?: $r['logo_svg_path'];
                }
                // Bust CF's 30-day immutable cache on retrims by appending the
                // logo_updated_at timestamp; same pattern as the card-render bg URL.
                if ($src && !empty($r['logo_updated_at'])) {
                    $src .= '?v=' . strtotime($r['logo_updated_at']);
                }
                $bg  = $r['logo_dominant_color'] ?: '#f9fafb';
            ?>
                <a href="/companies/<?= logos_esc($r['slug']) ?>"
                   class="cardify-logo-card group relative bg-white rounded-xl border border-gray-200 hover:border-gray-300 transition-all duration-200 overflow-hidden hover:-translate-y-0.5 hover:shadow-[0_8px_24px_-8px_rgba(15,23,42,0.18)]"
                   style="--brand-bg: <?= logos_esc($bg) ?>">
                    <div class="aspect-square flex items-center justify-center p-3 sm:p-4 md:p-5 bg-gradient-to-br from-gray-50 to-white border-b border-gray-100 transition-colors duration-200 group-hover:bg-[var(--brand-bg)] group-hover:bg-none">
                        <?php if ($src): ?>
                            <img src="<?= logos_esc($src) ?>" alt="<?= logos_esc($r['name_en']) ?>"
                                 loading="lazy"
                                 class="max-h-[70%] max-w-[80%] w-auto h-auto object-contain object-center transition-transform duration-200 group-hover:scale-105">
                        <?php else: ?>
                            <div class="text-gray-300 text-2xl font-bold">
                                <?= logos_esc(mb_substr($r['name_en'], 0, 2)) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="p-2.5">
                        <div class="text-xs font-semibold text-gray-900 truncate">
                            <?= logos_esc($isAr ? $r['name_ar'] : $r['name_en']) ?>
                        </div>
                        <span class="mt-1 inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full ring-1 ring-inset text-[10px] font-medium <?= $badgeColor ?>">
                            <?= logos_esc($badgeLabel) ?>
                        </span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

        <?php
            $totalPages = (int) ceil(max(1, $data['total']) / $data['per_page']);
            if ($totalPages > 1):
        ?>
            <nav class="mt-10 flex justify-center gap-1.5" aria-label="<?= logos_esc(t('logos.aria_pagination')) ?>">
                <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++):
                    $qs = $_GET; $qs['page'] = $p; ?>
                    <a href="?<?= http_build_query($qs) ?>"
                       class="px-3.5 py-1.5 text-sm rounded-lg font-medium <?= $p === $page
                            ? 'bg-blue-600 text-white shadow shadow-blue-600/30'
                            : 'bg-white border border-gray-200 text-gray-700 hover:border-blue-300 hover:text-blue-600' ?>">
                        <?= $p ?>
                    </a>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>

        <!-- Why this library, product context -->
        <div class="mt-16 grid grid-cols-1 md:grid-cols-3 gap-5">
            <div class="bg-white rounded-2xl border border-gray-200 p-6">
                <div class="w-10 h-10 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center mb-4">
                    <i class="fa-solid fa-magnifying-glass"></i>
                </div>
                <h3 class="font-bold text-gray-900 mb-1"><?= logos_esc(t('logos.why_indexed_title')) ?></h3>
                <p class="text-sm text-gray-600 leading-relaxed">
                    <?= logos_esc(t('logos.why_indexed_body')) ?>
                </p>
            </div>
            <div class="bg-white rounded-2xl border border-gray-200 p-6">
                <div class="w-10 h-10 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center mb-4">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <h3 class="font-bold text-gray-900 mb-1"><?= logos_esc(t('logos.why_owner_title')) ?></h3>
                <p class="text-sm text-gray-600 leading-relaxed">
                    <?= logos_esc(t('logos.why_owner_body')) ?>
                </p>
            </div>
            <div class="bg-white rounded-2xl border border-gray-200 p-6">
                <div class="w-10 h-10 rounded-lg bg-rose-50 text-rose-600 flex items-center justify-center mb-4">
                    <i class="fa-solid fa-shield-halved"></i>
                </div>
                <h3 class="font-bold text-gray-900 mb-1"><?= logos_esc(t('logos.why_trademark_title')) ?></h3>
                <p class="text-sm text-gray-600 leading-relaxed">
                    <?= logos_esc(t('logos.why_trademark_body')) ?>
                </p>
            </div>
        </div>

        <!-- FAQ, helps SEO (FAQPage schema) + trust -->
        <div class="mt-16">
            <h2 class="text-2xl sm:text-3xl font-extrabold text-gray-900 mb-2">
                <?= logos_esc(t('logos.faq_title')) ?>
            </h2>
            <p class="text-gray-600 mb-6">
                <?= logos_esc(t('logos.faq_subtitle')) ?>
            </p>
            <div class="space-y-3">
                <?php foreach ($faqQuestions as $i => $q): ?>
                    <details class="group bg-white border border-gray-200 rounded-xl hover:border-blue-300 transition overflow-hidden"<?= $i === 0 ? ' open' : '' ?>>
                        <summary class="flex items-center justify-between gap-4 px-5 py-4 cursor-pointer list-none">
                            <span class="font-semibold text-gray-900"><?= logos_esc($q['q']) ?></span>
                            <i class="fa-solid fa-chevron-down text-gray-400 text-sm transition group-open:rotate-180"></i>
                        </summary>
                        <div class="px-5 pb-4 text-gray-600 leading-relaxed">
                            <?= logos_esc($q['a']) ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Cross-link: GCC Business Index + country landing pages -->
        <div class="mt-12 bg-gray-50 border border-gray-200 rounded-2xl p-8 lg:p-10">
            <div class="flex items-center gap-3 mb-3">
                <i class="fa-solid fa-globe text-blue-600 text-xl"></i>
                <h2 class="text-2xl font-bold text-gray-900"><?= logos_esc(t('logos.gcc_title')) ?></h2>
            </div>
            <p class="text-gray-600 mb-5 max-w-2xl">
                <?= logos_esc(t('logos.gcc_body')) ?>
            </p>
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                <a href="/gcc/oman" class="group bg-white border border-gray-200 hover:border-blue-300 rounded-xl p-3 text-center transition">
                    <div class="text-2xl mb-1">🇴🇲</div>
                    <div class="text-xs font-semibold text-gray-900 group-hover:text-blue-700">Oman</div>
                    <div class="text-[10px] text-emerald-600 font-medium"><?= logos_esc(t('logos.gcc_status_live')) ?></div>
                </a>
                <a href="/gcc/saudi-arabia" class="group bg-white border border-gray-200 hover:border-blue-300 rounded-xl p-3 text-center transition">
                    <div class="text-2xl mb-1">🇸🇦</div>
                    <div class="text-xs font-semibold text-gray-900 group-hover:text-blue-700">Saudi Arabia</div>
                    <div class="text-[10px] text-gray-500">2026</div>
                </a>
                <a href="/gcc/uae" class="group bg-white border border-gray-200 hover:border-blue-300 rounded-xl p-3 text-center transition">
                    <div class="text-2xl mb-1">🇦🇪</div>
                    <div class="text-xs font-semibold text-gray-900 group-hover:text-blue-700">UAE</div>
                    <div class="text-[10px] text-gray-500">2026</div>
                </a>
                <a href="/gcc/qatar" class="group bg-white border border-gray-200 hover:border-blue-300 rounded-xl p-3 text-center transition">
                    <div class="text-2xl mb-1">🇶🇦</div>
                    <div class="text-xs font-semibold text-gray-900 group-hover:text-blue-700">Qatar</div>
                    <div class="text-[10px] text-gray-500">2026</div>
                </a>
                <a href="/gcc/bahrain" class="group bg-white border border-gray-200 hover:border-blue-300 rounded-xl p-3 text-center transition">
                    <div class="text-2xl mb-1">🇧🇭</div>
                    <div class="text-xs font-semibold text-gray-900 group-hover:text-blue-700">Bahrain</div>
                    <div class="text-[10px] text-gray-500">2026</div>
                </a>
                <a href="/gcc/kuwait" class="group bg-white border border-gray-200 hover:border-blue-300 rounded-xl p-3 text-center transition">
                    <div class="text-2xl mb-1">🇰🇼</div>
                    <div class="text-xs font-semibold text-gray-900 group-hover:text-blue-700">Kuwait</div>
                    <div class="text-[10px] text-gray-500">2026</div>
                </a>
            </div>
            <a href="/gcc-business-index" class="inline-flex items-center gap-2 mt-5 text-sm font-semibold text-blue-700 hover:text-blue-800">
                <?= logos_esc(t('logos.gcc_open_index')) ?>
                <i class="fa-solid fa-arrow-<?= $isAr ? 'left' : 'right' ?> text-xs"></i>
            </a>
        </div>

        <!-- Cross-link to OBI -->
        <div class="mt-6 bg-gradient-to-br from-blue-600 to-indigo-700 rounded-2xl p-8 lg:p-10 text-white">
            <div class="flex flex-col md:flex-row items-start md:items-center gap-6 md:justify-between">
                <div class="max-w-xl">
                    <h2 class="text-2xl sm:text-3xl font-bold mb-2">
                        <?= logos_esc(t('logos.obi_title')) ?>
                    </h2>
                    <p class="text-blue-100">
                        <?= logos_esc(t('logos.obi_body')) ?>
                    </p>
                </div>
                <a href="/oman-business-index" class="inline-flex items-center gap-2 px-6 py-3 rounded-lg bg-white text-blue-700 font-semibold hover:bg-blue-50 transition shrink-0">
                    <?= logos_esc(t('logos.obi_cta')) ?>
                    <i class="fa-solid fa-arrow-<?= $isAr ? 'left' : 'right' ?> text-sm"></i>
                </a>
            </div>
        </div>

        <!-- Legal footer -->
        <div class="mt-10 text-center text-xs text-gray-500 space-y-1">
            <p>
                <?= logos_esc(t('logos.legal_marks_note')) ?>
            </p>
            <p class="space-x-3">
                <a href="/logos/terms" class="text-gray-600 hover:text-blue-600 underline underline-offset-2"><?= logos_esc(t('logos.legal_terms')) ?></a>
                <span class="text-gray-300">·</span>
                <a href="/logos/press" class="text-gray-600 hover:text-blue-600 underline underline-offset-2"><?= logos_esc(t('logos.legal_press')) ?></a>
                <span class="text-gray-300">·</span>
                <a href="/logo-takedown" class="text-gray-600 hover:text-blue-600 underline underline-offset-2"><?= logos_esc(t('logos.legal_takedown')) ?></a>
            </p>
            <p class="pt-1">
                <?= logos_esc(t('logos.legal_attribution')) ?>
            </p>
        </div>
    </div>
</div>

<script>
// Instant search-as-you-type for /logos. Debounced fetch to
// /api/logos/list.php, replaces #logos-grid + result count in place.
// URL state via pushState so filtered views are shareable + back-button friendly.
(function () {
    var form    = document.getElementById('logos-filter-form');
    var qIn     = document.getElementById('logos-q');
    var secIn   = document.getElementById('logos-sector');
    var verIn   = document.getElementById('logos-verified');
    var sortIn  = document.getElementById('logos-sort');
    var grid    = document.getElementById('logos-grid');
    var count   = document.getElementById('logos-result-count');
    var submitBtn = document.getElementById('logos-filter-submit');
    var ind     = document.getElementById('logos-search-indicator');
    var chipBar = document.getElementById('logos-chip-bar');
    if (!form || !grid) return;

    var isAr = document.documentElement.lang === 'ar';
    var BADGE_VERIFIED = <?= json_encode(t('logos.badge_verified')) ?>;
    var BADGE_INDEXED  = <?= json_encode(t('logos.badge_indexed')) ?>;
    var RESULT_ONE     = <?= json_encode(t('logos.result_singular')) ?>;
    var RESULT_MANY    = <?= json_encode(t('logos.result_plural')) ?>;
    var EMPTY_TITLE    = <?= json_encode($isAr ? 'لا توجد نتائج' : 'No matches') ?>;
    var EMPTY_BODY     = <?= json_encode($isAr ? 'جرّب كلمة بحث مختلفة أو وسّع الفلاتر.' : 'Try a different keyword or widen the filters.') ?>;

    var debounceId = null;
    var inFlight   = null;

    function badgeClass(status) {
        return status === 'verified'
            ? 'bg-emerald-50 text-emerald-700 ring-emerald-200'
            : 'bg-gray-50 text-gray-600 ring-gray-200';
    }
    function badgeLabel(status) {
        return status === 'verified' ? BADGE_VERIFIED : BADGE_INDEXED;
    }
    function escapeAttr(s) { return String(s || '').replace(/[&"<>]/g, function (c) {
        return ({ '&': '&amp;', '"': '&quot;', '<': '&lt;', '>': '&gt;' })[c];
    }); }

    function renderCard(r) {
        var name = isAr ? (r.name_ar || r.name_en) : (r.name_en || r.name_ar);
        var src  = r.display_url;
        var bg   = r.dominant_color || '#f9fafb';
        var imgInner = src
            ? '<img src="' + escapeAttr(src) + '" alt="' + escapeAttr(name) + '" loading="lazy" class="max-h-[70%] max-w-[80%] w-auto h-auto object-contain object-center transition-transform duration-200 group-hover:scale-105">'
            : '<div class="text-gray-300 text-2xl font-bold">' + escapeAttr((name || '').slice(0, 2)) + '</div>';
        return ''
            + '<a href="/companies/' + escapeAttr(r.slug) + '"'
            +    ' class="cardify-logo-card group relative bg-white rounded-xl border border-gray-200 hover:border-gray-300 transition-all duration-200 overflow-hidden hover:-translate-y-0.5 hover:shadow-[0_8px_24px_-8px_rgba(15,23,42,0.18)]"'
            +    ' style="--brand-bg: ' + escapeAttr(bg) + '">'
            +   '<div class="aspect-square flex items-center justify-center p-3 sm:p-4 md:p-5 bg-gradient-to-br from-gray-50 to-white border-b border-gray-100 transition-colors duration-200 group-hover:bg-[var(--brand-bg)] group-hover:bg-none">'
            +     imgInner
            +   '</div>'
            +   '<div class="p-2.5">'
            +     '<div class="text-xs font-semibold text-gray-900 truncate">' + escapeAttr(name) + '</div>'
            +     '<span class="mt-1 inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full ring-1 ring-inset text-[10px] font-medium ' + badgeClass(r.status) + '">'
            +       escapeAttr(badgeLabel(r.status))
            +     '</span>'
            +   '</div>'
            + '</a>';
    }

    function renderEmpty() {
        return ''
            + '<div class="col-span-full py-16 text-center">'
            +   '<div class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-gray-100 text-gray-400 mb-3"><i class="fa-regular fa-folder-open text-xl"></i></div>'
            +   '<p class="text-lg font-semibold text-gray-700">' + escapeAttr(EMPTY_TITLE) + '</p>'
            +   '<p class="mt-1 text-sm text-gray-500">' + escapeAttr(EMPTY_BODY) + '</p>'
            + '</div>';
    }

    function buildQuery() {
        var p = new URLSearchParams();
        if (qIn.value.trim())   p.set('q', qIn.value.trim());
        if (secIn.value)        p.set('sector', secIn.value);
        if (verIn.checked)      p.set('verified', '1');
        if (sortIn && sortIn.value && sortIn.value !== 'alpha') p.set('sort', sortIn.value);
        p.set('per_page', '60');
        return p;
    }

    function syncUrlState() {
        var pageParams = new URLSearchParams();
        if (qIn.value.trim())   pageParams.set('q', qIn.value.trim());
        if (secIn.value)        pageParams.set('sector', secIn.value);
        if (verIn.checked)      pageParams.set('verified', '1');
        if (sortIn && sortIn.value && sortIn.value !== 'alpha') pageParams.set('sort', sortIn.value);
        var qs = pageParams.toString();
        var newUrl = location.pathname + (qs ? '?' + qs : '');
        if (newUrl !== location.pathname + location.search) {
            history.replaceState(null, '', newUrl);
        }
    }

    function repaintSortChips() {
        if (!chipBar) return;
        var current = sortIn ? sortIn.value : 'alpha';
        chipBar.querySelectorAll('[data-sort-chip]').forEach(function (btn) {
            var active = btn.getAttribute('data-sort-chip') === current;
            btn.className = 'cardify-chip group inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold border transition ' + (
                active
                    ? 'bg-blue-600 text-white border-blue-600 shadow-sm shadow-blue-600/20'
                    : 'bg-white text-gray-700 border-gray-200 hover:border-gray-400 hover:text-gray-900'
            );
        });
    }

    function repaintStatusChips() {
        if (!chipBar) return;
        var verified = !!verIn.checked;
        chipBar.querySelectorAll('[data-status-chip]').forEach(function (btn) {
            var want = btn.getAttribute('data-status-chip');
            var active = (want === 'verified') === verified;
            if (want === 'all') {
                btn.className = 'cardify-chip inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold border transition ' + (
                    active
                        ? 'bg-gray-900 text-white border-gray-900 shadow-sm'
                        : 'bg-white text-gray-700 border-gray-200 hover:border-gray-400'
                );
            } else {
                btn.className = 'cardify-chip inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold border transition ' + (
                    active
                        ? 'bg-emerald-600 text-white border-emerald-600 shadow-sm shadow-emerald-600/20'
                        : 'bg-white text-emerald-700 border-emerald-200 hover:border-emerald-400'
                );
            }
        });
    }

    function fmtCount(n) {
        var label = n === 1 ? RESULT_ONE : RESULT_MANY;
        return (n).toLocaleString(isAr ? 'ar-OM' : 'en') + ' ' + label;
    }

    function applyFilter() {
        ind.classList.remove('hidden');
        if (inFlight) inFlight.abort();
        inFlight = new AbortController();
        var params = buildQuery();
        fetch('/api/logos/list?' + params.toString(), {
            signal: inFlight.signal,
            headers: { 'Accept': 'application/json' }
        }).then(function (r) { return r.json(); })
          .then(function (data) {
              ind.classList.add('hidden');
              syncUrlState();
              count.textContent = fmtCount(data.total || 0);
              if (!data.results || !data.results.length) {
                  grid.innerHTML = renderEmpty();
                  return;
              }
              grid.innerHTML = data.results.map(renderCard).join('');
          })
          .catch(function (err) {
              if (err && err.name === 'AbortError') return;
              ind.classList.add('hidden');
          });
    }

    function debounceApply() {
        clearTimeout(debounceId);
        debounceId = setTimeout(applyFilter, 220);
    }

    qIn.addEventListener('input', debounceApply);
    secIn.addEventListener('change', applyFilter);
    verIn.addEventListener('change', function () { repaintStatusChips(); applyFilter(); });
    form.addEventListener('submit', function (e) { e.preventDefault(); applyFilter(); });
    if (submitBtn) submitBtn.style.display = 'none';

    // Chip-bar event delegation
    if (chipBar) {
        chipBar.addEventListener('click', function (e) {
            var sortChip = e.target.closest('[data-sort-chip]');
            if (sortChip) {
                if (sortIn) sortIn.value = sortChip.getAttribute('data-sort-chip');
                repaintSortChips();
                applyFilter();
                return;
            }
            var statusChip = e.target.closest('[data-status-chip]');
            if (statusChip) {
                verIn.checked = statusChip.getAttribute('data-status-chip') === 'verified';
                repaintStatusChips();
                applyFilter();
            }
        });
    }

    // ESC clears the search input
    qIn.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && qIn.value) {
            qIn.value = ''; applyFilter();
        }
    });

    // Back/forward navigation re-applies the query string state
    window.addEventListener('popstate', function () {
        var p = new URLSearchParams(location.search);
        qIn.value     = p.get('q') || '';
        secIn.value   = p.get('sector') || '';
        verIn.checked = p.get('verified') === '1';
        if (sortIn) sortIn.value = p.get('sort') || 'alpha';
        repaintSortChips();
        repaintStatusChips();
        applyFilter();
    });
})();
</script>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
