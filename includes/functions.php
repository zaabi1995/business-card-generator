<?php
/**
 * Helper Functions for BHD Business Cards
 */

/**
 * Get base path for assets (works in subdirectories)
 * @return string Base path with trailing slash (e.g., '/' or '/bhd/')
 */
function getBasePath() {
    static $basePath = null;
    
    if ($basePath === null) {
        $scriptPath = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '/index.php';
        $scriptPath = str_replace('\\', '/', $scriptPath);
        $scriptDir = dirname($scriptPath);
        
        // If script is in admin subdirectory, go up one level
        if (basename($scriptDir) === 'admin' || basename($scriptDir) === 'includes') {
            $scriptDir = dirname($scriptDir);
        }
        
        $scriptDir = str_replace('\\', '/', $scriptDir);
        
        if ($scriptDir === '/' || $scriptDir === '.' || $scriptDir === '') {
            $basePath = '/';
        } else {
            $basePath = rtrim($scriptDir, '/') . '/';
        }
        
        if ($basePath[0] !== '/') {
            $basePath = '/' . $basePath;
        }
    }
    
    return $basePath;
}

/**
 * Get asset URL
 */
function assetUrl($path) {
    $basePath = getBasePath();
    $path = ltrim($path, '/');
    return $basePath . 'assets/' . $path;
}

/**
 * Convert image path to web-accessible URL
 */
function imageUrl($imagePath) {
    if (empty($imagePath)) {
        return '';
    }
    
    $basePath = getBasePath();
    
    if ($basePath !== '/' && strpos($imagePath, $basePath) === 0) {
        $imagePath = substr($imagePath, strlen(rtrim($basePath, '/')));
    }
    
    if ($imagePath[0] === '/') {
        if ($basePath !== '/') {
            return rtrim($basePath, '/') . $imagePath;
        }
        return $imagePath;
    }
    
    if (file_exists($imagePath) && strpos($imagePath, BASE_DIR) === 0) {
        return getWebPath($imagePath);
    }
    
    return $basePath . ltrim($imagePath, '/');
}

/**
 * Get file system path from stored path
 */
function getFilePath($storedPath) {
    if (empty($storedPath)) {
        return '';
    }
    
    $basePath = getBasePath();
    
    if ($basePath !== '/' && strpos($storedPath, $basePath) === 0) {
        $storedPath = substr($storedPath, strlen(rtrim($basePath, '/')));
    }
    
    return BASE_DIR . $storedPath;
}

/**
 * Sanitize input string
 */
function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Sanitize email
 */
function sanitizeEmail($email) {
    $email = trim(strtolower($email));
    return filter_var($email, FILTER_SANITIZE_EMAIL);
}

/**
 * Validate email format
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Generate UUID
 */
function generateUUID() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

// ============================================
// EMPLOYEE FUNCTIONS
// ============================================

/**
 * Load employees from JSON
 */
function loadEmployees() {
    if (!file_exists(EMPLOYEES_JSON)) {
        return [];
    }
    
    $json = file_get_contents(EMPLOYEES_JSON);
    $data = json_decode($json, true);
    
    return is_array($data) ? $data : [];
}

/**
 * Save employees to JSON
 */
function saveEmployees($employees) {
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0755, true);
    }
    
    $json = json_encode(array_values($employees), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return file_put_contents(EMPLOYEES_JSON, $json) !== false;
}

/**
 * Find employee by email
 */
function findEmployeeByEmail($email) {
    $email = trim(strtolower($email));
    $employees = loadEmployees();
    
    foreach ($employees as $employee) {
        if (strtolower($employee['email'] ?? '') === $email) {
            return $employee;
        }
    }
    
    return null;
}

/**
 * Find employee by ID
 */
function findEmployeeById($id) {
    $employees = loadEmployees();
    
    foreach ($employees as $employee) {
        if ($employee['id'] === $id) {
            return $employee;
        }
    }
    
    return null;
}

/**
 * Add new employee
 */
