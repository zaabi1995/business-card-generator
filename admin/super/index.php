<?php
/**
 * Super Admin Panel
 * Full platform administration
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Currency.php';
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

// Print shop stats (if table exists)
$totalPrintShops = 0;
$pendingPrintShops = 0;
if ($db->tableExists('print_shops')) {
    $totalPrintShops = $db->fetchOne("SELECT COUNT(*) as count FROM print_shops")['count'] ?? 0;
    $pendingPrintShops = $db->fetchOne("SELECT COUNT(*) as count FROM print_shops WHERE status = 'pending'")['count'] ?? 0;
}

// Email stats (if table exists)
$totalEmails = 0;
$failedEmails = 0;
if ($db->tableExists('email_logs')) {
    $totalEmails = $db->fetchOne("SELECT COUNT(*) as count FROM email_logs")['count'] ?? 0;
    $failedEmails = $db->fetchOne("SELECT COUNT(*) as count FROM email_logs WHERE status = 'failed'")['count'] ?? 0;
}

// Get recent companies
$recentCompanies = $db->fetchAll("SELECT * FROM companies ORDER BY created_at DESC LIMIT 10");

// Get recent transactions
$recentTransactions = $db->fetchAll("SELECT pt.*, c.name as company_name FROM payment_transactions pt LEFT JOIN companies c ON pt.company_id = c.id ORDER BY pt.created_at DESC LIMIT 10");

adminHeader('Super Admin Dashboard', 'dashboard');
?>

<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
    <a href="companies.php" class="bg-white rounded-lg shadow p-4 hover:shadow-md transition-shadow">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500">Total Companies</p>
                <p class="text-2xl font-semibold text-gray-900"><?php echo $totalCompanies; ?></p>
            </div>
            <div class="w-10 h-10 rounded-lg bg-blue-100 text-blue-600 flex items-center justify-center">
                <i class="fa-solid fa-building"></i>
            </div>
        </div>
    </a>
    <a href="employees.php" class="bg-white rounded-lg shadow p-4 hover:shadow-md transition-shadow">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500">Total Employees</p>
                <p class="text-2xl font-semibold text-gray-900"><?php echo $totalEmployees; ?></p>
            </div>
            <div class="w-10 h-10 rounded-lg bg-amber-100 text-amber-600 flex items-center justify-center">
                <i class="fa-solid fa-users"></i>
            </div>
        </div>
    </a>
    <a href="print_shops.php" class="bg-white rounded-lg shadow p-4 hover:shadow-md transition-shadow">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500">Print Shops</p>
                <p class="text-2xl font-semibold text-gray-900"><?php echo $totalPrintShops; ?></p>
                <?php if ($pendingPrintShops > 0): ?>
                <p class="text-xs text-amber-600"><?php echo $pendingPrintShops; ?> pending approval</p>
                <?php endif; ?>
            </div>
            <div class="w-10 h-10 rounded-lg bg-pink-100 text-pink-600 flex items-center justify-center">
                <i class="fa-solid fa-store"></i>
            </div>
        </div>
    </a>
    <a href="email_logs.php" class="bg-white rounded-lg shadow p-4 hover:shadow-md transition-shadow">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500">Emails Sent</p>
                <p class="text-2xl font-semibold text-gray-900"><?php echo $totalEmails; ?></p>
                <?php if ($failedEmails > 0): ?>
                <p class="text-xs text-red-600"><?php echo $failedEmails; ?> failed</p>
                <?php endif; ?>
            </div>
            <div class="w-10 h-10 rounded-lg bg-cyan-100 text-cyan-600 flex items-center justify-center">
                <i class="fa-solid fa-envelope"></i>
            </div>
        </div>
    </a>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
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
    <a href="../audit-logs.php" class="bg-white rounded-lg shadow p-4 hover:shadow-md transition-shadow">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500">Audit Logs</p>
                <p class="text-2xl font-semibold text-gray-900">View</p>
            </div>
            <div class="w-10 h-10 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center">
                <i class="fa-solid fa-clipboard-list"></i>
            </div>
        </div>
    </a>
</div>

<div class="bg-white rounded-lg shadow p-6 mb-6">
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-semibold text-gray-900">Quick Actions</h2>
        <span class="text-sm text-gray-500">Admin shortcuts</span>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
        <a href="companies.php" class="flex items-start gap-4 p-4 border border-gray-200 rounded-lg hover:bg-gray-50">
            <div class="w-10 h-10 rounded-lg bg-blue-100 text-blue-600 flex items-center justify-center">
                <i class="fa-solid fa-building"></i>
            </div>
            <div>
                <p class="font-semibold text-gray-900">Manage Companies</p>
                <p class="text-sm text-gray-500">View, edit, delete companies</p>
            </div>
        </a>
        <a href="employees.php" class="flex items-start gap-4 p-4 border border-gray-200 rounded-lg hover:bg-gray-50">
            <div class="w-10 h-10 rounded-lg bg-amber-100 text-amber-600 flex items-center justify-center">
                <i class="fa-solid fa-users-gear"></i>
            </div>
            <div>
                <p class="font-semibold text-gray-900">All Employees</p>
                <p class="text-sm text-gray-500">Manage all employees</p>
            </div>
        </a>
        <a href="print_shops.php" class="flex items-start gap-4 p-4 border border-gray-200 rounded-lg hover:bg-gray-50">
            <div class="w-10 h-10 rounded-lg bg-pink-100 text-pink-600 flex items-center justify-center">
                <i class="fa-solid fa-store"></i>
            </div>
            <div>
                <p class="font-semibold text-gray-900">Print Shops</p>
                <p class="text-sm text-gray-500">Manage print partners</p>
            </div>
        </a>
        <a href="email_logs.php" class="flex items-start gap-4 p-4 border border-gray-200 rounded-lg hover:bg-gray-50">
            <div class="w-10 h-10 rounded-lg bg-cyan-100 text-cyan-600 flex items-center justify-center">
                <i class="fa-solid fa-envelope"></i>
            </div>
            <div>
                <p class="font-semibold text-gray-900">Email Logs</p>
                <p class="text-sm text-gray-500">Track sent emails</p>
            </div>
        </a>
        <a href="../plans.php" class="flex items-start gap-4 p-4 border border-gray-200 rounded-lg hover:bg-gray-50">
            <div class="w-10 h-10 rounded-lg bg-indigo-100 text-indigo-600 flex items-center justify-center">
                <i class="fa-solid fa-tags"></i>
            </div>
            <div>
                <p class="font-semibold text-gray-900">Subscription Plans</p>
                <p class="text-sm text-gray-500">Manage pricing and limits</p>
            </div>
        </a>
        <a href="../audit-logs.php" class="flex items-start gap-4 p-4 border border-gray-200 rounded-lg hover:bg-gray-50">
            <div class="w-10 h-10 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center">
                <i class="fa-solid fa-clipboard-list"></i>
            </div>
            <div>
                <p class="font-semibold text-gray-900">Audit Logs</p>
                <p class="text-sm text-gray-500">View activity history</p>
            </div>
        </a>
        <a href="../updates.php" class="flex items-start gap-4 p-4 border border-gray-200 rounded-lg hover:bg-gray-50">
            <div class="w-10 h-10 rounded-lg bg-amber-100 text-amber-600 flex items-center justify-center">
                <i class="fa-solid fa-download"></i>
            </div>
            <div>
                <p class="font-semibold text-gray-900">Database Updates</p>
                <p class="text-sm text-gray-500">Run migrations</p>
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
    </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
    <div class="bg-white rounded-lg shadow">
        <div class="p-6 border-b border-gray-100 flex items-center justify-between">
            <h2 class="text-lg font-semibold text-gray-900">Recent Companies</h2>
            <a href="companies.php" class="text-sm text-blue-600 hover:text-blue-800">View All &rarr;</a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left text-gray-500">
                <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3">Company</th>
                        <th scope="col" class="px-6 py-3">Status</th>
                        <th scope="col" class="px-6 py-3">Created</th>
                        <th scope="col" class="px-6 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentCompanies)): ?>
                    <tr>
                        <td colspan="4" class="px-6 py-6 text-center text-gray-500">No companies found.</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($recentCompanies as $company): ?>
                    <tr class="bg-white border-b hover:bg-gray-50">
                        <th scope="row" class="px-6 py-4 font-medium text-gray-900 whitespace-nowrap">
                            <div><?php echo sanitize($company['name']); ?></div>
                            <div class="text-xs text-gray-500 font-normal"><?php echo sanitize($company['slug']); ?></div>
                        </th>
                        <td class="px-6 py-4">
                            <span class="px-2 py-1 rounded-full text-xs font-semibold 
                                <?php echo ($company['status'] ?? 'active') === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-700'; ?>">
                                <?php echo sanitize(ucfirst($company['status'] ?? 'active')); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4"><?php echo date('M d, Y', strtotime($company['created_at'])); ?></td>
                        <td class="px-6 py-4">
                            <a href="employees.php?company_id=<?php echo $company['id']; ?>" class="text-blue-600 hover:text-blue-800 mr-2" title="View Employees">
                                <i class="fa-solid fa-users"></i>
                            </a>
                            <a href="<?php echo getBasePath() . $company['slug']; ?>/" target="_blank" class="text-green-600 hover:text-green-800" title="Visit Portal">
                                <i class="fa-solid fa-external-link"></i>
                            </a>
                        </td>
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
                        <td class="px-6 py-4"><?php echo Currency::formatHtml($tx['amount'], $tx['currency'] ?? 'OMR', 'sm'); ?></td>
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
