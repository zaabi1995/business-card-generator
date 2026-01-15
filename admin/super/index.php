<?php
/**
 * Super Admin Panel
 * Full platform administration
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';

Auth::requireRole('super_admin');

$db = Database::getInstance();
$currentUser = Auth::getCurrentUser();

// Get statistics
$totalCompanies = $db->fetchOne("SELECT COUNT(*) as count FROM companies")['count'] ?? 0;
$totalEmployees = $db->fetchOne("SELECT COUNT(*) as count FROM employees")['count'] ?? 0;
$totalTemplates = $db->fetchOne("SELECT COUNT(*) as count FROM templates")['count'] ?? 0;
$totalGenerated = $db->fetchOne("SELECT COUNT(*) as count FROM generated_cards")['count'] ?? 0;
$activeSubscriptions = $db->fetchOne("SELECT COUNT(*) as count FROM companies WHERE subscription_status = 'active'")['count'] ?? 0;

// Get recent companies
$recentCompanies = $db->fetchAll("SELECT * FROM companies ORDER BY created_at DESC LIMIT 10");

// Get recent transactions
$recentTransactions = $db->fetchAll("SELECT pt.*, c.name as company_name FROM payment_transactions pt LEFT JOIN companies c ON pt.company_id = c.id ORDER BY pt.created_at DESC LIMIT 10");

adminHeader('Super Admin Dashboard', 'dashboard');
?>

<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-4 mb-6">
    <div class="bg-white rounded-lg shadow p-4">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500">Total Companies</p>
                <p class="text-2xl font-semibold text-gray-900"><?php echo $totalCompanies; ?></p>
            </div>
            <div class="w-10 h-10 rounded-lg bg-blue-100 text-blue-600 flex items-center justify-center">
                <i class="fa-solid fa-building"></i>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500">Total Employees</p>
                <p class="text-2xl font-semibold text-gray-900"><?php echo $totalEmployees; ?></p>
            </div>
            <div class="w-10 h-10 rounded-lg bg-amber-100 text-amber-600 flex items-center justify-center">
                <i class="fa-solid fa-users"></i>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500">Templates</p>
                <p class="text-2xl font-semibold text-gray-900"><?php echo $totalTemplates; ?></p>
            </div>
            <div class="w-10 h-10 rounded-lg bg-purple-100 text-purple-600 flex items-center justify-center">
                <i class="fa-solid fa-palette"></i>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500">Cards Generated</p>
                <p class="text-2xl font-semibold text-gray-900"><?php echo $totalGenerated; ?></p>
            </div>
            <div class="w-10 h-10 rounded-lg bg-green-100 text-green-600 flex items-center justify-center">
                <i class="fa-solid fa-id-card"></i>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500">Active Subscriptions</p>
                <p class="text-2xl font-semibold text-gray-900"><?php echo $activeSubscriptions; ?></p>
            </div>
            <div class="w-10 h-10 rounded-lg bg-emerald-100 text-emerald-600 flex items-center justify-center">
                <i class="fa-solid fa-circle-check"></i>
            </div>
        </div>
    </div>
</div>

<div class="bg-white rounded-lg shadow p-6 mb-6">
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-semibold text-gray-900">Quick Actions</h2>
        <span class="text-sm text-gray-500">Admin shortcuts</span>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        <a href="../companies.php" class="flex items-start gap-4 p-4 border border-gray-200 rounded-lg hover:bg-gray-50">
            <div class="w-10 h-10 rounded-lg bg-blue-100 text-blue-600 flex items-center justify-center">
                <i class="fa-solid fa-building"></i>
            </div>
            <div>
                <p class="font-semibold text-gray-900">Manage Companies</p>
                <p class="text-sm text-gray-500">View companies and hierarchy</p>
            </div>
        </a>
        <a href="../updates.php" class="flex items-start gap-4 p-4 border border-gray-200 rounded-lg hover:bg-gray-50">
            <div class="w-10 h-10 rounded-lg bg-amber-100 text-amber-600 flex items-center justify-center">
                <i class="fa-solid fa-download"></i>
            </div>
            <div>
                <p class="font-semibold text-gray-900">Database Updates</p>
                <p class="text-sm text-gray-500">Run migrations and updates</p>
            </div>
        </a>
        <a href="users.php" class="flex items-start gap-4 p-4 border border-gray-200 rounded-lg hover:bg-gray-50">
            <div class="w-10 h-10 rounded-lg bg-purple-100 text-purple-600 flex items-center justify-center">
                <i class="fa-solid fa-user-shield"></i>
            </div>
            <div>
                <p class="font-semibold text-gray-900">Manage Users</p>
                <p class="text-sm text-gray-500">Control user roles</p>
            </div>
        </a>
        <a href="../whatsapp_settings.php" class="flex items-start gap-4 p-4 border border-gray-200 rounded-lg hover:bg-gray-50">
            <div class="w-10 h-10 rounded-lg bg-green-100 text-green-600 flex items-center justify-center">
                <i class="fa-brands fa-whatsapp"></i>
            </div>
            <div>
                <p class="font-semibold text-gray-900">WhatsApp API</p>
                <p class="text-sm text-gray-500">Configure messaging</p>
            </div>
        </a>
        <a href="../printer.php" class="flex items-start gap-4 p-4 border border-gray-200 rounded-lg hover:bg-gray-50">
            <div class="w-10 h-10 rounded-lg bg-pink-100 text-pink-600 flex items-center justify-center">
                <i class="fa-solid fa-print"></i>
            </div>
            <div>
                <p class="font-semibold text-gray-900">Printer Management</p>
                <p class="text-sm text-gray-500">Manage print orders</p>
            </div>
        </a>
        <a href="../odoo_settings.php" class="flex items-start gap-4 p-4 border border-gray-200 rounded-lg hover:bg-gray-50">
            <div class="w-10 h-10 rounded-lg bg-gray-100 text-gray-700 flex items-center justify-center">
                <i class="fa-solid fa-plug"></i>
            </div>
            <div>
                <p class="font-semibold text-gray-900">Odoo Integration</p>
                <p class="text-sm text-gray-500">ERP synchronization</p>
            </div>
        </a>
    </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
    <div class="bg-white rounded-lg shadow">
        <div class="p-6 border-b border-gray-100">
            <h2 class="text-lg font-semibold text-gray-900">Recent Companies</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left text-gray-500">
                <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3">Company</th>
                        <th scope="col" class="px-6 py-3">Slug</th>
                        <th scope="col" class="px-6 py-3">Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentCompanies)): ?>
                    <tr>
                        <td colspan="3" class="px-6 py-6 text-center text-gray-500">No companies found.</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($recentCompanies as $company): ?>
                    <tr class="bg-white border-b">
                        <th scope="row" class="px-6 py-4 font-medium text-gray-900 whitespace-nowrap">
                            <?php echo sanitize($company['name']); ?>
                        </th>
                        <td class="px-6 py-4"><?php echo sanitize($company['slug']); ?></td>
                        <td class="px-6 py-4"><?php echo date('M d, Y', strtotime($company['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow">
        <div class="p-6 border-b border-gray-100">
            <h2 class="text-lg font-semibold text-gray-900">Recent Transactions</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left text-gray-500">
                <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3">Company</th>
                        <th scope="col" class="px-6 py-3">Amount</th>
                        <th scope="col" class="px-6 py-3">Status</th>
                        <th scope="col" class="px-6 py-3">Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentTransactions)): ?>
                    <tr>
                        <td colspan="4" class="px-6 py-6 text-center text-gray-500">No transactions found.</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($recentTransactions as $tx): ?>
                    <tr class="bg-white border-b">
                        <th scope="row" class="px-6 py-4 font-medium text-gray-900 whitespace-nowrap">
                            <?php echo sanitize($tx['company_name'] ?? 'N/A'); ?>
                        </th>
                        <td class="px-6 py-4">$<?php echo number_format($tx['amount'], 2); ?></td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold <?php echo ($tx['status'] ?? '') === 'success' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'; ?>">
                                <?php echo sanitize($tx['status']); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4"><?php echo date('M d', strtotime($tx['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php adminFooter(); ?>