function addEmployee($data) {
    $employees = loadEmployees();
    
    // Check if email exists
    foreach ($employees as $emp) {
        if (strtolower($emp['email'] ?? '') === strtolower($data['email'] ?? '')) {
            return ['success' => false, 'error' => 'Email already exists'];
        }
    }
    
    $employee = [
        'id' => generateUUID(),
        'email' => trim(strtolower($data['email'] ?? '')),
        'name_en' => trim($data['name_en'] ?? ''),
        'name_ar' => trim($data['name_ar'] ?? ''),
        'position_en' => trim($data['position_en'] ?? ''),
        'position_ar' => trim($data['position_ar'] ?? ''),
        'phone' => trim($data['phone'] ?? ''),
        'mobile' => trim($data['mobile'] ?? ''),
        'company_en' => trim($data['company_en'] ?? ''),
        'company_ar' => trim($data['company_ar'] ?? ''),
        'website' => trim($data['website'] ?? ''),
        'address' => trim($data['address'] ?? ''),
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    $employees[] = $employee;
    
    if (saveEmployees($employees)) {
        return ['success' => true, 'employee' => $employee];
    }
    
    return ['success' => false, 'error' => 'Failed to save employee'];
}

/**
 * Update employee
 */
function updateEmployee($id, $data) {
    $employees = loadEmployees();
    $found = false;
    
    foreach ($employees as &$employee) {
        if ($employee['id'] === $id) {
            // Check if new email conflicts with another employee
            $newEmail = strtolower(trim($data['email'] ?? ''));
            foreach ($employees as $emp) {
                if ($emp['id'] !== $id && strtolower($emp['email'] ?? '') === $newEmail) {
                    return ['success' => false, 'error' => 'Email already exists'];
                }
            }
            
            $employee['email'] = $newEmail;
            $employee['name_en'] = trim($data['name_en'] ?? '');
            $employee['name_ar'] = trim($data['name_ar'] ?? '');
            $employee['position_en'] = trim($data['position_en'] ?? '');
            $employee['position_ar'] = trim($data['position_ar'] ?? '');
            $employee['phone'] = trim($data['phone'] ?? '');
            $employee['mobile'] = trim($data['mobile'] ?? '');
            $employee['company_en'] = trim($data['company_en'] ?? '');
            $employee['company_ar'] = trim($data['company_ar'] ?? '');
            $employee['website'] = trim($data['website'] ?? '');
            $employee['address'] = trim($data['address'] ?? '');
            $employee['updated_at'] = date('Y-m-d H:i:s');
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        return ['success' => false, 'error' => 'Employee not found'];
    }
    
    if (saveEmployees($employees)) {
        return ['success' => true];
    }
    
    return ['success' => false, 'error' => 'Failed to save changes'];
}

/**
 * Delete employee
 */
function deleteEmployee($id) {
    $employees = loadEmployees();
    $newEmployees = array_filter($employees, function($emp) use ($id) {
        return $emp['id'] !== $id;
    });
    
    if (count($newEmployees) === count($employees)) {
        return ['success' => false, 'error' => 'Employee not found'];
    }
    
    if (saveEmployees($newEmployees)) {
        return ['success' => true];
    }
    
    return ['success' => false, 'error' => 'Failed to delete employee'];
}

// ============================================
// TEMPLATE FUNCTIONS
// ============================================

/**
 * Load templates from JSON
 */
function loadTemplates() {
    if (!file_exists(TEMPLATES_JSON)) {
        return getDefaultTemplatesConfig();
    }
    
    $json = file_get_contents(TEMPLATES_JSON);
    $data = json_decode($json, true);
    
    if ($data === null) {
        return getDefaultTemplatesConfig();
    }
    
    return $data;
}

/**
 * Save templates to JSON
 */
function saveTemplates($config) {
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0755, true);
    }
    
    $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return file_put_contents(TEMPLATES_JSON, $json) !== false;
}

/**
 * Get default templates config
 */
function getDefaultTemplatesConfig() {
    return [
        'activeFrontId' => null,
        'activeBackId' => null,
        'templates' => []
    ];
}

/**
 * Get default field settings
 */
