<?php
/**
 * Cardify Helper Functions
 */

// I18n bootstrap, loaded for every request via config.php -> functions.php chain.
if (!class_exists('I18n')) {
    require_once __DIR__ . '/I18n.php';
}
I18n::boot();

// Sentry bootstrap (Cat T action 456). No-op unless SENTRY_DSN is
// defined in config.php. Installs set_exception_handler +
// register_shutdown_function so uncaught / fatal errors get reported.
if (!class_exists('Sentry')) {
    require_once __DIR__ . '/Sentry.php';
}
Sentry::init();

/**
 * Global translation helper. Alias for I18n::t().
 * t('common.save') / t('auth.welcome', ['name' => $name])
 */
if (!function_exists('t')) {
    function t(string $key, array $params = [], ?string $locale = null): string {
        return I18n::t($key, $params, $locale);
    }
}

if (!function_exists('currentLocale')) {
    function currentLocale(): string { return I18n::getLocale(); }
}

if (!function_exists('currentDir')) {
    function currentDir(): string { return I18n::getDir(); }
}

if (!function_exists('isRtl')) {
    function isRtl(): bool { return I18n::isRtl(); }
}

/**
 * Tenant URL helpers, canonicalize every tenant link to
 * https://{slug}.cardify.om{path}. Use these EVERYWHERE instead of
 * stringing `getBasePath() . $slug . '/...'` together. The old path
 * style is 301'd by router.php for compatibility, but every link we
 * emit should already be on the subdomain.
 */
function cardifyApexHost(): string {
    return defined('APP_HOST') ? APP_HOST : 'cardify.om';
}

function getTenantUrl(?string $slug, string $path = '/'): string {
    $slug = trim((string) $slug);
    if ($slug === '') {
        // Without a slug we fall back to the bare apex; callers should
        // avoid this, but the apex URL is still a valid landing page.
        return 'https://' . cardifyApexHost() . $path;
    }
    if ($path === '' || $path[0] !== '/') $path = '/' . $path;
    return 'https://' . $slug . '.' . cardifyApexHost() . $path;
}

/**
 * Canonical public card URL for an employee. Accepts either the
 * email (legacy `/card/{email}` pattern supported by digital_card
 * resolver) or a pre-slugified name; returns the subdomain form.
 */
function getTenantCardUrl(?string $slug, string $empKey): string {
    return getTenantUrl($slug, '/' . ltrim($empKey, '/'));
}

/**
 * Get base URL (protocol + domain + base path)
 * @return string Full base URL (e.g., 'https://cardify.om/')
 */
