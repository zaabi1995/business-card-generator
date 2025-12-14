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
// MULTI-TENANT (COMPANY) HELPERS
// ============================================

if (!defined('COMPANIES_JSON')) {
    define('COMPANIES_JSON', DATA_DIR . '/companies.json');
}
if (!defined('COMPANIES_DATA_DIR')) {
    define('COMPANIES_DATA_DIR', DATA_DIR . '/companies');
}
if (!defined('COMPANIES_UPLOADS_DIR')) {
    define('COMPANIES_UPLOADS_DIR', UPLOADS_DIR . '/companies');
}

function slugify($value) {
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    $value = trim($value, '-');
    return $value ?: 'company';
}

function isMultiTenantEnabled() {
    if (!file_exists(COMPANIES_JSON)) {
        return false;
    }
    $json = file_get_contents(COMPANIES_JSON);
    $data = json_decode($json, true);
    return is_array($data) && count($data) > 0;
}

function loadCompanies() {
    // Use database if available
    if (class_exists('DatabaseAdapter') && DatabaseAdapter::useDatabase()) {
        return DatabaseAdapter::loadCompanies();
    }
    
    // Fallback to JSON
    if (!file_exists(COMPANIES_JSON)) {
        return [];
    }
    $json = file_get_contents(COMPANIES_JSON);
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function saveCompanies($companies) {
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0755, true);
    }
    $json = json_encode(array_values($companies), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return file_put_contents(COMPANIES_JSON, $json) !== false;
}

function findCompanyBySlug($slug) {
    // Use database if available
    if (class_exists('DatabaseAdapter') && DatabaseAdapter::useDatabase()) {
        return DatabaseAdapter::findCompanyBySlug($slug);
    }
    
    // Fallback to JSON
    $slug = slugify($slug);
    foreach (loadCompanies() as $company) {
        if (($company['slug'] ?? '') === $slug) {
            return $company;
        }
    }
    return null;
}

function findCompanyById($companyId) {
    // Use database if available
    if (class_exists('DatabaseAdapter') && DatabaseAdapter::useDatabase()) {
        return DatabaseAdapter::findCompanyById($companyId);
    }
    
    // Fallback to JSON
    foreach (loadCompanies() as $company) {
        if (($company['id'] ?? '') === $companyId) {
            return $company;
        }
    }
    return null;
}

function setCompanyContext($company) {
    if (!$company || empty($company['id']) || empty($company['slug'])) {
        return false;
    }
    $_SESSION['company_id'] = $company['id'];
    $_SESSION['company_slug'] = $company['slug'];
    $_SESSION['company_name'] = $company['name'] ?? $company['slug'];
    return true;
}

function clearCompanyContext() {
    unset($_SESSION['company_id'], $_SESSION['company_slug'], $_SESSION['company_name']);
}

function getCurrentCompanyId() {
    return $_SESSION['company_id'] ?? null;
}

function requireCompanyContext() {
    if (!isMultiTenantEnabled()) {
        return;
    }
    if (!getCurrentCompanyId()) {
        header('Location: ' . getBasePath() . 'company/login.php');
        exit;
    }
}

