<?php
/** @var bool $isAr; @var string $title; */
$pageTitle       = $title;
$pageDescription = t('logos.terms_meta_desc');
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
            ["@type" => "ListItem", "position" => 3, "name" => t('logos.terms_breadcrumb'), "item" => $canonicalUrl],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>'
    . '<link rel="alternate" hreflang="en" href="https://cardify.om/logos/terms">'
    . '<link rel="alternate" hreflang="ar" href="https://cardify.om/ar/logos/terms">'
    . '<link rel="alternate" hreflang="x-default" href="https://cardify.om/logos/terms">';
$suppressDefaultHreflang = true;
require_once INCLUDES_DIR . '/ui-header.php';

function logos_terms_esc($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
?>

<div class="bg-gradient-to-b from-gray-50 to-white pt-28 pb-16" <?= $isAr ? 'dir="rtl"' : '' ?>>
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">

        <nav class="flex items-center gap-1.5 text-sm text-gray-500 mb-6 flex-wrap">
            <a href="/logos" class="hover:text-blue-600"><?= logos_terms_esc(t('logos.breadcrumb_library')) ?></a>
            <i class="fa-solid fa-chevron-<?= $isAr ? 'left' : 'right' ?> text-[10px] text-gray-300"></i>
            <span class="text-gray-900 font-medium"><?= logos_terms_esc(t('logos.terms_breadcrumb')) ?></span>
        </nav>

        <h1 class="text-3xl sm:text-4xl font-extrabold text-gray-900 mb-6"><?= logos_terms_esc($title) ?></h1>

        <div class="prose prose-slate max-w-none prose-headings:font-bold prose-headings:text-gray-900 prose-a:text-blue-600 prose-a:no-underline hover:prose-a:underline">
            <p class="lead"><?= logos_terms_esc(t('logos.terms_lead')) ?></p>

            <h2><?= logos_terms_esc(t('logos.terms_h2_reference')) ?></h2>
            <p><?= logos_terms_esc(t('logos.terms_p_reference')) ?></p>

            <h2><?= logos_terms_esc(t('logos.terms_h2_verified')) ?></h2>
            <p><?= logos_terms_esc(t('logos.terms_p_verified')) ?></p>

            <h2><?= logos_terms_esc(t('logos.terms_h2_takedown')) ?></h2>
            <p><?= t('logos.terms_p_takedown_html') ?></p>

            <h2><?= logos_terms_esc(t('logos.terms_h2_attribution')) ?></h2>
            <p><?= logos_terms_esc(t('logos.terms_p_attribution')) ?></p>

            <h2><?= logos_terms_esc(t('logos.terms_h2_contact')) ?></h2>
            <p><?= t('logos.terms_p_contact_html') ?></p>
        </div>

        <!-- Back CTA -->
        <div class="mt-10 flex flex-wrap gap-3">
            <a href="/logos" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold shadow shadow-blue-600/20 transition">
                <i class="fa-solid fa-arrow-<?= $isAr ? 'right' : 'left' ?> text-xs"></i>
                <?= logos_terms_esc(t('logos.terms_back_library')) ?>
            </a>
            <a href="/logo-takedown" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-white border border-gray-200 text-gray-700 hover:border-blue-300 hover:text-blue-600 text-sm font-semibold transition">
                <?= logos_terms_esc(t('logos.terms_request_takedown')) ?>
            </a>
        </div>
    </div>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
