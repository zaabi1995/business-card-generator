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

// Tenant subdomain check (e.g. ohb.cardify.om). If the host is a tenant
// subdomain, route the root + /login here to the OTP login page.
if (file_exists(__DIR__ . '/includes/TenantHost.php')) {
    require_once __DIR__ . '/includes/TenantHost.php';
    if (TenantHost::isTenantHost()) {
        require __DIR__ . '/tenant_login.php';
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
$pageTitle = 'Cardify — Digital & Printed Business Cards for the GCC';
$pageDescription = 'Bilingual Arabic/English digital and printed business cards for teams across the Gulf: Oman (live), Saudi Arabia, UAE, Qatar, Bahrain, and Kuwait (rolling out 2026). QR vCard save, Apple Wallet, NFC, bulk provisioning. Free to start.';
$canonicalUrl = 'https://cardify.om/';
$bodyClass = 'bg-white';

// Fetch subscription plans from database for pricing section
$subscriptionPlans = [];
try {
    if (isset($db) && $db->isConnected() && $db->tableExists('subscription_plans')) {
        $subscriptionPlans = $db->fetchAll(
            "SELECT p.*, 
                    pp_omr.price_monthly as omr_monthly, 
                    pp_omr.price_yearly as omr_yearly,
                    pp_usd.price_monthly as usd_monthly,
                    pp_usd.price_yearly as usd_yearly
             FROM subscription_plans p 
             LEFT JOIN plan_prices pp_omr ON p.id = pp_omr.plan_id AND pp_omr.currency = 'OMR'
             LEFT JOIN plan_prices pp_usd ON p.id = pp_usd.plan_id AND pp_usd.currency = 'USD'
             WHERE p.is_active = 1 
             ORDER BY p.sort_order, p.id"
        );
    }
} catch (Exception $e) {
    // Plans table might not exist yet, will use default display
    $subscriptionPlans = [];
}

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

$extraHead = $homeJsonLd . '<style>
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
    ['href' => '#features', 'label' => 'Features'],
    ['href' => '#pricing', 'label' => 'Pricing'],
    ['href' => getBasePath() . 'tools', 'label' => 'Free Tools'],
    ['href' => getBasePath() . 'oman-business-index', 'label' => 'Oman Business Index'],
    ['href' => getBasePath() . 'blog', 'label' => 'Blog'],
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
        <div class="max-w-7xl mx-auto" x-data="{ annual: false }">
            <!-- Section Header -->
            <div class="text-center mb-12">
                <span class="inline-flex items-center gap-2 py-1 px-3 mb-4 text-xs font-semibold text-blue-700 bg-blue-100 rounded-full uppercase tracking-wide">
                    <?= htmlspecialchars(t('pricing.home_kicker')) ?>
                </span>
                <p class="text-sm font-semibold uppercase tracking-wider text-blue-600 mb-3"><?= htmlspecialchars(t('pricing.home_kicker')) ?></p>
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-bold tracking-tight text-gray-900 mb-4"><?= htmlspecialchars(t('pricing.home_headline')) ?></h2>
                <p class="text-lg text-gray-600 max-w-2xl mx-auto"><?= htmlspecialchars(t('pricing.home_subhead')) ?></p>

                <!-- Billing Toggle -->
                <div class="mt-8 inline-flex items-center gap-3 bg-gray-100 rounded-full p-1">
                    <button @click="annual = false" :class="!annual ? 'bg-white shadow text-gray-900' : 'text-gray-500'" class="px-5 py-2 rounded-full text-sm font-semibold transition-all"><?= htmlspecialchars(t('pricing.home_toggle_month')) ?></button>
                    <button @click="annual = true" :class="annual ? 'bg-white shadow text-gray-900' : 'text-gray-500'" class="px-5 py-2 rounded-full text-sm font-semibold transition-all">
                        <?= htmlspecialchars(t('pricing.home_toggle_annual')) ?> <span class="text-green-600 text-xs font-bold ml-1"><?= htmlspecialchars(t('pricing.home_toggle_save')) ?></span>
                    </button>
                </div>
            </div>

            <!-- Pricing Grid -->
            <div class="cardify-pricing-grid">
                <!-- Starter -->
                <div class="cardify-plan">
                    <div class="cardify-plan-header">
                        <h3 class="cardify-plan-name"><?= htmlspecialchars(t('pricing.starter_name')) ?></h3>
                        <p class="cardify-plan-tagline"><?= htmlspecialchars(t('pricing.home_starter_tag')) ?></p>
                    </div>
                    <div class="cardify-plan-price">
                        <span class="cardify-plan-price-value"><?= htmlspecialchars(t('pricing.home_starter_price')) ?></span>
                        <p class="cardify-plan-price-sub"><?= htmlspecialchars(t('pricing.home_starter_sub')) ?></p>
                    </div>
                    <ul class="cardify-plan-features">
                        <li><i class="fa-solid fa-check cardify-tick"></i><?= htmlspecialchars(t('pricing.home_starter_f1')) ?></li>
                        <li><i class="fa-solid fa-check cardify-tick"></i><?= htmlspecialchars(t('pricing.home_starter_f2')) ?></li>
                        <li><i class="fa-solid fa-check cardify-tick"></i><?= htmlspecialchars(t('pricing.home_starter_f3')) ?></li>
                        <li><i class="fa-solid fa-check cardify-tick"></i><?= htmlspecialchars(t('pricing.home_starter_f4')) ?></li>
                    </ul>
                    <a href="<?= getBasePath() ?>company/register.php" class="cardify-plan-cta cardify-plan-cta--ghost"><?= htmlspecialchars(t('pricing.home_starter_cta')) ?></a>
                </div>

                <!-- Professional (Popular) -->
                <div class="cardify-plan cardify-plan--featured">
                    <span class="cardify-plan-badge"><?= htmlspecialchars(t('pricing.home_badge_popular')) ?></span>
                    <div class="cardify-plan-header">
                        <h3 class="cardify-plan-name"><?= htmlspecialchars(t('pricing.pro_name')) ?></h3>
                        <p class="cardify-plan-tagline"><?= htmlspecialchars(t('pricing.home_pro_tag')) ?></p>
                    </div>
                    <div class="cardify-plan-price">
                        <div class="cardify-plan-price-row">
                            <span class="cardify-plan-price-value cardify-plan-price-value--num" x-text="annual ? '4.167' : '5.000'">5.000</span>
                            <span class="cardify-plan-price-unit"><?= htmlspecialchars(t('pricing.home_unit_month')) ?></span>
                        </div>
                        <p class="cardify-plan-price-sub" x-show="annual"><?= htmlspecialchars(t('pricing.home_billed_year', ['amount' => '50.000'])) ?></p>
                        <p class="cardify-plan-price-sub" x-show="!annual"><?= htmlspecialchars(t('pricing.home_billed_month')) ?></p>
                    </div>
                    <ul class="cardify-plan-features">
                        <li><i class="fa-solid fa-check cardify-tick cardify-tick--accent"></i><?= htmlspecialchars(t('pricing.home_pro_f1')) ?></li>
                        <li><i class="fa-solid fa-check cardify-tick cardify-tick--accent"></i><?= htmlspecialchars(t('pricing.home_pro_f2')) ?></li>
                        <li><i class="fa-solid fa-check cardify-tick cardify-tick--accent"></i><?= htmlspecialchars(t('pricing.home_pro_f3')) ?></li>
                        <li><i class="fa-solid fa-check cardify-tick cardify-tick--accent"></i><?= htmlspecialchars(t('pricing.home_pro_f4')) ?></li>
                        <li><i class="fa-solid fa-check cardify-tick cardify-tick--accent"></i><?= htmlspecialchars(t('pricing.home_pro_f5')) ?></li>
                        <li><i class="fa-solid fa-check cardify-tick cardify-tick--accent"></i><?= htmlspecialchars(t('pricing.home_pro_f6')) ?></li>
                    </ul>
                    <a href="<?= getBasePath() ?>company/register.php?plan=professional" class="cardify-plan-cta cardify-plan-cta--primary"><?= htmlspecialchars(t('pricing.home_pro_cta')) ?></a>
                </div>

                <!-- Business -->
                <div class="cardify-plan">
                    <div class="cardify-plan-header">
                        <h3 class="cardify-plan-name"><?= htmlspecialchars(t('pricing.biz_name')) ?></h3>
                        <p class="cardify-plan-tagline"><?= htmlspecialchars(t('pricing.home_biz_tag')) ?></p>
                    </div>
                    <div class="cardify-plan-price">
                        <div class="cardify-plan-price-row">
                            <span class="cardify-plan-price-value cardify-plan-price-value--num" x-text="annual ? '12.500' : '15.000'">15.000</span>
                            <span class="cardify-plan-price-unit"><?= htmlspecialchars(t('pricing.home_unit_month')) ?></span>
                        </div>
                        <p class="cardify-plan-price-sub" x-show="annual"><?= htmlspecialchars(t('pricing.home_billed_year', ['amount' => '150.000'])) ?></p>
                        <p class="cardify-plan-price-sub" x-show="!annual"><?= htmlspecialchars(t('pricing.home_billed_month')) ?></p>
                    </div>
                    <ul class="cardify-plan-features">
                        <li><i class="fa-solid fa-check cardify-tick"></i><?= htmlspecialchars(t('pricing.home_biz_f1')) ?></li>
                        <li><i class="fa-solid fa-check cardify-tick"></i><?= htmlspecialchars(t('pricing.home_biz_f2')) ?></li>
                        <li><i class="fa-solid fa-check cardify-tick"></i><?= htmlspecialchars(t('pricing.home_biz_f3')) ?></li>
                        <li><i class="fa-solid fa-check cardify-tick"></i><?= htmlspecialchars(t('pricing.home_biz_f4')) ?></li>
                        <li><i class="fa-solid fa-check cardify-tick"></i><?= htmlspecialchars(t('pricing.home_biz_f5')) ?></li>
                        <li><i class="fa-solid fa-check cardify-tick"></i><?= htmlspecialchars(t('pricing.home_biz_f6')) ?></li>
                    </ul>
                    <a href="<?= getBasePath() ?>company/register.php?plan=business" class="cardify-plan-cta cardify-plan-cta--ghost"><?= htmlspecialchars(t('pricing.home_biz_cta')) ?></a>
                </div>

                <!-- Enterprise -->
                <div class="cardify-plan cardify-plan--dark">
                    <div class="cardify-plan-header">
                        <h3 class="cardify-plan-name"><?= htmlspecialchars(t('pricing.ent_name')) ?></h3>
                        <p class="cardify-plan-tagline"><?= htmlspecialchars(t('pricing.home_ent_tag')) ?></p>
                    </div>
                    <div class="cardify-plan-price">
                        <span class="cardify-plan-price-value"><?= htmlspecialchars(t('pricing.home_ent_price')) ?></span>
                        <p class="cardify-plan-price-sub"><?= htmlspecialchars(t('pricing.home_ent_sub')) ?></p>
                    </div>
                    <ul class="cardify-plan-features">
                        <li><i class="fa-solid fa-check cardify-tick cardify-tick--gold"></i><?= htmlspecialchars(t('pricing.home_ent_f1')) ?></li>
                        <li><i class="fa-solid fa-check cardify-tick cardify-tick--gold"></i><?= htmlspecialchars(t('pricing.home_ent_f2')) ?></li>
                        <li><i class="fa-solid fa-check cardify-tick cardify-tick--gold"></i><?= htmlspecialchars(t('pricing.home_ent_f3')) ?></li>
                        <li><i class="fa-solid fa-check cardify-tick cardify-tick--gold"></i><?= htmlspecialchars(t('pricing.home_ent_f4')) ?></li>
                        <li><i class="fa-solid fa-check cardify-tick cardify-tick--gold"></i><?= htmlspecialchars(t('pricing.home_ent_f5')) ?></li>
                        <li><i class="fa-solid fa-check cardify-tick cardify-tick--gold"></i><?= htmlspecialchars(t('pricing.home_ent_f6')) ?></li>
                    </ul>
                    <a href="https://wa.me/96899899100?text=Hi%2C%20I%27m%20interested%20in%20Cardify%20Enterprise" target="_blank" rel="noopener" class="cardify-plan-cta cardify-plan-cta--on-dark"><?= htmlspecialchars(t('pricing.home_ent_cta')) ?></a>
                </div>
            </div>

            <p class="text-center text-sm text-gray-500 mt-8"><?= htmlspecialchars(t('pricing.home_footnote')) ?></p>
        </div>
    </section>

    <!-- ========== TESTIMONIALS SECTION (Flowbite Style) ========== -->
    <section id="testimonials" class="py-16 lg:py-24 bg-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Section Header -->
            <div class="max-w-2xl mx-auto text-center mb-16">
                <p class="text-sm font-semibold uppercase tracking-wider text-blue-600 mb-3">Trusted in Oman</p>
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-bold tracking-tight text-gray-900 mb-4">
                    Loved by Omani businesses
                </h2>
                <p class="text-lg text-gray-600">
                    See what local companies have to say about Cardify.
                </p>
            </div>

            <!-- Testimonials Grid -->
            <div class="grid lg:grid-cols-2 gap-8">
                <!-- Testimonial 1 -->
                <figure class="bg-white rounded-2xl p-8 shadow-sm ring-1 ring-gray-200/70 hover:ring-blue-200 hover:shadow-lg transition-all">
                    <div class="mb-5 text-blue-500 text-2xl leading-none"><i class="fa-solid fa-quote-left"></i></div>
                    <blockquote class="mb-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">"Perfect for our growing team"</h3>
                        <p class="text-gray-600 leading-relaxed">
                            "We designed one template and now all 50 of our employees have professional cards. The Arabic support is excellent and the print ordering feature saved us so much time."
                        </p>
                    </blockquote>
                    <figcaption class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-full bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center text-white font-bold">
                            AA
                        </div>
                        <div>
                            <div class="font-semibold text-gray-900">Ahmed Al-Balushi</div>
                            <div class="text-sm text-gray-500">Managing Director, Muscat Trading</div>
                        </div>
                    </figcaption>
                </figure>

                <!-- Testimonial 2 -->
                <figure class="bg-white rounded-2xl p-8 shadow-sm ring-1 ring-gray-200/70 hover:ring-blue-200 hover:shadow-lg transition-all">
                    <div class="mb-5 text-blue-500 text-2xl leading-none"><i class="fa-solid fa-quote-left"></i></div>
                    <blockquote class="mb-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">"Finally, cards that represent our brand"</h3>
                        <p class="text-gray-600 leading-relaxed">
                            "The visual editor is amazing. We created bilingual cards that perfectly match our brand guidelines. Our sales team loves the QR code tracking feature."
                        </p>
                    </blockquote>
                    <figcaption class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-full bg-gradient-to-br from-amber-400 to-amber-500 flex items-center justify-center text-white font-bold">
                            FA
                        </div>
                        <div>
                            <div class="font-semibold text-gray-900">Fatima Al-Rashdi</div>
                            <div class="text-sm text-gray-500">Marketing Manager, Gulf Solutions</div>
                        </div>
                    </figcaption>
                </figure>

                <!-- Testimonial 3 -->
                <figure class="bg-white rounded-2xl p-8 shadow-sm ring-1 ring-gray-200/70 hover:ring-blue-200 hover:shadow-lg transition-all">
                    <div class="mb-5 text-blue-500 text-2xl leading-none"><i class="fa-solid fa-quote-left"></i></div>
                    <blockquote class="mb-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">"Free and feature-rich, unbelievable"</h3>
                        <p class="text-gray-600 leading-relaxed">
                            "I couldn't believe it was free! We've been using it for 6 months and only paid when we needed to print cards. The department feature helps us organize different teams perfectly."
                        </p>
                    </blockquote>
                    <figcaption class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-full bg-gradient-to-br from-purple-500 to-purple-600 flex items-center justify-center text-white font-bold">
                            KH
                        </div>
                        <div>
                            <div class="font-semibold text-gray-900">Khalid Al-Habsi</div>
                            <div class="text-sm text-gray-500">HR Director, Oman Tech Services</div>
                        </div>
                    </figcaption>
                </figure>

                <!-- Testimonial 4 -->
                <figure class="bg-white rounded-2xl p-8 shadow-sm ring-1 ring-gray-200/70 hover:ring-blue-200 hover:shadow-lg transition-all">
                    <div class="mb-5 text-blue-500 text-2xl leading-none"><i class="fa-solid fa-quote-left"></i></div>
                    <blockquote class="mb-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">"The employee portal is a game-changer"</h3>
                        <p class="text-gray-600 leading-relaxed">
                            "Our employees can now request their own cards through the portal. We just approve and print. It's reduced our admin work by 80%. Highly recommend for any Omani company."
                        </p>
                    </blockquote>
                    <figcaption class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-full bg-gradient-to-br from-green-500 to-green-600 flex items-center justify-center text-white font-bold">
                            SA
                        </div>
                        <div>
                            <div class="font-semibold text-gray-900">Sara Al-Kindi</div>
                            <div class="text-sm text-gray-500">Operations Lead, Salalah Enterprises</div>
                        </div>
                    </figcaption>
                </figure>
            </div>
        </div>
    </section>

    <!-- ========== FROM THE BLOG (SEO internal linking) ========== -->
    <?php if (!empty($latestPosts)): ?>
    <section class="py-16 lg:py-24 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-end justify-between mb-10 flex-wrap gap-4">
                <div>
                    <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-900 mb-2">From the Blog</h2>
                    <p class="text-lg text-gray-600">Practical guides for Omani professionals and teams.</p>
                </div>
                <a href="<?= getBasePath() ?>blog" class="inline-flex items-center gap-2 text-blue-600 hover:text-blue-700 font-semibold">
                    View all posts
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
    <section id="resources" class="py-16 lg:py-24 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12">
                <span class="inline-block text-sm font-semibold text-blue-600 uppercase tracking-wider mb-3">Free for everyone</span>
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-gray-900 mb-4">
                    Free Tools &amp; Oman Business Directory
                </h2>
                <p class="text-lg text-gray-600 max-w-3xl mx-auto">
                    Use our free business card tools, or browse the public directory of 2,414 Omani enterprises by sector and governorate.
                </p>
            </div>

            <div class="grid md:grid-cols-2 gap-8 mb-12">
                <!-- Free Tools -->
                <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-2xl p-8 border border-blue-100">
                    <div class="flex items-center gap-3 mb-6">
                        <div class="w-12 h-12 rounded-xl bg-blue-600 text-white flex items-center justify-center">
                            <i class="fa-solid fa-toolbox text-xl"></i>
                        </div>
                        <h3 class="text-2xl font-bold text-gray-900">Free Tools</h3>
                    </div>
                    <p class="text-gray-600 mb-6">Generate what you need in seconds — no sign-up required.</p>
                    <ul class="space-y-3 mb-6">
                        <li>
                            <a href="/tools/vcard-qr-generator" class="flex items-start gap-3 p-3 rounded-lg hover:bg-white transition group">
                                <i class="fa-solid fa-qrcode text-blue-600 mt-1"></i>
                                <div>
                                    <div class="font-semibold text-gray-900 group-hover:text-blue-700">vCard QR Generator</div>
                                    <div class="text-sm text-gray-500">Create a contact-card QR in seconds</div>
                                </div>
                            </a>
                        </li>
                        <li>
                            <a href="/tools/email-signature-generator" class="flex items-start gap-3 p-3 rounded-lg hover:bg-white transition group">
                                <i class="fa-solid fa-envelope text-blue-600 mt-1"></i>
                                <div>
                                    <div class="font-semibold text-gray-900 group-hover:text-blue-700">Email Signature Generator</div>
                                    <div class="text-sm text-gray-500">Branded EN/AR signatures for Gmail &amp; Outlook</div>
                                </div>
                            </a>
                        </li>
                        <li>
                            <a href="/tools/whatsapp-qr-generator" class="flex items-start gap-3 p-3 rounded-lg hover:bg-white transition group">
                                <i class="fa-brands fa-whatsapp text-blue-600 mt-1"></i>
                                <div>
                                    <div class="font-semibold text-gray-900 group-hover:text-blue-700">WhatsApp QR Generator</div>
                                    <div class="text-sm text-gray-500">Chat-link QR codes with pre-filled message</div>
                                </div>
                            </a>
                        </li>
                        <li>
                            <a href="/tools/nfc-business-card-guide" class="flex items-start gap-3 p-3 rounded-lg hover:bg-white transition group">
                                <i class="fa-solid fa-wifi text-blue-600 mt-1"></i>
                                <div>
                                    <div class="font-semibold text-gray-900 group-hover:text-blue-700">NFC Business Card Guide</div>
                                    <div class="text-sm text-gray-500">How tap-to-share works, step by step</div>
                                </div>
                            </a>
                        </li>
                    </ul>
                    <a href="/tools" class="inline-flex items-center gap-2 text-blue-700 font-semibold hover:text-blue-800">
                        Browse all free tools
                        <i class="fa-solid fa-arrow-right text-xs"></i>
                    </a>
                </div>

                <!-- Oman Business Directory -->
                <div class="bg-gradient-to-br from-emerald-50 to-teal-50 rounded-2xl p-8 border border-emerald-100">
                    <div class="flex items-center gap-3 mb-6">
                        <div class="w-12 h-12 rounded-xl bg-emerald-600 text-white flex items-center justify-center">
                            <i class="fa-solid fa-building-columns text-xl"></i>
                        </div>
                        <h3 class="text-2xl font-bold text-gray-900">Oman Business Index</h3>
                    </div>
                    <p class="text-gray-600 mb-6">Free public directory of the 2,414 largest enterprises in Oman, sourced from MoCIIP.</p>
                    <div class="grid grid-cols-2 gap-2 mb-6">
                        <a href="/companies/sector/oil-gas" class="text-sm px-3 py-2 rounded-lg bg-white text-gray-700 hover:bg-emerald-100 hover:text-emerald-700 transition font-medium border border-gray-100">Oil &amp; Gas</a>
                        <a href="/companies/sector/construction" class="text-sm px-3 py-2 rounded-lg bg-white text-gray-700 hover:bg-emerald-100 hover:text-emerald-700 transition font-medium border border-gray-100">Construction</a>
                        <a href="/companies/sector/finance" class="text-sm px-3 py-2 rounded-lg bg-white text-gray-700 hover:bg-emerald-100 hover:text-emerald-700 transition font-medium border border-gray-100">Finance &amp; Banking</a>
                        <a href="/companies/sector/trading" class="text-sm px-3 py-2 rounded-lg bg-white text-gray-700 hover:bg-emerald-100 hover:text-emerald-700 transition font-medium border border-gray-100">Trading</a>
                        <a href="/companies/sector/manufacturing" class="text-sm px-3 py-2 rounded-lg bg-white text-gray-700 hover:bg-emerald-100 hover:text-emerald-700 transition font-medium border border-gray-100">Manufacturing</a>
                        <a href="/companies/sector/hospitality-tourism" class="text-sm px-3 py-2 rounded-lg bg-white text-gray-700 hover:bg-emerald-100 hover:text-emerald-700 transition font-medium border border-gray-100">Hospitality</a>
                        <a href="/companies/wilayat/muscat" class="text-sm px-3 py-2 rounded-lg bg-white text-gray-700 hover:bg-emerald-100 hover:text-emerald-700 transition font-medium border border-gray-100">Muscat</a>
                        <a href="/companies/wilayat/dhofar" class="text-sm px-3 py-2 rounded-lg bg-white text-gray-700 hover:bg-emerald-100 hover:text-emerald-700 transition font-medium border border-gray-100">Dhofar / Salalah</a>
                    </div>
                    <a href="/oman-business-index" class="inline-flex items-center gap-2 text-emerald-700 font-semibold hover:text-emerald-800">
                        Explore the full directory
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
                        <h3 class="text-xl font-bold text-gray-900">Solutions for Your Industry</h3>
                    </div>
                    <a href="/solutions" class="text-sm font-semibold text-purple-700 hover:text-purple-800">View all 20 solutions →</a>
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
                <span>Proudly supporting Omani businesses</span>
            </div>
            
            <h2 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-white mb-6">
                Create Your First Card in Minutes
            </h2>
            <p class="text-xl text-blue-100 mb-10 max-w-2xl mx-auto">
                Join hundreds of Omani companies using Cardify. Start free, upgrade as you grow.
            </p>

            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                <a href="<?php echo getBasePath(); ?>company/register.php" class="inline-flex items-center justify-center gap-2 px-8 py-4 bg-white hover:bg-gray-100 text-blue-600 font-bold rounded-xl shadow-xl transition-all hover:-translate-y-0.5 text-lg">
                    <i class="fa-solid fa-rocket"></i>
                    Start Your Free Trial
                </a>
                <a href="<?php echo getBasePath(); ?>intro" class="inline-flex items-center justify-center gap-2 px-8 py-4 bg-blue-500/20 hover:bg-blue-500/30 text-white font-semibold rounded-xl border-2 border-white/30 transition-all text-lg">
                    <i class="fa-solid fa-play-circle"></i>
                    See How It Works
                </a>
            </div>

            <div class="mt-10 flex flex-wrap justify-center gap-6 text-white/70 text-sm">
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-check-circle"></i>
                    <span>Free Starter Plan</span>
                </div>
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-check-circle"></i>
                    <span>14-Day Free Trial</span>
                </div>
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-check-circle"></i>
                    <span>Plans from 5 OMR/mo</span>
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
                    <h4 class="font-bold text-lg mb-6">Product</h4>
                    <ul class="space-y-3">
                        <li><a href="#features" class="text-gray-400 hover:text-white transition-colors">Features</a></li>
                        <li><a href="#pricing" class="text-gray-400 hover:text-white transition-colors">Pricing</a></li>
                        <li><a href="#resources" class="text-gray-400 hover:text-white transition-colors">Free Tools</a></li>
                        <li><a href="<?php echo getBasePath(); ?>company/register.php" class="text-gray-400 hover:text-white transition-colors">Get Started</a></li>
                    </ul>
                </div>

                <!-- Free Tools -->
                <div>
                    <h4 class="font-bold text-lg mb-6">Free Tools</h4>
                    <ul class="space-y-3">
                        <li><a href="<?php echo getBasePath(); ?>tools/vcard-qr-generator" class="text-gray-400 hover:text-white transition-colors">vCard QR Generator</a></li>
                        <li><a href="<?php echo getBasePath(); ?>tools/email-signature-generator" class="text-gray-400 hover:text-white transition-colors">Email Signature</a></li>
                        <li><a href="<?php echo getBasePath(); ?>tools/whatsapp-qr-generator" class="text-gray-400 hover:text-white transition-colors">WhatsApp QR</a></li>
                        <li><a href="<?php echo getBasePath(); ?>tools/nfc-business-card-guide" class="text-gray-400 hover:text-white transition-colors">NFC Card Guide</a></li>
                        <li><a href="<?php echo getBasePath(); ?>tools" class="text-gray-400 hover:text-white transition-colors">All Tools</a></li>
                    </ul>
                </div>

                <!-- Directory & Solutions -->
                <div>
                    <h4 class="font-bold text-lg mb-6">Directory &amp; Solutions</h4>
                    <ul class="space-y-3">
                        <li><a href="<?php echo getBasePath(); ?>oman-business-index" class="text-gray-400 hover:text-white transition-colors">Oman Business Index</a></li>
                        <li><a href="<?php echo getBasePath(); ?>companies" class="text-gray-400 hover:text-white transition-colors">Browse 2,414 Companies</a></li>
                        <li><a href="<?php echo getBasePath(); ?>companies/sector/oil-gas" class="text-gray-400 hover:text-white transition-colors">Oil &amp; Gas</a></li>
                        <li><a href="<?php echo getBasePath(); ?>companies/sector/construction" class="text-gray-400 hover:text-white transition-colors">Construction</a></li>
                        <li><a href="<?php echo getBasePath(); ?>companies/wilayat/muscat" class="text-gray-400 hover:text-white transition-colors">Muscat Companies</a></li>
                        <li><a href="<?php echo getBasePath(); ?>solutions" class="text-gray-400 hover:text-white transition-colors">All Solutions</a></li>
                    </ul>
                </div>

                <!-- Company + Legal -->
                <div>
                    <h4 class="font-bold text-lg mb-6">Company</h4>
                    <ul class="space-y-3">
                        <li><a href="<?php echo getBasePath(); ?>about" class="text-gray-400 hover:text-white transition-colors">About Us</a></li>
                        <li><a href="<?php echo getBasePath(); ?>blog" class="text-gray-400 hover:text-white transition-colors">Blog</a></li>
                        <li><a href="<?php echo getBasePath(); ?>careers" class="text-gray-400 hover:text-white transition-colors">Careers</a></li>
                        <li><a href="<?php echo getBasePath(); ?>contact" class="text-gray-400 hover:text-white transition-colors">Contact</a></li>
                        <li><a href="<?php echo getBasePath(); ?>privacy" class="text-gray-400 hover:text-white transition-colors">Privacy</a></li>
                        <li><a href="<?php echo getBasePath(); ?>terms" class="text-gray-400 hover:text-white transition-colors">Terms</a></li>
                    </ul>
                </div>
            </div>

            <!-- Bottom Bar -->
            <div class="pt-8 border-t border-gray-800 flex flex-col md:flex-row justify-between items-center gap-4">
                <p class="text-gray-500 text-sm">
                    &copy; <?php echo date('Y'); ?> <?php echo $brandName; ?>. All rights reserved.
                </p>
                <div class="flex items-center gap-6 text-sm text-gray-500">
                    <span class="flex items-center gap-2">
                        <i class="fa-solid fa-globe"></i>
                        English (US)
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
      "name": "Starter",
      "price": "0",
      "priceCurrency": "OMR",
      "description": "Free forever — up to 3 employees, digital cards with QR vCard, bilingual EN+AR, basic analytics.",
      "availability": "https://schema.org/InStock",
      "url": "https://cardify.om/get-started"
    },
    {
      "@type": "Offer",
      "name": "Professional",
      "price": "5",
      "priceCurrency": "OMR",
      "priceSpecification": {
        "@type": "UnitPriceSpecification",
        "price": "5",
        "priceCurrency": "OMR",
        "referenceQuantity": { "@type": "QuantitativeValue", "value": "1", "unitCode": "MON" }
      },
      "description": "For teams up to 10 employees — custom domain, team templates, printed card ordering, priority support.",
      "availability": "https://schema.org/InStock",
      "url": "https://cardify.om/get-started?plan=professional"
    },
    {
      "@type": "Offer",
      "name": "Business",
      "price": "15",
      "priceCurrency": "OMR",
      "priceSpecification": {
        "@type": "UnitPriceSpecification",
        "price": "15",
        "priceCurrency": "OMR",
        "referenceQuantity": { "@type": "QuantitativeValue", "value": "1", "unitCode": "MON" }
      },
      "description": "For teams up to 50 employees — department management, SSO, advanced analytics, bulk printing discounts.",
      "availability": "https://schema.org/InStock",
      "url": "https://cardify.om/get-started?plan=business"
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
    "highPrice": "15",
    "offerCount": "3",
    "availability": "https://schema.org/InStock"
  }
}
</script>

<?php
    require INCLUDES_DIR . '/ui-footer.php';
    ?>
