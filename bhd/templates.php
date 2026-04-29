<?php
/**
 * BHD Printing, Public Template Browser
 * Customers can browse card templates and customize them
 */
require_once __DIR__ . '/../config.php';

// BHD print shop ID is 2
$BHD_SHOP_ID = 2;

$db = Database::getInstance();

// Fetch active templates for BHD
$templates = $db->fetchAll(
    "SELECT * FROM shop_templates WHERE print_shop_id = :shop AND is_active = 1 ORDER BY sort_order ASC, created_at DESC",
    ['shop' => $BHD_SHOP_ID]
);

// Parse field_definitions
foreach ($templates as &$t) {
    $t['field_definitions'] = json_decode($t['field_definitions'] ?? '[]', true) ?: [];
}

$pageTitle = 'Choose Your Card Design, BHD Printing';
$pageDescription = 'Browse professional business card templates from BHD Printing. Pick a design, add your details, and order.';
$bodyClass = 'bg-gray-50';

$extraHead = '
<style>
    .template-card { transition: transform 0.15s, box-shadow 0.15s; }
    .template-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,0.12); }
</style>
';

require_once INCLUDES_DIR . '/ui-header.php';
?>

<!-- Nav -->
<nav class="bg-white border-b border-gray-200">
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-14">
            <div class="flex items-center gap-3">
                <img src="<?= getBasePath() ?>assets/images/logo.svg" alt="Cardify" class="h-7 w-auto">
                <span class="text-gray-300 text-sm">×</span>
                <span class="text-sm font-medium text-gray-700">BHD Printing</span>
            </div>
            <a href="<?= getBasePath() ?>bhd/" class="text-sm text-gray-500 hover:text-gray-700">← Back to BHD</a>
        </div>
    </div>
</nav>

<!-- Hero -->
<div class="bg-white border-b border-gray-100">
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-10 text-center">
        <p class="text-xs font-semibold uppercase tracking-widest text-blue-600 mb-2">BHD Printing Exclusive</p>
        <h1 class="text-3xl sm:text-4xl font-bold text-gray-900 mb-3">Choose Your Card Design</h1>
        <p class="text-gray-500 max-w-lg mx-auto">Pick a template below, fill in your details, preview your card, and we'll print it for you.</p>
    </div>
</div>

<!-- Templates -->
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-10">

    <?php if (empty($templates)): ?>
    <!-- No templates yet -->
    <div class="text-center py-20">
        <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <i class="fa-solid fa-layer-group text-2xl text-gray-300"></i>
        </div>
        <h2 class="text-xl font-semibold text-gray-600 mb-2">Templates Coming Soon</h2>
        <p class="text-sm text-gray-400 mb-6">BHD Printing is uploading their templates. Check back soon!</p>
        <a href="https://api.whatsapp.com/send?phone=96899999100&text=Hi+BHD+Printing%2C+I'd+like+to+order+business+cards"
           target="_blank"
           class="inline-flex items-center gap-2 bg-green-500 hover:bg-green-600 text-white text-sm font-medium px-5 py-2.5 rounded-lg transition-colors">
            <i class="fa-brands fa-whatsapp text-base"></i>
            Contact BHD on WhatsApp
        </a>
    </div>
    <?php else: ?>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
        <?php foreach ($templates as $tmpl): ?>
        <?php
            $thumbUrl = $tmpl['thumbnail_path']
                ? getBasePath() . ltrim($tmpl['thumbnail_path'], '/')
                : ($tmpl['background_path'] ? getBasePath() . ltrim($tmpl['background_path'], '/') : null);
            $fieldCount = count($tmpl['field_definitions']);
        ?>
        <div class="template-card bg-white rounded-2xl border border-gray-200 overflow-hidden cursor-pointer group"
             onclick="window.location='<?= getBasePath() ?>customize.php?template=<?= urlencode($tmpl['id']) ?>'">
            <!-- Preview image -->
            <div class="aspect-[3.5/2] bg-gradient-to-br from-gray-100 to-gray-200 overflow-hidden relative">
                <?php if ($thumbUrl): ?>
                    <img src="<?= sanitize($thumbUrl) ?>"
                         alt="<?= sanitize($tmpl['name']) ?>"
                         class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300">
                <?php else: ?>
                    <div class="absolute inset-0 flex flex-col items-center justify-center text-gray-300">
                        <i class="fa-regular fa-credit-card text-4xl mb-2"></i>
                        <span class="text-xs">No preview</span>
                    </div>
                <?php endif; ?>
                <!-- Hover overlay -->
                <div class="absolute inset-0 bg-blue-600 bg-opacity-0 group-hover:bg-opacity-10 transition-all duration-200 flex items-center justify-center">
                    <span class="opacity-0 group-hover:opacity-100 transition-opacity bg-white text-blue-700 text-xs font-semibold px-3 py-1.5 rounded-full shadow">
                        Customize →
                    </span>
                </div>
            </div>
            <!-- Info -->
            <div class="p-4">
                <h3 class="font-semibold text-gray-900"><?= sanitize($tmpl['name']) ?></h3>
                <?php if ($tmpl['description']): ?>
                    <p class="text-xs text-gray-500 mt-0.5 line-clamp-2"><?= sanitize($tmpl['description']) ?></p>
                <?php endif; ?>
                <div class="mt-3 flex items-center justify-between">
                    <div class="flex flex-wrap gap-1">
                        <?php foreach (array_slice($tmpl['field_definitions'], 0, 3) as $field): ?>
                            <span class="text-xs bg-gray-100 text-gray-500 px-2 py-0.5 rounded-full"><?= sanitize($field['label'] ?? '') ?></span>
                        <?php endforeach; ?>
                        <?php if ($fieldCount > 3): ?>
                            <span class="text-xs text-gray-400">+<?= $fieldCount - 3 ?> more</span>
                        <?php endif; ?>
                    </div>
                    <button class="text-xs font-medium text-blue-600 group-hover:text-blue-700 flex-shrink-0 ml-2">
                        Customize →
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- CTA footer -->
    <div class="mt-12 text-center">
        <p class="text-sm text-gray-500 mb-3">Don't see what you're looking for?</p>
        <a href="https://api.whatsapp.com/send?phone=96899999100&text=Hi+BHD+Printing%2C+I'd+like+a+custom+business+card+design"
           target="_blank"
           class="inline-flex items-center gap-2 bg-green-500 hover:bg-green-600 text-white text-sm font-medium px-5 py-2.5 rounded-lg transition-colors">
            <i class="fa-brands fa-whatsapp text-base"></i>
            Request Custom Design
        </a>
    </div>
    <?php endif; ?>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
