<?php
/**
 * Employee Management - Cardify
 */
require_once __DIR__ . '/../config.php';
requireAdmin();
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';

$db = Database::getInstance();
$companyId = getCurrentCompanyId();

// Get departments for dropdown
$departments = [];
if (DatabaseAdapter::useDatabase() && $companyId) {
    $departments = $db->fetchAll(
        "SELECT * FROM departments WHERE company_id = :id ORDER BY name",
        ['id' => $companyId]
    );
}

$employees = loadEmployees();
$message = null;
$messageType = 'success';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'add':
            $result = addEmployee($_POST);
            if ($result['success']) {
                $message = 'Employee added successfully';
                $employees = loadEmployees();
            } else {
                $message = $result['error'];
                $messageType = 'error';
            }
            break;
            
        case 'update':
            $result = updateEmployee($_POST['id'], $_POST);
            if ($result['success']) {
                $message = 'Employee updated successfully';
                $employees = loadEmployees();
            } else {
                $message = $result['error'];
                $messageType = 'error';
            }
            break;
            
        case 'delete':
            $result = deleteEmployee($_POST['id']);
            if ($result['success']) {
                $message = 'Employee deleted successfully';
                $employees = loadEmployees();
            } else {
                $message = $result['error'];
                $messageType = 'error';
            }
            break;
            
        case 'import':
            $result = importFromExcel($_FILES['excel_file'] ?? null);
            if ($result['success']) {
                $message = "Imported {$result['count']} employees successfully";
                $employees = loadEmployees();
            } else {
                $message = $result['error'];
                $messageType = 'error';
            }
            break;
    }
}

/**
 * Import employees from Excel/CSV file
 */
function importFromExcel($file) {
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'No file uploaded or upload error'];
    }
    
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if ($ext === 'csv') {
        return importFromCSV($file['tmp_name']);
    } elseif (in_array($ext, ['xlsx', 'xls'])) {
        $autoloadPath = BASE_DIR . '/vendor/autoload.php';
        if (file_exists($autoloadPath)) {
            require_once $autoloadPath;
            return importFromXLSX($file['tmp_name']);
        } else {
            $savedPath = EXCEL_DIR . '/' . uniqid('import_') . '.' . $ext;
            move_uploaded_file($file['tmp_name'], $savedPath);
            return ['success' => false, 'error' => 'Excel support requires PhpSpreadsheet. Please use CSV format.'];
        }
    }
    
    return ['success' => false, 'error' => 'Unsupported file format. Use CSV or XLSX.'];
}

function importFromCSV($filepath) {
    $handle = fopen($filepath, 'r');
    if (!$handle) return ['success' => false, 'error' => 'Could not read file'];
    
    $header = fgetcsv($handle);
    if (!$header) { fclose($handle); return ['success' => false, 'error' => 'Empty file']; }
    
    $header = array_map(fn($h) => strtolower(trim(str_replace([' ', '-'], '_', $h))), $header);
    
    $columnMap = [
        'email' => array_search('email', $header),
        'name_en' => findColumn($header, ['name_en', 'name_english', 'english_name', 'name']),
        'name_ar' => findColumn($header, ['name_ar', 'name_arabic', 'arabic_name']),
        'position_en' => findColumn($header, ['position_en', 'position_english', 'title_en', 'position', 'title']),
        'position_ar' => findColumn($header, ['position_ar', 'position_arabic', 'title_ar']),
        'phone' => findColumn($header, ['phone', 'telephone', 'tel']),
        'mobile' => findColumn($header, ['mobile', 'cell', 'cellphone']),
        'company_en' => findColumn($header, ['company_en', 'company_english', 'company']),
        'company_ar' => findColumn($header, ['company_ar', 'company_arabic']),
        'website' => findColumn($header, ['website', 'web', 'url']),
        'address' => findColumn($header, ['address', 'location'])
    ];
    
    if ($columnMap['email'] === false) { fclose($handle); return ['success' => false, 'error' => 'Email column not found']; }
    
    $imported = 0;
    while (($row = fgetcsv($handle)) !== false) {
        $data = [];
        foreach ($columnMap as $field => $index) {
            $data[$field] = ($index !== false && isset($row[$index])) ? trim($row[$index]) : '';
        }
        if (empty($data['email'])) continue;
        
        $existing = findEmployeeByEmail($data['email']);
        if ($existing) { updateEmployee($existing['id'], $data); }
        else { addEmployee($data); }
        $imported++;
    }
    fclose($handle);
    return ['success' => true, 'count' => $imported];
}

