<?php
/**
 * Omani Logo Library, Sector view.
 * Same canonical Cardify chrome as hub.
 *
 * @var array  $data
 * @var string $sectorSlug
 * @var string $sectorLabel
 * @var string $title
 * @var string $canonical
 * @var array  $SECTORS
 * @var bool   $isAr
 */

if (!defined('INCLUDES_DIR')) { http_response_code(404); exit; }
// Title / description lean into the "Omani {sector} logo" long-tail, that's
// the query pattern for Google Image search on branded marks.
$pageTitle       = t('logos.sector_meta_title', ['sector' => $sectorLabel]);
$pageDescription = mb_substr(t('logos.sector_meta_desc', ['sector' => $sectorLabel, 'count' => (string) $data['total']]), 0, 155);
$canonicalUrl    = $canonical;
$bodyClass       = 'bg-white';
$showNavigation  = true;
$ogImage         = "https://cardify.om/storage/og/logos/{$sectorSlug}.png";

// JSON-LD: CollectionPage + BreadcrumbList + ItemList of up to 20 logo
// samples (helps Google understand what's on the page for image carousels).
$itemListEls = [];
foreach (array_slice($data['rows'], 0, 20) as $idx => $r) {
    $src = $r['logo_svg_path']
        ?: $r['logo_png_path']
        ?: $r['logo_webp_path']
        ?: $r['logo_png_512_path'];
    if (!$src) continue;
    $itemListEls[] = [
        "@type"    => "ListItem",
        "position" => $idx + 1,
        "url"      => "https://cardify.om/companies/" . $r['slug'],
        "name"     => $r['name_en'],
        "image"    => "https://cardify.om" . $src,
    ];
}

$jsonLdBlocks = [
    [
        "@context"    => "https://schema.org",
        "@type"       => "CollectionPage",
        "name"        => $title,
        "url"         => $canonical,
        "inLanguage"  => $isAr ? 'ar' : 'en',
        "numberOfItems" => $data['total'],
        "about"       => ["@type" => "Thing", "name" => "Omani $sectorLabel companies"],
    ],
    [
        "@context" => "https://schema.org",
        "@type"    => "BreadcrumbList",
        "itemListElement" => [
            ["@type" => "ListItem", "position" => 1, "name" => "Cardify",       "item" => "https://cardify.om"],
            ["@type" => "ListItem", "position" => 2, "name" => "Logo Library",  "item" => "https://cardify.om/logos"],
            ["@type" => "ListItem", "position" => 3, "name" => $sectorLabel,    "item" => $canonical],
        ],
    ],
];
if ($itemListEls) {
    $jsonLdBlocks[] = [
        "@context"        => "https://schema.org",
        "@type"           => "ItemList",
        "itemListOrder"   => "https://schema.org/ItemListOrderAscending",
        "numberOfItems"   => count($itemListEls),
        "itemListElement" => $itemListEls,
    ];
}

