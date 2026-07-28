<?php
/** @var string $title; @var bool $isAr; */
$pageTitle       = $title;
$pageDescription = t('logos.press_meta_desc');
require_once INCLUDES_DIR . '/ArTwins.php';
// Canonical follows the SERVED locale: an Arabic page that
// canonicalises to its English twin asks to be dropped.
$canonicalUrl    = (!empty($isAr) && ArTwins::arPath('/logos/press') !== null)
    ? 'https://cardify.om' . ArTwins::arPath('/logos/press')
    : 'https://cardify.om/logos/press';
$bodyClass       = 'bg-white';
$showNavigation  = true;

$extraHead =
    '<script type="application/ld+json">' . json_encode([
        "@context" => "https://schema.org",
        "@type"    => "WebPage",
        "name"     => $title,
        "url"      => $canonicalUrl,
        "inLanguage" => $isAr ? 'ar' : 'en',
        "about"    => ["@type" => "Thing", "name" => "Omani Logo Library press kit"],
        "publisher" => ["@type" => "Organization", "name" => "Cardify", "url" => "https://cardify.om"],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>'
    . '<script type="application/ld+json">' . json_encode([
        "@context" => "https://schema.org",
        "@type"    => "BreadcrumbList",
        "itemListElement" => [
            ["@type" => "ListItem", "position" => 1, "name" => "Cardify",      "item" => "https://cardify.om"],
            ["@type" => "ListItem", "position" => 2, "name" => t('logos.breadcrumb_library'), "item" => "https://cardify.om/logos"],
            ["@type" => "ListItem", "position" => 3, "name" => t('logos.press_breadcrumb'), "item" => $canonicalUrl],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';

require_once INCLUDES_DIR . '/ui-header.php';

function logos_press_esc($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
?>

<div class="bg-gradient-to-b from-gray-50 to-white pt-28 pb-16" <?= $isAr ? 'dir="rtl"' : '' ?>>
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">

        <nav class="flex items-center gap-1.5 text-sm text-gray-500 mb-6 flex-wrap">
            <a href="/logos" class="hover:text-blue-600"><?= logos_press_esc(t('logos.breadcrumb_library')) ?></a>
            <i class="fa-solid fa-chevron-<?= $isAr ? 'left' : 'right' ?> text-[10px] text-gray-300"></i>
            <span class="text-gray-900 font-medium"><?= logos_press_esc(t('logos.press_breadcrumb')) ?></span>
        </nav>

        <div class="mb-10">
            <span class="inline-block px-3 py-1 rounded-full bg-blue-100 text-blue-700 text-xs font-semibold uppercase tracking-wide mb-3">
                <?= logos_press_esc(t('logos.press_badge')) ?>
            </span>
            <h1 class="text-3xl sm:text-4xl font-extrabold text-gray-900 mb-3">
                <?= logos_press_esc(t('logos.press_h1')) ?>
            </h1>
            <p class="text-lg text-gray-600">
                <?= logos_press_esc(t('logos.press_intro')) ?>
            </p>
        </div>

        <!-- Fast facts -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-10">
            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <div class="text-3xl font-extrabold text-gray-900">2,400+</div>
                <div class="text-xs text-gray-500 mt-1 uppercase tracking-wide"><?= logos_press_esc(t('logos.press_stat_companies')) ?></div>
            </div>
            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <div class="text-3xl font-extrabold text-gray-900">23</div>
                <div class="text-xs text-gray-500 mt-1 uppercase tracking-wide"><?= logos_press_esc(t('logos.press_stat_sectors')) ?></div>
            </div>
            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <div class="text-3xl font-extrabold text-gray-900">EN + AR</div>
                <div class="text-xs text-gray-500 mt-1 uppercase tracking-wide"><?= logos_press_esc(t('logos.press_stat_bilingual')) ?></div>
            </div>
            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <div class="text-3xl font-extrabold text-gray-900">24h</div>
                <div class="text-xs text-gray-500 mt-1 uppercase tracking-wide"><?= logos_press_esc(t('logos.press_stat_sla')) ?></div>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-gray-200 p-6 lg:p-8 mb-8">
            <h2 class="text-xl font-bold text-gray-900 mb-3"><?= logos_press_esc(t('logos.press_how_title')) ?></h2>
            <ul class="space-y-3 text-gray-700">
                <li class="flex gap-3">
                    <i class="fa-solid fa-magnifying-glass mt-1 w-5 text-blue-600"></i>
                    <span><?= t('logos.press_how_1') ?></span>
                </li>
                <li class="flex gap-3">
                    <i class="fa-solid fa-circle-check mt-1 w-5 text-emerald-600"></i>
                    <span><?= t('logos.press_how_2') ?></span>
                </li>
                <li class="flex gap-3">
                    <i class="fa-solid fa-download mt-1 w-5 text-gray-600"></i>
                    <span><?= t('logos.press_how_3') ?></span>
                </li>
                <li class="flex gap-3">
                    <i class="fa-solid fa-shield-halved mt-1 w-5 text-rose-600"></i>
                    <span><?= t('logos.press_how_4') ?></span>
                </li>
            </ul>
        </div>

        <!-- API quickstart -->
        <div class="bg-gray-900 rounded-2xl p-6 lg:p-8 mb-8">
            <h2 class="text-xl font-bold text-white mb-3"><?= logos_press_esc(t('logos.press_api_title')) ?></h2>
            <p class="text-gray-400 text-sm mb-4">
                <?= logos_press_esc(t('logos.press_api_body')) ?>
            </p>
            <pre class="text-gray-100 text-sm font-mono overflow-x-auto bg-black/30 rounded-lg p-4 leading-relaxed" dir="ltr"><code># List + search (60 req/min/IP)
GET /api/logos/list?q=bank&sort=verified&sector=finance&verified=1&page=1&per_page=50
GET /api/logos/show?slug=omantel
GET /api/logos/sectors
GET /api/logos/stats
GET /api/logos/random          # one random brand, no-cache</code></pre>

            <div class="grid sm:grid-cols-2 gap-4 mt-5" dir="ltr">
                <div>
                    <p class="text-xs font-bold text-gray-300 uppercase tracking-wide mb-2">/list query params</p>
                    <ul class="text-xs text-gray-400 space-y-1 font-mono">
                        <li><span class="text-emerald-400">q</span> &mdash; search name_en / name_ar / slug</li>
                        <li><span class="text-emerald-400">sort</span> &mdash; alpha | newest | verified</li>
                        <li><span class="text-emerald-400">sector</span> &mdash; e.g. finance, technology</li>
                        <li><span class="text-emerald-400">verified</span> &mdash; 1 = verified only</li>
                        <li><span class="text-emerald-400">page</span> / <span class="text-emerald-400">per_page</span> &mdash; pagination (max 100)</li>
                    </ul>
                </div>
                <div>
                    <p class="text-xs font-bold text-gray-300 uppercase tracking-wide mb-2">each result includes</p>
                    <ul class="text-xs text-gray-400 space-y-1 font-mono">
                        <li><span class="text-blue-400">display_url</span> &mdash; auto-picked (dark on light logos)</li>
                        <li><span class="text-blue-400">urls.{svg,png_512,png_1024,png_2048,webp}</span></li>
                        <li><span class="text-blue-400">urls.{svg,png,webp}_dark</span> &mdash; black monochrome</li>
                        <li><span class="text-blue-400">urls.{svg,png,webp}_white</span> &mdash; white monochrome</li>
                        <li><span class="text-blue-400">palette[]</span> &mdash; up to 5 brand hex colors</li>
                        <li><span class="text-blue-400">dominant_color, status, profile_url</span></li>
                    </ul>
                </div>
            </div>
            <p class="text-[11px] text-gray-500 mt-4" dir="ltr">
                CORS open (Access-Control-Allow-Origin: *). Every asset URL carries a
                <span class="font-mono">?v=</span> cache-buster. Attribution to
                <span class="text-gray-300">cardify.om/logos</span> appreciated for editorial use.
            </p>
        </div>

        <!-- Contact -->
        <div class="bg-white rounded-2xl border border-gray-200 p-6 lg:p-8">
            <h2 class="text-xl font-bold text-gray-900 mb-3"><?= logos_press_esc(t('logos.press_contact_title')) ?></h2>
            <p class="text-gray-600 mb-4">
                <?= logos_press_esc(t('logos.press_contact_body')) ?>
            </p>
            <div class="flex flex-wrap gap-3">
                <a href="mailto:press@cardify.om" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold shadow shadow-blue-600/20 transition">
                    <i class="fa-solid fa-envelope text-xs"></i>
                    press@cardify.om
                </a>
                <a href="https://wa.me/96898899100" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-white border border-gray-200 text-gray-700 hover:border-emerald-300 hover:text-emerald-700 text-sm font-semibold transition">
                    <i class="fa-brands fa-whatsapp text-emerald-600"></i>
                    +968 9889 9100 <?= logos_press_esc(t('logos.press_bhd_suffix')) ?>
                </a>
            </div>
        </div>
    </div>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