function getBaseUrl() {
    static $baseUrl = null;
    
    if ($baseUrl === null) {
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || 
                   (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
                   (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ? 'https' : 'http';
        
        $host = $_SERVER['HTTP_HOST'] ?? 'cardify.om';
        $basePath = getBasePath();
        
        $baseUrl = $protocol . '://' . $host . $basePath;
    }
    
    return $baseUrl;
}

/**
 * Get base path for assets (works in subdirectories)
 * @return string Base path with trailing slash (e.g., '/' or '/bhd/')
 */
if (!function_exists('getBasePath')) {
    function getBasePath() {
        static $basePath = null;
        
        if ($basePath === null) {
            $scriptPath = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '/index.php';
            $scriptPath = str_replace('\\', '/', $scriptPath);
            $scriptDir = dirname($scriptPath);
            
            // Known subdirectories that are part of the app
            $appDirs = ['admin', 'includes', 'company', 'amwalpay', 'paymob', 'webhooks', 'super', 'install', 'share', 'printshop', 'api', 'database', 'bhd', 'tools', 'industries', 'gcc'];
            
            // Navigate up through app directories to find the root
            while (in_array(basename($scriptDir), $appDirs) && $scriptDir !== '/' && $scriptDir !== '.') {
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
    
    // If path starts with 'companies/' or 'templates/', it's relative to uploads/
    if (preg_match('#^(companies|templates)/#', $imagePath)) {
        return $basePath . 'uploads/' . ltrim($imagePath, '/');
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
 * Generate or retrieve CSRF token for the current session
 * @return string The CSRF token
 */
function generateCSRFToken() {
    // Only attempt to start a session if headers haven't been sent yet
    // (calling generateCSRFToken from inside a rendered page would emit
    // a noisy "Session cannot be started after headers have already been
    // sent" warning into php-errors.log on every request).
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        @session_start();
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        // Without a session we still need a CSRF token, fall back to a
        // request-scoped random one. It won't survive across requests
        // but it satisfies the form-render contract.
        return bin2hex(random_bytes(16));
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate a CSRF token against the session token
 * @param string $token The token from the form submission
 * @return bool True if valid
 */
function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Output a hidden CSRF token input field for forms
 */
function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCSRFToken()) . '">';
}

/**
 * Same-origin guard for anonymous POST endpoints.
 *
 * Public forms (testimonials, appointment booking, lead capture) have no
 * server-issued CSRF token because the submitter has no session. Instead we
 * reject POSTs whose Origin / Referer header doesn't belong to this host,
 * which together with SameSite=Lax session cookies defeats the standard CSRF
 * vectors without breaking legitimate browser submissions.
 *
 * Returns true if the request is same-origin (or carries no Origin/Referer
 * at all, some browsers strip both for privacy, so we don't hard-fail).
 */
function isSameOriginRequest(): bool {
    $host = defined('APP_HOST') ? APP_HOST : ($_SERVER['HTTP_HOST'] ?? '');
    $allowed = array_filter([$host, 'cardify.om', 'www.cardify.om']);

    $origin  = $_SERVER['HTTP_ORIGIN']  ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';

    foreach ([$origin, $referer] as $h) {
        if ($h === '') continue;
        $parsedHost = strtolower(parse_url($h, PHP_URL_HOST) ?: '');
        if ($parsedHost === '') continue;
        return in_array($parsedHost, $allowed, true);
    }
    // No Origin AND no Referer, privacy mode or native client; allow through.
    return true;
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
 * Convert Western numerals to Arabic numerals
 * @param string $str Input string with Western numbers
 * @return string String with Arabic numerals (٠١٢٣٤٥٦٧٨٩)
 */
function toArabicNumerals($str) {
    if (empty($str)) return $str;
    $western = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    $arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    return str_replace($western, $arabic, $str);
}

/**
 * Convert Arabic numerals to Western numerals
 * @param string $str Input string with Arabic numbers
 * @return string String with Western numerals (0123456789)
 */
function toWesternNumerals($str) {
    if (empty($str)) return $str;
    $arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    $western = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return str_replace($arabic, $western, $str);
}

/**
 * Check if string contains Arabic numerals
 * @param string $str Input string
 * @return bool True if contains Arabic numerals
 */
function hasArabicNumerals($str) {
    return preg_match('/[٠-٩]/', $str) === 1;
}

/**
 * Extract domain from email address
 * @param string $email Email address
 * @return string|null Domain portion or null if invalid
 */
function extractEmailDomain($email) {
    $email = trim(strtolower($email));
    if (!isValidEmail($email)) {
        return null;
    }
    $parts = explode('@', $email);
    return $parts[1] ?? null;
}

/**
 * Check if email domain is a common/public domain
 * Common domains should not auto-create companies
 * @param string $email Email address
 * @return bool True if common domain (gmail, hotmail, etc.)
 */
function isCommonEmailDomain($email) {
    $commonDomains = [
        // Google
        'gmail.com', 'googlemail.com',
        // Microsoft
        'hotmail.com', 'outlook.com', 'live.com', 'msn.com', 'hotmail.co.uk',
        // Yahoo
        'yahoo.com', 'yahoo.co.uk', 'yahoo.fr', 'yahoo.de', 'ymail.com', 'rocketmail.com',
        // Apple
        'icloud.com', 'me.com', 'mac.com',
        // Others
        'aol.com', 'protonmail.com', 'proton.me', 'zoho.com', 'mail.com',
        'gmx.com', 'gmx.de', 'gmx.net', 'web.de', 'freenet.de',
        // Regional
        'qq.com', '163.com', '126.com', 'sina.com', 'sohu.com',
        'naver.com', 'daum.net', 'hanmail.net',
        'yandex.ru', 'yandex.com', 'mail.ru',
        // Disposable/Temporary (block these)
        'tempmail.com', 'throwaway.com', 'guerrillamail.com', 'mailinator.com',
        '10minutemail.com', 'fakeinbox.com', 'sharklasers.com',
    ];
    
    $domain = extractEmailDomain($email);
    if (!$domain) {
        return true; // Treat invalid as common (don't create company)
    }
    
    return in_array(strtolower($domain), $commonDomains);
}

/**
 * Check if email domain is a business/custom domain
 * @param string $email Email address
 * @return bool True if custom business domain
 */
function isBusinessEmailDomain($email) {
    return !isCommonEmailDomain($email);
}

/**
 * Generate a company slug from email domain
 * @param string $email Email address
 * @return string|null Slug based on domain
 */
function generateSlugFromEmail($email) {
    $domain = extractEmailDomain($email);
    if (!$domain || isCommonEmailDomain($email)) {
        return null;
    }
    
    // Remove common TLDs for cleaner slug
    $slug = preg_replace('/\.(com|org|net|co|io|tech|app|dev|ai)$/i', '', $domain);
    // Remove country TLDs (e.g., .co.uk, .com.au)
    $slug = preg_replace('/\.(co\.|com\.)?[a-z]{2}$/i', '', $slug);
    // Clean up
    $slug = slugify($slug);
    
    return $slug ?: null;
}

/**
 * Find company by domain
 * @param string $domain Domain to search
 * @return array|null Company data or null
 */
function findCompanyByDomain($domain) {
    if (!class_exists('DatabaseAdapter') || !DatabaseAdapter::useDatabase()) {
        return null;
    }
    
    $db = Database::getInstance();
    if (!$db->isConnected()) {
        return null;
    }
    
    // Check if any company has this domain in their email or domain field
    $company = $db->fetchOne(
        "SELECT * FROM companies WHERE 
         (domain = :domain OR admin_email LIKE :emailPattern) 
         AND status = 'active'
         LIMIT 1",
        [
            'domain' => $domain,
            'emailPattern' => '%@' . $domain
        ]
    );
    
    return $company ?: null;
}

/**
 * Generate UUID
 */
function generateUUID() {
    $data = random_bytes(16);
    // Set version to 4 (random)
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    // Set variant to RFC 4122
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// ============================================
// MULTI-TENANT (COMPANY) HELPERS
// ============================================

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
    if (!class_exists('DatabaseAdapter') || !DatabaseAdapter::useDatabase()) {
        return false;
    }

    $db = Database::getInstance();
    try {
        $result = $db->fetchOne("SELECT COUNT(*) as count FROM companies");
        return !empty($result) && (int)($result['count'] ?? 0) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function loadCompanies() {
    if (!class_exists('DatabaseAdapter') || !DatabaseAdapter::useDatabase()) {
        return [];
    }

    return DatabaseAdapter::loadCompanies();
}

function findCompanyBySlug($slug) {
    if (!class_exists('DatabaseAdapter') || !DatabaseAdapter::useDatabase()) {
        return null;
    }

    return DatabaseAdapter::findCompanyBySlug($slug);
}

function findCompanyById($id) {
    if (!class_exists('DatabaseAdapter') || !DatabaseAdapter::useDatabase()) {
        return null;
    }

    return DatabaseAdapter::findCompanyById($id);
}

function createCompany($name, $email, $password, $parentCompanyId = null, $customSlug = null) {
    if (!class_exists('DatabaseAdapter') || !DatabaseAdapter::useDatabase()) {
        return ['success' => false, 'error' => 'Database not available'];
    }

    return DatabaseAdapter::createCompany($name, $email, $password, $parentCompanyId, $customSlug);
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
    // Auto-load Auth so callers don't all need to require it first. Some
    // admin pages (e.g. auto_generate.php) call requireAdmin BEFORE pulling
    // admin-layout.php, the absent class made the gate redirect to /login.php
    // for any direct URL access.
    if (!class_exists('Auth') && defined('INCLUDES_DIR')) {
        $authPath = INCLUDES_DIR . '/Auth.php';
        if (is_file($authPath)) require_once $authPath;
    }

    // Check if logged in
    if (!class_exists('Auth') || !Auth::isLoggedIn()) {
        header('Location: ' . getBasePath() . 'login.php');
        exit;
    }

    // Check company context
    if (!isset($_SESSION['company_id'])) {
        header('Location: ' . getBasePath() . 'login.php');
        exit;
    }

    // Verify user has admin-level role
    $adminRoles = ['super_admin', 'admin', 'company', 'company_admin'];
    $userRole = $_SESSION['user_role'] ?? '';
    if (!in_array($userRole, $adminRoles)) {
        header('Location: ' . getBasePath() . 'login.php?error=unauthorized');
        exit;
    }

    // Redirect to company-specific admin URL if not already there
    if (!defined('COMPANY_ADMIN_BASE')) {
        redirectToCompanyAdmin();
    }
}

/**
 * Redirect to company-specific admin URL if user has a company context
 */
function redirectToCompanyAdmin() {
    // Skip if already in company admin context
    if (defined('COMPANY_ADMIN_BASE')) {
        return;
    }
    
    // Skip for super admins
    if (class_exists('Auth') && method_exists('Auth', 'isSuperAdmin') && Auth::isSuperAdmin()) {
        return;
    }
    
    // Skip if no session
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    
    // Redirect print shop users to their dashboard
    $userRole = $_SESSION['user_role'] ?? null;
    if ($userRole === 'print_shop') {
        header('Location: ' . getBasePath() . 'printshop/dashboard.php');
        exit;
    }
    
    // Get company slug
    $companySlug = $_SESSION['company_slug'] ?? null;
    
    if (!$companySlug && isset($_SESSION['company_id'])) {
        $company = findCompanyById($_SESSION['company_id']);
        if ($company && !empty($company['slug'])) {
            $companySlug = $company['slug'];
            $_SESSION['company_slug'] = $companySlug;
        }
    }
    
    if ($companySlug) {
        $currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
        $path = '/admin/' . ($currentPage !== 'index' ? $currentPage : '');
        // Don't loop: if we're already on the tenant subdomain, the URL is
        // canonical, just continue rendering. Only redirect from the apex.
        $currentHost = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
        $tenantHost  = strtolower($companySlug . '.' . cardifyApexHost());
        if ($currentHost === $tenantHost) {
            return;
        }
        header('Location: ' . getTenantUrl($companySlug, $path));
        exit;
    }
}

function getCompanyUploadsPath($companyId) {
    $dir = COMPANIES_UPLOADS_DIR . '/' . $companyId;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
        // Try with 777 if 755 fails
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
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
        @mkdir($dir, 0755, true);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
    }
    return $dir;
}

/**
 * Get company cards directory
 */
function getCompanyCardsDir($companyId) {
    $dir = COMPANIES_UPLOADS_DIR . '/' . $companyId . '/cards';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
    }
    return $dir;
}

function loadEmployees($companyId = null) {
    if (!class_exists('DatabaseAdapter') || !DatabaseAdapter::useDatabase()) {
        return [];
    }

    return DatabaseAdapter::loadEmployees($companyId);
}

function findEmployeeByEmail($email, $companyId = null) {
    if (!class_exists('DatabaseAdapter') || !DatabaseAdapter::useDatabase()) {
        return null;
    }

    return DatabaseAdapter::findEmployeeByEmail($email, $companyId);
}

function findEmployeeById($id, $companyId = null) {
    if (!class_exists('DatabaseAdapter') || !DatabaseAdapter::useDatabase()) {
        return null;
    }

    return DatabaseAdapter::findEmployeeById($id, $companyId);
}

function findDepartmentById($id, $companyId = null) {
    if (!class_exists('DatabaseAdapter') || !DatabaseAdapter::useDatabase()) {
        return null;
    }

    return DatabaseAdapter::findDepartmentById($id, $companyId);
}

function addEmployee($employeeData, $companyId = null) {
    if (!class_exists('DatabaseAdapter') || !DatabaseAdapter::useDatabase()) {
        return ['success' => false, 'error' => 'Database not available'];
    }

    return DatabaseAdapter::addEmployee($employeeData, $companyId);
}

function updateEmployee($id, $employeeData, $companyId = null) {
    if (!class_exists('DatabaseAdapter') || !DatabaseAdapter::useDatabase()) {
        return ['success' => false, 'error' => 'Database not available'];
    }

    return DatabaseAdapter::updateEmployee($id, $employeeData, $companyId);
}

function deleteEmployee($id, $companyId = null) {
    if (!class_exists('DatabaseAdapter') || !DatabaseAdapter::useDatabase()) {
        return ['success' => false, 'error' => 'Database not available'];
    }

    return DatabaseAdapter::deleteEmployee($id, $companyId);
}

function loadTemplates($companyId = null) {
    if (!class_exists('DatabaseAdapter') || !DatabaseAdapter::useDatabase()) {
        return getDefaultTemplatesConfig();
    }

    return DatabaseAdapter::loadTemplates($companyId);
}

function saveTemplates($templates, $companyId = null) {
    if (!class_exists('DatabaseAdapter') || !DatabaseAdapter::useDatabase()) {
        return false;
    }

    return DatabaseAdapter::saveTemplates($templates, $companyId);
}

function getDefaultTemplatesConfig() {
    return [
        'templates' => [],
        'activeFrontId' => null,
        'activeBackId' => null
    ];
}

function getActiveFrontTemplate($companyId = null) {
    $config = loadTemplates($companyId);
    $activeFrontId = $config['activeFrontId'] ?? null;
    
    if (!$activeFrontId || empty($config['templates'])) {
        return null;
    }
    
    foreach ($config['templates'] as $template) {
        if ($template['id'] === $activeFrontId) {
            return $template;
        }
    }
    
    return null;
}

function getActiveBackTemplate($companyId = null) {
    $config = loadTemplates($companyId);
    $activeBackId = $config['activeBackId'] ?? null;
    
    if (!$activeBackId || empty($config['templates'])) {
        return null;
    }
    
    foreach ($config['templates'] as $template) {
        if ($template['id'] === $activeBackId) {
            return $template;
        }
    }
    
    return null;
}

/**
 * Get templates for an employee based on their department or company default
 * @param array $employee Employee data with department_id
 * @param string|null $companyId Company ID
 * @return array ['front' => template, 'back' => template]
 */
function getEmployeeTemplates($employee, $companyId = null) {
    $companyId = $companyId ?? getCurrentCompanyId();
    $db = Database::getInstance();
    
    // Check if employee has a department with a custom template
    if (!empty($employee['department_id'])) {
        $dept = $db->fetchOne(
            "SELECT template_pair_id FROM departments WHERE id = :id AND company_id = :cid",
            ['id' => $employee['department_id'], 'cid' => $companyId]
        );
        
        if ($dept && !empty($dept['template_pair_id'])) {
            // Get templates from this pair
            $frontTemplate = $db->fetchOne(
                "SELECT * FROM templates WHERE pair_id = :pid AND side = 'front'",
                ['pid' => $dept['template_pair_id']]
            );
            $backTemplate = $db->fetchOne(
                "SELECT * FROM templates WHERE pair_id = :pid AND side = 'back'",
                ['pid' => $dept['template_pair_id']]
            );
            
            if ($frontTemplate || $backTemplate) {
                // Parse fields_json for both
                if ($frontTemplate && isset($frontTemplate['fields_json'])) {
                    $frontTemplate['fields'] = json_decode($frontTemplate['fields_json'], true) ?? [];
                }
                if ($backTemplate && isset($backTemplate['fields_json'])) {
                    $backTemplate['fields'] = json_decode($backTemplate['fields_json'], true) ?? [];
                }
                
                return [
                    'front' => $frontTemplate,
                    'back' => $backTemplate,
                    'source' => 'department'
                ];
            }
        }
    }
    
    // Fall back to company default
    return [
        'front' => getActiveFrontTemplate($companyId),
        'back' => getActiveBackTemplate($companyId),
        'source' => 'company'
    ];
}

/**
 * Get employees grouped by department for batch card generation
 * @param string|null $companyId Company ID
 * @return array Departments with their employees
 */
function getEmployeesByDepartment($companyId = null) {
    $companyId = $companyId ?? getCurrentCompanyId();
    $db = Database::getInstance();
    
    // Get all departments
    $departments = $db->fetchAll(
        "SELECT d.*, 
                (SELECT COUNT(*) FROM employees WHERE department_id = d.id) as employee_count
         FROM departments d 
         WHERE d.company_id = :cid 
         ORDER BY d.name",
        ['cid' => $companyId]
    );
    
    // Get employees for each department
    foreach ($departments as &$dept) {
        $dept['employees'] = $db->fetchAll(
            "SELECT * FROM employees WHERE department_id = :did AND company_id = :cid ORDER BY name_en",
            ['did' => $dept['id'], 'cid' => $companyId]
        );
    }
    
    // Get employees without department
    $noDeptEmployees = $db->fetchAll(
        "SELECT * FROM employees WHERE (department_id IS NULL OR department_id = '') AND company_id = :cid ORDER BY name_en",
        ['cid' => $companyId]
    );
    
    if (!empty($noDeptEmployees)) {
        array_unshift($departments, [
            'id' => null,
            'name' => 'No Department',
            'template_pair_id' => null,
            'employee_count' => count($noDeptEmployees),
            'employees' => $noDeptEmployees
        ]);
    }
    
    return $departments;
}

function loadGeneratedLog($companyId = null) {
    if (!class_exists('DatabaseAdapter') || !DatabaseAdapter::useDatabase()) {
        return [];
    }

    return DatabaseAdapter::loadGeneratedLog($companyId);
}

function logGeneratedCard($employeeId, $frontTemplateId, $backTemplateId, $frontFile, $backFile, $pdfFile = null, $companyId = null) {
    if (!class_exists('DatabaseAdapter') || !DatabaseAdapter::useDatabase()) {
        return null;
    }

    return DatabaseAdapter::logGeneratedCard($employeeId, $frontTemplateId, $backTemplateId, $frontFile, $backFile, $pdfFile, $companyId);
}

function deleteGeneratedCard($entryId, $companyId = null) {
    if (!class_exists('DatabaseAdapter') || !DatabaseAdapter::useDatabase()) {
        return false;
    }

    return DatabaseAdapter::deleteGeneratedCard($entryId, $companyId);
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
    $dirs = [DATA_DIR, UPLOADS_DIR, COMPANIES_UPLOADS_DIR, TEMPLATES_DIR, CARDS_DIR, EXCEL_DIR, ASSETS_DIR];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
            // If mkdir fails, try with 0777 (less secure but works)
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
        }
    }
}

/**
 * Initialize data files
 */
function initializeDataFiles() {
    ensureDirectories();
    return true;
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

// loginAdmin() removed, was a legacy function with hardcoded password.
// Use Auth::unifiedLogin() instead.

// Initialize on include
// Only initialize data files if database is configured or we're not redirecting to installer
// Check if we should skip initialization (during installation)
$skipInit = false;
if (defined('DB_HOST') && !empty(DB_HOST) && defined('DB_NAME') && !empty(DB_NAME)) {
    // Database is configured, safe to initialize
    initializeDataFiles();
} else {
    // Database not configured - might be installing, skip initialization to avoid errors
    // initializeDataFiles() will be called after installation
}

/**
 * Get default field settings for business card templates
 * Using Fabric.js compatible properties (pixel positions on 1050x600 canvas)
 */
function getDefaultFieldSettings() {
    return [
        'name_en' => [
            'enabled' => true,
            'x' => 50,              // Pixel position from left
            'y' => 60,              // Pixel position from top
            'fontSize' => 28,
            'fontFamily' => 'Inter',
            'fontWeight' => 'bold',
            'fill' => '#1f2937',    // Fabric.js uses 'fill' for text color
            'color' => '#1f2937',   // Keep for backward compatibility
            'textAlign' => 'left',
            'originX' => 'left',
            'originY' => 'top'
        ],
        'name_ar' => [
            'enabled' => false,
            'x' => 1000,
            'y' => 60,
            'fontSize' => 28,
            'fontFamily' => 'Cairo',
            'fontWeight' => 'bold',
            'fill' => '#1f2937',
            'color' => '#1f2937',
            'textAlign' => 'right',
            'originX' => 'right',
            'originY' => 'top'
        ],
        'position_en' => [
            'enabled' => true,
            'x' => 50,
            'y' => 100,
            'fontSize' => 16,
            'fontFamily' => 'Inter',
            'fontWeight' => 'normal',
            'fill' => '#6b7280',
            'color' => '#6b7280',
            'textAlign' => 'left',
            'originX' => 'left',
            'originY' => 'top'
        ],
        'position_ar' => [
            'enabled' => false,
            'x' => 1000,
            'y' => 100,
            'fontSize' => 16,
            'fontFamily' => 'Cairo',
            'fontWeight' => 'normal',
            'fill' => '#6b7280',
            'color' => '#6b7280',
            'textAlign' => 'right',
            'originX' => 'right',
            'originY' => 'top'
        ],
        'company_en' => [
            'enabled' => true,
            'x' => 50,
            'y' => 130,
            'fontSize' => 14,
            'fontFamily' => 'Inter',
            'fontWeight' => '500',
            'fill' => '#374151',
            'color' => '#374151',
            'textAlign' => 'left',
            'originX' => 'left',
            'originY' => 'top'
        ],
        'company_ar' => [
            'enabled' => false,
            'x' => 1000,
            'y' => 130,
            'fontSize' => 14,
            'fontFamily' => 'Cairo',
            'fontWeight' => '500',
            'fill' => '#374151',
            'color' => '#374151',
            'textAlign' => 'right',
            'originX' => 'right',
            'originY' => 'top'
        ],
        'phone' => [
            'enabled' => true,
            'x' => 50,
            'y' => 450,
            'fontSize' => 14,
            'fontFamily' => 'Inter',
            'fontWeight' => 'normal',
            'fill' => '#374151',
            'color' => '#374151',
            'textAlign' => 'left',
            'originX' => 'left',
            'originY' => 'top'
        ],
        'mobile' => [
            'enabled' => true,
            'x' => 50,
            'y' => 480,
            'fontSize' => 14,
            'fontFamily' => 'Inter',
            'fontWeight' => 'normal',
            'fill' => '#374151',
            'color' => '#374151',
            'textAlign' => 'left',
            'originX' => 'left',
            'originY' => 'top'
        ],
        'email' => [
            'enabled' => true,
            'x' => 50,
            'y' => 510,
            'fontSize' => 14,
            'fontFamily' => 'Inter',
            'fontWeight' => 'normal',
            'fill' => '#374151',
            'color' => '#374151',
            'textAlign' => 'left',
            'originX' => 'left',
            'originY' => 'top'
        ],
        'phone_ar' => [
            'enabled' => false,
            'x' => 1000,
            'y' => 450,
            'fontSize' => 14,
            'fontFamily' => 'Cairo',
            'fontWeight' => 'normal',
            'fill' => '#374151',
            'color' => '#374151',
            'textAlign' => 'right',
            'originX' => 'right',
            'originY' => 'top'
        ],
        'mobile_ar' => [
            'enabled' => false,
            'x' => 1000,
            'y' => 480,
            'fontSize' => 14,
            'fontFamily' => 'Cairo',
            'fontWeight' => 'normal',
            'fill' => '#374151',
            'color' => '#374151',
            'textAlign' => 'right',
            'originX' => 'right',
            'originY' => 'top'
        ],
        'website' => [
            'enabled' => false,
            'x' => 50,
            'y' => 540,
            'fontSize' => 12,
            'fontFamily' => 'Inter',
            'fontWeight' => 'normal',
            'fill' => '#6b7280',
            'color' => '#6b7280',
            'textAlign' => 'left',
            'originX' => 'left',
            'originY' => 'top'
        ],
        'website_ar' => [
            'enabled' => false,
            'x' => 1000,
            'y' => 540,
            'fontSize' => 12,
            'fontFamily' => 'Cairo',
            'fontWeight' => 'normal',
            'fill' => '#6b7280',
            'color' => '#6b7280',
            'textAlign' => 'right',
            'originX' => 'right',
            'originY' => 'top'
        ],
        'address' => [
            'enabled' => false,
            'x' => 50,
            'y' => 560,
            'fontSize' => 11,
            'fontFamily' => 'Inter',
            'fontWeight' => 'normal',
            'fill' => '#6b7280',
            'color' => '#6b7280',
            'textAlign' => 'left',
            'originX' => 'left',
            'originY' => 'top'
        ],
        'address_en' => [
            'enabled' => false,
            'x' => 50,
            'y' => 560,
            'fontSize' => 11,
            'fontFamily' => 'Inter',
            'fontWeight' => 'normal',
            'fill' => '#6b7280',
            'color' => '#6b7280',
            'textAlign' => 'left',
            'originX' => 'left',
            'originY' => 'top'
        ],
        'address_ar' => [
            'enabled' => false,
            'x' => 1000,
            'y' => 560,
            'fontSize' => 11,
            'fontFamily' => 'Cairo',
            'fontWeight' => 'normal',
            'fill' => '#6b7280',
            'color' => '#6b7280',
            'textAlign' => 'right',
            'originX' => 'right',
            'originY' => 'top'
        ],
        'qr_code' => [
            'enabled' => true,
            'x' => 880,
            'y' => 420,
            'size' => 140
        ]
    ];
}

/**
 * Convert legacy percentage-based field positions to pixel positions
 * For backward compatibility with existing templates
 * 
 * @param array $fields Field settings
 * @param int $canvasWidth Canvas width (default 1050)
 * @param int $canvasHeight Canvas height (default 600)
 * @return array Converted field settings
 */
function convertLegacyFieldPositions($fields, $canvasWidth = 1050, $canvasHeight = 600, $fieldsFormat = null) {
    if (empty($fields)) return $fields;

    // Modern templates carry an explicit format marker; never run the percentage
    // heuristic on them. The heuristic cannot distinguish a real small-pixel
    // coordinate from a legacy percentage (e.g. y=45px vs y=45%) and would
    // multiply legitimate pixel positions by the canvas size on every reload.
    $isLegacy = ($fieldsFormat !== 'px');

    $converted = [];
    foreach ($fields as $key => $field) {
        $converted[$key] = $field;

        if ($isLegacy) {
            $x = $field['x'] ?? 0;
            $y = $field['y'] ?? 0;

            if ($x <= 100 && $y <= 100 && ($x < 50 || $y < 50 || $x > 80 || $y > 80)) {
                if ($x <= 100) {
                    $converted[$key]['x'] = round(($x / 100) * $canvasWidth);
                }
                if ($y <= 100) {
                    $converted[$key]['y'] = round(($y / 100) * $canvasHeight);
                }
            }
        }
        
        // Ensure fill property exists (Fabric.js compatibility)
        if (isset($field['color']) && !isset($field['fill'])) {
            $converted[$key]['fill'] = $field['color'];
        }
        
        // Add default fontFamily if missing
        if (!isset($converted[$key]['fontFamily']) && $key !== 'qr_code') {
            $isArabic = strpos($key, '_ar') !== false;
            $converted[$key]['fontFamily'] = $isArabic ? 'Cairo' : 'Inter';
        }
        
        // Add origin properties if missing
        if (!isset($converted[$key]['originX']) && $key !== 'qr_code') {
            $isArabic = strpos($key, '_ar') !== false;
            $converted[$key]['originX'] = $isArabic ? 'right' : 'left';
            $converted[$key]['originY'] = 'top';
        }
    }
    
    return $converted;
}

/**
 * Handle file upload with validation
 * 
 * @param array $file The $_FILES array element
 * @param string $destination Directory to save the file
 * @param array $allowedTypes Allowed MIME types
 * @param int $maxSize Maximum file size in bytes
 * @return array Result with 'success', 'path' or 'error'
 */
function handleFileUpload($file, $destination, $allowedTypes = null, $maxSize = 10485760) {
    // Default allowed types for images
    if ($allowedTypes === null) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    }
    
    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'Upload stopped by extension',
        ];
        return ['success' => false, 'error' => $errors[$file['error']] ?? 'Unknown upload error'];
    }
    
    // Validate file size
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'error' => 'File size exceeds ' . ($maxSize / 1048576) . 'MB limit'];
    }
    
    // Validate MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    // finfo often misreports SVG as text/xml, application/xml, text/html, or text/plain.
    // If the file extension is .svg and the content looks like SVG, normalize to image/svg+xml.
    $clientExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($clientExt === 'svg' && in_array($mimeType, ['text/xml', 'application/xml', 'text/html', 'text/plain', 'image/svg+xml'], true)) {
        $head = @file_get_contents($file['tmp_name'], false, null, 0, 2048);
        if ($head !== false && preg_match('/<svg[\s>]/i', $head)) {
            $mimeType = 'image/svg+xml';
        }
    }

    if (!in_array($mimeType, $allowedTypes)) {
        return ['success' => false, 'error' => 'File type not allowed: ' . $mimeType];
    }
    
    // Create destination directory if it doesn't exist
    if (!is_dir($destination)) {
        if (!mkdir($destination, 0755, true)) {
            return ['success' => false, 'error' => 'Failed to create upload directory'];
        }
    }
    
    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    if (empty($extension)) {
        // Determine extension from MIME type
        $mimeExtensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            'image/svg+xml' => 'svg',
        ];
        $extension = $mimeExtensions[$mimeType] ?? 'bin';
    }
    
    $filename = uniqid('template_', true) . '.' . strtolower($extension);
    $filepath = rtrim($destination, '/') . '/' . $filename;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => false, 'error' => 'Failed to move uploaded file'];
    }
    
    // Set permissions
    chmod($filepath, 0644);
    
    // Handle PDF files - keep original for vector export
    $originalPdfPath = null;
    $isPdf = ($mimeType === 'application/pdf');
    
    if ($isPdf) {
        // Keep the original PDF for vector exports
        $originalPdfPath = $filepath;
        
        // Also create a PNG preview for the editor canvas
        $pngFilename = uniqid('template_', true) . '.png';
        $pngPath = rtrim($destination, '/') . '/' . $pngFilename;
        
        // Convert at high DPI (600) for preview
        $converted = convertPdfToImage($filepath, $pngPath, 0, 600);
        
        if ($converted && file_exists($pngPath)) {
            // PNG created successfully - return PNG for display, keep original PDF
            return [
                'success' => true, 
                'path' => $pngPath,
                'filename' => $pngFilename,
                'originalPdf' => $originalPdfPath,
                'isPdf' => true
            ];
        } else {
            // PNG conversion failed - return the PDF path (client will need to handle)
            // This is a fallback, ideally ImageMagick should be installed
            error_log("PDF to PNG conversion failed for: $filepath. Install ImageMagick for better quality.");
            return [
                'success' => true, 
                'path' => $filepath,
                'filename' => $filename,
                'originalPdf' => $originalPdfPath,
                'isPdf' => true,
                'conversionFailed' => true
            ];
        }
    }
    
    return [
        'success' => true, 
        'path' => $filepath, 
        'filename' => $filename,
        'originalPdf' => null,
        'isPdf' => false
    ];
}

