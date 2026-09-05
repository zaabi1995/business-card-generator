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
// TenantHost decides "am I on a tenant subdomain?" which drives the whole
// super-admin nav split. Admin pages have no autoloader, so without this
// require class_exists('TenantHost') is false and every admin page falls
// back to the apex nav (leaking the cross-tenant block onto tenants).
if (!class_exists('TenantHost') && is_file(__DIR__ . '/TenantHost.php')) {
    require_once __DIR__ . '/TenantHost.php';
}

/**
 * Get the admin base path - company-specific or global
 */
function getAdminBasePath() {
    // Check if we're in company admin context
    // On the tenant subdomain (ohb.cardify.om) `/admin/` is the natural
    // root; no slug prefix needed. COMPANY_ADMIN_BASE was minted with a
    // full subdomain URL in company_admin.php, which is also valid for
    // cross-host links so we still honor it when defined.
    if (class_exists('TenantHost') && TenantHost::isTenantHost()) {
        return '/admin/';
    }

    if (defined('COMPANY_ADMIN_BASE')) {
        return COMPANY_ADMIN_BASE;
    }

    if (!empty($_SESSION['company_slug'])) {
        return getTenantUrl($_SESSION['company_slug'], '/admin/');
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

    // HOST IS THE SWITCH (Jul 2026): on a tenant subdomain (ohb.cardify.om)
    // even a super_admin is contextually scoped to THAT company. Serving the
    // cross-tenant "All Employees" / Companies / Print Shops block under a
    // single tenant's hostname (+ tenant logo, theme, favicon) is a category
    // error and the exact source of the "two employees pages" confusion.
    // So the global super block appears ONLY on the apex; on a tenant the
    // super_admin gets the normal single-company nav plus the tenant-scoped
    // super tools, and reaches "everyone" via the workspace chip / global
    // console link (one click, nothing buried).
    $onTenant = class_exists('TenantHost') && TenantHost::isTenantHost();
    $isGlobalSuper = ($role === 'super_admin') && !$onTenant;

    // Dashboard target: the apex super dashboard only when we're actually on
    // the apex; a super_admin inside a tenant lands on that tenant's dashboard.
    $dashboardUrl = $isGlobalSuper ? getBasePath() . 'admin/super/' : $basePath;
    
    // CONSOLIDATED 6-TAB IA (May 2026): merged 16 → 6 to cut admin
    // cognitive load. Old pages still accessible via the merged tabs
    // (Brand wraps Theme/Domains/NFC/Links; Orders wraps Requests/Print/
    // Appointments; Billing tab contains Payment History sub-tab; the
    // per-employee detail page folds QR analytics + live feed inline).
    // Old top-level keys (customer-dashboard, growth, live-analytics,
    // analytics, requests, print, theme, etc.) still map to the new
    // sections so highlight-on-current-page keeps working.
    $items = [
        ['name' => t('admin.nav_dashboard'),  'icon' => 'fa-solid fa-chart-pie',  'url' => $dashboardUrl,                          'key' => 'dashboard',  'matches' => ['customer-dashboard', 'growth', 'live-analytics']],
        ['name' => t('admin.nav_employees'),  'icon' => 'fa-solid fa-users',      'url' => $basePath . 'employees' . $ext,         'key' => 'employees',  'matches' => ['departments', 'analytics', 'employee']],
        ['name' => t('admin.nav_orders'),     'icon' => 'fa-solid fa-inbox',      'url' => $basePath . 'orders' . $ext,            'key' => 'orders',     'matches' => ['requests', 'print', 'appointments'], 'badge_count' => 'pending_requests'],
        ['name' => t('admin.nav_card_designs'), 'icon' => 'fa-solid fa-id-card', 'url' => $basePath . 'templates' . $ext, 'key' => 'templates', 'matches' => []],
        ['name' => t('admin.nav_print_tracking'), 'icon' => 'fa-solid fa-clipboard-list', 'url' => $basePath . 'print-tracking' . $ext, 'key' => 'print-tracking', 'matches' => []],
        ['name' => t('admin.nav_brand'),      'icon' => 'fa-solid fa-palette',    'url' => $basePath . 'brand' . $ext,             'key' => 'brand',      'matches' => ['theme', 'custom-domains', 'nfc-tags', 'short-links']],
    ];
    
    // Add settings dropdown items
    $settingsItems = [];
    
    // Cross-tenant super console: APEX ONLY. Inside a tenant subdomain a
    // super_admin gets the EXACT company-admin nav (Ali's instruction: "same
    // page as them, no need to see other companies and employees"), so the
    // whole super block is gated on $isGlobalSuper and super-on-tenant simply
    // falls through to the company-admin branch below.
    if ($isGlobalSuper) {
        $superBasePath = getBasePath() . 'admin/super/';
        array_splice($items, 1, 0, [
            ['name' => t('admin.nav_companies'), 'icon' => 'fa-solid fa-building', 'url' => $superBasePath . 'companies.php', 'key' => 'companies'],
            ['name' => t('admin.nav_employees'), 'icon' => 'fa-solid fa-users-gear', 'url' => $superBasePath . 'employees.php', 'key' => 'all-employees'],
            ['name' => t('admin.nav_print_shops'), 'icon' => 'fa-solid fa-store', 'url' => $superBasePath . 'print_shops.php', 'key' => 'print_shops'],
            ['name' => t('admin.nav_blog'), 'icon' => 'fa-solid fa-pen-nib', 'url' => $superBasePath . 'blog.php', 'key' => 'blog'],
            ['name' => t('admin.nav_linkedin'), 'icon' => 'fa-brands fa-linkedin', 'url' => $superBasePath . 'linkedin-carousels.php', 'key' => 'linkedin-carousels'],
            ['name' => t('admin.nav_plans'), 'icon' => 'fa-solid fa-tags', 'url' => $basePath . 'plans' . $ext, 'key' => 'plans'],
            ['name' => t('admin.nav_subscriptions'), 'icon' => 'fa-solid fa-credit-card', 'url' => $superBasePath . 'subscriptions.php', 'key' => 'subscriptions'],
            ['name' => t('admin.nav_referrals'), 'icon' => 'fa-solid fa-share-nodes', 'url' => $superBasePath . 'referrals.php', 'key' => 'referrals'],
            ['name' => 'Scan Intelligence', 'icon' => 'fa-solid fa-brain', 'url' => $superBasePath . 'scan-intelligence.php', 'key' => 'scan-intelligence'],
            ['name' => t('admin.nav_audit_logs'), 'icon' => 'fa-solid fa-clipboard-list', 'url' => $basePath . 'audit-logs' . $ext, 'key' => 'audit-logs'],
            ['name' => t('admin.nav_email_logs'), 'icon' => 'fa-solid fa-envelope', 'url' => $superBasePath . 'email_logs.php', 'key' => 'email-logs']
        ]);
        $settingsItems[] = ['name' => t('admin.nav_account_settings'), 'icon' => 'fa-solid fa-user-gear', 'url' => $superBasePath . 'settings.php', 'key' => 'account-settings'];
        $settingsItems[] = ['name' => t('admin.nav_email_settings'), 'icon' => 'fa-solid fa-envelope-circle-check', 'url' => $superBasePath . 'email_settings.php', 'key' => 'email-settings'];
        $settingsItems[] = ['name' => t('admin.nav_print_settings'), 'icon' => 'fa-solid fa-print', 'url' => $basePath . 'print_settings' . $ext, 'key' => 'print'];
        $settingsItems[] = ['name' => t('admin.nav_print_orders'), 'icon' => 'fa-solid fa-box', 'url' => $basePath . 'print_orders' . $ext, 'key' => 'print_orders'];
        $settingsItems[] = ['name' => t('admin.nav_whatsapp'), 'icon' => 'fa-brands fa-whatsapp', 'url' => $basePath . 'whatsapp_settings' . $ext, 'key' => 'whatsapp'];
        $settingsItems[] = ['name' => t('admin.nav_bulk_claim'), 'icon' => 'fa-solid fa-wand-magic-sparkles', 'url' => $basePath . 'bulk-claim' . $ext, 'key' => 'bulk-claim'];
        $settingsItems[] = ['name' => t('admin.nav_erp_settings'), 'icon' => 'fa-solid fa-plug', 'url' => $basePath . 'odoo_settings' . $ext, 'key' => 'odoo'];
        $settingsItems[] = ['name' => t('admin.nav_updates'), 'icon' => 'fa-solid fa-download', 'url' => $basePath . 'updates' . $ext, 'key' => 'updates'];
    } else {
        // Company admin AND super_admin-on-a-tenant: identical single-company nav.
        $items[] = ['name' => t('admin.nav_billing'),  'icon' => 'fa-solid fa-credit-card', 'url' => $basePath . 'billing' . $ext,  'key' => 'billing',  'matches' => ['payment-history']];
        $items[] = ['name' => t('admin.nav_settings'), 'icon' => 'fa-solid fa-gear',        'url' => $basePath . 'settings' . $ext, 'key' => 'settings'];
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

function adminHeader($pageTitle = 'Dashboard', $currentPage = 'dashboard', $showTitleBar = true) {
    global $currentUser, $brandName;
    
    // Print partners may use company admin for attached client tenants.
    // Unattached shop sessions still bounce to the print-shop dashboard.
    if (class_exists('Auth') && Auth::isLoggedIn()) {
        $role = Auth::getCurrentRole();
        if ($role === 'print_shop' || $role === 'print_shop_operator') {
            if (!class_exists('PrintShopClients') && defined('INCLUDES_DIR')) {
                $clientsPath = INCLUDES_DIR . '/PrintShopClients.php';
                if (is_file($clientsPath)) require_once $clientsPath;
            }
            $urlCompanyId = class_exists('PrintShopClients')
                ? PrintShopClients::urlTenantCompanyId()
                : null;
            $partnerOk = class_exists('PrintShopClients')
                && PrintShopClients::currentSessionCanStayInCompanyAdmin($urlCompanyId);
            if (!$partnerOk) {
                header('Location: ' . getBasePath() . 'printshop/dashboard.php');
                exit;
            }
            if (!empty($GLOBALS['company']) && is_array($GLOBALS['company'])) {
                PrintShopClients::adoptClientContext($GLOBALS['company']);
            }
        }
    }
    
    $brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
    $tBrandLogo = null;
    $tBrandFavicon = null;
    $tBrandName = null;
    $basePath = getBasePath();
    $nav = getAdminNavItems();

    // Context for the workspace chip + "viewing as super admin" banner.
    // Computed once here and reused so identity/scope is always on screen.
    $__role      = class_exists('Auth') ? (Auth::getCurrentRole() ?? 'admin') : 'admin';
    $__isSuper   = ($__role === 'super_admin');
    $__onTenant  = class_exists('TenantHost') && TenantHost::isTenantHost();
    // A super_admin browsing a single tenant is a "visitor with power" — flag it.
    $__superOnTenant = $__isSuper && $__onTenant;
    
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
    // Cardify brand cyan. Was #2563eb/#1d4ed8, the retired indigo pair, which
    // repainted every blue utility across every admin page plus the PWA
    // theme-color for any company with no company_themes row. One real tenant
    // was rendering it, and any future signup that skips onboarding or finishes
    // without a logo would have too, because saveCompanyTheme() only writes the
    // row when a logo exists.
    $tBrand    = '#009bc1';
    $tBrand2   = '#067a98';
    $tBrandInk = '#ffffff';
    $tBrandRing= 'rgba(0,155,193,.35)';
    try {
        $_cid = $_SESSION['company_id'] ?? null;
        if ($_cid && class_exists('Database') && class_exists('DatabaseAdapter') && DatabaseAdapter::useDatabase()) {
            $_theme = Database::getInstance()->fetchOne(
                'SELECT t.primary_color, t.secondary_color, t.logo_path, t.favicon_path, c.name AS company_name
                 FROM companies c
                 LEFT JOIN company_themes t ON t.company_id = c.id
                 WHERE c.id = :id LIMIT 1',
                ['id' => $_cid]
            );
            if ($_theme) {
                if (preg_match('/^#[0-9a-fA-F]{6}$/', $_theme['primary_color'] ?? '')) {
                    $tBrand = $_theme['primary_color'];
                }
                if (preg_match('/^#[0-9a-fA-F]{6}$/', $_theme['secondary_color'] ?? '')) {
                    $tBrand2 = $_theme['secondary_color'];
                }
                // Path normalisation: company_themes stores either '/uploads/...'
                // (preferred) or 'companies/<id>/theme/foo.svg' (legacy bare
                // relative). Prepend '/uploads/' for the legacy form so admin
                // pages render the correct path. Mirrors TenantHost::theme().
                $_norm = function ($p) {
                    if (!$p) return null;
                    $p = trim((string)$p);
                    if ($p === '' || preg_match('#^https?://#i', $p)) return $p;
                    if ($p[0] === '/') return $p;
                    return '/uploads/' . ltrim($p, '/');
                };
                $tBrandLogo    = $_norm($_theme['logo_path']    ?? null);
                $tBrandFavicon = $_norm($_theme['favicon_path'] ?? null);
                // Auto-derive favicon from logo when not explicitly set, the
                // most common case after registration. Browsers accept SVG /
                // PNG logos as favicons directly.
                if (!$tBrandFavicon && $tBrandLogo) {
                    $tBrandFavicon = $tBrandLogo;
                }
                if (!empty($_theme['company_name'])) {
                    $tBrandName = $_theme['company_name'];
                    $brandName  = $tBrandName;
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
    <?php if (!empty($tBrandFavicon)):
        $__favType = preg_match('/\.svg(\?|$)/i', $tBrandFavicon) ? 'image/svg+xml'
                    : (preg_match('/\.png(\?|$)/i', $tBrandFavicon) ? 'image/png'
                    : (preg_match('/\.ico(\?|$)/i', $tBrandFavicon) ? 'image/x-icon' : 'image/png'));
    ?>
    <link rel="icon" href="<?= htmlspecialchars($tBrandFavicon, ENT_QUOTES) ?>" type="<?= $__favType ?>">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars($tBrandFavicon, ENT_QUOTES) ?>">
    <?php else: ?>
    <link rel="icon" href="<?php echo $basePath; ?>favicon.svg" type="image/svg+xml">
    <?php endif; ?>

    <!-- PWA manifest + theme color (action 287) -->
    <link rel="manifest" href="<?php echo $basePath; ?>manifest.webmanifest">
    <meta name="theme-color" content="<?= htmlspecialchars($tBrand) ?>">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">

    <!-- BHD Design Language Tokens -->
    <link rel="stylesheet" href="<?php echo $basePath; ?>assets/css/bhd-tokens.css">

    <!-- Cardify Design System: tokens + components + toast (Category K actions 296-320) -->
    <link rel="stylesheet" href="<?php echo $basePath; ?>assets/css/cardify-tokens.css">
    <link rel="stylesheet" href="<?php echo $basePath; ?>assets/css/cardify-components.css">
    <link rel="stylesheet" href="<?php echo $basePath; ?>assets/css/cardify-toast.css">

    <!-- Fonts, Inter + (when rtl) IBM Plex Sans Arabic -->
    <link rel="preconnect" href="https://fonts.bhd.om">
    <link rel="preconnect" href="https://fonts.bhd.om" crossorigin>
    <?php if ($adminDir === 'rtl'): ?>
    <link href="https://fonts.bhd.om/css2?family=Inter:wght@300;400;500;600;700;800&family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <?php else: ?>
    <link href="https://fonts.bhd.om/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <?php endif; ?>

    <!-- Font Awesome 7.2 Pro (design.bhd.om), ?v busts stale CF cache -->
    <link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/fontawesome.min.css?v=7.2.0">
    <link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/solid.min.css?v=7.2.0">
    <link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/brands.min.css?v=7.2.0">
    
    <!-- Tailwind CSS (Local) -->
    <?php $tailwindVersion = @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/techwind/css/tailwind.min.css') ?: time(); ?>
    <link rel="stylesheet" href="/assets/techwind/css/tailwind.min.css?v=<?php echo $tailwindVersion; ?>">
    
    <!-- Flowbite CSS (Local) -->
    <?php $flowbiteCssVersion = @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/flowbite/app.css') ?: time(); ?>
    <link rel="stylesheet" href="/assets/flowbite/app.css?v=<?php echo $flowbiteCssVersion; ?>">
    <?php /* The utilities the site uses that neither Tailwind build contains.
       Both were generated on 16 Apr 2026 and never rebuilt, so classes added
       after that date rendered as nothing: text-start, rounded-3xl,
       scroll-mt-*, tabular-nums and 575 more. Generated by
       scripts/build-css-supplement.mjs; every rule in it is a class no other
       stylesheet defines, so nothing that renders today changes. */ ?>
    <?php $cardifySupplementVersion = @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/cardify-tailwind-supplement.css') ?: time(); ?>
    <link rel="stylesheet" href="/assets/css/cardify-tailwind-supplement.css?v=<?php echo $cardifySupplementVersion; ?>">
    
    <!-- Flag Icons CSS for country/phone dropdowns -->
    <link rel="stylesheet" href="/assets/vendor/flag-icons/css/flag-icons.min.css">

    <!-- Apple Pay JS SDK: registers <apple-pay-button> + (in non-Safari) installs
         window.ApplePaySession with the QR handoff. Loaded async so it never blocks
         paint; used by the inline checkout on order-checkout.php + card-credits.php. -->
    <link rel="preconnect" href="https://applepay.cdn-apple.com" crossorigin>
    <script async src="https://applepay.cdn-apple.com/jsapi/1.latest/apple-pay-sdk.js" crossorigin data-apple-pay-sdk></script>

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
    <link href="https://fonts.bhd.om/css2?family=Cairo:wght@400;500;600;700&family=Tajawal:wght@200;300;400;500;700;800;900&family=Almarai:wght@300;400;700;800&family=Noto+Kufi+Arabic:wght@400;500;600;700&family=IBM+Plex+Sans+Arabic:wght@100;200;300;400;500;600;700&family=Noto+Sans+Arabic:wght@400;500;600;700&family=Readex+Pro:wght@200;300;400;500;600;700&family=El+Messiri:wght@400;500;600;700&family=Changa:wght@200;300;400;500;600;700;800&family=Reem+Kufi:wght@400;500;600;700&family=Amiri:wght@400;700&family=Scheherazade+New:wght@400;500;600;700&family=Mada:wght@200;300;400;500;600;700;800;900&family=Lalezar&family=Lemonada:wght@300;400;500;600;700&family=Aref+Ruqaa:wght@400;700&family=Mirza:wght@400;500;600;700&family=Rakkas&family=Baloo+Bhaijaan+2:wght@400;500;600;700;800&family=Noto+Naskh+Arabic:wght@400;500;600;700&family=Noto+Nastaliq+Urdu:wght@400;500;600;700&family=Lateef:wght@200;300;400;500;600;700;800&family=Harmattan:wght@400;500;600;700&family=Markazi+Text:wght@400;500;600;700&family=Gulzar&display=swap" rel="stylesheet">
    <!-- English Sans-Serif (with Light 300, ExtraBold 800, and italics) -->
    <link href="https://fonts.bhd.om/css2?family=Inter:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Plus+Jakarta+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Montserrat:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Roboto:ital,wght@0,300;0,400;0,500;0,700;1,300;1,400;1,500;1,700&family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Open+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Lato:ital,wght@0,300;0,400;0,700;1,300;1,400;1,700&family=Nunito:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Raleway:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Work+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Outfit:wght@300;400;500;600;700;800&family=Manrope:wght@300;400;500;600;700;800&family=Urbanist:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Lexend:wght@300;400;500;600;700;800&family=Sora:wght@300;400;500;600;700;800&family=Rubik:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Quicksand:wght@300;400;500;600;700&family=Ubuntu:ital,wght@0,300;0,400;0,500;0,700;1,300;1,400;1,500;1,700&family=Barlow:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Jost:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&display=swap" rel="stylesheet">
    <!-- Extra Sans/Serif/Display/Script/Mono families (Myriad-Pro-style alternatives + more) -->
    <link href="https://fonts.bhd.om/css2?family=Source+Sans+3:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Hind:wght@300;400;500;600;700&family=Nunito+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Mulish:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=PT+Sans:ital,wght@0,400;0,700;1,400;1,700&family=Cabin:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500;1,600;1,700&family=Assistant:wght@300;400;500;600;700;800&family=Karla:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Figtree:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Red+Hat+Display:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Red+Hat+Text:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&family=Public+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Archivo:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=IBM+Plex+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&family=Onest:wght@300;400;500;600;700;800&family=Geist:wght@300;400;500;600;700;800&family=Source+Serif+4:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Fraunces:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Crimson+Pro:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Alegreya:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400;1,500;1,600;1,700&family=Libre+Caslon+Text:ital,wght@0,400;0,700;1,400&family=Newsreader:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&family=Roboto+Serif:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Abril+Fatface&family=Bungee&family=Comfortaa:wght@300;400;500;600;700&family=Josefin+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&family=Alfa+Slab+One&family=Chivo:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Russo+One&family=Unbounded:wght@300;400;500;600;700;800&family=Satisfy&family=Shadows+Into+Light&family=Homemade+Apple&family=Amatic+SC:wght@400;700&family=Parisienne&family=IBM+Plex+Mono:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&family=Courier+Prime:ital,wght@0,400;0,700;1,400;1,700&family=Inconsolata:wght@300;400;500;600;700;800&family=Vazirmatn:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Serif, Display, Handwriting, Monospace -->
    <link href="https://fonts.bhd.om/css2?family=Playfair+Display:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400;1,500;1,600;1,700&family=Merriweather:ital,wght@0,300;0,400;0,700;0,900;1,300;1,400;1,700;1,900&family=Lora:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500;1,600;1,700&family=PT+Serif:ital,wght@0,400;0,700;1,400;1,700&family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&family=EB+Garamond:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400;1,500;1,600;1,700&family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&family=Spectral:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Noto+Serif:ital,wght@0,400;0,700;1,400;1,700&family=Vollkorn:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400;1,500;1,600;1,700&family=Bodoni+Moda:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400;1,500;1,600;1,700&family=Bebas+Neue&family=Oswald:wght@300;400;500;600;700&family=Anton&family=Archivo+Black&family=Righteous&family=Teko:wght@300;400;500;600;700&family=Big+Shoulders+Display:wght@300;400;500;600;700;800&family=Fredoka:wght@300;400;500;600;700&family=Dancing+Script:wght@400;500;600;700&family=Pacifico&family=Great+Vibes&family=Sacramento&family=Allura&family=Lobster&family=Caveat:wght@400;500;600;700&family=Kaushan+Script&family=Roboto+Mono:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&family=JetBrains+Mono:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Fira+Code:wght@300;400;500;600;700&family=Source+Code+Pro:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700&family=Space+Mono:ital,wght@0,400;0,700;1,400;1,700&display=swap" rel="stylesheet">
    
    <!-- WebFontLoader -->
    <script src="https://ajax.googleapis.com/ajax/libs/webfont/1.6.26/webfont.js"></script>
    
    <!-- Fabric.js 7.1.0 for Card Editor -->
    <script src="https://cdn.jsdelivr.net/npm/fabric@7.1.0/dist/index.min.js"></script>
    
    <!-- QR Code Generator -->
    <script src="<?= htmlspecialchars(getBasePath()) ?>assets/js/qrcode-generator-1.4.4.min.js"></script>
    
    <!-- jsPDF for PDF Export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    
    <!-- PDF-lib for Vector PDF Export (preserves original PDF quality) -->
    <script src="https://unpkg.com/pdf-lib@1.17.1/dist/pdf-lib.min.js"></script>
    
    <!-- html2canvas (fallback) -->
    <script src="<?= htmlspecialchars(getBasePath()) ?>assets/js/html2canvas-1.4.1.min.js"></script>
    
    <!-- Alpine.js with Collapse plugin (self-hosted, pinned; collapse before core) -->
    <script defer src="/assets/js/alpine-collapse-3.15.12.min.js"></script>
    <script defer src="/assets/js/alpine-3.15.12.min.js"></script>
    
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
        .page-loader-svg { overflow: visible; }
        .page-loader-arc {
            transform-origin: 60px 60px;
            animation: pageLoaderSpin 1.2s linear infinite;
        }
        .page-loader-card {
            transform-origin: 60px 58px;
            animation: pageLoaderWobble 2.6s ease-in-out infinite;
        }
        .page-loader-dot { animation: pageLoaderDot 1.2s ease-in-out infinite; }
        .page-loader-dot.dot-2, .page-loader-dot.dot-4 { animation-delay: 0.6s; }
        .page-loader-dot.dot-3 { animation-delay: 0.4s; }
        @keyframes pageLoaderSpin { to { transform: rotate(360deg); } }
        @keyframes pageLoaderWobble {
            0%, 100% { transform: rotate(-3deg); }
            50% { transform: rotate(3deg); }
        }
        @keyframes pageLoaderDot {
            0%, 100% { opacity: 0.2; }
            50% { opacity: 1; }
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
            background: linear-gradient(135deg, #009bc1 0%, #067a98 100%);
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
      /* Tenant-branded loader: logo in centre + ::before ring spinner in
         --tbrand. Mirrors the portal-loader UX so admin and customer pages
         look visually consistent. */
      .page-loader-ring{position:relative;width:120px;height:120px;display:flex;align-items:center;justify-content:center}
      .page-loader-ring::before{content:'';position:absolute;inset:0;border-radius:50%;border:4px solid color-mix(in srgb, var(--tbrand) 12%, transparent);border-top-color:var(--tbrand);animation:adminLoaderSpin 1s linear infinite}
      .page-loader-ring img{max-width:70px;max-height:70px;object-fit:contain}
      @keyframes adminLoaderSpin{to{transform:rotate(360deg)}}
    </style>
    <?php /* Delegated behaviour, replacing the on* attributes. An inline
       handler is inline script, and a CSP without 'unsafe-inline' kills every
       one. Placed after the async stylesheets so its first pass already sees
       them. */ ?>
    <script src="/assets/js/cardify-actions.js?v=<?php echo @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/cardify-actions.js') ?: time(); ?>"></script>
</head>
<body class="bg-gray-50"<?php echo Impersonation::isActive() ? ' data-impersonating="true"' : ''; ?>>
    <?php Impersonation::renderBanner(); ?>
    <!-- Page Loader (auto-hides via CSS after 1s even without JS).
         When the tenant has a logo, we show it in a brand-coloured ring
         (matches portal-loader). Otherwise, fall back to the abstract
         Cardify card SVG (used on apex / brand-less tenants). -->
    <div class="page-loader" id="pageLoader">
        <?php if (!empty($tBrandLogo)): ?>
        <div class="page-loader-ring">
            <img src="<?= htmlspecialchars($tBrandLogo, ENT_QUOTES) ?>" alt="<?= htmlspecialchars($brandName, ENT_QUOTES) ?>" data-cardify-hide-on-error>
        </div>
        <?php else: ?>
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120" width="100" height="100" role="img" aria-label="Loading" class="page-loader-svg">
            <circle cx="60" cy="60" r="52" fill="none" stroke="#e2e8f0" stroke-width="6"/>
            <circle class="page-loader-arc" cx="60" cy="60" r="52" fill="none" stroke="var(--tbrand)" stroke-width="6" stroke-linecap="round" stroke-dasharray="60 266"/>
            <g class="page-loader-card">
                <rect x="26" y="36" width="68" height="44" rx="10" fill="var(--tbrand)"/>
                <rect x="34" y="44" width="52" height="28" rx="6" fill="#ffffff"/>
                <rect x="40" y="50" width="10" height="10" rx="5" fill="var(--tbrand)" opacity="0.85"/>
                <rect x="54" y="50" width="24" height="4" rx="2" fill="var(--tbrand)"/>
                <rect x="54" y="58" width="18" height="4" rx="2" fill="var(--tbrand)" opacity="0.4"/>
            </g>
            <circle class="page-loader-dot dot-1" cx="60" cy="12" r="3" fill="var(--tbrand)"/>
            <circle class="page-loader-dot dot-2" cx="108" cy="60" r="3" fill="var(--tbrand)"/>
            <circle class="page-loader-dot dot-3" cx="60" cy="108" r="3" fill="var(--tbrand)"/>
            <circle class="page-loader-dot dot-4" cx="12" cy="60" r="3" fill="var(--tbrand)"/>
        </svg>
        <?php endif; ?>
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
                    // Only jump to the apex super console when actually on the
                    // apex. A super_admin inside a tenant clicks the logo to go
                    // to THAT company's dashboard, not out to the global view.
                    $logoUrl = ($__isSuper && !$__onTenant) ? getBasePath() . 'admin/super/' : getAdminBasePath();
                    ?>
                    <a href="<?php echo $logoUrl; ?>" class="flex items-center mr-3 lg:mr-4">
                        <?php if (!empty($tBrandLogo)): ?>
                        <img src="<?= htmlspecialchars($tBrandLogo, ENT_QUOTES) ?>" class="h-8 w-auto" alt="<?php echo htmlspecialchars($brandName, ENT_QUOTES); ?>">
                        <?php else: ?>
                        <img src="<?php echo assetUrl('images/logo.svg'); ?>" class="h-8 w-auto" alt="<?php echo $brandName; ?>">
                        <?php endif; ?>
                    </a>

                    <?php
                    // Workspace chip: the always-on answer to "which company am I
                    // managing and who am I signed in as". Inside a tenant a
                    // super_admin is treated exactly like that company's admin,
                    // so the chip is neutral (brand dot, plain "Admin") there;
                    // the "Super Admin" identity only shows on the apex console.
                    $__wsName = $__isSuper && !$__onTenant
                        ? t('admin.chip_all_companies')
                        : ($tBrandName ?: ($brandName ?: 'Workspace'));
                    $__isPartner = ($__role === 'print_shop' || $__role === 'print_shop_operator');
                    $__roleLabel = ($__isSuper && !$__onTenant)
                        ? t('admin.chip_role_super')
                        : ($__isPartner ? t('printshopinternal.chip_role_partner') : t('admin.chip_role_admin'));
                    ?>
                    <div class="hidden md:flex items-center gap-2 mr-4 lg:mr-6 pl-3 pr-3.5 py-1.5 rounded-xl border border-gray-200 bg-gray-50/80 max-w-[16rem]"
                         title="<?= htmlspecialchars(t('admin.chip_signed_in_as', ['name' => $userName]) . ' · ' . $__roleLabel, ENT_QUOTES) ?>">
                        <span class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background:var(--tbrand)"></span>
                        <span class="min-w-0 leading-tight">
                            <span class="block text-[13px] font-semibold text-gray-900 truncate"><?= htmlspecialchars($__wsName) ?></span>
                            <span class="block text-[11px] text-gray-500 truncate"><?= htmlspecialchars($userName) ?> · <?= htmlspecialchars($__roleLabel) ?></span>
                        </span>
                    </div>
                    <?php if (!empty($__isPartner)): ?>
                    <a href="<?php echo getBasePath(); ?>printshop/clients.php" class="hidden md:inline-flex items-center gap-1.5 mr-3 text-xs font-medium text-[#00708c] hover:underline">
                        <i class="fa-solid fa-store"></i> <?= htmlspecialchars(t('printshopinternal.back_to_print_shop')) ?>
                    </a>
                    <?php endif; ?>

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
                        <?php $cardifyLangSwitchMode = 'query'; ?><?php require INCLUDES_DIR . '/lang-switcher.php'; ?>
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
                                    $dropdownDashboardUrl = ($currentRole === 'super_admin' && !$__onTenant) ? getBasePath() . 'admin/super/' : getAdminBasePath();
                                    ?>
                                    <a href="<?php echo $dropdownDashboardUrl; ?>" class="flex items-center gap-3 py-2 px-4 text-sm text-gray-700 hover:bg-gray-50 hover:text-blue-600 transition-colors">
                                        <i class="fa-solid fa-gauge-high w-4 text-gray-400"></i>Dashboard
                                    </a>
                                </li>
                                <li>
                                    <?php
                                    $ext = (defined('COMPANY_ADMIN_BASE') || !empty($_SESSION['company_slug'])) ? '' : '.php';
                                    // Apex super admins get account settings; a super_admin inside
                                    // a tenant (or a company admin) gets that company's theme settings.
                                    $settingsUrl = ($currentRole === 'super_admin' && !$__onTenant)
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
                        <?php foreach ($nav['main'] as $item):
                            // Active highlight matches the item's own key OR any
                            // key in its 'matches' array (used when a merged tab
                            // wraps multiple legacy pages — e.g. Orders matches
                            // requests/print/appointments; Brand matches theme/
                            // custom-domains/nfc-tags/short-links).
                            $matches = $item['matches'] ?? [];
                            $isActive = ($currentPage === $item['key']) || in_array($currentPage, $matches, true);
                            $showBadge = ($item['key'] === 'orders' || $item['key'] === 'requests') && $pendingRequestsCount > 0;
                        ?>
                        <li>
                            <a href="<?php echo htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8'); ?>"
                               class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg group transition-colors <?php echo $isActive ? 'text-blue-700 bg-blue-50 ring-1 ring-blue-100' : 'text-gray-700 hover:bg-gray-50 hover:text-gray-900'; ?>">
                                <i class="<?php echo htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8'); ?> w-5 h-5 transition-colors flex-shrink-0 <?php echo $isActive ? 'text-blue-600' : 'text-gray-400 group-hover:text-gray-600'; ?>"></i>
                                <span class="ml-3 flex-1"><?php echo htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php if ($showBadge): ?>
                                <span class="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 text-[11px] font-semibold text-white bg-red-500 rounded-full"><?php echo $pendingRequestsCount > 99 ? '99+' : $pendingRequestsCount; ?></span>
                                <?php elseif (!empty($item['badge'])): ?>
                                <span class="inline-flex items-center px-1.5 h-4 text-[10px] font-semibold tracking-wide text-emerald-700 bg-emerald-100 rounded"><?php echo htmlspecialchars($item['badge']); ?></span>
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
                                <span class="flex-1 ml-3 text-left whitespace-nowrap"><?= htmlspecialchars(t('admin.nav_settings_group')) ?></span>
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
                            <span class="ml-3"><?= htmlspecialchars(t('admin.nav_back_to_website')) ?></span>
                        </a>
                        <a href="<?php echo $basePath; ?>logout.php" class="flex items-center px-3 py-2.5 text-sm font-medium text-gray-700 rounded-lg transition-colors hover:bg-red-50 hover:text-red-600 group">
                            <i class="fa-solid fa-right-from-bracket w-5 h-5 text-gray-400 group-hover:text-red-500 transition-colors flex-shrink-0"></i>
                            <span class="ml-3"><?= htmlspecialchars(t('admin.nav_sign_out')) ?></span>
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
<?php if ($showTitleBar): ?>
                <!-- Page title -->
                <div class="mb-6 pb-4 border-b border-gray-200/80">
                    <h1 class="text-2xl font-bold tracking-tight text-gray-900 sm:text-3xl"><?php echo htmlspecialchars($pageTitle); ?></h1>
                </div>
<?php endif; ?>
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
    <script<?= cspNonceAttr() ?>>
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
    <script<?= cspNonceAttr() ?>>
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
    <script<?= cspNonceAttr() ?>>
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
