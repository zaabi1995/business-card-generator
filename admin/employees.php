<?php
/**
 * Employee Management - BHD Business Cards
 */
require_once __DIR__ . '/../config.php';
requireAdmin();

$employees = loadEmployees();
$message = null;
$messageType = 'success';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
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
        // Check if PhpSpreadsheet is available
        $autoloadPath = BASE_DIR . '/vendor/autoload.php';
        if (file_exists($autoloadPath)) {
            require_once $autoloadPath;
            return importFromXLSX($file['tmp_name']);
        } else {
            // Fallback: save file and provide instructions
            $savedPath = EXCEL_DIR . '/' . uniqid('import_') . '.' . $ext;
            move_uploaded_file($file['tmp_name'], $savedPath);
            return ['success' => false, 'error' => 'Excel support requires PhpSpreadsheet. Please use CSV format or install: composer require phpoffice/phpspreadsheet'];
        }
    }
    
    return ['success' => false, 'error' => 'Unsupported file format. Use CSV or XLSX.'];
}

function importFromCSV($filepath) {
    $handle = fopen($filepath, 'r');
    if (!$handle) {
        return ['success' => false, 'error' => 'Could not read file'];
    }
    
    // Read header row
    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        return ['success' => false, 'error' => 'Empty file'];
    }
    
    // Normalize header names
    $header = array_map(function($h) {
        return strtolower(trim(str_replace([' ', '-'], '_', $h)));
    }, $header);
    
    // Map columns
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
    
    if ($columnMap['email'] === false) {
        fclose($handle);
        return ['success' => false, 'error' => 'Email column not found'];
    }
    
    $imported = 0;
    $skipped = 0;
    
    while (($row = fgetcsv($handle)) !== false) {
        $data = [];
        foreach ($columnMap as $field => $index) {
            $data[$field] = ($index !== false && isset($row[$index])) ? trim($row[$index]) : '';
        }
        
        if (empty($data['email'])) {
            $skipped++;
            continue;
        }
        
        // Check if employee exists
        $existing = findEmployeeByEmail($data['email']);
        if ($existing) {
            // Update existing
            updateEmployee($existing['id'], $data);
        } else {
            // Add new
            addEmployee($data);
        }
        $imported++;
    }
    
    fclose($handle);
    
    return ['success' => true, 'count' => $imported, 'skipped' => $skipped];
}

function importFromXLSX($filepath) {
    try {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filepath);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();
        
        if (empty($rows)) {
            return ['success' => false, 'error' => 'Empty file'];
        }
        
        // First row is header
        $header = array_map(function($h) {
            return strtolower(trim(str_replace([' ', '-'], '_', $h ?? '')));
        }, $rows[0]);
        
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
        
        if ($columnMap['email'] === false) {
            return ['success' => false, 'error' => 'Email column not found'];
        }
        
        $imported = 0;
        
        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            $data = [];
            foreach ($columnMap as $field => $index) {
                $data[$field] = ($index !== false && isset($row[$index])) ? trim($row[$index] ?? '') : '';
            }
            
            if (empty($data['email'])) continue;
            
            $existing = findEmployeeByEmail($data['email']);
            if ($existing) {
                updateEmployee($existing['id'], $data);
            } else {
                addEmployee($data);
            }
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
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employees | <?php echo SITE_NAME; ?></title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="<?php echo assetUrl('css/tailwind.css'); ?>?v=<?php echo filemtime(ASSETS_DIR . '/css/tailwind.css'); ?>">
    
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    
    <style>
        [x-cloak] { display: none !important; }
        .glass-card { background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.1); }
        .input-bhd { background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.15); transition: all 0.3s ease; }
        .input-bhd:focus { background: rgba(255, 255, 255, 0.08); border-color: rgba(212, 175, 55, 0.6); box-shadow: 0 0 0 3px rgba(212, 175, 55, 0.1); }
        .btn-bhd { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%); transition: all 0.3s ease; border: 1px solid rgba(212, 175, 55, 0.3); }
        .btn-bhd:hover { box-shadow: 0 0 20px rgba(212, 175, 55, 0.2); border-color: rgba(212, 175, 55, 0.5); }
    </style>
