<?php
/** @var string $title; @var bool $isAr; */
$pageTitle       = $title;
$pageDescription = $isAr
    ? 'الملف الإعلامي لمكتبة الشعارات العمانية، أكثر من 2,400 علامة تجارية عمانية. واجهة برمجية، صور، تواصل.'
    : 'Press kit for The Omani Logo Library, 2,400+ Omani brand marks indexed by Cardify. API, screenshots, contact.';
$canonicalUrl    = 'https://cardify.om/logos/press';
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
            ["@type" => "ListItem", "position" => 2, "name" => ($isAr ? "مكتبة الشعارات" : "Logo Library"), "item" => "https://cardify.om/logos"],
            ["@type" => "ListItem", "position" => 3, "name" => ($isAr ? "الملف الإعلامي" : "Press kit"), "item" => $canonicalUrl],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';

require_once INCLUDES_DIR . '/ui-header.php';

function logos_press_esc($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
?>

<div class="bg-gradient-to-b from-gray-50 to-white pt-28 pb-16" <?= $isAr ? 'dir="rtl"' : '' ?>>
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">

        <nav class="flex items-center gap-1.5 text-sm text-gray-500 mb-6 flex-wrap">
            <a href="/logos" class="hover:text-blue-600"><?= $isAr ? 'مكتبة الشعارات' : 'Logo Library' ?></a>
            <i class="fa-solid fa-chevron-<?= $isAr ? 'left' : 'right' ?> text-[10px] text-gray-300"></i>
            <span class="text-gray-900 font-medium"><?= $isAr ? 'الملف الإعلامي' : 'Press kit' ?></span>
        </nav>

        <div class="mb-10">
            <span class="inline-block px-3 py-1 rounded-full bg-blue-100 text-blue-700 text-xs font-semibold uppercase tracking-wide mb-3">
                <?= $isAr ? 'صحافة · إعلام' : 'Press · Media' ?>
            </span>
            <h1 class="text-3xl sm:text-4xl font-extrabold text-gray-900 mb-3">
                <?= $isAr ? 'الملف الإعلامي، مكتبة الشعارات العمانية' : 'Press Kit, Omani Logo Library' ?>
            </h1>
            <p class="text-lg text-gray-600">
                <?= $isAr
                    ? 'أرشيف عام لشعارات الشركات العمانية. مفتوح للتصفّح، قابل للتوثيق من المالكين، مبنيّ بواسطة كارديفاي.'
                    : 'A public archive of Omani brand marks. Free to browse, owner-verifiable, built by Cardify.' ?>
            </p>
        </div>

        <!-- Fast facts -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-10">
            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <div class="text-3xl font-extrabold text-gray-900">2,400+</div>
                <div class="text-xs text-gray-500 mt-1 uppercase tracking-wide"><?= $isAr ? 'شركة عُمانية' : 'Omani companies' ?></div>
            </div>
            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <div class="text-3xl font-extrabold text-gray-900">23</div>
                <div class="text-xs text-gray-500 mt-1 uppercase tracking-wide"><?= $isAr ? 'قطاعاً' : 'Sectors' ?></div>
            </div>
            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <div class="text-3xl font-extrabold text-gray-900">EN + AR</div>
                <div class="text-xs text-gray-500 mt-1 uppercase tracking-wide"><?= $isAr ? 'ثنائي اللغة' : 'Bilingual' ?></div>
            </div>
            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <div class="text-3xl font-extrabold text-gray-900">24h</div>
                <div class="text-xs text-gray-500 mt-1 uppercase tracking-wide"><?= $isAr ? 'مدة الإزالة' : 'Takedown SLA' ?></div>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-gray-200 p-6 lg:p-8 mb-8">
            <h2 class="text-xl font-bold text-gray-900 mb-3"><?= $isAr ? 'كيف تعمل المكتبة' : 'How it works' ?></h2>
            <ul class="space-y-3 text-gray-700">
                <li class="flex gap-3">
                    <i class="fa-solid fa-magnifying-glass mt-1 w-5 text-blue-600"></i>
                    <span><?= $isAr
                        ? '<strong>مُفهرسة</strong> من مصادر عامة مع بيانات القطاع والولاية والسجل التجاري.'
                        : '<strong>Indexed</strong> from public sources with sector, wilayat, and CR metadata.' ?></span>
                </li>
                <li class="flex gap-3">
                    <i class="fa-solid fa-circle-check mt-1 w-5 text-emerald-600"></i>
                    <span><?= $isAr
                        ? '<strong>موثَّقة عند المطالبة</strong>، يسجّل مالك العلامة الدخول ببريد شركته ويتمّ التوثيق تلقائياً عند تطابق النطاق.'
                        : '<strong>Verified on claim</strong>, brand owners sign in with their company email and auto-verify when the domain matches.' ?></span>
                </li>
                <li class="flex gap-3">
                    <i class="fa-solid fa-download mt-1 w-5 text-gray-600"></i>
                    <span><?= $isAr
                        ? '<strong>قابلة للتنزيل</strong>، SVG وPNG بأحجام 512/1024/2048 وWebP وحزم ZIP للشعارات الموثَّقة.'
                        : '<strong>Downloadable</strong>, SVG, PNG 512/1024/2048, WebP, and ZIP bundles on verified logos.' ?></span>
                </li>
                <li class="flex gap-3">
                    <i class="fa-solid fa-shield-halved mt-1 w-5 text-rose-600"></i>
                    <span><?= $isAr
                        ? '<strong>إزالة سريعة</strong>، الطلبات السليمة مبدئياً تؤدّي إلى حذف الشعار خلال 24 ساعة.'
                        : '<strong>Takedown-ready</strong>, prima-facie valid requests result in logo removal within 24 hours.' ?></span>
                </li>
            </ul>
        </div>

        <!-- API quickstart -->
        <div class="bg-gray-900 rounded-2xl p-6 lg:p-8 mb-8">
            <h2 class="text-xl font-bold text-white mb-3"><?= $isAr ? 'واجهة برمجية عامة' : 'Public API' ?></h2>
            <p class="text-gray-400 text-sm mb-4">
                <?= $isAr
                    ? 'مجانية، بحدّ 60 طلباً في الدقيقة، بدون توثيق. CORS مفتوح على نقاط القراءة.'
                    : 'Free, rate-limited (60 req/min), no auth. CORS open on read endpoints.' ?>
            </p>
            <pre class="text-gray-100 text-sm font-mono overflow-x-auto bg-black/30 rounded-lg p-4 leading-relaxed" dir="ltr"><code>GET https://cardify.om/api/logos/list?per_page=20
GET https://cardify.om/api/logos/show?slug=omantel
GET https://cardify.om/api/logos/sectors
GET https://cardify.om/api/logos/stats</code></pre>
        </div>

        <!-- Contact -->
        <div class="bg-white rounded-2xl border border-gray-200 p-6 lg:p-8">
            <h2 class="text-xl font-bold text-gray-900 mb-3"><?= $isAr ? 'التواصل الإعلامي' : 'Press contact' ?></h2>
            <p class="text-gray-600 mb-4">
                <?= $isAr
                    ? 'بإمكان الصحفيين والباحثين والمحلّلين استخدام لقطات كارديفاي وأرقام الفهرسة مع ذكر المصدر. تبقى الشعارات الفرديّة علامات تجارية مملوكة لأصحابها.'
                    : 'Journalists, researchers, and analysts can use Cardify-generated screenshots and indexed counts with attribution. Individual brand marks remain trademarks of their respective owners.' ?>
            </p>
            <div class="flex flex-wrap gap-3">
                <a href="mailto:press@cardify.om" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold shadow shadow-blue-600/20 transition">
                    <i class="fa-solid fa-envelope text-xs"></i>
                    press@cardify.om
                </a>
                <a href="https://wa.me/96898899100" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-white border border-gray-200 text-gray-700 hover:border-emerald-300 hover:text-emerald-700 text-sm font-semibold transition">
                    <i class="fa-brands fa-whatsapp text-emerald-600"></i>
                    +968 9889 9100 <?= $isAr ? '(مجموعة BHD)' : '(BHD Group)' ?>
                </a>
            </div>
        </div>
    </div>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