function importFromXLSX($filepath) {
    try {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filepath);
        $rows = $spreadsheet->getActiveSheet()->toArray();
        if (empty($rows)) return ['success' => false, 'error' => 'Empty file'];
        
        $header = array_map(fn($h) => strtolower(trim(str_replace([' ', '-'], '_', $h ?? ''))), $rows[0]);
        $columnMap = [
            'email' => array_search('email', $header),
            'name_en' => findColumn($header, ['name_en', 'name_english', 'english_name', 'name']),
            'name_ar' => findColumn($header, ['name_ar', 'name_arabic', 'arabic_name']),
            'position_en' => findColumn($header, ['position_en', 'position_english', 'title_en', 'position', 'title']),
            'position_ar' => findColumn($header, ['position_ar', 'position_arabic', 'title_ar']),
            'phone' => findColumn($header, ['phone', 'telephone', 'tel']),
            'mobile' => findColumn($header, ['mobile', 'cell', 'cellphone']),
            'company_en' => findColumn($header, ['company_en', 'company_english', 'company']),
            'company_ar' => findColumn($header, ['company_ar', 'company_arabic']),
            'website' => findColumn($header, ['website', 'web', 'url']),
            'address' => findColumn($header, ['address', 'location'])
        ];
        
        if ($columnMap['email'] === false) return ['success' => false, 'error' => 'Email column not found'];
        
        $imported = 0;
        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            $data = [];
            foreach ($columnMap as $field => $index) {
                $data[$field] = ($index !== false && isset($row[$index])) ? trim($row[$index] ?? '') : '';
            }
            if (empty($data['email'])) continue;
            
            $existing = findEmployeeByEmail($data['email']);
            if ($existing) { updateEmployee($existing['id'], $data); }
            else { addEmployee($data); }
            $imported++;
        }
        return ['success' => true, 'count' => $imported];
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Error reading Excel file: ' . $e->getMessage()];
    }
}

function findColumn($header, $possibleNames) {
    foreach ($possibleNames as $name) {
        $index = array_search($name, $header);
        if ($index !== false) return $index;
    }
    return false;
}

// Start admin layout
adminHeader('Employees', 'employees');
?>

