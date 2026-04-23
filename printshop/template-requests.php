<?php
/**
 * Print Shop Template Requests
 * View and manage customer card requests from the template workflow
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

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) die(htmlspecialchars(t('printshoptpl.invalid_request')));
    $action = $_POST['action'] ?? '';

    if ($action === 'update_status') {
        $requestId = $_POST['request_id'] ?? '';
        $newStatus = $_POST['status'] ?? '';
        $allowed = ['pending', 'confirmed', 'printing', 'ready', 'delivered', 'cancelled'];
        if ($requestId && in_array($newStatus, $allowed)) {
            $req = $db->fetchOne("SELECT * FROM shop_template_requests WHERE id = :id AND print_shop_id = :shop", ['id' => $requestId, 'shop' => $shopId]);
            if ($req) {
                $db->update('shop_template_requests', ['status' => $newStatus], 'id = :id', ['id' => $requestId]);
                $stk = 'printshoptpl.status_' . $newStatus;
                $stl = t($stk);
                if ($stl === $stk) $stl = ucfirst($newStatus);
                $message = str_replace(':status', $stl, t('printshoptpl.status_updated'));
            }
        }
    }
}

// Filter
$statusFilter = $_GET['status'] ?? '';
$params = ['shop' => $shopId];
$where = 'r.print_shop_id = :shop';
if ($statusFilter) {
    $where .= ' AND r.status = :status';
    $params['status'] = $statusFilter;
}

$requests = $db->fetchAll(
    "SELECT r.*, t.name as template_name, t.thumbnail_path
     FROM shop_template_requests r
     LEFT JOIN shop_templates t ON r.template_id = t.id
     WHERE $where
     ORDER BY r.created_at DESC
     LIMIT 100",
    $params
);

// Counts by status
$counts = $db->fetchAll(
    "SELECT status, COUNT(*) as n FROM shop_template_requests WHERE print_shop_id = :shop GROUP BY status",
    ['shop' => $shopId]
);
$countMap = [];
$total = 0;
foreach ($counts as $c) { $countMap[$c['status']] = $c['n']; $total += $c['n']; }

$pageTitle = t('printshoppages.title_template_requests');
$bodyClass = 'bg-gray-50';
require_once INCLUDES_DIR . '/ui-header.php';
?>

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
                <a href="dashboard.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-chart-pie mr-1"></i><?= htmlspecialchars(t('printshoptpl.nav_dashboard')) ?></a>
                <a href="orders.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-box mr-1"></i><?= htmlspecialchars(t('printshoptpl.nav_orders')) ?></a>
                <a href="templates.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-layer-group mr-1"></i><?= htmlspecialchars(t('printshoptpl.nav_templates')) ?></a>
                <a href="settings.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-cog"></i></a>
            </div>
        </div>
    </div>
</nav>

<div class="pt-20 pb-12 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto">

    <?php if ($message): ?>
    <div class="mb-4 p-3 rounded-lg <?= $messageType === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200' ?> text-sm">
        <?= sanitize($message) ?>
    </div>
    <?php endif; ?>

    <div class="flex items-center justify-between mb-5">
        <div>
            <h1 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars(t("printshoppages.h1_template_requests")) ?></h1>
            <p class="text-sm text-gray-500 mt-0.5"><?= htmlspecialchars(t('printshoptpl.page_sub')) ?></p>
        </div>
        <a href="templates.php" class="text-sm text-blue-600 hover:underline">
            <i class="fa-solid fa-arrow-left mr-1"></i><?= htmlspecialchars(t('printshoptpl.nav_templates')) ?>
        </a>
    </div>

    <!-- Status filter tabs -->
    <div class="flex gap-2 flex-wrap mb-5">
        <?php
        $tabs = [
            ''          => str_replace(':n', (string) $total, t('printshoptpl.tab_all')),
            'pending'   => str_replace(':n', (string) ($countMap['pending'] ?? 0), t('printshoptpl.tab_pending')),
            'confirmed' => str_replace(':n', (string) ($countMap['confirmed'] ?? 0), t('printshoptpl.tab_confirmed')),
            'printing'  => str_replace(':n', (string) ($countMap['printing'] ?? 0), t('printshoptpl.tab_printing')),
            'ready'     => str_replace(':n', (string) ($countMap['ready'] ?? 0), t('printshoptpl.tab_ready')),
            'delivered' => str_replace(':n', (string) ($countMap['delivered'] ?? 0), t('printshoptpl.tab_delivered')),
            'cancelled' => str_replace(':n', (string) ($countMap['cancelled'] ?? 0), t('printshoptpl.tab_cancelled')),
        ];
        foreach ($tabs as $val => $label):
        ?>
        <a href="?status=<?= $val ?>"
           class="text-xs px-3 py-1.5 rounded-full font-medium transition-colors <?= $statusFilter === $val ? 'bg-blue-600 text-white' : 'bg-white border border-gray-200 text-gray-600 hover:bg-gray-50' ?>">
            <?= htmlspecialchars($label) ?>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if (empty($requests)): ?>
    <div class="text-center py-16 bg-white rounded-xl border border-dashed border-gray-200">
        <i class="fa-solid fa-inbox text-3xl text-gray-300 mb-3 block"></i>
        <h3 class="text-gray-500 font-medium"><?= htmlspecialchars(t('printshoptpl.empty_h')) ?></h3>
        <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars(t('printshoptpl.empty_body')) ?></p>
    </div>
    <?php else: ?>

    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-100 text-xs text-gray-500 uppercase tracking-wide">
                    <th class="px-4 py-3 text-left"><?= htmlspecialchars(t('printshoptpl.col_customer')) ?></th>
                    <th class="px-4 py-3 text-left"><?= htmlspecialchars(t('printshoptpl.col_template')) ?></th>
                    <th class="px-4 py-3 text-left"><?= htmlspecialchars(t('printshoptpl.col_details')) ?></th>
                    <th class="px-4 py-3 text-left"><?= htmlspecialchars(t('printshoptpl.col_qty')) ?></th>
                    <th class="px-4 py-3 text-left"><?= htmlspecialchars(t('printshoptpl.col_status')) ?></th>
                    <th class="px-4 py-3 text-left"><?= htmlspecialchars(t('printshoptpl.col_date')) ?></th>
                    <th class="px-4 py-3 text-left"><?= htmlspecialchars(t('printshoptpl.col_actions')) ?></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
            <?php foreach ($requests as $req): ?>
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
                $fieldValues = json_decode($req['field_values'] ?? '{}', true) ?: [];
                $thumbUrl = $req['thumbnail_path'] ? getBasePath() . ltrim($req['thumbnail_path'], '/') : null;
            ?>
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3">
                    <p class="font-medium text-gray-900"><?= sanitize($req['customer_name'] ?: t('printshoptpl.anonymous')) ?></p>
                    <?php if ($req['customer_phone']): ?>
                    <a href="https://api.whatsapp.com/send?phone=<?= preg_replace('/\D/', '', $req['customer_phone']) ?>&text=Hi+<?= urlencode($req['customer_name'] ?? '') ?>%2C+your+business+card+from+BHD+Printing"
                       target="_blank"
                       class="text-xs text-green-600 hover:text-green-700 flex items-center gap-0.5 mt-0.5">
                        <i class="fa-brands fa-whatsapp"></i>
                        <?= sanitize($req['customer_phone']) ?>
                    </a>
                    <?php endif; ?>
                    <?php if ($req['customer_email']): ?>
                    <p class="text-xs text-gray-400"><?= sanitize($req['customer_email']) ?></p>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3">
                    <div class="flex items-center gap-2">
                        <?php if ($thumbUrl): ?>
                        <img src="<?= sanitize($thumbUrl) ?>" class="w-12 h-7 object-cover rounded border border-gray-100 flex-shrink-0">
                        <?php endif; ?>
                        <span class="text-sm text-gray-700"><?= sanitize($req['template_name'] ?? t('printshoptpl.unknown_template')) ?></span>
                    </div>
                </td>
                <td class="px-4 py-3 max-w-xs">
                    <?php if (!empty($fieldValues)): ?>
                    <div class="text-xs text-gray-500 space-y-0.5">
                        <?php foreach (array_slice($fieldValues, 0, 3, true) as $k => $v): ?>
                        <?php if ($v): ?>
                        <div><span class="text-gray-400"><?= sanitize(ucfirst(str_replace('_', ' ', $k))) ?>:</span> <?= sanitize($v) ?></div>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($req['notes']): ?>
                    <p class="text-xs text-orange-600 mt-0.5"><i class="fa-solid fa-note-sticky mr-0.5"></i><?= sanitize($req['notes']) ?></p>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3 text-gray-700"><?= (int)$req['quantity'] ?></td>
                <td class="px-4 py-3">
                    <span class="text-xs font-medium px-2 py-0.5 rounded-full <?= $sc ?>"><?php $stk = 'printshoptpl.status_' . $req['status']; $stl = t($stk); echo htmlspecialchars($stl === $stk ? ucfirst($req['status']) : $stl); ?></span>
                </td>
                <td class="px-4 py-3 text-xs text-gray-400 whitespace-nowrap">
                    <?= date('M j, Y', strtotime($req['created_at'])) ?><br>
                    <?= date('g:ia', strtotime($req['created_at'])) ?>
                </td>
                <td class="px-4 py-3">
                    <form method="POST" class="flex items-center gap-1">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="request_id" value="<?= sanitize($req['id']) ?>">
                        <select name="status" class="text-xs border border-gray-200 rounded px-2 py-1 bg-white" onchange="this.form.submit()">
                            <?php foreach (['pending','confirmed','printing','ready','delivered','cancelled'] as $s): ?>
                            <option value="<?= $s ?>" <?= $req['status'] === $s ? 'selected' : '' ?>><?= htmlspecialchars(t('printshoptpl.status_' . $s)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <?php if ($req['preview_image_path']): ?>
                    <a href="<?= getBasePath() . ltrim($req['preview_image_path'], '/') ?>" target="_blank"
                       class="text-xs text-blue-500 hover:underline mt-1 block">
                        <i class="fa-solid fa-image mr-0.5"></i><?= htmlspecialchars(t('printshoptpl.preview_link')) ?>
                    </a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
