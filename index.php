<?php
/**
 * Cardify - Professional Digital Business Cards
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
                                        $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                                            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                                            mt_rand(0, 0xffff),
                                            mt_rand(0, 0x0fff) | 0x4000,
                                            mt_rand(0, 0x3fff) | 0x8000,
                                            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
                                        );
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
$tagline = 'Professional Digital Business Cards';
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $brandName; ?> - <?php echo $tagline; ?></title>
    <meta name="description" content="Create stunning digital business cards for your entire organization. Easy to manage, beautiful to share.">
    <link rel="icon" href="<?php echo getBasePath(); ?>favicon.svg" type="image/svg+xml">
    <link rel="alternate icon" href="<?php echo getBasePath(); ?>favicon.ico">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Font Awesome Pro (Local) -->
    <link rel="stylesheet" href="<?php echo getBasePath(); ?>assets/vendor/css/all.css">
    <link rel="stylesheet" href="<?php echo getBasePath(); ?>assets/vendor/css/sharp-solid.css">
    <link rel="stylesheet" href="<?php echo getBasePath(); ?>assets/vendor/css/sharp-regular.css">
    <link rel="stylesheet" href="<?php echo getBasePath(); ?>assets/vendor/css/sharp-light.css">
    <link rel="stylesheet" href="<?php echo getBasePath(); ?>assets/vendor/css/duotone.css">
    
    <!-- Techwind CSS (pre-built Tailwind) -->
    <link rel="stylesheet" href="<?php echo assetUrl('techwind/css/tailwind.css'); ?>">
    
    <!-- Custom Overrides -->
    <link rel="stylesheet" href="<?php echo assetUrl('css/cardify-overrides.css'); ?>?v=<?php echo filemtime(ASSETS_DIR . '/css/cardify-overrides.css'); ?>">
    
    <style>
        body { font-family: 'Inter', ui-sans-serif, system-ui, sans-serif; }
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
    </style>
</head>
<body class="bg-white text-gray-900 antialiased">

    <!-- ========== NAVIGATION ========== -->
    <nav class="fixed top-0 left-0 right-0 z-50 bg-white/80 bg-blur border-b border-gray-100 transition-all duration-300" id="navbar">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16 lg:h-20">
                <!-- Logo -->
                <a href="#" class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-600 to-blue-700 shadow-lg shadow-blue-600/30 flex items-center justify-center">
                        <img src="<?php echo assetUrl('images/logo.svg'); ?>" alt="<?php echo $brandName; ?>" class="w-8 h-8">
                    </div>
                    <span class="text-xl font-bold text-gray-900"><?php echo $brandName; ?></span>
                </a>

                <!-- Desktop Navigation -->
                <div class="hidden lg:flex items-center gap-8">
                    <a href="#features" class="text-gray-600 hover:text-blue-600 transition-colors font-medium">Features</a>
                    <a href="#how-it-works" class="text-gray-600 hover:text-blue-600 transition-colors font-medium">How it Works</a>
                    <a href="#pricing" class="text-gray-600 hover:text-blue-600 transition-colors font-medium">Pricing</a>
                    <a href="#testimonials" class="text-gray-600 hover:text-blue-600 transition-colors font-medium">Testimonials</a>
                    <a href="#contact" class="text-gray-600 hover:text-blue-600 transition-colors font-medium">Contact</a>
                </div>

                <!-- CTA Buttons -->
                <div class="flex items-center gap-3">
                    <a href="<?php echo getBasePath(); ?>login.php" class="hidden sm:inline-flex items-center px-4 py-2 text-gray-700 hover:text-blue-600 font-medium transition-colors">
                        Sign In
                    </a>
                    <a href="<?php echo getBasePath(); ?>company/register.php" class="inline-flex items-center px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg shadow-lg shadow-blue-600/30 transition-all hover:shadow-xl hover:shadow-blue-600/40">
                        Get Started Free
                    </a>
                    
                    <!-- Mobile Menu Button -->
                    <button type="button" class="lg:hidden p-2 text-gray-600 hover:text-blue-600" id="mobile-menu-btn">
                        <i class="fa-solid fa-bars text-xl"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- Mobile Menu -->
        <div class="lg:hidden hidden bg-white border-t border-gray-100 py-4" id="mobile-menu">
            <div class="max-w-7xl mx-auto px-4 space-y-3">
                <a href="#features" class="block py-2 text-gray-600 hover:text-blue-600 font-medium">Features</a>
                <a href="#how-it-works" class="block py-2 text-gray-600 hover:text-blue-600 font-medium">How it Works</a>
                <a href="#pricing" class="block py-2 text-gray-600 hover:text-blue-600 font-medium">Pricing</a>
                <a href="#testimonials" class="block py-2 text-gray-600 hover:text-blue-600 font-medium">Testimonials</a>
                <a href="#contact" class="block py-2 text-gray-600 hover:text-blue-600 font-medium">Contact</a>
                <hr class="border-gray-200">
                <a href="<?php echo getBasePath(); ?>login.php" class="block py-2 text-gray-600 hover:text-blue-600 font-medium">Sign In</a>
            </div>
        </div>
    </nav>

    <!-- ========== HERO SECTION (Flowbite Style) ========== -->
    <section class="hero-gradient pt-28 lg:pt-36 pb-16 lg:pb-24 overflow-hidden">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid lg:grid-cols-12 gap-8 lg:gap-12 items-center">
                <!-- Left Content -->
                <div class="lg:col-span-6 text-center lg:text-left">
                    <!-- Badge -->
                    <a href="#features" class="inline-flex items-center gap-2 py-1.5 px-4 pr-5 mb-6 text-sm text-amber-800 bg-amber-100 rounded-full hover:bg-amber-200 transition-colors">
                        <span class="bg-amber-600 text-white text-xs font-bold px-2.5 py-1 rounded-full">New</span>
                        <span class="font-medium">Introducing Smart Card Analytics</span>
                        <i class="fa-solid fa-chevron-right text-xs ml-1"></i>
                    </a>

                    <!-- Headline -->
                    <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold tracking-tight leading-tight text-gray-900 mb-6">
                        Digital Business Cards
                        <span class="text-blue-600 block">Made Simple</span>
                    </h1>

                    <!-- Subheadline -->
                    <p class="text-lg lg:text-xl text-gray-600 mb-8 max-w-xl mx-auto lg:mx-0 leading-relaxed">
                        Create, manage, and share beautiful digital business cards for your entire organization. Professional, modern, and effortlessly easy.
                    </p>

                    <!-- CTA Buttons -->
                    <div class="flex flex-col sm:flex-row gap-4 justify-center lg:justify-start mb-10">
                        <a href="<?php echo getBasePath(); ?>company/register.php" class="inline-flex items-center justify-center gap-2 px-7 py-4 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl shadow-lg shadow-blue-600/30 transition-all hover:shadow-xl text-lg">
                            Start Free Trial
                            <i class="fa-solid fa-arrow-right"></i>
                        </a>
                        <a href="#how-it-works" class="inline-flex items-center justify-center gap-2 px-7 py-4 bg-white hover:bg-gray-50 text-gray-900 font-semibold rounded-xl border-2 border-gray-200 transition-all text-lg">
                            <i class="fa-solid fa-play-circle text-blue-600"></i>
                            Watch Demo
                        </a>
                    </div>

                    <!-- Trust Badges -->
                    <div class="flex flex-wrap items-center justify-center lg:justify-start gap-x-6 gap-y-3 text-sm text-gray-500">
                        <div class="flex items-center gap-2">
                            <i class="fa-solid fa-circle-check text-green-500"></i>
                            <span>No credit card required</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <i class="fa-solid fa-circle-check text-green-500"></i>
                            <span>14-day free trial</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <i class="fa-solid fa-circle-check text-green-500"></i>
                            <span>Cancel anytime</span>
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

    <!-- ========== LOGO CLOUD (Flowbite Style) ========== -->
    <section class="py-10 bg-white border-y border-gray-100">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <p class="text-center text-sm font-semibold text-gray-400 uppercase tracking-wider mb-8">Trusted by teams at leading companies</p>
            <div class="flex flex-wrap items-center justify-center gap-x-12 gap-y-6 opacity-60 grayscale">
                <div class="text-2xl font-bold text-gray-900">TechCorp</div>
                <div class="text-2xl font-bold text-gray-900">Innovate</div>
                <div class="text-2xl font-bold text-gray-900">StartupXYZ</div>
                <div class="text-2xl font-bold text-gray-900">DesignCo</div>
                <div class="text-2xl font-bold text-gray-900">MediaHub</div>
            </div>
        </div>
    </section>

    <!-- ========== FEATURES SECTION (Flowbite Style) ========== -->
    <section id="features" class="py-16 lg:py-24 bg-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Section Header -->
            <div class="max-w-3xl mb-16 text-center lg:text-left">
                <span class="inline-flex items-center gap-2 py-1 px-3 mb-4 text-xs font-semibold text-blue-700 bg-blue-100 rounded-full uppercase tracking-wide">
                    <i class="fa-solid fa-sparkles"></i>
                    Powerful Features
                </span>
                <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-900 mb-4">
                    Everything you need to manage digital business cards
                </h2>
                <p class="text-lg text-gray-600">
                    From creation to analytics, Cardify provides all the tools your team needs to make networking effortless.
                </p>
            </div>

            <!-- Features Grid -->
            <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8">
                <!-- Feature 1 -->
                <div class="relative bg-white rounded-2xl p-8 shadow-lg shadow-gray-200/60 border border-gray-100 hover:shadow-xl transition-shadow group">
                    <div class="w-14 h-14 rounded-xl bg-blue-100 flex items-center justify-center mb-6 group-hover:bg-blue-600 transition-colors">
                        <i class="fa-solid fa-building text-2xl text-blue-600 group-hover:text-white transition-colors"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Multi-Company Support</h3>
                    <p class="text-gray-600 leading-relaxed">
                        Manage multiple organizations from a single dashboard. Perfect for agencies and enterprise teams with multiple brands.
                    </p>
                </div>

                <!-- Feature 2 -->
                <div class="relative bg-white rounded-2xl p-8 shadow-lg shadow-gray-200/60 border border-gray-100 hover:shadow-xl transition-shadow group">
                    <div class="w-14 h-14 rounded-xl bg-amber-100 flex items-center justify-center mb-6 group-hover:bg-amber-500 transition-colors">
                        <i class="fa-solid fa-palette text-2xl text-amber-600 group-hover:text-white transition-colors"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Custom Templates</h3>
                    <p class="text-gray-600 leading-relaxed">
                        Design beautiful card templates that match your brand. Full control over colors, fonts, layouts and more.
                    </p>
                </div>

                <!-- Feature 3 -->
                <div class="relative bg-white rounded-2xl p-8 shadow-lg shadow-gray-200/60 border border-gray-100 hover:shadow-xl transition-shadow group">
                    <div class="w-14 h-14 rounded-xl bg-green-100 flex items-center justify-center mb-6 group-hover:bg-green-600 transition-colors">
                        <i class="fa-solid fa-users text-2xl text-green-600 group-hover:text-white transition-colors"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Team Management</h3>
                    <p class="text-gray-600 leading-relaxed">
                        Add and organize employees by department. Bulk import from CSV or integrate with your HR systems.
                    </p>
                </div>

                <!-- Feature 4 -->
                <div class="relative bg-white rounded-2xl p-8 shadow-lg shadow-gray-200/60 border border-gray-100 hover:shadow-xl transition-shadow group">
                    <div class="w-14 h-14 rounded-xl bg-purple-100 flex items-center justify-center mb-6 group-hover:bg-purple-600 transition-colors">
                        <i class="fa-solid fa-share-nodes text-2xl text-purple-600 group-hover:text-white transition-colors"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Easy Sharing</h3>
                    <p class="text-gray-600 leading-relaxed">
                        Share cards via QR code, direct link, NFC, WhatsApp, or email. Recipients don't need an account.
                    </p>
                </div>

                <!-- Feature 5 -->
                <div class="relative bg-white rounded-2xl p-8 shadow-lg shadow-gray-200/60 border border-gray-100 hover:shadow-xl transition-shadow group">
                    <div class="w-14 h-14 rounded-xl bg-pink-100 flex items-center justify-center mb-6 group-hover:bg-pink-600 transition-colors">
                        <i class="fa-solid fa-chart-line text-2xl text-pink-600 group-hover:text-white transition-colors"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Analytics Dashboard</h3>
                    <p class="text-gray-600 leading-relaxed">
                        Track views, saves, and engagement. Understand how your cards perform with detailed analytics.
                    </p>
                </div>

                <!-- Feature 6 -->
                <div class="relative bg-white rounded-2xl p-8 shadow-lg shadow-gray-200/60 border border-gray-100 hover:shadow-xl transition-shadow group">
                    <div class="w-14 h-14 rounded-xl bg-red-100 flex items-center justify-center mb-6 group-hover:bg-red-600 transition-colors">
                        <i class="fa-solid fa-shield-halved text-2xl text-red-600 group-hover:text-white transition-colors"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Secure & Private</h3>
                    <p class="text-gray-600 leading-relaxed">
                        Enterprise-grade security with role-based access control. Your data is encrypted and protected.
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
                <span class="inline-flex items-center gap-2 py-1 px-3 mb-4 text-xs font-semibold text-green-700 bg-green-100 rounded-full uppercase tracking-wide">
                    <i class="fa-solid fa-rocket"></i>
                    Quick Setup
                </span>
                <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-900 mb-4">
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
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Share & Grow</h3>
                    <p class="text-gray-600">Share digital cards instantly via QR, link, or NFC. Track engagement in real-time.</p>
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
                        <img src="<?php echo assetUrl('images/landing/light-dash.png'); ?>" alt="Cardify Dashboard" class="w-full">
                    </div>
                    <!-- Decorative elements -->
                    <div class="absolute -top-4 -right-4 w-24 h-24 bg-amber-400 rounded-full opacity-20 blur-xl"></div>
                    <div class="absolute -bottom-8 -left-8 w-32 h-32 bg-green-400 rounded-full opacity-20 blur-xl"></div>
                </div>
            </div>
        </div>
    </section>

    <!-- ========== PRICING SECTION (Flowbite Style) ========== -->
    <section id="pricing" class="py-16 lg:py-24 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Section Header -->
            <div class="max-w-2xl mx-auto text-center mb-16">
                <span class="inline-flex items-center gap-2 py-1 px-3 mb-4 text-xs font-semibold text-amber-700 bg-amber-100 rounded-full uppercase tracking-wide">
                    <i class="fa-solid fa-tag"></i>
                    Simple Pricing
                </span>
                <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-900 mb-4">
                    Plans that scale with your team
                </h2>
                <p class="text-lg text-gray-600">
                    Start free, upgrade when you need more. No hidden fees, cancel anytime.
                </p>
            </div>

            <!-- Pricing Cards -->
            <div class="grid lg:grid-cols-3 gap-8 max-w-5xl mx-auto">
                <!-- Starter -->
                <div class="relative bg-white rounded-2xl p-8 border-2 border-gray-200 hover:border-blue-200 transition-colors">
                    <h3 class="text-xl font-bold text-gray-900 mb-2">Starter</h3>
                    <p class="text-gray-500 mb-6">Best for small teams getting started</p>
                    
                    <div class="flex items-baseline gap-1 mb-8">
                        <span class="text-5xl font-extrabold text-gray-900">$19</span>
                        <span class="text-gray-500">/month</span>
                    </div>

                    <ul class="space-y-4 mb-8">
                        <li class="flex items-center gap-3 text-gray-600">
                            <i class="fa-solid fa-check w-5 text-green-500"></i>
                            Up to 25 team members
                        </li>
                        <li class="flex items-center gap-3 text-gray-600">
                            <i class="fa-solid fa-check w-5 text-green-500"></i>
                            Custom card templates
                        </li>
                        <li class="flex items-center gap-3 text-gray-600">
                            <i class="fa-solid fa-check w-5 text-green-500"></i>
                            QR code sharing
                        </li>
                        <li class="flex items-center gap-3 text-gray-600">
                            <i class="fa-solid fa-check w-5 text-green-500"></i>
                            Basic analytics
                        </li>
                        <li class="flex items-center gap-3 text-gray-600">
                            <i class="fa-solid fa-check w-5 text-green-500"></i>
                            Email support
                        </li>
                    </ul>

                    <a href="<?php echo getBasePath(); ?>company/register.php" class="block w-full py-3 px-6 text-center bg-gray-100 hover:bg-gray-200 text-gray-900 font-semibold rounded-lg transition-colors">
                        Start Free Trial
                    </a>
                </div>

                <!-- Professional (Popular) -->
                <div class="relative bg-blue-600 rounded-2xl p-8 shadow-xl shadow-blue-600/30 scale-105">
                    <!-- Popular badge -->
                    <div class="absolute -top-4 left-1/2 -translate-x-1/2">
                        <span class="inline-flex items-center gap-1 px-3 py-1 bg-amber-400 text-amber-900 text-xs font-bold rounded-full">
                            <i class="fa-solid fa-star"></i>
                            Most Popular
                        </span>
                    </div>

                    <h3 class="text-xl font-bold text-white mb-2">Professional</h3>
                    <p class="text-blue-200 mb-6">Best for growing businesses</p>
                    
                    <div class="flex items-baseline gap-1 mb-8">
                        <span class="text-5xl font-extrabold text-white">$49</span>
                        <span class="text-blue-200">/month</span>
                    </div>

                    <ul class="space-y-4 mb-8">
                        <li class="flex items-center gap-3 text-blue-100">
                            <i class="fa-solid fa-check w-5 text-green-400"></i>
                            Up to 100 team members
                        </li>
                        <li class="flex items-center gap-3 text-blue-100">
                            <i class="fa-solid fa-check w-5 text-green-400"></i>
                            Advanced templates & branding
                        </li>
                        <li class="flex items-center gap-3 text-blue-100">
                            <i class="fa-solid fa-check w-5 text-green-400"></i>
                            NFC card integration
                        </li>
                        <li class="flex items-center gap-3 text-blue-100">
                            <i class="fa-solid fa-check w-5 text-green-400"></i>
                            Advanced analytics
                        </li>
                        <li class="flex items-center gap-3 text-blue-100">
                            <i class="fa-solid fa-check w-5 text-green-400"></i>
                            Priority support
                        </li>
                        <li class="flex items-center gap-3 text-blue-100">
                            <i class="fa-solid fa-check w-5 text-green-400"></i>
                            CSV/API import
                        </li>
                    </ul>

                    <a href="<?php echo getBasePath(); ?>company/register.php" class="block w-full py-3 px-6 text-center bg-white hover:bg-blue-50 text-blue-600 font-semibold rounded-lg transition-colors">
                        Start Free Trial
                    </a>
                </div>

                <!-- Enterprise -->
                <div class="relative bg-white rounded-2xl p-8 border-2 border-gray-200 hover:border-blue-200 transition-colors">
                    <h3 class="text-xl font-bold text-gray-900 mb-2">Enterprise</h3>
                    <p class="text-gray-500 mb-6">For large organizations</p>
                    
                    <div class="flex items-baseline gap-1 mb-8">
                        <span class="text-5xl font-extrabold text-gray-900">$149</span>
                        <span class="text-gray-500">/month</span>
                    </div>

                    <ul class="space-y-4 mb-8">
                        <li class="flex items-center gap-3 text-gray-600">
                            <i class="fa-solid fa-check w-5 text-green-500"></i>
                            Unlimited team members
                        </li>
                        <li class="flex items-center gap-3 text-gray-600">
                            <i class="fa-solid fa-check w-5 text-green-500"></i>
                            White-label options
                        </li>
                        <li class="flex items-center gap-3 text-gray-600">
                            <i class="fa-solid fa-check w-5 text-green-500"></i>
                            SSO / SAML integration
                        </li>
                        <li class="flex items-center gap-3 text-gray-600">
                            <i class="fa-solid fa-check w-5 text-green-500"></i>
                            Dedicated account manager
                        </li>
                        <li class="flex items-center gap-3 text-gray-600">
                            <i class="fa-solid fa-check w-5 text-green-500"></i>
                            Custom integrations
                        </li>
                        <li class="flex items-center gap-3 text-gray-600">
                            <i class="fa-solid fa-check w-5 text-green-500"></i>
                            SLA & uptime guarantee
                        </li>
                    </ul>

                    <a href="<?php echo getBasePath(); ?>company/register.php" class="block w-full py-3 px-6 text-center bg-gray-100 hover:bg-gray-200 text-gray-900 font-semibold rounded-lg transition-colors">
                        Contact Sales
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- ========== TESTIMONIALS SECTION (Flowbite Style) ========== -->
    <section id="testimonials" class="py-16 lg:py-24 bg-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Section Header -->
            <div class="max-w-2xl mx-auto text-center mb-16">
                <span class="inline-flex items-center gap-2 py-1 px-3 mb-4 text-xs font-semibold text-pink-700 bg-pink-100 rounded-full uppercase tracking-wide">
                    <i class="fa-solid fa-heart"></i>
                    Testimonials
                </span>
                <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-900 mb-4">
                    Loved by teams worldwide
                </h2>
                <p class="text-lg text-gray-600">
                    See what our customers have to say about Cardify.
                </p>
            </div>

            <!-- Testimonials Grid -->
            <div class="grid lg:grid-cols-2 gap-8">
                <!-- Testimonial 1 -->
                <figure class="bg-white rounded-2xl p-8 shadow-lg border border-gray-100">
                    <blockquote class="mb-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">"Finally, a solution that just works"</h3>
                        <p class="text-gray-600 leading-relaxed">
                            "We tried several digital card solutions before Cardify. The difference is night and day. Setup took 10 minutes, our entire team was onboarded within an hour, and the cards look absolutely professional."
                        </p>
                    </blockquote>
                    <figcaption class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-full bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center text-white font-bold">
                            JM
                        </div>
                        <div>
                            <div class="font-semibold text-gray-900">Jennifer Martinez</div>
                            <div class="text-sm text-gray-500">Head of Operations, TechStart</div>
                        </div>
                    </figcaption>
                </figure>

                <!-- Testimonial 2 -->
                <figure class="bg-white rounded-2xl p-8 shadow-lg border border-gray-100">
                    <blockquote class="mb-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">"Our sales team can't stop raving about it"</h3>
                        <p class="text-gray-600 leading-relaxed">
                            "The QR sharing feature is a game-changer at conferences. We've seen a 40% increase in follow-up connections since switching to Cardify. The analytics help us track which team members are most active."
                        </p>
                    </blockquote>
                    <figcaption class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-full bg-gradient-to-br from-amber-400 to-amber-500 flex items-center justify-center text-white font-bold">
                            RK
                        </div>
                        <div>
                            <div class="font-semibold text-gray-900">Robert Kim</div>
                            <div class="text-sm text-gray-500">VP Sales, GlobalCorp</div>
                        </div>
                    </figcaption>
                </figure>

                <!-- Testimonial 3 -->
                <figure class="bg-white rounded-2xl p-8 shadow-lg border border-gray-100">
                    <blockquote class="mb-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">"Beautiful design, zero learning curve"</h3>
                        <p class="text-gray-600 leading-relaxed">
                            "As a design-focused agency, we needed cards that reflected our brand quality. Cardify delivered beyond expectations. The customization options are fantastic and our clients love receiving them."
                        </p>
                    </blockquote>
                    <figcaption class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-full bg-gradient-to-br from-purple-500 to-purple-600 flex items-center justify-center text-white font-bold">
                            SW
                        </div>
                        <div>
                            <div class="font-semibold text-gray-900">Sarah Williams</div>
                            <div class="text-sm text-gray-500">Creative Director, DesignHub</div>
                        </div>
                    </figcaption>
                </figure>

                <!-- Testimonial 4 -->
                <figure class="bg-white rounded-2xl p-8 shadow-lg border border-gray-100">
                    <blockquote class="mb-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">"Enterprise features at a startup price"</h3>
                        <p class="text-gray-600 leading-relaxed">
                            "We manage 500+ employees across 3 offices. The bulk import and department organization features make administration a breeze. The ROI compared to printed cards is incredible."
                        </p>
                    </blockquote>
                    <figcaption class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-full bg-gradient-to-br from-green-500 to-green-600 flex items-center justify-center text-white font-bold">
                            ML
                        </div>
                        <div>
                            <div class="font-semibold text-gray-900">Michael Lee</div>
                            <div class="text-sm text-gray-500">IT Director, EnterpriseNow</div>
                        </div>
                    </figcaption>
                </figure>
            </div>
        </div>
    </section>

    <!-- ========== CTA SECTION (Flowbite Style) ========== -->
    <section class="py-16 lg:py-24 bg-gradient-to-br from-blue-600 to-indigo-700">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <h2 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-white mb-6">
                Ready to transform your business cards?
            </h2>
            <p class="text-xl text-blue-100 mb-10 max-w-2xl mx-auto">
                Join thousands of professionals already using Cardify to make lasting impressions. Start your free trial today.
            </p>
            
            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                <a href="<?php echo getBasePath(); ?>company/register.php" class="inline-flex items-center justify-center gap-2 px-8 py-4 bg-white hover:bg-gray-100 text-blue-600 font-bold rounded-xl shadow-xl transition-all text-lg">
                    <i class="fa-solid fa-rocket"></i>
                    Start Free Trial
                </a>
                <a href="<?php echo getBasePath(); ?>login.php" class="inline-flex items-center justify-center gap-2 px-8 py-4 bg-blue-500/20 hover:bg-blue-500/30 text-white font-semibold rounded-xl border-2 border-white/30 transition-all text-lg">
                    <i class="fa-solid fa-right-to-bracket"></i>
                    Sign In
                </a>
            </div>

            <p class="mt-8 text-blue-200 text-sm">
                <i class="fa-solid fa-shield-halved mr-2"></i>
                No credit card required. 14-day free trial. Cancel anytime.
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
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-blue-600 shadow-lg flex items-center justify-center">
                            <img src="<?php echo assetUrl('images/logo.svg'); ?>" alt="<?php echo $brandName; ?>" class="w-8 h-8">
                        </div>
                        <span class="text-xl font-bold"><?php echo $brandName; ?></span>
                    </div>
                    <p class="text-gray-400 mb-6 leading-relaxed">
                        The modern way to create and share professional business cards. Built for teams of all sizes.
                    </p>
                    <div class="flex gap-3">
                        <a href="#" class="w-10 h-10 rounded-lg bg-gray-800 hover:bg-blue-600 flex items-center justify-center transition-colors" aria-label="Twitter">
                            <i class="fa-brands fa-x-twitter"></i>
                        </a>
                        <a href="#" class="w-10 h-10 rounded-lg bg-gray-800 hover:bg-blue-600 flex items-center justify-center transition-colors" aria-label="LinkedIn">
                            <i class="fa-brands fa-linkedin-in"></i>
                        </a>
                        <a href="#" class="w-10 h-10 rounded-lg bg-gray-800 hover:bg-pink-600 flex items-center justify-center transition-colors" aria-label="Instagram">
                            <i class="fa-brands fa-instagram"></i>
                        </a>
                        <a href="#" class="w-10 h-10 rounded-lg bg-gray-800 hover:bg-blue-500 flex items-center justify-center transition-colors" aria-label="Facebook">
                            <i class="fa-brands fa-facebook-f"></i>
                        </a>
                    </div>
                </div>

                <!-- Product Links -->
                <div>
                    <h4 class="font-bold text-lg mb-6">Product</h4>
                    <ul class="space-y-4">
                        <li><a href="#features" class="text-gray-400 hover:text-white transition-colors">Features</a></li>
                        <li><a href="#pricing" class="text-gray-400 hover:text-white transition-colors">Pricing</a></li>
                        <li><a href="#how-it-works" class="text-gray-400 hover:text-white transition-colors">How it Works</a></li>
                        <li><a href="<?php echo getBasePath(); ?>company/register.php" class="text-gray-400 hover:text-white transition-colors">Get Started</a></li>
                    </ul>
                </div>

                <!-- Company Links -->
                <div>
                    <h4 class="font-bold text-lg mb-6">Company</h4>
                    <ul class="space-y-4">
                        <li><a href="#" class="text-gray-400 hover:text-white transition-colors">About Us</a></li>
                        <li><a href="#" class="text-gray-400 hover:text-white transition-colors">Blog</a></li>
                        <li><a href="#" class="text-gray-400 hover:text-white transition-colors">Careers</a></li>
                        <li><a href="#contact" class="text-gray-400 hover:text-white transition-colors">Contact</a></li>
                    </ul>
                </div>

                <!-- Legal Links -->
                <div>
                    <h4 class="font-bold text-lg mb-6">Legal</h4>
                    <ul class="space-y-4">
                        <li><a href="#" class="text-gray-400 hover:text-white transition-colors">Privacy Policy</a></li>
                        <li><a href="#" class="text-gray-400 hover:text-white transition-colors">Terms of Service</a></li>
                        <li><a href="#" class="text-gray-400 hover:text-white transition-colors">Cookie Policy</a></li>
                        <li><a href="#" class="text-gray-400 hover:text-white transition-colors">Security</a></li>
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
    <script>
        // Mobile menu toggle
        const mobileMenuBtn = document.getElementById('mobile-menu-btn');
        const mobileMenu = document.getElementById('mobile-menu');
        
        mobileMenuBtn?.addEventListener('click', () => {
            mobileMenu.classList.toggle('hidden');
        });

        // Smooth scroll for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    // Close mobile menu if open
                    mobileMenu?.classList.add('hidden');
                }
            });
        });

        // Navbar scroll effect
        const navbar = document.getElementById('navbar');
        let lastScroll = 0;

        window.addEventListener('scroll', () => {
            const currentScroll = window.pageYOffset;
            
            if (currentScroll > 50) {
                navbar.classList.add('shadow-md');
            } else {
                navbar.classList.remove('shadow-md');
            }
            
            lastScroll = currentScroll;
        });
    </script>
</body>
</html>
