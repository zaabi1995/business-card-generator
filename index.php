<?php
/**
 * Cardify - Business Cards Made Simple
 * SaaS Landing Page
 */

// Helper function to get base path (before config.php loads)
function getBasePathForRedirect() {
    $scriptPath = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '/index.php';
    $scriptPath = str_replace('\\', '/', $scriptPath);
    $scriptDir = dirname($scriptPath);
    
    if ($scriptDir === '/' || $scriptDir === '.' || $scriptDir === '') {
        return '/';
    }
    
    $basePath = rtrim($scriptDir, '/') . '/';
    if ($basePath[0] !== '/') {
        $basePath = '/' . $basePath;
    }
    return $basePath;
}

// Check if installation is needed
$configFile = __DIR__ . '/config.php';
$installDir = __DIR__ . '/install';

// If config.php doesn't exist, redirect to installer
if (!file_exists($configFile)) {
    if (is_dir($installDir)) {
        header('Location: ' . getBasePathForRedirect() . 'install/');
        exit;
    } else {
        die('Configuration file not found. Please run the installation wizard.');
    }
}

require_once $configFile;

// Tenant subdomain check (e.g. ohb.cardify.om).
// Convention across all tenants:
//   <slug>.cardify.om/        -> portal.php  (employee Self-Service request form)
//   <slug>.cardify.om/login   -> tenant_login.php (admin OTP sign-in)
//   <slug>.cardify.om/admin/  -> admin dashboard (post-login)
//   <slug>.cardify.om/<email-localpart>  -> digital_card.php (printed card target)
//   <slug>.cardify.om/card/<id>          -> digital_card.php (legacy URL pattern)
if (file_exists(__DIR__ . '/includes/TenantHost.php')) {
    require_once __DIR__ . '/includes/TenantHost.php';
    if (TenantHost::isTenantHost()) {
        $reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if ($reqPath === '/login' || $reqPath === '/login/') {
            require __DIR__ . '/tenant_login.php';
        } else {
            // Default: employee request portal. A bare single-token path that
            // is NOT the canonical "/" or "/portal" reaches here only as a
            // soft-404 fallback (mistyped/old card link, scanner probing
            // /wp-admin, etc.). The portal still renders so a real visitor can
            // request a card, but tell search engines NOT to index these
            // arbitrary URLs, else Google indexes infinite junk paths per
            // tenant as soft-404s. (BHD loop audit iter 13, 3 Jun 2026.)
            $canonicalPortal = ($reqPath === '/' || $reqPath === ''
                || $reqPath === '/portal' || $reqPath === '/portal/'
                || preg_match('~^/portal/[a-z0-9-]+/?$~i', $reqPath));
            if (!$canonicalPortal && !headers_sent()) {
                header('X-Robots-Tag: noindex, nofollow', true);
            }
            require __DIR__ . '/portal.php';
        }
        exit;
    }
}

// Custom Domain check, if the Host header maps to a verified custom domain,
// this serves the linked employee card and exits. Otherwise returns and the
// normal landing-page flow continues unchanged.
if (file_exists(__DIR__ . '/custom_domain_router.php')) {
    require __DIR__ . '/custom_domain_router.php';
}

// Check if database is configured and installation is complete
$needsInstallation = false;

if (!defined('DB_HOST') || empty(DB_HOST) || !defined('DB_NAME') || empty(DB_NAME)) {
    $needsInstallation = true;
} else {
    try {
        if (class_exists('Database')) {
            $db = Database::getInstance();
            if (!$db->isConnected()) {
                if (defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER')) {
                    $connected = $db->connect(DB_HOST, DB_NAME, DB_USER, DB_PASS ?? '', DB_PORT ?? '3306', DB_TYPE ?? 'mysql');
                    if (!$connected) {
                        $needsInstallation = true;
                    }
                } else {
                    $needsInstallation = true;
                }
            }
            
            if ($db->isConnected()) {
                try {
                    $tables = $db->fetchAll("SHOW TABLES LIKE 'system_settings'");
                    if (empty($tables)) {
                        $needsInstallation = true;
                    } else {
                        try {
                            $setting = $db->fetchOne("SELECT setting_value FROM system_settings WHERE setting_key = 'installation_complete'");
                            
                            if ($setting !== false && !empty($setting) && isset($setting['setting_value'])) {
                                $value = trim((string)$setting['setting_value']);
                                if ($value === '1' || $value === 'true' || $value === 'yes' || $value === 'TRUE') {
                                    $needsInstallation = false;
                                } else {
                                    $needsInstallation = true;
                                }
                            } else {
                                try {
                                    $companiesCount = $db->fetchOne("SELECT COUNT(*) as count FROM companies");
                                    if ($companiesCount && ($companiesCount['count'] ?? 0) > 0) {
                                        $uuid = generateUUID();
                                        try {
                                            $db->insert('system_settings', [
                                                'id' => $uuid,
                                                'setting_key' => 'installation_complete',
                                                'setting_value' => '1',
                                                'description' => 'Whether installation has been completed'
                                            ]);
                                            $needsInstallation = false;
                                        } catch (Exception $insertError) {
                                            try {
                                                $db->update('system_settings',
                                                    ['setting_value' => '1'],
                                                    'setting_key = :key',
                                                    ['key' => 'installation_complete']
                                                );
                                                $needsInstallation = false;
                                            } catch (Exception $updateError) {
                                                $needsInstallation = true;
                                            }
                                        }
                                    } else {
                                        $needsInstallation = true;
                                    }
                                } catch (Exception $e) {
                                    $needsInstallation = true;
                                }
                            }
                        } catch (Exception $e) {
                            $needsInstallation = true;
                        }
                    }
                } catch (Exception $e) {
                    $needsInstallation = true;
                }
            }
        } else {
            $needsInstallation = true;
        }
    } catch (Exception $e) {
        $needsInstallation = true;
    }
}

// Redirect to installer if needed
if ($needsInstallation && is_dir($installDir)) {
    if (!headers_sent()) {
        $basePath = function_exists('getBasePath') ? getBasePath() : getBasePathForRedirect();
        header('Location: ' . $basePath . 'install/');
        exit;
    }
}

// Suppress permission warnings during initialization
error_reporting(E_ALL & ~E_WARNING);
@initializeDataFiles();
error_reporting(E_ALL);

// Check if this is a company-specific route
if (isset($_GET['company_slug'])) {
    require __DIR__ . '/router.php';
    exit;
}

// Brand name
$brandName = 'Cardify';
$tagline = 'Business Cards Made Simple';
$pageTitle = 'Cardify, Digital & Printed Business Cards for the GCC';
$pageDescription = 'Bilingual Arabic/English digital and printed business cards for teams across the Gulf: Oman (live), Saudi Arabia, UAE, Qatar, Bahrain, and Kuwait (rolling out 2026). QR vCard save, Apple Wallet, NFC, bulk provisioning. Free to start.';
// Self-canonicalize per locale (the AR home previously canonicalized to the EN
// home, so Google never indexed it) + emit a full bilingual hreflang set
// (ui-header's default only advertises en + x-default, never ar).
$canonicalUrl = (function_exists('currentLocale') && currentLocale() === 'ar')
    ? 'https://cardify.om/ar/'
    : 'https://cardify.om/';
$suppressDefaultHreflang = true;
$homeHreflang = '<link rel="alternate" hreflang="en" href="https://cardify.om/">'
              . '<link rel="alternate" hreflang="ar" href="https://cardify.om/ar/">'
              . '<link rel="alternate" hreflang="x-default" href="https://cardify.om/">';
$bodyClass = 'bg-white';

// Homepage pricing: compute display strings in the visitor's currency once,
// so the currency pill in the header switches ALL shown prices. Source of
// truth is OMR (rates live in Currency.php fx table); formatNumber respects
// per-currency decimals and separators.
require_once INCLUDES_DIR . '/Currency.php';
$homeCur     = Currency::getUserCurrency();
$homeCurName = $homeCur;
// Convert, then marketing-round (keeps OMR exact, rounds AED/USD/etc to
// clean psychological numbers like 50 / 150 / 1,500 instead of 47.72).
// BHD and KWD are rounded to the nearest whole number AND displayed with
// no decimals (4 instead of 4.000) since Ali asked for the "closest total".
$fmt = function ($omr) use ($homeCur) {
    $converted = Currency::convert((float)$omr, $homeCur);
    $rounded   = Currency::marketingRound($converted, $homeCur);
    if (in_array($homeCur, ['BHD', 'KWD'], true) && floor($rounded) == $rounded) {
        return number_format($rounded, 0);
    }
    return Currency::formatNumber($rounded, $homeCur);
};
// Tier-based subscription pricing was removed Apr 2026. Platform is free forever,
// revenue comes from per-order print products (see lang/en/pricing.php and /pricing).

