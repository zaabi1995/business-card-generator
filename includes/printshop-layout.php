<?php
/**
 * Print-shop shared chrome.
 *
 * Single source of truth for the print-shop fixed top nav and page
 * boilerplate. Mirrors `includes/admin-layout.php` for the tenant-admin
 * side; replaces 16 hand-rolled nav blocks across `printshop/*.php`.
 *
 * Usage:
 *   require_once INCLUDES_DIR . '/printshop-layout.php';
 *   printshopHeader('Page Title', 'orders');
 *   // ... page body ...
 *   printshopFooter();
 *
 * Resolves operator + shop context via PrintShopAuth::context(). Caller
 * is responsible for gating with PrintShopAuth::requireLogin() (or
 * requireInternalProvider() for internal-provider pages) BEFORE calling
 * printshopHeader, since the helper does not redirect.
 *
 * Brand: BHD teal #009bc1 (--cardify-primary-500). Active nav link uses
 * this color + a 2px bottom border so the operator always sees where
 * they are.
 */
require_once __DIR__ . '/PrintShopAuth.php';

if (!function_exists('printshopNavConfig')) {
    /**
     * Build the nav item config for a given shop. Every shop gets
     * Clients (attached tenants, or all companies for BHD internal
     * provider). Operators stay internal-provider only.
     *
     * @return array<string, array{label_key:string, url:string, icon:string, hide_below:?string}>
     */
    function printshopNavConfig(array $shop): array
    {
        $isInternal = !empty($shop['is_internal_provider']);
        $items = [
            'dashboard'         => ['label_key' => 'printshoplayout.nav_dashboard',         'url' => 'dashboard.php',         'icon' => 'fa-chart-pie',         'hide_below' => null],
            'orders'            => ['label_key' => 'printshoplayout.nav_orders',            'url' => 'orders.php',            'icon' => 'fa-box',               'hide_below' => null],
            'clients'           => ['label_key' => 'printshoplayout.nav_clients',           'url' => 'clients.php',           'icon' => 'fa-building',          'hide_below' => 'sm'],
            'analytics'         => ['label_key' => 'printshoplayout.nav_analytics',         'url' => 'analytics.php',         'icon' => 'fa-chart-line',        'hide_below' => 'sm'],
            'credit'            => ['label_key' => 'printshoplayout.nav_credit',            'url' => 'credit-accounts.php',   'icon' => 'fa-building-columns',  'hide_below' => 'md'],
            'client_pricing'    => ['label_key' => 'printshoplayout.nav_client_pricing',    'url' => 'client-pricing.php',    'icon' => 'fa-tags',              'hide_below' => 'lg'],
            'template_requests' => ['label_key' => 'printshoplayout.nav_template_requests', 'url' => 'template-requests.php', 'icon' => 'fa-file-pen',          'hide_below' => 'lg'],
        ];
        if ($isInternal) {
            $items['operators'] = ['label_key' => 'printshoplayout.nav_operators', 'url' => 'operators.php', 'icon' => 'fa-users-gear', 'hide_below' => 'lg'];
        }
        return $items;
    }
}

if (!function_exists('printshopStatusBadge')) {
    /**
     * Canonical 7-status badge. Maps print_orders.status enum values
     * to the existing Cardify badge variants.
     *
     * Status flow: pending -> submitted -> processing -> printing -> shipped -> delivered
     * Cancelled is the off-ramp.
     */
    function printshopStatusBadge(string $status): string
    {
        $variant = 'info';
        switch ($status) {
            case 'delivered':                                       $variant = 'success'; break;
            case 'cancelled':                                       $variant = 'danger';  break;
            case 'pending': case 'submitted':                       $variant = 'info';    break;
            case 'processing': case 'printing': case 'shipped':     $variant = 'info';    break;
            default:                                                $variant = 'info';    break;
        }
        $label = function_exists('t') ? t('printshopdash.status_' . $status) : ucfirst($status);
        if (str_starts_with($label, 'printshopdash.status_')) {
            $label = ucfirst($status);
        }
        return '<span class="cardify-badge cardify-badge--' . htmlspecialchars($variant) . '">' . htmlspecialchars($label) . '</span>';
    }
}

