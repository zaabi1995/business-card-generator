<?php
/**
 * Brand — unified surface for Theme + Custom Domains + NFC Tags + Short Links.
 *
 * Commit 1 (sidebar IA consolidation) ships this as a hub page that links
 * out to the existing controllers. Commit 3 (per the plan) embeds each
 * controller as a tabbed section via URL hash (#theme, #domains, #nfc, #links).
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';

requireAdmin();
$companyId = getCurrentCompanyId();
if (!$companyId) { header('Location: ' . getBasePath() . 'login.php'); exit; }

$basePath = getAdminBasePath();
$isCompanyAdmin = defined('COMPANY_ADMIN_BASE') || !empty($_SESSION['company_slug']);
$ext = $isCompanyAdmin ? '' : '.php';

adminHeader('Brand', 'brand');
?>

<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <header class="mb-8">
        <h1 class="text-2xl font-bold text-gray-900">Brand</h1>
        <p class="text-sm text-gray-500 mt-1">Theme, custom domain, NFC tags, short links — your brand surfaces in one place.</p>
    </header>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <a href="<?= htmlspecialchars($basePath . 'theme' . $ext, ENT_QUOTES) ?>"
           class="group p-6 bg-white rounded-2xl border border-gray-200 hover:border-blue-300 hover:shadow-sm transition-all">
            <div class="w-10 h-10 rounded-xl bg-pink-50 flex items-center justify-center group-hover:bg-pink-100 transition-colors mb-3">
                <i class="fa-solid fa-palette text-pink-600"></i>
            </div>
            <h3 class="font-semibold text-gray-900 mb-1">Theme</h3>
            <p class="text-sm text-gray-500">Logo, colors, favicon, card design defaults.</p>
        </a>

        <a href="<?= htmlspecialchars($basePath . 'custom-domains' . $ext, ENT_QUOTES) ?>"
           class="group p-6 bg-white rounded-2xl border border-gray-200 hover:border-blue-300 hover:shadow-sm transition-all">
            <div class="w-10 h-10 rounded-xl bg-indigo-50 flex items-center justify-center group-hover:bg-indigo-100 transition-colors mb-3">
                <i class="fa-solid fa-globe text-indigo-600"></i>
            </div>
            <h3 class="font-semibold text-gray-900 mb-1">Custom Domains</h3>
            <p class="text-sm text-gray-500">Serve cards from your own domain (e.g. cards.yourcompany.com).</p>
        </a>

        <a href="<?= htmlspecialchars($basePath . 'nfc/batch.php', ENT_QUOTES) ?>"
           class="group p-6 bg-white rounded-2xl border border-gray-200 hover:border-blue-300 hover:shadow-sm transition-all">
            <div class="w-10 h-10 rounded-xl bg-teal-50 flex items-center justify-center group-hover:bg-teal-100 transition-colors mb-3">
                <i class="fa-solid fa-wifi text-teal-600"></i>
            </div>
            <h3 class="font-semibold text-gray-900 mb-1">NFC Tags</h3>
            <p class="text-sm text-gray-500">Program NFC tags for tap-to-share business cards.</p>
        </a>

        <a href="<?= htmlspecialchars($basePath . 'short-links' . $ext, ENT_QUOTES) ?>"
           class="group p-6 bg-white rounded-2xl border border-gray-200 hover:border-blue-300 hover:shadow-sm transition-all">
            <div class="w-10 h-10 rounded-xl bg-amber-50 flex items-center justify-center group-hover:bg-amber-100 transition-colors mb-3">
                <i class="fa-solid fa-link text-amber-600"></i>
            </div>
            <h3 class="font-semibold text-gray-900 mb-1">Short Links</h3>
            <p class="text-sm text-gray-500">Branded short URLs with click tracking.</p>
        </a>
    </div>
</div>

<?php adminFooter(); ?>
