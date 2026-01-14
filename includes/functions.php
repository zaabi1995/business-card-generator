<?php
/**
 * Helper Functions for BHD Business Cards
 */

/**
 * Get base URL (protocol + domain + base path)
 * @return string Full base URL (e.g., 'https://bc.bhd.om/')
 */
function getBaseUrl() {
    static $baseUrl = null;
    
    if ($baseUrl === null) {
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || 
                   (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
                   (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ? 'https' : 'http';
        
        $host = $_SERVER['HTTP_HOST'] ?? 'bc.bhd.om';
        $basePath = getBasePath();
        
        $baseUrl = $protocol . '://' . $host . $basePath;
    }
    
    return $baseUrl;
}

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
        if (basename($scriptDir) === 'admin' || basename($scriptDir) === 'includes' || basename($scriptDir) === 'company' || basename($scriptDir) === 'amwalpay' || basename($scriptDir) === 'webhooks') {
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
 * Check if running in production environment
 */
function isProduction() {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    return strpos($host, 'localhost') === false && 
           strpos($host, '127.0.0.1') === false &&
           strpos($host, '.local') === false &&
           strpos($host, '.test') === false;
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

/**
 * Generate abbreviation from company name
 * Example: "Mohsin Haider Darwish" -> "mhd"
 * Example: "Acme Corporation" -> "ac"
 * Example: "ABC Company" -> "abc"
 */
function generateAbbreviation($name) {
    $name = trim($name);
    if (empty($name)) {
        return '';
    }
    
    // Split into words
    $words = preg_split('/\s+/', $name);
    
    // If multiple words, take first letter of each
    if (count($words) >= 2) {
        $abbrev = '';
        foreach ($words as $word) {
            $firstChar = mb_substr($word, 0, 1);
            if (preg_match('/[a-zA-Z0-9]/', $firstChar)) {
                $abbrev .= strtolower($firstChar);
            }
        }
        return $abbrev;
    }
    
    // Single word: take first 3-5 characters
    $word = $words[0];
    $abbrev = '';
    for ($i = 0; $i < min(5, mb_strlen($word)); $i++) {
        $char = mb_substr($word, $i, 1);
        if (preg_match('/[a-zA-Z0-9]/', $char)) {
            $abbrev .= strtolower($char);
        }
    }
    
    return $abbrev;
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

function findCompanyBySlug($slug) {
    // Use database if available
    if (class_exists('DatabaseAdapter') && DatabaseAdapter::useDatabase()) {
        return DatabaseAdapter::findCompanyBySlug($slug);
    }
    
    // Fallback to JSON
    $companies = loadCompanies();
    foreach ($companies as $company) {
        if (isset($company['slug']) && $company['slug'] === $slug) {
            return $company;
        }
    }
    return null;
}

function findCompanyById($id) {
    // Use database if available
    if (class_exists('DatabaseAdapter') && DatabaseAdapter::useDatabase()) {
        return DatabaseAdapter::findCompanyById($id);
    }
    
    // Fallback to JSON
    $companies = loadCompanies();
    foreach ($companies as $company) {
        if (isset($company['id']) && $company['id'] === $id) {
            return $company;
        }
    }
    return null;
}

function createCompany($name, $email, $password, $parentCompanyId = null, $customSlug = null) {
    // Use database if available
    if (class_exists('DatabaseAdapter') && DatabaseAdapter::useDatabase()) {
        return DatabaseAdapter::createCompany($name, $email, $password, $parentCompanyId, $customSlug);
    }
    
    // Fallback to JSON
    $companies = loadCompanies();
    $slug = slugify($name);
    
    // Ensure unique slug
    $originalSlug = $slug;
    $counter = 1;
    while (findCompanyBySlug($slug)) {
        $slug = $originalSlug . '-' . $counter;
        $counter++;
    }
    
    // Use custom slug if provided
    if (!empty($customSlug)) {
        $customSlug = strtolower(trim($customSlug));
        $customSlug = preg_replace('/[^a-z0-9-]/', '', $customSlug);
        $customSlug = trim($customSlug, '-');
        
        if (!empty($customSlug) && !findCompanyBySlug($customSlug)) {
            $slug = $customSlug;
        }
    }
    
    $company = [
        'id' => generateUUID(),
        'name' => $name,
        'slug' => $slug,
        'email' => sanitizeEmail($email),
        'password' => password_hash($password, PASSWORD_BCRYPT),
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    $companies[] = $company;
    
    if (!is_dir(dirname(COMPANIES_JSON))) {
        mkdir(dirname(COMPANIES_JSON), 0755, true);
    }
    
    if (file_put_contents(COMPANIES_JSON, json_encode($companies, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
        return ['success' => true, 'company' => $company];
    }
    
    return ['success' => false, 'error' => 'Failed to save company'];
}

function setCompanyContext($company) {
    $_SESSION['company_id'] = $company['id'];
    $_SESSION['company_slug'] = $company['slug'];
    $_SESSION['company_name'] = $company['name'];
}

function getCurrentCompanyId() {
    return $_SESSION['company_id'] ?? null;
}

function getCurrentCompanySlug() {
    return $_SESSION['company_slug'] ?? null;
}

function clearCompanyContext() {
    unset($_SESSION['company_id']);
    unset($_SESSION['company_slug']);
    unset($_SESSION['company_name']);
}

function requireAdmin() {
    if (!isset($_SESSION['company_id'])) {
        header('Location: ' . getBasePath() . 'company/login.php');
        exit;
    }
}

function getCompanyEmployeesJsonPath($companyId) {
    $dir = COMPANIES_DATA_DIR . '/' . $companyId;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir . '/employees.json';
}

function getCompanyUploadsPath($companyId) {
    $dir = COMPANIES_UPLOADS_DIR . '/' . $companyId;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

/**
 * Get company data directory
 */
function getCompanyDataDir($companyId) {
    $dir = COMPANIES_DATA_DIR . '/' . $companyId;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

/**
 * Get company uploads directory
 */
function getCompanyUploadsDir($companyId) {
    return getCompanyUploadsPath($companyId);
}

/**
 * Get company templates directory
 */
function getCompanyTemplatesDir($companyId) {
    $dir = COMPANIES_UPLOADS_DIR . '/' . $companyId . '/templates';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

/**
 * Get company cards directory
 */
function getCompanyCardsDir($companyId) {
    $dir = COMPANIES_UPLOADS_DIR . '/' . $companyId . '/cards';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

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

function findEmployeeByEmail($email, $companyId = null) {
    // Use database if available
    if (class_exists('DatabaseAdapter') && DatabaseAdapter::useDatabase()) {
        return DatabaseAdapter::findEmployeeByEmail($email, $companyId);
    }
    
    // Fallback to JSON
    if ($companyId === null) {
        $companyId = getCurrentCompanyId();
    }
    $employees = loadEmployees($companyId);
    foreach ($employees as $employee) {
        if (isset($employee['email']) && strtolower($employee['email']) === strtolower($email)) {
            return $employee;
        }
    }
    return null;
}

function findEmployeeById($id, $companyId = null) {
    // Use database if available
    if (class_exists('DatabaseAdapter') && DatabaseAdapter::useDatabase()) {
        return DatabaseAdapter::findEmployeeById($id, $companyId);
    }
    
    // Fallback to JSON
    if ($companyId === null) {
        $companyId = getCurrentCompanyId();
    }
    $employees = loadEmployees($companyId);
    foreach ($employees as $employee) {
        if (isset($employee['id']) && $employee['id'] === $id) {
            return $employee;
        }
    }
    return null;
}

function addEmployee($employeeData, $companyId = null) {
    // Use database if available
    if (class_exists('DatabaseAdapter') && DatabaseAdapter::useDatabase()) {
        return DatabaseAdapter::addEmployee($employeeData, $companyId);
    }
    
    // Fallback to JSON
    if ($companyId === null) {
        $companyId = getCurrentCompanyId();
    }
    if (!$companyId) {
        return ['success' => false, 'error' => 'Company ID required'];
    }
    
    $employees = loadEmployees($companyId);
    
    if (empty($employeeData['id'])) {
        $employeeData['id'] = generateUUID();
    }
    
    $employees[] = $employeeData;
    $path = getCompanyEmployeesJsonPath($companyId);
    
    if (file_put_contents($path, json_encode($employees, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
        return ['success' => true, 'employee' => $employeeData];
    }
    
    return ['success' => false, 'error' => 'Failed to save employee'];
}

function updateEmployee($id, $employeeData, $companyId = null) {
    // Use database if available
    if (class_exists('DatabaseAdapter') && DatabaseAdapter::useDatabase()) {
        return DatabaseAdapter::updateEmployee($id, $employeeData, $companyId);
    }
    
    // Fallback to JSON
    if ($companyId === null) {
        $companyId = getCurrentCompanyId();
    }
    if (!$companyId) {
        return ['success' => false, 'error' => 'Company ID required'];
    }
    
    $employees = loadEmployees($companyId);
    $found = false;
    
    foreach ($employees as $index => $employee) {
        if (isset($employee['id']) && $employee['id'] === $id) {
            $employees[$index] = array_merge($employee, $employeeData);
            $employees[$index]['id'] = $id; // Preserve ID
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        return ['success' => false, 'error' => 'Employee not found'];
    }
    
    $path = getCompanyEmployeesJsonPath($companyId);
    
    if (file_put_contents($path, json_encode($employees, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
        return ['success' => true, 'employee' => $employees[$index]];
    }
    
    return ['success' => false, 'error' => 'Failed to update employee'];
}

function deleteEmployee($id, $companyId = null) {
    // Use database if available
    if (class_exists('DatabaseAdapter') && DatabaseAdapter::useDatabase()) {
        return DatabaseAdapter::deleteEmployee($id, $companyId);
    }
    
    // Fallback to JSON
    if ($companyId === null) {
        $companyId = getCurrentCompanyId();
    }
    if (!$companyId) {
        return ['success' => false, 'error' => 'Company ID required'];
    }
    
    $employees = loadEmployees($companyId);
    $found = false;
    
    foreach ($employees as $index => $employee) {
        if (isset($employee['id']) && $employee['id'] === $id) {
            unset($employees[$index]);
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        return ['success' => false, 'error' => 'Employee not found'];
    }
    
    $employees = array_values($employees); // Re-index
    $path = getCompanyEmployeesJsonPath($companyId);
    
    if (file_put_contents($path, json_encode($employees, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
        return ['success' => true];
    }
    
    return ['success' => false, 'error' => 'Failed to delete employee'];
}

function loadTemplates($companyId = null) {
    // Use database if available
    if (class_exists('DatabaseAdapter') && DatabaseAdapter::useDatabase()) {
        return DatabaseAdapter::loadTemplates($companyId);
    }
    
    // Fallback to JSON
    if ($companyId === null) {
        $companyId = getCurrentCompanyId();
    }
    $path = $companyId ? (getCompanyUploadsPath($companyId) . '/templates.json') : TEMPLATES_JSON;
    if (!file_exists($path)) {
        return getDefaultTemplatesConfig();
    }
    $json = file_get_contents($path);
    $data = json_decode($json, true);
    return is_array($data) ? $data : getDefaultTemplatesConfig();
}

function saveTemplates($templates, $companyId = null) {
    // Use database if available
    if (class_exists('DatabaseAdapter') && DatabaseAdapter::useDatabase()) {
        return DatabaseAdapter::saveTemplates($templates, $companyId);
    }
    
    // Fallback to JSON
    if ($companyId === null) {
        $companyId = getCurrentCompanyId();
    }
    $path = $companyId ? (getCompanyUploadsPath($companyId) . '/templates.json') : TEMPLATES_JSON;
    
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    
    return file_put_contents($path, json_encode($templates, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

function getDefaultTemplatesConfig() {
    return [
        'front' => null,
        'back' => null
    ];
}

function getActiveFrontTemplate($companyId = null) {
    $templates = loadTemplates($companyId);
    return $templates['front'] ?? null;
}

function getActiveBackTemplate($companyId = null) {
    $templates = loadTemplates($companyId);
    return $templates['back'] ?? null;
}

function loadGeneratedLog($companyId = null) {
    // Use database if available
    if (class_exists('DatabaseAdapter') && DatabaseAdapter::useDatabase()) {
        return DatabaseAdapter::loadGeneratedLog($companyId);
    }
    
    // Fallback to JSON
    if ($companyId === null) {
        $companyId = getCurrentCompanyId();
    }
    $path = $companyId ? (getCompanyUploadsPath($companyId) . '/generated.json') : GENERATED_JSON;
    if (!file_exists($path)) {
        return [];
    }
    $json = file_get_contents($path);
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function logGeneratedCard($employeeId, $frontUrl, $backUrl, $companyId = null) {
    // Use database if available
    if (class_exists('DatabaseAdapter') && DatabaseAdapter::useDatabase()) {
        return DatabaseAdapter::logGeneratedCard($employeeId, $frontUrl, $backUrl, $companyId);
    }
    
    // Fallback to JSON
    if ($companyId === null) {
        $companyId = getCurrentCompanyId();
    }
    $log = loadGeneratedLog($companyId);
    $log[] = [
        'employee_id' => $employeeId,
        'front_url' => $frontUrl,
        'back_url' => $backUrl,
        'generated_at' => date('Y-m-d H:i:s')
    ];
    $path = $companyId ? (getCompanyUploadsPath($companyId) . '/generated.json') : GENERATED_JSON;
    
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    
    return file_put_contents($path, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
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

// ============================================
// AUTHENTICATION FUNCTIONS (Legacy Support)
// ============================================

function companyAdminLogin($slug, $password) {
    require_once INCLUDES_DIR . '/Auth.php';
    $company = findCompanyBySlug($slug);
    
    if (!$company) {
        return ['success' => false, 'error' => 'Company not found'];
    }
    
    // Try unified auth first
    if (DatabaseAdapter::useDatabase()) {
        $db = Database::getInstance();
        $user = $db->fetchOne(
            "SELECT * FROM users WHERE company_id = :id AND role IN ('company', 'admin') AND status = 'active'",
            ['id' => $company['id']]
        );
        
        if ($user && password_verify($password, $user['password_hash'])) {
            $result = Auth::loginUser($user);
            return $result;
        }
    }
    
    // Fallback to company password
    if (password_verify($password, $company['password_hash'])) {
        setCompanyContext($company);
        return ['success' => true, 'company' => $company];
    }
    
    return ['success' => false, 'error' => 'Invalid password'];
}

function isCompanyAdminLoggedIn() {
    return isset($_SESSION['company_id']) || (isset($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['company', 'admin', 'super_admin']));
}

function isAdminLoggedIn() {
    return isset($_SESSION['admin_logged_in']) || isCompanyAdminLoggedIn();
}

function loginAdmin($password) {
    // Legacy single-tenant admin login
    $adminPassword = 'admin'; // Default - should be in config
    if ($password === $adminPassword) {
        $_SESSION['admin_logged_in'] = true;
        return true;
    }
    return false;
}

// Initialize on include
initializeDataFiles();
