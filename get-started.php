<?php
/**
 * Cardify, /get-started. The paid-traffic landing page.
 *
 * It was English-only by convention rather than by architecture: the hero,
 * the trust strip, the three steps, the feature grid and the closing block
 * were all hardcoded English while the instant-card demo in the middle was
 * already translated. /ar/get-started 301'd to the English URL because a
 * body-language check measured its Arabic letter share at 0.24, which is
 * chrome only. The body is translated now, so the Arabic URL serves Arabic.
 *
 * Campaign compatibility is deliberate: every inbound query parameter is
 * carried onto the signup link, so utm_source, utm_campaign, gclid and ref
 * survive the click instead of dying on this page. config.php already keys
 * its session behaviour off those same parameters.
 *
 * The design follows the home page: the same hero gradient, badge pill, type
 * scale, button weights and trust pills, so a visitor who arrives here from
 * an ad and then browses the site does not cross a visual seam.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/ArTwins.php';
require_once INCLUDES_DIR . '/CardCatalogPricing.php';

$isAr  = currentLocale() === 'ar';
$dir   = currentDir();
$base  = getBasePath();

$pageTitle       = t('getstarted.page_title');
$pageDescription = t('getstarted.page_desc');
$canonicalUrl    = 'https://cardify.om' . ($isAr ? '/ar' : '') . '/get-started';
$brandName       = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

/**
 * Forward the campaign parameters onto the signup link.
 *
 * Only the known tracking keys travel, and each is length-capped, so this
 * cannot be used to smuggle arbitrary query state into an internal URL.
 */
$campaignKeys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'msclkid', 'ref'];
$campaign = [];
foreach ($campaignKeys as $k) {
    $v = $_GET[$k] ?? '';
    if (is_string($v) && $v !== '') $campaign[$k] = substr($v, 0, 96);
}
$campaignQs  = $campaign ? ('?' . http_build_query($campaign)) : '';
$registerUrl = $base . 'company/register.php' . $campaignQs;

$waMsg = $isAr ? 'مرحباً، أرغب بعرض توضيحي لكارديفاي لشركتي' : 'Hi, I would like a demo of Cardify for my company';
$waUrl = 'https://api.whatsapp.com/send?phone=96898899100&text=' . rawurlencode($waMsg);

// The page carries its own minimal header, so the site nav stays off.
$showNavigation = false;
$bodyClass      = 'bg-white' . ($isAr ? ' font-arabic' : '');
$bodyAttributes = $isAr ? 'dir="rtl" lang="ar"' : '';
require_once INCLUDES_DIR . '/ui-header.php';

$gs = static fn(string $k): string => htmlspecialchars(t('getstarted.' . $k));
?>

