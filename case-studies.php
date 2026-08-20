<?php
/**
 * Cardify, Case Studies (Cat R action 435).
 *
 * Three real Omani teams running on Cardify: BHD Printing & Designing,
 * CupsByAA and Alali Investment. Listing hub at /case-studies, per-
 * company detail at /case-studies/{slug}.
 *
 * All three companies are BHD-family-controlled entities that genuinely
 * use Cardify today, so we can speak to their stories without asking
 * for permission.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Seo.php';
require_once INCLUDES_DIR . '/ArTwins.php';

$baseUrl   = 'https://cardify.om';
$lang      = ($_GET['lang'] ?? '') === 'ar' ? 'ar' : 'en';
$isAr      = $lang === 'ar';
$slug      = $_GET['slug'] ?? null;

$CASES = [
    'bhd' => [
        'key_prefix'  => 'bhd',
        'slug'        => 'bhd-printing-and-designing',
        'logo'        => '/storage/logos/verified/2492.svg',
        'accent'      => 'from-blue-500 to-blue-600',
        'website'     => 'https://bhd.om',
    ],
    'cupsbyaa' => [
        'key_prefix'  => 'cup',
        'slug'        => 'cupsbyaa',
        'logo'        => '/storage/logos/verified/2496.svg',
        'accent'      => 'from-pink-400 to-pink-500',
        'website'     => 'https://cupsbyaa.com',
    ],
    'alali' => [
        'key_prefix'  => 'ali',
        'slug'        => 'alali-investments',
        'logo'        => '/storage/logos/verified/2499.svg',
        'accent'      => 'from-amber-400 to-amber-500',
        'website'     => null,
    ],
];

// --- Detail view ---
$activeCase = null;
if ($slug) {
    foreach ($CASES as $c) {
        if ($c['slug'] === $slug) { $activeCase = $c; break; }
    }
    if (!$activeCase) {
        http_response_code(404);
        include __DIR__ . '/404.php';
        exit;
    }
}

// --- Page metadata ---
$brandName       = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
$pageTitle       = $activeCase
    ? t('case_studies.' . $activeCase['key_prefix'] . '_name') . ', ' . t('case_studies.page_title')
    : t('case_studies.page_title');
$pageDescription = $activeCase
    ? t('case_studies.' . $activeCase['key_prefix'] . '_summary')
    : t('case_studies.page_desc');

$canonicalUrl = $baseUrl . ($isAr ? '/ar' : '') . '/case-studies'
    . ($activeCase ? '/' . $activeCase['slug'] : '');

$bodyClass       = 'bg-white' . ($isAr ? ' font-arabic' : '');
$bodyAttributes  = $isAr ? 'dir="rtl" lang="ar"' : '';
$showNavigation  = true;

require_once INCLUDES_DIR . '/ui-header.php';

// --- JSON-LD (Article schema on detail, ItemList on hub) ---
if ($activeCase) {
    Seo::breadcrumbs([
        [$isAr ? 'الرئيسية' : 'Home', '/'],
        [t('case_studies.back_to_list'), ($isAr ? '/ar' : '') . '/case-studies'],
        [t('case_studies.' . $activeCase['key_prefix'] . '_name'), $canonicalUrl],
    ]);
    Seo::article(
        t('case_studies.' . $activeCase['key_prefix'] . '_name') . ', ' . t('case_studies.hero_heading'),
        t('case_studies.' . $activeCase['key_prefix'] . '_summary'),
        $canonicalUrl,
        $baseUrl . $activeCase['logo'],
        null,
        null,
        'Cardify'
    );
}
?>

<main class="bg-gray-50 min-h-screen pt-24 pb-16">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">

    <?php if (!$activeCase): // --- LIST VIEW --- ?>
        <header class="text-center mb-12">
            <p class="text-sm font-semibold uppercase tracking-wider text-blue-600 mb-3"><?= htmlspecialchars(t('case_studies.hero_eyebrow')) ?></p>
            <h1 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-gray-900 mb-4"><?= htmlspecialchars(t('case_studies.hero_heading')) ?></h1>
            <p class="text-lg text-gray-600 max-w-2xl mx-auto"><?= htmlspecialchars(t('case_studies.hero_sub')) ?></p>
        </header>

        <div class="grid md:grid-cols-3 gap-8">
        <?php foreach ($CASES as $c): $p = $c['key_prefix']; ?>
            <a href="<?= ($isAr ? '/ar' : '') ?>/case-studies/<?= htmlspecialchars($c['slug']) ?>"
               class="group block bg-white rounded-2xl overflow-hidden shadow-sm ring-1 ring-gray-200/70 hover:shadow-lg hover:ring-blue-200 transition-all">
                <div class="aspect-[16/9] bg-gradient-to-br <?= $c['accent'] ?> flex items-center justify-center p-8">
                    <img src="<?= htmlspecialchars($c['logo']) ?>" alt="<?= htmlspecialchars(t('case_studies.' . $p . '_name')) ?>" class="max-h-20 max-w-full drop-shadow" loading="lazy" width="200" height="80">
                </div>
                <div class="p-6">
                    <h2 class="text-xl font-bold text-gray-900 mb-2 group-hover:text-blue-700 transition-colors">
                        <?= htmlspecialchars(t('case_studies.' . $p . '_name')) ?>
                    </h2>
                    <p class="text-sm text-gray-500 mb-3"><?= htmlspecialchars(t('case_studies.' . $p . '_industry')) ?></p>
                    <p class="text-gray-700 leading-relaxed mb-4"><?= htmlspecialchars(t('case_studies.' . $p . '_summary')) ?></p>
                    <div class="inline-flex items-center gap-1.5 text-blue-600 font-medium text-sm">
                        <?= htmlspecialchars(t('case_studies.read_more')) ?>
                        <i class="fa-solid fa-arrow-<?= $isAr ? 'left' : 'right' ?> text-xs"></i>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
        </div>

    <?php else: // --- DETAIL VIEW --- $p = $activeCase['key_prefix']; ?>
        <nav class="mb-8 text-sm">
            <a href="<?= ($isAr ? '/ar' : '') ?>/case-studies" class="text-blue-600 hover:text-blue-700 inline-flex items-center gap-1.5">
                <i class="fa-solid fa-arrow-<?= $isAr ? 'right' : 'left' ?>"></i>
                <?= htmlspecialchars(t('case_studies.back_to_list')) ?>
            </a>
        </nav>

        <article class="bg-white rounded-2xl overflow-hidden shadow-sm ring-1 ring-gray-200/70">
            <header class="bg-gradient-to-br <?= $activeCase['accent'] ?> p-10 text-white">
                <div class="flex items-center gap-6 flex-wrap">
                    <div class="bg-white rounded-2xl p-4 shadow-lg">
                        <img src="<?= htmlspecialchars($activeCase['logo']) ?>" alt="<?= htmlspecialchars(t('case_studies.' . $p . '_name')) ?>" class="h-16 w-auto" width="200" height="64">
                    </div>
                    <div>
                        <h1 class="text-3xl sm:text-4xl font-extrabold mb-1"><?= htmlspecialchars(t('case_studies.' . $p . '_name')) ?></h1>
                        <p class="text-white/80"><?= htmlspecialchars(t('case_studies.' . $p . '_industry')) ?></p>
                    </div>
                </div>
            </header>

            <div class="p-10 grid md:grid-cols-3 gap-6 border-b border-gray-100 bg-gray-50">
                <div>
                    <div class="text-xs uppercase tracking-wider text-gray-500 mb-1"><?= htmlspecialchars(t('case_studies.meta_team_size')) ?></div>
                    <div class="text-gray-900 font-semibold"><?= htmlspecialchars(t('case_studies.' . $p . '_team')) ?></div>
                </div>
                <div>
                    <div class="text-xs uppercase tracking-wider text-gray-500 mb-1"><?= htmlspecialchars(t('case_studies.meta_live_since')) ?></div>
                    <div class="text-gray-900 font-semibold"><?= htmlspecialchars(t('case_studies.' . $p . '_live_since')) ?></div>
                </div>
                <div>
                    <div class="text-xs uppercase tracking-wider text-gray-500 mb-1"><?= htmlspecialchars(t('case_studies.meta_outcome')) ?></div>
                    <div class="text-gray-900 font-semibold"><?= htmlspecialchars(t('case_studies.' . $p . '_outcome')) ?></div>
                </div>
            </div>

            <div class="p-10 prose prose-gray max-w-none">
                <p class="text-lg text-gray-700 leading-relaxed mb-10"><?= htmlspecialchars(t('case_studies.' . $p . '_summary')) ?></p>

                <h2 class="text-2xl font-bold text-gray-900 mb-3"><?= htmlspecialchars(t('case_studies.' . $p . '_challenge_h')) ?></h2>
                <p class="text-gray-700 leading-relaxed mb-8"><?= htmlspecialchars(t('case_studies.' . $p . '_challenge_b')) ?></p>

                <h2 class="text-2xl font-bold text-gray-900 mb-3"><?= htmlspecialchars(t('case_studies.' . $p . '_solution_h')) ?></h2>
                <p class="text-gray-700 leading-relaxed mb-8"><?= htmlspecialchars(t('case_studies.' . $p . '_solution_b')) ?></p>

                <h2 class="text-2xl font-bold text-gray-900 mb-3"><?= htmlspecialchars(t('case_studies.' . $p . '_result_h')) ?></h2>
                <p class="text-gray-700 leading-relaxed mb-10"><?= htmlspecialchars(t('case_studies.' . $p . '_result_b')) ?></p>

                <blockquote class="border-<?= $isAr ? 'r' : 'l' ?>-4 border-blue-500 bg-blue-50 rounded-<?= $isAr ? 'r' : 'l' ?>-lg p-6 not-prose mb-2">
                    <p class="text-lg text-gray-900 italic leading-relaxed mb-3">&ldquo;<?= htmlspecialchars(t('case_studies.' . $p . '_quote')) ?>&rdquo;</p>
                    <footer class="text-sm font-semibold text-gray-700"><?= htmlspecialchars(t('case_studies.' . $p . '_quote_author')) ?></footer>
                </blockquote>

                <?php if (!empty($activeCase['website'])): ?>
                <p class="mt-8">
                    <a href="<?= htmlspecialchars($activeCase['website']) ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 text-blue-600 hover:text-blue-700 font-medium">
                        <i class="fa-solid fa-up-right-from-square text-xs"></i>
                        <?= htmlspecialchars(t('case_studies.visit_website')) ?>
                    </a>
                </p>
                <?php endif; ?>
            </div>
        </article>
    <?php endif; ?>

        <!-- Closing CTA, shared by both views -->
        <section class="mt-16 bg-gradient-to-br from-blue-600 to-indigo-700 rounded-2xl p-10 text-center text-white">
            <h2 class="text-3xl font-extrabold mb-3"><?= htmlspecialchars(t('case_studies.closing_h')) ?></h2>
            <p class="text-blue-100 max-w-xl mx-auto mb-6"><?= htmlspecialchars(t('case_studies.closing_b')) ?></p>
            <div class="flex flex-col sm:flex-row gap-3 justify-center">
                <a href="<?= htmlspecialchars(ArTwins::navLink('get-started', '/', $isAr)) ?>" class="inline-flex items-center justify-center gap-2 px-6 py-3 bg-white text-blue-700 font-semibold rounded-xl hover:bg-blue-50 transition">
                    <?= htmlspecialchars(t('case_studies.closing_cta')) ?>
                    <i class="fa-solid fa-arrow-<?= $isAr ? 'left' : 'right' ?>"></i>
                </a>
                <?php $waMsg = $isAr ? 'مرحباً، أرغب بعرض توضيحي لكارديفاي' : 'Hi, I would like a demo of Cardify'; ?>
                <a href="https://api.whatsapp.com/send?phone=96898899100&amp;text=<?= rawurlencode($waMsg) ?>" target="_blank" rel="noopener" class="inline-flex items-center justify-center gap-2 px-6 py-3 bg-green-500 text-white font-semibold rounded-xl hover:bg-green-600 transition">
                    <i class="fa-brands fa-whatsapp"></i>
                    <?= htmlspecialchars(t('case_studies.closing_cta_wa')) ?>
                </a>
            </div>
        </section>
    </div>
</main>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
