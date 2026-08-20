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
// Hreflang alternates, from the ArTwins oracle rather than a hand-written
// triple: this file used to concatenate the '/ar' prefix itself, which made it
// a fourth independent source of truth for the same fact and is why the
// sitemap and this page disagreed about /logos/{sector} (r80, llm27-46).
require_once INCLUDES_DIR . '/ArTwins.php';
$extraHead .= ArTwins::pairLinks('/logos/' . $sectorSlug);
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
                    if ($src && !empty($r['logo_updated_at'])) {
                        $src .= '?v=' . dbTs($r['logo_updated_at']);
                    }
                    // Stash row data for the quick-preview modal (same shape as
                    // logos_hub.php). Variant URLs not all selected here, so
                    // some keys will be null; the modal handles that gracefully.
                    $_ver = !empty($r['logo_updated_at']) ? '?v=' . strtotime($r['logo_updated_at']) : '';
                    $_abs = fn(?string $p) => $p ? $p . $_ver : null;
                    $_quickJson = [
                        'slug'           => $r['slug'],
                        'name_en'        => $r['name_en'],
                        'name_ar'        => $r['name_ar'] ?? null,
                        'status'         => $r['logo_status'],
                        'palette'        => $palette ?: [],
                        'dominant_color' => $r['logo_dominant_color'] ?? null,
                        'display_url'    => $src,
                        'urls' => [
                            'svg'      => $_abs($r['logo_svg_path']     ?? null),
                            'png_512'  => $_abs($r['logo_png_512_path'] ?? null),
                            'png_1024' => $_abs($r['logo_png_path']     ?? null),
                            'webp'     => $_abs($r['logo_webp_path']    ?? null),
                            'svg_dark'  => $_abs($r['logo_svg_dark_path']  ?? null),
                            'png_dark'  => $_abs($r['logo_png_dark_path']  ?? null),
                            'webp_dark' => $_abs($r['logo_webp_dark_path'] ?? null),
                        ],
                        'profile_url'    => '/companies/' . $r['slug'],
                    ];
                ?>
                    <?php $bg = $r['logo_dominant_color'] ?: '#f9fafb'; ?>
                    <a href="/companies/<?= logos_sector_esc($r['slug']) ?>"
                       class="cardify-logo-card group relative bg-white rounded-xl border border-gray-200 hover:border-gray-300 transition-all duration-200 overflow-hidden hover:-translate-y-0.5 hover:shadow-[0_8px_24px_-8px_rgba(15,23,42,0.18)]"
                       style="--brand-bg: <?= logos_sector_esc($bg) ?>"
                       data-logo='<?= htmlspecialchars(json_encode($_quickJson, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, "UTF-8") ?>'>
                        <div class="aspect-square flex items-center justify-center p-3 sm:p-4 md:p-5 bg-gradient-to-br from-gray-50 to-white border-b border-gray-100 transition-colors duration-200 group-hover:bg-[var(--brand-bg)] group-hover:bg-none">
                            <?php if ($src): ?>
                                <img src="<?= logos_sector_esc($src) ?>" alt="<?= logos_sector_esc($r['name_en']) ?>"
                                     loading="lazy"
                                     class="max-h-[70%] max-w-[80%] w-auto h-auto object-contain object-center transition-transform duration-200 group-hover:scale-105">
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
                           style="min-width:2.5rem; padding:0.5rem 0.875rem;"
                           class="inline-flex items-center justify-center text-sm rounded-lg font-medium <?= $p === $page
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

<!-- Quick-preview modal (mirrors logos_hub.php). Tap any grid card to
     see logo + brand info + variant downloads without a page nav. -->
<dialog id="logo-quick-preview"
        class="rounded-2xl p-0 backdrop:bg-black/40 max-w-2xl w-[92vw] border border-gray-200 shadow-2xl">
    <div class="p-6" id="logo-quick-body"></div>
</dialog>

