<?php
/**
 * Unified Authentication System
 * Handles login for super admin, company admin, and employees
 */
class Auth {
    private static $db = null;
    
    public static function init() {
        if (self::$db === null) {
            self::$db = Database::getInstance();
        }
    }
    
    /**
     * Unified login - auto-detects user type and logs them in
     * Flow: users table -> employees table -> company admins -> not found
     */
    public static function unifiedLogin($email, $password) {
        self::init();

        if (!self::$db || !self::$db->isConnected()) {
            return ['success' => false, 'error' => 'Database not connected'];
        }

        $email = sanitizeEmail($email);

        // Brute force protection — persistent per-IP counter in the DB so it
        // survives cookie clears and new browser sessions. 10 attempts per
        // 15-minute rolling bucket, fail-open if the limiter backend is down.
        require_once __DIR__ . '/RateLimiter.php';
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (!RateLimiter::check('login_attempt', $ip, 10, 900)) {
            return ['success' => false, 'error' => 'Too many login attempts. Please try again in 15 minutes.'];
        }
        
        // Step 1: Check users table (super_admin, admin, company roles)
        $user = self::$db->fetchOne(
            "SELECT * FROM users WHERE email = :email AND status = 'active'",
            ['email' => $email]
        );
        
        if ($user) {
            if (password_verify($password, $user['password_hash'])) {
                return self::loginUser($user);
            }
            return ['success' => false, 'error' => 'Invalid email or password'];
        }

        // Step 2: Check employees table
        $employee = self::$db->fetchOne(
            "SELECT e.*, c.slug as company_slug, c.name as company_name
             FROM employees e
             JOIN companies c ON e.company_id = c.id
             WHERE e.email = :email AND e.status = 'active' AND c.status = 'active'",
            ['email' => $email]
        );

        if ($employee) {
            // Check employee password (if they have one set)
            if (!empty($employee['password_hash'])) {
                if (password_verify($password, $employee['password_hash'])) {
                    return self::loginEmployee($employee);
                }
                return ['success' => false, 'error' => 'Invalid email or password'];
            }
            // Employee exists but has no password - need to set one up
            return ['success' => false, 'error' => 'Please contact your administrator to set up your password'];
        }

        // Step 3: Check company admin_email (legacy companies table login)
        $company = self::$db->fetchOne(
            "SELECT * FROM companies WHERE admin_email = :email AND status = 'active'",
            ['email' => $email]
        );

        if ($company) {
            if (!empty($company['password_hash']) && password_verify($password, $company['password_hash'])) {
                return self::loginCompany($company);
            }
            return ['success' => false, 'error' => 'Invalid email or password'];
        }

        // Email not found — return same generic error to prevent user enumeration
        return ['success' => false, 'error' => 'Invalid email or password'];
    }
    
    /**
     * Legacy login method - kept for backward compatibility
     */
    public static function login($email, $password, $companySlug = null) {
        // Use unified login for better detection
        return self::unifiedLogin($email, $password);
    }
    
    /**
     * Login employee
     */
    private static function loginEmployee($employee) {
        // Regenerate session ID to prevent session fixation attacks
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            unset($_SESSION['login_attempts_' . md5($ip)]);
        }
        $_SESSION['user_id'] = $employee['id'];
        $_SESSION['user_email'] = $employee['email'];
        $_SESSION['user_name'] = $employee['name_en'] ?? $employee['name'] ?? $employee['email'];
        $_SESSION['user_role'] = 'employee';
        $_SESSION['user_company_id'] = $employee['company_id'];
        $_SESSION['company_id'] = $employee['company_id'];
        $_SESSION['company_slug'] = $employee['company_slug'];
        $_SESSION['company_name'] = $employee['company_name'];
        $_SESSION['employee_id'] = $employee['id'];
        
        // Update last login if column exists
        try {
            self::$db->update('employees',
                ['updated_at' => date('Y-m-d H:i:s')],
                'id = :id',
                ['id' => $employee['id']]
            );
        } catch (Exception $e) {
            // Column might not exist, ignore
        }
        
        // Audit log
        if (class_exists('AuditLog')) {
            AuditLog::log('login', 'employee', $employee['id'], null, ['email' => $employee['email']], $employee['company_id']);
        }
        
