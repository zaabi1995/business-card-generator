<?php
/**
 * /glossary, the bilingual hub. Defines the DefinedTermSet that every term
 * page's inDefinedTermSet points at, so the cluster resolves as one set.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Seo.php';

$terms     = require __DIR__ . '/terms.php';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

$pageTitle       = 'Digital Business Card Glossary, English and Arabic';
$pageDescription = 'Plain definitions of the terms used in digital business cards, in English and Arabic: digital business card, NFC, vCard, QR vCard, Apple Wallet pass and contactless business card.';
$canonicalUrl    = 'https://cardify.om/glossary';

$showNavigation = true;

ksort($terms);

$extraHead = Seo::ldScript(
    Seo::breadcrumbNode([
        ['Home', 'https://cardify.om/'],
        ['Glossary', $canonicalUrl],
    ]),
    [
        '@context' => 'https://schema.org',
        '@type' => 'DefinedTermSet',
        '@id' => Seo::GLOSSARY_ID,
        'name' => 'Cardify Digital Business Card Glossary',
        'description' => $pageDescription,
        'url' => $canonicalUrl,
        'inLanguage' => ['en', 'ar'],
        'publisher' => ['@id' => 'https://cardify.om/#organization'],
        'hasDefinedTerm' => array_values(array_map(
            static fn(string $slug): array => ['@id' => 'https://cardify.om/glossary/' . $slug . '#term'],
            array_keys($terms)
        )),
    ]
);

require_once INCLUDES_DIR . '/ui-header.php';
$base = getBasePath();
?>

<div class="min-h-screen bg-gray-50">
    <div class="bg-white pt-28 pb-12 border-b border-gray-100">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            <nav class="text-sm text-gray-500 mb-4" aria-label="Breadcrumb">
                <a href="<?= $base ?>" class="hover:text-blue-600">Home</a>
                <span class="mx-2">/</span>
                <span class="text-gray-700">Glossary</span>
            </nav>
            <h1 class="text-4xl sm:text-5xl font-extrabold tracking-tight text-gray-900 mb-5">
                Digital business card glossary
            </h1>
            <p class="text-gray-600 text-lg max-w-3xl leading-relaxed">
                The vocabulary of this field, defined plainly in English and Arabic. Written for people evaluating a platform rather than for people who already work in one.
            </p>
        </div>
    </div>

    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div class="grid gap-5 sm:grid-cols-2">
            <?php foreach ($terms as $slug => $t): ?>
                <a href="<?= $base ?>glossary/<?= htmlspecialchars($slug) ?>" class="group block rounded-2xl bg-white border border-gray-200 hover:border-blue-400 hover:shadow-lg transition p-6">
                    <h2 class="text-xl font-bold text-gray-900 group-hover:text-blue-700 mb-1"><?= htmlspecialchars($t['title']) ?></h2>
                    <p class="text-base text-gray-500 font-semibold mb-3" lang="ar" dir="rtl"><?= htmlspecialchars($t['ar_title']) ?></p>
                    <p class="text-gray-600 text-sm leading-relaxed mb-4"><?= htmlspecialchars($t['short']) ?></p>
                    <span class="inline-flex items-center gap-2 text-blue-600 font-semibold text-sm">
                        Full definition
                        <i class="fa-solid fa-arrow-right group-hover:translate-x-0.5 transition-transform" aria-hidden="true"></i>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="mt-10 rounded-2xl bg-blue-600 px-6 py-8 sm:px-10 sm:py-10 text-white">
            <h2 class="text-2xl sm:text-3xl font-bold mb-3">Bilingual cards for your whole team</h2>
            <p class="text-blue-100 mb-6 max-w-2xl">Free for unlimited employees. Arabic and English on one card, printing fulfilled in Oman.</p>
            <a href="<?= $base ?>get-started" class="inline-flex items-center gap-2 px-6 py-3.5 bg-white text-blue-700 font-semibold rounded-xl hover:bg-blue-50 transition">
                Get started free
                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
            </a>
        </div>
    </div>
</div>

<?php require INCLUDES_DIR . '/ui-footer.php'; ?>
