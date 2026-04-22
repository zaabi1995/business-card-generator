<?php
/**
 * Print Shop Template Library
 * Manage card templates that customers can customize and order
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/PrintShop.php';

Auth::requireLogin();
$user = Auth::getCurrentUser();

if ($user['role'] !== 'print_shop' && $user['role'] !== 'super_admin') {
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

$printShop = PrintShop::getByUserId($user['id']);
if (!$printShop && $user['role'] !== 'super_admin') {
    header('Location: ' . getBasePath() . 'printshop/register.php');
    exit;
}
if ($user['role'] === 'super_admin' && isset($_GET['shop'])) {
    $printShop = PrintShop::getById((int)$_GET['shop']);
}
if (!$printShop) {
    header('Location: ' . getBasePath() . 'admin/print_shops.php');
    exit;
}

$shopId = $printShop['id'];
$db = Database::getInstance();
$message = null;
$messageType = 'success';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        die('Invalid request');
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_active') {
        $templateId = $_POST['template_id'] ?? '';
        $template = $db->fetchOne("SELECT * FROM shop_templates WHERE id = :id AND print_shop_id = :shop", ['id' => $templateId, 'shop' => $shopId]);
        if ($template) {
            $newState = $template['is_active'] ? 0 : 1;
            $db->update('shop_templates', ['is_active' => $newState], 'id = :id', ['id' => $templateId]);
            $message = $newState ? 'Template activated.' : 'Template deactivated.';
        }
    } elseif ($action === 'delete') {
        $templateId = $_POST['template_id'] ?? '';
        $template = $db->fetchOne("SELECT * FROM shop_templates WHERE id = :id AND print_shop_id = :shop", ['id' => $templateId, 'shop' => $shopId]);
        if ($template) {
            // Delete files
            if ($template['background_path']) {
                $bgFile = BASE_DIR . $template['background_path'];
                if (file_exists($bgFile)) unlink($bgFile);
            }
            if ($template['thumbnail_path']) {
                $thumbFile = BASE_DIR . $template['thumbnail_path'];
                if (file_exists($thumbFile)) unlink($thumbFile);
            }
            $db->exec("DELETE FROM shop_templates WHERE id = :id", ['id' => $templateId]);
            $message = 'Template deleted.';
        }
    }
}

// Fetch templates for this shop
$templates = $db->fetchAll(
    "SELECT * FROM shop_templates WHERE print_shop_id = :shop ORDER BY sort_order ASC, created_at DESC",
    ['shop' => $shopId]
);

// Fetch recent requests
$recentRequests = $db->fetchAll(
    "SELECT r.*, t.name as template_name
     FROM shop_template_requests r
     LEFT JOIN shop_templates t ON r.template_id = t.id
     WHERE r.print_shop_id = :shop
     ORDER BY r.created_at DESC
     LIMIT 10",
    ['shop' => $shopId]
);

$pageTitle = t('printshoppages.title_templates');
$bodyClass = 'bg-gray-50';
require_once INCLUDES_DIR . '/ui-header.php';
?>

<!-- Nav -->
<nav class="bg-white border-b border-gray-200 fixed top-0 left-0 right-0 z-50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex items-center gap-4">
                <a href="<?= getBasePath() ?>printshop/dashboard.php" class="flex items-center gap-2">
                    <img src="<?= getBasePath() ?>assets/images/logo.svg" alt="Cardify" class="h-8 w-auto">
                </a>
                <span class="text-gray-300">|</span>
                <span class="font-semibold text-gray-900"><?= sanitize($printShop['name']) ?></span>
            </div>
            <div class="flex items-center gap-4 text-sm">
                <a href="dashboard.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-chart-pie mr-1"></i>Dashboard</a>
                <a href="orders.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-box mr-1"></i>Orders</a>
                <a href="templates.php" class="text-blue-600 font-medium"><i class="fa-solid fa-layer-group mr-1"></i>Templates</a>
                <a href="credit-accounts.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-building-columns mr-1"></i>Credit</a>
                <a href="settings.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-cog"></i></a>
            </div>
        </div>
    </div>
</nav>

<div class="pt-20 pb-12 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto">

    <?php if ($message): ?>
    <div class="mb-4 p-4 rounded-lg <?= $messageType === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200' ?>">
        <?= sanitize($message) ?>
    </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars(t("printshoppages.h1_templates")) ?></h1>
            <p class="text-sm text-gray-500 mt-1">Upload templates and let customers fill in their details to order</p>
        </div>
        <a href="<?= getBasePath() ?>printshop/template-editor.php"
           class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-4 py-2.5 rounded-lg transition-colors">
            <i class="fa-solid fa-plus"></i>
            New Template
        </a>
    </div>

    <!-- Customer Link Banner -->
    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-6 flex flex-col sm:flex-row items-start sm:items-center gap-3">
        <div class="flex-1">
            <p class="text-sm font-medium text-blue-800">Share with customers</p>
            <p class="text-xs text-blue-600 mt-0.5">Customers can browse and customize your templates at this link:</p>
            <code class="text-xs text-blue-700 font-mono mt-1 block"><?= getBaseUrl() ?>bhd/templates.php</code>
        </div>
        <button onclick="navigator.clipboard.writeText('<?= getBaseUrl() ?>bhd/templates.php').then(() => this.textContent = 'Copied!')"
                class="flex-shrink-0 text-xs bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded-lg transition-colors">
            Copy Link
        </button>
    </div>

    <?php if (empty($templates)): ?>
    <!-- Empty state -->
    <div class="text-center py-16 bg-white rounded-xl border border-dashed border-gray-300">
        <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <i class="fa-solid fa-layer-group text-2xl text-gray-400"></i>
        </div>
        <h3 class="text-lg font-semibold text-gray-700 mb-2">No templates yet</h3>
        <p class="text-sm text-gray-500 mb-6 max-w-sm mx-auto">Upload your first card template and define the editable fields your customers will fill in.</p>
        <a href="<?= getBasePath() ?>printshop/template-editor.php"
           class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-5 py-2.5 rounded-lg transition-colors">
            <i class="fa-solid fa-plus"></i>
            Create First Template
        </a>
    </div>
    <?php else: ?>

    <!-- Templates Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">
        <?php foreach ($templates as $tmpl): ?>
        <?php
            $fields = json_decode($tmpl['field_definitions'] ?? '[]', true) ?: [];
            $thumbUrl = $tmpl['thumbnail_path'] ? getBasePath() . ltrim($tmpl['thumbnail_path'], '/') : null;
        ?>
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden hover:shadow-md transition-shadow">
            <!-- Preview -->
            <div class="aspect-[3.5/2] bg-gray-100 relative overflow-hidden">
                <?php if ($thumbUrl): ?>
                    <img src="<?= sanitize($thumbUrl) ?>" alt="<?= sanitize($tmpl['name']) ?>" class="w-full h-full object-cover">
                <?php else: ?>
                    <div class="absolute inset-0 flex items-center justify-center">
                        <i class="fa-regular fa-image text-3xl text-gray-300"></i>
                    </div>
                <?php endif; ?>
                <!-- Status badge -->
                <div class="absolute top-2 right-2">
                    <?php if ($tmpl['is_active']): ?>
                        <span class="bg-green-100 text-green-700 text-xs font-medium px-2 py-0.5 rounded-full">Active</span>
                    <?php else: ?>
                        <span class="bg-gray-100 text-gray-500 text-xs font-medium px-2 py-0.5 rounded-full">Inactive</span>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Info -->
            <div class="p-4">
                <h3 class="font-semibold text-gray-900 text-sm"><?= sanitize($tmpl['name']) ?></h3>
                <?php if ($tmpl['description']): ?>
                    <p class="text-xs text-gray-500 mt-0.5 line-clamp-2"><?= sanitize($tmpl['description']) ?></p>
                <?php endif; ?>
                <!-- Fields summary -->
                <?php if (!empty($fields)): ?>
                <div class="mt-2 flex flex-wrap gap-1">
                    <?php foreach (array_slice($fields, 0, 4) as $field): ?>
                        <span class="text-xs bg-gray-100 text-gray-600 px-1.5 py-0.5 rounded"><?= sanitize($field['label'] ?? $field['key'] ?? '') ?></span>
                    <?php endforeach; ?>
                    <?php if (count($fields) > 4): ?>
                        <span class="text-xs text-gray-400">+<?= count($fields) - 4 ?> more</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <!-- Actions -->
                <div class="mt-3 flex items-center gap-2">
                    <a href="<?= getBasePath() ?>printshop/template-editor.php?id=<?= urlencode($tmpl['id']) ?>"
                       class="flex-1 text-center text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1.5 rounded-lg transition-colors">
                        <i class="fa-solid fa-pen mr-1"></i>Edit
                    </a>
                    <a href="<?= getBasePath() ?>customize.php?template=<?= urlencode($tmpl['id']) ?>" target="_blank"
                       class="flex-1 text-center text-xs bg-blue-50 hover:bg-blue-100 text-blue-700 px-3 py-1.5 rounded-lg transition-colors">
                        <i class="fa-solid fa-eye mr-1"></i>Preview
                    </a>
                    <form method="POST" class="inline" onsubmit="return confirm('Are you sure?')">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="toggle_active">
                        <input type="hidden" name="template_id" value="<?= sanitize($tmpl['id']) ?>">
                        <button type="submit" class="text-xs text-gray-400 hover:text-gray-600 px-2 py-1.5 rounded-lg transition-colors" title="<?= $tmpl['is_active'] ? 'Deactivate' : 'Activate' ?>">
                            <i class="fa-solid fa-<?= $tmpl['is_active'] ? 'toggle-on text-green-500' : 'toggle-off' ?>"></i>
                        </button>
                    </form>
                    <form method="POST" class="inline" onsubmit="return confirm('Delete this template? This cannot be undone.')">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="template_id" value="<?= sanitize($tmpl['id']) ?>">
                        <button type="submit" class="text-xs text-red-400 hover:text-red-600 px-2 py-1.5 rounded-lg transition-colors">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Recent Requests -->
    <?php if (!empty($recentRequests)): ?>
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
            <h2 class="font-semibold text-gray-900 text-sm"><?= htmlspecialchars(t("printshoppages.recent_customer_requests")) ?></h2>
            <a href="<?= getBasePath() ?>printshop/template-requests.php" class="text-xs text-blue-600 hover:underline">View all</a>
        </div>
        <div class="divide-y divide-gray-100">
            <?php foreach ($recentRequests as $req): ?>
            <?php
                $statusColors = [
                    'pending' => 'bg-yellow-100 text-yellow-700',
                    'confirmed' => 'bg-blue-100 text-blue-700',
                    'printing' => 'bg-purple-100 text-purple-700',
                    'ready' => 'bg-green-100 text-green-700',
                    'delivered' => 'bg-gray-100 text-gray-600',
                    'cancelled' => 'bg-red-100 text-red-700',
                ];
                $sc = $statusColors[$req['status']] ?? 'bg-gray-100 text-gray-600';
            ?>
            <div class="px-4 py-3 flex items-center gap-4">
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-gray-900 truncate"><?= sanitize($req['customer_name'] ?: 'Anonymous') ?></p>
                    <p class="text-xs text-gray-500 truncate"><?= sanitize($req['template_name'] ?? 'Unknown template') ?> &middot; <?= $req['quantity'] ?> cards</p>
                </div>
                <span class="text-xs font-medium px-2 py-0.5 rounded-full <?= $sc ?>"><?= ucfirst($req['status']) ?></span>
                <span class="text-xs text-gray-400 flex-shrink-0"><?= date('M j', strtotime($req['created_at'])) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
