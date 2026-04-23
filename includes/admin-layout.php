<?php
/**
 * Cardify - Admin Layout (Flowbite Dashboard Design)
 */

// Ensure Auth class is available
if (!class_exists('Auth')) {
    require_once __DIR__ . '/Auth.php';
}
// Impersonation helper (renders "Login as" banner when active)
if (!class_exists('Impersonation')) {
    require_once __DIR__ . '/Impersonation.php';
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
        ['name' => 'Growth Dashboard', 'icon' => 'fa-solid fa-chart-simple', 'url' => $basePath . 'growth' . $ext, 'key' => 'growth'],
        ['name' => 'Appointments', 'icon' => 'fa-solid fa-calendar-check', 'url' => $basePath . 'appointments' . $ext, 'key' => 'appointments'],
        ['name' => 'Theme', 'icon' => 'fa-solid fa-palette', 'url' => $basePath . 'theme' . $ext, 'key' => 'theme'],
        ['name' => 'Custom Domains', 'icon' => 'fa-solid fa-globe', 'url' => $basePath . 'custom-domains' . $ext, 'key' => 'custom-domains'],
        ['name' => 'NFC Tags', 'icon' => 'fa-solid fa-wifi', 'url' => $basePath . 'nfc/batch.php', 'key' => 'nfc-tags'],
        ['name' => 'Short Links', 'icon' => 'fa-solid fa-link', 'url' => $basePath . 'short-links' . $ext, 'key' => 'short-links'],
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
            ['name' => 'Blog Posts', 'icon' => 'fa-solid fa-pen-nib', 'url' => $superBasePath . 'blog.php', 'key' => 'blog'],
            ['name' => 'LinkedIn Carousels', 'icon' => 'fa-brands fa-linkedin', 'url' => $superBasePath . 'linkedin-carousels.php', 'key' => 'linkedin-carousels'],
            ['name' => 'Plans', 'icon' => 'fa-solid fa-tags', 'url' => $basePath . 'plans' . $ext, 'key' => 'plans'],
            ['name' => 'Subscriptions', 'icon' => 'fa-solid fa-credit-card', 'url' => $superBasePath . 'subscriptions.php', 'key' => 'subscriptions'],
            ['name' => 'Referrals', 'icon' => 'fa-solid fa-share-nodes', 'url' => $superBasePath . 'referrals.php', 'key' => 'referrals'],
            ['name' => 'Audit Logs', 'icon' => 'fa-solid fa-clipboard-list', 'url' => $basePath . 'audit-logs' . $ext, 'key' => 'audit-logs'],
            ['name' => 'Email Logs', 'icon' => 'fa-solid fa-envelope', 'url' => $superBasePath . 'email_logs.php', 'key' => 'email-logs']
        ]);
        $settingsItems[] = ['name' => 'Account Settings', 'icon' => 'fa-solid fa-user-gear', 'url' => $superBasePath . 'settings.php', 'key' => 'account-settings'];
        $settingsItems[] = ['name' => 'Email Settings', 'icon' => 'fa-solid fa-envelope-circle-check', 'url' => $superBasePath . 'email_settings.php', 'key' => 'email-settings'];
        $settingsItems[] = ['name' => 'Print Settings', 'icon' => 'fa-solid fa-print', 'url' => $basePath . 'print_settings' . $ext, 'key' => 'print'];
        $settingsItems[] = ['name' => 'Print Orders', 'icon' => 'fa-solid fa-box', 'url' => $basePath . 'print_orders' . $ext, 'key' => 'print_orders'];
        $settingsItems[] = ['name' => 'WhatsApp API', 'icon' => 'fa-brands fa-whatsapp', 'url' => $basePath . 'whatsapp_settings' . $ext, 'key' => 'whatsapp'];
        $settingsItems[] = ['name' => 'Bulk Claim', 'icon' => 'fa-solid fa-wand-magic-sparkles', 'url' => $basePath . 'bulk-claim' . $ext, 'key' => 'bulk-claim'];
        $settingsItems[] = ['name' => t('admin.nav_erp_settings'), 'icon' => 'fa-solid fa-plug', 'url' => $basePath . 'odoo_settings' . $ext, 'key' => 'odoo'];
        $settingsItems[] = ['name' => 'Updates', 'icon' => 'fa-solid fa-download', 'url' => $basePath . 'updates' . $ext, 'key' => 'updates'];
    } else {
        // Billing is only for company admins, not super admin
        $items[] = ['name' => 'Billing', 'icon' => 'fa-solid fa-credit-card', 'url' => $basePath . 'billing' . $ext, 'key' => 'billing'];
        $items[] = ['name' => 'Payment History', 'icon' => 'fa-solid fa-clock-rotate-left', 'url' => $basePath . 'payment-history' . $ext, 'key' => 'payment-history'];
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
    $adminLocale = function_exists('currentLocale') ? currentLocale() : 'en';
    $adminDir    = function_exists('currentDir')    ? currentDir()    : 'ltr';

    // Tenant theme: look up primary/secondary from company_themes so every
    // admin page gets the tenant's brand colors (buttons, focus rings, nav
    // accents, page-loader gradient). Cardify default when no row exists.
    $tBrand    = '#2563eb';
    $tBrand2   = '#1d4ed8';
    $tBrandInk = '#ffffff';
    $tBrandRing= 'rgba(37,99,235,.35)';
    try {
        $_cid = $_SESSION['company_id'] ?? null;
        if ($_cid && class_exists('Database') && class_exists('DatabaseAdapter') && DatabaseAdapter::useDatabase()) {
            $_theme = Database::getInstance()->fetchOne(
                'SELECT primary_color, secondary_color FROM company_themes WHERE company_id = :id LIMIT 1',
                ['id' => $_cid]
            );
            if ($_theme) {
                if (preg_match('/^#[0-9a-fA-F]{6}$/', $_theme['primary_color'] ?? '')) {
                    $tBrand = $_theme['primary_color'];
                }
                if (preg_match('/^#[0-9a-fA-F]{6}$/', $_theme['secondary_color'] ?? '')) {
                    $tBrand2 = $_theme['secondary_color'];
                }
            }
        }
    } catch (Throwable $_) { /* legacy installs without company_themes */ }
    // Pick legible ink (sRGB luminance threshold 0.6): dark text on light brand, white on dark.
    $_hex2 = ltrim($tBrand, '#');
    $_lum = (0.2126 * hexdec(substr($_hex2, 0, 2)) + 0.7152 * hexdec(substr($_hex2, 2, 2)) + 0.0722 * hexdec(substr($_hex2, 4, 2))) / 255;
    $tBrandInk = $_lum > 0.6 ? '#0f172a' : '#ffffff';
    $tBrandRing = $tBrand . '59'; // ~35% alpha
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($adminLocale) ?>" dir="<?= htmlspecialchars($adminDir) ?>" data-product="cardify">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - <?php echo $brandName; ?></title>
    <link rel="icon" href="<?php echo $basePath; ?>favicon.svg" type="image/svg+xml">

    <!-- PWA manifest + theme color (action 287) -->
    <link rel="manifest" href="<?php echo $basePath; ?>manifest.webmanifest">
    <meta name="theme-color" content="<?= htmlspecialchars($tBrand) ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">

    <!-- BHD Design Language Tokens -->
    <link rel="stylesheet" href="<?php echo $basePath; ?>assets/css/bhd-tokens.css">

    <!-- Cardify Design System: tokens + components + toast (Category K actions 296-320) -->
    <link rel="stylesheet" href="<?php echo $basePath; ?>assets/css/cardify-tokens.css">
    <link rel="stylesheet" href="<?php echo $basePath; ?>assets/css/cardify-components.css">
    <link rel="stylesheet" href="<?php echo $basePath; ?>assets/css/cardify-toast.css">

    <!-- Fonts, Inter + (when rtl) IBM Plex Sans Arabic -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <?php if ($adminDir === 'rtl'): ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <?php else: ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <?php endif; ?>

    <!-- Font Awesome (CDN) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
    <!-- Tailwind CSS (Local) -->
    <?php $tailwindVersion = @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/techwind/css/tailwind.min.css') ?: time(); ?>
    <link rel="stylesheet" href="/assets/techwind/css/tailwind.min.css?v=<?php echo $tailwindVersion; ?>">
    
    <!-- Flowbite CSS (Local) -->
    <?php $flowbiteCssVersion = @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/flowbite/app.css') ?: time(); ?>
    <link rel="stylesheet" href="/assets/flowbite/app.css?v=<?php echo $flowbiteCssVersion; ?>">
    
    <!-- Flag Icons CSS for country/phone dropdowns -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/lipis/flag-icons@7.0.0/css/flag-icons.min.css">
    
    <!-- Myriad Pro (licensed, self-hosted OTF). Weight mapping:
         300 = Light, 400 = Regular, 600 = SemiBold, 700 = Bold. -->
    <style>
        @font-face {
            font-family: 'Myriad Pro';
            font-style: normal;
            font-weight: 300;
            font-display: swap;
            src: url('<?php echo getBasePath(); ?>assets/fonts/myriad-pro/MyriadPro-Light.otf') format('opentype');
        }
        @font-face {
            font-family: 'Myriad Pro';
            font-style: normal;
            font-weight: 400;
            font-display: swap;
            src: url('<?php echo getBasePath(); ?>assets/fonts/myriad-pro/MyriadPro-Regular.otf') format('opentype');
        }
        @font-face {
            font-family: 'Myriad Pro';
            font-style: normal;
            font-weight: 600;
            font-display: swap;
            src: url('<?php echo getBasePath(); ?>assets/fonts/myriad-pro/MyriadPro-SemiBold.otf') format('opentype');
        }
        @font-face {
            font-family: 'Myriad Pro';
            font-style: italic;
            font-weight: 600;
            font-display: swap;
            src: url('<?php echo getBasePath(); ?>assets/fonts/myriad-pro/MyriadPro-SemiBoldIt.otf') format('opentype');
        }
        @font-face {
            font-family: 'Myriad Pro';
            font-style: normal;
            font-weight: 700;
            font-display: swap;
            src: url('<?php echo getBasePath(); ?>assets/fonts/myriad-pro/MyriadPro-Bold.otf') format('opentype');
        }
        @font-face {
            font-family: 'Myriad Pro';
            font-style: italic;
            font-weight: 700;
            font-display: swap;
            src: url('<?php echo getBasePath(); ?>assets/fonts/myriad-pro/MyriadPro-BoldIt.otf') format('opentype');
        }

        /* Tahoma (self-hosted, Regular only for now). Browsers synthesise
           bold/italic for weight/style requests that have no real cut. */
        @font-face {
            font-family: 'Tahoma';
            font-style: normal;
            font-weight: 400;
            font-display: swap;
            src: local('Tahoma'),
                 url('<?php echo getBasePath(); ?>assets/fonts/tahoma/Tahoma-Regular.ttf') format('truetype');
        }
    </style>

    <!-- Google Fonts for Card Editor (Arabic + English) -->
    <!-- Arabic Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&family=Tajawal:wght@200;300;400;500;700;800;900&family=Almarai:wght@300;400;700;800&family=Noto+Kufi+Arabic:wght@400;500;600;700&family=IBM+Plex+Sans+Arabic:wght@100;200;300;400;500;600;700&family=Noto+Sans+Arabic:wght@400;500;600;700&family=Readex+Pro:wght@200;300;400;500;600;700&family=El+Messiri:wght@400;500;600;700&family=Changa:wght@200;300;400;500;600;700;800&family=Reem+Kufi:wght@400;500;600;700&family=Amiri:wght@400;700&family=Scheherazade+New:wght@400;500;600;700&family=Mada:wght@200;300;400;500;600;700;800;900&family=Lalezar&family=Lemonada:wght@300;400;500;600;700&family=Aref+Ruqaa:wght@400;700&family=Mirza:wght@400;500;600;700&family=Rakkas&family=Baloo+Bhaijaan+2:wght@400;500;600;700;800&family=Noto+Naskh+Arabic:wght@400;500;600;700&family=Noto+Nastaliq+Urdu:wght@400;500;600;700&family=Lateef:wght@200;300;400;500;600;700;800&family=Harmattan:wght@400;500;600;700&family=Markazi+Text:wght@400;500;600;700&family=Gulzar&display=swap" rel="stylesheet">
    <!-- English Sans-Serif (with Light 300, ExtraBold 800, and italics) -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Plus+Jakarta+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Montserrat:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Roboto:ital,wght@0,300;0,400;0,500;0,700;1,300;1,400;1,500;1,700&family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Open+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Lato:ital,wght@0,300;0,400;0,700;1,300;1,400;1,700&family=Nunito:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Raleway:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Work+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Outfit:wght@300;400;500;600;700;800&family=Manrope:wght@300;400;500;600;700;800&family=Urbanist:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Lexend:wght@300;400;500;600;700;800&family=Sora:wght@300;400;500;600;700;800&family=Rubik:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Quicksand:wght@300;400;500;600;700&family=Ubuntu:ital,wght@0,300;0,400;0,500;0,700;1,300;1,400;1,500;1,700&family=Barlow:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Jost:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&display=swap" rel="stylesheet">
    <!-- Extra Sans/Serif/Display/Script/Mono families (Myriad-Pro-style alternatives + more) -->
    <link href="https://fonts.googleapis.com/css2?family=Source+Sans+3:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Hind:wght@300;400;500;600;700&family=Nunito+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Mulish:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=PT+Sans:ital,wght@0,400;0,700;1,400;1,700&family=Cabin:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500;1,600;1,700&family=Assistant:wght@300;400;500;600;700;800&family=Karla:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Figtree:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Red+Hat+Display:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Red+Hat+Text:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&family=Public+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Archivo:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=IBM+Plex+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&family=Onest:wght@300;400;500;600;700;800&family=Geist:wght@300;400;500;600;700;800&family=Source+Serif+4:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Fraunces:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Crimson+Pro:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Alegreya:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400;1,500;1,600;1,700&family=Libre+Caslon+Text:ital,wght@0,400;0,700;1,400&family=Newsreader:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&family=Roboto+Serif:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Abril+Fatface&family=Bungee&family=Comfortaa:wght@300;400;500;600;700&family=Josefin+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&family=Alfa+Slab+One&family=Chivo:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Russo+One&family=Unbounded:wght@300;400;500;600;700;800&family=Satisfy&family=Shadows+Into+Light&family=Homemade+Apple&family=Amatic+SC:wght@400;700&family=Parisienne&family=IBM+Plex+Mono:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&family=Courier+Prime:ital,wght@0,400;0,700;1,400;1,700&family=Inconsolata:wght@300;400;500;600;700;800&family=Vazirmatn:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Serif, Display, Handwriting, Monospace -->
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400;1,500;1,600;1,700&family=Merriweather:ital,wght@0,300;0,400;0,700;0,900;1,300;1,400;1,700;1,900&family=Lora:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500;1,600;1,700&family=PT+Serif:ital,wght@0,400;0,700;1,400;1,700&family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&family=EB+Garamond:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400;1,500;1,600;1,700&family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&family=Spectral:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Noto+Serif:ital,wght@0,400;0,700;1,400;1,700&family=Vollkorn:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400;1,500;1,600;1,700&family=Bodoni+Moda:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400;1,500;1,600;1,700&family=Bebas+Neue&family=Oswald:wght@300;400;500;600;700&family=Anton&family=Archivo+Black&family=Righteous&family=Teko:wght@300;400;500;600;700&family=Big+Shoulders+Display:wght@300;400;500;600;700;800&family=Fredoka:wght@300;400;500;600;700&family=Dancing+Script:wght@400;500;600;700&family=Pacifico&family=Great+Vibes&family=Sacramento&family=Allura&family=Lobster&family=Caveat:wght@400;500;600;700&family=Kaushan+Script&family=Roboto+Mono:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&family=JetBrains+Mono:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Fira+Code:wght@300;400;500;600;700&family=Source+Code+Pro:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Space+Mono:ital,wght@0,400;0,700;1,400;1,700&display=swap" rel="stylesheet">
    
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

    <!-- Tenant brand override: maps common Tailwind blue utilities to the
         tenant's primary color so every admin page (Dashboard, Employees,
         Theme, etc.) picks up the brand without touching each template. -->
    <style>
      :root{
        --tbrand: <?= htmlspecialchars($tBrand) ?>;
        --tbrand-2: <?= htmlspecialchars($tBrand2) ?>;
        --tbrand-ink: <?= htmlspecialchars($tBrandInk) ?>;
        --tbrand-ring: <?= htmlspecialchars($tBrandRing) ?>;
      }
      .bg-blue-500,.bg-blue-600{background-color:var(--tbrand)!important;color:var(--tbrand-ink)!important}
      .bg-blue-700,.hover\:bg-blue-700:hover,.hover\:bg-blue-600:hover,.hover\:bg-blue-500:hover{background-color:var(--tbrand-2)!important;color:var(--tbrand-ink)!important}
      .bg-blue-50{background-color:color-mix(in srgb, var(--tbrand) 8%, white)!important}
      .bg-blue-100{background-color:color-mix(in srgb, var(--tbrand) 18%, white)!important}
      .text-blue-500,.text-blue-600,.text-blue-700,.text-blue-800,
      .hover\:text-blue-600:hover,.hover\:text-blue-700:hover{color:var(--tbrand)!important}
      .border-blue-400,.border-blue-500,.border-blue-600{border-color:var(--tbrand)!important}
      .ring-blue-500,.ring-blue-600{--tw-ring-color:var(--tbrand-ring)!important}
      .focus\:ring-blue-500:focus,.focus\:ring-blue-500\/20:focus{box-shadow:0 0 0 3px var(--tbrand-ring)!important}
      .focus\:border-blue-500:focus{border-color:var(--tbrand)!important}
      /* gradients from blue-* utilities */
      .from-blue-500,.from-blue-600{--tw-gradient-from:var(--tbrand) var(--tw-gradient-from-position)!important;--tw-gradient-to:transparent var(--tw-gradient-to-position);--tw-gradient-stops:var(--tw-gradient-from),var(--tw-gradient-to)!important}
      .to-blue-600,.to-blue-700{--tw-gradient-to:var(--tbrand-2) var(--tw-gradient-to-position)!important}
      /* Page-loader brand gradient (line 293 fallback) uses hardcoded blue -
         swap to brand primary→secondary. */
      .page-loader-brand{background:none!important;color:var(--tbrand)!important;-webkit-text-fill-color:var(--tbrand)!important}
    </style>