// Latest blog posts for homepage SEO (internal links + freshness signal)
$latestPosts = [];
try {
    if (isset($db) && $db->isConnected() && $db->tableExists('blog_posts')) {
        $latestPosts = $db->fetchAll(
            "SELECT slug, title, excerpt, featured_image, published_at
             FROM blog_posts
             WHERE status='published'
             ORDER BY published_at DESC
             LIMIT 3"
        );
    }
} catch (Exception $e) {
    $latestPosts = [];
}

// WebSite JSON-LD for homepage (Organization + SoftwareApp already in body of index.php)
// Adds SearchAction so Google can surface a sitelinks search box in SERP.
$siteLd = [
    '@context' => 'https://schema.org',
    '@type' => 'WebSite',
    'name' => 'Cardify',
    'alternateName' => 'Cardify GCC',
    'url' => 'https://cardify.om/',
    'inLanguage' => ['en', 'ar'],
    'publisher' => ['@type' => 'Organization', 'name' => 'Cardify'],
    'potentialAction' => [
        '@type' => 'SearchAction',
        'target' => [
            '@type' => 'EntryPoint',
            'urlTemplate' => 'https://cardify.om/companies?q={search_term_string}',
        ],
        'query-input' => 'required name=search_term_string',
    ],
];
$homeJsonLd = '<script type="application/ld+json">' . json_encode($siteLd, JSON_UNESCAPED_SLASHES) . '</script>';

$extraHead = $homeHreflang . $homeJsonLd . '<style>
    .hero-gradient { background: linear-gradient(135deg, #eff6ff 0%, #ffffff 50%, #fffbeb 100%); }
    .card-shadow { box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15); }
    .float-animation { animation: float 6s ease-in-out infinite; }
    @keyframes float {
        0%, 100% { transform: translateY(0px) rotate(-2deg); }
        50% { transform: translateY(-20px) rotate(2deg); }
    }
    .float-delayed { animation: float 6s ease-in-out infinite; animation-delay: -3s; }
    .float-delay-2 { animation-delay: -1.5s; }
    .bg-blur { backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); }
</style>';

// Enable dynamic navigation with auth awareness
$showNavigation = true;
$navLinks = [
    ['href' => '#features',                             'label' => function_exists('t') ? t('footer.link_features')   : 'Features'],
    ['href' => '#pricing',                              'label' => function_exists('t') ? t('footer.link_pricing')    : 'Pricing'],
    ['href' => getBasePath() . 'tools',                 'label' => function_exists('t') ? t('footer.link_all_tools')  : 'Free Tools'],
    ['href' => getBasePath() . 'oman-business-index',   'label' => function_exists('t') ? t('footer.link_oman_index') : 'Oman Business Index'],
    ['href' => getBasePath() . 'blog',                  'label' => function_exists('t') ? t('footer.link_blog')       : 'Blog'],
];

// Include Auth for navigation state
require_once INCLUDES_DIR . '/Auth.php';

// Hint the browser to start downloading the hero screenshot before the
// stylesheet parse completes. Biggest measurable LCP win on /.
$lcpImage = assetUrl('images/landing/light-dash.png');

