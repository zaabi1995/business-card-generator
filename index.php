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

// Custom Domain check — if the Host header maps to a verified custom domain,
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
$pageTitle = 'Cardify — Digital & Printed Business Cards in Oman';
$pageDescription = 'Create, manage, and print professional business cards for your team in Oman. Digital cards with QR codes, NFC sharing, and online ordering. Free to start.';
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
$siteLd = [
    '@context' => 'https://schema.org',
    '@type' => 'WebSite',
    'name' => 'Cardify',
    'url' => 'https://cardify.om/',
    'publisher' => ['@type' => 'Organization', 'name' => 'Cardify'],
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
    ['href' => getBasePath() . 'blog', 'label' => 'Blog'],
    ['href' => getBasePath() . 'about', 'label' => 'About'],
    ['href' => getBasePath() . 'contact', 'label' => 'Contact'],
];

// Include Auth for navigation state
require_once INCLUDES_DIR . '/Auth.php';
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
                        <span class="inline-flex items-center gap-1 bg-blue-50 text-blue-700 font-semibold text-xs px-3 py-1 rounded-full"><span>🇴🇲</span> Oman</span>
                        <span class="font-medium text-gray-700">Built for teams of 100–2000</span>
                    </div>

                    <!-- Headline -->
                    <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold tracking-tight leading-tight text-gray-900 mb-6">
                        One Template.
                        <span class="text-blue-600 block">Every Employee.</span>
                        <span class="text-gray-500 text-3xl sm:text-4xl lg:text-5xl">Done.</span>
                    </h1>

                    <!-- Subheadline -->
                    <p class="text-lg lg:text-xl text-gray-600 mb-8 max-w-xl mx-auto lg:mx-0 leading-relaxed">
                        Stop coordinating card orders manually. Upload your team, generate a unique card for every employee, and order professional prints — delivered across Oman.
                        <strong class="text-gray-900">From 6 OMR per design.</strong>
                    </p>

                    <!-- CTA Buttons -->
                    <div class="flex flex-col sm:flex-row gap-4 justify-center lg:justify-start mb-10">
                        <a href="<?php echo getBasePath(); ?>company/register.php" class="inline-flex items-center justify-center gap-2 px-7 py-4 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl shadow-lg shadow-blue-600/30 transition-all hover:shadow-xl hover:-translate-y-0.5 text-lg">
                            Start Free
                            <i class="fa-solid fa-arrow-right"></i>
                        </a>
                        <a href="https://wa.me/96899899100?text=Hi%2C%20I'd%20like%20a%20demo%20of%20Cardify%20for%20my%20company" target="_blank" rel="noopener" class="inline-flex items-center justify-center gap-2 px-7 py-4 bg-green-500 hover:bg-green-600 text-white font-semibold rounded-xl transition-all text-lg">
                            <i class="fa-brands fa-whatsapp"></i>
                            Request a Demo
                        </a>
                    </div>

                    <!-- Trust Badges -->
                    <div class="flex flex-wrap items-center justify-center lg:justify-start gap-3 text-sm">
                        <div class="flex items-center gap-2 px-3 py-1.5 bg-green-50 text-green-700 rounded-full">
                            <i class="fa-solid fa-circle-check"></i>
                            <span>Free to Design</span>
                        </div>
                        <div class="flex items-center gap-2 px-3 py-1.5 bg-blue-50 text-blue-700 rounded-full">
                            <i class="fa-solid fa-print"></i>
                            <span>Printed by BHD Muscat</span>
                        </div>
                        <div class="flex items-center gap-2 px-3 py-1.5 bg-purple-50 text-purple-700 rounded-full">
                            <i class="fa-solid fa-users"></i>
                            <span>Bulk CSV Import</span>
                        </div>
                        <div class="flex items-center gap-2 px-3 py-1.5 bg-amber-50 text-amber-700 rounded-full">
                            <i class="fa-solid fa-language"></i>
                            <span>Arabic + English</span>
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
                                <button class="p-2.5 bg-gray-100 text-gray-600 rounded-lg hover:bg-gray-200 transition">
                                    <i class="fa-solid fa-qrcode"></i>
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

    <!-- ========== VALUE PROPOSITION BANNER ========== -->
    <section class="py-12 bg-gradient-to-r from-blue-600 via-blue-700 to-indigo-700 text-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid md:grid-cols-3 gap-8 text-center">
                <div class="flex flex-col items-center">
                    <div class="w-14 h-14 bg-white/10 rounded-2xl flex items-center justify-center mb-4">
                        <i class="fa-solid fa-palette text-2xl"></i>
                    </div>
                    <h3 class="text-lg font-bold mb-2">Design Once</h3>
                    <p class="text-blue-100 text-sm">Create one template, generate cards for all your employees automatically</p>
                </div>
                <div class="flex flex-col items-center">
                    <div class="w-14 h-14 bg-white/10 rounded-2xl flex items-center justify-center mb-4">
                        <i class="fa-solid fa-print text-2xl"></i>
                    </div>
                    <h3 class="text-lg font-bold mb-2">Print Instantly</h3>
                    <p class="text-blue-100 text-sm">Order from verified local print shops directly from your dashboard</p>
                </div>
                <div class="flex flex-col items-center">
                    <div class="w-14 h-14 bg-white/10 rounded-2xl flex items-center justify-center mb-4">
                        <i class="fa-solid fa-gift text-2xl"></i>
                    </div>
                    <h3 class="text-lg font-bold mb-2">Start Free</h3>
                    <p class="text-blue-100 text-sm">Free starter plan with no credit card required. Upgrade when you grow</p>
                </div>
            </div>
        </div>
    </section>

    <!-- ========== FEATURES SECTION (Flowbite Style) ========== -->
    <section id="features" class="py-16 lg:py-24 bg-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Section Header -->
            <div class="mx-auto max-w-2xl text-center mb-16">
                <p class="text-sm font-semibold uppercase tracking-wider text-blue-600 mb-3">Powerful features</p>
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-bold tracking-tight text-gray-900 mb-4">
                    Everything you need to manage cards at scale
                </h2>
                <p class="text-lg leading-relaxed text-gray-600">
                    From design to print, Cardify provides all the tools your team needs to create professional business cards effortlessly.
                </p>
            </div>

            <!-- Features Grid -->
            <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8">
                <!-- Feature 1 - Design Once -->
                <div class="relative bg-white rounded-2xl p-8 shadow-lg shadow-gray-200/60 border border-gray-100 hover:shadow-xl transition-shadow group">
                    <div class="w-14 h-14 rounded-xl bg-blue-100 flex items-center justify-center mb-6 group-hover:bg-blue-600 transition-colors">
                        <i class="fa-solid fa-wand-magic-sparkles text-2xl text-blue-600 group-hover:text-white transition-colors"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Design Once, Use Forever</h3>
                    <p class="text-gray-600 leading-relaxed">
                        Create a single template with your brand design. Automatically generate cards for all employees with their unique details.
                    </p>
                </div>

                <!-- Feature 2 - Print Integration -->
                <div class="relative bg-white rounded-2xl p-8 shadow-lg shadow-gray-200/60 border border-gray-100 hover:shadow-xl transition-shadow group">
                    <div class="absolute -top-3 -right-3 px-2 py-1 bg-green-500 text-white text-xs font-bold rounded-full">New</div>
                    <div class="w-14 h-14 rounded-xl bg-green-100 flex items-center justify-center mb-6 group-hover:bg-green-600 transition-colors">
                        <i class="fa-solid fa-print text-2xl text-green-600 group-hover:text-white transition-colors"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Verified Print Shops</h3>
                    <p class="text-gray-600 leading-relaxed">
                        Order professional prints directly from verified local print shops. One click ordering with delivery across Oman.
                    </p>
                </div>

                <!-- Feature 3 - Bilingual -->
                <div class="relative bg-white rounded-2xl p-8 shadow-lg shadow-gray-200/60 border border-gray-100 hover:shadow-xl transition-shadow group">
                    <div class="w-14 h-14 rounded-xl bg-amber-100 flex items-center justify-center mb-6 group-hover:bg-amber-500 transition-colors">
                        <i class="fa-solid fa-language text-2xl text-amber-600 group-hover:text-white transition-colors"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Arabic & English</h3>
                    <p class="text-gray-600 leading-relaxed">
                        Full bilingual support with proper RTL formatting. AI-powered Arabic translation makes it easy.
                    </p>
                </div>

                <!-- Feature 4 - Team Management -->
                <div class="relative bg-white rounded-2xl p-8 shadow-lg shadow-gray-200/60 border border-gray-100 hover:shadow-xl transition-shadow group">
                    <div class="w-14 h-14 rounded-xl bg-purple-100 flex items-center justify-center mb-6 group-hover:bg-purple-600 transition-colors">
                        <i class="fa-solid fa-users text-2xl text-purple-600 group-hover:text-white transition-colors"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Team & Departments</h3>
                    <p class="text-gray-600 leading-relaxed">
                        Organize employees by department with unique templates for each. Bulk import or let employees self-register.
                    </p>
                </div>

                <!-- Feature 5 - QR Tracking -->
                <div class="relative bg-white rounded-2xl p-8 shadow-lg shadow-gray-200/60 border border-gray-100 hover:shadow-xl transition-shadow group">
                    <div class="w-14 h-14 rounded-xl bg-pink-100 flex items-center justify-center mb-6 group-hover:bg-pink-600 transition-colors">
                        <i class="fa-solid fa-qrcode text-2xl text-pink-600 group-hover:text-white transition-colors"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Smart QR Codes</h3>
                    <p class="text-gray-600 leading-relaxed">
                        Every card includes a trackable QR code. Know when and where your cards are being scanned with detailed analytics.
                    </p>
                </div>

                <!-- Feature 6 - Self Service Portal -->
                <div class="relative bg-white rounded-2xl p-8 shadow-lg shadow-gray-200/60 border border-gray-100 hover:shadow-xl transition-shadow group">
                    <div class="w-14 h-14 rounded-xl bg-red-100 flex items-center justify-center mb-6 group-hover:bg-red-600 transition-colors">
                        <i class="fa-solid fa-door-open text-2xl text-red-600 group-hover:text-white transition-colors"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Employee Portal</h3>
                    <p class="text-gray-600 leading-relaxed">
                        Employees can request their own cards through a branded self-service portal. Admins review and approve.
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
                <p class="text-sm font-semibold uppercase tracking-wider text-green-600 mb-3">Quick setup</p>
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-bold tracking-tight text-gray-900 mb-4">
                    Get started in three simple steps
                </h2>
                <p class="text-lg text-gray-600">
                    From signup to sharing your first card in under 5 minutes.
                </p>
            </div>

            <!-- Steps -->
            <div class="grid md:grid-cols-3 gap-8 lg:gap-12">
                <!-- Step 1 -->
                <div class="relative text-center group">
                    <div class="w-20 h-20 rounded-full bg-blue-600 text-white text-3xl font-bold flex items-center justify-center mx-auto mb-6 shadow-xl shadow-blue-600/30 group-hover:scale-110 transition-transform">
                        1
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Create Your Account</h3>
                    <p class="text-gray-600">Sign up with your email and set up your company profile in just a few clicks.</p>
                    
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
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Add Your Team</h3>
                    <p class="text-gray-600">Import employees individually or in bulk. Customize their card details and design.</p>
                    
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
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Print & Share</h3>
                    <p class="text-gray-600">Order prints from local Omani shops or share digital cards via QR code and WhatsApp.</p>
                </div>
            </div>

            <!-- CTA -->
            <div class="text-center mt-16">
                <a href="<?php echo getBasePath(); ?>company/register.php" class="inline-flex items-center gap-2 px-8 py-4 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl shadow-lg shadow-blue-600/30 transition-all text-lg">
                    Get Started Now
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
                        <img src="<?php echo assetUrl('images/landing/light-dash.png'); ?>" alt="Cardify Dashboard" class="w-full" loading="lazy">
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
                    Simple Pricing
                </span>
                <p class="text-sm font-semibold uppercase tracking-wider text-blue-600 mb-3">Simple pricing</p>
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-bold tracking-tight text-gray-900 mb-4">Plans for every team size</h2>
                <p class="text-lg text-gray-600 max-w-2xl mx-auto">Start free, upgrade when you need more. All prices in Omani Rial.</p>

                <!-- Billing Toggle -->
                <div class="mt-8 inline-flex items-center gap-3 bg-gray-100 rounded-full p-1">
                    <button @click="annual = false" :class="!annual ? 'bg-white shadow text-gray-900' : 'text-gray-500'" class="px-5 py-2 rounded-full text-sm font-semibold transition-all">Monthly</button>
                    <button @click="annual = true" :class="annual ? 'bg-white shadow text-gray-900' : 'text-gray-500'" class="px-5 py-2 rounded-full text-sm font-semibold transition-all">
                        Annual <span class="text-green-600 text-xs font-bold ml-1">Save 17%</span>
                    </button>
                </div>
            </div>

            <!-- Pricing Grid -->
            <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-6 max-w-6xl mx-auto">
                <!-- Starter -->
                <div class="bg-white rounded-2xl border border-gray-200 p-6 flex flex-col">
                    <div class="mb-6">
                        <h3 class="text-lg font-bold text-gray-900">Starter</h3>
                        <p class="text-sm text-gray-500 mt-1">For freelancers and solo professionals</p>
                    </div>
                    <div class="mb-6">
                        <div class="flex items-baseline gap-1">
                            <span class="text-4xl font-extrabold text-gray-900">Free</span>
                        </div>
                        <p class="text-sm text-gray-500 mt-1">No credit card required</p>
                    </div>
                    <ul class="space-y-3 mb-8 flex-1">
                        <li class="flex items-start gap-2 text-sm text-gray-600">
                            <i class="fa-solid fa-check text-green-500 mt-0.5 flex-shrink-0"></i>
                            Up to 3 team members
                        </li>
                        <li class="flex items-start gap-2 text-sm text-gray-600">
                            <i class="fa-solid fa-check text-green-500 mt-0.5 flex-shrink-0"></i>
                            Digital cards with QR code
                        </li>
                        <li class="flex items-start gap-2 text-sm text-gray-600">
                            <i class="fa-solid fa-check text-green-500 mt-0.5 flex-shrink-0"></i>
                            3 card templates
                        </li>
                        <li class="flex items-start gap-2 text-sm text-gray-600">
                            <i class="fa-solid fa-check text-green-500 mt-0.5 flex-shrink-0"></i>
                            Email support
                        </li>
                    </ul>
                    <a href="<?= getBasePath() ?>company/register.php" class="block text-center py-3 px-4 rounded-xl border-2 border-gray-200 text-gray-700 font-semibold hover:border-blue-300 hover:text-blue-600 transition-colors">
                        Get Started Free
                    </a>
                </div>

                <!-- Professional (Popular) -->
                <div class="bg-white rounded-2xl border-2 border-blue-600 p-6 flex flex-col relative shadow-lg shadow-blue-100">
                    <div class="absolute -top-3 left-1/2 -translate-x-1/2">
                        <span class="bg-blue-600 text-white text-xs font-bold px-3 py-1 rounded-full">Most Popular</span>
                    </div>
                    <div class="mb-6">
                        <h3 class="text-lg font-bold text-gray-900">Professional</h3>
                        <p class="text-sm text-gray-500 mt-1">For growing teams</p>
                    </div>
                    <div class="mb-6">
                        <div class="flex items-baseline gap-1">
                            <span class="text-4xl font-extrabold text-gray-900" x-text="annual ? '4.167' : '5.000'">5.000</span>
                            <span class="text-gray-500 text-sm font-medium">OMR/mo</span>
                        </div>
                        <p class="text-sm text-gray-500 mt-1" x-show="annual">Billed 50.000 OMR/year</p>
                        <p class="text-sm text-gray-500 mt-1" x-show="!annual">Billed monthly</p>
                    </div>
                    <ul class="space-y-3 mb-8 flex-1">
                        <li class="flex items-start gap-2 text-sm text-gray-600">
                            <i class="fa-solid fa-check text-blue-500 mt-0.5 flex-shrink-0"></i>
                            Up to 10 team members
                        </li>
                        <li class="flex items-start gap-2 text-sm text-gray-600">
                            <i class="fa-solid fa-check text-blue-500 mt-0.5 flex-shrink-0"></i>
                            Unlimited templates
                        </li>
                        <li class="flex items-start gap-2 text-sm text-gray-600">
                            <i class="fa-solid fa-check text-blue-500 mt-0.5 flex-shrink-0"></i>
                            Custom branding
                        </li>
                        <li class="flex items-start gap-2 text-sm text-gray-600">
                            <i class="fa-solid fa-check text-blue-500 mt-0.5 flex-shrink-0"></i>
                            CSV bulk import
                        </li>
                        <li class="flex items-start gap-2 text-sm text-gray-600">
                            <i class="fa-solid fa-check text-blue-500 mt-0.5 flex-shrink-0"></i>
                            Analytics dashboard
                        </li>
                        <li class="flex items-start gap-2 text-sm text-gray-600">
                            <i class="fa-solid fa-check text-blue-500 mt-0.5 flex-shrink-0"></i>
                            Priority support
                        </li>
                    </ul>
                    <a href="<?= getBasePath() ?>company/register.php?plan=professional" class="block text-center py-3 px-4 rounded-xl bg-blue-600 text-white font-semibold hover:bg-blue-700 transition-colors shadow-md">
                        Start Free Trial
                    </a>
                </div>

                <!-- Business -->
                <div class="bg-white rounded-2xl border border-gray-200 p-6 flex flex-col">
                    <div class="mb-6">
                        <h3 class="text-lg font-bold text-gray-900">Business</h3>
                        <p class="text-sm text-gray-500 mt-1">For scaling companies</p>
                    </div>
                    <div class="mb-6">
                        <div class="flex items-baseline gap-1">
                            <span class="text-4xl font-extrabold text-gray-900" x-text="annual ? '12.500' : '15.000'">15.000</span>
                            <span class="text-gray-500 text-sm font-medium">OMR/mo</span>
                        </div>
                        <p class="text-sm text-gray-500 mt-1" x-show="annual">Billed 150.000 OMR/year</p>
                        <p class="text-sm text-gray-500 mt-1" x-show="!annual">Billed monthly</p>
                    </div>
                    <ul class="space-y-3 mb-8 flex-1">
                        <li class="flex items-start gap-2 text-sm text-gray-600">
                            <i class="fa-solid fa-check text-green-500 mt-0.5 flex-shrink-0"></i>
                            Up to 50 team members
                        </li>
                        <li class="flex items-start gap-2 text-sm text-gray-600">
                            <i class="fa-solid fa-check text-green-500 mt-0.5 flex-shrink-0"></i>
                            Everything in Professional
                        </li>
                        <li class="flex items-start gap-2 text-sm text-gray-600">
                            <i class="fa-solid fa-check text-green-500 mt-0.5 flex-shrink-0"></i>
                            Print ordering integration
                        </li>
                        <li class="flex items-start gap-2 text-sm text-gray-600">
                            <i class="fa-solid fa-check text-green-500 mt-0.5 flex-shrink-0"></i>
                            NFC card support
                        </li>
                        <li class="flex items-start gap-2 text-sm text-gray-600">
                            <i class="fa-solid fa-check text-green-500 mt-0.5 flex-shrink-0"></i>
                            Dedicated account manager
                        </li>
                        <li class="flex items-start gap-2 text-sm text-gray-600">
                            <i class="fa-solid fa-check text-green-500 mt-0.5 flex-shrink-0"></i>
                            API access
                        </li>
                    </ul>
                    <a href="<?= getBasePath() ?>company/register.php?plan=business" class="block text-center py-3 px-4 rounded-xl border-2 border-gray-200 text-gray-700 font-semibold hover:border-blue-300 hover:text-blue-600 transition-colors">
                        Start Free Trial
                    </a>
                </div>

                <!-- Enterprise -->
                <div class="bg-gradient-to-br from-gray-900 to-gray-800 rounded-2xl p-6 flex flex-col text-white">
                    <div class="mb-6">
                        <h3 class="text-lg font-bold">Enterprise</h3>
                        <p class="text-sm text-gray-400 mt-1">For large organisations</p>
                    </div>
                    <div class="mb-6">
                        <div class="flex items-baseline gap-1">
                            <span class="text-4xl font-extrabold">Custom</span>
                        </div>
                        <p class="text-sm text-gray-400 mt-1">Tailored to your needs</p>
                    </div>
                    <ul class="space-y-3 mb-8 flex-1">
                        <li class="flex items-start gap-2 text-sm text-gray-300">
                            <i class="fa-solid fa-check text-amber-400 mt-0.5 flex-shrink-0"></i>
                            Unlimited employees
                        </li>
                        <li class="flex items-start gap-2 text-sm text-gray-300">
                            <i class="fa-solid fa-check text-amber-400 mt-0.5 flex-shrink-0"></i>
                            Everything in Business
                        </li>
                        <li class="flex items-start gap-2 text-sm text-gray-300">
                            <i class="fa-solid fa-check text-amber-400 mt-0.5 flex-shrink-0"></i>
                            Custom integrations
                        </li>
                        <li class="flex items-start gap-2 text-sm text-gray-300">
                            <i class="fa-solid fa-check text-amber-400 mt-0.5 flex-shrink-0"></i>
                            SLA guarantee
                        </li>
                        <li class="flex items-start gap-2 text-sm text-gray-300">
                            <i class="fa-solid fa-check text-amber-400 mt-0.5 flex-shrink-0"></i>
                            White-label options
                        </li>
                        <li class="flex items-start gap-2 text-sm text-gray-300">
                            <i class="fa-solid fa-check text-amber-400 mt-0.5 flex-shrink-0"></i>
                            On-premise deployment
                        </li>
                    </ul>
                    <a href="https://wa.me/96899899100?text=Hi%2C%20I%27m%20interested%20in%20Cardify%20Enterprise" target="_blank" rel="noopener" class="block text-center py-3 px-4 rounded-xl bg-white text-gray-900 font-semibold hover:bg-gray-100 transition-colors">
                        Contact Sales
                    </a>
                </div>
            </div>

            <p class="text-center text-sm text-gray-500 mt-8">All plans include a 14-day free trial. No credit card required to start.</p>
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
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">"Free and feature-rich — unbelievable"</h3>
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
                        <img src="<?= getBasePath() . $img ?>" alt="<?= htmlspecialchars($post['title']) ?>" class="w-full h-full object-cover group-hover:scale-105 transition-transform" loading="lazy">
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
            <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-12 mb-12">
                <!-- Brand -->
                <div class="lg:col-span-1">
                    <div class="flex items-center gap-3 mb-6">
                        <img src="<?php echo assetUrl('images/logo-light.svg'); ?>" alt="<?php echo $brandName; ?>" class="h-10 w-auto">
                    </div>
                    <p class="text-gray-400 mb-4 leading-relaxed">
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
                    <ul class="space-y-4">
                        <li><a href="#features" class="text-gray-400 hover:text-white transition-colors">Features</a></li>
                        <li><a href="#how-it-works" class="text-gray-400 hover:text-white transition-colors">How it Works</a></li>
                        <li><a href="<?php echo getBasePath(); ?>company/register.php" class="text-gray-400 hover:text-white transition-colors">Get Started</a></li>
                    </ul>
                </div>

                <!-- Company Links -->
                <div>
                    <h4 class="font-bold text-lg mb-6">Company</h4>
                    <ul class="space-y-4">
                        <li><a href="<?php echo getBasePath(); ?>about" class="text-gray-400 hover:text-white transition-colors">About Us</a></li>
                        <li><a href="<?php echo getBasePath(); ?>blog" class="text-gray-400 hover:text-white transition-colors">Blog</a></li>
                        <li><a href="<?php echo getBasePath(); ?>careers" class="text-gray-400 hover:text-white transition-colors">Careers</a></li>
                        <li><a href="<?php echo getBasePath(); ?>contact" class="text-gray-400 hover:text-white transition-colors">Contact</a></li>
                    </ul>
                </div>

                <!-- Legal Links -->
                <div>
                    <h4 class="font-bold text-lg mb-6">Legal</h4>
                    <ul class="space-y-4">
                        <li><a href="<?php echo getBasePath(); ?>privacy" class="text-gray-400 hover:text-white transition-colors">Privacy Policy</a></li>
                        <li><a href="<?php echo getBasePath(); ?>terms" class="text-gray-400 hover:text-white transition-colors">Terms of Service</a></li>
                        <li><a href="<?php echo getBasePath(); ?>cookies" class="text-gray-400 hover:text-white transition-colors">Cookie Policy</a></li>
                        <li><a href="<?php echo getBasePath(); ?>security" class="text-gray-400 hover:text-white transition-colors">Security</a></li>
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
  "url": "https://cardify.om",
  "logo": "https://cardify.om/assets/images/logo.svg",
  "description": "SaaS platform for creating and managing professional digital and printed business cards in Oman",
  "address": {
    "@type": "PostalAddress",
    "addressCountry": "OM",
    "addressLocality": "Muscat"
  },
  "sameAs": ["https://instagram.com/cardifyom"],
  "contactPoint": {
    "@type": "ContactPoint",
    "contactType": "customer service",
    "url": "https://cardify.om/contact"
  }
}
</script>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "SoftwareApplication",
  "name": "Cardify",
  "applicationCategory": "BusinessApplication",
  "operatingSystem": "Web",
  "url": "https://cardify.om",
  "offers": {
    "@type": "Offer",
    "price": "0",
    "priceCurrency": "OMR",
    "description": "Starter plan free — up to 3 employees. Professional from 5 OMR/mo for 10 employees. Business 15 OMR/mo for 50 employees."
  }
}
</script>

<?php
    require INCLUDES_DIR . '/ui-footer.php';
    ?>