<script>
(function () {
    var qpDlg  = document.getElementById('logo-quick-preview');
    var qpBody = document.getElementById('logo-quick-body');
    if (!qpDlg || !qpBody) return;

    var isAr = document.documentElement.lang === 'ar';
    var BADGE_VERIFIED = <?= json_encode(t('logos.badge_verified')) ?>;
    var BADGE_INDEXED  = <?= json_encode(t('logos.badge_indexed')) ?>;

    function escapeAttr(s) { return String(s || '').replace(/[&"<>]/g, function (c) {
        return ({ '&': '&amp;', '"': '&quot;', '<': '&lt;', '>': '&gt;' })[c];
    }); }
    function badgeClass(s) {
        return s === 'verified' ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-gray-50 text-gray-600 ring-gray-200';
    }
    function badgeLabel(s) { return s === 'verified' ? BADGE_VERIFIED : BADGE_INDEXED; }
    function fmtChip(label, url, icon) {
        if (!url) return '';
        return '<a href="' + escapeAttr(url) + '" rel="nofollow" download'
            +    ' class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg bg-gray-50 border border-gray-200 hover:border-gray-400 transition text-xs font-medium text-gray-800">'
            +    '<i class="fa-solid ' + icon + ' text-[10px]"></i>'
            +    escapeAttr(label)
            +  '</a>';
    }

    function openQuickPreview(row) {
        var name = isAr ? (row.name_ar || row.name_en) : (row.name_en || row.name_ar);
        var pal  = (row.palette || []).slice(0, 5);
        var urls = row.urls || {};
        var swatches = pal.map(function (hex) {
            hex = String(hex).toUpperCase();
            return '<span class="inline-flex items-center gap-1.5 px-1.5 py-0.5 rounded bg-white border border-gray-200 text-[10px] font-mono text-gray-700">'
                +    '<span class="w-3 h-3 rounded-full ring-1 ring-inset ring-black/10" style="background:' + escapeAttr(hex) + '"></span>'
                +    escapeAttr(hex)
                + '</span>';
        }).join(' ');
        var fmtRow = ''
            + fmtChip('SVG',       urls.svg,      'fa-bezier-curve')
            + fmtChip('PNG · 1024', urls.png_1024, 'fa-image')
            + fmtChip('WebP',      urls.webp,     'fa-image');
        var darkRow = ''
            + fmtChip('SVG · dark',  urls.svg_dark,  'fa-bezier-curve')
            + fmtChip('PNG · dark',  urls.png_dark,  'fa-image')
            + fmtChip('WebP · dark', urls.webp_dark, 'fa-image');

        qpBody.innerHTML = ''
            + '<div class="flex items-start gap-4 mb-5">'
            +   '<div class="shrink-0 w-20 h-20 rounded-xl bg-gradient-to-br from-gray-50 to-white border border-gray-200 flex items-center justify-center p-2">'
            +     (row.display_url ? '<img src="' + escapeAttr(row.display_url) + '" alt="' + escapeAttr(name) + '" class="max-h-[80%] max-w-[85%] object-contain">' : '')
            +   '</div>'
            +   '<div class="min-w-0 flex-1">'
            +     '<p class="text-xs uppercase tracking-wider text-gray-400 mb-1">' + escapeAttr(<?= json_encode($isAr ? 'مكتبة الشعارات' : 'Logo Library') ?>) + '</p>'
            +     '<h2 class="text-xl font-bold text-gray-900 truncate">' + escapeAttr(name) + '</h2>'
            +     '<span class="inline-flex items-center gap-1.5 mt-2 px-2 py-0.5 rounded-full ring-1 ring-inset text-[10px] font-semibold ' + badgeClass(row.status) + '">' + escapeAttr(badgeLabel(row.status)) + '</span>'
            +   '</div>'
            +   '<button type="button" data-qp-close class="shrink-0 -mt-1 -mr-1 w-9 h-9 rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-700 transition flex items-center justify-center" aria-label="Close"><i class="fa-solid fa-xmark text-lg"></i></button>'
            + '</div>'
            + (pal.length ? '<div class="mb-4"><p class="text-xs text-gray-500 mb-1.5">' + escapeAttr(<?= json_encode($isAr ? 'لوحة الألوان' : 'Brand palette') ?>) + '</p><div class="flex flex-wrap gap-1.5">' + swatches + '</div></div>' : '')
            + '<div class="mb-3"><p class="text-xs text-gray-500 mb-1.5">' + escapeAttr(<?= json_encode($isAr ? 'الأصلي' : 'Original') ?>) + '</p><div class="flex flex-wrap gap-1.5">' + (fmtRow || '<span class="text-xs text-gray-400">,</span>') + '</div></div>'
            + (darkRow ? '<div class="mb-4"><p class="text-xs text-gray-500 mb-1.5">' + escapeAttr(<?= json_encode($isAr ? 'داكن (للخلفيات الفاتحة)' : 'Dark (for light backgrounds)') ?>) + '</p><div class="flex flex-wrap gap-1.5">' + darkRow + '</div></div>' : '')
            + '<div class="flex items-center justify-end gap-2 pt-3 border-t border-gray-100">'
            +   '<a href="' + escapeAttr(row.profile_url || ('/companies/' + (row.slug || ''))) + '" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">'
            +     escapeAttr(<?= json_encode($isAr ? 'فتح الصفحة الكاملة' : 'Open full page') ?>) + ' <i class="fa-solid fa-arrow-' + (isAr ? 'left' : 'right') + ' text-xs"></i>'
            +   '</a>'
            + '</div>';
        try { qpDlg.showModal(); } catch (_) { qpDlg.setAttribute('open', ''); }
    }

    document.addEventListener('click', function (e) {
        var card = e.target.closest('[data-logo]');
        if (!card) return;
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button === 1) return;
        e.preventDefault();
        var raw = card.getAttribute('data-logo');
        if (!raw) return;
        try { openQuickPreview(JSON.parse(raw)); }
        catch (_) { location.href = card.getAttribute('href'); }
    });
    qpDlg.addEventListener('click', function (e) {
        if (e.target && e.target.closest('[data-qp-close]')) qpDlg.close();
        if (e.target === qpDlg) qpDlg.close();
    });
})();
</script>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
