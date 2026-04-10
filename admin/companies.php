<?php
/**
 * Company Management (Super Admin) - Cardify
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Currency.php';
require_once INCLUDES_DIR . '/admin-layout.php';

Auth::requireRole('super_admin');

$db = Database::getInstance();
$message = null;
$messageType = 'success';

$useDatabase = class_exists('DatabaseAdapter') && DatabaseAdapter::useDatabase();

// Check for currency column
$hasCurrency = false;
if ($useDatabase && method_exists($db, 'columnExists')) {
    $hasCurrency = $db->columnExists('companies', 'currency');
}

// Get subscription plans
$plans = [];
if ($useDatabase) {
    try {
        $plans = $db->fetchAll("SELECT id, name FROM subscription_plans WHERE is_active = 1 ORDER BY sort_order, name");
    } catch (Exception $e) {
        $plans = [
            ['id' => 'free', 'name' => 'Free'],
            ['id' => 'pro', 'name' => 'Pro'],
            ['id' => 'enterprise', 'name' => 'Enterprise']
        ];
    }
}
if (empty($plans)) {
    $plans = [
        ['id' => 'free', 'name' => 'Free'],
        ['id' => 'pro', 'name' => 'Pro'],
        ['id' => 'enterprise', 'name' => 'Enterprise']
    ];
}

// Get currencies from plan_prices or use defaults (OMR first — Oman-first product)
$currencies = ['OMR', 'USD', 'AED', 'SAR', 'EUR'];
if ($useDatabase) {
    try {
        $currencyRows = $db->fetchAll("SELECT DISTINCT currency FROM plan_prices ORDER BY currency");
        if (!empty($currencyRows)) {
            $currencies = array_column($currencyRows, 'currency');
        }
    } catch (Exception $e) {
        // Use defaults
    }
}

// Get all companies with stats
if ($useDatabase) {
    // Check if print_orders table exists
    $hasOrdersTable = false;
    try {
        $db->fetchOne("SELECT 1 FROM print_orders LIMIT 1");
        $hasOrdersTable = true;
    } catch (Exception $e) {
        // print_orders table doesn't exist yet
    }
    
    if ($hasOrdersTable) {
        $companies = $db->fetchAll("
            SELECT c.*,
                (SELECT COUNT(*) FROM employees WHERE company_id = c.id) as employee_count,
                (SELECT COUNT(*) FROM print_orders WHERE company_id = c.id) as order_count,
                (SELECT COALESCE(SUM(total), 0) FROM print_orders WHERE company_id = c.id AND payment_status = 'paid') as total_spend
            FROM companies c 
            ORDER BY c.name
        ");
    } else {
        $companies = $db->fetchAll("
            SELECT c.*,
                (SELECT COUNT(*) FROM employees WHERE company_id = c.id) as employee_count,
                0 as order_count,
                0 as total_spend
            FROM companies c 
            ORDER BY c.name
        ");
    }
    
    // Get parent companies for hierarchy
    $parentCompanies = $db->fetchAll("SELECT id, name FROM companies ORDER BY name");
} else {
    $companies = [];
    $parentCompanies = [];
}

// Handle create/update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { die('Invalid request'); }
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $name = $_POST['name'] ?? '';
        $email = sanitizeEmail($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $parentId = $_POST['parent_company_id'] ?? null;
        $customSlug = $_POST['company_slug'] ?? null;
        $plan = $_POST['plan'] ?? 'free';
        $currency = $_POST['currency'] ?? 'OMR';
        
        if (!empty($name) && !empty($email) && !empty($password)) {
            $result = DatabaseAdapter::createCompany($name, $email, $password, $parentId, $customSlug);
            if ($result['success']) {
                // Update plan and currency
                if ($useDatabase && !empty($result['company']['id'])) {
                    $updateData = ['plan' => $plan];
                    if ($hasCurrency) {
                        $updateData['currency'] = $currency;
                    }
                    $db->update('companies', $updateData, 'id = :id', ['id' => $result['company']['id']]);
                    
                    // Audit log
                    if (class_exists('AuditLog')) {
                        AuditLog::logCompany('create', $result['company']['id'], null, array_merge($result['company'], $updateData));
                    }
                }
                
                $message = 'Company created successfully!';
                // Refresh companies list
                $companies = $db->fetchAll("
                    SELECT c.*,
                        (SELECT COUNT(*) FROM employees WHERE company_id = c.id) as employee_count,
                        (SELECT COUNT(*) FROM print_orders WHERE company_id = c.id) as order_count,
                        (SELECT COALESCE(SUM(total), 0) FROM print_orders WHERE company_id = c.id AND payment_status = 'paid') as total_spend
                    FROM companies c 
                    ORDER BY c.name
                ");
            } else {
                $message = $result['error'] ?? 'Failed to create company';
                $messageType = 'error';
            }
        }
    } elseif ($action === 'update') {
        $companyId = $_POST['company_id'] ?? '';
        
        // Get current data for audit
        $beforeData = null;
        if (class_exists('AuditLog') && $useDatabase) {
            $beforeData = $db->fetchOne("SELECT * FROM companies WHERE id = :id", ['id' => $companyId]);
        }
        
        $updateData = [];
        
        if (!empty($_POST['name'])) $updateData['name'] = $_POST['name'];
        if (!empty($_POST['slug'])) $updateData['slug'] = $_POST['slug'];
        if (!empty($_POST['admin_email'])) $updateData['admin_email'] = sanitizeEmail($_POST['admin_email']);
        if (!empty($_POST['billing_email'])) $updateData['billing_email'] = sanitizeEmail($_POST['billing_email']);
        if (!empty($_POST['password'])) $updateData['password'] = $_POST['password'];
        if (isset($_POST['plan'])) $updateData['plan'] = $_POST['plan'];
        if (isset($_POST['status'])) $updateData['status'] = $_POST['status'];
        if (isset($_POST['currency']) && $hasCurrency) $updateData['currency'] = $_POST['currency'];
        if (isset($_POST['parent_company_id'])) $updateData['parent_company_id'] = $_POST['parent_company_id'] ?: null;
        
        if (!empty($companyId) && !empty($updateData)) {
            $result = DatabaseAdapter::updateCompany($companyId, $updateData);
            if ($result['success']) {
                $message = 'Company updated successfully!';
                
                // Audit log
                if (class_exists('AuditLog') && $beforeData) {
                    $afterData = $db->fetchOne("SELECT * FROM companies WHERE id = :id", ['id' => $companyId]);
                    AuditLog::logCompany('update', $companyId, $beforeData, $afterData);
                }
                
                // Refresh companies list
                $companies = $db->fetchAll("
                    SELECT c.*,
                        (SELECT COUNT(*) FROM employees WHERE company_id = c.id) as employee_count,
                        (SELECT COUNT(*) FROM print_orders WHERE company_id = c.id) as order_count,
                        (SELECT COALESCE(SUM(total), 0) FROM print_orders WHERE company_id = c.id AND payment_status = 'paid') as total_spend
                    FROM companies c 
                    ORDER BY c.name
                ");
            } else {
                $message = $result['error'] ?? 'Failed to update company';
                $messageType = 'error';
            }
        }
    }
}

adminHeader('Companies', 'companies');
?>

<div x-data="companyManager()">
    <!-- Page Header Actions -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <p class="text-gray-600"><?php echo count($companies); ?> registered companies</p>
        </div>
        <button @click="showModal = true; editMode = false; resetForm()" 
                class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors text-sm font-medium flex items-center gap-2">
            <i class="fa-solid fa-plus"></i>
            <span>Add Company</span>
        </button>
    </div>

    <!-- Alert Message -->
<?php if ($message): ?>
    <div class="mb-6 p-4 rounded-xl flex items-center gap-3 <?php echo $messageType === 'success' ? 'bg-green-50 border border-green-200 text-green-700' : 'bg-red-50 border border-red-200 text-red-700'; ?>">
        <i class="<?php echo $messageType === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-exclamation'; ?>"></i>
        <?php echo sanitize($message); ?>
    </div>
<?php endif; ?>

    <!-- Companies Table -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-6 py-4 text-gray-600 font-semibold text-sm">Company</th>
                        <th class="text-left px-6 py-4 text-gray-600 font-semibold text-sm">Admin</th>
                        <th class="text-left px-6 py-4 text-gray-600 font-semibold text-sm">Plan</th>
                        <th class="text-left px-6 py-4 text-gray-600 font-semibold text-sm">Currency</th>
                        <th class="text-left px-6 py-4 text-gray-600 font-semibold text-sm">Stats</th>
                        <th class="text-left px-6 py-4 text-gray-600 font-semibold text-sm">Status</th>
                        <th class="text-right px-6 py-4 text-gray-600 font-semibold text-sm">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if (empty($companies)): ?>
                    <tr>
                        <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                            <i class="fa-solid fa-building text-4xl mb-3 opacity-30"></i>
                            <p>No companies found. Create your first company to get started.</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php foreach ($companies as $company): ?>
                    <tr class="hover:bg-gray-50 transition-colors">
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center text-white font-semibold text-sm">
                                    <?php echo strtoupper(substr($company['name'], 0, 2)); ?>
                                </div>
                                <div>
                                    <p class="font-semibold text-gray-900"><?php echo sanitize($company['name']); ?></p>
                                    <p class="text-gray-500 text-sm"><?php echo sanitize($company['slug']); ?></p>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <p class="text-gray-900 text-sm"><?php echo sanitize($company['admin_email']); ?></p>
                            <?php if (!empty($company['billing_email']) && $company['billing_email'] !== $company['admin_email']): ?>
                            <p class="text-gray-500 text-xs">Billing: <?php echo sanitize($company['billing_email']); ?></p>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium 
                                <?php 
                                $plan = $company['plan'] ?? 'free';
                                echo match($plan) {
                                    'enterprise' => 'bg-purple-100 text-purple-700',
                                    'pro' => 'bg-blue-100 text-blue-700',
                                    default => 'bg-gray-100 text-gray-700'
                                };
                                ?>">
                                <?php echo ucfirst($plan); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-gray-700 text-sm font-medium"><?php echo $company['currency'] ?? 'OMR'; ?></span>
                        </td>
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-4 text-sm">
                                <div class="flex items-center gap-1 text-gray-600" title="Employees">
                                    <i class="fa-solid fa-users text-xs"></i>
                                    <span><?php echo (int)($company['employee_count'] ?? 0); ?></span>
                                </div>
                                <div class="flex items-center gap-1 text-gray-600" title="Orders">
                                    <i class="fa-solid fa-shopping-cart text-xs"></i>
                                    <span><?php echo (int)($company['order_count'] ?? 0); ?></span>
                                </div>
                                <div class="flex items-center gap-1 text-green-600" title="Total Spend">
                                    <span><?php echo Currency::formatHtml((float)($company['total_spend'] ?? 0), $company['currency'] ?? 'OMR', 'sm'); ?></span>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium
                                <?php 
                                $status = $company['status'] ?? 'active';
                                echo match($status) {
                                    'active' => 'bg-green-100 text-green-700',
                                    'suspended' => 'bg-red-100 text-red-700',
                                    'pending' => 'bg-amber-100 text-amber-700',
                                    default => 'bg-gray-100 text-gray-700'
                                };
                                ?>">
                                <span class="w-1.5 h-1.5 rounded-full <?php echo $status === 'active' ? 'bg-green-500' : ($status === 'suspended' ? 'bg-red-500' : 'bg-amber-500'); ?>"></span>
                                <?php echo ucfirst($status); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <a href="/<?php echo urlencode($company['slug']); ?>" target="_blank"
                                   class="p-2 text-gray-500 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
                                   title="View Portal">
                                    <i class="fa-solid fa-external-link"></i>
                                </a>
                                <button 
                                    @click='openEditModal(<?php echo json_encode($company); ?>)'
                                    class="p-2 text-gray-500 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
                                    title="Edit Company"
                                >
                                    <i class="fa-solid fa-pen-to-square"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add/Edit Modal -->
    <div x-show="showModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" @keydown.escape.window="showModal = false">
        <div class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" @click="showModal = false"></div>
        <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
            <div class="p-6 border-b border-gray-100">
                <h3 class="text-xl font-bold text-gray-900" x-text="editMode ? 'Edit Company' : 'Create Company'"></h3>
            </div>
            
            <form method="post" class="p-6">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" :value="editMode ? 'update' : 'create'">
                <input type="hidden" name="company_id" x-model="formData.id">
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Company Name <span class="text-red-500">*</span></label>
                        <input type="text" name="name" x-model="formData.name" required 
                               class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    </div>
                    
                    <div x-show="!editMode">
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Company Slug</label>
                        <input type="text" name="company_slug" x-model="formData.slug" 
                               class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                               placeholder="auto-generated if empty">
                    </div>
                    
                    <div x-show="editMode">
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Slug</label>
                        <input type="text" name="slug" x-model="formData.slug"
                               class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Admin Email <span class="text-red-500">*</span></label>
                            <input type="email" name="email" x-model="formData.admin_email" :required="!editMode"
                                   class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Billing Email</label>
                            <input type="email" name="billing_email" x-model="formData.billing_email"
                                   class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                                   placeholder="Same as admin if empty">
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Password <span x-show="!editMode" class="text-red-500">*</span></label>
                        <input type="password" name="password" :required="!editMode"
                               class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                               :placeholder="editMode ? 'Leave blank to keep current' : ''">
                    </div>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Plan</label>
                            <select name="plan" x-model="formData.plan" 
                                    class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                                <?php foreach ($plans as $plan): ?>
                                <option value="<?php echo sanitize($plan['id']); ?>"><?php echo sanitize($plan['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Currency</label>
                            <select name="currency" x-model="formData.currency" 
                                    class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                                <?php foreach ($currencies as $curr): ?>
                                <option value="<?php echo sanitize($curr); ?>"><?php echo sanitize($curr); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div x-show="editMode">
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Status</label>
                            <select name="status" x-model="formData.status" 
                                    class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                                <option value="active">Active</option>
                                <option value="pending">Pending</option>
                                <option value="suspended">Suspended</option>
                            </select>
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Parent Company</label>
                        <select name="parent_company_id" x-model="formData.parent_company_id" 
                                class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                            <option value="">None (Standalone)</option>
                            <?php foreach ($parentCompanies as $parent): ?>
                            <option value="<?php echo $parent['id']; ?>"><?php echo sanitize($parent['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="flex items-center justify-end gap-3 mt-6 pt-6 border-t border-gray-100">
                    <button type="button" @click="showModal = false" class="px-4 py-2 text-gray-600 hover:text-gray-900 font-medium transition-colors">
                        Cancel
                    </button>
                    <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors">
                        <span x-text="editMode ? 'Save Changes' : 'Create Company'"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function companyManager() {
    return {
        showModal: false,
        editMode: false,
        formData: { 
            id: '', 
            name: '', 
            slug: '', 
            admin_email: '', 
            billing_email: '',
            plan: 'free', 
            currency: 'OMR',
            status: 'active', 
            parent_company_id: '' 
        },
        
        resetForm() {
            this.formData = { 
                id: '', 
                name: '', 
                slug: '', 
                admin_email: '', 
                billing_email: '',
                plan: 'free', 
                currency: 'OMR',
                status: 'active', 
                parent_company_id: '' 
            };
        },
        
        openEditModal(company) {
            this.editMode = true;
            this.formData = {
                id: company.id,
                name: company.name,
                slug: company.slug,
                admin_email: company.admin_email,
                billing_email: company.billing_email || '',
                plan: company.plan || 'free',
                currency: company.currency || 'USD',
                status: company.status || 'active',
                parent_company_id: company.parent_company_id || ''
            };
            this.showModal = true;
        }
    };
}
</script>

<?php adminFooter(); ?>