function getDefaultFieldSettings() {
    return [
        'name_en' => ['x' => 50, 'y' => 30, 'fontSize' => 24, 'fontFamily' => "'Plus Jakarta Sans', sans-serif", 'fontWeight' => 'bold', 'color' => '#ffffff', 'enabled' => true],
        'name_ar' => ['x' => 50, 'y' => 40, 'fontSize' => 22, 'fontFamily' => "'Cairo', sans-serif", 'fontWeight' => 'bold', 'color' => '#ffffff', 'enabled' => true],
        'position_en' => ['x' => 50, 'y' => 50, 'fontSize' => 16, 'fontFamily' => "'Plus Jakarta Sans', sans-serif", 'fontWeight' => 'normal', 'color' => '#d4af37', 'enabled' => true],
        'position_ar' => ['x' => 50, 'y' => 58, 'fontSize' => 14, 'fontFamily' => "'Cairo', sans-serif", 'fontWeight' => 'normal', 'color' => '#d4af37', 'enabled' => true],
        'phone' => ['x' => 20, 'y' => 75, 'fontSize' => 12, 'fontFamily' => "'Plus Jakarta Sans', sans-serif", 'fontWeight' => 'normal', 'color' => '#ffffff', 'enabled' => true],
        'mobile' => ['x' => 20, 'y' => 82, 'fontSize' => 12, 'fontFamily' => "'Plus Jakarta Sans', sans-serif", 'fontWeight' => 'normal', 'color' => '#ffffff', 'enabled' => true],
        'email' => ['x' => 20, 'y' => 89, 'fontSize' => 12, 'fontFamily' => "'Plus Jakarta Sans', sans-serif", 'fontWeight' => 'normal', 'color' => '#ffffff', 'enabled' => true],
        'company_en' => ['x' => 80, 'y' => 75, 'fontSize' => 14, 'fontFamily' => "'Plus Jakarta Sans', sans-serif", 'fontWeight' => 'bold', 'color' => '#ffffff', 'enabled' => true],
        'company_ar' => ['x' => 80, 'y' => 82, 'fontSize' => 12, 'fontFamily' => "'Cairo', sans-serif", 'fontWeight' => 'normal', 'color' => '#ffffff', 'enabled' => false],
        'website' => ['x' => 80, 'y' => 89, 'fontSize' => 11, 'fontFamily' => "'Plus Jakarta Sans', sans-serif", 'fontWeight' => 'normal', 'color' => '#d4af37', 'enabled' => true],
        'address' => ['x' => 50, 'y' => 95, 'fontSize' => 10, 'fontFamily' => "'Plus Jakarta Sans', sans-serif", 'fontWeight' => 'normal', 'color' => '#cccccc', 'enabled' => false],
        'qr_code' => ['x' => 85, 'y' => 50, 'size' => 80, 'enabled' => false]
    ];
}

/**
 * Get template by ID
 */
function getTemplateById($id) {
    $config = loadTemplates();
    
    foreach ($config['templates'] as $template) {
        if ($template['id'] === $id) {
            return $template;
        }
    }
    
    return null;
}

/**
 * Get active front template
 */
function getActiveFrontTemplate() {
    $config = loadTemplates();
    $activeId = $config['activeFrontId'] ?? null;
    
    if (!$activeId) return null;
    
    foreach ($config['templates'] as $template) {
        if ($template['id'] === $activeId && $template['side'] === 'front') {
            return $template;
        }
    }
    
    return null;
}

/**
 * Get active back template
 */
function getActiveBackTemplate() {
    $config = loadTemplates();
    $activeId = $config['activeBackId'] ?? null;
    
    if (!$activeId) return null;
    
    foreach ($config['templates'] as $template) {
        if ($template['id'] === $activeId && $template['side'] === 'back') {
            return $template;
        }
    }
    
    return null;
}

/**
 * Generate template ID
 */
function generateTemplateId($name) {
    $base = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
    $base = trim($base, '-');
    return $base . '-' . substr(uniqid(), -6);
}

// ============================================
// GENERATED CARDS LOG
// ============================================

/**
 * Load generated cards log
 */
