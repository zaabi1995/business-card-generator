<?php
/**
 * Company Admin View - Dashboard with full management
 */
if (!defined('INCLUDES_DIR')) { http_response_code(404); exit; }
require_once INCLUDES_DIR . '/Currency.php';

// Get stats
$employeeCount = 0;
$templateCount = 0;
$orderCount = 0;
$totalSpend = 0;

try {
    $result = $db->fetchOne("SELECT COUNT(*) as count FROM employees WHERE company_id = :id", ['id' => $company['id']]);
    $employeeCount = $result['count'] ?? 0;
} catch (Exception $e) {}

try {
    $result = $db->fetchOne("SELECT COUNT(*) as count FROM templates WHERE company_id = :id", ['id' => $company['id']]);
    $templateCount = $result['count'] ?? 0;
} catch (Exception $e) {}

try {
    $result = $db->fetchOne("SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total FROM orders WHERE company_id = :id", ['id' => $company['id']]);
    $orderCount = $result['count'] ?? 0;
    $totalSpend = $result['total'] ?? 0;
} catch (Exception $e) {}

// Get recent employees
$recentEmployees = [];
try {
    $recentEmployees = $db->fetchAll(
        "SELECT * FROM employees WHERE company_id = :id ORDER BY created_at DESC LIMIT 5",
        ['id' => $company['id']]
    );
} catch (Exception $e) {}

// Get recent orders
$recentOrders = [];
try {
    $recentOrders = $db->fetchAll(
        "SELECT * FROM orders WHERE company_id = :id ORDER BY created_at DESC LIMIT 5",
        ['id' => $company['id']]
    );
} catch (Exception $e) {}

$currency = $company['currency'] ?? 'OMR';

require_once INCLUDES_DIR . '/admin-layout.php';
adminHeader('Dashboard - ' . $company['name'], 'dashboard');
?>

<!-- Company Header -->
<div class="mb-6 flex items-center justify-between">
    <div class="flex items-center gap-4">
        <?php if ($companyTheme && !empty($companyTheme['logo_path'])): ?>
        <img src="<?php echo imageUrl($companyTheme['logo_path']); ?>" alt="<?php echo sanitize($company['name']); ?>" class="h-10">
        <?php else: ?>
        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center text-white font-bold">
            <?php echo strtoupper(substr($company['name'], 0, 2)); ?>
        </div>
        <?php endif; ?>
        <div>
            <h1 class="text-xl font-bold text-gray-900"><?php echo sanitize($company['name']); ?></h1>
            <p class="text-sm text-gray-500"><?php echo sanitize($company['slug']); ?> &middot; <?php echo ucfirst($company['plan'] ?? 'free'); ?> Plan</p>
        </div>
    </div>
    <a href="<?= htmlspecialchars(getTenantUrl($companySlug, '/'), ENT_QUOTES, 'UTF-8') ?>" target="_blank" class="text-sm text-blue-600 hover:text-blue-800">
        <i class="fa-solid fa-external-link mr-1"></i> View Portal
    </a>
</div>

