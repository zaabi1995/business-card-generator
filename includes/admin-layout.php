<?php
/**
 * Cardify - Admin Layout (Flowbite Dashboard Design)
 */

// Ensure Auth class is available
if (!class_exists('Auth')) {
    require_once __DIR__ . '/Auth.php';
}

/**
 * Get the admin base path - company-specific or global
 */
function getAdminBasePath() {
    // Check if we're in company admin context
    if (defined('COMPANY_ADMIN_BASE')) {
        return COMPANY_ADMIN_BASE;
    }
    
    // Check session for company slug
    if (!empty($_SESSION['company_slug'])) {
        return getBasePath() . $_SESSION['company_slug'] . '/admin/';
    }
    
    // Default to global admin
    return getBasePath() . 'admin/';
}


function getAdminNavItems() {
    $role = Auth::getCurrentRole() ?? 'admin';
    $basePath = getAdminBasePath();
    
    // Check if we're in company admin context (clean URLs without .php)
    $isCompanyAdmin = defined('COMPANY_ADMIN_BASE') || !empty($_SESSION['company_slug']);
    $ext = $isCompanyAdmin ? '' : '.php';
    
    // Super admin dashboard should go to /admin/super/
    $dashboardUrl = ($role === 'super_admin') ? getBasePath() . 'admin/super/' : $basePath;
    
    $items = [
        ['name' => 'Dashboard', 'icon' => 'fa-solid fa-chart-pie', 'url' => $dashboardUrl, 'key' => 'dashboard'],
        ['name' => 'My Dashboard', 'icon' => 'fa-solid fa-gauge', 'url' => $basePath . 'customer-dashboard' . $ext, 'key' => 'customer-dashboard'],
        ['name' => 'Employees', 'icon' => 'fa-solid fa-users', 'url' => $basePath . 'employees' . $ext, 'key' => 'employees'],
        ['name' => 'Departments', 'icon' => 'fa-solid fa-sitemap', 'url' => $basePath . 'departments' . $ext, 'key' => 'departments'],
        ['name' => 'Card Requests', 'icon' => 'fa-solid fa-inbox', 'url' => $basePath . 'requests' . $ext, 'key' => 'requests'],
        ['name' => 'Print Orders', 'icon' => 'fa-solid fa-print', 'url' => $basePath . 'print' . $ext, 'key' => 'print'],
        ['name' => 'QR Analytics', 'icon' => 'fa-solid fa-chart-line', 'url' => $basePath . 'analytics' . $ext, 'key' => 'analytics'],
        ['name' => 'Theme', 'icon' => 'fa-solid fa-palette', 'url' => $basePath . 'theme' . $ext, 'key' => 'theme'],
    ];
    
    // Add settings dropdown items
    $settingsItems = [];
    
    // Super admin only items
    if ($role === 'super_admin') {
        // Check if we're in the super admin area
        $superBasePath = getBasePath() . 'admin/super/';
        
        array_splice($items, 1, 0, [
            ['name' => 'Companies', 'icon' => 'fa-solid fa-building', 'url' => $superBasePath . 'companies.php', 'key' => 'companies'],
            ['name' => 'All Employees', 'icon' => 'fa-solid fa-users-gear', 'url' => $superBasePath . 'employees.php', 'key' => 'all-employees'],
            ['name' => 'Print Shops', 'icon' => 'fa-solid fa-store', 'url' => $superBasePath . 'print_shops.php', 'key' => 'print_shops'],
            ['name' => 'Plans', 'icon' => 'fa-solid fa-tags', 'url' => $basePath . 'plans' . $ext, 'key' => 'plans'],
            ['name' => 'Subscriptions', 'icon' => 'fa-solid fa-credit-card', 'url' => $superBasePath . 'subscriptions.php', 'key' => 'subscriptions'],
            ['name' => 'Audit Logs', 'icon' => 'fa-solid fa-clipboard-list', 'url' => $basePath . 'audit-logs' . $ext, 'key' => 'audit-logs'],
            ['name' => 'Email Logs', 'icon' => 'fa-solid fa-envelope', 'url' => $superBasePath . 'email_logs.php', 'key' => 'email-logs']
        ]);
        $settingsItems[] = ['name' => 'Account Settings', 'icon' => 'fa-solid fa-user-gear', 'url' => $superBasePath . 'settings.php', 'key' => 'account-settings'];
        $settingsItems[] = ['name' => 'Email Settings', 'icon' => 'fa-solid fa-envelope-circle-check', 'url' => $superBasePath . 'email_settings.php', 'key' => 'email-settings'];
        $settingsItems[] = ['name' => 'Print Settings', 'icon' => 'fa-solid fa-print', 'url' => $basePath . 'print_settings' . $ext, 'key' => 'print'];
        $settingsItems[] = ['name' => 'Print Orders', 'icon' => 'fa-solid fa-box', 'url' => $basePath . 'print_orders' . $ext, 'key' => 'print_orders'];
        $settingsItems[] = ['name' => 'WhatsApp API', 'icon' => 'fa-brands fa-whatsapp', 'url' => $basePath . 'whatsapp_settings' . $ext, 'key' => 'whatsapp'];
        $settingsItems[] = ['name' => 'Odoo ERP', 'icon' => 'fa-solid fa-plug', 'url' => $basePath . 'odoo_settings' . $ext, 'key' => 'odoo'];
        $settingsItems[] = ['name' => 'Updates', 'icon' => 'fa-solid fa-download', 'url' => $basePath . 'updates' . $ext, 'key' => 'updates'];
    } else {
        // Billing is only for company admins, not super admin
        $items[] = ['name' => 'Billing', 'icon' => 'fa-solid fa-credit-card', 'url' => $basePath . 'billing' . $ext, 'key' => 'billing'];
    }
    
    return ['main' => $items, 'settings' => $settingsItems];
}