require_once INCLUDES_DIR . '/ui-header.php';
?>

    <!-- ========== HERO SECTION (Flowbite Style) ========== -->
    <section class="hero-gradient pt-28 lg:pt-36 pb-16 lg:pb-24 overflow-hidden">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid lg:grid-cols-12 gap-8 lg:gap-12 items-center">
                <!-- Left Content -->
                <div class="lg:col-span-6 text-center lg:text-left">
                    <!-- Badge -->
                    <div class="inline-flex items-center gap-2 py-1 pl-1 pr-4 mb-6 text-sm bg-white border border-gray-200 rounded-full shadow-sm">
                        <span class="inline-flex items-center gap-1 bg-blue-50 text-blue-700 font-semibold text-xs px-3 py-1 rounded-full"><span>🇴🇲</span> <?= htmlspecialchars(t('landing.hero_badge_loc')) ?></span>
                        <span class="font-medium text-gray-700"><?= htmlspecialchars(t('landing.hero_badge_copy')) ?></span>
                    </div>

                    <!-- Headline -->
                    <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold tracking-tight leading-tight text-gray-900 mb-6">
                        <?= htmlspecialchars(t('landing.hero_h1_line1')) ?>
                        <span class="text-blue-600 block"><?= htmlspecialchars(t('landing.hero_h1_line2')) ?></span>
                        <span class="text-gray-500 text-3xl sm:text-4xl lg:text-5xl"><?= htmlspecialchars(t('landing.hero_h1_line3')) ?></span>
                    </h1>

                    <!-- Subheadline -->
                    <p class="text-lg lg:text-xl text-gray-600 mb-8 max-w-xl mx-auto lg:mx-0 leading-relaxed">
                        <?= htmlspecialchars(t('landing.hero_subhead')) ?>
                        <strong class="text-gray-900"><?= htmlspecialchars(t('landing.hero_price_tag')) ?></strong>
                    </p>

                    <!-- CTA Buttons -->
                    <div class="flex flex-col sm:flex-row gap-4 justify-center lg:justify-start mb-10">
                        <a href="<?php echo getBasePath(); ?>company/register.php" class="inline-flex items-center justify-center gap-2 px-7 py-4 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl shadow-lg shadow-blue-600/30 transition-all hover:shadow-xl hover:-translate-y-0.5 text-lg">
                            <?= htmlspecialchars(t('landing.cta_start_free')) ?>
                            <i class="fa-solid fa-arrow-right"></i>
                        </a>
                        <?php
                            $cardifyDemoMsg = (currentLocale() === 'ar')
                                ? 'مرحباً، أرغب بعرض توضيحي لكارديفاي لشركتي'
                                : 'Hi, I would like a demo of Cardify for my company';
                            $cardifyDemoUrl = 'https://wa.me/96899899100?text=' . rawurlencode($cardifyDemoMsg);
                        ?>
                        <a href="<?= htmlspecialchars($cardifyDemoUrl) ?>" target="_blank" rel="noopener" class="inline-flex items-center justify-center gap-2 px-7 py-4 bg-green-500 hover:bg-green-600 text-white font-semibold rounded-xl transition-all text-lg">
                            <i class="fa-brands fa-whatsapp"></i>
                            <?= htmlspecialchars(t('landing.cta_request_demo')) ?>
                        </a>
                    </div>

                    <!-- Trust Badges -->
                    <div class="flex flex-wrap items-center justify-center lg:justify-start gap-3 text-sm">
                        <div class="flex items-center gap-2 px-3 py-1.5 bg-green-50 text-green-700 rounded-full">
                            <i class="fa-solid fa-circle-check"></i>
                            <span><?= htmlspecialchars(t('landing.trust_free_design')) ?></span>
                        </div>
                        <div class="flex items-center gap-2 px-3 py-1.5 bg-blue-50 text-blue-700 rounded-full">
                            <i class="fa-solid fa-print"></i>
                            <span><?= htmlspecialchars(t('landing.trust_printed_by')) ?></span>
                        </div>
                        <div class="flex items-center gap-2 px-3 py-1.5 bg-purple-50 text-purple-700 rounded-full">
                            <i class="fa-solid fa-users"></i>
                            <span><?= htmlspecialchars(t('landing.trust_bulk_csv')) ?></span>
                        </div>
                        <div class="flex items-center gap-2 px-3 py-1.5 bg-amber-50 text-amber-700 rounded-full">
                            <i class="fa-solid fa-language"></i>
                            <span><?= htmlspecialchars(t('landing.trust_bilingual')) ?></span>
                        </div>
                    </div>
                </div>

                <!-- Right Content - Card Mockups -->
                <div class="lg:col-span-6 relative hidden lg:block">
                    <div class="relative h-[500px]">
                        <!-- Main Card -->
                        <div class="float-animation absolute top-0 right-0 w-80 bg-white rounded-2xl card-shadow p-6 border border-gray-100">
                            <div class="flex items-start gap-4">
                                <div class="w-16 h-16 rounded-full bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center text-white text-xl font-bold shadow-lg">
                                    JD
                                </div>
                                <div class="flex-1">
                                    <h3 class="font-bold text-gray-900 text-lg">John Doe</h3>
                                    <p class="text-blue-600 text-sm font-semibold">Senior Developer</p>
                                    <p class="text-gray-500 text-sm">TechCorp Inc.</p>
                                </div>
                            </div>
                            <div class="mt-5 pt-5 border-t border-gray-100 space-y-3">
                                <div class="flex items-center gap-3 text-sm text-gray-600">
                                    <i class="fa-solid fa-envelope w-4 text-blue-500"></i>
                                    <span>john.doe@techcorp.com</span>
                                </div>
                                <div class="flex items-center gap-3 text-sm text-gray-600">
                                    <i class="fa-solid fa-phone w-4 text-blue-500"></i>
                                    <span>+1 (555) 123-4567</span>
                                </div>
                                <div class="flex items-center gap-3 text-sm text-gray-600">
                                    <i class="fa-solid fa-globe w-4 text-blue-500"></i>
                                    <span>techcorp.com</span>
                                </div>
                            </div>
                            <div class="mt-5 flex gap-2">
                                <button class="flex-1 py-2.5 bg-blue-600 text-white text-sm font-semibold rounded-lg hover:bg-blue-700 transition">
                                    <i class="fa-solid fa-address-book mr-2"></i>Save Contact
                                </button>
                                <button class="p-2.5 bg-gray-100 text-gray-600 rounded-lg hover:bg-gray-200 transition"
                                        aria-label="<?= htmlspecialchars(t('common.show_qr')) ?>"
                                        type="button">
                                    <i class="fa-solid fa-qrcode" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Secondary Card -->
                        <div class="float-delayed absolute top-52 -left-4 w-72 bg-gradient-to-br from-amber-400 to-amber-500 rounded-2xl card-shadow p-6 text-white">
                            <div class="flex items-start gap-4">
                                <div class="w-14 h-14 rounded-full bg-white/20 flex items-center justify-center text-lg font-bold backdrop-blur-sm">
                                    SM
                                </div>
                                <div class="flex-1">
                                    <h3 class="font-bold text-lg">Sarah Miller</h3>
                                    <p class="text-white/80 text-sm">Marketing Director</p>
                                </div>
                            </div>
                            <div class="mt-4 pt-4 border-t border-white/20 space-y-2 text-sm text-white/90">
                                <div class="flex items-center gap-3">
                                    <i class="fa-solid fa-envelope w-4"></i>
                                    <span>sarah@creativeco.com</span>
                                </div>
                                <div class="flex items-center gap-3">
                                    <i class="fa-solid fa-globe w-4"></i>
                                    <span>creativeco.com</span>
                                </div>
                            </div>
                        </div>

                        <!-- Small Card -->
                        <div class="float-animation float-delay-2 absolute bottom-8 right-8 w-64 bg-white rounded-2xl card-shadow p-5 border border-gray-100">
                            <div class="flex items-center gap-3">
                                <div class="w-12 h-12 rounded-full bg-gradient-to-br from-green-400 to-green-600 flex items-center justify-center text-white font-bold shadow-lg">
                                    AK
                                </div>
                                <div>
                                    <h3 class="font-bold text-gray-900">Alex Kim</h3>
                                    <p class="text-gray-500 text-xs">CEO, StartupXYZ</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ========== TRUST SIGNALS ========== -->
    <?php @include __DIR__ . '/views/partials/trust_logo_strip.php'; ?>

    <!-- ========== VALUE PROPOSITION BANNER ========== -->
    <section class="py-12 bg-gradient-to-r from-blue-600 via-blue-700 to-indigo-700 text-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid md:grid-cols-3 gap-8 text-center">
                <div class="flex flex-col items-center">
                    <div class="w-14 h-14 bg-white/10 rounded-2xl flex items-center justify-center mb-4">
                        <i class="fa-solid fa-palette text-2xl"></i>
                    </div>
                    <h3 class="text-lg font-bold mb-2"><?= htmlspecialchars(t('landing.vp_design_title')) ?></h3>
                    <p class="text-blue-100 text-sm"><?= htmlspecialchars(t('landing.vp_design_body')) ?></p>
                </div>
                <div class="flex flex-col items-center">
                    <div class="w-14 h-14 bg-white/10 rounded-2xl flex items-center justify-center mb-4">
                        <i class="fa-solid fa-print text-2xl"></i>
                    </div>
                    <h3 class="text-lg font-bold mb-2"><?= htmlspecialchars(t('landing.vp_print_title')) ?></h3>
                    <p class="text-blue-100 text-sm"><?= htmlspecialchars(t('landing.vp_print_body')) ?></p>
                </div>
                <div class="flex flex-col items-center">
                    <div class="w-14 h-14 bg-white/10 rounded-2xl flex items-center justify-center mb-4">
                        <i class="fa-solid fa-gift text-2xl"></i>
                    </div>
                    <h3 class="text-lg font-bold mb-2"><?= htmlspecialchars(t('landing.vp_free_title')) ?></h3>
                    <p class="text-blue-100 text-sm"><?= htmlspecialchars(t('landing.vp_free_body')) ?></p>
                </div>
            </div>
        </div>
    </section>

    <!-- ========== FEATURES SECTION (Flowbite Style) ========== -->
    <section id="features" class="py-16 lg:py-24 bg-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Section Header -->
            <div class="mx-auto max-w-2xl text-center mb-16">
                <p class="text-sm font-semibold uppercase tracking-wider text-blue-600 mb-3"><?= htmlspecialchars(t('landing.feat_kicker')) ?></p>
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-bold tracking-tight text-gray-900 mb-4">
                    <?= htmlspecialchars(t('landing.feat_headline')) ?>
                </h2>
                <p class="text-lg leading-relaxed text-gray-600">
                    <?= htmlspecialchars(t('landing.feat_subhead')) ?>
                </p>
            </div>

            <!-- Features Grid -->
            <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8">
                <!-- Feature 1 - Design Once -->
                <div class="relative bg-white rounded-2xl p-8 shadow-lg shadow-gray-200/60 border border-gray-100 hover:shadow-xl transition-shadow group">
                    <div class="w-14 h-14 rounded-xl bg-blue-100 flex items-center justify-center mb-6 group-hover:bg-blue-600 transition-colors">
                        <i class="fa-solid fa-wand-magic-sparkles text-2xl text-blue-600 group-hover:text-white transition-colors"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3"><?= htmlspecialchars(t('landing.feat_design_title')) ?></h3>
                    <p class="text-gray-600 leading-relaxed">
                        <?= htmlspecialchars(t('landing.feat_design_body')) ?>
                    </p>
                </div>

                <!-- Feature 2 - Print Integration -->
                <div class="relative bg-white rounded-2xl p-8 shadow-lg shadow-gray-200/60 border border-gray-100 hover:shadow-xl transition-shadow group">
                    <div class="absolute -top-3 -right-3 px-2 py-1 bg-green-500 text-white text-xs font-bold rounded-full"><?= htmlspecialchars(t('landing.feat_badge_new')) ?></div>
                    <div class="w-14 h-14 rounded-xl bg-green-100 flex items-center justify-center mb-6 group-hover:bg-green-600 transition-colors">
                        <i class="fa-solid fa-print text-2xl text-green-600 group-hover:text-white transition-colors"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3"><?= htmlspecialchars(t('landing.feat_print_title')) ?></h3>
                    <p class="text-gray-600 leading-relaxed">
                        <?= htmlspecialchars(t('landing.feat_print_body')) ?>
                    </p>
                </div>

                <!-- Feature 3 - Bilingual -->
                <div class="relative bg-white rounded-2xl p-8 shadow-lg shadow-gray-200/60 border border-gray-100 hover:shadow-xl transition-shadow group">
                    <div class="w-14 h-14 rounded-xl bg-amber-100 flex items-center justify-center mb-6 group-hover:bg-amber-500 transition-colors">
                        <i class="fa-solid fa-language text-2xl text-amber-600 group-hover:text-white transition-colors"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3"><?= htmlspecialchars(t('landing.feat_lang_title')) ?></h3>
                    <p class="text-gray-600 leading-relaxed">
                        <?= htmlspecialchars(t('landing.feat_lang_body')) ?>
                    </p>
                </div>

                <!-- Feature 4 - Team Management -->
                <div class="relative bg-white rounded-2xl p-8 shadow-lg shadow-gray-200/60 border border-gray-100 hover:shadow-xl transition-shadow group">
                    <div class="w-14 h-14 rounded-xl bg-purple-100 flex items-center justify-center mb-6 group-hover:bg-purple-600 transition-colors">
                        <i class="fa-solid fa-users text-2xl text-purple-600 group-hover:text-white transition-colors"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3"><?= htmlspecialchars(t('landing.feat_team_title')) ?></h3>
                    <p class="text-gray-600 leading-relaxed">
                        <?= htmlspecialchars(t('landing.feat_team_body')) ?>
                    </p>
                </div>

                <!-- Feature 5 - QR Tracking -->
                <div class="relative bg-white rounded-2xl p-8 shadow-lg shadow-gray-200/60 border border-gray-100 hover:shadow-xl transition-shadow group">
                    <div class="w-14 h-14 rounded-xl bg-pink-100 flex items-center justify-center mb-6 group-hover:bg-pink-600 transition-colors">
                        <i class="fa-solid fa-qrcode text-2xl text-pink-600 group-hover:text-white transition-colors"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3"><?= htmlspecialchars(t('landing.feat_qr_title')) ?></h3>
                    <p class="text-gray-600 leading-relaxed">
                        <?= htmlspecialchars(t('landing.feat_qr_body')) ?>
                    </p>
                </div>

                <!-- Feature 6 - Self Service Portal -->
                <div class="relative bg-white rounded-2xl p-8 shadow-lg shadow-gray-200/60 border border-gray-100 hover:shadow-xl transition-shadow group">
                    <div class="w-14 h-14 rounded-xl bg-red-100 flex items-center justify-center mb-6 group-hover:bg-red-600 transition-colors">
                        <i class="fa-solid fa-door-open text-2xl text-red-600 group-hover:text-white transition-colors"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3"><?= htmlspecialchars(t('landing.feat_portal_title')) ?></h3>
                    <p class="text-gray-600 leading-relaxed">
                        <?= htmlspecialchars(t('landing.feat_portal_body')) ?>
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- ========== HOW IT WORKS (Techwind Style) ========== -->
    <section id="how-it-works" class="py-16 lg:py-24 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Section Header -->
            <div class="max-w-2xl mx-auto text-center mb-16">
                <p class="text-sm font-semibold uppercase tracking-wider text-green-600 mb-3"><?= htmlspecialchars(t('landing.how_kicker')) ?></p>
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-bold tracking-tight text-gray-900 mb-4">
                    <?= htmlspecialchars(t('landing.how_headline')) ?>
                </h2>
                <p class="text-lg text-gray-600">
                    <?= htmlspecialchars(t('landing.how_subhead')) ?>
                </p>
            </div>

            <!-- Steps -->
            <div class="grid md:grid-cols-3 gap-8 lg:gap-12">
                <!-- Step 1 -->
                <div class="relative text-center group">
                    <div class="w-20 h-20 rounded-full bg-blue-600 text-white text-3xl font-bold flex items-center justify-center mx-auto mb-6 shadow-xl shadow-blue-600/30 group-hover:scale-110 transition-transform">
                        1
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3"><?= htmlspecialchars(t('landing.how_step1_title')) ?></h3>
                    <p class="text-gray-600"><?= htmlspecialchars(t('landing.how_step1_body')) ?></p>

                    <!-- Arrow (hidden on mobile) -->
                    <div class="hidden md:block absolute top-10 left-full w-full h-0.5 bg-gray-200 -translate-x-1/2">
                        <i class="fa-solid fa-chevron-right absolute right-0 -top-2 text-gray-300"></i>
                    </div>
                </div>

                <!-- Step 2 -->
                <div class="relative text-center group">
                    <div class="w-20 h-20 rounded-full bg-amber-500 text-white text-3xl font-bold flex items-center justify-center mx-auto mb-6 shadow-xl shadow-amber-500/30 group-hover:scale-110 transition-transform">
                        2
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3"><?= htmlspecialchars(t('landing.how_step2_title')) ?></h3>
                    <p class="text-gray-600"><?= htmlspecialchars(t('landing.how_step2_body')) ?></p>

                    <!-- Arrow (hidden on mobile) -->
                    <div class="hidden md:block absolute top-10 left-full w-full h-0.5 bg-gray-200 -translate-x-1/2">
                        <i class="fa-solid fa-chevron-right absolute right-0 -top-2 text-gray-300"></i>
                    </div>
                </div>

                <!-- Step 3 -->
                <div class="text-center group">
                    <div class="w-20 h-20 rounded-full bg-green-500 text-white text-3xl font-bold flex items-center justify-center mx-auto mb-6 shadow-xl shadow-green-500/30 group-hover:scale-110 transition-transform">
                        3
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3"><?= htmlspecialchars(t('landing.how_step3_title')) ?></h3>
                    <p class="text-gray-600"><?= htmlspecialchars(t('landing.how_step3_body')) ?></p>
                </div>
            </div>

            <!-- CTA -->
            <div class="text-center mt-16">
                <a href="<?php echo getBasePath(); ?>company/register.php" class="inline-flex items-center gap-2 px-8 py-4 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl shadow-lg shadow-blue-600/30 transition-all text-lg">
                    <?= htmlspecialchars(t('landing.how_cta')) ?>
                    <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>
        </div>
    </section>

    <!-- ========== SCREENSHOT SECTION (Techwind Style) ========== -->
    <section class="py-16 lg:py-24 bg-gradient-to-br from-blue-600 via-blue-700 to-indigo-800 overflow-hidden">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid lg:grid-cols-2 gap-12 items-center">
                <!-- Content -->
                <div class="text-white">
                    <span class="inline-flex items-center gap-2 py-1 px-3 mb-4 text-xs font-semibold text-white/80 bg-white/10 rounded-full uppercase tracking-wide">
                        <i class="fa-solid fa-desktop"></i>
                        Dashboard Preview
                    </span>
                    <h2 class="text-3xl sm:text-4xl font-extrabold mb-6">
                        A powerful dashboard at your fingertips
                    </h2>
                    <p class="text-lg text-blue-100 mb-8 leading-relaxed">
                        Manage all your digital business cards from one intuitive interface. Track performance, update information, and share instantly.
                    </p>
                    
                    <ul class="space-y-4 mb-8">
                        <li class="flex items-start gap-3">
                            <i class="fa-solid fa-circle-check text-green-400 mt-1"></i>
                            <span class="text-blue-100">Real-time analytics and engagement metrics</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <i class="fa-solid fa-circle-check text-green-400 mt-1"></i>
                            <span class="text-blue-100">One-click sharing to multiple platforms</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <i class="fa-solid fa-circle-check text-green-400 mt-1"></i>
                            <span class="text-blue-100">Instant updates across all shared cards</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <i class="fa-solid fa-circle-check text-green-400 mt-1"></i>
                            <span class="text-blue-100">Team management with role-based permissions</span>
                        </li>
                    </ul>

                    <a href="<?php echo getBasePath(); ?>company/register.php" class="inline-flex items-center gap-2 px-6 py-3 bg-white text-blue-600 font-semibold rounded-lg hover:bg-blue-50 transition-colors">
                        Try Dashboard Free
                        <i class="fa-solid fa-arrow-right"></i>
                    </a>
                </div>

                <!-- Screenshot -->
                <div class="relative">
                    <div class="relative rounded-xl overflow-hidden shadow-2xl border-8 border-white/10">
                        <img src="<?php echo assetUrl('images/landing/light-dash.png'); ?>" alt="Cardify Dashboard" class="w-full h-auto" width="1175" height="605" fetchpriority="high" decoding="async">
                    </div>
                    <!-- Decorative elements -->
                    <div class="absolute -top-4 -right-4 w-24 h-24 bg-amber-400 rounded-full opacity-20 blur-xl"></div>
                    <div class="absolute -bottom-8 -left-8 w-32 h-32 bg-green-400 rounded-full opacity-20 blur-xl"></div>
                </div>
            </div>
        </div>
    </section>

    <!-- ========== PRICING SECTION ========== -->
    <section id="pricing" class="py-16 lg:py-24 bg-gradient-to-b from-gray-50 to-white px-4">
        <div class="max-w-6xl mx-auto">
            <!-- Section Header -->
            <div class="text-center mb-12">
                <span class="inline-flex items-center gap-2 py-1 px-3 mb-4 text-xs font-semibold text-blue-700 bg-blue-100 rounded-full uppercase tracking-wide">
                    <i class="fa-solid fa-tag"></i>
                    <?= htmlspecialchars(t('pricing.home_kicker')) ?>
                </span>
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-bold tracking-tight text-gray-900 mb-4"><?= htmlspecialchars(t('pricing.home_headline')) ?></h2>
                <p class="text-lg text-gray-600 max-w-2xl mx-auto"><?= htmlspecialchars(t('pricing.home_subhead')) ?></p>
            </div>

            <!-- Platform (free forever) card -->
            <article class="relative bg-white rounded-3xl px-8 pt-12 pb-8 lg:px-10 lg:pt-14 lg:pb-10 ring-1 ring-gray-200/70 shadow-xl mb-10">
                <span class="absolute -top-3 left-8 px-4 py-1 bg-green-600 text-white text-xs font-bold rounded-full uppercase tracking-wider whitespace-nowrap shadow-md z-10">
                    <?= htmlspecialchars(t('pricing.platform_badge')) ?>
                </span>
                <div class="grid lg:grid-cols-2 gap-8 items-center">
                    <div>
                        <h3 class="text-2xl font-bold text-gray-700 mb-2"><?= htmlspecialchars(t('pricing.platform_name')) ?></h3>
                        <div class="flex items-baseline gap-2 mb-3">
                            <span class="text-5xl lg:text-6xl font-extrabold text-gray-900"><?= htmlspecialchars(t('pricing.platform_price')) ?></span>
                        </div>
                        <p class="text-gray-500 mb-6"><?= htmlspecialchars(t('pricing.platform_sub')) ?></p>
                        <a href="<?= getBasePath() ?>company/register.php" class="inline-flex items-center justify-center gap-2 px-7 py-3.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl shadow-lg shadow-blue-500/30 transition hover:-translate-y-0.5">
                            <?= htmlspecialchars(t('pricing.platform_cta')) ?>
                            <i class="fa-solid fa-arrow-right"></i>
                        </a>
                    </div>
                    <ul class="grid sm:grid-cols-2 gap-x-4 gap-y-1">
                        <?php for ($i = 1; $i <= 8; $i++): ?>
                            <li class="flex items-start gap-2 py-1.5 text-gray-700">
                                <i class="fa-solid fa-check text-green-600 mt-1 flex-shrink-0"></i>
                                <span><?= htmlspecialchars(t('pricing.platform_f' . $i)) ?></span>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </div>
            </article>

            <!-- Printed product catalogue -->
            <div class="text-center mb-8">
                <p class="text-sm font-semibold uppercase tracking-wider text-blue-600 mb-2"><?= htmlspecialchars(t('pricing.products_eyebrow')) ?></p>
                <h3 class="text-2xl sm:text-3xl font-bold text-gray-900 mb-2"><?= htmlspecialchars(t('pricing.products_h')) ?></h3>
                <p class="text-base text-gray-600 max-w-2xl mx-auto"><?= htmlspecialchars(t('pricing.products_b')) ?></p>
            </div>

            <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-6">
                <?php
                $homeProducts = [
                    'standard' => ['accent' => 'blue',    'icon' => 'fa-id-card'],
                    'premium'  => ['accent' => 'purple',  'icon' => 'fa-gem'],
                    'luxury'   => ['accent' => 'amber',   'icon' => 'fa-award'],
                    'nfc'      => ['accent' => 'emerald', 'icon' => 'fa-wifi'],
                ];
                foreach ($homeProducts as $key => $meta):
                ?>
                    <article class="flex flex-col bg-white rounded-2xl p-6 ring-1 ring-gray-200/70 hover:-translate-y-1 hover:shadow-xl transition-all">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center mb-4 bg-<?= $meta['accent'] ?>-100">
                            <i class="fa-solid <?= $meta['icon'] ?> text-xl text-<?= $meta['accent'] ?>-600"></i>
                        </div>
                        <h4 class="text-lg font-bold text-gray-900 mb-1"><?= htmlspecialchars(t('pricing.product_' . $key . '_name')) ?></h4>
                        <p class="text-sm text-gray-500 mb-4 leading-relaxed flex-1"><?= htmlspecialchars(t('pricing.product_' . $key . '_spec')) ?></p>
                        <div class="mb-4">
                            <span class="text-2xl font-extrabold text-gray-900"><?= htmlspecialchars(t('pricing.product_' . $key . '_price')) ?></span>
                            <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars(t('pricing.product_' . $key . '_unit')) ?></p>
                        </div>
                        <a href="<?= getBasePath() ?>company/register.php" class="text-blue-600 hover:text-blue-700 font-semibold text-sm inline-flex items-center gap-1">
                            <?= htmlspecialchars(t('pricing.product_cta')) ?>
                            <i class="fa-solid fa-arrow-right text-xs"></i>
                        </a>
                    </article>
                <?php endforeach; ?>
            </div>

            <p class="text-center text-sm text-gray-500 mt-10">
                <i class="fa-solid fa-shield-halved text-green-600 mr-1"></i>
                <?= htmlspecialchars(t('pricing.home_footnote')) ?>
            </p>
        </div>
    </section>

    <!-- ========== TESTIMONIALS SECTION (Flowbite Style) ========== -->
    <section id="testimonials" class="py-16 lg:py-24 bg-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Section Header -->
            <div class="max-w-2xl mx-auto text-center mb-16">
                <p class="text-sm font-semibold uppercase tracking-wider text-blue-600 mb-3"><?= htmlspecialchars(t('testimonials.kicker')) ?></p>
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-bold tracking-tight text-gray-900 mb-4">
                    <?= htmlspecialchars(t('testimonials.headline')) ?>
                </h2>
                <p class="text-lg text-gray-600">
                    <?= htmlspecialchars(t('testimonials.subhead')) ?>
                </p>
            </div>

            <!-- Testimonials Grid -->
            <div class="grid lg:grid-cols-2 gap-8">
                <?php foreach ([
                    ['n' => 1, 'initials' => 'AA', 'grad' => 'from-blue-500 to-blue-600'],
                    ['n' => 2, 'initials' => 'FA', 'grad' => 'from-amber-400 to-amber-500'],
                    ['n' => 3, 'initials' => 'KH', 'grad' => 'from-purple-500 to-purple-600'],
                    ['n' => 4, 'initials' => 'SA', 'grad' => 'from-green-500 to-green-600'],
                ] as $t): $k = 't' . $t['n']; ?>
                <figure class="bg-white rounded-2xl p-8 shadow-sm ring-1 ring-gray-200/70 hover:ring-blue-200 hover:shadow-lg transition-all">
                    <div class="mb-5 text-blue-500 text-2xl leading-none"><i class="fa-solid fa-quote-left"></i></div>
                    <blockquote class="mb-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4"><?= htmlspecialchars(t('testimonials.' . $k . '_title')) ?></h3>
                        <p class="text-gray-600 leading-relaxed">
                            <?= htmlspecialchars(t('testimonials.' . $k . '_quote')) ?>
                        </p>
                    </blockquote>
                    <figcaption class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-full bg-gradient-to-br <?= htmlspecialchars($t['grad']) ?> flex items-center justify-center text-white font-bold">
                            <?= htmlspecialchars($t['initials']) ?>
                        </div>
                        <div>
                            <div class="font-semibold text-gray-900"><?= htmlspecialchars(t('testimonials.' . $k . '_author')) ?></div>
                            <div class="text-sm text-gray-500"><?= htmlspecialchars(t('testimonials.' . $k . '_role')) ?></div>
                        </div>
                    </figcaption>
                </figure>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ========== FROM THE BLOG (SEO internal linking) ========== -->
    <?php if (!empty($latestPosts)): ?>
    <section class="py-16 lg:py-24 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-end justify-between mb-10 flex-wrap gap-4">
                <div>
                    <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-900 mb-2"><?= htmlspecialchars(t('landing.blog_heading')) ?></h2>
                    <p class="text-lg text-gray-600"><?= htmlspecialchars(t('landing.blog_sub')) ?></p>
                </div>
                <a href="<?= getBasePath() ?>blog" class="inline-flex items-center gap-2 text-blue-600 hover:text-blue-700 font-semibold">
                    <?= htmlspecialchars(t('landing.blog_view_all')) ?>
                    <i class="fa-solid fa-arrow-right text-sm"></i>
                </a>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <?php foreach ($latestPosts as $post): ?>
                <?php
                    $postUrl = getBasePath() . 'blog/' . $post['slug'];
                    $postDate = date('M j, Y', strtotime($post['published_at']));
                    $excerpt = $post['excerpt'] ?? '';
                    if (strlen($excerpt) > 140) $excerpt = substr($excerpt, 0, 140) . '…';
                    $img = !empty($post['featured_image']) ? htmlspecialchars($post['featured_image']) : 'assets/images/cardify-og.png';
                ?>
                <article class="group bg-white border border-gray-200 rounded-2xl overflow-hidden hover:shadow-xl transition-shadow">
                    <a href="<?= htmlspecialchars($postUrl) ?>" class="block aspect-video bg-gray-100 overflow-hidden">
                        <img src="<?= getBasePath() . $img ?>" alt="<?= htmlspecialchars($post['title']) ?>" class="w-full h-full object-cover group-hover:scale-105 transition-transform" width="1200" height="675" loading="lazy" decoding="async">
                    </a>
                    <div class="p-6">
                        <time class="text-sm text-gray-500"><?= $postDate ?></time>
                        <h3 class="mt-2 text-xl font-bold text-gray-900 leading-snug">
                            <a href="<?= htmlspecialchars($postUrl) ?>" class="hover:text-blue-700 transition-colors"><?= htmlspecialchars($post['title']) ?></a>
                        </h3>
                        <?php if ($excerpt): ?>
                        <p class="mt-3 text-gray-600 text-sm leading-relaxed"><?= htmlspecialchars($excerpt) ?></p>
                        <?php endif; ?>
                        <a href="<?= htmlspecialchars($postUrl) ?>" class="mt-4 inline-flex items-center gap-1 text-blue-600 hover:text-blue-700 text-sm font-semibold">
                            Read more
                            <i class="fa-solid fa-arrow-right text-xs"></i>
                        </a>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- ========== FREE TOOLS & DIRECTORIES (SEO HUB) ========== -->
    <?php
        // r6-51: published counts are derived at render time, never hardcoded.
        // The directory count and the logo count are DIFFERENT populations:
        // om_companies rows vs rows whose logo_status is indexed/verified.
        // If either query fails we drop the number from the sentence rather than ship a stale one.
        $resCompaniesCount = null;
        $resLogosCount = null;
        try {
            if (isset($db) && $db->isConnected()) {
                $r = $db->fetchOne("SELECT COUNT(*) c FROM om_companies");
                if ($r && isset($r['c'])) $resCompaniesCount = (int) $r['c'];
                $r = $db->fetchOne("SELECT COUNT(*) c FROM om_companies WHERE logo_status IN ('indexed','verified')");
                if ($r && isset($r['c'])) $resLogosCount = (int) $r['c'];
            }
        } catch (Throwable $e) { /* leave both null: render the count-free copy */ }
        $resSubhead = $resCompaniesCount !== null
            ? t('landing.res_subhead', ['companies' => number_format($resCompaniesCount)])
            : t('landing.res_subhead_nc');
        $resLogosSub = $resLogosCount !== null
            ? t('landing.res_logos_sub', ['logos' => number_format($resLogosCount)])
            : t('landing.res_logos_sub_nc');
    ?>
    <section id="resources" class="py-16 lg:py-24 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12">
                <span class="inline-block text-sm font-semibold text-blue-600 uppercase tracking-wider mb-3"><?= htmlspecialchars(t('landing.res_kicker')) ?></span>
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-gray-900 mb-4">
                    <?= htmlspecialchars(t('landing.res_headline')) ?>
                </h2>
                <p class="text-lg text-gray-600 max-w-3xl mx-auto">
                    <?= htmlspecialchars($resSubhead) ?>
                </p>
            </div>

            <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8 mb-12">
                <!-- Free Tools -->
                <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-2xl p-8 border border-blue-100">
                    <div class="flex items-center gap-3 mb-6">
                        <div class="w-12 h-12 rounded-xl bg-blue-600 text-white flex items-center justify-center">
                            <i class="fa-solid fa-toolbox text-xl"></i>
                        </div>
                        <h3 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars(t('landing.res_tools_title')) ?></h3>
                    </div>
                    <p class="text-gray-600 mb-6"><?= htmlspecialchars(t('landing.res_tools_sub')) ?></p>
                    <ul class="space-y-3 mb-6">
                        <?php foreach ([
                            ['href' => '/tools/vcard-qr-generator', 'icon' => 'fa-solid fa-qrcode', 'k' => 'tool_vcard'],
                            ['href' => '/tools/email-signature-generator', 'icon' => 'fa-solid fa-envelope', 'k' => 'tool_sig'],
                            ['href' => '/tools/whatsapp-qr-generator', 'icon' => 'fa-brands fa-whatsapp', 'k' => 'tool_wa'],
                            ['href' => '/tools/nfc-business-card-guide', 'icon' => 'fa-solid fa-wifi', 'k' => 'tool_nfc'],
                        ] as $tl): ?>
                        <li>
                            <a href="<?= htmlspecialchars($tl['href']) ?>" class="flex items-start gap-3 p-3 rounded-lg hover:bg-white transition group">
                                <i class="<?= htmlspecialchars($tl['icon']) ?> text-blue-600 mt-1"></i>
                                <div>
                                    <div class="font-semibold text-gray-900 group-hover:text-blue-700"><?= htmlspecialchars(t('landing.res_' . $tl['k'] . '_title')) ?></div>
                                    <div class="text-sm text-gray-500"><?= htmlspecialchars(t('landing.res_' . $tl['k'] . '_sub')) ?></div>
                                </div>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <a href="/tools" class="inline-flex items-center gap-2 text-blue-700 font-semibold hover:text-blue-800">
                        <?= htmlspecialchars(t('landing.res_tools_cta')) ?>
                        <i class="fa-solid fa-arrow-right text-xs"></i>
                    </a>
                </div>

                <!-- Oman Business Directory -->
                <div class="bg-gradient-to-br from-emerald-50 to-teal-50 rounded-2xl p-8 border border-emerald-100">
                    <div class="flex items-center gap-3 mb-6">
                        <div class="w-12 h-12 rounded-xl bg-emerald-600 text-white flex items-center justify-center">
                            <i class="fa-solid fa-building-columns text-xl"></i>
                        </div>
                        <h3 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars(t('landing.res_obi_title')) ?></h3>
                    </div>
                    <p class="text-gray-600 mb-6"><?= htmlspecialchars(t('landing.res_obi_sub')) ?></p>
                    <div class="grid grid-cols-2 gap-2 mb-6">
                        <?php foreach ([
                            'oil_gas'      => '/companies/sector/oil-gas',
                            'construction' => '/companies/sector/construction',
                            'finance'      => '/companies/sector/finance',
                            'trading'      => '/companies/sector/trading',
                            'manufacturing'=> '/companies/sector/manufacturing',
                            'hospitality'  => '/companies/sector/hospitality-tourism',
                            'muscat'       => '/companies/wilayat/muscat',
                            'dhofar'       => '/companies/wilayat/dhofar',
                        ] as $k => $href): ?>
                            <a href="<?= htmlspecialchars($href) ?>" class="text-sm px-3 py-2 rounded-lg bg-white text-gray-700 hover:bg-emerald-100 hover:text-emerald-700 transition font-medium border border-gray-100"><?= htmlspecialchars(t('landing.res_obi_' . $k)) ?></a>
                        <?php endforeach; ?>
                    </div>
                    <a href="/oman-business-index" class="inline-flex items-center gap-2 text-emerald-700 font-semibold hover:text-emerald-800">
                        <?= htmlspecialchars(t('landing.res_obi_cta')) ?>
                        <i class="fa-solid fa-arrow-right text-xs"></i>
                    </a>
                </div>

                <!-- Omani Logo Library -->
                <div class="bg-gradient-to-br from-amber-50 to-orange-50 rounded-2xl p-8 border border-amber-100">
                    <div class="flex items-center gap-3 mb-6">
                        <div class="w-12 h-12 rounded-xl bg-amber-500 text-white flex items-center justify-center">
                            <i class="fa-solid fa-bezier-curve text-xl"></i>
                        </div>
                        <h3 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars(t('landing.res_logos_title')) ?></h3>
                    </div>
                    <p class="text-gray-600 mb-6"><?= htmlspecialchars($resLogosSub) ?></p>
                    <div class="grid grid-cols-2 gap-2 mb-6">
                        <?php foreach ([
                            ['k' => 'pin1', 'href' => '/companies/bhd-group'],
                            ['k' => 'pin2', 'href' => '/companies/bank-muscat'],
                            ['k' => 'pin3', 'href' => '/companies/oq'],
                            ['k' => 'pin4', 'href' => '/companies/asyad-group'],
                            ['k' => 'pin5', 'href' => '/companies/oman-telecommunication'],
                            ['k' => 'pin6', 'href' => '/companies/ooredoo-01-0d1b'],
                            ['k' => 'pin7', 'href' => '/companies/bank-dhofar'],
                            ['k' => 'pin8', 'href' => '/companies/sohar-international-bank'],
                        ] as $pin): ?>
                            <a href="<?= htmlspecialchars($pin['href']) ?>" class="text-sm px-3 py-2 rounded-lg bg-white text-gray-700 hover:bg-amber-100 hover:text-amber-700 transition font-medium border border-gray-100"><?= htmlspecialchars(t('landing.res_logos_' . $pin['k'])) ?></a>
                        <?php endforeach; ?>
                    </div>
                    <a href="/logos" class="inline-flex items-center gap-2 text-amber-700 font-semibold hover:text-amber-800">
                        <?= htmlspecialchars(t('landing.res_logos_cta')) ?>
                        <i class="fa-solid fa-arrow-right text-xs"></i>
                    </a>
                </div>
            </div>

            <!-- Solutions Row -->
            <div class="bg-gray-50 rounded-2xl p-8">
                <div class="flex items-center justify-between flex-wrap gap-4 mb-6">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-purple-600 text-white flex items-center justify-center">
                            <i class="fa-solid fa-lightbulb"></i>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900"><?= htmlspecialchars(t('landing.res_sol_heading')) ?></h3>
                    </div>
                    <a href="/solutions" class="text-sm font-semibold text-purple-700 hover:text-purple-800"><?= htmlspecialchars(t('landing.res_sol_cta')) ?></a>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                    <a href="/solutions/business-cards-oman-construction-companies" class="p-3 bg-white rounded-lg text-sm font-medium text-gray-700 hover:text-purple-700 hover:shadow transition border border-gray-100">Construction &amp; Contracting</a>
                    <a href="/solutions/digital-business-cards-oil-gas-oman" class="p-3 bg-white rounded-lg text-sm font-medium text-gray-700 hover:text-purple-700 hover:shadow transition border border-gray-100">Oil &amp; Gas</a>
                    <a href="/solutions/business-cards-oman-bank-employees" class="p-3 bg-white rounded-lg text-sm font-medium text-gray-700 hover:text-purple-700 hover:shadow transition border border-gray-100">Bank Employees</a>
                    <a href="/solutions/business-cards-muscat-doctors-clinics" class="p-3 bg-white rounded-lg text-sm font-medium text-gray-700 hover:text-purple-700 hover:shadow transition border border-gray-100">Doctors &amp; Clinics</a>
                    <a href="/solutions/business-cards-omani-law-firms" class="p-3 bg-white rounded-lg text-sm font-medium text-gray-700 hover:text-purple-700 hover:shadow transition border border-gray-100">Law Firms</a>
                    <a href="/solutions/digital-cards-oman-real-estate-agents" class="p-3 bg-white rounded-lg text-sm font-medium text-gray-700 hover:text-purple-700 hover:shadow transition border border-gray-100">Real Estate</a>
                    <a href="/solutions/qr-code-menu-muscat-restaurants" class="p-3 bg-white rounded-lg text-sm font-medium text-gray-700 hover:text-purple-700 hover:shadow transition border border-gray-100">Restaurants QR Menu</a>
                    <a href="/solutions/bilingual-arabic-english-business-cards" class="p-3 bg-white rounded-lg text-sm font-medium text-gray-700 hover:text-purple-700 hover:shadow transition border border-gray-100">Bilingual AR/EN Cards</a>
                    <a href="/solutions/nfc-business-cards-oman-executives" class="p-3 bg-white rounded-lg text-sm font-medium text-gray-700 hover:text-purple-700 hover:shadow transition border border-gray-100">NFC for Executives</a>
                    <a href="/solutions/business-cards-oman-government-employees" class="p-3 bg-white rounded-lg text-sm font-medium text-gray-700 hover:text-purple-700 hover:shadow transition border border-gray-100">Government Employees</a>
                    <a href="/solutions/business-cards-oman-startups" class="p-3 bg-white rounded-lg text-sm font-medium text-gray-700 hover:text-purple-700 hover:shadow transition border border-gray-100">Startups</a>
                    <a href="/solutions/salalah-tourism-business-cards" class="p-3 bg-white rounded-lg text-sm font-medium text-gray-700 hover:text-purple-700 hover:shadow transition border border-gray-100">Salalah Tourism</a>
                </div>
            </div>
        </div>
    </section>

    <!-- ========== CTA SECTION (Flowbite Style) ========== -->
    <section class="py-16 lg:py-24 bg-gradient-to-br from-blue-600 to-indigo-700">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <div class="inline-flex items-center gap-2 px-4 py-2 bg-white/10 backdrop-blur-sm rounded-full text-white text-sm font-medium mb-6">
                <span>🇴🇲</span>
                <span><?= htmlspecialchars(t('landing.cta_supporting')) ?></span>
            </div>

            <h2 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-white mb-6">
                <?= htmlspecialchars(t('landing.cta_title')) ?>
            </h2>
            <p class="text-xl text-blue-100 mb-10 max-w-2xl mx-auto">
                <?= htmlspecialchars(t('landing.cta_sub')) ?>
            </p>

            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                <a href="<?php echo getBasePath(); ?>company/register.php" class="inline-flex items-center justify-center gap-2 px-8 py-4 bg-white hover:bg-gray-100 text-blue-600 font-bold rounded-xl shadow-xl transition-all hover:-translate-y-0.5 text-lg">
                    <i class="fa-solid fa-rocket"></i>
                    <?= htmlspecialchars(t('landing.cta_start_trial')) ?>
                </a>
                <a href="<?php echo getBasePath(); ?>intro" class="inline-flex items-center justify-center gap-2 px-8 py-4 bg-blue-500/20 hover:bg-blue-500/30 text-white font-semibold rounded-xl border-2 border-white/30 transition-all text-lg">
                    <i class="fa-solid fa-play-circle"></i>
                    <?= htmlspecialchars(t('landing.cta_see_how')) ?>
                </a>
            </div>

            <div class="mt-10 flex flex-wrap justify-center gap-6 text-white/70 text-sm">
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-check-circle"></i>
                    <span><?= htmlspecialchars(t('landing.cta_free_starter')) ?></span>
                </div>
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-check-circle"></i>
                    <span><?= htmlspecialchars(t('landing.cta_free_trial')) ?></span>
                </div>
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-check-circle"></i>
                    <span><?= htmlspecialchars(t('pricing.home_plans_from', ['amount' => $priceStarterFrom, 'currency' => $homeCurName])) ?></span>
                </div>
            </div>
            </p>
        </div>
    </section>

    <!-- ========== FOOTER ========== -->
    <footer id="contact" class="bg-gray-900 text-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
            <div class="grid md:grid-cols-2 lg:grid-cols-5 gap-10 mb-12">
                <!-- Brand -->
                <div class="lg:col-span-1">
                    <div class="flex items-center gap-3 mb-6">
                        <img src="<?php echo assetUrl('images/logo-light.svg'); ?>" alt="<?php echo $brandName; ?>" class="h-10 w-auto">
                    </div>
                    <p class="text-gray-400 mb-4 leading-relaxed text-sm">
                        The modern way to create and share professional business cards. Built for teams of all sizes.
                    </p>
                    <div class="flex gap-3 mb-6">
                        <a href="https://instagram.com/cardifyom" target="_blank" rel="noopener noreferrer" class="w-10 h-10 rounded-lg bg-gray-800 hover:bg-pink-600 flex items-center justify-center transition-colors" aria-label="Instagram">
                            <i class="fa-brands fa-instagram"></i>
                        </a>
                    </div>
                </div>

                <!-- Product Links -->
                <div>
                    <h4 class="font-bold text-lg mb-6"><?= htmlspecialchars(t('footer.col_product')) ?></h4>
                    <ul class="space-y-3">
                        <li><a href="#features" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_features')) ?></a></li>
                        <li><a href="#pricing" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_pricing')) ?></a></li>
                        <li><a href="#resources" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_all_tools')) ?></a></li>
                        <li><a href="<?php echo getBasePath(); ?>company/register.php" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('header.get_started_free')) ?></a></li>
                    </ul>
                </div>

                <!-- Free Tools -->
                <div>
                    <h4 class="font-bold text-lg mb-6"><?= htmlspecialchars(t('footer.col_free_tools')) ?></h4>
                    <ul class="space-y-3">
                        <li><a href="<?php echo getBasePath(); ?>tools/vcard-qr-generator" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_vcard_qr')) ?></a></li>
                        <li><a href="<?php echo getBasePath(); ?>tools/email-signature-generator" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_email_sig')) ?></a></li>
                        <li><a href="<?php echo getBasePath(); ?>tools/whatsapp-qr-generator" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_whatsapp_qr')) ?></a></li>
                        <li><a href="<?php echo getBasePath(); ?>tools/nfc-business-card-guide" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_nfc_guide')) ?></a></li>
                        <li><a href="<?php echo getBasePath(); ?>tools" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_all_tools')) ?></a></li>
                    </ul>
                </div>

                <!-- Directory & Solutions -->
                <div>
                    <h4 class="font-bold text-lg mb-6"><?= htmlspecialchars(t('footer.col_directory')) ?></h4>
                    <ul class="space-y-3">
                        <li><a href="<?php echo getBasePath(); ?>oman-business-index" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_oman_index')) ?></a></li>
                        <li><a href="<?php echo getBasePath(); ?>companies" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_browse_companies')) ?></a></li>
                        <li><a href="<?php echo getBasePath(); ?>companies/sector/oil-gas" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_ind_oil')) ?></a></li>
                        <li><a href="<?php echo getBasePath(); ?>companies/sector/construction" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_ind_construction')) ?></a></li>
                        <li><a href="<?php echo getBasePath(); ?>companies/wilayat/muscat" class="text-gray-400 hover:text-white transition-colors">Muscat Companies</a></li>
                        <li><a href="<?php echo getBasePath(); ?>solutions" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_solutions')) ?></a></li>
                    </ul>
                </div>

                <!-- Company + Legal -->
                <div>
                    <h4 class="font-bold text-lg mb-6"><?= htmlspecialchars(t('footer.col_company')) ?></h4>
                    <ul class="space-y-3">
                        <li><a href="<?php echo getBasePath(); ?>about" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_about')) ?></a></li>
                        <li><a href="<?php echo getBasePath(); ?>blog" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_blog')) ?></a></li>
                        <li><a href="<?php echo getBasePath(); ?>careers" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_careers')) ?></a></li>
                        <li><a href="<?php echo getBasePath(); ?>contact" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_contact')) ?></a></li>
                        <li><a href="<?php echo getBasePath(); ?>privacy" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_privacy')) ?></a></li>
                        <li><a href="<?php echo getBasePath(); ?>terms" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_terms')) ?></a></li>
                    </ul>
                </div>
            </div>

            <!-- Bottom Bar -->
            <div class="pt-8 border-t border-gray-800 flex flex-col md:flex-row justify-between items-center gap-4">
                <p class="text-gray-500 text-sm">
                    <?= htmlspecialchars(t('footer.copyright', ['year' => date('Y'), 'brand' => $brandName])) ?>
                </p>
                <div class="flex items-center gap-6 text-sm text-gray-500">
                    <span class="flex items-center gap-2">
                        <i class="fa-solid fa-globe"></i>
                        <?= (function_exists('currentLocale') && currentLocale() === 'ar') ? 'العربية' : 'English (US)' ?>
                    </span>
                </div>
            </div>
        </div>
    </footer>

    <!-- ========== SCRIPTS ========== -->
    <?php
    $extraScripts = <<<HTML
    <script>
        // Mobile menu toggle
        const mobileMenuBtn = document.getElementById('mobile-menu-btn');
        const mobileMenu = document.getElementById('mobile-menu');
        
        mobileMenuBtn?.addEventListener('click', () => {
            mobileMenu.classList.toggle('hidden');
        });

        // Navbar scroll effect
        const navbar = document.getElementById('navbar');
        window.addEventListener('scroll', () => {
            const currentScroll = window.pageYOffset;
            if (currentScroll > 50) {
                navbar.classList.add('shadow-md');
            } else {
                navbar.classList.remove('shadow-md');
            }
        });
    </script>
