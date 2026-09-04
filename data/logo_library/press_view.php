<?php
/** @var string $title; @var bool $isAr; */
$pageTitle       = $title;
$pageDescription = t('logos.press_meta_desc');
require_once INCLUDES_DIR . '/ArTwins.php';
require_once INCLUDES_DIR . '/JsonLd.php';
// Canonical follows the SERVED locale: an Arabic page that
// canonicalises to its English twin asks to be dropped.
$canonicalUrl    = (!empty($isAr) && ArTwins::arPath('/logos/press') !== null)
    ? 'https://cardify.om' . ArTwins::arPath('/logos/press')
    : 'https://cardify.om/logos/press';

// llm238-2 (r379): the crumb is DECIDED ONCE and both renderers -- the visible
// <nav> and the BreadcrumbList node -- read it, so a reader and a crawler
// cannot be sent into different language trees. It used to be hardcoded
// '/logos' in both places, so the Arabic label t('logos.breadcrumb_library')
// linked into the English tree while /ar/logos answers 200. Same rule as the
// header and footer; the '/ar' prefix is never concatenated here (llm27-46).
$crumbHomeHref  = ArTwins::navLink('',      '/', !empty($isAr));
$crumbLogosHref = ArTwins::navLink('logos', '/', !empty($isAr));

// r74 / bhd-group-seo-llm73-4. The API reference below was English under
// <html lang="ar">: ten blocks, every parameter gloss and the CORS paragraph.
// It survived because the rest of the page goes through t() and this one
// section was hand-written markup, and because the page's Arabic share stays
// high while a minority of its blocks are English (llm72-1's shape).
//
// A bilingual array rather than ten new t() keys, for the reason llm73-2
// names: a t() key can be added to the English catalogue alone and the page
// still renders, silently, in English. Here a missing 'ar' is a missing array
// key that logos_press_gloss() refuses.
//
// IDENTIFIERS STAY LATIN. sort, per_page, urls.svg_dark, palette[] and the
// header name are what a developer types; translating them would document an
// API that does not exist. Only the prose after the em-dash moves.
$LOGOS_PRESS_I18N = [
    'params_label'  => ['en' => '/list query params',
                        'ar' => 'معاملات الاستعلام في ‎/list'],
    'result_label'  => ['en' => 'each result includes',
                        'ar' => 'كل نتيجة تتضمن'],
    'q'             => ['en' => 'search <code>name_en</code> / <code>name_ar</code> / <code>slug</code>',
                        'ar' => 'بحث في <code>name_en</code> و <code>name_ar</code> و <code>slug</code>'],
    'sort'          => ['en' => '<code>alpha</code> | <code>newest</code> | <code>verified</code>',
                        'ar' => '<code>alpha</code> أو <code>newest</code> أو <code>verified</code>'],
    'sector'        => ['en' => 'e.g. <code>finance</code>, <code>technology</code>',
                        'ar' => 'مثل <code>finance</code> و <code>technology</code>'],
    'verified'      => ['en' => '1 = verified only',
                        'ar' => '‎1 تعني الموثّقة فقط'],
    'page'          => ['en' => 'pagination (max 100)',
                        'ar' => 'ترقيم الصفحات (100 كحد أقصى)'],
    'display_url'   => ['en' => 'auto-picked (dark on light logos)',
                        'ar' => 'يُختار تلقائياً (الداكن على الشعارات الفاتحة)'],
    'dark'          => ['en' => 'black monochrome',
                        'ar' => 'أحادي اللون بالأسود'],
    'white'         => ['en' => 'white monochrome',
                        'ar' => 'أحادي اللون بالأبيض'],
    'palette'       => ['en' => 'up to 5 brand hex colors',
                        'ar' => 'حتى 5 ألوان hex للعلامة'],
    'cors'          => ['en' => 'CORS open (Access-Control-Allow-Origin: *). Every asset URL carries a',
                        'ar' => 'CORS مفتوح (‎Access-Control-Allow-Origin: *‎). كل رابط أصل يحمل'],
    'cachebuster'   => ['en' => 'cache-buster. Attribution to',
                        'ar' => 'لكسر التخزين المؤقت. ذكر المصدر'],
    'attribution'   => ['en' => 'appreciated for editorial use.',
                        'ar' => 'موضع تقدير عند الاستخدام التحريري.'],
];

// Rows may carry <code> for API values. logos_press_gloss_html() escapes
// everything except that one tag, so the record stays authored-content and
// a stray < in a future gloss still cannot become markup.
$logos_press_gloss = function (string $key) use ($LOGOS_PRESS_I18N, $isAr): string {
    if (!isset($LOGOS_PRESS_I18N[$key])) {
        throw new RuntimeException("logos press gloss '$key' is not defined");
    }
    $lang = !empty($isAr) ? 'ar' : 'en';
    $row  = $LOGOS_PRESS_I18N[$key];
    if (!isset($row[$lang]) || trim($row[$lang]) === '') {
        // llm73-4: falling back to English here is exactly the defect. A page
        // that declares lang="ar" and serves English prose is worse than a
        // page that fails loudly in staging.
        throw new RuntimeException("logos press gloss '$key' has no '$lang' text");
    }
    return $row[$lang];
};

