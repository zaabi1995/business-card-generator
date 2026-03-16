<?php
/**
 * Print Shop Orders - View and manage all orders
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/PrintShop.php';
require_once INCLUDES_DIR . '/PrintShopIntegration.php';
require_once INCLUDES_DIR . '/Currency.php';

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

// For super admin viewing specific shop
if ($user['role'] === 'super_admin' && isset($_GET['shop'])) {
    $printShop = PrintShop::getById((int)$_GET['shop']);
}

if (!$printShop) {
    header('Location: ' . getBasePath() . 'admin/print_shops.php');
    exit;
}

$shopId = $printShop['id'];
$message = null;
$messageType = 'success';

// Handle status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    die('Invalid request');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_status') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        if ($orderId > 0) {
            $newStatus = $_POST['status'] ?? '';
            $trackingNumber = trim($_POST['tracking_number'] ?? '');
            $result = PrintShopIntegration::updateOrderStatus($orderId, $newStatus, $trackingNumber ?: null);
            if ($result['success']) {
                $message = "Order #$orderId updated to " . ucfirst($newStatus);
            } else {
                $message = "Error: " . ($result['error'] ?? 'Unknown');
                $messageType = 'error';
            }
        }

    } elseif ($action === 'bulk_update_status') {
        $orderIds = $_POST['order_ids'] ?? [];
        $newStatus = $_POST['bulk_status'] ?? '';
        $validStatuses = ['pending', 'processing', 'printing', 'shipped', 'delivered', 'cancelled'];

        if (empty($orderIds) || !in_array($newStatus, $validStatuses)) {
            $message = 'Please select orders and a valid status.';
            $messageType = 'error';
        } else {
            $db = Database::getInstance();
            $updated = 0;
            $errors = 0;
            foreach ($orderIds as $oid) {
                $oid = (int)$oid;
                if ($oid <= 0) continue;
                // Verify order belongs to this shop
                $order = $db->fetchOne("SELECT id FROM print_orders WHERE id = :id AND print_shop_id = :shop", [
                    'id' => $oid, 'shop' => $shopId
                ]);
                if (!$order) { $errors++; continue; }
                $result = PrintShopIntegration::updateOrderStatus($oid, $newStatus, null);
                if ($result['success']) $updated++;
                else $errors++;
            }
            $message = "Updated $updated order(s) to " . ucfirst($newStatus);
            if ($errors > 0) $message .= " ($errors failed)";
            if ($errors > 0 && $updated === 0) $messageType = 'error';
        }
    }
}

// Filter
$statusFilter = $_GET['status'] ?? '';
$orders = PrintShop::getOrders($shopId, $statusFilter ?: null, 100);

$statusColors = [
    'pending'    => 'bg-gray-100 text-gray-700',
    'confirmed'  => 'bg-blue-100 text-blue-700',
    'processing' => 'bg-purple-100 text-purple-700',
    'printing'   => 'bg-amber-100 text-amber-700',
    'shipped'    => 'bg-cyan-100 text-cyan-700',
    'delivered'  => 'bg-green-100 text-green-700',
    'cancelled'  => 'bg-red-100 text-red-700',
];

$pageTitle = 'Orders - ' . $printShop['name'];
$bodyClass = 'bg-gray-50';
require_once INCLUDES_DIR . '/ui-header.php';
?>

<div class="min-h-screen">
    <!-- Top Nav -->
    <nav class="bg-white border-b border-gray-200 fixed top-0 left-0 right-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center gap-4">
                    <a href="<?= getBasePath() ?>" class="flex items-center gap-2">
                        <img src="<?= getBasePath() ?>assets/images/logo.svg" alt="Cardify" class="h-8 w-auto">
                    </a>
                    <span class="text-gray-300">|</span>
                    <a href="dashboard.php" class="font-semibold text-gray-900 hover:text-blue-600">
                        <?= sanitize($printShop['name']) ?>
                    </a>
                </div>
                <div class="flex items-center gap-4 text-sm">
                    <a href="dashboard.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-chart-pie mr-1"></i>Dashboard</a>
                    <a href="orders.php" class="text-blue-600 font-medium"><i class="fa-solid fa-box mr-1"></i>Orders</a>
                    <a href="analytics.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-chart-line mr-1"></i>Analytics</a>
                    <a href="credit-accounts.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-building-columns mr-1"></i>Credit</a>
                    <a href="settings.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-cog"></i></a>
                    <a href="<?= getBasePath() ?>logout.php" class="text-gray-500 hover:text-red-600"><i class="fa-solid fa-sign-out-alt"></i></a>
                </div>
            </div>
        </div>
    </nav>

    <div class="pt-20 pb-12 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto">

        <!-- Header -->
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Orders</h1>
                <p class="text-gray-500">Manage incoming print orders</p>
            </div>
            <a href="analytics.php" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors text-sm">
                <i class="fa-solid fa-chart-line mr-2"></i>View Analytics
            </a>
        </div>

        <?php if ($message): ?>
        <div class="mb-6 p-4 rounded-xl <?= $messageType === 'success' ? 'bg-green-50 border border-green-200 text-green-700' : 'bg-red-50 border border-red-200 text-red-700' ?> flex items-center gap-3">
            <i class="fa-solid <?= $messageType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
            <?= sanitize($message) ?>
        </div>
        <?php endif; ?>

        <!-- Filter Tabs -->
        <div class="bg-white rounded-xl border border-gray-200 p-4 mb-4 flex items-center gap-2 overflow-x-auto">
            <a href="orders.php" class="px-4 py-2 rounded-lg text-sm font-medium whitespace-nowrap <?= empty($statusFilter) ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>">All</a>
            <?php foreach (['pending', 'processing', 'printing', 'shipped', 'delivered', 'cancelled'] as $status): ?>
            <a href="?status=<?= $status ?>" class="px-4 py-2 rounded-lg text-sm font-medium whitespace-nowrap <?= $statusFilter === $status ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>">
                <?= ucfirst($status) ?>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- Bulk Actions Bar (shown when items selected) -->
        <div id="bulkBar" class="hidden mb-4 p-3 bg-blue-50 border border-blue-200 rounded-xl flex items-center gap-3">
            <span class="text-sm text-blue-700 font-medium"><span id="selectedCount">0</span> selected</span>
            <form method="post" id="bulkForm" class="flex items-center gap-2">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="bulk_update_status">
                <!-- Hidden inputs for selected order IDs injected by JS -->
                <div id="bulkOrderIds"></div>
                <select name="bulk_status" class="text-sm border border-blue-300 rounded-lg px-3 py-1.5 bg-white focus:border-blue-500">
                    <option value="">-- Set status --</option>
                    <option value="processing">Processing</option>
                    <option value="printing">Printing</option>
                    <option value="shipped">Shipped</option>
                    <option value="delivered">Delivered</option>
                    <option value="cancelled">Cancelled</option>
                </select>
                <button type="submit" class="px-4 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition-colors">
                    Apply to Selected
                </button>
            </form>
            <button onclick="clearSelection()" class="ml-auto text-sm text-gray-500 hover:text-gray-700">Clear</button>
        </div>

        <!-- Orders List -->
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
            <?php if (empty($orders)): ?>
            <div class="p-12 text-center">
                <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fa-solid fa-inbox text-gray-400 text-2xl"></i>
                </div>
                <h3 class="text-lg font-medium text-gray-900 mb-2">No Orders Found</h3>
                <p class="text-gray-500"><?= $statusFilter ? "No $statusFilter orders" : "Orders will appear here when companies place them" ?></p>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-4 py-3 text-left">
                                <input type="checkbox" id="selectAll" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" onchange="toggleAll(this)">
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Order</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Company</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Details</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Shipping</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Files</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Total</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200" id="ordersTable">
                        <?php foreach ($orders as $order): ?>
                        <tr class="hover:bg-gray-50 order-row" data-order-id="<?= $order['id'] ?>">
                            <td class="px-4 py-4">
                                <input type="checkbox" class="order-checkbox rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                       value="<?= $order['id'] ?>" onchange="updateSelection()">
                            </td>
                            <td class="px-4 py-4">
                                <a href="order.php?id=<?= $order['id'] ?>" class="font-bold text-blue-600 hover:text-blue-700">#<?= $order['id'] ?></a>
                                <span class="block text-xs text-gray-500"><?= date('M j, Y', strtotime($order['created_at'])) ?></span>
                            </td>
                            <td class="px-4 py-4">
                                <p class="font-medium text-gray-900"><?= sanitize($order['company_name'] ?? 'Unknown') ?></p>
                                <?php if (!empty($order['employee_name'])): ?>
                                <p class="text-sm text-gray-500"><?= sanitize($order['employee_name']) ?></p>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-4">
                                <p class="text-gray-900"><?= number_format($order['quantity']) ?> cards</p>
                                <p class="text-sm text-gray-500"><?= ucfirst($order['paper_type'] ?? '') ?> &bull; <?= ucfirst(str_replace('_', ' ', $order['finish'] ?? '')) ?></p>
                            </td>
                            <td class="px-4 py-4 text-sm text-gray-600">
                                <?php if ($order['shipping_city']): ?>
                                    <?= sanitize($order['shipping_city']) ?>, <?= sanitize($order['shipping_country']) ?>
                                <?php else: ?>
                                    <span class="text-gray-400">Not provided</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-4">
                                <?php if (!empty($order['card_front_url'])): ?>
                                <div class="flex items-center gap-1">
                                    <a href="<?= sanitize($order['card_front_url']) ?>" target="_blank" download
                                       class="text-xs px-2 py-1 bg-blue-50 hover:bg-blue-100 text-blue-700 rounded font-medium transition-colors">
                                        <i class="fa-solid fa-download mr-1"></i>Front
                                    </a>
                                    <?php if (!empty($order['card_back_url'])): ?>
                                    <a href="<?= sanitize($order['card_back_url']) ?>" target="_blank" download
                                       class="text-xs px-2 py-1 bg-blue-50 hover:bg-blue-100 text-blue-700 rounded font-medium transition-colors">
                                        <i class="fa-solid fa-download mr-1"></i>Back
                                    </a>
                                    <?php endif; ?>
                                </div>
                                <?php else: ?>
                                <span class="text-xs text-gray-400">No files</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-4">
                                <span class="font-bold text-gray-900"><?= Currency::formatHtml($order['total'], $printShop['currency'] ?? 'OMR', 'sm') ?></span>
                            </td>
                            <td class="px-4 py-4">
                                <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-medium <?= $statusColors[$order['status']] ?? 'bg-gray-100 text-gray-600' ?>">
                                    <?= ucfirst($order['status']) ?>
                                </span>
                                <?php if ($order['tracking_number']): ?>
                                <p class="text-xs text-blue-600 mt-1"><?= sanitize($order['tracking_number']) ?></p>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-4">
                                <div class="flex items-center gap-2 justify-end">
                                    <a href="order.php?id=<?= $order['id'] ?>"
                                       class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm font-medium transition-colors">
                                        <i class="fa-solid fa-eye mr-1"></i>View
                                    </a>
                                    <form method="post" class="flex items-center gap-1">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                        <select name="status" class="text-sm border border-gray-200 rounded-lg px-2 py-1.5 focus:border-blue-500">
                                            <option value="pending"    <?= $order['status'] === 'pending'    ? 'selected' : '' ?>>Pending</option>
                                            <option value="processing" <?= $order['status'] === 'processing' ? 'selected' : '' ?>>Processing</option>
                                            <option value="printing"   <?= $order['status'] === 'printing'   ? 'selected' : '' ?>>Printing</option>
                                            <option value="shipped"    <?= $order['status'] === 'shipped'    ? 'selected' : '' ?>>Shipped</option>
                                            <option value="delivered"  <?= $order['status'] === 'delivered'  ? 'selected' : '' ?>>Delivered</option>
                                            <option value="cancelled"  <?= $order['status'] === 'cancelled'  ? 'selected' : '' ?>>Cancelled</option>
                                        </select>
                                        <button type="submit" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition-colors">
                                            Update
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function updateSelection() {
    const checked = document.querySelectorAll('.order-checkbox:checked');
    const count = checked.length;
    const bulkBar = document.getElementById('bulkBar');
    document.getElementById('selectedCount').textContent = count;

    if (count > 0) {
        bulkBar.classList.remove('hidden');
        bulkBar.classList.add('flex');
        // Rebuild hidden inputs
        const container = document.getElementById('bulkOrderIds');
        container.innerHTML = '';
        checked.forEach(cb => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'order_ids[]';
            input.value = cb.value;
            container.appendChild(input);
        });
    } else {
        bulkBar.classList.add('hidden');
        bulkBar.classList.remove('flex');
    }

    // Update select-all state
    const all = document.querySelectorAll('.order-checkbox');
    document.getElementById('selectAll').indeterminate = count > 0 && count < all.length;
    document.getElementById('selectAll').checked = count === all.length;
}

function toggleAll(selectAllCb) {
    document.querySelectorAll('.order-checkbox').forEach(cb => {
        cb.checked = selectAllCb.checked;
    });
    updateSelection();
}

function clearSelection() {
    document.querySelectorAll('.order-checkbox').forEach(cb => cb.checked = false);
    document.getElementById('selectAll').checked = false;
    updateSelection();
}

// Confirm bulk action
document.getElementById('bulkForm')?.addEventListener('submit', function(e) {
    const status = this.querySelector('[name="bulk_status"]').value;
    const count = document.querySelectorAll('.order-checkbox:checked').length;
    if (!status) { e.preventDefault(); alert('Please select a status to apply.'); return; }
    if (!confirm(`Update ${count} order(s) to "${status}"?`)) e.preventDefault();
});
</script>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
