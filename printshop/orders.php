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
    $orderId = (int)($_POST['order_id'] ?? 0);
    
    if ($action === 'update_status' && $orderId > 0) {
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
}

// Filter
$statusFilter = $_GET['status'] ?? '';
$orders = PrintShop::getOrders($shopId, $statusFilter ?: null, 100);

$statusColors = [
    'pending' => 'bg-gray-100 text-gray-700',
    'submitted' => 'bg-blue-100 text-blue-700',
    'processing' => 'bg-purple-100 text-purple-700',
    'printing' => 'bg-amber-100 text-amber-700',
    'shipped' => 'bg-cyan-100 text-cyan-700',
    'delivered' => 'bg-green-100 text-green-700',
    'cancelled' => 'bg-red-100 text-red-700'
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
                    <a href="<?php echo getBasePath(); ?>" class="flex items-center gap-2">
                        <img src="<?php echo getBasePath(); ?>assets/images/logo.svg" alt="Cardify" class="h-8 w-auto">
                    </a>
                    <span class="text-gray-300">|</span>
                    <a href="dashboard.php" class="font-semibold text-gray-900 hover:text-blue-600">
                        <?php echo sanitize($printShop['name']); ?>
                    </a>
                </div>
                <div class="flex items-center gap-4">
                    <a href="dashboard.php" class="text-gray-500 hover:text-gray-700">
                        <i class="fa-solid fa-home"></i>
                    </a>
                    <a href="<?php echo getBasePath(); ?>logout.php" class="text-gray-500 hover:text-red-600">
                        <i class="fa-solid fa-sign-out-alt"></i>
                    </a>
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
            <a href="dashboard.php" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg font-medium transition-colors">
                <i class="fa-solid fa-arrow-left mr-2"></i>
                Back to Dashboard
            </a>
        </div>
        
        <?php if ($message): ?>
        <div class="mb-6 p-4 rounded-xl <?php echo $messageType === 'success' ? 'bg-green-50 border border-green-200 text-green-700' : 'bg-red-50 border border-red-200 text-red-700'; ?> flex items-center gap-3">
            <i class="fa-solid <?php echo $messageType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
            <?php echo sanitize($message); ?>
        </div>
        <?php endif; ?>
        
        <!-- Filter Tabs -->
        <div class="bg-white rounded-xl border border-gray-200 p-4 mb-6 flex items-center gap-2 overflow-x-auto">
            <a href="orders.php" class="px-4 py-2 rounded-lg text-sm font-medium whitespace-nowrap <?php echo empty($statusFilter) ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">
                All
            </a>
            <?php foreach (['pending', 'submitted', 'processing', 'printing', 'shipped', 'delivered'] as $status): ?>
            <a href="?status=<?php echo $status; ?>" class="px-4 py-2 rounded-lg text-sm font-medium whitespace-nowrap <?php echo $statusFilter === $status ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">
                <?php echo ucfirst($status); ?>
            </a>
            <?php endforeach; ?>
        </div>
        
        <!-- Orders List -->
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
            <?php if (empty($orders)): ?>
            <div class="p-12 text-center">
                <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fa-solid fa-inbox text-gray-400 text-2xl"></i>
                </div>
                <h3 class="text-lg font-medium text-gray-900 mb-2">No Orders Found</h3>
                <p class="text-gray-500">
                    <?php echo $statusFilter ? "No $statusFilter orders" : "Orders will appear here when companies place them"; ?>
                </p>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Order</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Company</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Details</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Shipping</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Total</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($orders as $order): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4">
                                <a href="order.php?id=<?php echo $order['id']; ?>" class="font-bold text-blue-600 hover:text-blue-700">#<?php echo $order['id']; ?></a>
                                <span class="block text-xs text-gray-500"><?php echo date('M j, Y', strtotime($order['created_at'])); ?></span>
                            </td>
                            <td class="px-6 py-4">
                                <p class="font-medium text-gray-900"><?php echo sanitize($order['company_name'] ?? 'Unknown'); ?></p>
                                <?php if (!empty($order['employee_name'])): ?>
                                <p class="text-sm text-gray-500"><?php echo sanitize($order['employee_name']); ?></p>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4">
                                <p class="text-gray-900"><?php echo $order['quantity']; ?> cards</p>
                                <p class="text-sm text-gray-500"><?php echo ucfirst($order['paper_type']); ?> • <?php echo ucfirst(str_replace('_', ' ', $order['finish'])); ?></p>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600">
                                <?php if ($order['shipping_city']): ?>
                                <?php echo sanitize($order['shipping_city']); ?>, <?php echo sanitize($order['shipping_country']); ?>
                                <?php else: ?>
                                <span class="text-gray-400">Not provided</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4">
                                <span class="font-bold text-gray-900"><?php echo Currency::formatHtml($order['total'], $printShop['currency'] ?? 'USD', 'sm'); ?></span>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-medium <?php echo $statusColors[$order['status']] ?? 'bg-gray-100 text-gray-600'; ?>">
                                    <?php echo ucfirst($order['status']); ?>
                                </span>
                                <?php if ($order['tracking_number']): ?>
                                <p class="text-xs text-blue-600 mt-1"><?php echo sanitize($order['tracking_number']); ?></p>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-2 justify-end">
                                    <a href="order.php?id=<?php echo $order['id']; ?>" 
                                       class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm font-medium transition-colors">
                                        <i class="fa-solid fa-eye mr-1"></i> View
                                    </a>
                                    <form method="post" class="flex items-center gap-2">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                        <select name="status" class="text-sm border border-gray-200 rounded-lg px-2 py-1.5 focus:border-blue-500">
                                            <option value="pending" <?php echo $order['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            <option value="processing" <?php echo $order['status'] === 'processing' ? 'selected' : ''; ?>>Processing</option>
                                            <option value="printing" <?php echo $order['status'] === 'printing' ? 'selected' : ''; ?>>Printing</option>
                                            <option value="shipped" <?php echo $order['status'] === 'shipped' ? 'selected' : ''; ?>>Shipped</option>
                                            <option value="delivered" <?php echo $order['status'] === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                                            <option value="cancelled" <?php echo $order['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
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

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