</head>
<body class="bg-gray-50"<?php echo Impersonation::isActive() ? ' data-impersonating="true"' : ''; ?>>
    <?php Impersonation::renderBanner(); ?>
    <!-- Page Loader (auto-hides via CSS after 1s even without JS).
         SVG inlined so the tenant brand colors apply via CSS variables. -->
    <div class="page-loader" id="pageLoader">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120" width="100" height="100" role="img" aria-label="Loading" class="page-loader-svg">
            <circle cx="60" cy="60" r="52" fill="none" stroke="#e2e8f0" stroke-width="6"/>
            <circle cx="60" cy="60" r="52" fill="none" stroke="var(--tbrand)" stroke-width="6" stroke-linecap="round" stroke-dasharray="60 120">
                <animateTransform attributeName="transform" type="rotate" from="0 60 60" to="360 60 60" dur="1.2s" repeatCount="indefinite"/>
            </circle>
            <g>
                <rect x="26" y="36" width="68" height="44" rx="10" fill="var(--tbrand)">
                    <animateTransform attributeName="transform" type="rotate" values="-3 60 58; 3 60 58; -3 60 58" dur="2.6s" repeatCount="indefinite"/>
                </rect>
                <rect x="34" y="44" width="52" height="28" rx="6" fill="#ffffff">
                    <animate attributeName="opacity" values="1;0.9;1" dur="1.8s" repeatCount="indefinite"/>
                </rect>
                <rect x="40" y="50" width="10" height="10" rx="5" fill="var(--tbrand)" opacity="0.85"/>
                <rect x="54" y="50" width="24" height="4" rx="2" fill="var(--tbrand)"/>
                <rect x="54" y="58" width="18" height="4" rx="2" fill="var(--tbrand)" opacity="0.4"/>
            </g>
            <circle cx="60" cy="12" r="3" fill="var(--tbrand)"><animate attributeName="opacity" values="0;1;0" dur="1.2s" repeatCount="indefinite"/></circle>
            <circle cx="108" cy="60" r="3" fill="var(--tbrand)"><animate attributeName="opacity" values="1;0;1" dur="1.2s" repeatCount="indefinite"/></circle>
            <circle cx="60" cy="108" r="3" fill="var(--tbrand)"><animate attributeName="opacity" values="0;1;0" dur="1.2s" begin="0.4s" repeatCount="indefinite"/></circle>
            <circle cx="12" cy="60" r="3" fill="var(--tbrand)"><animate attributeName="opacity" values="1;0;1" dur="1.2s" begin="0.4s" repeatCount="indefinite"/></circle>
        </svg>
        <div class="page-loader-text">Loading...</div>
        <div class="page-loader-brand"><?php echo $brandName; ?></div>
    </div>
    <!-- Navbar -->
    <nav class="fixed z-30 w-full bg-white/80 backdrop-blur-md border-b border-gray-200/80 shadow-sm">
        <div class="py-3 px-4 lg:px-6">
            <div class="flex justify-between items-center">
                <div class="flex justify-start items-center">
                    <!-- Mobile menu button -->
                    <button id="toggleSidebarMobile" type="button" class="p-2 mr-2 text-gray-600 rounded-lg cursor-pointer lg:hidden hover:text-gray-900 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1 transition-colors">
                        <svg id="toggleSidebarMobileHamburger" class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h6a1 1 0 110 2H4a1 1 0 01-1-1zM3 15a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"></path></svg>
                        <svg id="toggleSidebarMobileClose" class="hidden w-6 h-6" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path></svg>
                    </button>

                    <!-- Logo -->
                    <?php
                    $logoUrl = (Auth::getCurrentRole() === 'super_admin') ? getBasePath() . 'admin/super/' : getAdminBasePath();
                    ?>
                    <a href="<?php echo $logoUrl; ?>" class="flex items-center mr-10 lg:mr-14">
                        <img src="<?php echo assetUrl('images/logo.svg'); ?>" class="h-8 w-auto" alt="<?php echo $brandName; ?>">
                    </a>

                    <!-- Search (desktop) -->
                    <form action="#" method="GET" class="hidden lg:block lg:pl-2">
                        <label for="topbar-search" class="sr-only">Search</label>
                        <div class="relative">
                            <div class="flex absolute inset-y-0 left-0 items-center pl-3 pointer-events-none">
                                <i class="fa-solid fa-search text-gray-400 text-sm"></i>
                            </div>
                            <input type="text" id="topbar-search" class="bg-gray-50 border border-gray-200 text-gray-900 text-sm rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 block w-72 pl-10 pr-3 py-2 placeholder-gray-400 transition-shadow" placeholder="Search employees, orders, cards…">
                        </div>
                    </form>
                </div>

                <div class="flex items-center gap-1">
                    <!-- Notifications -->
                    <button type="button" class="relative p-2 text-gray-500 rounded-lg hover:text-gray-900 hover:bg-gray-100 transition-colors">
                        <span class="sr-only">View notifications</span>
                        <i class="fa-solid fa-bell w-5 h-5"></i>
                    </button>

                    <!-- Language Switcher -->
                    <span class="hidden sm:inline-flex mr-2">
                        <?php require INCLUDES_DIR . '/lang-switcher.php'; ?>
                    </span>

                    <!-- User dropdown -->
                    <div class="flex items-center ml-1" x-data="{ open: false }">
                        <button @click="open = !open" type="button" class="flex items-center gap-2 text-sm rounded-full p-0.5 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1 transition">
                            <span class="sr-only">Open user menu</span>
                            <div class="w-9 h-9 rounded-full bg-gradient-to-br from-blue-600 to-indigo-600 flex items-center justify-center text-white text-sm font-semibold shadow-sm">
                                <?php echo $userInitials; ?>
                            </div>
                        </button>

                        <!-- Dropdown menu -->
                        <div x-show="open" @click.away="open = false" x-cloak
                             x-transition:enter="transition ease-out duration-100"
                             x-transition:enter-start="transform opacity-0 scale-95"
                             x-transition:enter-end="transform opacity-100 scale-100"
                             class="absolute right-4 top-14 z-50 w-56 text-base list-none bg-white rounded-xl divide-y divide-gray-100 shadow-lg ring-1 ring-black/5 overflow-hidden">
                            <div class="py-3 px-4">
                                <p class="text-sm font-semibold text-gray-900 truncate"><?php echo htmlspecialchars($userName); ?></p>
                                <p class="text-xs font-medium text-gray-500 truncate mt-0.5"><?php echo htmlspecialchars($userEmail); ?></p>
                            </div>
                            <ul class="py-1">
                                <li>
                                    <?php
                                    $currentRole = Auth::getCurrentRole();
                                    $dropdownDashboardUrl = ($currentRole === 'super_admin') ? getBasePath() . 'admin/super/' : getAdminBasePath();
                                    ?>
                                    <a href="<?php echo $dropdownDashboardUrl; ?>" class="flex items-center gap-3 py-2 px-4 text-sm text-gray-700 hover:bg-gray-50 hover:text-blue-600 transition-colors">
                                        <i class="fa-solid fa-gauge-high w-4 text-gray-400"></i>Dashboard
                                    </a>
                                </li>
                                <li>
                                    <?php
                                    $ext = (defined('COMPANY_ADMIN_BASE') || !empty($_SESSION['company_slug'])) ? '' : '.php';
                                    // Super admins get account settings, others get theme settings
                                    $settingsUrl = ($currentRole === 'super_admin')
                                        ? getBasePath() . 'admin/super/settings.php'
                                        : getAdminBasePath() . 'theme' . $ext;
                                    ?>
                                    <a href="<?php echo $settingsUrl; ?>" class="flex items-center gap-3 py-2 px-4 text-sm text-gray-700 hover:bg-gray-50 hover:text-blue-600 transition-colors">
                                        <i class="fa-solid fa-gear w-4 text-gray-400"></i>Settings
                                    </a>
                                </li>
                                <li>
                                    <a href="<?php echo $basePath; ?>logout.php" class="flex items-center gap-3 py-2 px-4 text-sm text-gray-700 hover:bg-red-50 hover:text-red-600 transition-colors">
                                        <i class="fa-solid fa-right-from-bracket w-4 text-gray-400"></i>Sign out
                                    </a>
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
                <div class="flex-1 px-3 space-y-1 bg-white divide-y divide-gray-100">
                    <ul class="pb-2 space-y-1">
                        <?php foreach ($nav['main'] as $item): ?>
                        <li>
                            <a href="<?php echo htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8'); ?>"
                               class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg group transition-colors <?php echo $currentPage === $item['key'] ? 'text-blue-700 bg-blue-50 ring-1 ring-blue-100' : 'text-gray-700 hover:bg-gray-50 hover:text-gray-900'; ?>">
                                <i class="<?php echo htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8'); ?> w-5 h-5 transition-colors flex-shrink-0 <?php echo $currentPage === $item['key'] ? 'text-blue-600' : 'text-gray-400 group-hover:text-gray-600'; ?>"></i>
                                <span class="ml-3 flex-1"><?php echo htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php if ($item['key'] === 'requests' && $pendingRequestsCount > 0): ?>
                                <span class="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 text-[11px] font-semibold text-white bg-red-500 rounded-full"><?php echo $pendingRequestsCount > 99 ? '99+' : $pendingRequestsCount; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <?php endforeach; ?>

                        <!-- Settings dropdown -->
                        <?php if (!empty($nav['settings'])): ?>
                        <li x-data="{ settingsOpen: <?php echo in_array($currentPage, array_column($nav['settings'], 'key')) ? 'true' : 'false'; ?> }" class="pt-1">
                            <button @click="settingsOpen = !settingsOpen" type="button"
                                    class="flex items-center px-3 py-2.5 w-full text-sm font-medium text-gray-700 rounded-lg transition-colors group hover:bg-gray-50 hover:text-gray-900">
                                <i class="fa-solid fa-cog w-5 h-5 text-gray-400 group-hover:text-gray-600 transition-colors flex-shrink-0"></i>
                                <span class="flex-1 ml-3 text-left whitespace-nowrap">Settings</span>
                                <i class="fa-solid fa-chevron-down text-xs transition-transform text-gray-400" :class="settingsOpen ? 'rotate-180' : ''"></i>
                            </button>
                            <ul x-show="settingsOpen" x-cloak class="py-1 space-y-1">
                                <?php foreach ($nav['settings'] as $item): ?>
                                <li>
                                    <a href="<?php echo htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8'); ?>"
                                       class="flex items-center px-3 py-2 pl-11 text-sm font-medium rounded-lg transition-colors <?php echo $currentPage === $item['key'] ? 'text-blue-700 bg-blue-50' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?>">
                                        <?php echo htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </li>
                        <?php endif; ?>
                    </ul>

                    <!-- Bottom links -->
                    <div class="pt-3 space-y-1">
                        <a href="<?php echo $basePath; ?>" class="flex items-center px-3 py-2.5 text-sm font-medium text-gray-700 rounded-lg transition-colors hover:bg-gray-50 hover:text-gray-900 group">
                            <i class="fa-solid fa-house w-5 h-5 text-gray-400 group-hover:text-gray-600 transition-colors flex-shrink-0"></i>
                            <span class="ml-3">Back to Website</span>
                        </a>
                        <a href="<?php echo $basePath; ?>logout.php" class="flex items-center px-3 py-2.5 text-sm font-medium text-gray-700 rounded-lg transition-colors hover:bg-red-50 hover:text-red-600 group">
                            <i class="fa-solid fa-right-from-bracket w-5 h-5 text-gray-400 group-hover:text-red-500 transition-colors flex-shrink-0"></i>
                            <span class="ml-3">Sign Out</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </aside>

    <!-- Sidebar backdrop -->
    <div class="hidden fixed inset-0 z-10 bg-gray-900/50 backdrop-blur-sm" id="sidebarBackdrop"></div>

    <!-- Main content -->
    <div class="overflow-y-auto lg:ml-64 pt-16">
        <main>
            <div class="px-4 sm:px-6 lg:px-8 pt-6">
                <!-- Page title -->
                <div class="mb-6 pb-4 border-b border-gray-200/80">
                    <h1 class="text-2xl font-bold tracking-tight text-gray-900 sm:text-3xl"><?php echo htmlspecialchars($pageTitle); ?></h1>
                </div>