/**
 * Convert PDF to image using Ghostscript or ImageMagick
 * Ghostscript is preferred as ImageMagick often has PDF security policy restrictions
 */
function convertPdfToImage($pdfPath, $outputPath, $page = 0, $dpi = 300) {
    // Try Ghostscript FIRST (more reliable for PDFs, no security policy issues)
    $gsCmd = null;
    exec("which gs 2>/dev/null", $gsOutput, $gsCode);
    if ($gsCode === 0 && !empty($gsOutput[0])) {
        $gsCmd = trim($gsOutput[0]);
    }
    
    if ($gsCmd) {
        $output = [];
        $cmd = sprintf(
            '%s -dSAFER -dBATCH -dNOPAUSE -dNumRenderingThreads=4 -sDEVICE=png16m -r%d -dFirstPage=%d -dLastPage=%d -dTextAlphaBits=4 -dGraphicsAlphaBits=4 -sOutputFile="%s" "%s" 2>&1',
            $gsCmd, $dpi, $page + 1, $page + 1, $outputPath, $pdfPath
        );
        exec($cmd, $output, $returnCode);
        
        if ($returnCode === 0 && file_exists($outputPath)) {
            return true;
        }
        error_log("Ghostscript conversion failed (code $returnCode): " . implode("\n", $output));
    }
    
    // Try PHP Imagick extension (may fail due to security policy)
    if (extension_loaded('imagick')) {
        try {
            $imagick = new Imagick();
            $imagick->setResolution($dpi, $dpi);
            $imagick->readImage($pdfPath . '[' . $page . ']');
            $imagick->setImageFormat('png');
            $imagick->setImageCompressionQuality(95);
            $imagick->writeImage($outputPath);
            $imagick->clear();
            $imagick->destroy();
            return true;
        } catch (Exception $e) {
            error_log("Imagick PDF conversion failed: " . $e->getMessage());
        }
    }
    
    // Try command-line ImageMagick convert (may fail due to security policy)
    $convertCmd = null;
    exec("which convert 2>/dev/null", $whichOutput, $whichCode);
    if ($whichCode === 0 && !empty($whichOutput[0])) {
        $convertCmd = trim($whichOutput[0]);
    }
    
    if ($convertCmd) {
        $output = [];
        $cmd = sprintf(
            '%s -density %d "%s[%d]" -quality 95 "%s" 2>&1',
            $convertCmd, $dpi, $pdfPath, $page, $outputPath
        );
        exec($cmd, $output, $returnCode);
        
        if ($returnCode === 0 && file_exists($outputPath)) {
            return true;
        }
        error_log("ImageMagick convert failed (code $returnCode): " . implode("\n", $output));
    }
    
    return false;
}

