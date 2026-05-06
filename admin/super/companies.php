<?php
/**
 * Super Admin - Companies Management
 * View, edit, and manage all companies
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/AuditLog.php';
require_once INCLUDES_DIR . '/admin-layout.php';

Auth::requireRole('super_admin');

$db = Database::getInstance();
$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    die('Invalid request');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_company') {
        $companyId = (int)$_POST['company_id'];
        $company = $db->fetchOne("SELECT * FROM companies WHERE id = :id", ['id' => $companyId]);
        
        if ($company) {
            $updateData = [
                'name' => sanitize($_POST['name']),
                'slug' => sanitize($_POST['slug']),
                'admin_email' => sanitizeEmail($_POST['admin_email']),
                'status' => sanitize($_POST['status']),
                'plan' => sanitize($_POST['plan']),
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            // Update password if provided
            if (!empty($_POST['new_password'])) {
                $updateData['password_hash'] = password_hash($_POST['new_password'], PASSWORD_BCRYPT);
            }
            
            $db->update('companies', $updateData, 'id = :id', ['id' => $companyId]);
            
            AuditLog::logCompany('update', $companyId, $company, array_merge($company, $updateData));
            
            $message = 'Company updated successfully';
            $messageType = 'success';
        }
    } elseif ($action === 'delete_company') {
        $companyId = (int)$_POST['company_id'];
        $company = $db->fetchOne("SELECT * FROM companies WHERE id = :id", ['id' => $companyId]);
        
        if ($company) {
            $db->delete('companies', 'id = :id', ['id' => $companyId]);
            AuditLog::logCompany('delete', $companyId, $company, null);
            
            $message = 'Company deleted successfully';
            $messageType = 'success';
        }
    } elseif ($action === 'create_company') {
        require_once INCLUDES_DIR . '/CardifyConvention.php';

        $adminEmail = sanitizeEmail($_POST['admin_email']);
        $rawSlug    = sanitize($_POST['slug'] ?? '');

        // Convention: if admin leaves slug blank, derive from email domain.
        // E.g. admin@alali.om -> "alali"; collisions get -2, -3, ... appended.
        if ($rawSlug === '' && $adminEmail !== '') {
            $slug = CardifyConvention::companySlugFromEmail($adminEmail, $db);
        } else {
            $slug = strtolower($rawSlug);
        }

        // Validate the slug isn't reserved or already in use.
        $reserved = CardifyConvention::reservedSlugs();
        if (in_array($slug, $reserved, true)) {
            $message = "Slug '{$slug}' is reserved, please choose another";
            $messageType = 'error';
        } elseif ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/', $slug)) {
            $message = 'Slug must be 2-63 chars, lowercase letters, digits and hyphens only';
            $messageType = 'error';
        } else {
            $existing = $db->fetchOne("SELECT id FROM companies WHERE slug = :slug", ['slug' => $slug]);
            if ($existing) {
                $message = 'Company slug already exists';
                $messageType = 'error';
            } else {
                $emailDomain = sanitize($_POST['email_domain'] ?? '');
                if ($emailDomain === '' && strpos($adminEmail, '@') !== false) {
                    $emailDomain = strtolower(substr($adminEmail, strpos($adminEmail, '@') + 1));
                }

                $newCompany = [
                    'id' => generateUUID(),
                    'name' => sanitize($_POST['name']),
                    'slug' => $slug,
                    'admin_email' => $adminEmail,
                    'email_domain' => $emailDomain,
                    'password_hash' => password_hash($_POST['password'], PASSWORD_BCRYPT),
                    'status' => sanitize($_POST['status']),
                    'plan' => sanitize($_POST['plan']),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ];

                $db->insert('companies', $newCompany);
                // Use the locally-generated UUID; Database::insert returns
                // lastInsertId() which is '0' for UUID-PK tables.
                AuditLog::logCompany('create', $newCompany['id'], null, $newCompany);

                $message = "Company '{$slug}' created. Tenant URL: " . CardifyConvention::tenantUrl($slug);
                $messageType = 'success';
            }
        }
    }
}

// Filters
$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$planFilter = $_GET['plan'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Build query
$where = [];
$params = [];

if ($search) {
    // PDO emulated prepares are OFF; reusing :search would 500 with HY093.
    // Split into 3 distinct placeholders bound to the same value (rule 12).
    $where[] = "(name LIKE :search_n OR slug LIKE :search_s OR admin_email LIKE :search_e)";
    $like = "%{$search}%";
    $params['search_n'] = $like;
    $params['search_s'] = $like;
    $params['search_e'] = $like;
}

if ($statusFilter) {
    $where[] = "status = :status";
    $params['status'] = $statusFilter;
}

if ($planFilter) {
    $where[] = "plan = :plan";
    $params['plan'] = $planFilter;
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Get total count
$totalResult = $db->fetchOne("SELECT COUNT(*) as count FROM companies {$whereClause}", $params);
$total = (int)($totalResult['count'] ?? 0);
$totalPages = ceil($total / $perPage);

// Get companies
$companies = $db->fetchAll(
    "SELECT c.*,
            (SELECT COUNT(*) FROM employees WHERE company_id = c.id) as employee_count,
            (SELECT COUNT(*) FROM generated_cards gc JOIN employees e ON gc.employee_id = e.id WHERE e.company_id = c.id) as card_count,
            (SELECT COUNT(*) FROM templates WHERE company_id = c.id) as template_count
     FROM companies c
     {$whereClause}
     ORDER BY c.created_at DESC
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

// Get plans for dropdown
$plans = $db->fetchAll("SELECT DISTINCT plan FROM companies WHERE plan IS NOT NULL ORDER BY plan");

adminHeader('Companies Management', 'companies');
?>

<?php if ($message): ?>
<div class="mb-4 p-4 rounded-lg <?php echo $messageType === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
    <?php echo sanitize($message); ?>
</div>
<?php endif; ?>

<!-- Filters and Actions -->
<div class="bg-white rounded-lg shadow p-4 mb-6">
    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <form method="GET" class="flex flex-col sm:flex-row gap-3 flex-1">
            <input type="text" name="search" value="<?php echo sanitize($search); ?>" 
                   placeholder="Search companies..." 
                   class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
            <select name="status" class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                <option value="">All Status</option>
                <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                <option value="suspended" <?php echo $statusFilter === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
            </select>
            <select name="plan" class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                <option value="">All Plans</option>
                <?php foreach ($plans as $p): ?>
                <option value="<?php echo sanitize($p['plan']); ?>" <?php echo $planFilter === $p['plan'] ? 'selected' : ''; ?>>
                    <?php echo sanitize(ucfirst($p['plan'])); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                <i class="fa-solid fa-search mr-2"></i>Filter
            </button>
        </form>
        <button onclick="openCreateModal()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
            <i class="fa-solid fa-plus mr-2"></i>Add Company
        </button>
    </div>
</div>

<!-- Stats -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-lg shadow p-4">
        <div class="text-sm text-gray-500">Total Companies</div>
        <div class="text-2xl font-semibold"><?php echo $total; ?></div>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <div class="text-sm text-gray-500">Active</div>
        <div class="text-2xl font-semibold text-green-600">
            <?php echo $db->fetchOne("SELECT COUNT(*) as c FROM companies WHERE status = 'active'")['c'] ?? 0; ?>
        </div>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <div class="text-sm text-gray-500">Inactive</div>
        <div class="text-2xl font-semibold text-gray-600">
            <?php echo $db->fetchOne("SELECT COUNT(*) as c FROM companies WHERE status = 'inactive'")['c'] ?? 0; ?>
        </div>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <div class="text-sm text-gray-500">Total Employees</div>
        <div class="text-2xl font-semibold text-blue-600">
            <?php echo $db->fetchOne("SELECT COUNT(*) as c FROM employees")['c'] ?? 0; ?>
        </div>
    </div>
</div>

<!-- Companies Table -->
<div class="bg-white rounded-lg shadow overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left text-gray-500">
            <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                <tr>
                    <th class="px-6 py-3">Company</th>
                    <th class="px-6 py-3">Admin Email</th>
                    <th class="px-6 py-3">Status</th>
                    <th class="px-6 py-3">Plan</th>
                    <th class="px-6 py-3">Employees</th>
                    <th class="px-6 py-3">Templates</th>
                    <th class="px-6 py-3">Cards</th>
                    <th class="px-6 py-3">Created</th>
                    <th class="px-6 py-3">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($companies)): ?>
                <tr><td colspan="8" class="px-6 py-8 text-center text-gray-500">No companies found</td></tr>
                <?php else: ?>
                <?php foreach ($companies as $company): ?>
                <tr class="bg-white border-b hover:bg-gray-50">
                    <td class="px-6 py-4">
                        <div class="font-medium text-gray-900"><?php echo sanitize($company['name']); ?></div>
                        <div class="text-xs text-gray-500"><?php echo sanitize($company['slug']); ?></div>
                    </td>
                    <td class="px-6 py-4"><?php echo sanitize($company['admin_email']); ?></td>
                    <td class="px-6 py-4">
                        <span class="px-2 py-1 rounded-full text-xs font-semibold 
                            <?php echo $company['status'] === 'active' ? 'bg-green-100 text-green-700' : 
                                       ($company['status'] === 'suspended' ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-700'); ?>">
                            <?php echo sanitize(ucfirst($company['status'])); ?>
                        </span>
                    </td>
                    <td class="px-6 py-4">
                        <span class="px-2 py-1 bg-blue-100 text-blue-700 rounded-full text-xs font-semibold">
                            <?php echo sanitize(ucfirst($company['plan'] ?? 'free')); ?>
                        </span>
                    </td>
                    <td class="px-6 py-4"><?php echo $company['employee_count']; ?></td>
                    <td class="px-6 py-4">
                        <?php if ((int)$company['template_count'] === 0 && (int)$company['employee_count'] > 0): ?>
                            <span class="text-amber-600 font-semibold" title="No templates, cards can't be generated">
                                0 <i class="fa-solid fa-triangle-exclamation text-xs ml-0.5"></i>
                            </span>
                        <?php else: ?>
                            <?php echo $company['template_count']; ?>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4"><?php echo $company['card_count']; ?></td>
                    <td class="px-6 py-4"><?php echo date('M d, Y', strtotime($company['created_at'])); ?></td>
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-2">
                            <button onclick='openEditModal(<?php echo json_encode($company); ?>)' 
                                    class="text-blue-600 hover:text-blue-800" title="Edit">
                                <i class="fa-solid fa-edit"></i>
                            </button>
                            <a href="employees.php?company_id=<?php echo $company['id']; ?>" 
                               class="text-green-600 hover:text-green-800" title="View Employees">
                                <i class="fa-solid fa-users"></i>
                            </a>
                            <a href="<?php echo htmlspecialchars(getTenantUrl($company['slug'])); ?>" target="_blank"
                               class="text-purple-600 hover:text-purple-800" title="Visit Portal">
                                <i class="fa-solid fa-external-link"></i>
                            </a>
                            <?php if ($company['status'] === 'active'): ?>
                            <form method="POST"
                                  action="<?php echo getBasePath(); ?>admin/impersonate.php?action=start"
                                  class="inline m-0"
                                  onsubmit="return confirm('Log in as <?php echo sanitize($company['name']); ?>? You will see Cardify as this company admin. The action is logged to the audit trail.');">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
                                <input type="hidden" name="company_id" value="<?php echo htmlspecialchars($company['id']); ?>">
                                <button type="submit"
                                        class="text-amber-600 hover:text-amber-800"
                                        title="Login as this company admin">
                                    <i class="fa-solid fa-user-secret"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                            <button onclick="confirmDelete(<?php echo $company['id']; ?>, '<?php echo sanitize($company['name']); ?>')"
                                    class="text-red-600 hover:text-red-800" title="Delete">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="px-6 py-4 border-t border-gray-100">
        <div class="flex items-center justify-between">
            <div class="text-sm text-gray-500">
                Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $perPage, $total); ?> of <?php echo $total; ?> companies
            </div>
            <div class="flex gap-2">
                <?php if ($page > 1): ?>
                <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($statusFilter); ?>&plan=<?php echo urlencode($planFilter); ?>" 
                   class="px-3 py-1 border rounded hover:bg-gray-50">Previous</a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($statusFilter); ?>&plan=<?php echo urlencode($planFilter); ?>" 
                   class="px-3 py-1 border rounded hover:bg-gray-50">Next</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Edit Modal -->
<div id="editModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-lg">
        <div class="p-6 border-b">
            <h3 class="text-lg font-semibold" id="modalTitle">Edit Company</h3>
        </div>
        <form method="POST" id="companyForm">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" id="formAction" value="update_company">
            <input type="hidden" name="company_id" id="companyId">
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Company Name</label>
                    <input type="text" name="name" id="companyName" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Slug</label>
                    <input type="text" name="slug" id="companySlug" required pattern="[a-z0-9-]+"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                    <p class="text-xs text-gray-500 mt-1">Lowercase letters, numbers, and hyphens only</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Admin Email</label>
                    <input type="email" name="admin_email" id="companyEmail" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div id="passwordField">
                    <label class="block text-sm font-medium text-gray-700 mb-1" id="passwordLabel">New Password</label>
                    <input type="password" name="new_password" id="companyPassword"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                    <p class="text-xs text-gray-500 mt-1" id="passwordHint">Leave blank to keep current password</p>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                        <select name="status" id="companyStatus" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="suspended">Suspended</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Plan</label>
                        <select name="plan" id="companyPlan" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                            <option value="free">Free</option>
                            <option value="starter">Starter</option>
                            <option value="professional">Professional</option>
                            <option value="enterprise">Enterprise</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="p-6 border-t bg-gray-50 flex justify-end gap-3">
                <button type="button" onclick="closeModal()" class="px-4 py-2 border rounded-lg hover:bg-gray-100">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="p-6">
            <h3 class="text-lg font-semibold text-red-600 mb-2">Delete Company</h3>
            <p class="text-gray-600">Are you sure you want to delete <strong id="deleteCompanyName"></strong>? This will also delete all employees and cards associated with this company. This action cannot be undone.</p>
        </div>
        <form method="POST" class="p-6 border-t bg-gray-50 flex justify-end gap-3">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="delete_company">
            <input type="hidden" name="company_id" id="deleteCompanyId">
            <button type="button" onclick="closeDeleteModal()" class="px-4 py-2 border rounded-lg hover:bg-gray-100">Cancel</button>
            <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">Delete Company</button>
        </form>
    </div>
</div>

<script>
function openEditModal(company) {
    document.getElementById('modalTitle').textContent = 'Edit Company';
    document.getElementById('formAction').value = 'update_company';
    document.getElementById('companyId').value = company.id;
    document.getElementById('companyName').value = company.name;
    document.getElementById('companySlug').value = company.slug;
    document.getElementById('companyEmail').value = company.admin_email;
    document.getElementById('companyPassword').value = '';
    document.getElementById('companyStatus').value = company.status || 'active';
    document.getElementById('companyPlan').value = company.plan || 'free';
    document.getElementById('passwordLabel').textContent = 'New Password';
    document.getElementById('passwordHint').textContent = 'Leave blank to keep current password';
    document.getElementById('companyPassword').removeAttribute('required');
    document.getElementById('editModal').classList.remove('hidden');
}

function openCreateModal() {
    document.getElementById('modalTitle').textContent = 'Add Company';
    document.getElementById('formAction').value = 'create_company';
    document.getElementById('companyId').value = '';
    document.getElementById('companyName').value = '';
    document.getElementById('companySlug').value = '';
    document.getElementById('companyEmail').value = '';
    document.getElementById('companyPassword').value = '';
    document.getElementById('companyStatus').value = 'active';
    document.getElementById('companyPlan').value = 'free';
    document.getElementById('passwordLabel').textContent = 'Password';
    document.getElementById('passwordHint').textContent = 'Required for new company';
    document.getElementById('companyPassword').setAttribute('required', 'required');
    document.getElementById('companyPassword').name = 'password';
    document.getElementById('editModal').classList.remove('hidden');
}

function closeModal() {
    document.getElementById('editModal').classList.add('hidden');
    document.getElementById('companyPassword').name = 'new_password';
}

function confirmDelete(id, name) {
    document.getElementById('deleteCompanyId').value = id;
    document.getElementById('deleteCompanyName').textContent = name;
    document.getElementById('deleteModal').classList.remove('hidden');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.add('hidden');
}

// Close modals on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal();
        closeDeleteModal();
    }
});

// Auto-generate slug from name
document.getElementById('companyName').addEventListener('input', function() {
    if (document.getElementById('formAction').value === 'create_company') {
        document.getElementById('companySlug').value = this.value
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-|-$/g, '');
    }
});
</script>

<?php adminFooter(); ?>
