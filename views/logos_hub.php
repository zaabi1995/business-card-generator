<?php
/**
 * Omani Logo Library — Hub view.
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

$pageTitle       = $title;
$pageDescription = ($isAr ? 'أرشيف عام لأكثر من ' : 'A public archive of ') .
                   number_format($total) .
                   ($isAr ? ' علامة تجارية عمانية.' : ' Omani brand marks. Free to browse, owners can claim.');
$canonicalUrl    = $canonical;
$bodyClass       = 'bg-white';
$showNavigation  = true;
$ogImage         = 'https://cardify.om/storage/og/logos/hub.png';

// JSON-LD: CollectionPage + BreadcrumbList + FAQPage + DataCatalog.
// Google commonly surfaces FAQPage rich results and image carousels from these.
$faqQuestions = [
    [
        'q_en' => 'Is it free to download these Omani logos?',
        'a_en' => 'Yes. Browsing and downloading indexed + verified logos from the Omani Logo Library is free. The logos themselves remain trademarks of their respective owners.',
        'q_ar' => 'هل التنزيل مجاني؟',
        'a_ar' => 'نعم. تصفح وتنزيل الشعارات المفهرسة والموثَّقة من مكتبة الشعارات العمانية مجاني. تبقى الشعارات علامات تجارية مملوكة لأصحابها.',
    ],
    [
        'q_en' => 'Can I use these logos commercially?',
        'a_en' => 'The library is built under nominative / reference-use principles. You may use logos for identification and reference (journalism, research, internal documents). Commercial reuse, redistribution, and derivative works require the owner\'s permission.',
        'q_ar' => 'هل يمكنني استخدام هذه الشعارات تجارياً؟',
        'a_ar' => 'المكتبة مبنية على مبدأ الاستخدام التعريفي. يمكنك استخدام الشعارات للتعريف والبحث والصحافة والمستندات الداخلية. الاستخدام التجاري وإعادة التوزيع والأعمال المشتقة تتطلب إذن المالك.',
    ],
    [
        'q_en' => 'How do I claim my company\'s logo?',
        'a_en' => 'Open your company profile and click "Claim this logo". Sign in with your company email — if the domain matches, you\'re verified instantly. Otherwise we review manually within 48 hours.',
        'q_ar' => 'كيف أطالب بشعار شركتي؟',
        'a_ar' => 'افتح ملف شركتك واضغط على "المطالبة بهذا الشعار". سجّل الدخول ببريد شركتك — إذا تطابق النطاق، يتم التوثيق فوراً. وإلا نراجع الطلب يدوياً خلال ٤٨ ساعة.',
    ],
    [
        'q_en' => 'What file formats are available?',
        'a_en' => 'We serve SVG (when available), PNG at 512, 1024, and 2048 pixels, and WebP for web use. A ZIP bundle with all formats is available for verified logos.',
        'q_ar' => 'ما الصيغ المتوفرة؟',
        'a_ar' => 'نوفر SVG عند توفره، وPNG بأحجام ٥١٢ و١٠٢٤ و٢٠٤٨ بكسل، وWebP للاستخدام على الويب. كما تتوفر حزمة ZIP تحتوي كل الصيغ للشعارات الموثَّقة.',
    ],
    [
        'q_en' => 'How do I request removal of a logo?',
        'a_en' => 'Submit the takedown form at /logo-takedown. We acknowledge within 48 hours and hide valid requests within 24 hours of prima-facie verification.',
        'q_ar' => 'كيف أطلب إزالة شعار؟',
        'a_ar' => 'قدّم نموذج الإزالة على /logo-takedown. نرد خلال ٤٨ ساعة ونخفي الطلبات الصحيحة خلال ٢٤ ساعة من التحقق الأولي.',
    ],
    [
        'q_en' => 'Is there an API?',
        'a_en' => 'Yes. GET /api/logos/list, /api/logos/show, /api/logos/sectors, /api/logos/stats are all public, CORS-enabled, and rate-limited to 60 req/min per IP.',
        'q_ar' => 'هل هناك واجهة برمجية؟',
        'a_ar' => 'نعم. الطلبات GET /api/logos/list و /show و /sectors و /stats جميعها عامة ومفعَّلة CORS ومحدودة بـ٦٠ طلب/دقيقة لكل IP.',
    ],
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
        "name"           => $isAr ? $q['q_ar'] : $q['q_en'],
        "acceptedAnswer" => [
            "@type" => "Answer",
            "text"  => $isAr ? $q['a_ar'] : $q['a_en'],
        ],
    ], $faqQuestions),
];

$jsonLdBlocks[] = [
    "@context"   => "https://schema.org",
    "@type"      => "DataCatalog",
    "name"       => "Omani Logo Library — Public API",
    "url"        => "https://cardify.om/logos/press",
    "license"    => "https://cardify.om/logos/terms",
    "isAccessibleForFree" => true,
    "distribution" => [
        ["@type" => "DataDownload", "encodingFormat" => "application/json", "contentUrl" => "https://cardify.om/api/logos/list"],
        ["@type" => "DataDownload", "encodingFormat" => "application/json", "contentUrl" => "https://cardify.om/api/logos/sectors"],
        ["@type" => "DataDownload", "encodingFormat" => "application/json", "contentUrl" => "https://cardify.om/api/logos/stats"],
    ],
];

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

require_once INCLUDES_DIR . '/ui-header.php';

function logos_esc($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
?>

<div class="bg-gradient-to-b from-gray-50 to-white pt-28 pb-16" <?= $isAr ? 'dir="rtl"' : '' ?>>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Hero -->
        <div class="text-center mb-10">
            <span class="inline-block px-3 py-1 rounded-full bg-blue-100 text-blue-700 text-xs font-semibold uppercase tracking-wide mb-4">
                <?= $isAr ? 'مكتبة عامة · أرشيف شعارات' : 'Public archive · Omani brands' ?>
            </span>
            <h1 class="text-4xl sm:text-5xl font-extrabold text-gray-900 mb-4">
                <?= $isAr ? 'مكتبة الشعارات العمانية' : 'The Omani Logo Library' ?>
            </h1>
            <p class="text-lg text-gray-600 max-w-2xl mx-auto">
                <?= $isAr
                    ? number_format($total) . '+ علامة تجارية عمانية. مُفهرسة وقابلة للبحث ومُوثَّقة عند المطالبة.'
                    : number_format($total) . '+ Omani brand marks. Indexed, searchable, owner-verifiable.' ?>
            </p>
        </div>

        <!-- Filter bar -->
        <form method="get" class="flex flex-wrap gap-2 mb-6 p-3 bg-white border border-gray-200 rounded-xl shadow-sm">
            <div class="relative flex-1 min-w-[220px]">
                <i class="fa-solid fa-magnifying-glass absolute <?= $isAr ? 'right-3' : 'left-3' ?> top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                <input type="search" name="q" value="<?= logos_esc($_GET['q'] ?? '') ?>"
                       placeholder="<?= $isAr ? 'ابحث عن شركة…' : 'Search a company…' ?>"
                       class="w-full <?= $isAr ? 'pr-10 pl-3' : 'pl-10 pr-3' ?> py-2.5 text-sm bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
            </div>
            <select name="sector" class="px-3 py-2.5 text-sm bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                <option value=""><?= $isAr ? 'كل القطاعات' : 'All sectors' ?></option>
                <?php foreach ($SECTORS as $slug => $label): ?>
                    <option value="<?= logos_esc($slug) ?>" <?= ($_GET['sector'] ?? '') === $slug ? 'selected' : '' ?>>
                        <?= logos_esc($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <label class="inline-flex items-center gap-2 px-3 py-2.5 text-sm bg-gray-50 border border-gray-200 rounded-lg cursor-pointer hover:bg-gray-100">
                <input type="checkbox" name="verified" value="1" <?= !empty($_GET['verified']) ? 'checked' : '' ?>
                       class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                <span class="text-gray-700 font-medium"><?= $isAr ? 'موثّقة فقط' : 'Verified only' ?></span>
            </label>
            <button class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold rounded-lg shadow shadow-blue-600/20 transition">
                <?= $isAr ? 'تصفية' : 'Filter' ?>
            </button>
        </form>

        <p class="text-sm text-gray-500 mb-5">
            <?= number_format($data['total']) ?>
            <?= $isAr ? 'نتيجة' : ($data['total'] === 1 ? 'result' : 'results') ?>
        </p>

        <!-- Grid -->
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
            <?php foreach ($data['rows'] as $r):
                $status = $r['logo_status'];
                $badgeColor = $status === 'verified' ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-gray-50 text-gray-600 ring-gray-200';
                $badgeLabel = $status === 'verified' ? ($isAr ? 'موثّق' : 'Verified') : ($isAr ? 'مفهرس' : 'Indexed');
                $src = $r['logo_webp_path'] ?: $r['logo_png_512_path'] ?: $r['logo_png_path'] ?: $r['logo_svg_path'];
                $bg  = $r['logo_dominant_color'] ?: '#f9fafb';
            ?>
                <a href="/companies/<?= logos_esc($r['slug']) ?>"
                   class="group relative bg-white rounded-xl border border-gray-200 hover:border-blue-300 hover:shadow-md transition-all overflow-hidden">
                    <div class="aspect-square flex items-center justify-center p-4 bg-gradient-to-br from-gray-50 to-white border-b border-gray-100">
                        <?php if ($src): ?>
                            <img src="<?= logos_esc($src) ?>" alt="<?= logos_esc($r['name_en']) ?>"
                                 loading="lazy" class="max-h-full max-w-full object-contain">
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
            <nav class="mt-10 flex justify-center gap-1.5" aria-label="<?= $isAr ? 'الصفحات' : 'Pagination' ?>">
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

        <!-- Why this library — product context -->
        <div class="mt-16 grid grid-cols-1 md:grid-cols-3 gap-5">
            <div class="bg-white rounded-2xl border border-gray-200 p-6">
                <div class="w-10 h-10 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center mb-4">
                    <i class="fa-solid fa-magnifying-glass"></i>
                </div>
                <h3 class="font-bold text-gray-900 mb-1"><?= $isAr ? 'مفهرس' : 'Indexed' ?></h3>
                <p class="text-sm text-gray-600 leading-relaxed">
                    <?= $isAr
                        ? 'شعارات مأخوذة من مصادر عامة لأغراض التعريف والبحث فقط.'
                        : 'Logos sourced from public records for identification and research only.' ?>
                </p>
            </div>
            <div class="bg-white rounded-2xl border border-gray-200 p-6">
                <div class="w-10 h-10 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center mb-4">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <h3 class="font-bold text-gray-900 mb-1"><?= $isAr ? 'موثَّق من المالك' : 'Owner-verified' ?></h3>
                <p class="text-sm text-gray-600 leading-relaxed">
                    <?= $isAr
                        ? 'أصحاب العلامات التجارية يمكنهم المطالبة بملفهم لتوثيق شعارهم وتنزيله.'
                        : 'Brand owners can claim their profile to verify + download clean SVG / PNG bundles.' ?>
                </p>
            </div>
            <div class="bg-white rounded-2xl border border-gray-200 p-6">
                <div class="w-10 h-10 rounded-lg bg-rose-50 text-rose-600 flex items-center justify-center mb-4">
                    <i class="fa-solid fa-shield-halved"></i>
                </div>
                <h3 class="font-bold text-gray-900 mb-1"><?= $isAr ? 'حماية العلامات' : 'Trademark respect' ?></h3>
                <p class="text-sm text-gray-600 leading-relaxed">
                    <?= $isAr
                        ? 'جميع العلامات مملوكة لأصحابها. نستجيب لطلبات الإزالة خلال ٢٤ ساعة.'
                        : 'All marks belong to their owners. Takedown requests honored within 24 hours.' ?>
                </p>
            </div>
        </div>

        <!-- FAQ — helps SEO (FAQPage schema) + trust -->
        <div class="mt-16">
            <h2 class="text-2xl sm:text-3xl font-extrabold text-gray-900 mb-2">
                <?= $isAr ? 'أسئلة شائعة' : 'Frequently asked' ?>
            </h2>
            <p class="text-gray-600 mb-6">
                <?= $isAr
                    ? 'الحقوق، التنزيل، المطالبة، طلبات الإزالة، والواجهة البرمجية.'
                    : 'Licensing, downloads, claims, takedowns, and the public API.' ?>
            </p>
            <div class="space-y-3">
                <?php foreach ($faqQuestions as $i => $q): ?>
                    <details class="group bg-white border border-gray-200 rounded-xl hover:border-blue-300 transition overflow-hidden"<?= $i === 0 ? ' open' : '' ?>>
                        <summary class="flex items-center justify-between gap-4 px-5 py-4 cursor-pointer list-none">
                            <span class="font-semibold text-gray-900"><?= logos_esc($isAr ? $q['q_ar'] : $q['q_en']) ?></span>
                            <i class="fa-solid fa-chevron-down text-gray-400 text-sm transition group-open:rotate-180"></i>
                        </summary>
                        <div class="px-5 pb-4 text-gray-600 leading-relaxed">
                            <?= logos_esc($isAr ? $q['a_ar'] : $q['a_en']) ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Cross-link to OBI -->
        <div class="mt-12 bg-gradient-to-br from-blue-600 to-indigo-700 rounded-2xl p-8 lg:p-10 text-white">
            <div class="flex flex-col md:flex-row items-start md:items-center gap-6 md:justify-between">
                <div class="max-w-xl">
                    <h2 class="text-2xl sm:text-3xl font-bold mb-2">
                        <?= $isAr ? 'استكشف دليل الشركات العمانية' : 'Explore the Oman Business Index' ?>
                    </h2>
                    <p class="text-blue-100">
                        <?= $isAr
                            ? 'أرشيف شامل لأكثر من ٢٤٠٠ شركة عمانية، مع قطاعاتها ومواقعها.'
                            : '2,400+ Omani companies with sector, wilayat, and CR metadata. Same project, more depth.' ?>
                    </p>
                </div>
                <a href="/oman-business-index" class="inline-flex items-center gap-2 px-6 py-3 rounded-lg bg-white text-blue-700 font-semibold hover:bg-blue-50 transition shrink-0">
                    <?= $isAr ? 'افتح الدليل' : 'Open the index' ?>
                    <i class="fa-solid fa-arrow-<?= $isAr ? 'left' : 'right' ?> text-sm"></i>
                </a>
            </div>
        </div>

        <!-- Legal footer -->
        <div class="mt-10 text-center text-xs text-gray-500 space-y-1">
            <p>
                <?= $isAr
                    ? 'جميع العلامات التجارية مملوكة لأصحابها. مُفهرسة للتعريف فقط.'
                    : 'All marks are property of their respective owners. Indexed for identification only.' ?>
            </p>
            <p class="space-x-3">
                <a href="/logos/terms" class="text-gray-600 hover:text-blue-600 underline underline-offset-2"><?= $isAr ? 'الشروط' : 'Terms' ?></a>
                <span class="text-gray-300">·</span>
                <a href="/logos/press" class="text-gray-600 hover:text-blue-600 underline underline-offset-2"><?= $isAr ? 'صحافة' : 'Press kit' ?></a>
                <span class="text-gray-300">·</span>
                <a href="/logo-takedown" class="text-gray-600 hover:text-blue-600 underline underline-offset-2"><?= $isAr ? 'طلب إزالة' : 'Takedown request' ?></a>
            </p>
            <p class="pt-1">
                <?= $isAr
                    ? 'مُحدَّث جزئياً من مصادر عامة مثل 2oman.net.'
                    : 'Seeded in part from publicly indexed sources including 2oman.net.' ?>
            </p>
        </div>
    </div>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