</head>
<body class="bg-alzayani-dark text-white font-sans min-h-screen antialiased">
    <div class="min-h-screen" x-data="employeeManager()">
        <!-- Header -->
        <header class="glass-card border-b border-white/10">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-4">
                        <a href="index.php" class="text-gray-400 hover:text-white transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                            </svg>
                        </a>
                        <div>
                            <h1 class="text-xl font-bold text-white">Employee Management</h1>
                            <p class="text-gray-500 text-xs"><?php echo count($employees); ?> employees</p>
                        </div>
                    </div>
                    
                    <div class="flex items-center space-x-3">
                        <button @click="showImportModal = true" class="px-4 py-2 bg-green-500/20 text-green-400 rounded-lg hover:bg-green-500/30 transition-colors text-sm flex items-center space-x-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                            </svg>
                            <span>Import Excel/CSV</span>
                        </button>
                        <button @click="openAddModal()" class="btn-bhd px-4 py-2 rounded-lg text-sm flex items-center space-x-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                            </svg>
                            <span>Add Employee</span>
                        </button>
                    </div>
                </div>
            </div>
        </header>
        
        <!-- Main Content -->
        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-xl <?php echo $messageType === 'success' ? 'bg-green-500/10 border border-green-500/30 text-green-400' : 'bg-red-500/10 border border-red-500/30 text-red-400'; ?>">
                <?php echo sanitize($message); ?>
            </div>
            <?php endif; ?>
            
            <!-- Search -->
            <div class="mb-6">
                <input 
                    type="text" 
                    x-model="searchQuery"
                    placeholder="Search employees..."
                    class="input-bhd w-full max-w-md px-4 py-3 rounded-xl text-white"
                >
            </div>
            
            <!-- Employees Table -->
            <div class="glass-card rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-white/10">
                                <th class="text-left p-4 text-gray-400 font-medium text-sm">Name</th>
                                <th class="text-left p-4 text-gray-400 font-medium text-sm">Position</th>
                                <th class="text-left p-4 text-gray-400 font-medium text-sm">Email</th>
                                <th class="text-left p-4 text-gray-400 font-medium text-sm">Phone</th>
                                <th class="text-left p-4 text-gray-400 font-medium text-sm">Company</th>
                                <th class="text-right p-4 text-gray-400 font-medium text-sm">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($employees as $emp): ?>
                            <tr 
                                class="border-b border-white/5 hover:bg-white/5 transition-colors"
                                x-show="matchesSearch('<?php echo addslashes($emp['email'] ?? ''); ?>', '<?php echo addslashes($emp['name_en'] ?? ''); ?>', '<?php echo addslashes($emp['name_ar'] ?? ''); ?>')"
                            >
                                <td class="p-4">
                                    <div>
                                        <p class="text-white font-medium"><?php echo sanitize($emp['name_en'] ?? ''); ?></p>
                                        <p class="text-gray-500 text-sm" dir="rtl"><?php echo sanitize($emp['name_ar'] ?? ''); ?></p>
                                    </div>
                                </td>
                                <td class="p-4">
                                    <div>
                                        <p class="text-gray-300 text-sm"><?php echo sanitize($emp['position_en'] ?? ''); ?></p>
                                        <p class="text-gray-500 text-xs" dir="rtl"><?php echo sanitize($emp['position_ar'] ?? ''); ?></p>
                                    </div>
                                </td>
                                <td class="p-4 text-gray-300 text-sm"><?php echo sanitize($emp['email'] ?? ''); ?></td>
                                <td class="p-4">
                                    <div class="text-gray-300 text-sm">
                                        <?php if (!empty($emp['phone'])): ?>
                                        <p><?php echo sanitize($emp['phone']); ?></p>
                                        <?php endif; ?>
                                        <?php if (!empty($emp['mobile'])): ?>
                                        <p class="text-gray-500"><?php echo sanitize($emp['mobile']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="p-4 text-gray-300 text-sm"><?php echo sanitize($emp['company_en'] ?? ''); ?></td>
                                <td class="p-4 text-right">
                                    <div class="flex items-center justify-end space-x-2">
                                        <button 
                                            @click='openEditModal(<?php echo json_encode($emp); ?>)'
                                            class="p-2 text-gray-500 hover:text-amber-400 transition-colors"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                            </svg>
                                        </button>
                                        <form method="post" class="inline" onsubmit="return confirm('Delete this employee?')">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo sanitize($emp['id']); ?>">
                                            <button type="submit" class="p-2 text-gray-500 hover:text-red-400 transition-colors">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                </svg>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            
                            <?php if (empty($employees)): ?>
                            <tr>
                                <td colspan="6" class="p-12 text-center text-gray-500">
                                    <svg class="w-12 h-12 mx-auto mb-4 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                    </svg>
                                    <p>No employees yet</p>
                                    <p class="text-sm mt-1">Add employees manually or import from Excel/CSV</p>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
        
        <!-- Add/Edit Modal -->
        <div x-show="showModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" @keydown.escape.window="showModal = false">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="showModal = false"></div>
            <div class="relative glass-card rounded-2xl p-6 w-full max-w-2xl max-h-[90vh] overflow-y-auto">
                <h3 class="text-xl font-bold text-white mb-4" x-text="editingEmployee ? 'Edit Employee' : 'Add Employee'"></h3>
                
                <form method="post">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" :value="editingEmployee ? 'update' : 'add'">
                    <input type="hidden" name="id" x-model="formData.id">
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Email *</label>
                            <input type="email" name="email" x-model="formData.email" required class="input-bhd w-full px-4 py-3 rounded-xl text-white">
                        </div>
                        
                        <div></div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Name (English) *</label>
                            <input type="text" name="name_en" x-model="formData.name_en" required class="input-bhd w-full px-4 py-3 rounded-xl text-white">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Name (Arabic)</label>
                            <input type="text" name="name_ar" x-model="formData.name_ar" dir="rtl" class="input-bhd w-full px-4 py-3 rounded-xl text-white">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Position (English)</label>
                            <input type="text" name="position_en" x-model="formData.position_en" class="input-bhd w-full px-4 py-3 rounded-xl text-white">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Position (Arabic)</label>
                            <input type="text" name="position_ar" x-model="formData.position_ar" dir="rtl" class="input-bhd w-full px-4 py-3 rounded-xl text-white">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Phone</label>
                            <input type="text" name="phone" x-model="formData.phone" class="input-bhd w-full px-4 py-3 rounded-xl text-white">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Mobile</label>
                            <input type="text" name="mobile" x-model="formData.mobile" class="input-bhd w-full px-4 py-3 rounded-xl text-white">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Company (English)</label>
                            <input type="text" name="company_en" x-model="formData.company_en" class="input-bhd w-full px-4 py-3 rounded-xl text-white">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Company (Arabic)</label>
                            <input type="text" name="company_ar" x-model="formData.company_ar" dir="rtl" class="input-bhd w-full px-4 py-3 rounded-xl text-white">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Website</label>
                            <input type="text" name="website" x-model="formData.website" class="input-bhd w-full px-4 py-3 rounded-xl text-white" placeholder="www.example.com">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Address</label>
                            <input type="text" name="address" x-model="formData.address" class="input-bhd w-full px-4 py-3 rounded-xl text-white">
                        </div>
                    </div>
                    
                    <div class="flex items-center justify-end space-x-3 mt-6">
                        <button type="button" @click="showModal = false" class="px-4 py-2 text-gray-400 hover:text-white transition-colors">
                            Cancel
                        </button>
                        <button type="submit" class="btn-bhd px-6 py-2 rounded-xl">
                            <span x-text="editingEmployee ? 'Save Changes' : 'Add Employee'"></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Import Modal -->
        <div x-show="showImportModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" @keydown.escape.window="showImportModal = false">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="showImportModal = false"></div>
            <div class="relative glass-card rounded-2xl p-6 w-full max-w-md">
                <h3 class="text-xl font-bold text-white mb-4">Import from Excel/CSV</h3>
                
                <form method="post" enctype="multipart/form-data">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="import">
                    
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-300 mb-2">Select File</label>
                        <input type="file" name="excel_file" accept=".csv,.xlsx,.xls" required class="input-bhd w-full px-4 py-3 rounded-xl text-white file:mr-4 file:py-1 file:px-3 file:rounded file:border-0 file:text-sm file:bg-white/10 file:text-gray-300">
                    </div>
                    
                    <div class="p-4 rounded-xl bg-amber-500/10 border border-amber-500/30 mb-6">
                        <h4 class="text-amber-400 font-medium mb-2">Expected Columns</h4>
                        <p class="text-gray-400 text-sm">Your file should have these column headers:</p>
                        <p class="text-gray-500 text-xs mt-2">email, name_en, name_ar, position_en, position_ar, phone, mobile, company_en, company_ar, website, address</p>
                    </div>
                    
                    <div class="flex items-center justify-end space-x-3">
                        <button type="button" @click="showImportModal = false" class="px-4 py-2 text-gray-400 hover:text-white transition-colors">
                            Cancel
                        </button>
                        <button type="submit" class="btn-bhd px-6 py-2 rounded-xl">
                            Import
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
                showModal: false,
                showImportModal: false,
                editingEmployee: false,
                formData: {
                    id: '',
                    email: '',
                    name_en: '',
                    name_ar: '',
                    position_en: '',
                    position_ar: '',
                    phone: '',
                    mobile: '',
                    company_en: '',
                    company_ar: '',
                    website: '',
                    address: ''
                },
                
                matchesSearch(email, nameEn, nameAr) {
                    if (!this.searchQuery) return true;
                    const query = this.searchQuery.toLowerCase();
                    return email.toLowerCase().includes(query) || 
                           nameEn.toLowerCase().includes(query) || 
                           nameAr.toLowerCase().includes(query);
                },
                
                openAddModal() {
                    this.editingEmployee = false;
                    this.formData = {
                        id: '', email: '', name_en: '', name_ar: '',
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
</body>
</html>