        return [
            'success' => true,
            'user' => $employee,
            'redirect' => getTenantUrl($employee['company_slug'] ?? null)
        ];
    }
    
    /**
     * Check if email exists in any table
     * Returns: array with 'exists' => bool, 'type' => string (user|employee|company|null)
     */
    public static function emailExists($email) {
        self::init();
        
        if (!self::$db || !self::$db->isConnected()) {
            return ['exists' => false, 'type' => null];
        }
        
        $email = sanitizeEmail($email);
        
        // Check users table
        $user = self::$db->fetchOne(
            "SELECT id, role FROM users WHERE email = :email",
            ['email' => $email]
        );
        if ($user) {
            return ['exists' => true, 'type' => 'user', 'role' => $user['role']];
        }
        
        // Check employees table
        $employee = self::$db->fetchOne(
            "SELECT id, company_id FROM employees WHERE email = :email",
            ['email' => $email]
        );
        if ($employee) {
            return ['exists' => true, 'type' => 'employee', 'company_id' => $employee['company_id']];
        }
        
        // Check companies table (admin_email)
        $company = self::$db->fetchOne(
            "SELECT id, slug FROM companies WHERE admin_email = :email",
            ['email' => $email]
        );
        if ($company) {
            return ['exists' => true, 'type' => 'company', 'company_id' => $company['id']];
        }
        
        return ['exists' => false, 'type' => null];
    }
    
    /**
     * Login user from users table
     */
    public static function loginUser($user) {
        // Regenerate session ID to prevent session fixation attacks
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            // Reset brute force counter on successful login
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            unset($_SESSION['login_attempts_' . md5($ip)]);
        }
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_company_id'] = $user['company_id'] ?? null;
        
        $companySlug = null;
        if ($user['company_id']) {
            $_SESSION['company_id'] = $user['company_id'];
            $company = self::$db->fetchOne(
                "SELECT * FROM companies WHERE id = :id",
                ['id' => $user['company_id']]
            );
            if ($company) {
                $_SESSION['company_slug'] = $company['slug'];
                $_SESSION['company_name'] = $company['name'];
                $companySlug = $company['slug'];
            }
        }
        
        // Update last login
        self::$db->update('users',
            ['last_login_at' => date('Y-m-d H:i:s')],
            'id = :id',
            ['id' => $user['id']]
        );
        
        // Audit log
        if (class_exists('AuditLog')) {
            AuditLog::log('login', 'user', $user['id'], null, ['email' => $user['email'], 'role' => $user['role']], $user['company_id']);
        }
        
        return [
            'success' => true,
            'user' => $user,
            'redirect' => self::getRedirectUrl($user['role'], $companySlug)
        ];
    }
    
    /**
     * Login company (legacy support)
     */
    private static function loginCompany($company) {
        // Regenerate session ID to prevent session fixation attacks
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            unset($_SESSION['login_attempts_' . md5($ip)]);
        }
        // Set user_id using company id prefixed to distinguish from user table ids
        $_SESSION['user_id'] = 'company_' . $company['id'];
        $_SESSION['user_company_id'] = $company['id'];
        $_SESSION['company_id'] = $company['id'];
        $_SESSION['company_slug'] = $company['slug'];
        $_SESSION['company_name'] = $company['name'];
        $_SESSION['user_role'] = 'company_admin';
        $_SESSION['user_email'] = $company['admin_email'];
        $_SESSION['user_name'] = $company['name'] ?? 'Admin';
        
        // Audit log
        if (class_exists('AuditLog')) {
            AuditLog::log('login', 'company', $company['id'], null, ['email' => $company['admin_email']], $company['id']);
        }
        
        return [
            'success' => true,
            'company' => $company,
            'redirect' => getTenantUrl($company['slug'] ?? null, '/admin/')
        ];
    }
    
    /**
     * Get redirect URL based on role
     * @param string $role User role
     * @param string|null $companySlug Company slug for portal redirect
     */
    private static function getRedirectUrl($role, $companySlug = null) {
        switch ($role) {
            case 'super_admin':
                return getBasePath() . 'admin/';
            case 'print_shop':
                // Redirect print shops to their dashboard
                return getBasePath() . 'printshop/dashboard.php';
            case 'company_admin':
            case 'admin':
            case 'company':
                if ($companySlug) {
                    return getTenantUrl($companySlug, '/admin/');
                }
                return getBasePath() . 'admin/';
            case 'employee':
                if ($companySlug) {
                    return getTenantUrl($companySlug);
                }
                return getBasePath();
            default:
                return getBasePath() . 'admin/';
        }
    }
    
    /**
     * Check if user is logged in
     * Only checks for user_id which is only set on successful authentication
     */
    public static function isLoggedIn() {
        return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    }
    
    /**
     * Get current user role
     */
    public static function getCurrentRole() {
        return $_SESSION['user_role'] ?? null;
    }
    
    /**
     * Check if user has specific role (supports string or array of roles)
     * @param string|array $role Single role string or array of roles (any match = true)
     */
    public static function hasRole($role) {
        $currentRole = self::getCurrentRole();
        
        // Support array of roles: return true if user has any of them
        if (is_array($role)) {
            foreach ($role as $r) {
                if (self::hasRole($r)) {
                    return true;
                }
            }
            return false;
        }
        
        if ($role === 'super_admin') {
            return $currentRole === 'super_admin';
        }
        if ($role === 'admin') {
            return in_array($currentRole, ['super_admin', 'admin', 'company', 'company_admin']);
        }
        if ($role === 'company_admin') {
            return in_array($currentRole, ['super_admin', 'admin', 'company', 'company_admin']);
        }
        return $currentRole === $role;
    }
    
    /**
     * Check if current user is a super admin
     */
    public static function isSuperAdmin() {
        return self::hasRole('super_admin');
    }
    
    /**
     * Require specific role
     */
    public static function requireRole($role) {
        if (!self::hasRole($role)) {
            header('Location: ' . getBasePath() . 'login.php');
            exit;
        }
    }
    
    /**
     * Require user to be logged in (any role)
     */
    public static function requireLogin() {
        if (!self::isLoggedIn()) {
            header('Location: ' . getBasePath() . 'login.php');
            exit;
        }
    }
    
    /**
     * Get current user
     * Handles employees (user_id = employee ID), legacy company admins (user_id = company_X),
     * and regular users (user_id = users table ID).
     */
    public static function getCurrentUser() {
        if (!self::isLoggedIn()) {
            return null;
        }
        
        self::init();
        
        $userId = $_SESSION['user_id'] ?? null;
        $userRole = $_SESSION['user_role'] ?? null;
        
        // Case 1: Employee — user_id holds the employee's ID
        if ($userRole === 'employee' && $userId) {
            $employee = self::$db->fetchOne(
                "SELECT e.*, c.slug as company_slug, c.name as company_name 
                 FROM employees e 
                 LEFT JOIN companies c ON e.company_id = c.id 
                 WHERE e.id = :id",
                ['id' => $userId]
            );
            if ($employee) {
                return [
                    'id' => $employee['id'],
                    'email' => $employee['email'],
                    'name' => $employee['name_en'] ?? $employee['name'] ?? $employee['email'],
                    'role' => 'employee',
                    'company_id' => $employee['company_id'],
                    'company_slug' => $employee['company_slug'] ?? null,
                    'company_name' => $employee['company_name'] ?? null,
                    'employee' => $employee
                ];
            }
        }
        
        // Case 2: Legacy company admin — user_id is "company_X"
        if ($userId && is_string($userId) && strpos($userId, 'company_') === 0) {
            $companyId = substr($userId, 8); // strip "company_" prefix
            $company = self::$db->fetchOne(
                "SELECT * FROM companies WHERE id = :id",
                ['id' => $companyId]
            );
            if ($company) {
                return [
                    'id' => $company['id'],
                    'email' => $company['admin_email'],
                    'name' => $company['name'],
                    'role' => 'company_admin',
                    'company_id' => $company['id'],
                    'company_slug' => $company['slug'] ?? null,
                    'company_name' => $company['name'] ?? null
                ];
            }
        }
        
        // Case 3: Regular user from users table
        if ($userId) {
            $user = self::$db->fetchOne(
                "SELECT * FROM users WHERE id = :id",
                ['id' => $userId]
            );
            if ($user) {
                return $user;
            }
        }
        
        // Fallback: Legacy company session without user_id prefix
        if (isset($_SESSION['company_id'])) {
            $company = self::$db->fetchOne(
                "SELECT * FROM companies WHERE id = :id",
                ['id' => $_SESSION['company_id']]
            );
            if ($company) {
                return [
                    'id' => $company['id'],
                    'email' => $company['admin_email'],
                    'name' => $company['name'],
                    'role' => 'company',
                    'company_id' => $company['id']
                ];
            }
        }
        
        return null;
    }
    
    /**
     * Logout
     */
    public static function logout() {
        // Clear impersonation stash explicitly so it never survives logout.
        if (isset($_SESSION['impersonator'])) {
            unset($_SESSION['impersonator']);
        }
        session_unset();
        session_destroy();
    }
    
    /**
     * Create user
     */
    public static function createUser($email, $password, $name, $role = 'company', $companyId = null) {
        self::init();
        
        // Check if user exists
        $existing = self::$db->fetchOne(
            "SELECT id FROM users WHERE email = :email",
            ['email' => sanitizeEmail($email)]
        );
        
        if ($existing) {
            return ['success' => false, 'error' => 'User already exists'];
        }
        
        $userId = generateUUID();
        $result = self::$db->insert('users', [
            'id' => $userId,
            'email' => sanitizeEmail($email),
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'name' => $name,
            'role' => $role,
            'company_id' => $companyId,
            'status' => 'active'
        ]);
        
        if ($result) {
            return [
                'success' => true,
                'user_id' => $userId
            ];
        }
        
        return ['success' => false, 'error' => 'Failed to create user'];
    }
}

// Initialize on include
Auth::init();
