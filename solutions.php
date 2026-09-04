<?php
/**
 * Cardify - Oman Solutions Hub
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/JsonLd.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = t('solutions.page_title');
$pageDescription = t('solutions.page_desc');
$canonicalUrl = 'https://cardify.om/solutions';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

$showNavigation = true;

$breadcrumbJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://cardify.om/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Solutions', 'item' => $canonicalUrl],
    ],
];
$extraHead = '<script type="application/ld+json">' . json_encode($breadcrumbJsonLd, JsonLd::SAFE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';

require_once INCLUDES_DIR . '/ui-header.php';

require_once __DIR__ . '/includes/SolutionShelf.php';
$solutions = solutionShelf();
?>

<div class="min-h-screen bg-gray-50">
    <div class="bg-white pt-28 pb-12">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <h1 class="text-4xl font-bold text-gray-900 mb-4"><?= htmlspecialchars(t('solutions.h1')) ?></h1>
            <p class="text-gray-600 text-lg max-w-2xl mx-auto">
                <?= htmlspecialchars(t('solutions.hero_sub')) ?>
            </p>
            <div class="mt-6">
                <a href="<?php echo getBasePath(); ?>company/register.php" class="inline-flex items-center gap-2 px-6 py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition-colors">
                    <?= htmlspecialchars(t('solutions.cta_hero_btn')) ?>
                    <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>
        </div>
    </div>

    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-16 space-y-16">
        <?php foreach ($solutions as $categoryKey => $items): ?>
            <section>
                <h2 class="text-2xl font-bold text-gray-900 mb-6 border-b border-gray-200 pb-3"><?= htmlspecialchars(t($categoryKey)) ?></h2>
                <div class="grid md:grid-cols-2 gap-6">
                    <?php foreach ($items as $item): ?>
                        <a href="<?php echo getBasePath(); ?>solutions/<?php echo htmlspecialchars($item['url']); ?>"
                           class="block bg-white rounded-xl shadow-sm p-6 border border-gray-100 hover:border-blue-300 hover:shadow-md transition-all">
                            <h3 class="text-lg font-bold text-gray-900 mb-2"><?= htmlspecialchars(t($item['title_key'])) ?></h3>
                            <p class="text-gray-600 text-sm leading-relaxed"><?= htmlspecialchars(t($item['desc_key'])) ?></p>
                            <p class="text-blue-600 font-medium text-sm mt-3 inline-flex items-center gap-1">
                                <?= htmlspecialchars(t('solutions.read_more')) ?> <i class="fa-solid fa-arrow-right text-xs"></i>
                            </p>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>

        <div class="bg-gradient-to-br from-blue-600 to-blue-800 rounded-2xl p-8 lg:p-12 text-center text-white">
            <h2 class="text-3xl font-bold mb-4"><?= htmlspecialchars(t('solutions.cta_h2')) ?></h2>
            <p class="text-blue-100 mb-8 max-w-2xl mx-auto">
                <?= htmlspecialchars(t('solutions.cta_body')) ?>
            </p>
            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                <a href="<?php echo getBasePath(); ?>company/register.php"
                   class="inline-flex items-center justify-center gap-2 px-8 py-4 bg-white text-blue-600 font-semibold rounded-xl hover:bg-blue-50 transition-colors">
                    <?= htmlspecialchars(t('solutions.cta_primary')) ?>
                    <i class="fa-solid fa-arrow-right"></i>
                </a>
                <a href="<?php echo getBasePath(); ?>contact"
                   class="inline-flex items-center justify-center gap-2 px-8 py-4 border-2 border-white text-white font-semibold rounded-xl hover:bg-white/10 transition-colors">
                    <?= htmlspecialchars(t('solutions.cta_secondary')) ?>
                    <i class="fa-solid fa-envelope"></i>
                </a>
            </div>
        </div>
    </div>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