<?php
}

function adminFooter() {
?>
            </div>
        </main>
        
        <!-- Footer -->
        <footer class="mt-10 px-4 sm:px-6 lg:px-8 py-6 border-t border-gray-200/80 flex flex-col md:flex-row md:items-center md:justify-between gap-2 text-xs text-gray-500">
            <span>&copy; <?php echo date('Y'); ?> <a href="#" class="font-medium text-gray-700 hover:text-blue-600 transition-colors"><?php echo defined('SITE_NAME') ? SITE_NAME : 'Cardify'; ?></a>. All rights reserved.</span>
            <span class="inline-flex items-center gap-1.5"><i class="fa-solid fa-shield-halved text-green-500"></i> Secure workspace</span>
        </footer>
    </div>
    
    <!-- Flowbite JS (Local) -->
    <?php $flowbiteJsVersion = @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/flowbite/app.bundle.js') ?: time(); ?>
    <script defer src="/assets/flowbite/app.bundle.js?v=<?php echo $flowbiteJsVersion; ?>"></script>
    
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

    <!-- Shared toast + form helpers + service-worker + web-vitals beacon (Cardify v2.0 Cat J/M/N) -->
    <?php $__base = function_exists('getBasePath') ? getBasePath() : '/'; ?>
    <script src="<?php echo $__base; ?>assets/js/cardify-toast.js" defer></script>
    <script src="<?php echo $__base; ?>assets/js/cardify-forms.js" defer></script>
    <script src="<?php echo $__base; ?>assets/js/cardify-webvitals.js" defer></script>
    <script>
    if ('serviceWorker' in navigator && location.protocol === 'https:') {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('<?php echo $__base; ?>sw.js', { scope: '<?php echo $__base; ?>' })
                .catch(() => { /* non-fatal */ });
        });
    }
    </script>
</body>
</html>
<?php
}