<div x-data="employeeManager()">
    <!-- Page Header Actions -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <p class="text-gray-600"><?php echo count($employees); ?> team members</p>
        </div>
        <div class="flex items-center gap-3">
            <button @click="showImportModal = true" class="px-4 py-2 bg-green-50 text-green-700 border border-green-200 rounded-lg hover:bg-green-100 transition-colors text-sm font-medium flex items-center gap-2">
                <i class="fa-solid fa-file-import"></i>
                <span>Import CSV</span>
            </button>
            <button @click="openAddModal()" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors text-sm font-medium flex items-center gap-2">
                <i class="fa-solid fa-plus"></i>
                <span>Add Employee</span>
            </button>
        </div>
    </div>

    <!-- Alert Message -->
    <?php if ($message): ?>
    <div class="mb-6 p-4 rounded-xl flex items-center gap-3 <?php echo $messageType === 'success' ? 'bg-green-50 border border-green-200 text-green-700' : 'bg-red-50 border border-red-200 text-red-700'; ?>">
        <i class="<?php echo $messageType === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-exclamation'; ?>"></i>
        <?php echo sanitize($message); ?>
    </div>
    <?php endif; ?>

    <!-- Search & Filter Bar -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-6">
        <div class="p-4 flex flex-col sm:flex-row gap-4">
            <div class="flex-1 relative">
                <i class="fa-solid fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                <input 
                    type="text" 
                    x-model="searchQuery"
                    placeholder="Search by name or email..."
                    class="w-full pl-10 pr-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 placeholder-gray-400 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 transition-all"
                >
            </div>
            <?php if (!empty($departments)): ?>
            <select x-model="filterDepartment" class="px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                <option value="">All Departments</option>
                <?php foreach ($departments as $dept): ?>
                <option value="<?php echo sanitize($dept['id']); ?>"><?php echo sanitize($dept['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
        </div>
    </div>

    <!-- Employees Table -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-6 py-4 text-gray-600 font-semibold text-sm">Employee</th>
                        <th class="text-left px-6 py-4 text-gray-600 font-semibold text-sm">Position</th>
                        <th class="text-left px-6 py-4 text-gray-600 font-semibold text-sm hidden md:table-cell">Department</th>
                        <th class="text-left px-6 py-4 text-gray-600 font-semibold text-sm hidden lg:table-cell">Contact</th>
                        <th class="text-right px-6 py-4 text-gray-600 font-semibold text-sm">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($employees as $emp): ?>
                    <?php 
                    $deptName = '-';
                    if (!empty($emp['department_id']) && DatabaseAdapter::useDatabase()) {
                        $dept = $db->fetchOne("SELECT name FROM departments WHERE id = :id", ['id' => $emp['department_id']]);
                        $deptName = $dept ? sanitize($dept['name']) : '-';
                    }
                    ?>
                    <tr 
                        class="hover:bg-gray-50 transition-colors"
                        x-show="matchesSearch('<?php echo addslashes($emp['email'] ?? ''); ?>', '<?php echo addslashes($emp['name_en'] ?? ''); ?>', '<?php echo addslashes($emp['name_ar'] ?? ''); ?>', '<?php echo addslashes($emp['department_id'] ?? ''); ?>')"
                    >
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center text-white font-semibold text-sm">
                                    <?php echo strtoupper(substr($emp['name_en'] ?? 'E', 0, 2)); ?>
                                </div>
                                <div>
                                    <p class="font-semibold text-gray-900"><?php echo sanitize($emp['name_en'] ?? ''); ?></p>
                                    <p class="text-gray-500 text-sm"><?php echo sanitize($emp['email'] ?? ''); ?></p>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <p class="text-gray-900"><?php echo sanitize($emp['position_en'] ?? '-'); ?></p>
                            <?php if (!empty($emp['position_ar'])): ?>
                            <p class="text-gray-500 text-sm" dir="rtl"><?php echo sanitize($emp['position_ar']); ?></p>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 hidden md:table-cell">
                            <?php if ($deptName !== '-'): ?>
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-blue-50 text-blue-700">
                                <?php echo $deptName; ?>
                            </span>
                            <?php else: ?>
                            <span class="text-gray-400">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 hidden lg:table-cell">
                            <div class="text-sm">
                                <?php if (!empty($emp['phone'])): ?>
                                <p class="text-gray-900"><?php echo sanitize($emp['phone']); ?></p>
                                <?php endif; ?>
                                <?php if (!empty($emp['mobile'])): ?>
                                <p class="text-gray-500"><?php echo sanitize($emp['mobile']); ?></p>
                                <?php endif; ?>
                                <?php if (empty($emp['phone']) && empty($emp['mobile'])): ?>
                                <span class="text-gray-400">-</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <button 
                                    @click='openEditModal(<?php echo json_encode($emp); ?>)'
                                    class="p-2 text-gray-500 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
                                    title="Edit"
                                >
                                    <i class="fa-solid fa-pen-to-square"></i>
                                </button>
                                <form method="post" class="inline" onsubmit="return confirm('Are you sure you want to delete this employee?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo sanitize($emp['id']); ?>">
                                    <button type="submit" class="p-2 text-gray-500 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Delete">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($employees)): ?>
                    <tr>
                        <td colspan="5" class="px-6 py-16 text-center">
                            <div class="text-gray-400">
                                <i class="fa-solid fa-users text-4xl mb-4 opacity-50"></i>
                                <p class="text-gray-600 font-medium">No employees yet</p>
                                <p class="text-sm mt-1">Add employees manually or import from a CSV file</p>
                                <button @click="openAddModal()" class="mt-4 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium">
                                    <i class="fa-solid fa-plus mr-2"></i>Add First Employee
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add/Edit Modal -->
    <div x-show="showModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" @keydown.escape.window="showModal = false">
        <div class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" @click="showModal = false"></div>
        <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
            <div class="p-6 border-b border-gray-100">
                <h3 class="text-xl font-bold text-gray-900" x-text="editingEmployee ? 'Edit Employee' : 'Add New Employee'"></h3>
            </div>
            
            <form method="post" class="p-6">
                <input type="hidden" name="action" :value="editingEmployee ? 'update' : 'add'">
                <input type="hidden" name="id" x-model="formData.id">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Email <span class="text-red-500">*</span></label>
                        <input type="email" name="email" x-model="formData.email" required 
                               class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    </div>
                    
                    <?php if (!empty($departments)): ?>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Department</label>
                        <select name="department_id" x-model="formData.department_id" 
                                class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                            <option value="">Select department</option>
                            <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo sanitize($dept['id']); ?>"><?php echo sanitize($dept['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Name (English) <span class="text-red-500">*</span></label>
                        <input type="text" name="name_en" x-model="formData.name_en" required 
                               class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Name (Arabic)</label>
                        <input type="text" name="name_ar" x-model="formData.name_ar" dir="rtl" 
                               class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Position (English)</label>
                        <input type="text" name="position_en" x-model="formData.position_en" 
                               class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Position (Arabic)</label>
                        <input type="text" name="position_ar" x-model="formData.position_ar" dir="rtl" 
                               class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Phone</label>
                        <input type="text" name="phone" x-model="formData.phone" 
                               class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Mobile</label>
                        <input type="text" name="mobile" x-model="formData.mobile" 
                               class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Company (English)</label>
                        <input type="text" name="company_en" x-model="formData.company_en" 
                               class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Company (Arabic)</label>
                        <input type="text" name="company_ar" x-model="formData.company_ar" dir="rtl" 
                               class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Website</label>
                        <input type="text" name="website" x-model="formData.website" placeholder="www.example.com"
                               class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Address</label>
                        <input type="text" name="address" x-model="formData.address" 
                               class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    </div>
                </div>
                
                <div class="flex items-center justify-end gap-3 mt-6 pt-6 border-t border-gray-100">
                    <button type="button" @click="showModal = false" class="px-4 py-2 text-gray-600 hover:text-gray-900 font-medium transition-colors">
                        Cancel
                    </button>
                    <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors">
                        <span x-text="editingEmployee ? 'Save Changes' : 'Add Employee'"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Import Modal -->
    <div x-show="showImportModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" @keydown.escape.window="showImportModal = false">
        <div class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" @click="showImportModal = false"></div>
        <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-md">
            <div class="p-6 border-b border-gray-100">
                <h3 class="text-xl font-bold text-gray-900">Import from CSV/Excel</h3>
            </div>
            
            <form method="post" enctype="multipart/form-data" class="p-6">
                <input type="hidden" name="action" value="import">
                
                <div class="mb-6">
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Select File</label>
                    <input type="file" name="excel_file" accept=".csv,.xlsx,.xls" required 
                           class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-blue-50 file:text-blue-700 file:font-medium hover:file:bg-blue-100">
                </div>
                
                <div class="p-4 rounded-xl bg-amber-50 border border-amber-200 mb-6">
                    <h4 class="text-amber-800 font-semibold mb-2 flex items-center gap-2">
                        <i class="fa-solid fa-lightbulb"></i>
                        Expected Columns
                    </h4>
                    <p class="text-amber-700 text-sm mb-2">Your file should have these column headers:</p>
                    <p class="text-amber-600 text-xs font-mono bg-amber-100 p-2 rounded">email, name_en, name_ar, position_en, position_ar, phone, mobile, company_en, company_ar, website, address</p>
                </div>
                
                <div class="flex items-center justify-end gap-3">
                    <button type="button" @click="showImportModal = false" class="px-4 py-2 text-gray-600 hover:text-gray-900 font-medium transition-colors">
                        Cancel
                    </button>
                    <button type="submit" class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium transition-colors">
                        <i class="fa-solid fa-file-import mr-2"></i>Import
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function employeeManager() {
    return {
        searchQuery: '',
        filterDepartment: '',
        showModal: false,
        showImportModal: false,
        editingEmployee: false,
        formData: {
            id: '', email: '', department_id: '', name_en: '', name_ar: '',
            position_en: '', position_ar: '', phone: '', mobile: '',
            company_en: '', company_ar: '', website: '', address: ''
        },
        
        matchesSearch(email, nameEn, nameAr, deptId) {
            const searchMatch = !this.searchQuery || 
                email.toLowerCase().includes(this.searchQuery.toLowerCase()) || 
                nameEn.toLowerCase().includes(this.searchQuery.toLowerCase()) || 
                nameAr.toLowerCase().includes(this.searchQuery.toLowerCase());
            const deptMatch = !this.filterDepartment || deptId === this.filterDepartment;
            return searchMatch && deptMatch;
        },
        
        openAddModal() {
            this.editingEmployee = false;
            this.formData = {
                id: '', email: '', department_id: '', name_en: '', name_ar: '',
                position_en: '', position_ar: '', phone: '', mobile: '',
                company_en: '', company_ar: '', website: '', address: ''
            };
            this.showModal = true;
        },
        
        openEditModal(employee) {
            this.editingEmployee = true;
            this.formData = { ...employee };
            this.showModal = true;
        }
    };
}
</script>

<?php adminFooter(); ?>