$extraHead = '';
foreach ($jsonLdBlocks as $block) {
    $extraHead .= '<script type="application/ld+json">'
               . json_encode($block, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
               . '</script>';
}
// Hreflang alternates
$extraHead .= '<link rel="alternate" hreflang="en" href="https://cardify.om/logos/' . htmlspecialchars($sectorSlug, ENT_QUOTES) . '">';
$extraHead .= '<link rel="alternate" hreflang="ar" href="https://cardify.om/ar/logos/' . htmlspecialchars($sectorSlug, ENT_QUOTES) . '">';
$extraHead .= '<link rel="alternate" hreflang="x-default" href="https://cardify.om/logos/' . htmlspecialchars($sectorSlug, ENT_QUOTES) . '">';
$suppressDefaultHreflang = true;
require_once INCLUDES_DIR . '/ui-header.php';

function logos_sector_esc($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
$page = $data['page'];
?>

<div class="bg-gradient-to-b from-gray-50 to-white pt-28 pb-16" <?= $isAr ? 'dir="rtl"' : '' ?>>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Breadcrumb -->
        <nav class="flex items-center gap-1.5 text-sm text-gray-500 mb-6 flex-wrap">
            <a href="/" class="hover:text-blue-600">Cardify</a>
            <i class="fa-solid fa-chevron-<?= $isAr ? 'left' : 'right' ?> text-[10px] text-gray-300"></i>
            <a href="/logos" class="hover:text-blue-600"><?= logos_sector_esc(t('logos.breadcrumb_library')) ?></a>
            <i class="fa-solid fa-chevron-<?= $isAr ? 'left' : 'right' ?> text-[10px] text-gray-300"></i>
            <span class="text-gray-900 font-medium"><?= logos_sector_esc($sectorLabel) ?></span>
        </nav>

        <!-- Header -->
        <div class="mb-8">
            <span class="inline-block px-3 py-1 rounded-full bg-blue-100 text-blue-700 text-xs font-semibold uppercase tracking-wide mb-3">
                <?= logos_sector_esc(t('logos.sector_badge')) ?>
            </span>
            <h1 class="text-3xl sm:text-4xl font-extrabold text-gray-900 mb-2">
                <?= logos_sector_esc(t('logos.sector_h1', ['sector' => $sectorLabel])) ?>
            </h1>
            <p class="text-lg text-gray-600">
                <?= number_format($data['total']) ?>
                <?= logos_sector_esc($data['total'] === 1 ? t('logos.sector_brand_indexed_singular') : t('logos.sector_brand_indexed_plural')) ?>
            </p>
        </div>

        <!-- Grid -->
        <?php if ($data['total'] === 0): ?>
            <div class="bg-white border border-gray-200 rounded-2xl p-10 text-center">
                <div class="w-14 h-14 mx-auto rounded-xl bg-gray-50 text-gray-400 flex items-center justify-center mb-4">
                    <i class="fa-solid fa-folder-open text-xl"></i>
                </div>
                <h3 class="font-bold text-gray-900 mb-1"><?= logos_sector_esc(t('logos.sector_empty_title')) ?></h3>
                <p class="text-sm text-gray-500 max-w-md mx-auto mb-5">
                    <?= logos_sector_esc(t('logos.sector_empty_body')) ?>
                </p>
                <a href="/logos" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold transition">
                    <?= logos_sector_esc(t('logos.sector_back_library')) ?>
                </a>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
                <?php foreach ($data['rows'] as $r):
                    $status = $r['logo_status'];
                    $badgeColor = $status === 'verified' ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-gray-50 text-gray-600 ring-gray-200';
                    $badgeLabel = $status === 'verified' ? t('logos.badge_verified') : t('logos.badge_indexed');
                    $src = $r['logo_webp_path'] ?: $r['logo_png_512_path'] ?: $r['logo_png_path'] ?: $r['logo_svg_path'];
                ?>
                    <a href="/companies/<?= logos_sector_esc($r['slug']) ?>"
                       class="group relative bg-white rounded-xl border border-gray-200 hover:border-blue-300 hover:shadow-md transition-all overflow-hidden">
                        <div class="aspect-square flex items-center justify-center p-3 sm:p-4 md:p-5 bg-gradient-to-br from-gray-50 to-white border-b border-gray-100">
                            <?php if ($src): ?>
                                <img src="<?= logos_sector_esc($src) ?>" alt="<?= logos_sector_esc($r['name_en']) ?>"
                                     loading="lazy"
                                     class="max-h-[70%] max-w-[80%] w-auto h-auto object-contain object-center">
                            <?php else: ?>
                                <div class="text-gray-300 text-xl font-bold">
                                    <?= logos_sector_esc(mb_substr($r['name_en'], 0, 2)) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="p-2.5">
                            <div class="text-xs font-semibold text-gray-900 truncate">
                                <?= logos_sector_esc($isAr ? $r['name_ar'] : $r['name_en']) ?>
                            </div>
                            <span class="mt-1 inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full ring-1 ring-inset text-[10px] font-medium <?= $badgeColor ?>">
                                <?= logos_sector_esc($badgeLabel) ?>
                            </span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php
                $totalPages = (int) ceil(max(1, $data['total']) / $data['per_page']);
                if ($totalPages > 1):
            ?>
                <nav class="mt-10 flex justify-center gap-1.5" aria-label="<?= logos_sector_esc(t('logos.aria_pagination')) ?>">
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
        <?php endif; ?>

        <!-- Cross-link -->
        <div class="mt-12 flex flex-wrap gap-2 justify-center text-sm text-gray-600">
            <?= logos_sector_esc(t('logos.sector_explore_also')) ?>
            <a href="/logos" class="text-blue-600 hover:text-blue-700 font-medium hover:underline">
                <?= logos_sector_esc(t('logos.sector_full_library')) ?>
            </a>
            <span class="text-gray-300">·</span>
            <a href="/oman-business-index" class="text-blue-600 hover:text-blue-700 font-medium hover:underline">
                Oman Business Index
            </a>
        </div>
    </div>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
