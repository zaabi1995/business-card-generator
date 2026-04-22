<?php
/**
 * Cardify — Pricing (Cat R action 436).
 *
 * Four tiers in OMR: Starter (free, 3 seats) / Professional (5 / mo, 10
 * seats) / Business (15 / mo, 50 seats) / Enterprise (custom). Print
 * cards priced separately, from 6 OMR, marketplace-sourced.
 *
 * Fully bilingual through lang/{en,ar}/pricing.php. Uses Seo::product
 * for JSON-LD offers on every paid tier and Seo::faqPage for the FAQ
 * block at the bottom.
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

// JSON-LD — one Product per paid tier + breadcrumbs + FAQ
Seo::breadcrumbs([
    [$isAr ? 'الرئيسية' : 'Home', '/'],
    [t('pricing.hero_eyebrow'), $canonicalUrl],
]);
Seo::product(t('pricing.pro_name'),   t('pricing.pro_badge'),   (string) 5,  $canonicalUrl);
Seo::product(t('pricing.biz_name'),   t('pricing.biz_badge'),   (string) 15, $canonicalUrl);
Seo::faqPage([
    [t('pricing.faq_q1'), t('pricing.faq_a1')],
    [t('pricing.faq_q2'), t('pricing.faq_a2')],
    [t('pricing.faq_q3'), t('pricing.faq_a3')],
    [t('pricing.faq_q4'), t('pricing.faq_a4')],
    [t('pricing.faq_q5'), t('pricing.faq_a5')],
    [t('pricing.faq_q6'), t('pricing.faq_a6')],
]);

$waMsg     = $isAr ? 'مرحباً، أرغب بعرض توضيحي لكارديفاي' : 'Hi, I would like a demo of Cardify';
$waSalesMsg = $isAr ? 'مرحباً، أرغب بالحديث عن باقة إنتربرايز' : 'Hi, I would like to discuss an Enterprise plan';
$waUrl     = 'https://wa.me/96899899100?text=' . rawurlencode($waMsg);
$waSales   = 'https://wa.me/96899899100?text=' . rawurlencode($waSalesMsg);
?>
<style>
    .pr-card { display: flex; flex-direction: column; }
    .pr-feat { display: flex; align-items: flex-start; gap: .625rem; color: #374151; font-size: .95rem; padding: .375rem 0; }
    .pr-feat i { color: #2563eb; margin-top: .25rem; flex-shrink: 0; }
    .pr-highlight { position: relative; box-shadow: 0 20px 40px -12px rgba(37, 99, 235, .25); transform: translateY(-8px); }
    @media (max-width: 768px) { .pr-highlight { transform: none; } }
</style>

<main class="bg-gray-50 pt-24 pb-16">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Hero -->
        <header class="text-center mb-12">
            <p class="text-sm font-semibold uppercase tracking-wider text-blue-600 mb-3"><?= htmlspecialchars(t('pricing.hero_eyebrow')) ?></p>
            <h1 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-gray-900 mb-4"><?= htmlspecialchars(t('pricing.hero_heading')) ?></h1>
            <p class="text-lg text-gray-600 max-w-2xl mx-auto"><?= htmlspecialchars(t('pricing.hero_sub')) ?></p>
        </header>

        <div x-data="{ yearly: false }">
        <!-- Billing toggle (monthly vs yearly) -->
        <div class="flex justify-center mb-10">
            <div class="inline-flex bg-gray-100 rounded-full p-1 border border-gray-200">
                <button type="button" @click="yearly = false" :class="!yearly ? 'bg-white shadow text-gray-900' : 'text-gray-500'" class="px-4 py-2 rounded-full text-sm font-medium transition">
                    <?= htmlspecialchars(t('pricing.billing_month')) ?>
                </button>
                <button type="button" @click="yearly = true" :class="yearly ? 'bg-white shadow text-gray-900' : 'text-gray-500'" class="px-4 py-2 rounded-full text-sm font-medium transition">
                    <?= htmlspecialchars(t('pricing.billing_year')) ?>
                </button>
            </div>
        </div>

        <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-6">
        <?php
        // Each tier is a compact spec used to render the card.
        $tiers = [
            ['p' => 'starter', 'features' => 5,  'highlight' => false, 'cta_href' => ($isAr ? '/ar' : '') . '/get-started'],
            ['p' => 'pro',     'features' => 7,  'highlight' => true,  'cta_href' => ($isAr ? '/ar' : '') . '/get-started?plan=professional'],
            ['p' => 'biz',     'features' => 7,  'highlight' => false, 'cta_href' => ($isAr ? '/ar' : '') . '/get-started?plan=business'],
            ['p' => 'ent',     'features' => 7,  'highlight' => false, 'cta_href' => $waSales],
        ];
        foreach ($tiers as $t):
            $p = $t['p'];
            $isEnt = $p === 'ent';
            $cardClass = 'pr-card bg-white rounded-2xl p-8 ring-1 ring-gray-200/70 ' . ($t['highlight'] ? 'pr-highlight ring-blue-500' : '');
        ?>
            <article class="<?= $cardClass ?>">
                <?php if ($t['highlight']): ?>
                    <span class="inline-block self-start text-xs font-semibold uppercase tracking-wider text-blue-600 bg-blue-50 px-2.5 py-1 rounded-full mb-2">
                        <?= htmlspecialchars(t('pricing.pro_highlight')) ?>
                    </span>
                <?php endif; ?>
                <h2 class="text-xl font-bold text-gray-900 mb-1"><?= htmlspecialchars(t('pricing.' . $p . '_name')) ?></h2>
                <p class="text-sm text-gray-500 mb-4"><?= htmlspecialchars(t('pricing.' . $p . '_badge')) ?></p>

                <?php if ($isEnt): ?>
                    <div class="mb-5">
                        <span class="text-3xl font-extrabold text-gray-900"><?= htmlspecialchars(t('pricing.ent_price')) ?></span>
                    </div>
                <?php elseif ($p === 'starter'): ?>
                    <div class="mb-5">
                        <span class="text-4xl font-extrabold text-gray-900"><?= htmlspecialchars(t('pricing.free_label')) ?></span>
                    </div>
                <?php else: ?>
                    <div class="mb-5">
                        <div x-show="!yearly">
                            <span class="text-4xl font-extrabold text-gray-900"><?= htmlspecialchars(t('pricing.' . $p . '_price')) ?></span>
                            <span class="text-gray-500 font-medium text-sm"> OMR<?= htmlspecialchars(t('pricing.per_month')) ?></span>
                        </div>
                        <div x-show="yearly" x-cloak>
                            <span class="text-4xl font-extrabold text-gray-900"><?= htmlspecialchars(t('pricing.' . $p . '_price_y')) ?></span>
                            <span class="text-gray-500 font-medium text-sm"> OMR<?= htmlspecialchars(t('pricing.per_year')) ?></span>
                        </div>
                    </div>
                <?php endif; ?>

                <ul class="flex-1 space-y-0.5 mb-6">
                    <?php for ($i = 1; $i <= $t['features']; $i++): ?>
                        <li class="pr-feat">
                            <i class="fa-solid fa-check"></i>
                            <span><?= htmlspecialchars(t('pricing.' . $p . '_f' . $i)) ?></span>
                        </li>
                    <?php endfor; ?>
                </ul>

                <a href="<?= htmlspecialchars($t['cta_href']) ?>"
                   <?= $isEnt ? 'target="_blank" rel="noopener"' : '' ?>
                   class="inline-flex items-center justify-center gap-2 w-full px-5 py-3 rounded-xl font-semibold transition <?= $t['highlight'] ? 'bg-blue-600 hover:bg-blue-700 text-white shadow-lg shadow-blue-600/30' : 'bg-gray-900 hover:bg-gray-800 text-white' ?>">
                    <?= htmlspecialchars(t('pricing.' . $p . '_cta')) ?>
                    <i class="fa-solid fa-arrow-<?= $isAr ? 'left' : 'right' ?> text-xs"></i>
                </a>
            </article>
        <?php endforeach; ?>
        </div>
        </div>

        <!-- Print pricing row -->
        <section class="mt-16 bg-white rounded-2xl p-8 ring-1 ring-gray-200/70">
            <header class="mb-6">
                <h2 class="text-2xl font-bold text-gray-900 mb-2"><?= htmlspecialchars(t('pricing.print_h')) ?></h2>
                <p class="text-gray-600"><?= htmlspecialchars(t('pricing.print_b')) ?></p>
            </header>
            <div class="grid sm:grid-cols-2 gap-4 mb-4">
                <?php foreach ([1,2,3,4] as $n): ?>
                <div class="flex items-center justify-between py-3 px-4 bg-gray-50 rounded-xl">
                    <span class="text-gray-900 font-medium"><?= htmlspecialchars(t('pricing.print_row' . $n . '_l')) ?></span>
                    <span class="text-blue-600 font-semibold"><?= htmlspecialchars(t('pricing.print_row' . $n . '_p')) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <p class="text-sm text-gray-500"><?= htmlspecialchars(t('pricing.print_note')) ?></p>
        </section>

        <!-- FAQ -->
        <section class="mt-16 max-w-3xl mx-auto">
            <h2 class="text-2xl font-bold text-gray-900 text-center mb-8"><?= htmlspecialchars(t('pricing.faq_h')) ?></h2>
            <dl class="space-y-4">
                <?php for ($i = 1; $i <= 6; $i++): ?>
                <details class="bg-white ring-1 ring-gray-200/70 rounded-xl p-5 open:ring-blue-200">
                    <summary class="cursor-pointer list-none flex items-center justify-between gap-4">
                        <dt class="font-semibold text-gray-900"><?= htmlspecialchars(t('pricing.faq_q' . $i)) ?></dt>
                        <i class="fa-solid fa-chevron-down text-gray-400 transition-transform group-open:rotate-180"></i>
                    </summary>
                    <dd class="mt-3 text-gray-600 leading-relaxed"><?= htmlspecialchars(t('pricing.faq_a' . $i)) ?></dd>
                </details>
                <?php endfor; ?>
            </dl>
        </section>

        <!-- Closing -->
        <section class="mt-16 bg-gradient-to-br from-blue-600 to-indigo-700 rounded-2xl p-10 text-center text-white">
            <h2 class="text-3xl font-extrabold mb-3"><?= htmlspecialchars(t('pricing.closing_h')) ?></h2>
            <p class="text-blue-100 max-w-xl mx-auto mb-6"><?= htmlspecialchars(t('pricing.closing_b')) ?></p>
            <div class="flex flex-col sm:flex-row gap-3 justify-center">
                <a href="<?= ($isAr ? '/ar' : '') ?>/get-started" class="inline-flex items-center justify-center gap-2 px-6 py-3 bg-white text-blue-700 font-semibold rounded-xl hover:bg-blue-50 transition">
                    <?= htmlspecialchars(t('pricing.closing_cta')) ?>
                    <i class="fa-solid fa-arrow-<?= $isAr ? 'left' : 'right' ?>"></i>
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
