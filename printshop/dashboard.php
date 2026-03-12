<?php
/**
 * Print Shop Dashboard
 * Dashboard for print shop owners to manage their shop and orders
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/PrintShop.php';
require_once INCLUDES_DIR . '/Currency.php';

// Require print shop role
Auth::requireLogin();
$user = Auth::getCurrentUser();

if ($user['role'] !== 'print_shop' && $user['role'] !== 'super_admin') {
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

// Get print shop
$printShop = PrintShop::getByUserId($user['id']);

if (!$printShop && $user['role'] !== 'super_admin') {
    // No print shop associated
    header('Location: ' . getBasePath() . 'printshop/register.php');
    exit;
}

// For super admin, allow viewing any shop
if ($user['role'] === 'super_admin' && isset($_GET['shop'])) {
    $printShop = PrintShop::getById((int)$_GET['shop']);
}

if (!$printShop) {
    header('Location: ' . getBasePath() . 'admin/print_shops.php');
    exit;
}

$shopId = $printShop['id'];
$stats = PrintShop::getStats($shopId);
$recentOrders = PrintShop::getOrders($shopId, null, 10);

// Handle status updates
$message = null;
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    die('Invalid request');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $orderId = (int)($_POST['order_id'] ?? 0);
    
    if ($action === 'update_status' && $orderId > 0) {
        require_once INCLUDES_DIR . '/PrintShopIntegration.php';
        $newStatus = $_POST['status'] ?? '';
        $trackingNumber = trim($_POST['tracking_number'] ?? '');
        
        $result = PrintShopIntegration::updateOrderStatus($orderId, $newStatus, $trackingNumber ?: null);
        
        if ($result['success']) {
            $message = "Order #$orderId updated to " . ucfirst($newStatus);
            $recentOrders = PrintShop::getOrders($shopId, null, 10);
        } else {
            $message = "Error: " . ($result['error'] ?? 'Unknown');
            $messageType = 'error';
        }
    }
}

$statusColors = [
    'pending' => 'bg-gray-100 text-gray-700',
    'submitted' => 'bg-blue-100 text-blue-700',
    'processing' => 'bg-purple-100 text-purple-700',
    'printing' => 'bg-amber-100 text-amber-700',
    'shipped' => 'bg-cyan-100 text-cyan-700',
    'delivered' => 'bg-green-100 text-green-700',
    'cancelled' => 'bg-red-100 text-red-700'
];

$pageTitle = $printShop['name'] . ' - Dashboard';
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
                    <span class="font-semibold text-gray-900"><?php echo sanitize($printShop['name']); ?></span>
                    <?php if (!empty($printShop['is_verified'])): ?>
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700">
                        <i class="fa-solid fa-circle-check"></i> Verified
                    </span>
                    <?php endif; ?>
                </div>
                <div class="flex items-center gap-4 text-sm">
                    <a href="dashboard.php" class="text-blue-600 font-medium"><i class="fa-solid fa-chart-pie mr-1"></i>Dashboard</a>
                    <a href="orders.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-box mr-1"></i>Orders</a>
                    <a href="credit-accounts.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-building-columns mr-1"></i>Credit</a>
                    <a href="settings.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-cog"></i></a>
                </div>
            </div>
        </div>
    </nav>
    
    <div class="pt-20 pb-12 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto">
        
        <!-- Status Banner -->
        <?php if ($printShop['status'] === 'pending'): ?>
        <div class="mb-6 p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-700 flex items-center gap-3">
            <i class="fa-solid fa-clock text-xl"></i>
            <div>
                <p class="font-semibold">Pending Approval</p>
                <p class="text-sm">Your print shop is awaiting admin approval. You'll be notified once approved.</p>
            </div>
        </div>
        <?php elseif ($printShop['status'] === 'suspended'): ?>
        <div class="mb-6 p-4 rounded-xl bg-red-50 border border-red-200 text-red-700 flex items-center gap-3">
            <i class="fa-solid fa-ban text-xl"></i>
            <div>
                <p class="font-semibold">Shop Suspended</p>
                <p class="text-sm">Your print shop has been suspended. Please contact support for more information.</p>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($message): ?>
        <div class="mb-6 p-4 rounded-xl <?php echo $messageType === 'success' ? 'bg-green-50 border border-green-200 text-green-700' : 'bg-red-50 border border-red-200 text-red-700'; ?> flex items-center gap-3">
            <i class="fa-solid <?php echo $messageType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
            <?php echo sanitize($message); ?>
        </div>
        <?php endif; ?>
        
        <!-- Stats -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                        <i class="fa-solid fa-box text-blue-600 text-xl"></i>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-gray-900"><?php echo $stats['total_orders']; ?></p>
                        <p class="text-sm text-gray-500">Total Orders</p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-amber-100 rounded-lg flex items-center justify-center">
                        <i class="fa-solid fa-clock text-amber-600 text-xl"></i>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-gray-900"><?php echo $stats['pending_orders']; ?></p>
                        <p class="text-sm text-gray-500">Pending</p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                        <i class="fa-solid fa-check-circle text-green-600 text-xl"></i>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-gray-900"><?php echo $stats['completed_orders']; ?></p>
                        <p class="text-sm text-gray-500">Completed</p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                        <i class="fa-solid fa-dollar-sign text-purple-600 text-xl"></i>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-gray-900"><?php echo Currency::formatHtml($stats['total_revenue'], $printShop['currency'] ?? 'USD', 'lg'); ?></p>
                        <p class="text-sm text-gray-500">Total Revenue</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="grid md:grid-cols-3 gap-4 mb-8">
            <a href="orders.php" class="bg-white rounded-xl border border-gray-200 p-6 hover:border-blue-300 hover:shadow-md transition-all flex items-center gap-4">
                <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                    <i class="fa-solid fa-list text-blue-600 text-xl"></i>
                </div>
                <div>
                    <p class="font-semibold text-gray-900">View All Orders</p>
                    <p class="text-sm text-gray-500">Manage incoming print orders</p>
                </div>
                <i class="fa-solid fa-chevron-right text-gray-400 ml-auto"></i>
            </a>
            
            <a href="settings.php" class="bg-white rounded-xl border border-gray-200 p-6 hover:border-green-300 hover:shadow-md transition-all flex items-center gap-4">
                <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                    <i class="fa-solid fa-cog text-green-600 text-xl"></i>
                </div>
                <div>
                    <p class="font-semibold text-gray-900">Shop Settings</p>
                    <p class="text-sm text-gray-500">Update pricing and services</p>
                </div>
                <i class="fa-solid fa-chevron-right text-gray-400 ml-auto"></i>
            </a>
            
            <a href="profile.php" class="bg-white rounded-xl border border-gray-200 p-6 hover:border-purple-300 hover:shadow-md transition-all flex items-center gap-4">
                <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                    <i class="fa-solid fa-store text-purple-600 text-xl"></i>
                </div>
                <div>
                    <p class="font-semibold text-gray-900">Shop Profile</p>
                    <p class="text-sm text-gray-500">Edit public shop information</p>
                </div>
                <i class="fa-solid fa-chevron-right text-gray-400 ml-auto"></i>
            </a>
        </div>
        
        <!-- Recent Orders -->
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="p-6 border-b border-gray-200 flex items-center justify-between">
                <h3 class="text-lg font-semibold text-gray-900">Recent Orders</h3>
                <a href="orders.php" class="text-sm text-blue-600 hover:text-blue-700 font-medium">View All</a>
            </div>
            
            <?php if (empty($recentOrders)): ?>
            <div class="p-12 text-center">
                <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fa-solid fa-inbox text-gray-400 text-2xl"></i>
                </div>
                <h4 class="text-lg font-medium text-gray-900 mb-2">No Orders Yet</h4>
                <p class="text-gray-500">Orders from companies will appear here</p>
            </div>
            <?php else: ?>
            <div class="divide-y divide-gray-200">
                <?php foreach ($recentOrders as $order): ?>
                <div class="p-6 hover:bg-gray-50 transition-colors">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <div class="w-10 h-10 bg-gray-100 rounded-lg flex items-center justify-center text-gray-600 font-bold">
                                #<?php echo $order['id']; ?>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-900">
                                    <?php echo sanitize($order['company_name'] ?? 'Unknown Company'); ?>
                                </p>
                                <p class="text-sm text-gray-500">
                                    <?php echo $order['quantity']; ?> cards • <?php echo ucfirst($order['paper_type'] ?? 'standard'); ?>
                                    <?php if (!empty($order['employee_name'])): ?>
                                    • <?php echo sanitize($order['employee_name']); ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        
                        <div class="flex items-center gap-4">
                            <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-medium <?php echo $statusColors[$order['status']] ?? 'bg-gray-100 text-gray-600'; ?>">
                                <?php echo ucfirst($order['status']); ?>
                            </span>
                            <span class="font-semibold text-gray-900"><?php echo Currency::formatHtml($order['total'], $printShop['currency'] ?? 'USD', 'sm'); ?></span>
                            
                            <!-- Quick Status Update -->
                            <?php if (in_array($order['status'], ['pending', 'submitted', 'processing', 'printing'])): ?>
                            <form method="post" class="flex items-center gap-2">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                <select name="status" class="text-sm border border-gray-200 rounded-lg px-2 py-1 focus:border-blue-500">
                                    <option value="processing" <?php echo $order['status'] === 'processing' ? 'selected' : ''; ?>>Processing</option>
                                    <option value="printing" <?php echo $order['status'] === 'printing' ? 'selected' : ''; ?>>Printing</option>
                                    <option value="shipped">Shipped</option>
                                    <option value="delivered">Delivered</option>
                                </select>
                                <button type="submit" class="px-2 py-1 bg-blue-100 hover:bg-blue-200 text-blue-700 rounded-lg text-sm">
                                    Update
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
