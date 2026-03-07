<?php
/**
 * Cardify - Shared UI Header
 * Include this at the top of all pages for consistent styling
 * 
 * Variables you can set before including:
 * - $pageTitle: Page title (default: 'Cardify')
 * - $pageDescription: Meta description
 * - $htmlClass: HTML tag classes (default: 'scroll-smooth')
 * - $bodyClass: Additional body classes
 * - $bodyAttributes: Extra attributes for body tag
 * - $metaAuthor: Meta author tag
 * - $metaRobots: Meta robots tag
 * - $canonicalUrl: Canonical URL
 * - $enableThemeScript: Enable dark mode script
 * - $extraHead: Extra head markup (styles/scripts/meta)
 */

$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
$pageTitle = $pageTitle ?? $brandName;
$pageDescription = $pageDescription ?? 'Business Cards Made Simple';
$htmlClass = $htmlClass ?? 'scroll-smooth';
$bodyClass = $bodyClass ?? '';
$bodyAttributes = $bodyAttributes ?? '';
$metaAuthor = $metaAuthor ?? '';
$metaRobots = $metaRobots ?? '';
$canonicalUrl = $canonicalUrl ?? '';
$enableThemeScript = $enableThemeScript ?? false;
$extraHead = $extraHead ?? '';
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo htmlspecialchars($htmlClass); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> | <?php echo $brandName; ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($pageDescription); ?>">
    <?php if (!empty($metaAuthor)): ?>
    <meta name="author" content="<?php echo htmlspecialchars($metaAuthor); ?>">
    <?php endif; ?>
    <?php if (!empty($metaRobots)): ?>
    <meta name="robots" content="<?php echo htmlspecialchars($metaRobots); ?>">
    <?php endif; ?>
    <?php if (!empty($canonicalUrl)): ?>
    <link rel="canonical" href="<?php echo htmlspecialchars($canonicalUrl); ?>">
    <?php endif; ?>
    <link rel="icon" href="<?php echo getBasePath(); ?>favicon.svg" type="image/svg+xml">
    <link rel="alternate icon" href="<?php echo getBasePath(); ?>favicon.ico">
    
    <!-- Google Fonts - Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Font Awesome (Local) -->
    <link rel="stylesheet" href="<?php echo getBasePath(); ?>assets/vendor/css/all.css">
    
    <!-- Tailwind CSS (Local) -->
    <?php $tailwindVersion = @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/techwind/css/tailwind.min.css') ?: time(); ?>
    <link rel="stylesheet" href="/assets/techwind/css/tailwind.min.css?v=<?php echo $tailwindVersion; ?>">
    
    <!-- Flowbite CSS (Local) -->
    <?php $flowbiteCssVersion = @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/flowbite/app.css') ?: time(); ?>
    <link rel="stylesheet" href="/assets/flowbite/app.css?v=<?php echo $flowbiteCssVersion; ?>">
    
    <!-- Flag Icons CSS for country/phone dropdowns -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/lipis/flag-icons@7.0.0/css/flag-icons.min.css">
    
    <!-- Custom Overrides -->
    <link rel="stylesheet" href="<?php echo assetUrl('css/cardify-overrides.css'); ?>"><?php /* Local fallback assets kept for offline use */ ?>
    
    <!-- Alpine.js -->
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    
    <style>
        body { font-family: 'Inter', ui-sans-serif, system-ui, sans-serif; }
        [x-cloak] { display: none !important; }
        .bg-blur { backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); }
        
        /* Page Loader with CSS-only auto-hide fallback */
        .page-loader {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 99999;
            /* CSS-only auto-hide after 2.5s (fallback if JS fails) */
            animation: loaderAutoHide 0.4s ease-out 2.5s forwards;
        }
        @keyframes loaderAutoHide {
            to {
                opacity: 0;
                visibility: hidden;
                pointer-events: none;
            }
        }
        .page-loader.hidden {
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            animation: none; /* Stop CSS animation when JS hides it */
        }
        .page-loader-text {
            margin-top: 24px;
            font-size: 14px;
            color: #64748b;
            font-weight: 500;
            letter-spacing: 0.5px;
        }
        .page-loader-brand {
            margin-top: 8px;
            font-size: 20px;
            font-weight: 700;
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        /* Content visibility - CSS auto-show after 2.5s */
        body > *:not(.page-loader) {
            opacity: 0;
            animation: contentAutoShow 0.3s ease-out 2.5s forwards;
        }
        @keyframes contentAutoShow {
            to { opacity: 1; }
        }
        body.loaded > *:not(.page-loader) {
            opacity: 1;
            animation: none;
        }
    </style>
    <?php if ($enableThemeScript): ?>
    <script>
        if (localStorage.getItem('color-theme') === 'dark'
            || (!('color-theme' in localStorage)
                && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>
    <?php endif; ?>
    <?php if (!empty($extraHead)) echo $extraHead; ?>
</head>
<body class="bg-gray-50 text-gray-900 antialiased <?php echo $bodyClass; ?>" <?php echo $bodyAttributes; ?>>
    <!-- Page Loader (auto-hides via CSS after 2.5s even without JS) -->
    <div class="page-loader" id="pageLoader">
        <img src="<?php echo getBasePath(); ?>assets/images/cardify-loader.svg" alt="Loading" width="100" height="100" onerror="this.style.display='none'">
        <div class="page-loader-text">Loading...</div>
        <div class="page-loader-brand"><?php echo $brandName; ?></div>
    </div>
<?php
/**
 * Dynamic Navigation Component
 * Can be enabled by setting $showNavigation = true before including ui-header.php
 * 
 * Variables:
 * - $showNavigation: Enable/disable navigation (default: false)
 * - $navTransparent: Use transparent background (default: false)
 * - $navLinks: Custom navigation links array (default: standard links)
 */
if (!function_exists('renderNavigation')) {
    function renderNavigation($options = []) {
        $brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
        $transparent = $options['transparent'] ?? false;
        $customLinks = $options['links'] ?? null;
        
        // Check if Auth class exists and user is logged in
        $isLoggedIn = false;
        $currentUser = null;
        $userRole = null;
        $dashboardUrl = getBasePath() . 'admin/';
        
        if (class_exists('Auth')) {
            $isLoggedIn = Auth::isLoggedIn();
            if ($isLoggedIn) {
                $currentUser = Auth::getCurrentUser();
                $userRole = Auth::getCurrentRole();
                // Determine dashboard URL based on role
                if ($userRole === 'super_admin') {
                    $dashboardUrl = getBasePath() . 'admin/super/';
                } elseif ($userRole === 'employee') {
                    $dashboardUrl = getBasePath() . 'profile.php';
                } else {
                    $dashboardUrl = getBasePath() . 'admin/';
                }
            }
        }
        
        // Default navigation links
        $defaultLinks = [
            ['href' => '#features', 'label' => 'Features'],
            ['href' => '#how-it-works', 'label' => 'How it Works'],
            ['href' => '#pricing', 'label' => 'Pricing'],
            ['href' => '#testimonials', 'label' => 'Testimonials'],
            ['href' => '#contact', 'label' => 'Contact'],
        ];
        
        $navLinks = $customLinks ?? $defaultLinks;
        $bgClass = $transparent ? 'bg-transparent' : 'bg-white/80 bg-blur border-b border-gray-100';
        
        // Get user display name
        $userName = 'User';
        if ($currentUser) {
            $userName = $currentUser['name'] ?? $currentUser['email'] ?? 'User';
            // Get first name only
            $nameParts = explode(' ', $userName);
            $userName = $nameParts[0];
        }
        ?>
        <nav class="fixed top-0 left-0 right-0 z-50 <?php echo $bgClass; ?> transition-all duration-300" id="navbar">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between items-center h-16 lg:h-20">
                    <!-- Logo -->
                    <a href="<?php echo getBasePath(); ?>" class="flex items-center gap-3">
                        <img src="<?php echo assetUrl('images/logo.svg'); ?>" alt="<?php echo $brandName; ?>" class="h-10 w-auto">
                        <span class="text-xl font-semibold text-gray-900" style="font-family: 'Gill Sans', 'Gill Sans MT', Calibri, sans-serif;"><?php echo $brandName; ?></span>
                    </a>

                    <!-- Desktop Navigation -->
                    <div class="hidden lg:flex items-center gap-8">
                        <?php foreach ($navLinks as $link): ?>
                        <a href="<?php echo htmlspecialchars($link['href']); ?>" class="text-gray-600 hover:text-blue-600 transition-colors font-medium"><?php echo htmlspecialchars($link['label']); ?></a>
                        <?php endforeach; ?>
                    </div>

                    <!-- CTA Buttons -->
                    <div class="flex items-center gap-3">
                        <?php if ($isLoggedIn): ?>
                            <!-- Logged In State -->
                            <span class="hidden sm:inline-flex items-center gap-2 px-4 py-2 text-gray-700 font-medium">
                                <i class="fa-solid fa-circle-user text-blue-600"></i>
                                Hello, <?php echo htmlspecialchars($userName); ?>
                            </span>
                            <a href="<?php echo $dashboardUrl; ?>" class="inline-flex items-center px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg shadow-lg shadow-blue-600/30 transition-all hover:shadow-xl hover:shadow-blue-600/40">
                                <i class="fa-solid fa-gauge-high mr-2"></i>
                                Dashboard
                            </a>
                        <?php else: ?>
                            <!-- Logged Out State -->
                            <a href="<?php echo getBasePath(); ?>login.php" class="hidden sm:inline-flex items-center px-4 py-2 text-gray-700 hover:text-blue-600 font-medium transition-colors">
                                Sign In
                            </a>
                            <a href="<?php echo getBasePath(); ?>company/register.php" class="inline-flex items-center px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg shadow-lg shadow-blue-600/30 transition-all hover:shadow-xl hover:shadow-blue-600/40">
                                Get Started Free
                            </a>
                        <?php endif; ?>
                        
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
                    <?php foreach ($navLinks as $link): ?>
                    <a href="<?php echo htmlspecialchars($link['href']); ?>" class="block py-2 text-gray-600 hover:text-blue-600 font-medium"><?php echo htmlspecialchars($link['label']); ?></a>
                    <?php endforeach; ?>
                    <hr class="border-gray-200">
                    <?php if ($isLoggedIn): ?>
                        <div class="py-2 text-gray-700 font-medium">
                            <i class="fa-solid fa-circle-user text-blue-600 mr-2"></i>
                            Hello, <?php echo htmlspecialchars($userName); ?>
                        </div>
                        <a href="<?php echo $dashboardUrl; ?>" class="block py-2 text-blue-600 hover:text-blue-700 font-medium">
                            <i class="fa-solid fa-gauge-high mr-2"></i>Dashboard
                        </a>
                        <a href="<?php echo getBasePath(); ?>logout.php" class="block py-2 text-gray-600 hover:text-red-600 font-medium">
                            <i class="fa-solid fa-right-from-bracket mr-2"></i>Sign Out
                        </a>
                    <?php else: ?>
                        <a href="<?php echo getBasePath(); ?>login.php" class="block py-2 text-gray-600 hover:text-blue-600 font-medium">Sign In</a>
                        <a href="<?php echo getBasePath(); ?>company/register.php" class="block py-2 text-blue-600 hover:text-blue-700 font-medium">Get Started Free</a>
                    <?php endif; ?>
                </div>
            </div>
        </nav>
        <?php
    }
}

// Auto-render navigation if $showNavigation is set to true
if (isset($showNavigation) && $showNavigation === true) {
    renderNavigation([
        'transparent' => $navTransparent ?? false,
        'links' => $navLinks ?? null
    ]);
}
?>