<div class="min-h-screen bg-white">

    <!-- Landing chrome: logo, language, sign in. Nothing else to click away with. -->
    <div class="bg-white/90 backdrop-blur border-b border-gray-100 py-3 px-4 sticky top-0 z-30">
        <div class="max-w-6xl mx-auto flex items-center justify-between gap-4">
            <a href="<?= $base . ($isAr ? 'ar/' : '') ?>" class="flex items-center gap-2 shrink-0">
                <img src="<?= assetUrl('images/logo.svg') ?>" alt="<?= htmlspecialchars($brandName) ?>" class="h-8 w-auto" width="120" height="32">
            </a>
            <div class="flex items-center gap-4 text-sm">
                <a href="<?= htmlspecialchars(($isAr ? '/get-started' : '/ar/get-started') . $campaignQs) ?>"
                   class="font-semibold text-gray-600 hover:text-gray-900" hreflang="<?= $isAr ? 'en' : 'ar' ?>">
                    <?= $isAr ? 'English' : 'العربية' ?>
                </a>
                <span class="hidden sm:inline text-gray-400"><?= $gs('hero_signin') ?></span>
                <a href="<?= $base ?>login.php" class="font-semibold text-blue-700 hover:text-blue-800"><?= $gs('hero_signin_cta') ?></a>
            </div>
        </div>
    </div>

    <!-- Hero -->
    <section class="hero-gradient pt-14 lg:pt-20 pb-14 lg:pb-20 overflow-hidden">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid lg:grid-cols-12 gap-10 lg:gap-12 items-center">

                <div class="lg:col-span-7 text-center lg:text-start">
                    <div class="inline-flex items-center gap-2 py-1 ps-1 pe-4 mb-6 text-sm bg-white border border-gray-200 rounded-full shadow-sm">
                        <span class="inline-flex items-center gap-1 bg-blue-50 text-blue-700 font-semibold text-xs px-3 py-1 rounded-full"><span aria-hidden="true">🇴🇲</span> <?= $gs('hero_badge_loc') ?></span>
                        <span class="font-medium text-gray-700"><?= $gs('hero_badge_copy') ?></span>
                    </div>

                    <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold tracking-tight leading-tight text-gray-900 mb-6">
                        <?= $gs('hero_h1') ?>
                        <span class="text-blue-600 block"><?= $gs('hero_h1_accent') ?></span>
                    </h1>

                    <p class="text-lg lg:text-xl text-gray-600 mb-8 max-w-2xl mx-auto lg:mx-0 leading-relaxed">
                        <?= $gs('hero_sub') ?>
                    </p>

                    <div class="flex flex-col sm:flex-row gap-4 justify-center lg:justify-start mb-8">
                        <a href="#instant-demo"
                           class="inline-flex items-center justify-center gap-2 px-7 py-4 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl shadow-lg shadow-blue-600/30 transition-all hover:shadow-xl hover:-translate-y-0.5 text-lg">
                            <?= $gs('hero_cta_try') ?>
                            <i class="fa-solid fa-arrow-down" aria-hidden="true"></i>
                        </a>
                        <a href="<?= htmlspecialchars($registerUrl) ?>"
                           class="inline-flex items-center justify-center gap-2 px-7 py-4 bg-white border-2 border-gray-200 hover:border-gray-300 text-gray-800 font-semibold rounded-xl transition-all text-lg">
                            <?= $gs('hero_cta_signup') ?>
                        </a>
                    </div>

                    <ul class="flex flex-wrap items-center justify-center lg:justify-start gap-3 text-sm" role="list">
                        <li class="flex items-center gap-2 px-3 py-1.5 bg-green-50 text-green-700 rounded-full">
                            <i class="fa-solid fa-circle-check" aria-hidden="true"></i><span><?= $gs('trust_free') ?></span>
                        </li>
                        <li class="flex items-center gap-2 px-3 py-1.5 bg-blue-50 text-blue-700 rounded-full">
                            <i class="fa-solid fa-lock" aria-hidden="true"></i><span><?= $gs('trust_no_card') ?></span>
                        </li>
                        <li class="flex items-center gap-2 px-3 py-1.5 bg-amber-50 text-amber-700 rounded-full">
                            <i class="fa-solid fa-language" aria-hidden="true"></i><span><?= $gs('trust_bilingual') ?></span>
                        </li>
                        <li class="flex items-center gap-2 px-3 py-1.5 bg-purple-50 text-purple-700 rounded-full">
                            <i class="fa-solid fa-print" aria-hidden="true"></i><span><?= $gs('trust_printed') ?></span>
                        </li>
                    </ul>
                </div>

                <?php
                /* The product object, flat. It is the same bilingual sample the
                   home page hero carries, without the three.js layer: this page
                   has one job and a rotating canvas is not it. Labelled a
                   sample, because it is not a customer. */
                ?>
                <div class="lg:col-span-5 mt-4 lg:mt-0">
                    <div class="mx-auto w-full max-w-sm">
                        <div class="rounded-2xl shadow-2xl shadow-blue-900/20 overflow-hidden" style="background:linear-gradient(150deg,#067a98,#053b49)" role="img" aria-label="<?= htmlspecialchars(t('herocard.alt')) ?>">
                            <div class="flex items-start justify-between px-5 pt-4 pb-2">
                                <p class="text-[11px] font-bold tracking-[0.16em] uppercase text-white">Cardify</p>
                                <span class="text-[10px] font-bold tracking-widest text-white rounded-full px-2 py-0.5" style="background:rgba(255,255,255,.22)"><?= $gs('sample_label') ?></span>
                            </div>
                            <div class="px-5 pb-3 grid grid-cols-2 gap-3 items-start">
                                <div dir="ltr" class="text-left">
                                    <p class="font-display font-bold text-white text-base sm:text-lg leading-tight">Aisha Al Balushi</p>
                                    <p class="text-xs mt-1" style="color:rgba(255,255,255,.92)">Operations Manager</p>
                                </div>
                                <div dir="rtl" class="text-right">
                                    <p class="font-display font-bold text-white text-base sm:text-lg leading-tight">عائشة البلوشي</p>
                                    <p class="text-xs mt-1" style="color:rgba(255,255,255,.92)">مديرة العمليات</p>
                                </div>
                            </div>
                            <div class="px-5 pb-3 flex items-end justify-between gap-3">
                                <div class="space-y-1 text-xs" dir="ltr" style="color:rgba(255,255,255,.92)">
                                    <p>aisha@example.om</p>
                                    <p>+968 2200 0000</p>
                                </div>
                                <div class="shrink-0 w-12 h-12 rounded-lg flex items-center justify-center" aria-hidden="true" style="background:rgba(255,255,255,.96)">
                                    <i class="fa-solid fa-qrcode text-2xl" style="color:#053b49"></i>
                                </div>
                            </div>
                            <div class="px-5 py-2 flex items-center gap-2" style="background:rgba(0,0,0,.18)">
                                <i class="fa-brands fa-apple" aria-hidden="true" style="color:rgba(255,255,255,.95)"></i>
                                <i class="fa-brands fa-google" aria-hidden="true" style="color:rgba(255,255,255,.95)"></i>
                                <p class="text-xs" style="color:rgba(255,255,255,.92)"><?= htmlspecialchars(t('herocard.wallet')) ?></p>
                            </div>
                        </div>
                        <p class="mt-3 text-xs text-gray-500 text-center"><?= $gs('sample_note') ?></p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ===================== INSTANT CARD DEMO =====================
         Paid traffic used to land on a hero and a signup button with nothing
         to try, while instant_card.php sat there as a complete, working
         endpoint that no page in the codebase called. It mints a REAL card
         under the `demo` tenant and emails a verify link, which is the one
         thing a landing page for this product should let you do before you
         commit to an account. -->
    <section id="instant-demo" class="bg-white py-16 px-4 border-b border-gray-100 scroll-mt-20" aria-labelledby="instant-demo-h2">
        <div class="max-w-3xl mx-auto">
            <div class="text-center mb-8">
                <span class="inline-flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-blue-700 bg-blue-50 rounded-full px-3 py-1 mb-3">
                    <i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i>
                    <?= htmlspecialchars(t('getstarted.demo_eyebrow')) ?>
                </span>
                <h2 id="instant-demo-h2" class="text-3xl sm:text-4xl font-bold text-gray-900 mb-3"><?= htmlspecialchars(t('getstarted.demo_h2')) ?></h2>
                <p class="text-gray-600 max-w-xl mx-auto"><?= htmlspecialchars(t('getstarted.demo_sub')) ?></p>
            </div>

            <form id="instant-form" class="bg-gray-50 rounded-3xl border border-gray-200 p-6 sm:p-8" novalidate>
                <div id="instant-error" class="hidden mb-5 rounded-xl bg-red-50 border border-red-200 text-red-800 text-sm px-4 py-3" role="alert"></div>

                <div class="grid sm:grid-cols-2 gap-4">
                    <div>
                        <label for="ic-name" class="block text-sm font-semibold text-gray-700 mb-1"><?= htmlspecialchars(t('getstarted.demo_name')) ?></label>
                        <input id="ic-name" name="name" type="text" required autocomplete="name" maxlength="120"
                               placeholder="<?= htmlspecialchars(t('getstarted.demo_name_ph')) ?>"
                               class="w-full rounded-xl border border-gray-300 px-4 py-3 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label for="ic-title" class="block text-sm font-semibold text-gray-700 mb-1"><?= htmlspecialchars(t('getstarted.demo_title')) ?></label>
                        <input id="ic-title" name="title" type="text" autocomplete="organization-title" maxlength="120"
                               placeholder="<?= htmlspecialchars(t('getstarted.demo_title_ph')) ?>"
                               class="w-full rounded-xl border border-gray-300 px-4 py-3 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label for="ic-company" class="block text-sm font-semibold text-gray-700 mb-1"><?= htmlspecialchars(t('getstarted.demo_company')) ?></label>
                        <input id="ic-company" name="company" type="text" autocomplete="organization" maxlength="120"
                               placeholder="<?= htmlspecialchars(t('getstarted.demo_company_ph')) ?>"
                               class="w-full rounded-xl border border-gray-300 px-4 py-3 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label for="ic-email" class="block text-sm font-semibold text-gray-700 mb-1"><?= htmlspecialchars(t('getstarted.demo_email')) ?></label>
                        <input id="ic-email" name="email" type="email" required autocomplete="email" maxlength="190"
                               placeholder="<?= htmlspecialchars(t('getstarted.demo_email_ph')) ?>"
                               class="w-full rounded-xl border border-gray-300 px-4 py-3 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                </div>

                <div class="mt-5 flex items-center gap-3">
                    <label for="ic-color" class="text-sm font-semibold text-gray-700"><?= htmlspecialchars(t('getstarted.demo_color')) ?></label>
                    <input id="ic-color" name="color" type="color" value="#2d13ea"
                           class="h-10 w-16 rounded-lg border border-gray-300 cursor-pointer p-1">
                </div>

                <button id="ic-submit" type="submit"
                        class="mt-6 w-full bg-blue-700 text-white font-bold px-8 py-4 rounded-xl text-lg hover:bg-blue-800 transition-all disabled:opacity-60 disabled:cursor-not-allowed">
                    <?= htmlspecialchars(t('getstarted.demo_submit')) ?>
                </button>
                <p class="mt-3 text-xs text-gray-500 text-center"><?= htmlspecialchars(t('getstarted.demo_privacy')) ?></p>
            </form>

            <!-- Success state, swapped in place of the form. -->
            <div id="instant-done" class="hidden bg-white rounded-3xl border-2 border-green-200 p-8 text-center" role="status">
                <div class="w-14 h-14 rounded-full bg-green-50 flex items-center justify-center mx-auto mb-4">
                    <i class="fa-solid fa-circle-check text-2xl text-green-600" aria-hidden="true"></i>
                </div>
                <h3 class="text-2xl font-bold text-gray-900 mb-2"><?= htmlspecialchars(t('getstarted.demo_done_h3')) ?></h3>
                <p class="text-gray-600 mb-6"><?= htmlspecialchars(t('getstarted.demo_done_sub')) ?></p>
                <div class="flex flex-col sm:flex-row gap-3 justify-center">
                    <a id="ic-card-url" href="#" target="_blank" rel="noopener"
                       class="bg-blue-700 text-white font-bold px-8 py-3 rounded-xl hover:bg-blue-800 transition-all">
                        <?= htmlspecialchars(t('getstarted.demo_view')) ?>
                    </a>
                    <a href="<?= getBasePath() ?>company/register.php"
                       class="border-2 border-gray-300 text-gray-700 font-semibold px-8 py-3 rounded-xl hover:bg-gray-50 transition-all">
                        <?= htmlspecialchars(t('getstarted.demo_signup')) ?>
                    </a>
                </div>
            </div>
        </div>
    </section>


    <!-- What it costs. Every amount comes from CardCatalogPricing. -->
    <section class="bg-white py-16 px-4 border-b border-gray-100" aria-labelledby="gs-price-h2">
        <div class="max-w-5xl mx-auto">
            <p class="text-center text-sm font-semibold uppercase tracking-wider text-blue-700 mb-2"><?= $gs('price_eyebrow') ?></p>
            <h2 id="gs-price-h2" class="text-3xl sm:text-4xl font-bold text-center text-gray-900 mb-10"><?= $gs('close_b') ?></h2>
            <div class="grid sm:grid-cols-3 gap-5">
                <?php
                /* Full class strings, never built from a variable: the compiled
                   Tailwind in assets/techwind holds only the utilities it was
                   built with, and the page this replaced asked for bg-teal-100
                   and bg-red-100, neither of which is in that file, so two of
                   its six feature icons rendered on no background at all. */
                foreach ([
                    ['free',  'fa-infinity', 'bg-green-50 text-green-600'],
                    ['print', 'fa-print',    'bg-blue-50 text-blue-600'],
                    ['nfc',   'fa-wifi',     'bg-purple-50 text-purple-600'],
                ] as [$k, $icon, $accent]): ?>
                <div class="rounded-2xl border border-gray-200 p-6 flex flex-col">
                    <div class="w-11 h-11 rounded-xl <?= $accent ?> flex items-center justify-center mb-4">
                        <i class="fa-solid <?= $icon ?> text-lg" aria-hidden="true"></i>
                    </div>
                    <h3 class="font-bold text-gray-900 mb-1"><?= $gs('price_' . $k . '_h') ?></h3>
                    <p class="text-2xl font-extrabold text-gray-900"><?= $gs('price_' . $k . '_v') ?></p>
                    <?php if ($k !== 'free'): ?>
                        <p class="text-xs text-gray-500 mb-3"><?= $gs('price_' . $k . '_u') ?></p>
                    <?php else: ?>
                        <p class="text-xs text-gray-500 mb-3">&nbsp;</p>
                    <?php endif; ?>
                    <p class="text-sm text-gray-600 leading-relaxed"><?= $gs('price_' . $k . '_b') ?></p>
                </div>
                <?php endforeach; ?>
            </div>
            <p class="text-center mt-6">
                <a href="<?= $base . ($isAr ? 'ar/' : '') ?>pricing" class="text-blue-700 font-semibold hover:underline"><?= $gs('price_link') ?></a>
            </p>
        </div>
    </section>

    <!-- How it works -->
    <section class="bg-gray-50 py-16 px-4" aria-labelledby="gs-how-h2">
        <div class="max-w-5xl mx-auto">
            <p class="text-center text-sm font-semibold uppercase tracking-wider text-blue-700 mb-2"><?= $gs('how_eyebrow') ?></p>
            <h2 id="gs-how-h2" class="text-3xl sm:text-4xl font-bold text-center text-gray-900 mb-3"><?= $gs('how_h2') ?></h2>
            <p class="text-center text-gray-600 mb-12 max-w-2xl mx-auto"><?= $gs('how_sub') ?></p>
            <ol class="grid md:grid-cols-3 gap-8" role="list">
                <?php foreach ([1, 2, 3] as $n): ?>
                <li class="text-center">
                    <div class="w-14 h-14 bg-white border border-gray-200 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-sm">
                        <span class="text-xl font-extrabold text-blue-600"><?= $n ?></span>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2"><?= $gs('how_' . $n . '_h') ?></h3>
                    <p class="text-gray-600 leading-relaxed"><?= $gs('how_' . $n . '_b') ?></p>
                </li>
                <?php endforeach; ?>
            </ol>
        </div>
    </section>

    <!-- What you get -->
    <section class="bg-white py-16 px-4" aria-labelledby="gs-feat-h2">
        <div class="max-w-5xl mx-auto">
            <p class="text-center text-sm font-semibold uppercase tracking-wider text-blue-700 mb-2"><?= $gs('feat_eyebrow') ?></p>
            <h2 id="gs-feat-h2" class="text-3xl sm:text-4xl font-bold text-center text-gray-900 mb-12"><?= $gs('feat_h2') ?></h2>
            <div class="grid md:grid-cols-2 gap-5">
                <?php foreach ([
                    [1, 'fa-language',      'bg-blue-50',   'text-blue-600'],
                    [2, 'fa-layer-group',   'bg-purple-50', 'text-purple-600'],
                    [3, 'fa-qrcode',        'bg-amber-50',  'text-amber-600'],
                    [4, 'fa-mobile-screen', 'bg-orange-50', 'text-orange-600'],
                    [5, 'fa-user-pen',      'bg-green-50',  'text-green-600'],
                    [6, 'fa-print',         'bg-red-50',    'text-red-600'],
                ] as [$n, $icon, $bg, $fg]): ?>
                <div class="rounded-2xl border border-gray-200 p-6 flex gap-4">
                    <div class="w-12 h-12 <?= $bg ?> rounded-xl flex items-center justify-center flex-shrink-0">
                        <i class="fa-solid <?= $icon ?> <?= $fg ?> text-lg" aria-hidden="true"></i>
                    </div>
                    <div class="min-w-0">
                        <h3 class="font-bold text-gray-900 mb-1"><?= $gs('feat_' . $n . '_h') ?></h3>
                        <p class="text-gray-600 text-sm leading-relaxed"><?= $gs('feat_' . $n . '_b') ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Closing -->
    <section class="bg-gray-50 py-16 px-4 border-t border-gray-100" aria-labelledby="gs-close-h2">
        <div class="max-w-3xl mx-auto text-center">
            <h2 id="gs-close-h2" class="text-3xl sm:text-4xl font-bold text-gray-900 mb-4"><?= $gs('close_h2') ?></h2>
            <p class="text-lg text-gray-600 mb-8"><?= $gs('close_b') ?></p>
            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                <a href="<?= htmlspecialchars($registerUrl) ?>"
                   class="inline-flex items-center justify-center gap-2 px-8 py-4 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-xl shadow-lg shadow-blue-600/30 transition-all hover:shadow-xl hover:-translate-y-0.5 text-lg">
                    <?= $gs('close_cta') ?>
                    <i class="fa-solid <?= $isAr ? 'fa-arrow-left' : 'fa-arrow-right' ?>" aria-hidden="true"></i>
                </a>
                <a href="<?= htmlspecialchars($waUrl) ?>" target="_blank" rel="noopener"
                   class="inline-flex items-center justify-center gap-2 px-8 py-4 bg-green-500 hover:bg-green-600 text-white font-semibold rounded-xl transition-all text-lg">
                    <i class="fa-brands fa-whatsapp" aria-hidden="true"></i>
                    <?= $gs('close_demo') ?>
                </a>
            </div>
            <p class="mt-6 text-gray-500 text-sm">
                <?= str_replace(
                    [':faq', ':contact'],
                    [
                        '<a href="' . htmlspecialchars($base . ($isAr ? 'ar/' : '') . 'faq') . '" class="text-blue-700 hover:underline">' . $gs('close_faq') . '</a>',
                        '<a href="' . htmlspecialchars($base . ($isAr ? 'ar/' : '') . 'contact') . '" class="text-blue-700 hover:underline">' . $gs('close_contact') . '</a>',
                    ],
                    htmlspecialchars(t('getstarted.close_help'))
                ) ?>
            </p>
        </div>
    </section>