$logos_press_gloss_html = function (string $key) use ($logos_press_gloss): string {
    $safe = htmlspecialchars($logos_press_gloss($key), ENT_QUOTES, 'UTF-8');
    return str_replace(['&lt;code&gt;', '&lt;/code&gt;'],
                       ['<code dir="ltr">', '</code>'], $safe);
};
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
    ], JsonLd::SAFE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>'
    . '<script type="application/ld+json">' . json_encode([
        "@context" => "https://schema.org",
        "@type"    => "BreadcrumbList",
        "itemListElement" => [
            ["@type" => "ListItem", "position" => 1, "name" => "Cardify",      "item" => ArTwins::SITE . $crumbHomeHref],
            ["@type" => "ListItem", "position" => 2, "name" => t('logos.breadcrumb_library'), "item" => ArTwins::SITE . $crumbLogosHref],
            ["@type" => "ListItem", "position" => 3, "name" => t('logos.press_breadcrumb'), "item" => $canonicalUrl],
        ],
    ], JsonLd::SAFE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';

require_once INCLUDES_DIR . '/ui-header.php';

function logos_press_esc($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
?>

<div class="bg-gradient-to-b from-gray-50 to-white pt-28 pb-16" <?= $isAr ? 'dir="rtl"' : '' ?>>
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">

        <nav class="flex items-center gap-1.5 text-sm text-gray-500 mb-6 flex-wrap">
            <a href="<?= logos_press_esc($crumbLogosHref) ?>" class="hover:text-blue-600"><?= logos_press_esc(t('logos.breadcrumb_library')) ?></a>
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

            <div class="grid sm:grid-cols-2 gap-4 mt-5" dir="<?= $isAr ? 'rtl' : 'ltr' ?>">
                <div>
                    <p class="text-xs font-bold text-gray-300 uppercase tracking-wide mb-2"><?= $logos_press_gloss_html(('params_label')) ?></p>
                    <ul class="text-xs text-gray-400 space-y-1 font-mono">
                        <li><span class="text-emerald-400" dir="ltr">q</span> &mdash; <?= $logos_press_gloss_html(('q')) ?></li>
                        <li><span class="text-emerald-400" dir="ltr">sort</span> &mdash; <?= $logos_press_gloss_html(('sort')) ?></li>
                        <li><span class="text-emerald-400" dir="ltr">sector</span> &mdash; <?= $logos_press_gloss_html(('sector')) ?></li>
                        <li><span class="text-emerald-400" dir="ltr">verified</span> &mdash; <?= $logos_press_gloss_html(('verified')) ?></li>
                        <li><span class="text-emerald-400" dir="ltr">page</span> / <span class="text-emerald-400" dir="ltr">per_page</span> &mdash; <?= $logos_press_gloss_html(('page')) ?></li>
                    </ul>
                </div>
                <div>
                    <p class="text-xs font-bold text-gray-300 uppercase tracking-wide mb-2"><?= $logos_press_gloss_html(('result_label')) ?></p>
                    <ul class="text-xs text-gray-400 space-y-1 font-mono">
                        <li><span class="text-blue-400" dir="ltr">display_url</span> &mdash; <?= $logos_press_gloss_html(('display_url')) ?></li>
                        <li><span class="text-blue-400" dir="ltr">urls.{svg,png_512,png_1024,png_2048,webp}</span></li>
                        <li><span class="text-blue-400" dir="ltr">urls.{svg,png,webp}_dark</span> &mdash; <?= $logos_press_gloss_html(('dark')) ?></li>
                        <li><span class="text-blue-400" dir="ltr">urls.{svg,png,webp}_white</span> &mdash; <?= $logos_press_gloss_html(('white')) ?></li>
                        <li><span class="text-blue-400" dir="ltr">palette[]</span> &mdash; <?= $logos_press_gloss_html(('palette')) ?></li>
                        <li><span class="text-blue-400" dir="ltr">dominant_color, status, profile_url</span></li>
                    </ul>
                </div>
            </div>
            <p class="text-[11px] text-gray-500 mt-4" dir="<?= $isAr ? 'rtl' : 'ltr' ?>">
                <?= $logos_press_gloss_html(('cors')) ?>
                <span class="font-mono" dir="ltr">?v=</span> <?= $logos_press_gloss_html(('cachebuster')) ?>
                <span class="text-gray-300" dir="ltr">cardify.om/logos</span> <?= $logos_press_gloss_html(('attribution')) ?>
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
                <a href="https://api.whatsapp.com/send?phone=96898899100" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-white border border-gray-200 text-gray-700 hover:border-emerald-300 hover:text-emerald-700 text-sm font-semibold transition">
                    <i class="fa-brands fa-whatsapp text-emerald-600"></i>
                    +968 9889 9100 <?= logos_press_esc(t('logos.press_bhd_suffix')) ?>
                </a>
            </div>
        </div>
    </div>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