if (!function_exists('printshopHeader')) {
    /**
     * Emit the page <head> + opening <body> + canonical fixed top nav.
     *
     * @param string $pageTitle  Browser tab title (will be prefixed with shop name)
     * @param string $currentNav Key matching printshopNavConfig keys, e.g. 'orders', 'dashboard'
     * @param array  $opts       'extraHead' string for additional <link>/<style> tags,
     *                            'pageTitleTpl' optional t() key with :shop placeholder,
     *                            'wide' bool (true => max-w-screen-2xl, default max-w-7xl)
     */
    function printshopHeader(string $pageTitle = '', string $currentNav = 'dashboard', array $opts = []): void
    {
        $ctx  = PrintShopAuth::context();
        $shop = $ctx['shop'] ?? null;
        $operator = $ctx['operator'] ?? null;

        if (!$shop) {
            // Defensive fallback. Caller should have called requireLogin already.
            header('Location: ' . getBasePath() . 'printshop/login.php');
            exit;
        }

        $titleTpl = $opts['pageTitleTpl'] ?? '';
        if ($titleTpl && function_exists('t')) {
            $titleTr = t($titleTpl, ['shop' => $shop['name']]);
            if ($titleTr !== $titleTpl) {
                $pageTitle = $titleTr;
            }
        }
        if ($pageTitle === '') {
            $pageTitle = ($shop['name'] ?? 'Print shop') . ' , ' . t('printshoplayout.nav_dashboard');
        }

        $isInternal = !empty($shop['is_internal_provider']);
        $isVerified = !empty($shop['is_verified']) || !empty($shop['verified']);

        $GLOBALS['__printshop_page_wide'] = !empty($opts['wide']);
        $GLOBALS['__printshop_in_layout'] = true;

        $bodyClass = 'bg-gray-50 printshop-layout';
        $extraHead = $opts['extraHead'] ?? '';

        // Pass to ui-header
        $GLOBALS['pageTitle'] = $pageTitle;
        $GLOBALS['bodyClass'] = $bodyClass;
        // ui-header expects $pageTitle/$bodyClass as locals in scope
        // when require'd; replicate by setting them in this function's
        // scope before requiring.
        $pageTitle = $pageTitle;
        require_once INCLUDES_DIR . '/ui-header.php';

        // Inline CSS that ui-header doesn't ship: print-shop nav active state
        // and a few utility tweaks. Tiny, scoped to .printshop-layout, OK to inline.
        ?>
<style>
.printshop-layout .ps-nav { background: #ffffff; border-bottom: 1px solid var(--cardify-border, #e5e7eb); }
.printshop-layout .ps-nav-link { color: var(--cardify-text-muted, #6b7280); font-weight: 500; padding: 6px 2px; transition: color 150ms; }
.printshop-layout .ps-nav-link:hover { color: var(--cardify-text, #111827); }
.printshop-layout .ps-nav-link.is-active {
    color: var(--cardify-primary-500, #009bc1);
    font-weight: 600;
    border-bottom: 2px solid var(--cardify-primary-500, #009bc1);
}
.printshop-layout .ps-nav-icon { font-size: 13px; }
.printshop-layout .ps-shop-name { font-family: var(--cardify-font-sans, "Inter", sans-serif); font-weight: 600; color: #111827; font-size: 14px; }
.printshop-layout .ps-shop-divider { width: 1px; height: 18px; background: var(--cardify-border, #e5e7eb); }
.printshop-layout .ps-internal-badge {
    display:inline-flex; align-items:center; gap:4px;
    padding:2px 8px; font-size:11px; font-weight:600;
    border-radius: 999px;
    background: var(--cardify-primary-50, #e6f5f9);
    color: var(--cardify-primary-700, #00708c);
}
.printshop-layout .ps-verified-badge {
    display:inline-flex; align-items:center; gap:4px;
    padding:2px 8px; font-size:11px; font-weight:600;
    border-radius: 999px;
    background: #dcfce7; color: #166534;
}
.printshop-layout .ps-content-wrap { padding-top: 80px; padding-bottom: 64px; }
@media (max-width: 640px) {
    .printshop-layout .ps-nav-link { padding: 6px 0; }
}

/* Tailwind-ish utility for BHD teal accents that aren't the primary button */
.printshop-layout .text-cardify-primary { color: var(--cardify-primary-500, #009bc1); }
.printshop-layout .hover\:text-cardify-primary:hover { color: var(--cardify-primary-500, #009bc1); }
.printshop-layout .bg-cardify-primary-50 { background-color: var(--cardify-primary-50, #e6f5f9); }
.printshop-layout .text-cardify-primary-700 { color: var(--cardify-primary-700, #00708c); }
.printshop-layout .ring-cardify-primary { box-shadow: 0 0 0 3px var(--cardify-shadow-focus, rgba(0,155,193,0.25)); }

/* Reusable button helper for CTAs that already use Tailwind shape but
   want BHD teal. .cardify-btn--primary does the same; this is just for
   inline anchors that don't take a class refactor without churn. */
.printshop-layout .ps-btn-primary {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 8px 16px; font-size: 14px; font-weight: 600; line-height: 1;
    border-radius: 8px;
    background: var(--cardify-primary-500, #009bc1);
    color: #ffffff;
    border: 1px solid var(--cardify-primary-500, #009bc1);
    transition: background 150ms;
}
.printshop-layout .ps-btn-primary:hover { background: var(--cardify-primary-600, #0086a6); border-color: var(--cardify-primary-600, #0086a6); }
.printshop-layout .ps-btn-secondary {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 8px 16px; font-size: 14px; font-weight: 500; line-height: 1;
    border-radius: 8px;
    background: #ffffff;
    color: #374151;
    border: 1px solid #e5e7eb;
    transition: background 150ms;
}
.printshop-layout .ps-btn-secondary:hover { background: #f9fafb; }

/* Cardify brand bridge: any Tailwind bg-blue-600 / hover:bg-blue-700 inside
   the print shop pages renders as BHD teal so we don't have to touch every
   button individually. text-blue-600 / 700 follow the same rule for links.
   Specificity bumped past Tailwind utilities by scoping under .printshop-layout. */
.printshop-layout .bg-blue-600 { background-color: var(--cardify-primary-500, #009bc1); }
.printshop-layout .hover\:bg-blue-700:hover { background-color: var(--cardify-primary-600, #0086a6); }
.printshop-layout .text-blue-600 { color: var(--cardify-primary-500, #009bc1); }
.printshop-layout .text-blue-700 { color: var(--cardify-primary-700, #00708c); }
.printshop-layout .border-blue-200 { border-color: var(--cardify-primary-100, #b3e0ec); }
.printshop-layout .focus\:border-blue-500:focus { border-color: var(--cardify-primary-500, #009bc1); }
.printshop-layout .focus\:ring-blue-500:focus { --tw-ring-color: var(--cardify-primary-500, #009bc1); box-shadow: 0 0 0 3px var(--cardify-shadow-focus, rgba(0,155,193,0.25)); }
.printshop-layout .bg-blue-50 { background-color: var(--cardify-primary-50, #e6f5f9); }
.printshop-layout .bg-blue-100 { background-color: var(--cardify-primary-100, #b3e0ec); }
.printshop-layout .file\:bg-blue-50::file-selector-button { background-color: var(--cardify-primary-50, #e6f5f9); }
.printshop-layout .file\:text-blue-700::file-selector-button { color: var(--cardify-primary-700, #00708c); }
</style>
<?php if ($extraHead): echo $extraHead; endif; ?>

<nav class="ps-nav fixed top-0 left-0 right-0 z-40">
    <div class="<?= $GLOBALS['__printshop_page_wide'] ? 'max-w-screen-2xl' : 'max-w-7xl' ?> mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16">
            <!-- Brand + shop -->
            <div class="flex items-center gap-3 min-w-0">
                <a href="<?= getBasePath() ?>" class="flex items-center gap-2">
                    <img src="<?= getBasePath() ?>assets/images/logo.svg" alt="Cardify" class="h-7 w-auto">
                </a>
                <span class="ps-shop-divider"></span>
                <span class="ps-shop-name truncate max-w-[160px] sm:max-w-xs"><?= sanitize($shop['name'] ?? '') ?></span>
                <?php if ($isVerified): ?>
                <span class="ps-verified-badge"><i class="fa-solid fa-circle-check"></i><?= htmlspecialchars(t('printshoplayout.badge_verified')) ?></span>
                <?php endif; ?>
                <?php if ($isInternal): ?>
                <span class="ps-internal-badge"><i class="fa-solid fa-building"></i><?= htmlspecialchars(t('printshoplayout.badge_internal_provider')) ?></span>
                <?php endif; ?>
            </div>

            <!-- Nav items -->
            <div class="flex items-center gap-4 sm:gap-6 text-sm">
                <?php
                $navItems = printshopNavConfig($shop);
                foreach ($navItems as $key => $item):
                    $isActive = ($key === $currentNav);
                    $hideClass = '';
                    if (!empty($item['hide_below'])) {
                        $hideClass = 'hidden ' . $item['hide_below'] . ':inline-flex';
                    } else {
                        $hideClass = 'inline-flex';
                    }
                ?>
                <a href="<?= htmlspecialchars($item['url']) ?>"
                   class="ps-nav-link <?= $hideClass ?> items-center gap-1.5 <?= $isActive ? 'is-active' : '' ?>">
                    <i class="fa-solid <?= htmlspecialchars($item['icon']) ?> ps-nav-icon"></i>
                    <span class="hidden sm:inline"><?= htmlspecialchars(t($item['label_key'])) ?></span>
                </a>
                <?php endforeach; ?>

                <a href="settings.php" class="ps-nav-link <?= $currentNav === 'settings' ? 'is-active' : '' ?>" aria-label="<?= htmlspecialchars(t('printshoplayout.nav_settings')) ?>">
                    <i class="fa-solid fa-gear ps-nav-icon"></i>
                </a>
                <a href="<?= getBasePath() ?>logout.php" class="ps-nav-link" aria-label="<?= htmlspecialchars(t('printshoplayout.nav_logout')) ?>">
                    <i class="fa-solid fa-right-from-bracket ps-nav-icon"></i>
                </a>
            </div>
        </div>
    </div>
</nav>

<main class="ps-content-wrap <?= $GLOBALS['__printshop_page_wide'] ? 'max-w-screen-2xl' : 'max-w-7xl' ?> mx-auto px-4 sm:px-6 lg:px-8">
        <?php
    }
}

if (!function_exists('printshopFooter')) {
    function printshopFooter(): void
    {
        ?>
</main>
        <?php
        require_once INCLUDES_DIR . '/ui-footer.php';
    }
}
