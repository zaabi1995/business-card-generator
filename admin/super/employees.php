<?php
/**
 * Super Admin - Employees Management
 * View, edit, and manage all employees across all companies
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/AuditLog.php';
require_once INCLUDES_DIR . '/admin-layout.php';
require_once INCLUDES_DIR . '/CardifyConvention.php';

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
    
    if ($action === 'update_employee') {
        // employee ids are VARCHAR (email localpart / slug), never ints.
        $employeeId = trim((string)($_POST['employee_id'] ?? ''));
        $employee = $db->fetchOne("SELECT * FROM employees WHERE id = :id", ['id' => $employeeId]);
        
        if ($employee) {
            $updateData = [
                'name_en' => sanitize($_POST['name_en']),
                'name_ar' => sanitize($_POST['name_ar']),
                'email' => sanitizeEmail($_POST['email']),
                'position_en' => sanitize($_POST['position_en']),
                'position_ar' => sanitize($_POST['position_ar']),
                'phone' => sanitize($_POST['phone']),
                'mobile' => sanitize($_POST['mobile']),
                'status' => sanitize($_POST['status']),
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            // Update password if provided
            if (!empty($_POST['new_password'])) {
                $updateData['password_hash'] = password_hash($_POST['new_password'], PASSWORD_BCRYPT);
            }
            
            $db->update('employees', $updateData, 'id = :id', ['id' => $employeeId]);
            
            AuditLog::log('update', 'employee', $employeeId, $employee, array_merge($employee, $updateData), $employee['company_id']);
            
            $message = 'Employee updated successfully';
            $messageType = 'success';
        }
    } elseif ($action === 'delete_employee') {
        $employeeId = trim((string)($_POST['employee_id'] ?? ''));
        $employee = $db->fetchOne("SELECT * FROM employees WHERE id = :id", ['id' => $employeeId]);
        
        if ($employee) {
            $db->delete('employees', 'id = :id', ['id' => $employeeId]);
            AuditLog::log('delete', 'employee', $employeeId, $employee, null, $employee['company_id']);
            
            $message = 'Employee deleted successfully';
            $messageType = 'success';
        }
    } elseif ($action === 'reset_password') {
        $employeeId = trim((string)($_POST['employee_id'] ?? ''));
        $newPassword = $_POST['new_password'];

        if ($employeeId !== '' && $newPassword) {
            $employee = $db->fetchOne("SELECT * FROM employees WHERE id = :id", ['id' => $employeeId]);
            if ($employee) {
                $db->update('employees', 
                    ['password_hash' => password_hash($newPassword, PASSWORD_BCRYPT), 'updated_at' => date('Y-m-d H:i:s')],
                    'id = :id', ['id' => $employeeId]
                );
                
                AuditLog::log('password_reset', 'employee', $employeeId, null, ['action' => 'Password reset by super admin'], $employee['company_id']);
                
                $message = 'Password reset successfully';
                $messageType = 'success';
            }
        }
    }
}

// Filters
$search = $_GET['search'] ?? '';
// company ids are VARCHAR slugs (e.g. "otech7010-rfq..."), never ints.
// Casting to (int) silently zeroed the filter for every slug-id company.
$companyFilter = trim((string)($_GET['company_id'] ?? ''));
$statusFilter = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

// Build query
$where = [];
$params = [];

if ($search) {
    $where[] = "(e.name_en LIKE :search OR e.name_ar LIKE :search OR e.email LIKE :search OR e.position_en LIKE :search)";
    $params['search'] = "%{$search}%";
}

if ($companyFilter) {
    $where[] = "e.company_id = :company_id";
    $params['company_id'] = $companyFilter;
}

if ($statusFilter) {
    $where[] = "e.status = :status";
    $params['status'] = $statusFilter;
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Get total count
$totalResult = $db->fetchOne("SELECT COUNT(*) as count FROM employees e {$whereClause}", $params);
$total = (int)($totalResult['count'] ?? 0);
$totalPages = ceil($total / $perPage);

// Get employees
$employees = $db->fetchAll(
    "SELECT e.*, c.name as company_name, c.slug as company_slug,
            (SELECT COUNT(*) FROM generated_cards WHERE employee_id = e.id) as card_count
     FROM employees e 
     LEFT JOIN companies c ON e.company_id = c.id
     {$whereClause} 
     ORDER BY e.created_at DESC 
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

// Get companies for dropdown
$companies = $db->fetchAll("SELECT id, name FROM companies ORDER BY name");

// Get selected company name for breadcrumb
$selectedCompanyName = '';
if ($companyFilter) {
    $selectedCompany = $db->fetchOne("SELECT name FROM companies WHERE id = :id", ['id' => $companyFilter]);
    $selectedCompanyName = $selectedCompany['name'] ?? '';
}

adminHeader('Employees Management' . ($selectedCompanyName ? " - {$selectedCompanyName}" : ''), 'employees');
?>

<?php if ($message): ?>
<div class="mb-4 p-4 rounded-lg <?php echo $messageType === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
    <?php echo sanitize($message); ?>
</div>
<?php endif; ?>

<!-- This is the global fleet directory. Card design + generation + printing
     live in each company's own hub; use "Open hub" to jump there. -->
<div class="mb-6 rounded-lg border border-blue-100 bg-blue-50 p-3.5 flex items-start gap-2.5 text-sm text-blue-800">
    <i class="fa-solid fa-circle-info mt-0.5 text-blue-500"></i>
    <span>This is the cross-company directory for oversight (who is active, who has cards, who has a password). To design, generate or print a card, open that person's company hub.</span>
</div>

<!-- Breadcrumb if filtering by company -->
<?php if ($companyFilter && $selectedCompanyName): ?>
<div class="mb-4">
    <nav class="flex" aria-label="Breadcrumb">
        <ol class="inline-flex items-center space-x-1 md:space-x-3">
            <li><a href="companies.php" class="text-blue-600 hover:text-blue-800">Companies</a></li>
            <li class="flex items-center"><i class="fa-solid fa-chevron-right mx-2 text-gray-400 text-xs"></i>
                <span class="text-gray-500"><?php echo sanitize($selectedCompanyName); ?> Employees</span>
            </li>
        </ol>
    </nav>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="bg-white rounded-lg shadow p-4 mb-6">
    <form method="GET" class="flex flex-col lg:flex-row gap-4">
        <input type="text" name="search" value="<?php echo sanitize($search); ?>" 
               placeholder="Search by name, email, or position..." 
               class="flex-1 px-4 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
        <select name="company_id" class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
            <option value="">All Companies</option>
            <?php foreach ($companies as $c): ?>
            <option value="<?php echo htmlspecialchars((string)$c['id'], ENT_QUOTES); ?>" <?php echo $companyFilter === (string)$c['id'] ? 'selected' : ''; ?>>
                <?php echo sanitize($c['name']); ?>
            </option>
            <?php endforeach; ?>
        </select>
        <select name="status" class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
            <option value="">All Status</option>
            <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
            <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
        </select>
        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
            <i class="fa-solid fa-search mr-2"></i>Filter
        </button>
        <?php if ($search || $companyFilter || $statusFilter): ?>
        <a href="employees.php" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 text-center">Clear</a>
        <?php endif; ?>
    </form>
</div>

<!-- Stats -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-lg shadow p-4">
        <div class="text-sm text-gray-500">Total Employees</div>
        <div class="text-2xl font-semibold"><?php echo $total; ?></div>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <div class="text-sm text-gray-500">Active</div>
        <div class="text-2xl font-semibold text-green-600">
            <?php 
            $activeQuery = "SELECT COUNT(*) as c FROM employees e {$whereClause}" . ($whereClause ? " AND" : " WHERE") . " e.status = 'active'";
            echo $db->fetchOne($activeQuery, $params)['c'] ?? 0; 
            ?>
        </div>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <div class="text-sm text-gray-500">With Cards</div>
        <div class="text-2xl font-semibold text-blue-600">
            <?php 
            $withCardsQuery = "SELECT COUNT(DISTINCT e.id) as c FROM employees e
                              INNER JOIN generated_cards gc ON gc.employee_id = e.id
                              " . ($companyFilter !== '' ? "WHERE e.company_id = :company_id" : "");
            echo $db->fetchOne($withCardsQuery, $companyFilter !== '' ? ['company_id' => $companyFilter] : [])['c'] ?? 0;
            ?>
        </div>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <div class="text-sm text-gray-500">With Password</div>
        <div class="text-2xl font-semibold text-purple-600">
            <?php 
            $withPwdQuery = "SELECT COUNT(*) as c FROM employees e {$whereClause}" . ($whereClause ? " AND" : " WHERE") . " e.password_hash IS NOT NULL AND e.password_hash != ''";
            echo $db->fetchOne($withPwdQuery, $params)['c'] ?? 0; 
            ?>
        </div>
    </div>
</div>

<!-- Employees Table -->
<div class="bg-white rounded-lg shadow overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left text-gray-500">
            <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                <tr>
                    <th class="px-6 py-3">Employee</th>
                    <th class="px-6 py-3">Company</th>
                    <th class="px-6 py-3">Email</th>
                    <th class="px-6 py-3">Position</th>
                    <th class="px-6 py-3">Status</th>
                    <th class="px-6 py-3">Cards</th>
                    <th class="px-6 py-3">Has Password</th>
                    <th class="px-6 py-3">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($employees)): ?>
                <tr><td colspan="8" class="px-6 py-8 text-center text-gray-500">No employees found</td></tr>
                <?php else: ?>
                <?php foreach ($employees as $emp): ?>
                <tr class="bg-white border-b hover:bg-gray-50">
                    <td class="px-6 py-4">
                        <div class="font-medium text-gray-900"><?php echo sanitize($emp['name_en'] ?? $emp['email']); ?></div>
                        <?php if ($emp['name_ar']): ?>
                        <div class="text-xs text-gray-500" dir="rtl"><?php echo sanitize($emp['name_ar']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4">
                        <a href="?company_id=<?php echo $emp['company_id']; ?>" class="text-blue-600 hover:underline">
                            <?php echo sanitize($emp['company_name'] ?? 'N/A'); ?>
                        </a>
                    </td>
                    <td class="px-6 py-4"><?php echo sanitize($emp['email']); ?></td>
                    <td class="px-6 py-4"><?php echo sanitize($emp['position_en'] ?? 'N/A'); ?></td>
                    <td class="px-6 py-4">
                        <span class="px-2 py-1 rounded-full text-xs font-semibold 
                            <?php echo ($emp['status'] ?? 'active') === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-700'; ?>">
                            <?php echo sanitize(ucfirst($emp['status'] ?? 'active')); ?>
                        </span>
                    </td>
                    <td class="px-6 py-4"><?php echo $emp['card_count']; ?></td>
                    <td class="px-6 py-4">
                        <?php if (!empty($emp['password_hash'])): ?>
                        <span class="text-green-600"><i class="fa-solid fa-check-circle"></i></span>
                        <?php else: ?>
                        <span class="text-gray-400"><i class="fa-solid fa-times-circle"></i></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-2">
                            <?php if ($emp['company_slug']): ?>
                            <!-- Primary: jump into this person's company hub where
                                 cards are designed / generated / printed. -->
                            <a href="<?php echo htmlspecialchars(getTenantUrl($emp['company_slug'], '/admin/employees'), ENT_QUOTES); ?>" target="_blank" rel="noopener"
                               class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-medium bg-blue-50 text-blue-700 hover:bg-blue-100 transition-colors" title="Open in company hub">
                                <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>Open hub
                            </a>
                            <a href="<?php echo htmlspecialchars(getTenantUrl($emp['company_slug'], '/card/' . $emp['id']), ENT_QUOTES); ?>" target="_blank" rel="noopener"
                               class="text-green-600 hover:text-green-800" title="View Card">
                                <i class="fa-solid fa-id-card"></i>
                            </a>
                            <?php endif; ?>
                            <button onclick='openEditModal(<?php echo htmlspecialchars(json_encode($emp), ENT_QUOTES); ?>)'
                                    class="text-blue-600 hover:text-blue-800" title="Edit">
                                <i class="fa-solid fa-edit"></i>
                            </button>
                            <button onclick="openPasswordModal(<?php echo htmlspecialchars(json_encode((string)$emp['id']), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode($emp['name_en'] ?? $emp['email']), ENT_QUOTES); ?>)"
                                    class="text-purple-600 hover:text-purple-800" title="Reset Password">
                                <i class="fa-solid fa-key"></i>
                            </button>
                            <button onclick="confirmDelete(<?php echo htmlspecialchars(json_encode((string)$emp['id']), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode($emp['name_en'] ?? $emp['email']), ENT_QUOTES); ?>)"
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
                Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $perPage, $total); ?> of <?php echo $total; ?> employees
            </div>
            <div class="flex gap-2">
                <?php 
                $queryParams = http_build_query(array_filter([
                    'search' => $search,
                    'company_id' => $companyFilter,
                    'status' => $statusFilter
                ]));
                ?>
                <?php if ($page > 1): ?>
                <a href="?page=<?php echo $page - 1; ?>&<?php echo $queryParams; ?>" 
                   class="px-3 py-1 border rounded hover:bg-gray-50">Previous</a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                <a href="?page=<?php echo $page + 1; ?>&<?php echo $queryParams; ?>" 
                   class="px-3 py-1 border rounded hover:bg-gray-50">Next</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Edit Modal -->
<div id="editModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b">
            <h3 class="text-lg font-semibold">Edit Employee</h3>
        </div>
        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="update_employee">
            <input type="hidden" name="employee_id" id="employeeId">
            <div class="p-6 space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Name (English)</label>
                        <input type="text" name="name_en" id="empNameEn" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Name (Arabic)</label>
                        <input type="text" name="name_ar" id="empNameAr" dir="rtl"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" name="email" id="empEmail" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Position (English)</label>
                        <input type="text" name="position_en" id="empPositionEn"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Position (Arabic)</label>
                        <input type="text" name="position_ar" id="empPositionAr" dir="rtl"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                        <input type="text" name="phone" id="empPhone"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Mobile</label>
                        <input type="text" name="mobile" id="empMobile"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                        <select name="status" id="empStatus" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
                        <input type="password" name="new_password" id="empPassword"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                        <p class="text-xs text-gray-500 mt-1">Leave blank to keep current</p>
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

<!-- Password Reset Modal -->
<div id="passwordModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="p-6 border-b">
            <h3 class="text-lg font-semibold">Reset Password</h3>
            <p class="text-sm text-gray-500 mt-1">Set a new password for <strong id="pwdEmployeeName"></strong></p>
        </div>
        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="employee_id" id="pwdEmployeeId">
            <div class="p-6">
                <label class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
                <input type="password" name="new_password" required minlength="6"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                <p class="text-xs text-gray-500 mt-1">Minimum 6 characters</p>
            </div>
            <div class="p-6 border-t bg-gray-50 flex justify-end gap-3">
                <button type="button" onclick="closePasswordModal()" class="px-4 py-2 border rounded-lg hover:bg-gray-100">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">Reset Password</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="p-6">
            <h3 class="text-lg font-semibold text-red-600 mb-2">Delete Employee</h3>
            <p class="text-gray-600">Are you sure you want to delete <strong id="deleteEmpName"></strong>? This will also delete all their generated cards. This action cannot be undone.</p>
        </div>
        <form method="POST" class="p-6 border-t bg-gray-50 flex justify-end gap-3">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="delete_employee">
            <input type="hidden" name="employee_id" id="deleteEmpId">
            <button type="button" onclick="closeDeleteModal()" class="px-4 py-2 border rounded-lg hover:bg-gray-100">Cancel</button>
            <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">Delete Employee</button>
        </form>
    </div>
</div>

<script>
function openEditModal(emp) {
    document.getElementById('employeeId').value = emp.id;
    document.getElementById('empNameEn').value = emp.name_en || '';
    document.getElementById('empNameAr').value = emp.name_ar || '';
    document.getElementById('empEmail').value = emp.email || '';
    document.getElementById('empPositionEn').value = emp.position_en || '';
    document.getElementById('empPositionAr').value = emp.position_ar || '';
    document.getElementById('empPhone').value = emp.phone || '';
    document.getElementById('empMobile').value = emp.mobile || '';
    document.getElementById('empStatus').value = emp.status || 'active';
    document.getElementById('empPassword').value = '';
    document.getElementById('editModal').classList.remove('hidden');
}

function closeModal() {
    document.getElementById('editModal').classList.add('hidden');
}

function openPasswordModal(id, name) {
    document.getElementById('pwdEmployeeId').value = id;
    document.getElementById('pwdEmployeeName').textContent = name;
    document.getElementById('passwordModal').classList.remove('hidden');
}

function closePasswordModal() {
    document.getElementById('passwordModal').classList.add('hidden');
}

function confirmDelete(id, name) {
    document.getElementById('deleteEmpId').value = id;
    document.getElementById('deleteEmpName').textContent = name;
    document.getElementById('deleteModal').classList.remove('hidden');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.add('hidden');
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal();
        closePasswordModal();
        closeDeleteModal();
    }
});
</script>

<?php adminFooter(); ?>
