<?php
/**
 * Brand — unified surface for Theme + Custom Domains + NFC Tags + Short Links.
 *
 * Renders as a tabbed page: click a tab → full underlying admin page
 * opens. Each underlying controller (theme.php / custom-domains.php /
 * nfc/batch.php / short-links.php) is unchanged; this is pure IA
 * consolidation — the tab bar gives admins one stable landing for every
 * brand-shaping surface.
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

// ?tab=theme|domains|nfc|links (default theme). Each tab links to the
// existing controller so the form submits, AJAX endpoints, and feature
// gating stay exactly where they are.
$tab = $_GET['tab'] ?? 'theme';
$tabs = [
    'theme'   => ['label' => 'Theme',          'icon' => 'fa-solid fa-palette', 'url' => $basePath . 'theme' . $ext,           'blurb' => 'Logo, colors, favicon, card design defaults.'],
    'domains' => ['label' => 'Custom Domain',  'icon' => 'fa-solid fa-globe',   'url' => $basePath . 'custom-domains' . $ext,  'blurb' => 'Serve cards from your own domain.'],
    'nfc'     => ['label' => 'NFC Tags',       'icon' => 'fa-solid fa-wifi',    'url' => $basePath . 'nfc/batch.php',          'blurb' => 'Program NFC tags for tap-to-share.'],
    'links'   => ['label' => 'Short Links',    'icon' => 'fa-solid fa-link',    'url' => $basePath . 'short-links' . $ext,     'blurb' => 'Branded short URLs with click tracking.'],
];

adminHeader('Brand', 'brand');
?>

<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <header class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Brand</h1>
        <p class="text-sm text-gray-500 mt-1">Theme, custom domain, NFC tags, short links — your brand surfaces in one place.</p>
    </header>

    <!-- Tab strip -->
    <nav class="flex flex-wrap gap-2 mb-6 border-b border-gray-200">
        <?php foreach ($tabs as $key => $meta): $active = $tab === $key; ?>
        <a href="?tab=<?= $key ?>"
           class="inline-flex items-center gap-2 px-4 py-2.5 -mb-px text-sm font-medium border-b-2 transition-colors <?= $active ? 'text-blue-700 border-blue-600' : 'text-gray-600 border-transparent hover:text-gray-900 hover:border-gray-300' ?>">
            <i class="<?= htmlspecialchars($meta['icon']) ?>"></i>
            <?= htmlspecialchars($meta['label']) ?>
        </a>
        <?php endforeach; ?>
    </nav>

    <?php $current = $tabs[$tab] ?? $tabs['theme']; ?>
    <div class="bg-white rounded-2xl border border-gray-200 p-6">
        <div class="flex items-center justify-between mb-3">
            <div>
                <h2 class="font-semibold text-gray-900"><?= htmlspecialchars($current['label']) ?></h2>
                <p class="text-sm text-gray-500"><?= htmlspecialchars($current['blurb']) ?></p>
            </div>
            <a href="<?= htmlspecialchars($current['url'], ENT_QUOTES) ?>"
               class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700">
                Open <?= htmlspecialchars($current['label']) ?>
                <i class="fa-solid fa-arrow-right text-xs"></i>
            </a>
        </div>
        <p class="text-xs text-gray-400 mt-2">Tip: each section keeps its full feature set on its own page; this hub is just a faster way to jump between them.</p>
    </div>
</div>

<?php adminFooter(); ?>
