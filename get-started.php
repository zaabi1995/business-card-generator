<?php
/**
 * Cardify - Get Started Landing Page (Google Ads / Paid Traffic)
 * Optimized for conversions with minimal distractions
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = 'Get Started, Free Business Cards for Your Omani Company';
$pageDescription = 'Create professional digital and printed business cards for your team in minutes. Free forever for unlimited employees. Only pay when you order physical prints.';
$canonicalUrl = 'https://cardify.om/get-started';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

$showNavigation = false; // Minimal nav for landing page
require_once INCLUDES_DIR . '/ui-header.php';
?>

<div class="min-h-screen bg-white">
    <!-- Minimal Header -->
    <div class="bg-white border-b border-gray-100 py-4 px-4">
        <div class="max-w-5xl mx-auto flex items-center justify-between">
            <a href="<?= getBasePath() ?>" class="flex items-center gap-2">
                <img src="<?= getBasePath() ?>assets/images/logo.svg" alt="Cardify" class="h-8 w-auto">
            </a>
            <a href="<?= getBasePath() ?>login.php" class="text-sm text-gray-500 hover:text-gray-700">Already have an account? Sign in</a>
        </div>
    </div>

    <!-- Hero Section -->
    <div class="bg-gradient-to-br from-blue-600 via-blue-700 to-blue-900 text-white py-20 px-4">
        <div class="max-w-5xl mx-auto text-center">
            <div class="inline-flex items-center gap-2 bg-blue-500/30 rounded-full px-4 py-1.5 mb-6 text-sm">
                <i class="fa-solid fa-star text-yellow-300"></i>
                <span>Start free, no credit card needed</span>
            </div>
            <h1 class="text-4xl md:text-5xl lg:text-6xl font-bold mb-6 leading-tight">
                Professional Business Cards<br>
                <span class="text-blue-200">in Minutes, Not Days</span>
            </h1>
            <p class="text-xl text-blue-100 max-w-2xl mx-auto mb-10">
                Create stunning digital and printed business cards for your entire team.
                Free forever for unlimited employees. No design skills needed.
            </p>
            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                <a href="<?= getBasePath() ?>company/register.php"
                   class="bg-white text-blue-700 font-bold px-10 py-4 rounded-xl text-lg hover:bg-blue-50 transition-all shadow-lg hover:shadow-xl">
                    Create Free Account
                    <i class="fa-solid fa-arrow-right ml-2"></i>
                </a>
                <a href="<?= getBasePath() ?>intro"
                   class="border-2 border-white/30 text-white font-semibold px-10 py-4 rounded-xl text-lg hover:bg-white/10 transition-all">
                    See How It Works
                </a>
            </div>
            <p class="mt-4 text-blue-200 text-sm">No credit card required. Setup in 2 minutes.</p>
        </div>
    </div>

    <!-- ===================== INSTANT CARD DEMO =====================
         Paid traffic used to land on a hero and a signup button with nothing
         to try, while instant_card.php sat there as a complete, working
         endpoint that no page in the codebase called. It mints a REAL card
         under the `demo` tenant and emails a verify link, which is the one
         thing a landing page for this product should let you do before you
         commit to an account. -->
    <section id="instant-demo" class="bg-white py-16 px-4 border-b border-gray-100" aria-labelledby="instant-demo-h2">
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

    <!-- Trust strip -->
    <div class="bg-gray-50 py-8 px-4 border-b border-gray-100">
        <div class="max-w-5xl mx-auto flex flex-wrap items-center justify-center gap-8 md:gap-16 text-center text-gray-700">
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 1H21l-3 6 3 6h-8.5l-1-1H5a2 2 0 00-2 2zm9-13.5V9"/></svg>
                <span class="font-semibold">Built for Oman</span>
            </div>
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                <span class="font-semibold">Paymob verified</span>
            </div>
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                <span class="font-semibold">Free forever</span>
            </div>
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                <span class="font-semibold">Print partners ready</span>
            </div>
        </div>
    </div>

    <!-- How It Works -->
    <div class="py-20 px-4">
        <div class="max-w-5xl mx-auto">
            <h2 class="text-3xl font-bold text-center text-gray-900 mb-4">How It Works</h2>
            <p class="text-center text-gray-600 mb-12 max-w-2xl mx-auto">Three simple steps to professional business cards for your entire team.</p>

            <div class="grid md:grid-cols-3 gap-8">
                <div class="text-center">
                    <div class="w-16 h-16 bg-blue-100 rounded-2xl flex items-center justify-center mx-auto mb-4">
                        <span class="text-2xl font-bold text-blue-600">1</span>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-2">Sign Up Free</h3>
                    <p class="text-gray-600">Create your company account in seconds. No credit card needed. Free forever for any team size.</p>
                </div>
                <div class="text-center">
                    <div class="w-16 h-16 bg-blue-100 rounded-2xl flex items-center justify-center mx-auto mb-4">
                        <span class="text-2xl font-bold text-blue-600">2</span>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-2">Design Your Cards</h3>
                    <p class="text-gray-600">Choose a template, upload your logo, add employee details. Our drag-and-drop editor makes it easy.</p>
                </div>
                <div class="text-center">
                    <div class="w-16 h-16 bg-blue-100 rounded-2xl flex items-center justify-center mx-auto mb-4">
                        <span class="text-2xl font-bold text-blue-600">3</span>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-2">Share or Print</h3>
                    <p class="text-gray-600">Share digital cards instantly via QR code or WhatsApp. Order prints from local Omani print shops.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Features Grid -->
    <div class="bg-gray-50 py-20 px-4">
        <div class="max-w-5xl mx-auto">
            <h2 class="text-3xl font-bold text-center text-gray-900 mb-12">Everything You Need</h2>

            <div class="grid md:grid-cols-2 gap-6">
                <div class="bg-white rounded-xl p-6 flex gap-4">
                    <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0">
                        <i class="fa-solid fa-mobile-screen text-blue-600 text-xl"></i>
                    </div>
                    <div>
                        <h3 class="font-bold text-gray-900 mb-1">Digital Cards</h3>
                        <p class="text-gray-600 text-sm">Interactive digital cards with QR codes, NFC sharing, and click-to-call buttons.</p>
                    </div>
                </div>
                <div class="bg-white rounded-xl p-6 flex gap-4">
                    <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center flex-shrink-0">
                        <i class="fa-solid fa-print text-green-600 text-xl"></i>
                    </div>
                    <div>
                        <h3 class="font-bold text-gray-900 mb-1">Print Orders</h3>
                        <p class="text-gray-600 text-sm">Order premium printed cards from verified print shops across Oman. Delivered to your door.</p>
                    </div>
                </div>
                <div class="bg-white rounded-xl p-6 flex gap-4">
                    <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center flex-shrink-0">
                        <i class="fa-solid fa-users text-purple-600 text-xl"></i>
                    </div>
                    <div>
                        <h3 class="font-bold text-gray-900 mb-1">Team Management</h3>
                        <p class="text-gray-600 text-sm">Bulk generate cards for your entire team. Consistent branding across all employees.</p>
                    </div>
                </div>
                <div class="bg-white rounded-xl p-6 flex gap-4">
                    <div class="w-12 h-12 bg-amber-100 rounded-lg flex items-center justify-center flex-shrink-0">
                        <i class="fa-solid fa-qrcode text-amber-600 text-xl"></i>
                    </div>
                    <div>
                        <h3 class="font-bold text-gray-900 mb-1">QR Code Sharing</h3>
                        <p class="text-gray-600 text-sm">Every card gets a unique QR code. Scan to save contacts instantly. Track scan analytics.</p>
                    </div>
                </div>
                <div class="bg-white rounded-xl p-6 flex gap-4">
                    <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center flex-shrink-0">
                        <i class="fa-brands fa-whatsapp text-red-600 text-xl"></i>
                    </div>
                    <div>
                        <h3 class="font-bold text-gray-900 mb-1">WhatsApp Sharing</h3>
                        <p class="text-gray-600 text-sm">Share your digital card via WhatsApp with one tap. Perfect for Oman's WhatsApp-first market.</p>
                    </div>
                </div>
                <div class="bg-white rounded-xl p-6 flex gap-4">
                    <div class="w-12 h-12 bg-teal-100 rounded-lg flex items-center justify-center flex-shrink-0">
                        <i class="fa-solid fa-language text-teal-600 text-xl"></i>
                    </div>
                    <div>
                        <h3 class="font-bold text-gray-900 mb-1">Bilingual Support</h3>
                        <p class="text-gray-600 text-sm">Create cards in English and Arabic. Full RTL support for Arabic text and layouts.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- CTA Section -->
    <div class="py-20 px-4">
        <div class="max-w-3xl mx-auto text-center">
            <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-4">
                Start Creating Professional Business Cards Today
            </h2>
            <p class="text-lg text-gray-600 mb-8">
                Free forever for unlimited employees. No credit card required. Takes 2 minutes to set up.
            </p>
            <a href="<?= getBasePath() ?>company/register.php"
               class="inline-block bg-blue-600 text-white font-bold px-12 py-4 rounded-xl text-lg hover:bg-blue-700 transition-all shadow-lg hover:shadow-xl">
                Create Free Account
                <i class="fa-solid fa-arrow-right ml-2"></i>
            </a>
            <p class="mt-6 text-gray-500 text-sm">
                Questions? <a href="<?= getBasePath() ?>contact" class="text-blue-600 hover:underline">Contact us</a>
                or read our <a href="<?= getBasePath() ?>faq" class="text-blue-600 hover:underline">FAQ</a>
            </p>
        </div>
    </div>
</div>

<script>
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