function getCompanyDataDir($companyId) {
    $dir = COMPANIES_DATA_DIR . '/' . $companyId;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

function getCompanyUploadsDir($companyId) {
    $dir = COMPANIES_UPLOADS_DIR . '/' . $companyId;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

function getCompanyEmployeesJsonPath($companyId) {
    return getCompanyDataDir($companyId) . '/employees.json';
}

function getCompanyTemplatesJsonPath($companyId) {
    return getCompanyDataDir($companyId) . '/templates.json';
}

function getCompanyGeneratedJsonPath($companyId) {
    return getCompanyDataDir($companyId) . '/generated.json';
}

function getCompanyTemplatesDir($companyId) {
    $dir = getCompanyUploadsDir($companyId) . '/templates';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

function getCompanyCardsDir($companyId) {
    $dir = getCompanyUploadsDir($companyId) . '/cards';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

function createCompany($name, $adminEmail, $password) {
    // Use database if available
    if (class_exists('DatabaseAdapter') && DatabaseAdapter::useDatabase()) {
        return DatabaseAdapter::createCompany($name, $adminEmail, $password);
    }
    
    // Fallback to JSON
    $name = trim($name);
    $adminEmail = sanitizeEmail($adminEmail);
    if (empty($name)) {
        return ['success' => false, 'error' => 'Company name is required'];
    }
    if (!isValidEmail($adminEmail)) {
        return ['success' => false, 'error' => 'Valid admin email is required'];
    }
    if (strlen($password) < 6) {
        return ['success' => false, 'error' => 'Password must be at least 6 characters'];
    }

    $companies = loadCompanies();
    $baseSlug = slugify($name);
    $slug = $baseSlug;
    $i = 1;
    while (findCompanyBySlug($slug)) {
        $i++;
        $slug = $baseSlug . '-' . $i;
    }

    $company = [
        'id' => generateUUID(),
        'name' => $name,
        'slug' => $slug,
        'admin_email' => $adminEmail,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'created_at' => date('Y-m-d H:i:s')
    ];

    $companies[] = $company;
    if (!saveCompanies($companies)) {
        return ['success' => false, 'error' => 'Failed to create company'];
    }

    // Initialize company data files
    $cid = $company['id'];
    if (!file_exists(getCompanyEmployeesJsonPath($cid))) {
        file_put_contents(getCompanyEmployeesJsonPath($cid), json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    if (!file_exists(getCompanyTemplatesJsonPath($cid))) {
        file_put_contents(getCompanyTemplatesJsonPath($cid), json_encode(getDefaultTemplatesConfig(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    if (!file_exists(getCompanyGeneratedJsonPath($cid))) {
        file_put_contents(getCompanyGeneratedJsonPath($cid), json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    // Ensure company upload dirs
    getCompanyTemplatesDir($cid);
    getCompanyCardsDir($cid);

    return ['success' => true, 'company' => $company];
}

function companyAdminLogin($companySlug, $password) {
    $company = findCompanyBySlug($companySlug);
    if (!$company) {
        return ['success' => false, 'error' => 'Company not found'];
    }
    if (!password_verify($password, $company['password_hash'] ?? '')) {
        return ['success' => false, 'error' => 'Invalid password'];
    }
    setCompanyContext($company);
    $_SESSION['company_admin_logged_in'] = true;
    return ['success' => true, 'company' => $company];
}

function isCompanyAdminLoggedIn() {
    return !empty($_SESSION['company_admin_logged_in']) && !empty($_SESSION['company_id']);
}

function requireCompanyAdmin() {
    if (!isCompanyAdminLoggedIn()) {
        header('Location: ' . getBasePath() . 'admin/login.php');
        exit;
    }
}

function logoutCompanyAdmin() {
    unset($_SESSION['company_admin_logged_in']);
    clearCompanyContext();
}

// ============================================
// EMPLOYEE FUNCTIONS
// ============================================

/**
 * Load employees from JSON
 */
function loadEmployees($companyId = null) {
    // Use database if available
    if (class_exists('DatabaseAdapter') && DatabaseAdapter::useDatabase()) {
        return DatabaseAdapter::loadEmployees($companyId);
    }
    
    // Fallback to JSON
    if ($companyId === null) {
        $companyId = getCurrentCompanyId();
    }
    $path = $companyId ? getCompanyEmployeesJsonPath($companyId) : EMPLOYEES_JSON;
    if (!file_exists($path)) {
        return [];
    }
    
    $json = file_get_contents($path);
    $data = json_decode($json, true);
    
    return is_array($data) ? $data : [];
}

/**
 * Save employees to JSON
 */
function saveEmployees($employees, $companyId = null) {
    if ($companyId === null) {
        $companyId = getCurrentCompanyId();
    }
    
    $json = json_encode(array_values($employees), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $path = $companyId ? getCompanyEmployeesJsonPath($companyId) : EMPLOYEES_JSON;
    return file_put_contents($path, $json) !== false;
}

/**
 * Find employee by email
 */
function findEmployeeByEmail($email, $companyId = null) {
    // Use database if available
    if (class_exists('DatabaseAdapter') && DatabaseAdapter::useDatabase()) {
        return DatabaseAdapter::findEmployeeByEmail($email, $companyId);
    }
    
    // Fallback to JSON
    $email = trim(strtolower($email));
    $employees = loadEmployees($companyId);
    
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
function findEmployeeById($id, $companyId = null) {
    // Use database if available
    if (class_exists('DatabaseAdapter') && DatabaseAdapter::useDatabase()) {
        return DatabaseAdapter::findEmployeeById($id, $companyId);
    }
    
    // Fallback to JSON
    $employees = loadEmployees($companyId);
    
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
function addEmployee($data, $companyId = null) {
    // Use database if available
    if (class_exists('DatabaseAdapter') && DatabaseAdapter::useDatabase()) {
        return DatabaseAdapter::addEmployee($data, $companyId);
    }
    
    // Fallback to JSON
    $employees = loadEmployees($companyId);
    
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
    
    if (saveEmployees($employees, $companyId)) {
        return ['success' => true, 'employee' => $employee];
    }
    
    return ['success' => false, 'error' => 'Failed to save employee'];
}

/**
 * Update employee
 */
function updateEmployee($id, $data, $companyId = null) {
    // Use database if available
    if (class_exists('DatabaseAdapter') && DatabaseAdapter::useDatabase()) {
        return DatabaseAdapter::updateEmployee($id, $data, $companyId);
    }
    
    // Fallback to JSON
    $employees = loadEmployees($companyId);
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
    
    if (saveEmployees($employees, $companyId)) {
        return ['success' => true];
    }
    
    return ['success' => false, 'error' => 'Failed to save changes'];
}

/**
 * Delete employee
 */
function deleteEmployee($id, $companyId = null) {
    // Use database if available
    if (class_exists('DatabaseAdapter') && DatabaseAdapter::useDatabase()) {
        return DatabaseAdapter::deleteEmployee($id, $companyId);
    }
    
    // Fallback to JSON
    $employees = loadEmployees($companyId);
    $newEmployees = array_filter($employees, function($emp) use ($id) {
        return $emp['id'] !== $id;
    });
    
    if (count($newEmployees) === count($employees)) {
        return ['success' => false, 'error' => 'Employee not found'];
    }
    
    if (saveEmployees($newEmployees, $companyId)) {
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
function loadTemplates($companyId = null) {
    // Use database if available
    if (class_exists('DatabaseAdapter') && DatabaseAdapter::useDatabase()) {
        return DatabaseAdapter::loadTemplates($companyId);
    }
    
    // Fallback to JSON
    if ($companyId === null) {
        $companyId = getCurrentCompanyId();
    }
    $path = $companyId ? getCompanyTemplatesJsonPath($companyId) : TEMPLATES_JSON;
    if (!file_exists($path)) {
        return getDefaultTemplatesConfig();
    }
    
    $json = file_get_contents($path);
    $data = json_decode($json, true);
    
    if ($data === null) {
        return getDefaultTemplatesConfig();
    }
    
    return $data;
}

/**
 * Save templates to JSON
 */
function saveTemplates($config, $companyId = null) {
    // Use database if available
    if (class_exists('DatabaseAdapter') && DatabaseAdapter::useDatabase()) {
        return DatabaseAdapter::saveTemplates($config, $companyId);
    }
    
    // Fallback to JSON
    if ($companyId === null) {
        $companyId = getCurrentCompanyId();
    }
    
    $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $path = $companyId ? getCompanyTemplatesJsonPath($companyId) : TEMPLATES_JSON;
    return file_put_contents($path, $json) !== false;
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
function getTemplateById($id, $companyId = null) {
    $config = loadTemplates($companyId);
    
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
function getActiveFrontTemplate($companyId = null) {
    $config = loadTemplates($companyId);
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
function getActiveBackTemplate($companyId = null) {
    $config = loadTemplates($companyId);
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
function loadGeneratedLog($companyId = null) {
    // Use database if available
    if (class_exists('DatabaseAdapter') && DatabaseAdapter::useDatabase()) {
        return DatabaseAdapter::loadGeneratedLog($companyId);
    }
    
    // Fallback to JSON
    if ($companyId === null) {
        $companyId = getCurrentCompanyId();
    }
    $path = $companyId ? getCompanyGeneratedJsonPath($companyId) : GENERATED_JSON;
    if (!file_exists($path)) {
        return [];
    }
    
    $json = file_get_contents($path);
    $data = json_decode($json, true);
    
    return is_array($data) ? $data : [];
}

/**
 * Save generated cards log
 */
function saveGeneratedLog($log, $companyId = null) {
    if ($companyId === null) {
        $companyId = getCurrentCompanyId();
    }
    
    $json = json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $path = $companyId ? getCompanyGeneratedJsonPath($companyId) : GENERATED_JSON;
    return file_put_contents($path, $json) !== false;
}

/**
 * Log generated card
 */
function logGeneratedCard($employeeId, $frontTemplateId, $backTemplateId, $frontFile, $backFile, $pdfFile = null, $companyId = null) {
    // Use database if available
    if (class_exists('DatabaseAdapter') && DatabaseAdapter::useDatabase()) {
        return DatabaseAdapter::logGeneratedCard($employeeId, $frontTemplateId, $backTemplateId, $frontFile, $backFile, $pdfFile, $companyId);
    }
    
    // Fallback to JSON
    $log = loadGeneratedLog($companyId);
    
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
    
    saveGeneratedLog($log, $companyId);
    
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
    // Multi-tenant mode: company admin session
    if (isMultiTenantEnabled()) {
        requireCompanyAdmin();
        return;
    }
    // Single-tenant legacy mode
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
    $dirs = [DATA_DIR, COMPANIES_DATA_DIR, UPLOADS_DIR, COMPANIES_UPLOADS_DIR, TEMPLATES_DIR, CARDS_DIR, EXCEL_DIR, ASSETS_DIR];
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

    if (!file_exists(COMPANIES_JSON)) {
        // empty companies list by default; multi-tenant activates once a company exists
        file_put_contents(COMPANIES_JSON, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    
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