/**
 * Generate a unique template ID from name
 * 
 * @param string $name Template name
 * @return string Unique template ID
 */
function generateTemplateId($name) {
    // Create slug from name
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');

    // Add unique suffix
    $uniqueSuffix = substr(uniqid(), -6);

    return $slug . '-' . $uniqueSuffix;
}

/**
 * Format an OMR amount in the user's currently-selected display currency.
 * This is the one-liner used at every price render site.
 *
 *   echo formatPrice(5.400);  // "5.000 OMR" or "47.72 AED" depending on user
 *
 * Returns HTML-safe plain text. No HTML wrapping.
 */
if (!function_exists('formatPrice')) {
    function formatPrice(float $omrAmount): string {
        static $loaded = false;
        if (!$loaded) {
            require_once __DIR__ . '/Currency.php';
            $loaded = true;
        }
        $cur = Currency::getUserCurrency();
        $amt = Currency::convert($omrAmount, $cur);
        return Currency::format($amt, $cur);
    }
}

/**
 * Seed a starter template pair (front + back) for a company.
 * Idempotent: no-op if the company already has any templates.
 * Shared between api/onboarding.php and company/register.php so
 * every new signup lands on a dashboard with a usable template.
 */
if (!function_exists('seedStarterTemplate')) {
    function seedStarterTemplate($db, $companyId, $templateKey = 'bhd-classic') {
        try {
            $existing = $db->fetchOne(
                "SELECT COUNT(*) as cnt FROM templates WHERE company_id = :id",
                ['id' => $companyId]
            );
            if (($existing['cnt'] ?? 0) > 0) return;

            $templates = getBhdTemplateDefinitions();
            if (!isset($templates[$templateKey])) {
                $templateKey = 'bhd-classic';
            }

            $def = $templates[$templateKey];
            $pairId = generateUUID();
            $now = date('Y-m-d H:i:s');

            $db->insert('templates', [
                'id'                    => generateUUID(),
                'company_id'            => $companyId,
                'pair_id'               => $pairId,
                'name'                  => $def['name'] . ' (Front)',
                'side'                  => 'front',
                'background_image_path' => '',
                'fields_json'           => json_encode($def['front_fields']),
                'settings_json'         => json_encode($def['settings']),
                'is_active'             => 1,
                'is_shared'             => 0,
                'created_at'            => $now,
                'updated_at'            => $now,
            ]);

            $db->insert('templates', [
                'id'                    => generateUUID(),
                'company_id'            => $companyId,
                'pair_id'               => $pairId,
                'name'                  => $def['name'] . ' (Back)',
                'side'                  => 'back',
                'background_image_path' => '',
                'fields_json'           => json_encode($def['back_fields']),
                'settings_json'         => json_encode($def['settings']),
                'is_active'             => 1,
                'is_shared'             => 0,
                'created_at'            => $now,
                'updated_at'            => $now,
            ]);
        } catch (Exception $e) {
            error_log('[seedStarterTemplate] failed for company ' . $companyId . ': ' . $e->getMessage());
        }
    }
}

