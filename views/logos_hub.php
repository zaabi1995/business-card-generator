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

        <!-- Filter bar -->
        <form method="get" class="flex flex-wrap gap-2 mb-6 p-3 bg-white border border-gray-200 rounded-xl shadow-sm">
            <div class="relative flex-1 min-w-[220px]">
                <i class="fa-solid fa-magnifying-glass absolute <?= $isAr ? 'right-3' : 'left-3' ?> top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                <input type="search" name="q" value="<?= logos_esc($_GET['q'] ?? '') ?>"
                       placeholder="<?= logos_esc(t('logos.filter_search_placeholder')) ?>"
                       class="w-full <?= $isAr ? 'pr-10 pl-3' : 'pl-10 pr-3' ?> py-2.5 text-sm bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
            </div>
            <select name="sector" class="px-3 py-2.5 text-sm bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                <option value=""><?= logos_esc(t('logos.filter_all_sectors')) ?></option>
                <?php foreach (($SECTOR_LABELS ?? $SECTORS) as $slug => $label): ?>
                    <option value="<?= logos_esc($slug) ?>" <?= ($_GET['sector'] ?? '') === $slug ? 'selected' : '' ?>>
                        <?= logos_esc($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <label class="inline-flex items-center gap-2 px-3 py-2.5 text-sm bg-gray-50 border border-gray-200 rounded-lg cursor-pointer hover:bg-gray-100">
                <input type="checkbox" name="verified" value="1" <?= !empty($_GET['verified']) ? 'checked' : '' ?>
                       class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                <span class="text-gray-700 font-medium"><?= logos_esc(t('logos.filter_verified_only')) ?></span>
            </label>
            <button class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold rounded-lg shadow shadow-blue-600/20 transition">
                <?= logos_esc(t('logos.filter_submit')) ?>
            </button>
        </form>

        <p class="text-sm text-gray-500 mb-5">
            <?= number_format($data['total']) ?>
            <?= logos_esc($data['total'] === 1 ? t('logos.result_singular') : t('logos.result_plural')) ?>
        </p>

        <!-- Grid -->
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
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
                   class="group relative bg-white rounded-xl border border-gray-200 hover:border-blue-300 hover:shadow-md transition-all overflow-hidden">
                    <div class="aspect-square flex items-center justify-center p-3 sm:p-4 md:p-5 bg-gradient-to-br from-gray-50 to-white border-b border-gray-100">
                        <?php if ($src): ?>
                            <img src="<?= logos_esc($src) ?>" alt="<?= logos_esc($r['name_en']) ?>"
                                 loading="lazy"
                                 class="max-h-[70%] max-w-[80%] w-auto h-auto object-contain object-center">
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

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
