<?php
/**
 * Company Subscriptions Management (Super Admin) - Cardify
 * Manage company plans, subscriptions, and billing
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';
require_once INCLUDES_DIR . '/Billing.php';
require_once INCLUDES_DIR . '/Currency.php';

Auth::requireRole('super_admin');

$db = Database::getInstance();
$message = null;
$messageType = 'success';

// Get all plans for dropdown
$plans = $db->fetchAll("SELECT * FROM subscription_plans WHERE is_active = 1 ORDER BY sort_order, name");

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    die('Invalid request');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_subscription') {
        $companyId = $_POST['company_id'] ?? '';
        $planId = $_POST['plan_id'] ?? 'free';
        $subscriptionStatus = $_POST['subscription_status'] ?? 'inactive';
        $durationType = $_POST['duration_type'] ?? 'custom';
        $expiresAt = null;
        
        // Calculate expiry date based on duration type
        if ($subscriptionStatus === 'active') {
            switch ($durationType) {
                case '1_month':
                    $expiresAt = date('Y-m-d H:i:s', strtotime('+1 month'));
                    break;
                case '3_months':
                    $expiresAt = date('Y-m-d H:i:s', strtotime('+3 months'));
                    break;
                case '6_months':
                    $expiresAt = date('Y-m-d H:i:s', strtotime('+6 months'));
                    break;
                case '1_year':
                    $expiresAt = date('Y-m-d H:i:s', strtotime('+1 year'));
                    break;
                case 'lifetime':
                    $expiresAt = date('Y-m-d H:i:s', strtotime('+100 years'));
                    break;
                case 'custom':
                    $expiresAt = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
                    break;
                case 'extend':
                    // Extend from current expiry or from now
                    $company = $db->fetchOne("SELECT subscription_expires_at FROM companies WHERE id = :id", ['id' => $companyId]);
                    $baseDate = ($company && $company['subscription_expires_at'] && strtotime($company['subscription_expires_at']) > time()) 
                        ? $company['subscription_expires_at'] 
                        : date('Y-m-d H:i:s');
                    $extendMonths = (int)($_POST['extend_months'] ?? 1);
                    $expiresAt = date('Y-m-d H:i:s', strtotime($baseDate . " +{$extendMonths} months"));
                    break;
            }
        }
        
        // Update company subscription
        $updateData = [
            'plan' => $planId,
            'subscription_status' => $subscriptionStatus,
            'subscription_expires_at' => $expiresAt,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        $db->update('companies', $updateData, 'id = :id', ['id' => $companyId]);
        
        // Log the change
        if (class_exists('AuditLog')) {
            AuditLog::log('subscription_update', 'company', $companyId, [
                'plan' => $planId,
                'status' => $subscriptionStatus,
                'expires_at' => $expiresAt,
                'duration_type' => $durationType
            ]);
        }
        
        $message = 'Subscription updated successfully!';
    } elseif ($action === 'bulk_update') {
        $companyIds = $_POST['company_ids'] ?? [];
        $planId = $_POST['bulk_plan_id'] ?? '';
        $bulkAction = $_POST['bulk_action'] ?? '';
        
        if (!empty($companyIds) && !empty($bulkAction)) {
            $updateCount = 0;
            
            foreach ($companyIds as $companyId) {
                $updateData = [];
                
                switch ($bulkAction) {
                    case 'upgrade':
                        $updateData = [
                            'plan' => $planId,
                            'subscription_status' => 'active',
                            'subscription_expires_at' => date('Y-m-d H:i:s', strtotime('+1 month'))
                        ];
                        break;
                    case 'downgrade_free':
                        $updateData = [
                            'plan' => 'free',
                            'subscription_status' => 'inactive',
                            'subscription_expires_at' => null
                        ];
                        break;
                    case 'extend_1_month':
                        $company = $db->fetchOne("SELECT subscription_expires_at FROM companies WHERE id = :id", ['id' => $companyId]);
                        $baseDate = ($company && $company['subscription_expires_at'] && strtotime($company['subscription_expires_at']) > time()) 
                            ? $company['subscription_expires_at'] 
                            : date('Y-m-d H:i:s');
                        $updateData = [
                            'subscription_expires_at' => date('Y-m-d H:i:s', strtotime($baseDate . ' +1 month'))
                        ];
                        break;
                    case 'cancel':
                        $updateData = [
                            'subscription_status' => 'cancelled',
                            'subscription_expires_at' => date('Y-m-d H:i:s') // Expire immediately
                        ];
                        break;
                }
                
                if (!empty($updateData)) {
                    $updateData['updated_at'] = date('Y-m-d H:i:s');
                    $db->update('companies', $updateData, 'id = :id', ['id' => $companyId]);
                    $updateCount++;
                }
            }
            
            $message = "Updated {$updateCount} company subscriptions!";
        }
    }
}

// Get companies with subscription info
$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';

$whereClause = "1=1";
$params = [];

if ($filter === 'free') {
    $whereClause .= " AND (c.plan = 'free' OR c.plan IS NULL)";
} elseif ($filter === 'paid') {
    $whereClause .= " AND c.plan != 'free' AND c.plan IS NOT NULL";
} elseif ($filter === 'active') {
    $whereClause .= " AND c.subscription_status = 'active' AND (c.subscription_expires_at IS NULL OR c.subscription_expires_at > NOW())";
} elseif ($filter === 'expiring') {
    $whereClause .= " AND c.subscription_status = 'active' AND c.subscription_expires_at IS NOT NULL AND c.subscription_expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)";
} elseif ($filter === 'expired') {
    $whereClause .= " AND c.subscription_expires_at IS NOT NULL AND c.subscription_expires_at < NOW()";
}

if (!empty($search)) {
    $whereClause .= " AND (c.name LIKE :search OR c.admin_email LIKE :search OR c.slug LIKE :search)";
    $params['search'] = "%{$search}%";
}

$companies = $db->fetchAll("
    SELECT c.*, 
           sp.name as plan_name,
           (SELECT COUNT(*) FROM employees WHERE company_id = c.id) as employee_count,
           (SELECT COUNT(*) FROM templates WHERE company_id = c.id) as template_count
    FROM companies c
    LEFT JOIN subscription_plans sp ON c.plan = sp.id
    WHERE {$whereClause}
    ORDER BY c.created_at DESC
", $params);

// Stats
$stats = [
    'total' => count($companies),
    'free' => 0,
    'paid' => 0,
    'active' => 0,
    'expiring_soon' => 0
];

foreach ($companies as $c) {
    if ($c['plan'] === 'free' || empty($c['plan'])) {
        $stats['free']++;
    } else {
        $stats['paid']++;
    }
    
    if ($c['subscription_status'] === 'active' && (!$c['subscription_expires_at'] || strtotime($c['subscription_expires_at']) > time())) {
        $stats['active']++;
    }
    
    if ($c['subscription_expires_at'] && strtotime($c['subscription_expires_at']) > time() && strtotime($c['subscription_expires_at']) < strtotime('+30 days')) {
        $stats['expiring_soon']++;
    }
}

adminHeader('Subscriptions', 'super');
?>

<div x-data="subscriptionManager()">
    <!-- Page Header -->
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Company Subscriptions</h1>
            <p class="text-gray-600">Manage company plans and billing</p>
        </div>
        <a href="<?php echo getBasePath(); ?>admin/plans.php" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm font-medium transition-colors flex items-center gap-2">
            <i class="fa-solid fa-cog"></i>
            Manage Plans
        </a>
    </div>
    
    <?php if ($message): ?>
    <div class="mb-6 p-4 rounded-xl flex items-center gap-3 <?php echo $messageType === 'success' ? 'bg-green-50 border border-green-200 text-green-700' : 'bg-red-50 border border-red-200 text-red-700'; ?>">
        <i class="<?php echo $messageType === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-exclamation'; ?>"></i>
        <?php echo sanitize($message); ?>
    </div>
    <?php endif; ?>
    
    <!-- Stats -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
        <div class="bg-white rounded-xl border border-gray-200 p-4">
            <p class="text-2xl font-bold text-gray-900"><?php echo $stats['total']; ?></p>
            <p class="text-sm text-gray-500">Total Companies</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
            <p class="text-2xl font-bold text-gray-500"><?php echo $stats['free']; ?></p>
            <p class="text-sm text-gray-500">Free Plan</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
            <p class="text-2xl font-bold text-blue-600"><?php echo $stats['paid']; ?></p>
            <p class="text-sm text-gray-500">Paid Plans</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
            <p class="text-2xl font-bold text-green-600"><?php echo $stats['active']; ?></p>
            <p class="text-sm text-gray-500">Active Subscriptions</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
            <p class="text-2xl font-bold text-amber-600"><?php echo $stats['expiring_soon']; ?></p>
            <p class="text-sm text-gray-500">Expiring Soon</p>
        </div>
    </div>
    
    <!-- Filters & Search -->
    <div class="bg-white rounded-xl border border-gray-200 p-4 mb-6">
        <form method="get" class="flex flex-wrap items-center gap-4">
            <div class="flex items-center gap-2">
                <label class="text-sm text-gray-600">Filter:</label>
                <select name="filter" onchange="this.form.submit()" class="px-3 py-2 border border-gray-200 rounded-lg text-sm">
                    <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All Companies</option>
                    <option value="free" <?php echo $filter === 'free' ? 'selected' : ''; ?>>Free Plan</option>
                    <option value="paid" <?php echo $filter === 'paid' ? 'selected' : ''; ?>>Paid Plans</option>
                    <option value="active" <?php echo $filter === 'active' ? 'selected' : ''; ?>>Active Subscriptions</option>
                    <option value="expiring" <?php echo $filter === 'expiring' ? 'selected' : ''; ?>>Expiring Soon (30 days)</option>
                    <option value="expired" <?php echo $filter === 'expired' ? 'selected' : ''; ?>>Expired</option>
                </select>
            </div>
            
            <div class="flex-1 min-w-[200px]">
                <input type="text" name="search" value="<?php echo sanitize($search); ?>" placeholder="Search by name, email, or slug..." 
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
            </div>
            
            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium">
                Search
            </button>
        </form>
    </div>
    
    <!-- Bulk Actions -->
    <div x-show="selectedCompanies.length > 0" x-cloak class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-6">
        <form method="post" class="flex flex-wrap items-center gap-4">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="bulk_update">
            <template x-for="id in selectedCompanies" :key="id">
                <input type="hidden" name="company_ids[]" :value="id">
            </template>
            
            <span class="text-blue-700 font-medium"><span x-text="selectedCompanies.length"></span> companies selected</span>
            
            <select name="bulk_action" required class="px-3 py-2 border border-blue-200 rounded-lg text-sm bg-white">
                <option value="">Select Action...</option>
                <option value="upgrade">Upgrade to Plan</option>
                <option value="downgrade_free">Downgrade to Free</option>
                <option value="extend_1_month">Extend +1 Month</option>
                <option value="cancel">Cancel Subscription</option>
            </select>
            
            <select name="bulk_plan_id" class="px-3 py-2 border border-blue-200 rounded-lg text-sm bg-white">
                <?php foreach ($plans as $plan): ?>
                <option value="<?php echo $plan['id']; ?>"><?php echo sanitize($plan['name']); ?></option>
                <?php endforeach; ?>
            </select>
            
            <button type="submit" onclick="return confirm('Apply this action to all selected companies?')" 
                    class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium">
                Apply
            </button>
            
            <button type="button" @click="selectedCompanies = []" class="px-4 py-2 text-blue-600 hover:text-blue-700 text-sm font-medium">
                Clear Selection
            </button>
        </form>
    </div>
    
    <!-- Companies Table -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="px-4 py-3 text-left">
                            <input type="checkbox" @change="toggleAll($event.target.checked)" class="rounded border-gray-300">
                        </th>
                        <th class="px-4 py-3 text-left text-sm font-semibold text-gray-600">Company</th>
                        <th class="px-4 py-3 text-left text-sm font-semibold text-gray-600">Plan</th>
                        <th class="px-4 py-3 text-left text-sm font-semibold text-gray-600">Status</th>
                        <th class="px-4 py-3 text-left text-sm font-semibold text-gray-600">Expires</th>
                        <th class="px-4 py-3 text-left text-sm font-semibold text-gray-600">Usage</th>
                        <th class="px-4 py-3 text-right text-sm font-semibold text-gray-600">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($companies as $company): ?>
                    <?php 
                    $isExpired = $company['subscription_expires_at'] && strtotime($company['subscription_expires_at']) < time();
                    $isExpiringSoon = $company['subscription_expires_at'] && strtotime($company['subscription_expires_at']) > time() && strtotime($company['subscription_expires_at']) < strtotime('+30 days');
                    $isFree = $company['plan'] === 'free' || empty($company['plan']);
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <input type="checkbox" :checked="selectedCompanies.includes('<?php echo $company['id']; ?>')"
                                   @change="toggleCompany('<?php echo $company['id']; ?>', $event.target.checked)"
                                   class="rounded border-gray-300">
                        </td>
                        <td class="px-4 py-3">
                            <div>
                                <p class="font-semibold text-gray-900"><?php echo sanitize($company['name_en'] ?? $company['name'] ?? 'Unnamed'); ?></p>
                                <p class="text-sm text-gray-500"><?php echo sanitize($company['email']); ?></p>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <?php if ($isFree): ?>
                            <span class="inline-flex px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                                Free
                            </span>
                            <?php else: ?>
                            <span class="inline-flex px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-700">
                                <?php echo sanitize($company['plan_name'] ?? ucfirst($company['plan'])); ?>
                            </span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <?php if ($company['subscription_status'] === 'active' && !$isExpired): ?>
                            <span class="inline-flex px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-700">
                                Active
                            </span>
                            <?php elseif ($isExpired): ?>
                            <span class="inline-flex px-2 py-1 rounded-full text-xs font-medium bg-red-100 text-red-700">
                                Expired
                            </span>
                            <?php elseif ($company['subscription_status'] === 'cancelled'): ?>
                            <span class="inline-flex px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                                Cancelled
                            </span>
                            <?php else: ?>
                            <span class="inline-flex px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                                Inactive
                            </span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <?php if ($company['subscription_expires_at']): ?>
                            <span class="text-sm <?php echo $isExpired ? 'text-red-600' : ($isExpiringSoon ? 'text-amber-600' : 'text-gray-600'); ?>">
                                <?php echo date('M j, Y', strtotime($company['subscription_expires_at'])); ?>
                                <?php if ($isExpiringSoon): ?>
                                <i class="fa-solid fa-clock ml-1" title="Expiring soon"></i>
                                <?php endif; ?>
                            </span>
                            <?php else: ?>
                            <span class="text-sm text-gray-400">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <div class="text-sm text-gray-600">
                                <span><i class="fa-solid fa-users text-gray-400 mr-1"></i><?php echo (int)$company['employee_count']; ?></span>
                                <span class="ml-3"><i class="fa-solid fa-palette text-gray-400 mr-1"></i><?php echo (int)$company['template_count']; ?></span>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button @click="openEditModal(<?php echo htmlspecialchars(json_encode($company), ENT_QUOTES); ?>)"
                                    class="px-3 py-1.5 bg-blue-50 hover:bg-blue-100 text-blue-700 rounded-lg text-sm font-medium transition-colors">
                                <i class="fa-solid fa-edit mr-1"></i> Edit
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Edit Subscription Modal -->
    <div x-show="showEditModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" @keydown.escape.window="showEditModal = false">
        <div class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" @click="showEditModal = false"></div>
        <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-lg">
            <div class="p-6 border-b border-gray-100">
                <h3 class="text-xl font-bold text-gray-900">Edit Subscription</h3>
                <p class="text-gray-500" x-text="editingCompany?.name_en || editingCompany?.name"></p>
            </div>
            
            <form method="post" class="p-6">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="update_subscription">
                <input type="hidden" name="company_id" x-model="editingCompany.id">
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Plan</label>
                        <select name="plan_id" x-model="editingCompany.plan" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg">
                            <option value="free">Free</option>
                            <?php foreach ($plans as $plan): ?>
                            <option value="<?php echo $plan['id']; ?>"><?php echo sanitize($plan['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Subscription Status</label>
                        <select name="subscription_status" x-model="editingCompany.subscription_status" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg">
                            <option value="inactive">Inactive</option>
                            <option value="active">Active</option>
                            <option value="cancelled">Cancelled</option>
                            <option value="past_due">Past Due</option>
                        </select>
                    </div>
                    
                    <div x-show="editingCompany.subscription_status === 'active'">
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Subscription Duration</label>
                        <select name="duration_type" x-model="durationType" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg">
                            <option value="1_month">1 Month</option>
                            <option value="3_months">3 Months</option>
                            <option value="6_months">6 Months</option>
                            <option value="1_year">1 Year</option>
                            <option value="lifetime">Lifetime</option>
                            <option value="extend">Extend Current</option>
                            <option value="custom">Custom Date</option>
                        </select>
                    </div>
                    
                    <div x-show="durationType === 'extend' && editingCompany.subscription_status === 'active'">
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Extend by (months)</label>
                        <input type="number" name="extend_months" value="1" min="1" max="60" 
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-lg">
                        <p class="text-xs text-gray-500 mt-1" x-show="editingCompany.subscription_expires_at">
                            Current expiry: <span x-text="editingCompany.subscription_expires_at"></span>
                        </p>
                    </div>
                    
                    <div x-show="durationType === 'custom' && editingCompany.subscription_status === 'active'">
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Expiry Date</label>
                        <input type="datetime-local" name="expires_at" x-model="editingCompany.subscription_expires_at"
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-lg">
                    </div>
                    
                    <div class="p-4 bg-blue-50 rounded-lg">
                        <h4 class="font-semibold text-blue-800 mb-2">Plan Features</h4>
                        <div class="text-sm text-blue-700 space-y-1">
                            <p x-show="editingCompany.plan === 'free' || !editingCompany.plan">
                                <i class="fa-solid fa-times-circle text-red-500 mr-1"></i> Low quality card previews
                            </p>
                            <p x-show="editingCompany.plan && editingCompany.plan !== 'free'">
                                <i class="fa-solid fa-check-circle text-green-500 mr-1"></i> High quality card generation
                            </p>
                            <p x-show="editingCompany.plan && editingCompany.plan !== 'free'">
                                <i class="fa-solid fa-check-circle text-green-500 mr-1"></i> QR scan analytics
                            </p>
                        </div>
                    </div>
                </div>
                
                <div class="flex items-center justify-end gap-3 mt-6 pt-6 border-t border-gray-100">
                    <button type="button" @click="showEditModal = false" class="px-4 py-2 text-gray-600 hover:text-gray-900 font-medium">
                        Cancel
                    </button>
                    <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium">
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function subscriptionManager() {
    return {
        selectedCompanies: [],
        showEditModal: false,
        editingCompany: {},
        durationType: '1_month',
        
        toggleCompany(id, checked) {
            if (checked) {
                if (!this.selectedCompanies.includes(id)) {
                    this.selectedCompanies.push(id);
                }
            } else {
                this.selectedCompanies = this.selectedCompanies.filter(c => c !== id);
            }
        },
        
        toggleAll(checked) {
            if (checked) {
                this.selectedCompanies = <?php echo json_encode(array_column($companies, 'id')); ?>;
            } else {
                this.selectedCompanies = [];
            }
        },
        
        openEditModal(company) {
            this.editingCompany = { ...company };
            this.editingCompany.plan = company.plan || 'free';
            this.editingCompany.subscription_status = company.subscription_status || 'inactive';
            this.durationType = '1_month';
            this.showEditModal = true;
        }
    };
}
</script>

<?php adminFooter(); ?>