if (!function_exists('getBhdTemplateDefinitions')) {
    function getBhdTemplateDefinitions() {
        $standardSettings = [
            'cardSize'        => 'standard',
            'cardOrientation' => 'landscape',
            'dpi'             => 300,
            'bleedEnabled'    => false,
            'bleedSize'       => 3,
            'bleedUnit'       => 'mm',
        ];

        $classicFront = [
            'name_en' => [
                'enabled' => true, 'x' => 60, 'y' => 70,
                'fontSize' => 26, 'fontFamily' => 'Inter', 'fontWeight' => 'bold',
                'fill' => '#1f2937', 'textAlign' => 'left', 'originX' => 'left', 'originY' => 'top'
            ],
            'position_en' => [
                'enabled' => true, 'x' => 60, 'y' => 108,
                'fontSize' => 14, 'fontFamily' => 'Inter', 'fontWeight' => 'normal',
                'fill' => '#009bc1', 'textAlign' => 'left', 'originX' => 'left', 'originY' => 'top'
            ],
            'company_en' => [
                'enabled' => true, 'x' => 60, 'y' => 135,
                'fontSize' => 12, 'fontFamily' => 'Inter', 'fontWeight' => 'normal',
                'fill' => '#6b7280', 'textAlign' => 'left', 'originX' => 'left', 'originY' => 'top'
            ],
            'phone' => [
                'enabled' => true, 'x' => 60, 'y' => 175,
                'fontSize' => 12, 'fontFamily' => 'Inter', 'fontWeight' => 'normal',
                'fill' => '#374151', 'textAlign' => 'left', 'originX' => 'left', 'originY' => 'top'
            ],
            'email' => [
                'enabled' => true, 'x' => 60, 'y' => 198,
                'fontSize' => 12, 'fontFamily' => 'Inter', 'fontWeight' => 'normal',
                'fill' => '#374151', 'textAlign' => 'left', 'originX' => 'left', 'originY' => 'top'
            ],
            'website' => [
                'enabled' => true, 'x' => 60, 'y' => 221,
                'fontSize' => 12, 'fontFamily' => 'Inter', 'fontWeight' => 'normal',
                'fill' => '#009bc1', 'textAlign' => 'left', 'originX' => 'left', 'originY' => 'top'
            ],
        ];

        $classicBack = [
            'company_en' => [
                'enabled' => true, 'x' => 500, 'y' => 130,
                'fontSize' => 22, 'fontFamily' => 'Inter', 'fontWeight' => 'bold',
                'fill' => '#1f2937', 'textAlign' => 'center', 'originX' => 'center', 'originY' => 'top'
            ],
            'website' => [
                'enabled' => true, 'x' => 500, 'y' => 165,
                'fontSize' => 12, 'fontFamily' => 'Inter', 'fontWeight' => 'normal',
                'fill' => '#009bc1', 'textAlign' => 'center', 'originX' => 'center', 'originY' => 'top'
            ],
        ];

        $navyFront = array_merge($classicFront, [
            'name_en' => array_merge($classicFront['name_en'], ['fill' => '#ffffff']),
            'position_en' => array_merge($classicFront['position_en'], ['fill' => '#009bc1']),
            'company_en' => array_merge($classicFront['company_en'], ['fill' => '#94a3b8']),
            'phone' => array_merge($classicFront['phone'], ['fill' => '#cbd5e1']),
            'email' => array_merge($classicFront['email'], ['fill' => '#cbd5e1']),
            'website' => array_merge($classicFront['website'], ['fill' => '#38bdf8']),
        ]);

        $skyFront = array_merge($classicFront, [
            'name_en' => array_merge($classicFront['name_en'], ['fill' => '#ffffff']),
            'position_en' => array_merge($classicFront['position_en'], ['fill' => '#e0f7ff']),
            'company_en' => array_merge($classicFront['company_en'], ['fill' => '#cceeff']),
            'phone' => array_merge($classicFront['phone'], ['fill' => '#ffffff']),
            'email' => array_merge($classicFront['email'], ['fill' => '#ffffff']),
            'website' => array_merge($classicFront['website'], ['fill' => '#ffffff']),
        ]);

        return [
            'bhd-classic' => [
                'name'         => 'BHD Classic',
                'front_fields' => $classicFront,
                'back_fields'  => $classicBack,
                'settings'     => array_merge($standardSettings, ['backgroundColor' => '#ffffff']),
            ],
            'bhd-navy' => [
                'name'         => 'BHD Navy',
                'front_fields' => $navyFront,
                'back_fields'  => array_merge($classicBack, [
                    'company_en' => array_merge($classicBack['company_en'], ['fill' => '#ffffff']),
                    'website' => array_merge($classicBack['website'], ['fill' => '#38bdf8']),
                ]),
                'settings'     => array_merge($standardSettings, ['backgroundColor' => '#0f172a']),
            ],
            'bhd-sky' => [
                'name'         => 'BHD Sky',
                'front_fields' => $skyFront,
                'back_fields'  => array_merge($classicBack, [
                    'company_en' => array_merge($classicBack['company_en'], ['fill' => '#ffffff']),
                    'website' => array_merge($classicBack['website'], ['fill' => '#ffffff']),
                ]),
                'settings'     => array_merge($standardSettings, ['backgroundColor' => '#009bc1']),
            ],
        ];
    }
}
