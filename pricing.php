<?php
/**
 * Cardify, Pricing page.
 *
 * Free platform plus pay-per-print product catalogue (Standard 6 OMR,
 * Premium 8 OMR, Luxury 15 OMR, NFC 25 OMR). No SaaS tiers, no seat
 * caps, no trials. Unlimited everything on the platform side.
 *
 * Fully bilingual via lang/{en,ar}/pricing.php. Uses Seo::product for
 * JSON-LD offers on every print product and Seo::faqPage for the FAQ
 * block.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Seo.php';

$baseUrl = 'https://cardify.om';
$lang    = ($_GET['lang'] ?? '') === 'ar' ? 'ar' : 'en';
$isAr    = $lang === 'ar';

$brandName       = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
$pageTitle       = t('pricing.page_title');
$pageDescription = t('pricing.page_desc');
$canonicalUrl    = $baseUrl . ($isAr ? '/ar' : '') . '/pricing';
$showNavigation  = true;
$bodyClass       = 'bg-white' . ($isAr ? ' font-arabic' : '');
$bodyAttributes  = $isAr ? 'dir="rtl" lang="ar"' : '';

require_once INCLUDES_DIR . '/ui-header.php';

// JSON-LD, breadcrumbs + one Product per printed product + FAQ
Seo::breadcrumbs([
    [$isAr ? 'الرئيسية' : 'Home', '/'],
    [t('pricing.hero_eyebrow'), $canonicalUrl],
]);
Seo::product(t('pricing.product_standard_name'), t('pricing.product_standard_spec'), '6',  $canonicalUrl);
Seo::product(t('pricing.product_premium_name'),  t('pricing.product_premium_spec'),  '8',  $canonicalUrl);
Seo::product(t('pricing.product_luxury_name'),   t('pricing.product_luxury_spec'),   '15', $canonicalUrl);
Seo::product(t('pricing.product_nfc_name'),      t('pricing.product_nfc_spec'),      '25', $canonicalUrl);
Seo::faqPage([
    [t('pricing.faq_q1'), t('pricing.faq_a1')],
    [t('pricing.faq_q2'), t('pricing.faq_a2')],
    [t('pricing.faq_q3'), t('pricing.faq_a3')],
    [t('pricing.faq_q4'), t('pricing.faq_a4')],
    [t('pricing.faq_q5'), t('pricing.faq_a5')],
    [t('pricing.faq_q6'), t('pricing.faq_a6')],
]);

$waMsg   = $isAr ? 'مرحباً، أرغب بعرض توضيحي لكارديفاي' : 'Hi, I would like a demo of Cardify';
$waUrl   = 'https://wa.me/96898899100?text=' . rawurlencode($waMsg);
$arrow   = $isAr ? 'left' : 'right';
$regUrl  = ($isAr ? '/ar' : '') . '/company/register.php';

// Product catalogue driver
$products = [
    'standard' => ['accent' => 'blue',   'icon' => 'fa-id-card',     'highlight' => false],
    'premium'  => ['accent' => 'purple', 'icon' => 'fa-gem',         'highlight' => true],
    'luxury'   => ['accent' => 'amber',  'icon' => 'fa-award',       'highlight' => false],
    'nfc'      => ['accent' => 'emerald','icon' => 'fa-wifi',        'highlight' => false],
];
?>
<style>
    .pr-card { display: flex; flex-direction: column; transition: transform .3s cubic-bezier(.4,0,.2,1), box-shadow .3s cubic-bezier(.4,0,.2,1); }
    .pr-card:hover { transform: translateY(-4px); box-shadow: 0 20px 40px -12px rgba(0,0,0,.12); }
    .pr-feat { display: flex; align-items: flex-start; gap: .625rem; color: #374151; font-size: .95rem; padding: .375rem 0; }
    .pr-feat i { color: #16a34a; margin-top: .25rem; flex-shrink: 0; }
    .pr-highlight { box-shadow: 0 20px 40px -12px rgba(124, 58, 237, .25); }
</style>

<main class="bg-gray-50 pt-24 pb-16">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Hero -->
        <header class="text-center mb-12">
            <p class="text-sm font-semibold uppercase tracking-wider text-blue-600 mb-3"><?= htmlspecialchars(t('pricing.hero_eyebrow')) ?></p>
            <h1 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-gray-900 mb-4"><?= htmlspecialchars(t('pricing.hero_heading')) ?></h1>
            <p class="text-lg text-gray-600 max-w-2xl mx-auto"><?= htmlspecialchars(t('pricing.hero_sub')) ?></p>
        </header>

        <!-- Platform (free forever) -->
        <section class="mb-16">
            <article class="relative bg-white rounded-3xl px-8 pt-12 pb-8 lg:px-10 lg:pt-14 lg:pb-10 ring-1 ring-gray-200/70 shadow-xl">
                <!-- Inline top/<side> styles defend against Tailwind JIT
                     not having -top-3 / left-8 in the pre-built CSS. -->
                <span class="absolute px-4 py-1 bg-green-600 text-white text-xs font-bold rounded-full uppercase tracking-wider whitespace-nowrap shadow-md z-10"
                      style="top:-12px; <?= $isAr ? 'right:2rem' : 'left:2rem' ?>"><?= htmlspecialchars(t('pricing.platform_badge')) ?></span>
                <div class="grid lg:grid-cols-2 gap-8 items-center">
                    <div>
                        <h2 class="text-2xl font-bold text-gray-700 mb-2"><?= htmlspecialchars(t('pricing.platform_name')) ?></h2>
                        <div class="flex items-baseline gap-2 mb-3">
                            <span class="text-5xl lg:text-6xl font-extrabold text-gray-900"><?= htmlspecialchars(t('pricing.platform_price')) ?></span>
                        </div>
                        <p class="text-gray-500 mb-6"><?= htmlspecialchars(t('pricing.platform_sub')) ?></p>
                        <a href="<?= htmlspecialchars($regUrl) ?>" class="inline-flex items-center justify-center gap-2 px-7 py-3.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl shadow-lg shadow-blue-500/30 transition hover:-translate-y-0.5">
                            <?= htmlspecialchars(t('pricing.platform_cta')) ?>
                            <i class="fa-solid fa-arrow-<?= $arrow ?>"></i>
                        </a>
                    </div>
                    <ul class="grid sm:grid-cols-2 gap-x-4 gap-y-1">
                        <?php for ($i = 1; $i <= 8; $i++): ?>
                            <li class="pr-feat">
                                <i class="fa-solid fa-check"></i>
                                <span><?= htmlspecialchars(t('pricing.platform_f' . $i)) ?></span>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </div>
            </article>
        </section>

        <!-- Print products catalogue -->
        <section class="mb-16">
            <header class="text-center mb-10">
                <p class="text-sm font-semibold uppercase tracking-wider text-blue-600 mb-3"><?= htmlspecialchars(t('pricing.products_eyebrow')) ?></p>
                <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-900 mb-3"><?= htmlspecialchars(t('pricing.products_h')) ?></h2>
                <p class="text-lg text-gray-600 max-w-2xl mx-auto"><?= htmlspecialchars(t('pricing.products_b')) ?></p>
            </header>

            <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-6">
                <?php foreach ($products as $key => $meta):
                    $accent = $meta['accent'];
                    $ring   = $meta['highlight'] ? 'ring-2 ring-purple-500' : 'ring-1 ring-gray-200/70';
                    $cardClass = 'pr-card bg-white rounded-2xl p-7 ' . $ring . ($meta['highlight'] ? ' pr-highlight' : '');
                ?>
                    <article class="<?= $cardClass ?>">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center mb-4 bg-<?= $accent ?>-100">
                            <i class="fa-solid <?= $meta['icon'] ?> text-xl text-<?= $accent ?>-600"></i>
                        </div>
                        <h3 class="text-lg font-bold text-gray-900 mb-1"><?= htmlspecialchars(t('pricing.product_' . $key . '_name')) ?></h3>
                        <p class="text-sm text-gray-500 mb-5 leading-relaxed"><?= htmlspecialchars(t('pricing.product_' . $key . '_spec')) ?></p>
                        <div class="mb-5">
                            <div class="flex items-baseline gap-2">
                                <span class="text-3xl font-extrabold text-gray-900"><?= htmlspecialchars(t('pricing.product_' . $key . '_price')) ?></span>
                            </div>
                            <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(t('pricing.product_' . $key . '_unit')) ?></p>
                        </div>
                        <a href="<?= htmlspecialchars($regUrl) ?>" class="mt-auto inline-flex items-center justify-center gap-2 w-full px-5 py-3 rounded-xl font-semibold transition bg-gray-900 hover:bg-gray-800 text-white">
                            <?= htmlspecialchars(t('pricing.product_cta')) ?>
                            <i class="fa-solid fa-arrow-<?= $arrow ?> text-xs"></i>
                        </a>
                    </article>
                <?php endforeach; ?>
            </div>

            <p class="text-center text-sm text-gray-500 mt-6"><?= htmlspecialchars(t('pricing.products_note')) ?></p>
        </section>

        <!-- FAQ -->
        <section class="mb-16 max-w-3xl mx-auto">
            <h2 class="text-2xl font-bold text-gray-900 text-center mb-8"><?= htmlspecialchars(t('pricing.faq_h')) ?></h2>
            <dl class="space-y-4">
                <?php for ($i = 1; $i <= 6; $i++): ?>
                <details class="group bg-white ring-1 ring-gray-200/70 rounded-xl p-5 open:ring-blue-200">
                    <summary class="cursor-pointer list-none flex items-center justify-between gap-4">
                        <dt class="font-semibold text-gray-900"><?= htmlspecialchars(t('pricing.faq_q' . $i)) ?></dt>
                        <i class="fa-solid fa-chevron-down text-gray-400 transition-transform group-open:rotate-180"></i>
                    </summary>
                    <dd class="mt-3 text-gray-600 leading-relaxed"><?= htmlspecialchars(t('pricing.faq_a' . $i)) ?></dd>
                </details>
                <?php endfor; ?>
            </dl>
        </section>

        <!-- Closing CTA -->
        <section class="bg-gradient-to-br from-blue-600 to-indigo-700 rounded-2xl p-10 text-center text-white">
            <h2 class="text-3xl font-extrabold mb-3"><?= htmlspecialchars(t('pricing.closing_h')) ?></h2>
            <p class="text-blue-100 max-w-xl mx-auto mb-6"><?= htmlspecialchars(t('pricing.closing_b')) ?></p>
            <div class="flex flex-col sm:flex-row gap-3 justify-center">
                <a href="<?= htmlspecialchars($regUrl) ?>" class="inline-flex items-center justify-center gap-2 px-6 py-3 bg-white text-blue-700 font-semibold rounded-xl hover:bg-blue-50 transition">
                    <?= htmlspecialchars(t('pricing.closing_cta')) ?>
                    <i class="fa-solid fa-arrow-<?= $arrow ?>"></i>
                </a>
                <a href="<?= htmlspecialchars($waUrl) ?>" target="_blank" rel="noopener" class="inline-flex items-center justify-center gap-2 px-6 py-3 bg-green-500 text-white font-semibold rounded-xl hover:bg-green-600 transition">
                    <i class="fa-brands fa-whatsapp"></i>
                    <?= htmlspecialchars(t('pricing.closing_cta2')) ?>
                </a>
            </div>
        </section>
    </div>
</main>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
