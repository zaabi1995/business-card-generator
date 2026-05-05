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
    die(htmlspecialchars(t('printshopdash.invalid_request')));
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
            $stk = 'printshopdash.status_' . $newStatus;
            $stl = t($stk);
            if ($stl === $stk) $stl = ucfirst($newStatus);
            $message = strtr(t('printshopdash.order_updated'), [':id' => (string) $orderId, ':status' => $stl]);
            $recentOrders = PrintShop::getOrders($shopId, null, 10);
        } else {
            $message = str_replace(':msg', (string) ($result['error'] ?? t('printshopdash.unknown_error')), t('printshopdash.update_error'));
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

$pageTitle = t('printshoppages.title_dashboard', ['shop' => $printShop['name']]);
$bodyClass = 'bg-gray-50';
require_once INCLUDES_DIR . '/ui-header.php';
?>

<div class="min-h-screen">
    <!-- Top Nav -->
    <nav class="bg-white/90 backdrop-blur-md border-b border-gray-200/80 shadow-sm fixed top-0 left-0 right-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center gap-3">
                    <a href="<?php echo getBasePath(); ?>" class="flex items-center">
                        <img src="<?php echo getBasePath(); ?>assets/images/logo.svg" alt="Cardify" class="h-8 w-auto">
                    </a>
                    <span class="h-5 w-px bg-gray-200"></span>
                    <span class="font-semibold text-gray-900 text-sm"><?php echo sanitize($printShop['name']); ?></span>
                    <?php if (!empty($printShop['is_verified'])): ?>
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-blue-50 text-blue-700 ring-1 ring-blue-100">
                        <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars(t('printshopdash.nav_verified')) ?>
                    </span>
                    <?php endif; ?>
                </div>
                <div class="flex items-center gap-1 text-sm">
                    <a href="dashboard.php" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-blue-700 bg-blue-50 font-semibold"><i class="fa-solid fa-chart-pie"></i><?= htmlspecialchars(t('printshopdash.nav_dashboard')) ?></a>
                    <a href="orders.php" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-gray-600 hover:text-gray-900 hover:bg-gray-50 font-medium transition-colors"><i class="fa-solid fa-box"></i><?= htmlspecialchars(t('printshopdash.nav_orders')) ?></a>
                    <a href="analytics.php" class="hidden sm:inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-gray-600 hover:text-gray-900 hover:bg-gray-50 font-medium transition-colors"><i class="fa-solid fa-chart-line"></i><?= htmlspecialchars(t('printshopdash.nav_analytics')) ?></a>
                    <a href="credit-accounts.php" class="hidden sm:inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-gray-600 hover:text-gray-900 hover:bg-gray-50 font-medium transition-colors"><i class="fa-solid fa-building-columns"></i><?= htmlspecialchars(t('printshopdash.nav_credit')) ?></a>
                    <a href="client-pricing.php" class="hidden sm:inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-gray-600 hover:text-gray-900 hover:bg-gray-50 font-medium transition-colors"><i class="fa-solid fa-tags"></i><?= htmlspecialchars(t('printshopclientpricing.nav_label')) ?></a>
                    <a href="settings.php" class="inline-flex items-center justify-center w-9 h-9 rounded-lg text-gray-500 hover:text-gray-900 hover:bg-gray-50 transition-colors" aria-label="<?= htmlspecialchars(t('printshopdash.nav_settings')) ?>"><i class="fa-solid fa-cog"></i></a>
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
                <p class="font-semibold"><?= htmlspecialchars(t('printshopdash.pending_approval_h')) ?></p>
                <p class="text-sm"><?= htmlspecialchars(t('printshopdash.pending_approval_b')) ?></p>
            </div>
        </div>
        <?php elseif ($printShop['status'] === 'suspended'): ?>
        <div class="mb-6 p-4 rounded-xl bg-red-50 border border-red-200 text-red-700 flex items-center gap-3">
            <i class="fa-solid fa-ban text-xl"></i>
            <div>
                <p class="font-semibold"><?= htmlspecialchars(t('printshopdash.suspended_h')) ?></p>
                <p class="text-sm"><?= htmlspecialchars(t('printshopdash.suspended_b')) ?></p>
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
            <div class="bg-white rounded-xl ring-1 ring-gray-200/70 shadow-sm p-5 hover:shadow-md transition-shadow">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-500"><?= htmlspecialchars(t('printshopdash.kpi_total_orders')) ?></span>
                    <span class="w-9 h-9 bg-blue-50 text-blue-600 rounded-lg flex items-center justify-center"><i class="fa-solid fa-box"></i></span>
                </div>
                <p class="text-3xl font-bold tracking-tight text-gray-900"><?php echo $stats['total_orders']; ?></p>
            </div>

            <div class="bg-white rounded-xl ring-1 ring-gray-200/70 shadow-sm p-5 hover:shadow-md transition-shadow">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-500"><?= htmlspecialchars(t('printshopdash.kpi_pending')) ?></span>
                    <span class="w-9 h-9 bg-amber-50 text-amber-600 rounded-lg flex items-center justify-center"><i class="fa-solid fa-clock"></i></span>
                </div>
                <p class="text-3xl font-bold tracking-tight text-gray-900"><?php echo $stats['pending_orders']; ?></p>
            </div>

            <div class="bg-white rounded-xl ring-1 ring-gray-200/70 shadow-sm p-5 hover:shadow-md transition-shadow">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-500"><?= htmlspecialchars(t('printshopdash.kpi_completed')) ?></span>
                    <span class="w-9 h-9 bg-green-50 text-green-600 rounded-lg flex items-center justify-center"><i class="fa-solid fa-check"></i></span>
                </div>
                <p class="text-3xl font-bold tracking-tight text-gray-900"><?php echo $stats['completed_orders']; ?></p>
            </div>

            <div class="bg-white rounded-xl ring-1 ring-gray-200/70 shadow-sm p-5 hover:shadow-md transition-shadow">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-500"><?= htmlspecialchars(t('printshopdash.kpi_revenue')) ?></span>
                    <span class="w-9 h-9 bg-purple-50 text-purple-600 rounded-lg flex items-center justify-center"><i class="fa-solid fa-dollar-sign"></i></span>
                </div>
                <p class="text-2xl font-bold tracking-tight text-gray-900"><?php echo Currency::formatHtml($stats['total_revenue'], $printShop['currency'] ?? 'USD', 'lg'); ?></p>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="grid md:grid-cols-3 gap-4 mb-8">
            <a href="orders.php" class="bg-white rounded-xl border border-gray-200 p-6 hover:border-blue-300 hover:shadow-md transition-all flex items-center gap-4">
                <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                    <i class="fa-solid fa-list text-blue-600 text-xl"></i>
                </div>
                <div>
                    <p class="font-semibold text-gray-900"><?= htmlspecialchars(t('printshopdash.qa_view_orders_h')) ?></p>
                    <p class="text-sm text-gray-500"><?= htmlspecialchars(t('printshopdash.qa_view_orders_s')) ?></p>
                </div>
                <i class="fa-solid fa-chevron-right text-gray-400 ml-auto"></i>
            </a>
            
            <a href="settings.php" class="bg-white rounded-xl border border-gray-200 p-6 hover:border-green-300 hover:shadow-md transition-all flex items-center gap-4">
                <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                    <i class="fa-solid fa-cog text-green-600 text-xl"></i>
                </div>
                <div>
                    <p class="font-semibold text-gray-900"><?= htmlspecialchars(t('printshopdash.qa_settings_h')) ?></p>
                    <p class="text-sm text-gray-500"><?= htmlspecialchars(t('printshopdash.qa_settings_s')) ?></p>
                </div>
                <i class="fa-solid fa-chevron-right text-gray-400 ml-auto"></i>
            </a>
            
            <a href="profile.php" class="bg-white rounded-xl border border-gray-200 p-6 hover:border-purple-300 hover:shadow-md transition-all flex items-center gap-4">
                <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                    <i class="fa-solid fa-store text-purple-600 text-xl"></i>
                </div>
                <div>
                    <p class="font-semibold text-gray-900"><?= htmlspecialchars(t('printshopdash.qa_profile_h')) ?></p>
                    <p class="text-sm text-gray-500"><?= htmlspecialchars(t('printshopdash.qa_profile_s')) ?></p>
                </div>
                <i class="fa-solid fa-chevron-right text-gray-400 ml-auto"></i>
            </a>
        </div>
        
        <!-- Recent Orders -->
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="p-6 border-b border-gray-200 flex items-center justify-between">
                <h3 class="text-lg font-semibold text-gray-900"><?= htmlspecialchars(t('printshopdash.recent_orders_h')) ?></h3>
                <a href="orders.php" class="text-sm text-blue-600 hover:text-blue-700 font-medium"><?= htmlspecialchars(t('printshopdash.view_all')) ?></a>
            </div>
            
            <?php if (empty($recentOrders)): ?>
            <div class="p-12 text-center">
                <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fa-solid fa-inbox text-gray-400 text-2xl"></i>
                </div>
                <h4 class="text-lg font-medium text-gray-900 mb-2"><?= htmlspecialchars(t('printshopdash.empty_h')) ?></h4>
                <p class="text-gray-500"><?= htmlspecialchars(t('printshopdash.empty_s')) ?></p>
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
                                    <?php echo sanitize($order['company_name'] ?? t('printshopdash.unknown_company')); ?>
                                </p>
                                <p class="text-sm text-gray-500">
                                    <?php echo htmlspecialchars(strtr(t('printshopdash.order_meta'), [':n' => (string) $order['quantity'], ':paper' => ucfirst($order['paper_type'] ?? 'standard')])); ?>
                                    <?php if (!empty($order['employee_name'])): ?>
                                    • <?php echo sanitize($order['employee_name']); ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        
                        <div class="flex items-center gap-4">
                            <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-medium <?php echo $statusColors[$order['status']] ?? 'bg-gray-100 text-gray-600'; ?>">
                                <?php $stk = 'printshopdash.status_' . $order['status']; $stl = t($stk); echo htmlspecialchars($stl === $stk ? ucfirst($order['status']) : $stl); ?>
                            </span>
                            <span class="font-semibold text-gray-900"><?php echo Currency::formatHtml($order['total'], $printShop['currency'] ?? 'USD', 'sm'); ?></span>
                            
                            <!-- Quick Status Update -->
                            <?php if (in_array($order['status'], ['pending', 'submitted', 'processing', 'printing'])): ?>
                            <form method="post" class="flex items-center gap-2">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                <select name="status" class="text-sm border border-gray-200 rounded-lg px-2 py-1 focus:border-blue-500">
                                    <option value="processing" <?php echo $order['status'] === 'processing' ? 'selected' : ''; ?>><?= htmlspecialchars(t('printshopdash.status_processing')) ?></option>
                                    <option value="printing" <?php echo $order['status'] === 'printing' ? 'selected' : ''; ?>><?= htmlspecialchars(t('printshopdash.status_printing')) ?></option>
                                    <option value="shipped"><?= htmlspecialchars(t('printshopdash.status_shipped')) ?></option>
                                    <option value="delivered"><?= htmlspecialchars(t('printshopdash.status_delivered')) ?></option>
                                </select>
                                <button type="submit" class="px-2 py-1 bg-blue-100 hover:bg-blue-200 text-blue-700 rounded-lg text-sm">
                                    <?= htmlspecialchars(t('printshopdash.btn_update')) ?>
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
