<?php
/** @var bool $isAr; @var string $title; */
$pageTitle       = $title;
$pageDescription = $isAr
    ? 'شروط استخدام مكتبة الشعارات العمانية: الاستخدام المرجعي، التوثيق، طلبات الإزالة.'
    : 'Terms of use for the Omani Logo Library: reference use, verification, takedown policy.';
$canonicalUrl    = 'https://cardify.om/logos/terms';
$bodyClass       = 'bg-white';
$showNavigation  = true;
$metaRobots      = 'index,follow';

$extraHead =
    '<script type="application/ld+json">' . json_encode([
        "@context"   => "https://schema.org",
        "@type"      => "WebPage",
        "name"       => $title,
        "url"        => $canonicalUrl,
        "inLanguage" => $isAr ? 'ar' : 'en',
        "about"      => ["@type" => "Thing", "name" => "Omani Logo Library terms and license"],
        "license"    => $canonicalUrl,
        "publisher"  => ["@type" => "Organization", "name" => "Cardify", "url" => "https://cardify.om"],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>'
    . '<script type="application/ld+json">' . json_encode([
        "@context" => "https://schema.org",
        "@type"    => "BreadcrumbList",
        "itemListElement" => [
            ["@type" => "ListItem", "position" => 1, "name" => "Cardify",      "item" => "https://cardify.om"],
            ["@type" => "ListItem", "position" => 2, "name" => "Logo Library", "item" => "https://cardify.om/logos"],
            ["@type" => "ListItem", "position" => 3, "name" => ($isAr ? 'الشروط' : 'Terms'), "item" => $canonicalUrl],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>'
    . '<link rel="alternate" hreflang="en" href="https://cardify.om/logos/terms">'
    . '<link rel="alternate" hreflang="ar" href="https://cardify.om/ar/logos/terms">'
    . '<link rel="alternate" hreflang="x-default" href="https://cardify.om/logos/terms">';

require_once INCLUDES_DIR . '/ui-header.php';

function logos_terms_esc($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
?>

<div class="bg-gradient-to-b from-gray-50 to-white pt-28 pb-16" <?= $isAr ? 'dir="rtl"' : '' ?>>
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">

        <nav class="flex items-center gap-1.5 text-sm text-gray-500 mb-6 flex-wrap">
            <a href="/logos" class="hover:text-blue-600"><?= $isAr ? 'مكتبة الشعارات' : 'Logo Library' ?></a>
            <i class="fa-solid fa-chevron-<?= $isAr ? 'left' : 'right' ?> text-[10px] text-gray-300"></i>
            <span class="text-gray-900 font-medium"><?= $isAr ? 'الشروط' : 'Terms' ?></span>
        </nav>

        <h1 class="text-3xl sm:text-4xl font-extrabold text-gray-900 mb-6"><?= logos_terms_esc($title) ?></h1>

        <div class="prose prose-slate max-w-none prose-headings:font-bold prose-headings:text-gray-900 prose-a:text-blue-600 prose-a:no-underline hover:prose-a:underline">
        <?php if ($isAr): ?>
            <p class="lead">جميع الشعارات المعروضة في مكتبة الشعارات العمانية هي علامات تجارية لأصحابها. تقوم Cardify بفهرستها لأغراض التعريف والبحث ولا تطالب بأي ملكية.</p>

            <h2>الاستخدام المرجعي</h2>
            <p>يُسمح بعرض الشعارات لأغراض التعريف فقط. لا يعني وجود شعار في المكتبة أي ترخيص لإعادة الاستخدام التجاري.</p>

            <h2>الشعارات الموثّقة</h2>
            <p>عندما يقوم صاحب العلامة التجارية بتوثيق شعاره عبر عملية المطالبة، يصبح الشعار متاحاً للتنزيل بموافقة المالك.</p>

            <h2>طلبات الإزالة</h2>
            <p>يمكن لأصحاب العلامات التجارية تقديم طلب إزالة عبر <a href="/logo-takedown">نموذج الإزالة</a>. نستجيب خلال ٤٨ ساعة ونقوم بإخفاء الشعار خلال ٢٤ ساعة من التحقق الأولي.</p>

            <h2>الإسناد</h2>
            <p>تم إعداد هذه المكتبة جزئياً من مصادر عامة مفهرسة بما في ذلك 2oman.net، بالإضافة إلى مواقع الشركات.</p>
        <?php else: ?>
            <p class="lead">All logos shown in the Omani Logo Library are trademarks of their respective owners. Cardify indexes them for identification and research purposes and claims no ownership.</p>

            <h2>Reference use</h2>
            <p>Logos are displayed for identification only. Presence in the library does not imply any license for commercial reuse.</p>

            <h2>Verified logos</h2>
            <p>When a brand owner verifies their logo via the claim process, the logo becomes downloadable with the owner's consent. Usage remains bounded by nominative fair-use: identification and reference, not redistribution or derivative commercial works.</p>

            <h2>Takedown</h2>
            <p>Brand owners may request removal via the <a href="/logo-takedown">takedown form</a>. We acknowledge within 48 hours and hide logos within 24 hours of prima-facie validation. All requests are logged for audit.</p>

            <h2>Attribution</h2>
            <p>This library was seeded in part from publicly indexed sources including 2oman.net, with additional sourcing from company websites and the Oman Business Index.</p>

            <h2>Contact</h2>
            <p>Questions, concerns, or legal inquiries: <a href="mailto:contact@cardify.om">contact@cardify.om</a>.</p>
        <?php endif; ?>
        </div>

        <!-- Back CTA -->
        <div class="mt-10 flex flex-wrap gap-3">
            <a href="/logos" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold shadow shadow-blue-600/20 transition">
                <i class="fa-solid fa-arrow-<?= $isAr ? 'right' : 'left' ?> text-xs"></i>
                <?= $isAr ? 'الرجوع للمكتبة' : 'Back to the library' ?>
            </a>
            <a href="/logo-takedown" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-white border border-gray-200 text-gray-700 hover:border-blue-300 hover:text-blue-600 text-sm font-semibold transition">
                <?= $isAr ? 'طلب إزالة' : 'Request takedown' ?>
            </a>
        </div>
    </div>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
