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
     * Unified login - detects user type and logs them in
     */
    public static function login($email, $password, $companySlug = null) {
        self::init();
        
        if (!self::$db->isConnected()) {
            return ['success' => false, 'error' => 'Database not connected'];
        }
        
        // Try to find user in users table first (unified auth)
        $user = self::$db->fetchOne(
            "SELECT * FROM users WHERE email = :email AND status = 'active'",
            ['email' => sanitizeEmail($email)]
        );
        
        if ($user && password_verify($password, $user['password_hash'])) {
            // User found in unified users table
            return self::loginUser($user);
        }
        
        // Fallback: Try company login (legacy)
        if ($companySlug) {
            $company = self::$db->fetchOne(
                "SELECT * FROM companies WHERE slug = :slug AND status = 'active'",
                ['slug' => $companySlug]
            );
            
            if ($company && password_verify($password, $company['password_hash'])) {
                // Check if email matches company admin email
                if (strtolower($company['admin_email']) === strtolower($email)) {
                    return self::loginCompany($company);
                }
            }
        }
        
        return ['success' => false, 'error' => 'Invalid credentials'];
    }
    
    /**
     * Login user from users table
     */
    private static function loginUser($user) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_role'] = $user['role'];
        
        if ($user['company_id']) {
            $_SESSION['company_id'] = $user['company_id'];
            $company = self::$db->fetchOne(
                "SELECT * FROM companies WHERE id = :id",
                ['id' => $user['company_id']]
            );
            if ($company) {
                $_SESSION['company_slug'] = $company['slug'];
                $_SESSION['company_name'] = $company['name'];
            }
        }
        
        // Update last login
        self::$db->update('users',
            ['last_login_at' => date('Y-m-d H:i:s')],
            'id = :id',
            ['id' => $user['id']]
        );
        
        return [
            'success' => true,
            'user' => $user,
            'redirect' => self::getRedirectUrl($user['role'])
        ];
    }
    
    /**
     * Login company (legacy support)
     */
    private static function loginCompany($company) {
        $_SESSION['company_id'] = $company['id'];
        $_SESSION['company_slug'] = $company['slug'];
        $_SESSION['company_name'] = $company['name'];
        $_SESSION['user_role'] = 'company';
        $_SESSION['user_email'] = $company['admin_email'];
        
        return [
            'success' => true,
            'company' => $company,
            'redirect' => getBasePath() . 'admin/'
        ];
    }
    
    /**
     * Get redirect URL based on role
     */
    private static function getRedirectUrl($role) {
        switch ($role) {
            case 'super_admin':
                return getBasePath() . 'admin/super/';
            case 'admin':
                return getBasePath() . 'admin/';
            case 'company':
                return getBasePath() . 'admin/';
            case 'employee':
                return getBasePath();
            default:
                return getBasePath() . 'admin/';
        }
    }
    
    /**
     * Check if user is logged in
     */
    public static function isLoggedIn() {
        return isset($_SESSION['user_id']) || isset($_SESSION['company_id']);
    }
    
    /**
     * Get current user role
     */
    public static function getCurrentRole() {
        return $_SESSION['user_role'] ?? null;
    }
    
    /**
     * Check if user has specific role
     */
    public static function hasRole($role) {
        $currentRole = self::getCurrentRole();
        if ($role === 'super_admin') {
            return $currentRole === 'super_admin';
        }
        if ($role === 'admin') {
            return in_array($currentRole, ['super_admin', 'admin', 'company']);
        }
        return $currentRole === $role;
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
     * Get current user
     */
    public static function getCurrentUser() {
        if (!self::isLoggedIn()) {
            return null;
        }
        
        self::init();
        
        if (isset($_SESSION['user_id'])) {
            return self::$db->fetchOne(
                "SELECT * FROM users WHERE id = :id",
                ['id' => $_SESSION['user_id']]
            );
        }
        
        // Legacy company session
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
        session_unset();
        session_destroy();
        session_start();
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