function loadGeneratedLog() {
    if (!file_exists(GENERATED_JSON)) {
        return [];
    }
    
    $json = file_get_contents(GENERATED_JSON);
    $data = json_decode($json, true);
    
    return is_array($data) ? $data : [];
}

/**
 * Save generated cards log
 */
function saveGeneratedLog($log) {
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0755, true);
    }
    
    $json = json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return file_put_contents(GENERATED_JSON, $json) !== false;
}

/**
 * Log generated card
 */
function logGeneratedCard($employeeId, $frontTemplateId, $backTemplateId, $frontFile, $backFile, $pdfFile = null) {
    $log = loadGeneratedLog();
    
    $entry = [
        'id' => generateUUID(),
        'employee_id' => $employeeId,
        'front_template_id' => $frontTemplateId,
        'back_template_id' => $backTemplateId,
        'front_file' => $frontFile,
        'back_file' => $backFile,
        'pdf_file' => $pdfFile,
        'generated_at' => date('Y-m-d H:i:s')
    ];
    
    // Add to beginning of array (newest first)
    array_unshift($log, $entry);
    
    // Keep only last 500 entries
    $log = array_slice($log, 0, 500);
    
    saveGeneratedLog($log);
    
    return $entry;
}

// ============================================
// AUTH FUNCTIONS
// ============================================

/**
 * Check if admin is logged in
 */
function isAdminLoggedIn() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

/**
 * Require admin login
 */
function requireAdmin() {
    if (!isAdminLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Login admin
 */
function loginAdmin($password) {
    if ($password === ADMIN_PASSWORD) {
        $_SESSION['admin_logged_in'] = true;
        return true;
    }
    return false;
}

/**
 * Logout admin
 */
function logoutAdmin() {
    $_SESSION['admin_logged_in'] = false;
    session_destroy();
}

// ============================================
// FILE UPLOAD FUNCTIONS
// ============================================

/**
 * Handle file upload
 */
function handleFileUpload($file, $destination, $allowedTypes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], $maxSizeMB = 10) {
    if (!isset($file['error'])) {
        return ['success' => false, 'error' => 'No file uploaded.'];
    }
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit.',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form upload limit.',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension.'
        ];
        return ['success' => false, 'error' => $errorMessages[$file['error']] ?? 'Unknown upload error.'];
    }
    
    $maxSizeBytes = $maxSizeMB * 1024 * 1024;
    if ($file['size'] > $maxSizeBytes) {
        return ['success' => false, 'error' => "File size exceeds {$maxSizeMB}MB limit."];
    }
    
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    
    if (!in_array($mimeType, $allowedTypes)) {
        return ['success' => false, 'error' => 'Invalid file type. Only PNG, JPEG, GIF, and WebP are allowed.'];
    }
    
    if (!is_dir($destination)) {
        mkdir($destination, 0755, true);
    }
    
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid('template_') . '.' . strtolower($ext);
    $filepath = $destination . '/' . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => true, 'path' => $filepath];
    }
    
    return ['success' => false, 'error' => 'Failed to save uploaded file.'];
}

/**
 * Get web path from absolute path
 */
function getWebPath($absolutePath) {
    $relativePath = str_replace(BASE_DIR, '', $absolutePath);
    $relativePath = str_replace('\\', '/', $relativePath);
    if (!empty($relativePath) && $relativePath[0] !== '/') {
        $relativePath = '/' . $relativePath;
    }
    return $relativePath;
}

/**
 * Ensure directories exist
 */
function ensureDirectories() {
    $dirs = [DATA_DIR, TEMPLATES_DIR, CARDS_DIR, EXCEL_DIR, ASSETS_DIR];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}

/**
 * Initialize data files
 */
function initializeDataFiles() {
    ensureDirectories();
    
    if (!file_exists(EMPLOYEES_JSON)) {
        saveEmployees([]);
    }
    
    if (!file_exists(TEMPLATES_JSON)) {
        saveTemplates(getDefaultTemplatesConfig());
    }
    
    if (!file_exists(GENERATED_JSON)) {
        saveGeneratedLog([]);
    }
}

// Initialize on include
initializeDataFiles();