function getPendingRequestsCount() {
    $companyId = getCurrentCompanyId();
    if (!$companyId) return 0;
    
    try {
        $db = Database::getInstance();
        $result = $db->fetchOne(
            "SELECT COUNT(*) as count FROM card_requests WHERE company_id = :cid AND status = 'pending'",
            ['cid' => $companyId]
        );
        return (int)($result['count'] ?? 0);
    } catch (Exception $e) {
        return 0;
    }
}

function adminHeader($pageTitle = 'Dashboard', $currentPage = 'dashboard') {
    global $currentUser, $brandName;
    
    // Redirect print shop users to their dashboard - they shouldn't access admin pages
    if (class_exists('Auth') && Auth::isLoggedIn()) {
        $role = Auth::getCurrentRole();
        if ($role === 'print_shop') {
            header('Location: ' . getBasePath() . 'printshop/dashboard.php');
            exit;
        }
    }
    
    $brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
    $basePath = getBasePath();
    $nav = getAdminNavItems();
    
    // Get pending requests count for badge
    $pendingRequestsCount = getPendingRequestsCount();
    
    // Get current user info
    if (!isset($currentUser) && class_exists('Auth')) {
        $currentUser = Auth::getCurrentUser();
    }
    $userName = $currentUser['name'] ?? $currentUser['email'] ?? 'Admin';
    $userEmail = $currentUser['email'] ?? '';
    $userInitials = strtoupper(substr($userName, 0, 2));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - <?php echo $brandName; ?></title>
    <link rel="icon" href="<?php echo $basePath; ?>favicon.svg" type="image/svg+xml">
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="<?php echo $basePath; ?>assets/vendor/css/all.css">
    
    <!-- Tailwind CSS (Local) -->
    <?php $tailwindVersion = @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/techwind/css/tailwind.min.css') ?: time(); ?>
    <link rel="stylesheet" href="/assets/techwind/css/tailwind.min.css?v=<?php echo $tailwindVersion; ?>">
    
    <!-- Flowbite CSS (Local) -->
    <?php $flowbiteCssVersion = @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/flowbite/app.css') ?: time(); ?>
    <link rel="stylesheet" href="/assets/flowbite/app.css?v=<?php echo $flowbiteCssVersion; ?>">
    
    <!-- Flag Icons CSS for country/phone dropdowns -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/lipis/flag-icons@7.0.0/css/flag-icons.min.css">
    
    <!-- Google Fonts for Card Editor (Arabic + English) -->
    <!-- Arabic Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&family=Tajawal:wght@200;300;400;500;700;800;900&family=Almarai:wght@300;400;700;800&family=Noto+Kufi+Arabic:wght@400;500;600;700&family=IBM+Plex+Sans+Arabic:wght@100;200;300;400;500;600;700&family=Noto+Sans+Arabic:wght@400;500;600;700&family=Readex+Pro:wght@200;300;400;500;600;700&family=El+Messiri:wght@400;500;600;700&family=Changa:wght@200;300;400;500;600;700;800&family=Reem+Kufi:wght@400;500;600;700&family=Amiri:wght@400;700&family=Scheherazade+New:wght@400;500;600;700&family=Mada:wght@200;300;400;500;600;700;800;900&family=Lalezar&family=Lemonada:wght@300;400;500;600;700&family=Aref+Ruqaa:wght@400;700&family=Mirza:wght@400;500;600;700&family=Rakkas&family=Baloo+Bhaijaan+2:wght@400;500;600;700;800&family=Noto+Naskh+Arabic:wght@400;500;600;700&family=Noto+Nastaliq+Urdu:wght@400;500;600;700&family=Lateef:wght@200;300;400;500;600;700;800&family=Harmattan:wght@400;500;600;700&family=Markazi+Text:wght@400;500;600;700&family=Gulzar&display=swap" rel="stylesheet">
    <!-- English Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Montserrat:wght@400;500;600;700&family=Roboto:wght@400;500;700&family=Poppins:wght@400;500;600;700&family=Open+Sans:wght@400;600;700&family=Lato:wght@400;700&family=Nunito:wght@400;600;700&family=Raleway:wght@400;500;600;700&family=Work+Sans:wght@400;500;600;700&family=DM+Sans:wght@400;500;700&family=Outfit:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&family=Urbanist:wght@400;500;600;700&family=Lexend:wght@400;500;600;700&family=Sora:wght@400;500;600;700&family=Rubik:wght@400;500;600;700&family=Quicksand:wght@400;500;600;700&family=Ubuntu:wght@400;500;700&family=Barlow:wght@400;500;600;700&family=Jost:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Serif, Display, Handwriting, Monospace -->
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Merriweather:wght@400;700&family=Lora:wght@400;500;600;700&family=PT+Serif:wght@400;700&family=Libre+Baskerville:wght@400;700&family=EB+Garamond:wght@400;500;600;700&family=Cormorant+Garamond:wght@400;500;600;700&family=Spectral:wght@400;500;600;700&family=Noto+Serif:wght@400;700&family=Vollkorn:wght@400;500;600;700&family=Bodoni+Moda:wght@400;500;600;700&family=Bebas+Neue&family=Oswald:wght@400;500;600;700&family=Anton&family=Archivo+Black&family=Righteous&family=Teko:wght@400;500;600;700&family=Big+Shoulders+Display:wght@400;500;600;700;800&family=Fredoka:wght@400;500;600;700&family=Dancing+Script:wght@400;500;600;700&family=Pacifico&family=Great+Vibes&family=Sacramento&family=Allura&family=Lobster&family=Caveat:wght@400;500;600;700&family=Kaushan+Script&family=Roboto+Mono:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600;700&family=Fira+Code:wght@400;500;600;700&family=Source+Code+Pro:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    
    <!-- WebFontLoader -->
    <script src="https://ajax.googleapis.com/ajax/libs/webfont/1.6.26/webfont.js"></script>
    
    <!-- Fabric.js 7.1.0 for Card Editor -->
    <script src="https://cdn.jsdelivr.net/npm/fabric@7.1.0/dist/index.min.js"></script>
    
    <!-- QR Code Generator -->
    <script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
    
    <!-- jsPDF for PDF Export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    
    <!-- PDF-lib for Vector PDF Export (preserves original PDF quality) -->
    <script src="https://unpkg.com/pdf-lib@1.17.1/dist/pdf-lib.min.js"></script>
    
    <!-- html2canvas (fallback) -->
    <script src="https://html2canvas.hertzen.com/dist/html2canvas.min.js"></script>
    
    <!-- Alpine.js with Collapse plugin -->
    <script defer src="https://unpkg.com/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    
    <style>
        body { font-family: 'Inter', sans-serif; }
        [x-cloak] { display: none !important; }
        
        /* Canvas Editor Wrapper */
        #canvasWrapper {
            position: relative;
            background: white;
        }
        #canvasWrapper canvas {
            position: absolute !important;
            top: 0 !important;
            left: 0 !important;
            width: 100% !important;
            height: 100% !important;
        }
        #canvasWrapper .canvas-container {
            position: absolute !important;
            top: 0 !important;
            left: 0 !important;
            width: 100% !important;
            height: 100% !important;
        }

        .checkered-bg {
            background-color: #f3f4f6;
            background-image:
                linear-gradient(45deg, #e5e7eb 25%, transparent 25%),
                linear-gradient(-45deg, #e5e7eb 25%, transparent 25%),
                linear-gradient(45deg, transparent 75%, #e5e7eb 75%),
                linear-gradient(-45deg, transparent 75%, #e5e7eb 75%);
            background-size: 20px 20px;
            background-position: 0 0, 0 10px, 10px -10px, -10px 0px;
        }
        
        /* Page Loader with CSS fallback */
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
            /* CSS-only auto-hide after 1s (fallback if JS fails) */
            animation: loaderAutoHide 0.4s ease-out 1s forwards;
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
            animation: none;
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
        /* Content visibility - CSS auto-show after 1s */
        body > *:not(.page-loader) {
            opacity: 0;
            animation: contentAutoShow 0.3s ease-out 1s forwards;
        }
        @keyframes contentAutoShow {
            to { opacity: 1; }
        }
        body.loaded > *:not(.page-loader) {
            opacity: 1;
            animation: none;
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Page Loader (auto-hides via CSS after 1s even without JS) -->
    <div class="page-loader" id="pageLoader">
        <img src="<?php echo $basePath; ?>assets/images/cardify-loader.svg" alt="Loading" width="100" height="100" onerror="this.style.display='none'">
        <div class="page-loader-text">Loading...</div>
        <div class="page-loader-brand"><?php echo $brandName; ?></div>
    </div>
    <!-- Navbar -->
    <nav class="fixed z-30 w-full bg-white border-b border-gray-200">
        <div class="py-3 px-3 lg:px-5 lg:pl-3">
            <div class="flex justify-between items-center">
                <div class="flex justify-start items-center">
                    <!-- Mobile menu button -->
                    <button id="toggleSidebarMobile" type="button" class="p-2 mr-2 text-gray-600 rounded cursor-pointer lg:hidden hover:text-gray-900 hover:bg-gray-100 focus:bg-gray-100 focus:ring-2 focus:ring-gray-100">
                        <svg id="toggleSidebarMobileHamburger" class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h6a1 1 0 110 2H4a1 1 0 01-1-1zM3 15a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"></path></svg>
                        <svg id="toggleSidebarMobileClose" class="hidden w-6 h-6" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path></svg>
                    </button>
                    
                    <!-- Logo -->
                    <?php 
                    $logoUrl = (Auth::getCurrentRole() === 'super_admin') ? getBasePath() . 'admin/super/' : getAdminBasePath();
                    ?>
                    <a href="<?php echo $logoUrl; ?>" class="flex mr-14">
                        <img src="<?php echo assetUrl('images/logo.svg'); ?>" class="mr-3 h-8" alt="<?php echo $brandName; ?>">
                    </a>
                    
                    <!-- Search (desktop) -->
                    <form action="#" method="GET" class="hidden lg:block lg:pl-2">
                        <label for="topbar-search" class="sr-only">Search</label>
                        <div class="relative mt-1 lg:w-64">
                            <div class="flex absolute inset-y-0 left-0 items-center pl-3 pointer-events-none">
                                <i class="fa-solid fa-search w-5 h-5 text-gray-500"></i>
                            </div>
                            <input type="text" id="topbar-search" class="bg-gray-50 border border-gray-300 text-gray-900 sm:text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full pl-10 p-2.5" placeholder="Search">
                        </div>
                    </form>
                </div>
                
                <div class="flex items-center">
                    <!-- Notifications -->
                    <button type="button" class="p-2 text-gray-500 rounded-lg hover:text-gray-900 hover:bg-gray-100">
                        <span class="sr-only">View notifications</span>
                        <i class="fa-solid fa-bell w-6 h-6"></i>
                    </button>
                    
                    <!-- User dropdown -->
                    <div class="flex items-center ml-3" x-data="{ open: false }">
                        <button @click="open = !open" type="button" class="flex text-sm bg-gray-800 rounded-full focus:ring-4 focus:ring-gray-300">
                            <span class="sr-only">Open user menu</span>
                            <div class="w-8 h-8 rounded-full bg-blue-600 flex items-center justify-center text-white text-sm font-medium">
                                <?php echo $userInitials; ?>
                            </div>
                        </button>
                        
                        <!-- Dropdown menu -->
                        <div x-show="open" @click.away="open = false" x-cloak
                             class="absolute right-4 top-12 z-50 my-4 text-base list-none bg-white rounded divide-y divide-gray-100 shadow">
                            <div class="py-3 px-4">
                                <p class="text-sm text-gray-900"><?php echo htmlspecialchars($userName); ?></p>
                                <p class="text-sm font-medium text-gray-500 truncate"><?php echo htmlspecialchars($userEmail); ?></p>
                            </div>
                            <ul class="py-1">
                                <li>
                                    <?php 
                                    $currentRole = Auth::getCurrentRole();
                                    $dropdownDashboardUrl = ($currentRole === 'super_admin') ? getBasePath() . 'admin/super/' : getAdminBasePath();
                                    ?>
                                    <a href="<?php echo $dropdownDashboardUrl; ?>" class="block py-2 px-4 text-sm text-gray-700 hover:bg-gray-100">Dashboard</a>
                                </li>
                                <li>
                                    <?php 
                                    $ext = (defined('COMPANY_ADMIN_BASE') || !empty($_SESSION['company_slug'])) ? '' : '.php';
                                    // Super admins get account settings, others get theme settings
                                    $settingsUrl = ($currentRole === 'super_admin') 
                                        ? getBasePath() . 'admin/super/settings.php' 
                                        : getAdminBasePath() . 'theme' . $ext;
                                    ?>
                                    <a href="<?php echo $settingsUrl; ?>" class="block py-2 px-4 text-sm text-gray-700 hover:bg-gray-100">Settings</a>
                                </li>
                                <li>
                                    <a href="<?php echo $basePath; ?>logout.php" class="block py-2 px-4 text-sm text-gray-700 hover:bg-gray-100">Sign out</a>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </nav>
    
    <!-- Sidebar -->
    <aside id="sidebar" class="flex fixed top-0 left-0 z-20 flex-col flex-shrink-0 pt-16 w-64 h-full duration-75 lg:flex transition-width hidden" aria-label="Sidebar">
        <div class="flex relative flex-col flex-1 pt-0 min-h-0 bg-white border-r border-gray-200">
            <div class="flex overflow-y-auto flex-col flex-1 pt-5 pb-4">
                <div class="flex-1 px-3 space-y-1 bg-white divide-y divide-gray-200">
                    <ul class="pb-2 space-y-2">
                        <?php foreach ($nav['main'] as $item): ?>
                        <li>
                            <a href="<?php echo $item['url']; ?>" 
                               class="flex items-center p-2 text-base font-normal rounded-lg group <?php echo $currentPage === $item['key'] ? 'text-gray-900 bg-gray-100' : 'text-gray-900 hover:bg-gray-100'; ?>">
                                <i class="<?php echo $item['icon']; ?> w-6 h-6 text-gray-500 transition duration-75 group-hover:text-gray-900 flex-shrink-0"></i>
                                <span class="ml-3 flex-1"><?php echo $item['name']; ?></span>
                                <?php if ($item['key'] === 'requests' && $pendingRequestsCount > 0): ?>
                                <span class="inline-flex items-center justify-center w-5 h-5 text-xs font-bold text-white bg-red-500 rounded-full"><?php echo $pendingRequestsCount > 99 ? '99+' : $pendingRequestsCount; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <?php endforeach; ?>
                        
                        <!-- Settings dropdown -->
                        <?php if (!empty($nav['settings'])): ?>
                        <li x-data="{ settingsOpen: <?php echo in_array($currentPage, array_column($nav['settings'], 'key')) ? 'true' : 'false'; ?> }">
                            <button @click="settingsOpen = !settingsOpen" type="button" 
                                    class="flex items-center p-2 w-full text-base font-normal text-gray-900 rounded-lg transition duration-75 group hover:bg-gray-100">
                                <i class="fa-solid fa-cog w-6 h-6 text-gray-500 transition duration-75 group-hover:text-gray-900 flex-shrink-0"></i>
                                <span class="flex-1 ml-3 text-left whitespace-nowrap">Settings</span>
                                <i class="fa-solid fa-chevron-down w-6 h-6 transition-transform" :class="settingsOpen ? 'rotate-180' : ''"></i>
                            </button>
                            <ul x-show="settingsOpen" x-cloak class="py-2 space-y-2">
                                <?php foreach ($nav['settings'] as $item): ?>
                                <li>
                                    <a href="<?php echo $item['url']; ?>" 
                                       class="flex items-center p-2 pl-11 text-base font-normal rounded-lg group <?php echo $currentPage === $item['key'] ? 'text-gray-900 bg-gray-100' : 'text-gray-900 hover:bg-gray-100'; ?>">
                                        <?php echo $item['name']; ?>
                                    </a>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </li>
                        <?php endif; ?>
                    </ul>
                    
                    <!-- Bottom links -->
                    <div class="pt-2 space-y-2">
                        <a href="<?php echo $basePath; ?>" class="flex items-center p-2 text-base font-normal text-gray-900 rounded-lg transition duration-75 hover:bg-gray-100 group">
                            <i class="fa-solid fa-house w-6 h-6 text-gray-500 transition duration-75 group-hover:text-gray-900 flex-shrink-0"></i>
                            <span class="ml-3">Back to Website</span>
                        </a>
                        <a href="<?php echo $basePath; ?>logout.php" class="flex items-center p-2 text-base font-normal text-gray-900 rounded-lg transition duration-75 hover:bg-gray-100 group">
                            <i class="fa-solid fa-right-from-bracket w-6 h-6 text-gray-500 transition duration-75 group-hover:text-gray-900 flex-shrink-0"></i>
                            <span class="ml-3">Sign Out</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </aside>
    
    <!-- Sidebar backdrop -->
    <div class="hidden fixed inset-0 z-10 bg-gray-900/50" id="sidebarBackdrop"></div>
    
    <!-- Main content -->
    <div class="overflow-y-auto lg:ml-64 pt-16">
        <main>
            <div class="px-4 pt-6">
                <!-- Page title -->
                <div class="mb-4">
                    <h1 class="text-xl font-semibold text-gray-900 sm:text-2xl"><?php echo htmlspecialchars($pageTitle); ?></h1>
                </div>
<?php
}

function adminFooter() {
?>
            </div>
        </main>
        
        <!-- Footer -->
        <footer class="p-4 my-6 mx-4 bg-white rounded-lg shadow md:flex md:items-center md:justify-between md:p-6">
            <span class="text-sm text-gray-500 sm:text-center">
                &copy; <?php echo date('Y'); ?> <a href="#" class="hover:underline"><?php echo defined('SITE_NAME') ? SITE_NAME : 'Cardify'; ?></a>. All rights reserved.
            </span>
        </footer>
    </div>
    
    <!-- Flowbite JS (Local) -->
    <?php $flowbiteJsVersion = @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/flowbite/app.bundle.js') ?: time(); ?>
    <script src="/assets/flowbite/app.bundle.js?v=<?php echo $flowbiteJsVersion; ?>"></script>
    
    <!-- Card Editor JS -->
    <script src="<?php echo getBasePath(); ?>assets/js/font-loader.js"></script>
    <script src="<?php echo getBasePath(); ?>assets/js/card-editor.js?v=<?php echo time(); ?>"></script>
    
    <!-- Page Loader Script (JS enhancement - CSS handles fallback) -->
    <script>
        (function() {
            var loader = document.getElementById('pageLoader');
            var minLoadTime = 200; // Minimum 0.2 seconds for smooth UX
            var startTime = Date.now();
            
            function hideLoader() {
                var elapsed = Date.now() - startTime;
                var remaining = Math.max(0, minLoadTime - elapsed);
                
                setTimeout(function() {
                    if (loader) {
                        loader.classList.add('hidden');
                        document.body.classList.add('loaded');
                    }
                }, remaining);
            }
            
            // Hide loader when everything is loaded
            if (document.readyState === 'complete') {
                hideLoader();
            } else {
                window.addEventListener('load', hideLoader);
            }
        })();
    </script>
    
    <!-- Sidebar toggle script -->
    <script>
        const sidebar = document.getElementById('sidebar');
        const sidebarBackdrop = document.getElementById('sidebarBackdrop');
        const toggleSidebarMobile = document.getElementById('toggleSidebarMobile');
        const toggleSidebarMobileHamburger = document.getElementById('toggleSidebarMobileHamburger');
        const toggleSidebarMobileClose = document.getElementById('toggleSidebarMobileClose');
        
        if (toggleSidebarMobile) {
            toggleSidebarMobile.addEventListener('click', () => {
                sidebar.classList.toggle('hidden');
                sidebarBackdrop.classList.toggle('hidden');
                toggleSidebarMobileHamburger.classList.toggle('hidden');
                toggleSidebarMobileClose.classList.toggle('hidden');
            });
        }
        
        if (sidebarBackdrop) {
            sidebarBackdrop.addEventListener('click', () => {
                sidebar.classList.add('hidden');
                sidebarBackdrop.classList.add('hidden');
                toggleSidebarMobileHamburger.classList.remove('hidden');
                toggleSidebarMobileClose.classList.add('hidden');
            });
        }
        
        // Show sidebar on large screens
        if (window.innerWidth >= 1024) {
            sidebar.classList.remove('hidden');
        }
        
        window.addEventListener('resize', () => {
            if (window.innerWidth >= 1024) {
                sidebar.classList.remove('hidden');
                sidebarBackdrop.classList.add('hidden');
            } else {
                sidebar.classList.add('hidden');
            }
        });
    </script>
</body>
</html>
<?php
}
