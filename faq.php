<?php
/**
 * Cardify, Frequently Asked Questions (Cat R action 437).
 *
 * 21 questions across 7 categories, fully bilingual via
 * lang/{en,ar}/faq.php. JSON-LD FAQPage schema emitted via
 * Seo::faqPage() for Google rich-result eligibility.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Seo.php';

$baseUrl = 'https://cardify.om';
$lang    = ($_GET['lang'] ?? '') === 'ar' ? 'ar' : 'en';
$isAr    = $lang === 'ar';

$brandName       = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
$pageTitle       = t('faq.page_title');
$pageDescription = t('faq.page_desc');
$canonicalUrl    = $baseUrl . ($isAr ? '/ar' : '') . '/faq';
$showNavigation  = true;
$bodyClass       = 'bg-gray-50' . ($isAr ? ' font-arabic' : '');
$bodyAttributes  = $isAr ? 'dir="rtl" lang="ar"' : '';

// Source of truth: category → list of key prefixes. 21 entries.
$categories = [
    'cat_start'   => ['icon' => 'fa-solid fa-rocket',          'keys' => ['gs1', 'gs2', 'gs3', 'gs4']],
    'cat_digital' => ['icon' => 'fa-solid fa-mobile-screen',   'keys' => ['dc1', 'dc2', 'dc3', 'dc4']],
    'cat_print'   => ['icon' => 'fa-solid fa-print',           'keys' => ['pr1', 'pr2', 'pr3', 'pr4']],
    'cat_teams'   => ['icon' => 'fa-solid fa-users',           'keys' => ['tm1', 'tm2', 'tm3']],
    'cat_billing' => ['icon' => 'fa-solid fa-credit-card',     'keys' => ['bl1', 'bl2', 'bl3']],
    'cat_tech'    => ['icon' => 'fa-solid fa-gear',            'keys' => ['tc1', 'tc2']],
    // r6-50: 21 entries now. The entity question is last because it is the
    // least useful to a customer and the most useful to a model.
    'cat_company' => ['icon' => 'fa-solid fa-building',        'keys' => ['co1']],
];

require_once INCLUDES_DIR . '/ui-header.php';

// JSON-LD via Seo helper, FAQPage across all 21 questions.
$faqPairs = [];
foreach ($categories as $cat) {
    foreach ($cat['keys'] as $k) {
        $faqPairs[] = [t('faq.' . $k . '_q'), t('faq.' . $k . '_a')];
    }
}
Seo::breadcrumbs([
    [$isAr ? 'الرئيسية' : 'Home', '/'],
    [t('faq.hero_h1'), $canonicalUrl],
]);
Seo::faqPage($faqPairs);
?>

<main class="min-h-screen bg-gray-50">
    <div class="bg-white pt-28 pb-12">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <h1 class="text-4xl font-bold text-gray-900 mb-3"><?= htmlspecialchars(t('faq.hero_h1')) ?></h1>
            <p class="text-gray-500 text-lg max-w-2xl mx-auto"><?= htmlspecialchars(t('faq.hero_sub')) ?></p>
        </div>
    </div>

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
        <?php foreach ($categories as $catKey => $cat): ?>
            <section class="mb-12">
                <h2 class="text-2xl font-bold text-gray-900 mb-6 flex items-center gap-3">
                    <span class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center flex-shrink-0">
                        <i class="<?= htmlspecialchars($cat['icon']) ?> text-blue-600"></i>
                    </span>
                    <?= htmlspecialchars(t('faq.' . $catKey)) ?>
                </h2>
                <div class="space-y-3">
                    <?php foreach ($cat['keys'] as $k): ?>
                        <details class="group bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                            <summary class="flex items-center justify-between cursor-pointer px-6 py-5 text-left font-semibold text-gray-900 hover:bg-gray-50 transition-colors list-none [&::-webkit-details-marker]:hidden">
                                <span class="<?= $isAr ? 'pl-4' : 'pr-4' ?>"><?= htmlspecialchars(t('faq.' . $k . '_q')) ?></span>
                                <span class="flex-shrink-0 w-6 h-6 flex items-center justify-center text-blue-600 transition-transform group-open:rotate-45">
                                    <i class="fa-solid fa-plus"></i>
                                </span>
                            </summary>
                            <div class="px-6 pb-5 text-gray-600 leading-relaxed border-t border-gray-100 pt-4">
                                <?= htmlspecialchars(t('faq.' . $k . '_a')) ?>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>

        <section class="bg-gradient-to-br from-blue-600 to-blue-800 rounded-2xl p-8 lg:p-12 text-center text-white mt-16">
            <h2 class="text-3xl font-bold mb-4"><?= htmlspecialchars(t('faq.cta_h')) ?></h2>
            <p class="text-blue-100 mb-8 max-w-2xl mx-auto"><?= htmlspecialchars(t('faq.cta_b')) ?></p>
            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                <a href="<?= ($isAr ? '/ar' : '') ?>/get-started"
                   class="inline-flex items-center justify-center gap-2 px-8 py-4 bg-white text-blue-600 font-semibold rounded-xl hover:bg-blue-50 transition-colors">
                    <?= htmlspecialchars(t('faq.cta_start')) ?>
                    <i class="fa-solid fa-arrow-<?= $isAr ? 'left' : 'right' ?>"></i>
                </a>
                <a href="<?= ($isAr ? '/ar' : '') ?>/contact"
                   class="inline-flex items-center justify-center gap-2 px-8 py-4 border-2 border-white text-white font-semibold rounded-xl hover:bg-white/10 transition-colors">
                    <?= htmlspecialchars(t('faq.cta_contact')) ?>
                    <i class="fa-solid fa-envelope"></i>
                </a>
            </div>
        </section>

        <div class="mt-8 text-center">
            <a href="<?= ($isAr ? '/ar' : '') ?>/" class="inline-flex items-center gap-2 text-blue-600 hover:text-blue-700 font-medium">
                <i class="fa-solid fa-arrow-<?= $isAr ? 'right' : 'left' ?>"></i>
                <?= htmlspecialchars(t('faq.back_home')) ?>
            </a>
        </div>
    </div>
</main>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