</div>

<script<?= cspNonceAttr() ?>>
/* Instant-card demo. Posts to instant_card.php, which enforces its own
   same-origin check, rate limits and email validation, so this side stays
   thin: collect, post, swap in the result or say why not. */
(function () {
  var form = document.getElementById('instant-form');
  if (!form) return;

  var btn     = document.getElementById('ic-submit');
  var errBox  = document.getElementById('instant-error');
  var done    = document.getElementById('instant-done');
  var cardUrl = document.getElementById('ic-card-url');

  /* The endpoint answers with slugs, not sentences, so the copy lives in the
     lang files on both sides and is chosen here. An unrecognised slug falls
     back to the generic line rather than printing the slug at the user. */
  var MSG = <?= json_encode([
      'invalid_email' => t('getstarted.demo_err_invalid_email'),
      'bad_domain'    => t('getstarted.demo_err_bad_domain'),
      'slug_taken'    => t('getstarted.demo_err_slug_taken'),
      'rate_ip'       => t('getstarted.demo_err_rate'),
      'rate_email'    => t('getstarted.demo_err_rate'),
      'busy'          => t('getstarted.demo_err_busy'),
      'generic'       => t('getstarted.demo_err_generic'),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  var LABEL_IDLE     = <?= json_encode(t('getstarted.demo_submit'), JSON_UNESCAPED_UNICODE) ?>;
  var LABEL_BUILDING = <?= json_encode(t('getstarted.demo_building'), JSON_UNESCAPED_UNICODE) ?>;
  var ENDPOINT       = <?= json_encode(getBasePath() . 'instant_card.php', JSON_UNESCAPED_SLASHES) ?>;
  var LANG           = <?= json_encode(function_exists('currentLocale') ? currentLocale() : 'en') ?>;

  function showError(slug) {
    errBox.textContent = MSG[slug] || MSG.generic;
    errBox.classList.remove('hidden');
    errBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    errBox.classList.add('hidden');

    var email = document.getElementById('ic-email').value.trim();
    var name  = document.getElementById('ic-name').value.trim();
    if (!name || !email) { showError('invalid_email'); return; }

    btn.disabled = true;
    btn.textContent = LABEL_BUILDING;

    var body = new FormData();
    body.append('name',    name);
    body.append('title',   document.getElementById('ic-title').value.trim());
    body.append('company', document.getElementById('ic-company').value.trim());
    body.append('email',   email);
    body.append('color',   document.getElementById('ic-color').value);
    body.append('lang',    LANG);

    fetch(ENDPOINT, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'generic' }; }); })
      .then(function (d) {
        if (!d || !d.ok) { showError((d && d.error) || 'generic'); return; }
        /* Only ever point at the URL the server minted. */
        if (d.cardUrl) { cardUrl.href = d.cardUrl; } else { cardUrl.classList.add('hidden'); }
        form.classList.add('hidden');
        done.classList.remove('hidden');
        done.scrollIntoView({ behavior: 'smooth', block: 'center' });
      })
      .catch(function () { showError('generic'); })
      .finally(function () {
        btn.disabled = false;
        btn.textContent = LABEL_IDLE;
      });
  });
})();
</script>


<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