HTML;
?>

<!-- JSON-LD Structured Data -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Organization",
  "@id": "https://cardify.om/#organization",
  "parentOrganization": {"@id": "https://bhd.om/#organization"},
  "name": "Cardify",
  "alternateName": ["Cardify Oman", "Cardify GCC"],
  "url": "https://cardify.om",
  "logo": "https://cardify.om/assets/images/logo.svg",
  "description": "Business-identity platform for the Gulf: digital and printed business cards, public logo libraries, and the GCC Business Index. Built in Oman, expanding across Saudi Arabia, UAE, Qatar, Bahrain, and Kuwait through 2026.",
  "address": {
    "@type": "PostalAddress",
    "addressCountry": "OM",
    "addressLocality": "Muscat"
  },
  "foundingDate": "2024",
  "foundingLocation": {
    "@type": "Place",
    "address": {
      "@type": "PostalAddress",
      "addressCountry": "OM",
      "addressLocality": "Muscat"
    }
  },
  "areaServed": [
    { "@type": "Country", "name": "Oman", "alternateName": "عُمان", "identifier": "OMN" },
    { "@type": "Country", "name": "Saudi Arabia", "alternateName": "المملكة العربية السعودية", "identifier": "SAU" },
    { "@type": "Country", "name": "United Arab Emirates", "alternateName": "الإمارات العربية المتحدة", "identifier": "ARE" },
    { "@type": "Country", "name": "Qatar", "alternateName": "قطر", "identifier": "QAT" },
    { "@type": "Country", "name": "Bahrain", "alternateName": "البحرين", "identifier": "BHR" },
    { "@type": "Country", "name": "Kuwait", "alternateName": "الكويت", "identifier": "KWT" }
  ],
  "knowsLanguage": ["en", "ar"],
  "sameAs": ["https://instagram.com/cardifyom"],
  "contactPoint": {
    "@type": "ContactPoint",
    "contactType": "customer service",
    "url": "https://cardify.om/contact",
    "availableLanguage": ["en", "ar"]
  },
  "hasOfferCatalog": {
    "@type": "OfferCatalog",
    "name": "Cardify Product Catalog",
    "itemListElement": [
      { "@type": "OfferCatalog", "name": "Digital Business Cards", "url": "https://cardify.om/" },
      { "@type": "OfferCatalog", "name": "Omani Logo Library", "url": "https://cardify.om/logos" },
      { "@type": "OfferCatalog", "name": "Oman Business Index", "url": "https://cardify.om/oman-business-index" },
      { "@type": "OfferCatalog", "name": "GCC Business Index", "url": "https://cardify.om/gcc-business-index" }
    ]
  }
}
</script>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "SoftwareApplication",
  "name": "Cardify",
  "applicationCategory": "BusinessApplication",
  "operatingSystem": "Web, iOS, Android",
  "url": "https://cardify.om",
  "description": "Digital and printed business card SaaS for teams across the GCC. Bilingual EN+AR, QR vCard auto-save, Apple Wallet + Google Wallet, bulk team onboarding, local print fulfilment. Available in Oman, Saudi Arabia, UAE, Qatar, Bahrain, and Kuwait.",
  "inLanguage": ["en", "ar"],
  "offers": [
    {
      "@type": "Offer",
      "name": "Platform Access",
      "price": "0",
      "priceCurrency": "OMR",
      "description": "Free forever. Unlimited employees, unlimited templates, digital cards with QR vCard, bilingual EN+AR, analytics, WhatsApp and email share, no credit card required.",
      "availability": "https://schema.org/InStock",
      "url": "https://cardify.om/company/register.php"
    },
    {
      "@type": "Offer",
      "name": "Standard Printed Cards",
      "price": "6.000",
      "priceCurrency": "OMR",
      "priceSpecification": {
        "@type": "UnitPriceSpecification",
        "price": "6.000",
        "priceCurrency": "OMR",
        "referenceQuantity": { "@type": "QuantitativeValue", "value": "100", "unitText": "cards" }
      },
      "description": "300gsm matt, full colour both sides. From OMR 6.000 per 100 cards, printed by verified Omani shops.",
      "availability": "https://schema.org/InStock",
      "url": "https://cardify.om/pricing"
    },
    {
      "@type": "Offer",
      "name": "Premium Printed Cards",
      "price": "8.000",
      "priceCurrency": "OMR",
      "priceSpecification": {
        "@type": "UnitPriceSpecification",
        "price": "8.000",
        "priceCurrency": "OMR",
        "referenceQuantity": { "@type": "QuantitativeValue", "value": "100", "unitText": "cards" }
      },
      "description": "350gsm soft-touch, full colour both sides. From OMR 8.000 per 100 cards.",
      "availability": "https://schema.org/InStock",
      "url": "https://cardify.om/pricing"
    },
    {
      "@type": "Offer",
      "name": "Luxury Printed Cards",
      "price": "15.000",
      "priceCurrency": "OMR",
      "priceSpecification": {
        "@type": "UnitPriceSpecification",
        "price": "15.000",
        "priceCurrency": "OMR",
        "referenceQuantity": { "@type": "QuantitativeValue", "value": "100", "unitText": "cards" }
      },
      "description": "450gsm with spot UV or foil accents. From OMR 15.000 per 100 cards.",
      "availability": "https://schema.org/InStock",
      "url": "https://cardify.om/pricing"
    },
    {
      "@type": "Offer",
      "name": "NFC Tap Cards",
      "price": "25.000",
      "priceCurrency": "OMR",
      "priceSpecification": {
        "@type": "UnitPriceSpecification",
        "price": "25.000",
        "priceCurrency": "OMR",
        "referenceQuantity": { "@type": "QuantitativeValue", "value": "1", "unitText": "card" }
      },
      "description": "Re-programmable NFC chip plus QR. Tap-to-share on any phone. OMR 25.000 per card.",
      "availability": "https://schema.org/InStock",
      "url": "https://cardify.om/pricing"
    }
  ]
}
</script>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Product",
  "name": "Cardify Business Card Platform",
  "image": "https://cardify.om/assets/images/cardify-og.png",
  "description": "SaaS for creating, managing, and printing branded digital + printed business cards for teams in Oman.",
  "brand": { "@type": "Brand", "name": "Cardify" },
  "offers": {
    "@type": "AggregateOffer",
    "priceCurrency": "OMR",
    "lowPrice": "0",
    "highPrice": "25",
    "offerCount": "5",
    "availability": "https://schema.org/InStock"
  }
}
</script>

<?php
    require INCLUDES_DIR . '/ui-footer.php';
    ?>
