<?php
/**
 * Cardify, Pricing page.
 *
 * Free platform plus a pay-per-print product catalogue. Every published
 * amount comes from CardCatalogPricing, which is the only place a price is
 * written; this docblock used to carry its own numbers and three of the four
 * had drifted (it said Standard 6 and Premium 8 while the page rendered 5
 * and 6). No SaaS tiers, no seat caps, no trials.
 *
 * Fully bilingual via lang/{en,ar}/pricing.php. Uses Seo::product for
 * JSON-LD offers on every print product and Seo::faqPage for the FAQ
 * block.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Seo.php';
require_once INCLUDES_DIR . '/ArTwins.php';
require_once INCLUDES_DIR . '/CardCatalogPricing.php';

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
// r81 / llm20-21: the keys, not the RENDERED strings. Passing t() meant this
// page published four Products in whatever locale the request arrived in, each
// anonymous and each carrying no url and no sku, so /pricing and /ar/pricing
// described the same four products as eight unmergeable things.
// The image argument closes the CRITICAL half of GSC WNC-10030322: these four
// Products published no image at all, because the page renders each tier as a
// FontAwesome icon and no photograph of the stock existed. One photo per tier
// now lives in assets/images/products.
Seo::product('standard', 'pricing.product_standard_name', 'pricing.product_standard_spec', CardCatalogPricing::decimal('standard'),  'card-standard.jpg');
Seo::product('premium',  'pricing.product_premium_name',  'pricing.product_premium_spec',  CardCatalogPricing::decimal('premium'),  'card-premium.jpg');
Seo::product('luxury',   'pricing.product_luxury_name',   'pricing.product_luxury_spec',   CardCatalogPricing::decimal('luxury'), 'card-luxury.jpg');
Seo::product('nfc',      'pricing.product_nfc_name',      'pricing.product_nfc_spec',      CardCatalogPricing::decimal('nfc'), 'card-nfc.jpg');
Seo::faqPage([
    [t('pricing.faq_q1'), t('pricing.faq_a1')],
    [t('pricing.faq_q2'), t('pricing.faq_a2')],
    [t('pricing.faq_q3'), t('pricing.faq_a3')],
    [t('pricing.faq_q4'), t('pricing.faq_a4')],
    [t('pricing.faq_q5'), t('pricing.faq_a5')],
    [t('pricing.faq_q6'), t('pricing.faq_a6')],
]);

$waMsg   = $isAr ? 'مرحباً، أرغب بعرض توضيحي لكارديفاي' : 'Hi, I would like a demo of Cardify';
// Cardify demo line = Anna's Dardasha line (96898899100 = DARDASHA_FROM, the
// staffed line that auto-replies). BHD print-order links (bhd/, customize.php)
// keep the separate BHD line 96899999100 on purpose.
$waUrl   = 'https://api.whatsapp.com/send?phone=96898899100&text=' . rawurlencode($waMsg);
$arrow   = $isAr ? 'left' : 'right';
$regUrl  = ArTwins::navLink('company/register-otp.php', '/', $isAr);

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
    .pr-feat i { color: #15803d; margin-top: .25rem; flex-shrink: 0; }
    .pr-highlight { box-shadow: 0 20px 40px -12px rgba(124, 58, 237, .25); }
    /* Segmented control + radio rows (invideo / Wolt pattern). With no JS
       every panel shows, so nothing is ever unreachable. */
    .pr-seg { display: flex; gap: 4px; padding: 4px; background: #e5e7eb; border-radius: 999px; max-width: 30rem; margin: 0 auto 2rem; }
    .pr-seg button { flex: 1; min-height: 44px; border-radius: 999px; font-weight: 600; font-size: .875rem; white-space: nowrap; color: #374151; background: transparent; border: 0; cursor: pointer; padding: 0 .75rem; }
    .pr-seg button[aria-selected="true"] { background: #fff; color: #111827; box-shadow: 0 1px 3px rgba(0,0,0,.12); }
    .pr-seg button:focus-visible { outline: 2px solid #009bc1; outline-offset: 2px; }
    .pr-rows { max-width: 40rem; margin: 0 auto; display: grid; gap: .75rem; }
    .pr-row { display: flex; align-items: center; gap: .875rem; background: #fff; border-radius: 1rem; padding: 1rem 1.125rem; box-shadow: inset 0 0 0 1px #e5e7eb; cursor: pointer; }
    .pr-row:has(input:checked) { box-shadow: inset 0 0 0 2px #2563eb; }
    .pr-row input { width: 1.25rem; height: 1.25rem; flex-shrink: 0; accent-color: #2563eb; }
    .pr-row__text { flex: 1; min-width: 0; }
    .pr-row__name { display: block; font-weight: 700; color: #111827; }
    .pr-row__spec { display: block; font-size: .8125rem; color: #6b7280; line-height: 1.4; margin-top: 2px; }
    .pr-row__price { text-align: end; flex-shrink: 0; }
    .pr-row__price b { display: block; font-size: 1.125rem; color: #111827; font-weight: 800; white-space: nowrap; }
    .pr-row__price small { font-size: .75rem; color: #6b7280; white-space: nowrap; }
    .pr-orderbar { position: sticky; bottom: 0; z-index: 20; max-width: 40rem; margin: 1rem auto 0; padding: .75rem 0 calc(.75rem + env(safe-area-inset-bottom)); background: linear-gradient(to top, #f9fafb 70%, rgba(249,250,251,0)); }
    .pr-orderbar a { display: flex; align-items: center; justify-content: space-between; gap: .75rem; min-height: 52px; padding: 0 1.25rem; border-radius: .875rem; font-weight: 700; text-decoration: none; }
    [hidden] { display: none !important; }
</style>

<main class="bg-gray-50 pt-24 pb-16">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Hero -->
        <header class="text-center mb-12">
            <p class="text-sm font-semibold uppercase tracking-wider text-blue-600 mb-3"><?= htmlspecialchars(t('pricing.hero_eyebrow')) ?></p>
            <h1 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-gray-900 mb-4"><?= htmlspecialchars(t('pricing.hero_heading')) ?></h1>
            <p class="text-base text-gray-600 max-w-2xl mx-auto"><?= htmlspecialchars(t('pricing.hero_sub')) ?></p>
        </header>

        <div class="pr-seg" role="tablist" aria-label="<?= htmlspecialchars(t('pricing.tabs_label')) ?>" id="pr-seg">
            <button type="button" role="tab" id="pr-tab-platform" aria-controls="pr-panel-platform" aria-selected="false"><?= htmlspecialchars(t('pricing.tab_platform')) ?></button>
            <button type="button" role="tab" id="pr-tab-prints" aria-controls="pr-panel-prints" aria-selected="true"><?= htmlspecialchars(t('pricing.tab_prints')) ?></button>
            <button type="button" role="tab" id="pr-tab-nfc" aria-controls="pr-panel-nfc" aria-selected="false"><?= htmlspecialchars(t('pricing.tab_nfc')) ?></button>
        </div>

        <!-- Platform (free forever) -->
        <section class="mb-16" id="pr-panel-platform" role="tabpanel" aria-labelledby="pr-tab-platform">
            <article class="relative bg-white rounded-3xl px-8 pt-12 pb-8 lg:px-10 lg:pt-14 lg:pb-10 ring-1 ring-gray-200/70 shadow-xl" style="padding-top:3.5rem">
                <!-- Inline top/<side> styles defend against Tailwind JIT not
                     having -top-3 / left-8 / pt-12 in the pre-built CSS. Without
                     the inline padding-top the "FREE FOREVER" badge overlapped
                     the "Platform Access" heading at every viewport. -->
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

        <!-- Printed cards: one radio row per card type, price on the right -->
        <?php
        $printKeys = ['standard', 'premium', 'luxury'];
        $row = static function (string $key, string $group, bool $checked): void {
            $name  = t('pricing.product_' . $key . '_name');
            $price = t('pricing.product_' . $key . '_price');
            $unit  = t('pricing.product_' . $key . '_unit'); ?>
            <label class="pr-row">
                <input type="radio" name="<?= htmlspecialchars($group) ?>" value="<?= htmlspecialchars($key) ?>"<?= $checked ? ' checked' : '' ?>
                       data-name="<?= htmlspecialchars($name) ?>" data-price="<?= htmlspecialchars($price) ?>" data-unit="<?= htmlspecialchars($unit) ?>">
                <span class="pr-row__text">
                    <span class="pr-row__name"><?= htmlspecialchars($name) ?></span>
                    <span class="pr-row__spec"><?= htmlspecialchars(t('pricing.product_' . $key . '_spec')) ?></span>
                </span>
                <span class="pr-row__price"><b><?= htmlspecialchars($price) ?></b><small><?= htmlspecialchars($unit) ?></small></span>
            </label>
        <?php }; ?>
        <section class="mb-16" id="pr-panel-prints" role="tabpanel" aria-labelledby="pr-tab-prints">
            <header class="text-center mb-6">
                <h2 class="text-2xl sm:text-3xl font-extrabold text-gray-900 mb-2"><?= htmlspecialchars(t('pricing.products_h')) ?></h2>
                <p class="text-sm text-gray-600 max-w-2xl mx-auto"><?= htmlspecialchars(t('pricing.products_b')) ?></p>
            </header>
            <fieldset class="pr-rows">
                <legend class="sr-only"><?= htmlspecialchars(t('pricing.tab_prints')) ?></legend>
                <?php foreach ($printKeys as $idx => $key) { $row($key, 'pr_print', $key === 'premium'); } ?>
            </fieldset>
            <div class="pr-orderbar">
                <a href="<?= htmlspecialchars($regUrl) ?>" class="bg-blue-600 hover:bg-blue-700 text-white" data-orderbar="pr_print">
                    <span><?= htmlspecialchars(t('pricing.product_cta')) ?></span>
                    <span class="pr-orderbar__sum"><?= htmlspecialchars(t('pricing.product_premium_price')) ?></span>
                </a>
            </div>
            <p class="text-center text-sm text-gray-500 mt-4"><?= htmlspecialchars(t('pricing.products_note')) ?></p>
        </section>

        <!-- NFC cards -->
        <section class="mb-16" id="pr-panel-nfc" role="tabpanel" aria-labelledby="pr-tab-nfc">
            <fieldset class="pr-rows">
                <legend class="sr-only"><?= htmlspecialchars(t('pricing.tab_nfc')) ?></legend>
                <?php $row('nfc', 'pr_nfc', true); ?>
            </fieldset>
            <div class="pr-orderbar">
                <a href="<?= htmlspecialchars($regUrl) ?>" class="bg-blue-600 hover:bg-blue-700 text-white" data-orderbar="pr_nfc">
                    <span><?= htmlspecialchars(t('pricing.product_cta')) ?></span>
                    <span class="pr-orderbar__sum"><?= htmlspecialchars(t('pricing.product_nfc_price')) ?></span>
                </a>
            </div>
        </section>

        <script<?= function_exists('cspNonceAttr') ? cspNonceAttr() : '' ?>>
        (function () {
            var tabs = document.querySelectorAll('#pr-seg [role="tab"]');
            function show(id) {
                tabs.forEach(function (t) {
                    var on = t.id === id;
                    t.setAttribute('aria-selected', on ? 'true' : 'false');
                    t.tabIndex = on ? 0 : -1;
                    var panel = document.getElementById(t.getAttribute('aria-controls'));
                    if (panel) panel.hidden = !on;
                });
            }
            tabs.forEach(function (t, i) {
                t.addEventListener('click', function () { show(t.id); });
                t.addEventListener('keydown', function (e) {
                    var d = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
                    if (document.documentElement.dir === 'rtl') d = -d;
                    if (!d) return;
                    var n = tabs[(i + d + tabs.length) % tabs.length];
                    show(n.id); n.focus(); e.preventDefault();
                });
            });
            var start = (location.hash === '#platform' || location.hash === '#nfc') ? 'pr-tab-' + location.hash.slice(1) : 'pr-tab-prints';
            show(start);
            // Keep the order bar's price in step with the chosen row.
            document.querySelectorAll('.pr-row input').forEach(function (r) {
                r.addEventListener('change', function () {
                    var bar = document.querySelector('[data-orderbar="' + r.name + '"] .pr-orderbar__sum');
                    if (bar) bar.textContent = r.dataset.price;
                });
            });
        })();
        </script>

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