<!-- Stats Grid -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
    <div class="bg-white rounded-xl border border-gray-200 p-4">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center">
                <i class="fa-solid fa-users text-blue-600"></i>
            </div>
            <div>
                <p class="text-2xl font-bold text-gray-900"><?php echo number_format($employeeCount); ?></p>
                <p class="text-sm text-gray-500">Employees</p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-purple-100 flex items-center justify-center">
                <i class="fa-solid fa-palette text-purple-600"></i>
            </div>
            <div>
                <p class="text-2xl font-bold text-gray-900"><?php echo number_format($templateCount); ?></p>
                <p class="text-sm text-gray-500">Templates</p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-green-100 flex items-center justify-center">
                <i class="fa-solid fa-shopping-cart text-green-600"></i>
            </div>
            <div>
                <p class="text-2xl font-bold text-gray-900"><?php echo number_format($orderCount); ?></p>
                <p class="text-sm text-gray-500">Orders</p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center">
                <i class="fa-solid fa-dollar-sign text-amber-600"></i>
            </div>
            <div>
                <p class="text-2xl font-bold text-gray-900"><?php echo Currency::formatHtml($totalSpend, $currency, 'lg'); ?></p>
                <p class="text-sm text-gray-500">Total Spend</p>
            </div>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="grid md:grid-cols-4 gap-4 mb-8">
    <a href="<?php echo getBasePath(); ?>admin/employees.php" class="bg-white rounded-xl border border-gray-200 p-4 hover:border-blue-300 hover:shadow-md transition-all group">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-blue-50 group-hover:bg-blue-100 flex items-center justify-center transition-colors">
                <i class="fa-solid fa-user-plus text-blue-600"></i>
            </div>
            <div>
                <p class="font-semibold text-gray-900">Add Employee</p>
                <p class="text-sm text-gray-500">Manage team members</p>
            </div>
        </div>
    </a>
    <a href="<?php echo getBasePath(); ?>admin/" class="bg-white rounded-xl border border-gray-200 p-4 hover:border-purple-300 hover:shadow-md transition-all group">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-purple-50 group-hover:bg-purple-100 flex items-center justify-center transition-colors">
                <i class="fa-solid fa-wand-magic-sparkles text-purple-600"></i>
            </div>
            <div>
                <p class="font-semibold text-gray-900">Design Cards</p>
                <p class="text-sm text-gray-500">Create templates</p>
            </div>
        </div>
    </a>
    <a href="<?php echo getBasePath(); ?>admin/generated.php" class="bg-white rounded-xl border border-gray-200 p-4 hover:border-green-300 hover:shadow-md transition-all group">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-green-50 group-hover:bg-green-100 flex items-center justify-center transition-colors">
                <i class="fa-solid fa-id-card text-green-600"></i>
            </div>
            <div>
                <p class="font-semibold text-gray-900">Generate Cards</p>
                <p class="text-sm text-gray-500">Create & download</p>
            </div>
        </div>
    </a>
    <a href="<?php echo getBasePath(); ?>admin/print_orders.php" class="bg-white rounded-xl border border-gray-200 p-4 hover:border-amber-300 hover:shadow-md transition-all group">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-amber-50 group-hover:bg-amber-100 flex items-center justify-center transition-colors">
                <i class="fa-solid fa-print text-amber-600"></i>
            </div>
            <div>
                <p class="font-semibold text-gray-900">Print Orders</p>
                <p class="text-sm text-gray-500">Order physical cards</p>
            </div>
        </div>
    </a>
</div>

<div class="grid md:grid-cols-2 gap-6">
    <!-- Recent Employees -->
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="p-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="font-semibold text-gray-900">Recent Employees</h3>
            <a href="<?php echo getBasePath(); ?>admin/employees.php" class="text-sm text-blue-600 hover:text-blue-800">View all</a>
        </div>
        <div class="divide-y divide-gray-100">
            <?php if (empty($recentEmployees)): ?>
            <div class="p-4 text-center text-gray-500 text-sm">
                No employees yet. <a href="<?php echo getBasePath(); ?>admin/employees.php" class="text-blue-600">Add your first employee</a>
            </div>
            <?php else: ?>
            <?php foreach ($recentEmployees as $emp): ?>
            <div class="p-4 flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-gray-100 flex items-center justify-center text-gray-600 font-medium">
                    <?php echo strtoupper(substr($emp['name_en'] ?? $emp['email'], 0, 1)); ?>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="font-medium text-gray-900 truncate"><?php echo sanitize($emp['name_en'] ?? $emp['email']); ?></p>
                    <p class="text-sm text-gray-500 truncate"><?php echo sanitize($emp['position_en'] ?? 'No position'); ?></p>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Orders -->
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="p-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="font-semibold text-gray-900">Recent Orders</h3>
            <a href="<?php echo getBasePath(); ?>admin/print_orders.php" class="text-sm text-blue-600 hover:text-blue-800">View all</a>
        </div>
        <div class="divide-y divide-gray-100">
            <?php if (empty($recentOrders)): ?>
            <div class="p-4 text-center text-gray-500 text-sm">
                No orders yet. <a href="<?php echo getBasePath(); ?>admin/print_orders.php" class="text-blue-600">Create your first order</a>
            </div>
            <?php else: ?>
            <?php foreach ($recentOrders as $order): ?>
            <div class="p-4 flex items-center justify-between">
                <div>
                    <p class="font-medium text-gray-900">#<?php echo sanitize($order['order_number']); ?></p>
                    <p class="text-sm text-gray-500"><?php echo date('M j, Y', dbTs($order['created_at'])); ?></p>
                </div>
                <div class="text-right">
                    <p class="font-medium text-gray-900"><?php echo Currency::formatHtml($order['total_amount'], $currency, 'sm'); ?></p>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                        <?php echo match($order['status']) {
                            'completed' => 'bg-green-100 text-green-700',
                            'pending' => 'bg-amber-100 text-amber-700',
                            'cancelled' => 'bg-red-100 text-red-700',
                            default => 'bg-gray-100 text-gray-700'
                        }; ?>">
                        <?php echo ucfirst($order['status']); ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php adminFooter(); ?>
