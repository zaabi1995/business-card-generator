<?php
/**
 * Complete Installation Wizard
 * Handles full setup: database, site config, billing, admin account
 * 
 * SECURITY: This page can ONLY be accessed ONCE. After installation is complete,
 * this page is permanently locked and will redirect to the homepage.
 * To reinstall, you must manually delete the config.php file and drop the database tables.
 */
session_start();

// Reinstall flag (kept for legacy UI hints only)
$reinstall = isset($_GET['reinstall']) && $_GET['reinstall'] === '1';

// Calculate base path for redirects
$scriptPath = $_SERVER['PHP_SELF'] ?? $_SERVER['SCRIPT_NAME'] ?? '/install/index.php';
$basePath = str_replace('/install/index.php', '', $scriptPath);
$basePath = str_replace('/install/', '', $basePath);
$basePath = rtrim($basePath, '/');
if (empty($basePath)) $basePath = '';
require_once __DIR__ . '/../includes/installer-layout.php';

// STRICT CHECK: If installation is complete, BLOCK access completely - no exceptions
if (file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../includes/Database.php';
    require_once __DIR__ . '/../config.php';
    
    if (defined('DB_HOST') && !empty(DB_HOST)) {
        try {
            $db = Database::getInstance();
            if ($db->isConnected() || $db->connect(DB_HOST, DB_NAME, DB_USER, DB_PASS ?? '', DB_PORT ?? '3306', DB_TYPE ?? 'mysql')) {
                // Check if installation_complete flag is set
                $setting = $db->fetchOne("SELECT setting_value FROM system_settings WHERE setting_key = 'installation_complete'");
                if ($setting && $setting['setting_value'] === '1') {
                    // Installation is COMPLETE - BLOCK ACCESS PERMANENTLY
                    ?>
                    <?php
                    installerHeader(
                        'Installation Complete | Cardify',
                        $basePath,
                        '<meta http-equiv="refresh" content="5;url=' . $basePath . '/">',
                        'min-h-screen flex items-center justify-center bg-gradient-to-br from-blue-50 to-gray-100'
                    );
                    ?>
    <div class="bg-white rounded-2xl shadow-xl border border-gray-200 p-10 max-w-md text-center">
        <div class="w-20 h-20 rounded-full bg-green-100 flex items-center justify-center mx-auto mb-6">
            <svg class="w-8 h-8 text-green-600" aria-hidden="true">
                <use href="<?php echo $basePath; ?>/assets/images/cardify-icons.svg#icon-check"></use>
            </svg>
        </div>
        <h1 class="text-2xl font-bold text-gray-900 mb-4">Already Installed</h1>
        <p class="text-gray-600 mb-6">
            This application has already been installed and configured. 
            The installation wizard is no longer accessible.
        </p>
        <p class="text-gray-500 text-sm mb-6">
            <svg class="w-4 h-4 mr-2 inline-block text-gray-500 animate-spin" aria-hidden="true">
                <use href="<?php echo $basePath; ?>/assets/images/cardify-icons.svg#icon-spinner"></use>
            </svg>
            Redirecting to homepage in 5 seconds...
        </p>
        <a href="<?php echo $basePath; ?>/" 
           class="inline-block w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 px-6 rounded-xl transition">
            <svg class="w-4 h-4 mr-2 inline-block text-white" aria-hidden="true">
                <use href="<?php echo $basePath; ?>/assets/images/cardify-icons.svg#icon-link"></use>
            </svg>
            Go to Homepage Now
        </a>
        <p class="text-gray-500 text-xs mt-6">
            To reinstall, manually delete config.php and drop database tables.
        </p>
    </div>
<?php installerFooter(); ?>
                    <?php
                    exit;
                }
            }
        } catch (Exception $e) {
            // Database error - continue with installation (might be partial install)
        }
    }
}

require_once __DIR__ . '/../includes/Database.php';

$step = $_GET['step'] ?? 'welcome';
$errors = [];
$success = [];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'check_requirements':
            $step = 'database';
            break;
            
        case 'test_database':
            $host = $_POST['db_host'] ?? '';
            $database = $_POST['db_name'] ?? '';
            $username = $_POST['db_user'] ?? '';
            $password = $_POST['db_pass'] ?? '';
            $port = $_POST['db_port'] ?? '';
            $type = $_POST['db_type'] ?? 'mysql';
            
            $db = Database::getInstance();
            $port = $port ?: ($type === 'pgsql' ? 5432 : 3306);
            
            if ($db->connect($host, $database, $username, $password, $port, $type)) {
                $_SESSION['db_config'] = [
                    'host' => $host,
                    'database' => $database,
                    'username' => $username,
                    'password' => $password,
                    'port' => $port,
                    'type' => $type
                ];
                $step = 'migrate';
                $success[] = 'Database connection successful!';
            } else {
                $errors[] = 'Failed to connect to database. Please check your credentials.';
                $step = 'database';
            }
            break;
            
        case 'run_migration':
            if (!isset($_SESSION['db_config'])) {
                $errors[] = 'Database configuration not found.';
                $step = 'database';
                break;
            }
            
            $dbConfig = $_SESSION['db_config'];
            $db = Database::getInstance();
            
            if (!$db->connect($dbConfig['host'], $dbConfig['database'], $dbConfig['username'], 
                             $dbConfig['password'], $dbConfig['port'], $dbConfig['type'])) {
                $errors[] = 'Database connection failed.';
                $step = 'database';
                break;
            }
            
            require_once __DIR__ . '/../database/migrations/001_initial_schema.php';
            $result = migration_001_initial_schema($db->getConnection());
            
            if ($result['success']) {
                // Run enhanced admin migration
                require_once __DIR__ . '/../database/migrations/002_enhanced_admin.php';
                $result2 = migration_002_enhanced_admin($db->getConnection());
                
                // Run company hierarchy migration
                require_once __DIR__ . '/../database/migrations/003_company_hierarchy.php';
                $result3 = migration_003_company_hierarchy($db->getConnection());
                
                // Run print P.O. and WhatsApp migration
                require_once __DIR__ . '/../database/migrations/004_print_po_whatsapp.php';
                $result4 = migration_004_print_po_whatsapp($db->getConnection());
                
                // Filter out "table doesn't exist" errors as they're expected during initial install
                $filterErrors = function($errorList) {
                    return array_filter($errorList, function($error) {
                        return strpos($error, "doesn't exist") === false && 
                               strpos($error, 'Base table') === false;
                    });
                };
                
                $result2Errors = $filterErrors($result2['errors'] ?? []);
                $result3Errors = $filterErrors($result3['errors'] ?? []);
                $result4Errors = $filterErrors($result4['errors'] ?? []);
                
                $result2Success = empty($result2Errors);
                $result3Success = empty($result3Errors);
                $result4Success = empty($result4Errors);
                
                if ($result2Success && $result3Success && $result4Success) {
                    $step = 'site_config';
                    $success[] = 'Database migration completed successfully!';
                    $success[] = 'Enhanced admin features installed!';
                    $success[] = 'Company hierarchy support enabled!';
                    $success[] = 'Print P.O. and WhatsApp support enabled!';
                } else {
                    $step = 'site_config';
                    $success[] = 'Database migration completed!';
                    // Only show real errors, not "table doesn't exist" warnings
                    if (!empty($result2Errors)) {
                        $errors[] = 'Enhanced admin migration warnings: ' . implode(', ', $result2Errors);
                    }
                    if (!empty($result3Errors)) {
                        $errors[] = 'Company hierarchy migration warnings: ' . implode(', ', $result3Errors);
                    }
                    if (!empty($result4Errors)) {
                        $errors[] = 'Print P.O. and WhatsApp migration warnings: ' . implode(', ', $result4Errors);
                    }
                }
            } else {
                $errors[] = 'Migration errors: ' . implode(', ', $result['errors']);
                $step = 'migrate';
            }
            break;
            
        case 'save_site_config':
            if (!isset($_SESSION['db_config'])) {
                $errors[] = 'Database configuration missing.';
                $step = 'database';
                break;
            }
            
            $_SESSION['site_config'] = [
                'site_name' => $_POST['site_name'] ?? 'Business Cards',
                'site_description' => $_POST['site_description'] ?? 'Professional Business Card Generator',
                'timezone' => $_POST['timezone'] ?? 'Asia/Muscat'
            ];
            
            $step = 'billing';
            break;
            
        case 'save_billing_config':
            $_SESSION['billing_config'] = [
                'gateway' => $_POST['billing_gateway'] ?? 'amwal',
                'amwal_merchant_id' => $_POST['amwal_merchant_id'] ?? '',
                'amwal_terminal_id' => $_POST['amwal_terminal_id'] ?? '',
                'amwal_secure_key' => $_POST['amwal_secure_key'] ?? '',
                'amwal_api_url' => $_POST['amwal_api_url'] ?? 'https://backend.sa.amwal.tech',
                'stripe_secret_key' => $_POST['stripe_secret_key'] ?? '',
                'stripe_public_key' => $_POST['stripe_public_key'] ?? ''
            ];
            
            $step = 'admin';
            break;
            
        case 'skip_billing':
            $_SESSION['billing_config'] = [
                'gateway' => 'none',
                'amwal_merchant_id' => '',
                'amwal_terminal_id' => '',
                'amwal_secure_key' => '',
                'amwal_api_url' => 'https://backend.sa.amwal.tech',
                'stripe_secret_key' => '',
                'stripe_public_key' => ''
            ];
            $step = 'admin';
            break;
            
        case 'create_admin':
            if (!isset($_SESSION['db_config']) || !isset($_SESSION['site_config'])) {
                $errors[] = 'Previous steps not completed.';
                $step = 'welcome';
                break;
            }
            
            $adminEmail = $_POST['admin_email'] ?? '';
            $adminPassword = $_POST['admin_password'] ?? '';
            $adminPasswordConfirm = $_POST['admin_password_confirm'] ?? '';
            $adminName = $_POST['admin_name'] ?? 'Admin';
            
            if (empty($adminEmail) || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Valid admin email is required.';
                $step = 'admin';
                break;
            }
            
            if (empty($adminPassword) || strlen($adminPassword) < 8) {
                $errors[] = 'Password must be at least 8 characters.';
                $step = 'admin';
                break;
            }
            
            if ($adminPassword !== $adminPasswordConfirm) {
                $errors[] = 'Passwords do not match.';
                $step = 'admin';
                break;
            }
            
            $_SESSION['admin_config'] = [
                'email' => $adminEmail,
                'password' => $adminPassword,
                'name' => $adminName
            ];
            
            $step = 'finalize';
            break;
            
        case 'finalize_installation':
            if (!isset($_SESSION['db_config']) || !isset($_SESSION['site_config'])) {
                $errors[] = 'Configuration incomplete. Please start over.';
                $step = 'welcome';
                break;
            }
            
            $dbConfig = $_SESSION['db_config'];
            $siteConfig = $_SESSION['site_config'];
            $billingConfig = $_SESSION['billing_config'] ?? ['gateway' => 'none'];
            $adminConfig = $_SESSION['admin_config'] ?? null;
            
            // Create required directories
            $directories = [
                __DIR__ . '/../data',
                __DIR__ . '/../data/companies',  // For per-company JSON files
                __DIR__ . '/../uploads',
                __DIR__ . '/../uploads/companies',  // For per-company uploads
                __DIR__ . '/../uploads/templates',
                __DIR__ . '/../uploads/cards',
                __DIR__ . '/../uploads/excel'
            ];
            
            $createdDirs = [];
            foreach ($directories as $dir) {
                if (!is_dir($dir)) {
                    if (!mkdir($dir, 0755, true)) {
                        $errors[] = "Failed to create directory: " . basename($dir);
                        $step = 'finalize';
                        break 2;
                    }
                    $createdDirs[] = basename($dir);
                }
            }
            
            if (!empty($createdDirs)) {
                $success[] = 'Created required directories: ' . implode(', ', $createdDirs);
            }
            
            // Generate config.php
            $configContent = generateConfigFile($dbConfig, $siteConfig, $billingConfig);
            
            $configPath = __DIR__ . '/../config.php';
            
            // If config.php exists, try to make it writable first
            if (file_exists($configPath)) {
                // Try to change permissions to writable
                @chmod($configPath, 0666);
            }
            
            // Try to write config.php
            $writeResult = @file_put_contents($configPath, $configContent);
            
            if ($writeResult === false) {
                // Failed to write - provide detailed help
                $errors[] = 'Failed to write config.php due to permission issues.';
                $errors[] = '<strong>To fix this, run on your server:</strong><br><code style="background:#f1f5f9;padding:4px 8px;border-radius:4px;">chmod 666 ' . realpath(dirname($configPath)) . '/config.php</code><br>or<br><code style="background:#f1f5f9;padding:4px 8px;border-radius:4px;">chown www:www ' . realpath(dirname($configPath)) . '/config.php</code>';
                $step = 'finalize';
                break;
            }
            
            // Set proper permissions after writing
            @chmod($configPath, 0644);
            
            // Initialize database and complete setup
            // Use session config directly instead of requiring config.php
            // (config.php was just created but may not be fully loaded)
            require_once __DIR__ . '/../includes/Database.php';
            
            $db = Database::getInstance();
            if (!$db->isConnected()) {
                $db->connect($dbConfig['host'], $dbConfig['database'], $dbConfig['username'], 
                             $dbConfig['password'], $dbConfig['port'], $dbConfig['type']);
            }
            
            // Now require config.php after DB connection is established
            require_once __DIR__ . '/../config.php';
            require_once __DIR__ . '/../includes/functions.php';
            require_once __DIR__ . '/../includes/DatabaseAdapter.php';
            
            // Update system settings (insert if not exists, update if exists)
            
            $settings = [
                'installation_complete' => '1',
                'site_name' => $siteConfig['site_name'],
                'site_description' => $siteConfig['site_description']
            ];
            
            // Helper function to generate UUID if not available
            if (!function_exists('installer_generateUUID')) {
                function installer_generateUUID() {
                    $data = random_bytes(16);
                    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
                    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
                    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
                }
            }
            
            foreach ($settings as $key => $value) {
                try {
                    $existing = $db->fetchOne(
                        "SELECT setting_key FROM system_settings WHERE setting_key = :key",
                        ['key' => $key]
                    );
                    
                    if ($existing && !empty($existing)) {
                        // Update existing setting
                        $updated = $db->update('system_settings',
                            ['setting_value' => $value],
                            'setting_key = :key',
                            ['key' => $key]
                        );
                        if ($updated === false) {
                            error_log("Failed to update setting {$key}");
                        }
                    } else {
                        // Insert new setting - table requires id column (UUID)
                        try {
                            $uuid = function_exists('generateUUID') ? generateUUID() : installer_generateUUID();
                            $db->insert('system_settings', [
                                'id' => $uuid,
                                'setting_key' => $key,
                                'setting_value' => $value,
                                'description' => ucfirst(str_replace('_', ' ', $key))
                            ]);
                        } catch (Exception $insertError) {
                            // If insert fails (maybe duplicate key), try update
                            error_log("Insert failed for {$key}, trying update: " . $insertError->getMessage());
                            $db->update('system_settings',
                                ['setting_value' => $value],
                                'setting_key = :key',
                                ['key' => $key]
                            );
                        }
                    }
                    
                    // Verify it was set correctly
                    $verify = $db->fetchOne(
                        "SELECT setting_value FROM system_settings WHERE setting_key = :key",
                        ['key' => $key]
                    );
                    if (!$verify || ($verify['setting_value'] ?? '') !== $value) {
                        $errorMsg = "Warning: Setting {$key} verification failed. Expected: {$value}, Got: " . print_r($verify, true);
                        error_log($errorMsg);
                        // For installation_complete, this is critical - add to errors
                        if ($key === 'installation_complete') {
                            $errors[] = "Failed to set installation_complete flag. Please set it manually in database.";
                        }
                    } else {
                        // Success - add to success messages for installation_complete
                        if ($key === 'installation_complete') {
                            $success[] = "Installation complete flag set successfully.";
                        }
                    }
                } catch (Exception $e) {
                    $errorMsg = "Failed to set setting {$key}: " . $e->getMessage();
                    error_log($errorMsg);
                    // For installation_complete, this is critical
                    if ($key === 'installation_complete') {
                        $errors[] = "Critical: Failed to set installation_complete flag: " . $e->getMessage();
                    }
                }
            }
            
            // Final check: ensure installation_complete was set
            $finalCheck = $db->fetchOne(
                "SELECT setting_value FROM system_settings WHERE setting_key = 'installation_complete'"
            );
            if (!$finalCheck || ($finalCheck['setting_value'] ?? '') !== '1') {
                // Try one more time with direct SQL - include id column (UUID required)
                try {
                    $uuid = function_exists('generateUUID') ? generateUUID() : installer_generateUUID();
                    $db->query("INSERT INTO system_settings (id, setting_key, setting_value, description) VALUES ('{$uuid}', 'installation_complete', '1', 'Whether installation has been completed') ON DUPLICATE KEY UPDATE setting_value = '1'");
                    $success[] = "Installation complete flag set via direct SQL.";
                } catch (Exception $e) {
                    $errors[] = "Could not set installation_complete flag. The homepage will auto-create it on first visit if companies exist.";
                }
            }
            
            // Create admin company and user if admin config provided
            if ($adminConfig) {
                // Use DatabaseAdapter if database is available, otherwise use JSON function
                if (DatabaseAdapter::useDatabase()) {
                    $companyResult = DatabaseAdapter::createCompany(
                        $adminConfig['name'] . ' Admin', 
                        $adminConfig['email'], 
                        $adminConfig['password']
                    );
                } else {
                    $companyResult = createCompany(
                        $adminConfig['name'] . ' Admin', 
                        $adminConfig['email'], 
                        $adminConfig['password']
                    );
                }
                
                if ($companyResult['success']) {
                    $success[] = 'Admin company created successfully!';
                    $success[] = 'Company code: ' . $companyResult['company']['slug'];
                    
                    // Also create admin user in users table for unified login
                    try {
                        $userId = function_exists('generateUUID') ? generateUUID() : installer_generateUUID();
                        $passwordHash = password_hash($adminConfig['password'], PASSWORD_BCRYPT);
                        $db->insert('users', [
                            'id' => $userId,
                            'email' => $adminConfig['email'],
                            'password_hash' => $passwordHash,
                            'name' => $adminConfig['name'],
                            'role' => 'super_admin',
                            'company_id' => $companyResult['company']['id'],
                            'status' => 'active'
                        ]);
                        $success[] = 'Admin user account created!';
                    } catch (Exception $userError) {
                        // If user exists, update role and password to ensure login works
                        try {
                            $db->update('users',
                                [
                                    'password_hash' => $passwordHash,
                                    'role' => 'super_admin',
                                    'company_id' => $companyResult['company']['id'],
                                    'status' => 'active'
                                ],
                                'email = :email',
                                ['email' => $adminConfig['email']]
                            );
                            $success[] = 'Admin user account updated!';
                        } catch (Exception $updateError) {
                            error_log("Could not create/update admin user: " . $userError->getMessage());
                        }
                    }
                } else {
                    $errors[] = 'Failed to create admin company: ' . ($companyResult['error'] ?? 'Unknown error');
                }
            }
            
            // Verify config.php was created correctly
            if (!file_exists(__DIR__ . '/../config.php')) {
                $errors[] = 'config.php was not created. Please check file permissions.';
                $step = 'finalize';
                break;
            }
            
            // Verify required constants exist in config.php
            $configContent = file_get_contents(__DIR__ . '/../config.php');
            $requiredConstants = ['DB_HOST', 'DB_NAME', 'SITE_NAME', 'BASE_DIR', 'INCLUDES_DIR'];
            $missingConstants = [];
            foreach ($requiredConstants as $constant) {
                if (strpos($configContent, "define('$constant'") === false && strpos($configContent, "define(\"$constant\"") === false) {
                    $missingConstants[] = $constant;
                }
            }
            
            if (!empty($missingConstants)) {
                $errors[] = 'config.php is missing required constants: ' . implode(', ', $missingConstants);
                $step = 'finalize';
                break;
            }
            
            $step = 'complete';
            $success[] = 'Installation completed successfully!';
            $success[] = 'config.php has been created and verified.';
            
            break;
    }
}

function generateConfigFile($dbConfig, $siteConfig, $billingConfig) {
    // Build billing section with proper if (!defined()) checks
    $billingSection = '';
    if ($billingConfig['gateway'] === 'amwal') {
        $amwalMerchantId = addslashes($billingConfig['amwal_merchant_id'] ?? '');
        $amwalTerminalId = addslashes($billingConfig['amwal_terminal_id'] ?? '');
        $amwalSecureKey = addslashes($billingConfig['amwal_secure_key'] ?? '');
        $amwalApiUrl = addslashes($billingConfig['amwal_api_url'] ?? 'https://backend.sa.amwal.tech');
        
        $billingSection = "
// Billing/Payment Gateway Configuration - Amwal Pay
define('BILLING_GATEWAY', 'amwal');
if (!defined('AMWAL_MERCHANT_ID')) define('AMWAL_MERCHANT_ID', '{$amwalMerchantId}');
if (!defined('AMWAL_TERMINAL_ID')) define('AMWAL_TERMINAL_ID', '{$amwalTerminalId}');
if (!defined('AMWAL_SECURE_KEY')) define('AMWAL_SECURE_KEY', '{$amwalSecureKey}');
if (!defined('AMWAL_API_URL')) define('AMWAL_API_URL', '{$amwalApiUrl}');
if (!defined('STRIPE_SECRET_KEY')) define('STRIPE_SECRET_KEY', '');
if (!defined('STRIPE_PUBLIC_KEY')) define('STRIPE_PUBLIC_KEY', '');";
    } elseif ($billingConfig['gateway'] === 'stripe') {
        $stripeSecretKey = addslashes($billingConfig['stripe_secret_key'] ?? '');
        $stripePublicKey = addslashes($billingConfig['stripe_public_key'] ?? '');
        
        $billingSection = "
// Billing/Payment Gateway Configuration - Stripe
define('BILLING_GATEWAY', 'stripe');
if (!defined('AMWAL_MERCHANT_ID')) define('AMWAL_MERCHANT_ID', '');
if (!defined('AMWAL_TERMINAL_ID')) define('AMWAL_TERMINAL_ID', '');
if (!defined('AMWAL_SECURE_KEY')) define('AMWAL_SECURE_KEY', '');
if (!defined('AMWAL_API_URL')) define('AMWAL_API_URL', 'https://backend.sa.amwal.tech');
if (!defined('STRIPE_SECRET_KEY')) define('STRIPE_SECRET_KEY', '{$stripeSecretKey}');
if (!defined('STRIPE_PUBLIC_KEY')) define('STRIPE_PUBLIC_KEY', '{$stripePublicKey}');";
    } else {
        $billingSection = "
// Billing/Payment Gateway Configuration - Not configured
define('BILLING_GATEWAY', 'none');
if (!defined('AMWAL_MERCHANT_ID')) define('AMWAL_MERCHANT_ID', '');
if (!defined('AMWAL_TERMINAL_ID')) define('AMWAL_TERMINAL_ID', '');
if (!defined('AMWAL_SECURE_KEY')) define('AMWAL_SECURE_KEY', '');
if (!defined('AMWAL_API_URL')) define('AMWAL_API_URL', 'https://backend.sa.amwal.tech');
if (!defined('STRIPE_SECRET_KEY')) define('STRIPE_SECRET_KEY', '');
if (!defined('STRIPE_PUBLIC_KEY')) define('STRIPE_PUBLIC_KEY', '');";
    }
    
    $siteName = addslashes($siteConfig['site_name']);
    $siteDescription = addslashes($siteConfig['site_description']);
    $timezone = addslashes($siteConfig['timezone']);
    $dbHost = addslashes($dbConfig['host']);
    $dbName = addslashes($dbConfig['database']);
    $dbUser = addslashes($dbConfig['username']);
    $dbPass = addslashes($dbConfig['password']);
    $dbPort = addslashes($dbConfig['port']);
    $dbType = addslashes($dbConfig['type']);
    
    return <<<PHP
<?php
/**
 * Configuration file - Auto-generated by installer
 * Generated: {$siteName}
 * DO NOT EDIT MANUALLY - Use admin panel or re-run installer
 */

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Environment detection
\$isProduction = (strpos(\$_SERVER['HTTP_HOST'] ?? '', 'localhost') === false && 
                 strpos(\$_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false &&
                 strpos(\$_SERVER['HTTP_HOST'] ?? '', '.local') === false &&
                 strpos(\$_SERVER['HTTP_HOST'] ?? '', '.test') === false);

// Database configuration
define('DB_HOST', '{$dbHost}');
define('DB_NAME', '{$dbName}');
define('DB_USER', '{$dbUser}');
define('DB_PASS', '{$dbPass}');
define('DB_PORT', '{$dbPort}');
define('DB_TYPE', '{$dbType}');

// Base paths
define('BASE_DIR', __DIR__);
define('INCLUDES_DIR', BASE_DIR . '/includes');
define('DATA_DIR', BASE_DIR . '/data');
define('UPLOADS_DIR', BASE_DIR . '/uploads');
define('TEMPLATES_DIR', UPLOADS_DIR . '/templates');
define('CARDS_DIR', UPLOADS_DIR . '/cards');
define('EXCEL_DIR', UPLOADS_DIR . '/excel');
define('ASSETS_DIR', BASE_DIR . '/assets');

// Site settings
define('SITE_NAME', '{$siteName}');
define('SITE_DESCRIPTION', '{$siteDescription}');

// Timezone
date_default_timezone_set('{$timezone}');

// Error reporting (production-safe)
if (\$isProduction) {
    // Production: Log errors but don't display them
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', BASE_DIR . '/logs/php-errors.log');
    
    // Create logs directory if it doesn't exist
    if (!is_dir(BASE_DIR . '/logs')) {
        mkdir(BASE_DIR . '/logs', 0755, true);
    }
} else {
    // Development: Show errors
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('log_errors', 1);
}
{$billingSection}

// Include required files
require_once INCLUDES_DIR . '/Database.php';
require_once INCLUDES_DIR . '/functions.php';
require_once INCLUDES_DIR . '/DatabaseAdapter.php';
require_once INCLUDES_DIR . '/AuditLog.php';

// Initialize database connection (if configured)
if (defined('DB_HOST') && !empty(DB_HOST) && !empty(DB_NAME)) {
    \$db = Database::getInstance();
    if (!\$db->isConnected()) {
        \$db->connect(DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_PORT, DB_TYPE);
    }
    // Initialize DatabaseAdapter
    DatabaseAdapter::init();
}

// Security headers (production)
if (\$isProduction) {
    // Force HTTPS in production
    if (!isset(\$_SERVER['HTTPS']) && \$_SERVER['HTTP_HOST'] !== 'localhost') {
        header('Location: https://' . \$_SERVER['HTTP_HOST'] . \$_SERVER['REQUEST_URI'], true, 301);
        exit;
    }
    
    // Security headers
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    
    // HSTS (HTTP Strict Transport Security) - only if HTTPS
    if (isset(\$_SERVER['HTTPS']) && \$_SERVER['HTTPS'] === 'on') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

PHP;
}

if (!function_exists('getBasePath')) {
    function getBasePath() {
        $scriptPath = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '/install/index.php';
        $scriptDir = dirname(dirname($scriptPath));
        return $scriptDir === '/' ? '/' : rtrim($scriptDir, '/') . '/';
    }
}

installerHeader(
    'Installation Wizard | Cardify',
    $basePath,
    '<style>
        .card-box {
            background: white;
            border: 1px solid #e5e7eb;
            box-shadow: 0 10px 40px -10px rgba(0, 0, 0, 0.1);
        }
        .input-field {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            transition: all 0.2s ease;
        }
        .input-field:focus {
            outline: none;
            background: white;
            border-color: #3b82f6;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        }
        .btn-primary {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            transition: all 0.2s ease;
        }
        .btn-primary:hover {
            box-shadow: 0 10px 30px -10px rgba(37, 99, 235, 0.5);
            transform: translateY(-1px);
        }
        .btn-secondary {
            background: white;
            border: 1px solid #e5e7eb;
            transition: all 0.2s ease;
        }
        .btn-secondary:hover {
            background: #f9fafb;
            border-color: #d1d5db;
        }
        .step-connector {
            width: 30px;
            height: 2px;
            background: #e5e7eb;
            margin: 0 0.25rem;
            align-self: center;
        }
        .info-box {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        .info-box p {
            color: #1e40af;
            font-size: 0.875rem;
        }
    </style>',
    'bg-gradient-to-br from-gray-50 to-blue-50 min-h-screen'
);
?>
    <div class="min-h-screen flex items-center justify-center px-4 py-12">
        <div class="w-full max-w-3xl">
            <!-- Header -->
            <div class="text-center mb-8">
                <div class="flex items-center justify-center space-x-3 mb-4">
                    <div style="width: 52px; height: 34px; border-radius: 8px;" class="flex items-center justify-center shadow-lg overflow-hidden">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 68 44" fill="none" style="width: 52px; height: 34px;">
                            <defs><linearGradient id="cg1" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#2563eb"/><stop offset="100%" stop-color="#1d4ed8"/></linearGradient></defs>
                            <rect width="68" height="44" rx="10" fill="url(#cg1)"/>
                            <rect x="8" y="8" width="52" height="28" rx="6" fill="#ffffff"/>
                            <rect x="14" y="14" width="10" height="10" rx="5" fill="#f59e0b"/>
                            <rect x="28" y="14" width="24" height="4" rx="2" fill="#2563eb"/>
                            <rect x="28" y="22" width="18" height="4" rx="2" fill="#93c5fd"/>
                        </svg>
                    </div>
                    <span class="text-2xl font-bold text-gray-900" style="font-family: 'Gill Sans', 'Gill Sans MT', Calibri, sans-serif;">Cardify</span>
                </div>
                <h1 class="text-3xl md:text-4xl font-bold text-gray-900 mb-2">
                    Installation Wizard
                </h1>
                <p class="text-gray-500 text-lg">Complete setup for your Business Card Generator</p>
            </div>
            
            <!-- Step Indicator -->
            <div class="flex justify-center items-center mb-8 flex-wrap gap-2">
                <div class="step w-10 h-10 md:w-12 md:h-12 rounded-full flex items-center justify-center font-bold text-sm md:text-base transition-all <?php echo $step === 'welcome' || $step === 'requirements' ? 'bg-blue-600 text-white shadow-lg' : ($step !== 'welcome' && $step !== 'requirements' ? 'bg-green-500 text-white' : 'bg-gray-200 text-gray-500'); ?>">1</div>
                <div class="step-connector"></div>
                <div class="step w-10 h-10 md:w-12 md:h-12 rounded-full flex items-center justify-center font-bold text-sm md:text-base transition-all <?php echo $step === 'database' ? 'bg-blue-600 text-white shadow-lg' : ($step === 'migrate' || $step === 'site_config' || $step === 'billing' || $step === 'admin' || $step === 'finalize' || $step === 'complete' ? 'bg-green-500 text-white' : 'bg-gray-200 text-gray-500'); ?>">2</div>
                <div class="step-connector"></div>
                <div class="step w-10 h-10 md:w-12 md:h-12 rounded-full flex items-center justify-center font-bold text-sm md:text-base transition-all <?php echo $step === 'migrate' ? 'bg-blue-600 text-white shadow-lg' : ($step === 'site_config' || $step === 'billing' || $step === 'admin' || $step === 'finalize' || $step === 'complete' ? 'bg-green-500 text-white' : 'bg-gray-200 text-gray-500'); ?>">3</div>
                <div class="step-connector"></div>
                <div class="step w-10 h-10 md:w-12 md:h-12 rounded-full flex items-center justify-center font-bold text-sm md:text-base transition-all <?php echo $step === 'site_config' ? 'bg-blue-600 text-white shadow-lg' : ($step === 'billing' || $step === 'admin' || $step === 'finalize' || $step === 'complete' ? 'bg-green-500 text-white' : 'bg-gray-200 text-gray-500'); ?>">4</div>
                <div class="step-connector"></div>
                <div class="step w-10 h-10 md:w-12 md:h-12 rounded-full flex items-center justify-center font-bold text-sm md:text-base transition-all <?php echo $step === 'billing' ? 'bg-blue-600 text-white shadow-lg' : ($step === 'admin' || $step === 'finalize' || $step === 'complete' ? 'bg-green-500 text-white' : 'bg-gray-200 text-gray-500'); ?>">5</div>
                <div class="step-connector"></div>
                <div class="step w-10 h-10 md:w-12 md:h-12 rounded-full flex items-center justify-center font-bold text-sm md:text-base transition-all <?php echo $step === 'admin' ? 'bg-blue-600 text-white shadow-lg' : ($step === 'finalize' || $step === 'complete' ? 'bg-green-500 text-white' : 'bg-gray-200 text-gray-500'); ?>">6</div>
                <div class="step-connector"></div>
                <div class="step w-10 h-10 md:w-12 md:h-12 rounded-full flex items-center justify-center font-bold text-sm md:text-base transition-all <?php echo $step === 'finalize' ? 'bg-blue-600 text-white shadow-lg' : ($step === 'complete' ? 'bg-green-500 text-white' : 'bg-gray-200 text-gray-500'); ?>">7</div>
            </div>
            
            <!-- Messages -->
            <?php if (!empty($errors)): ?>
            <div class="mb-6 p-4 rounded-xl bg-red-50 border border-red-200">
                <?php foreach ($errors as $error): ?>
                <p class="text-red-700 text-sm flex items-start">
                    <svg class="w-4 h-4 mr-2 mt-0.5 text-red-500" aria-hidden="true">
                        <use href="<?php echo $basePath; ?>/assets/images/cardify-icons.svg#icon-x"></use>
                    </svg>
                    <?php echo $error; ?>
                </p>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($success)): ?>
            <div class="mb-6 p-4 rounded-xl bg-green-50 border border-green-200">
                <?php foreach ($success as $msg): ?>
                <p class="text-green-700 text-sm flex items-start">
                    <svg class="w-4 h-4 mr-2 mt-0.5 text-green-500" aria-hidden="true">
                        <use href="<?php echo $basePath; ?>/assets/images/cardify-icons.svg#icon-check-circle"></use>
                    </svg>
                    <?php echo htmlspecialchars($msg); ?>
                </p>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <!-- Step Content -->
            <div class="card-box rounded-2xl p-6 md:p-8">
                <?php if ($step === 'welcome' || $step === 'requirements'): ?>
                    <?php if ($reinstall): ?>
                    <div class="mb-6 p-4 rounded-xl bg-yellow-50 border border-yellow-200">
                        <p class="text-yellow-700 text-sm"><strong>⚠ Reinstall Mode:</strong> You can clean install (delete all existing data) during the migration step.</p>
                    </div>
                    <?php endif; ?>
                    
                    <h2 class="text-2xl font-bold mb-4">Welcome</h2>
                    <p class="text-gray-600 mb-6">This wizard will guide you through the complete setup of your Business Card Generator SaaS platform.</p>
                    
                    <h3 class="text-lg font-semibold mb-4">Requirements Check</h3>
                    <ul class="space-y-2 mb-6">
                        <li class="flex items-center space-x-2">
                            <span class="<?php echo version_compare(PHP_VERSION, '7.4.0', '>=') ? 'text-green-600' : 'text-red-400'; ?>">
                                <?php echo version_compare(PHP_VERSION, '7.4.0', '>=') ? '✓' : '✗'; ?>
                            </span>
                            <span>PHP <?php echo version_compare(PHP_VERSION, '7.4.0', '>=') ? '≥7.4 (' . PHP_VERSION . ')' : PHP_VERSION . ' (Need ≥7.4)'; ?></span>
                        </li>
                        <li class="flex items-center space-x-2">
                            <span class="<?php echo extension_loaded('pdo') ? 'text-green-600' : 'text-red-400'; ?>">
                                <?php echo extension_loaded('pdo') ? '✓' : '✗'; ?>
                            </span>
                            <span>PDO Extension: <?php echo extension_loaded('pdo') ? 'Installed' : 'Missing'; ?></span>
                        </li>
                        <li class="flex items-center space-x-2">
                            <span class="<?php echo extension_loaded('json') ? 'text-green-600' : 'text-red-400'; ?>">
                                <?php echo extension_loaded('json') ? '✓' : '✗'; ?>
                            </span>
                            <span>JSON Extension: <?php echo extension_loaded('json') ? 'Installed' : 'Missing'; ?></span>
                        </li>
                        <li class="flex items-center space-x-2">
                            <span class="<?php echo is_writable(__DIR__ . '/../data') ? 'text-green-600' : 'text-red-400'; ?>">
                                <?php echo is_writable(__DIR__ . '/../data') ? '✓' : '✗'; ?>
                            </span>
                            <span>Data directory writable</span>
                        </li>
                        <li class="flex items-center space-x-2">
                            <span class="<?php echo is_writable(__DIR__ . '/../uploads') ? 'text-green-600' : 'text-red-400'; ?>">
                                <?php echo is_writable(__DIR__ . '/../uploads') ? '✓' : '✗'; ?>
                            </span>
                            <span>Uploads directory writable</span>
                        </li>
                        <li class="flex items-center space-x-2">
                            <span class="<?php echo is_writable(__DIR__ . '/../') ? 'text-green-600' : 'text-red-400'; ?>">
                                <?php echo is_writable(__DIR__ . '/../') ? '✓' : '✗'; ?>
                            </span>
                            <span>Root directory writable (for config.php)</span>
                        </li>
                    </ul>
                    
                    <form method="post">
                        <input type="hidden" name="action" value="check_requirements">
                        <button type="submit" class="btn-primary w-full py-4 rounded-xl text-white font-bold hover:scale-[1.02] transition-transform">
                            Continue to Database Setup →
                        </button>
                    </form>
                    
                <?php elseif ($step === 'database'): ?>
                    <h2 class="text-2xl font-bold text-gray-900 mb-4">Database Configuration</h2>
                    <p class="text-gray-600 mb-6">Enter your database connection details. Make sure the database exists.</p>
                    
                    <form method="post">
                        <input type="hidden" name="action" value="test_database">
                        
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Database Type</label>
                                <select name="db_type" class="input-field w-full px-4 py-3 rounded-xl text-gray-900">
                                    <option value="mysql">MySQL/MariaDB</option>
                                    <option value="pgsql">PostgreSQL</option>
                                </select>
                            </div>
                            
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Database Host</label>
                                    <input type="text" name="db_host" value="localhost" required class="input-field w-full px-4 py-3 rounded-xl text-gray-900">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Database Port</label>
                                    <input type="number" name="db_port" placeholder="Auto" class="input-field w-full px-4 py-3 rounded-xl text-gray-900">
                                    <p class="text-xs text-gray-500 mt-1">Leave empty for default (3306 MySQL, 5432 PostgreSQL)</p>
                                </div>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Database Name</label>
                                <input type="text" name="db_name" required class="input-field w-full px-4 py-3 rounded-xl text-gray-900" placeholder="business_cards">
                                <p class="text-xs text-gray-500 mt-1">Create this database first if it doesn't exist</p>
                            </div>
                            
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Database Username</label>
                                    <input type="text" name="db_user" required class="input-field w-full px-4 py-3 rounded-xl text-gray-900">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Database Password</label>
                                    <input type="password" name="db_pass" class="input-field w-full px-4 py-3 rounded-xl text-gray-900">
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($reinstall): ?>
                        <div class="mb-4 p-4 rounded-xl bg-yellow-100 border border-yellow-300">
                            <p class="text-yellow-700 text-sm"><strong>⚠ Reinstall Mode:</strong> After testing connection, you can choose to clean install (delete all data) or continue with existing data.</p>
                        </div>
                        <?php endif; ?>
                        
                        <div class="mt-6 flex space-x-3">
                            <a href="?step=requirements" class="btn-secondary px-6 py-3 rounded-xl text-gray-700 flex-1 text-center">← Back</a>
                            <button type="submit" class="btn-primary px-6 py-3 rounded-xl text-white flex-1">Test Connection →</button>
                        </div>
                    </form>
                    
                <?php elseif ($step === 'migrate'): ?>
                    <h2 class="text-2xl font-bold mb-4">Database Migration</h2>
                    <p class="text-gray-600 mb-6">Ready to create database tables and set up the schema. This will create all necessary tables.</p>
                    
                    <?php
                    // Check if tables already exist
                    $hasExistingTables = false;
                    if (isset($_SESSION['db_config'])) {
                        try {
                            $dbConfig = $_SESSION['db_config'];
                            $db = Database::getInstance();
                            if ($db->connect($dbConfig['host'], $dbConfig['database'], $dbConfig['username'], 
                                             $dbConfig['password'], $dbConfig['port'], $dbConfig['type'])) {
                                $existingTables = $db->fetchAll("SHOW TABLES");
                                $hasExistingTables = !empty($existingTables);
                            }
                        } catch (Exception $e) {
                            // Ignore
                        }
                    }
                    ?>
                    
                    <?php if ($hasExistingTables): ?>
                    <div class="mb-6 p-4 rounded-xl bg-blue-50 border border-blue-200">
                        <p class="text-blue-700 text-sm"><strong>ℹ Note:</strong> Existing tables found in database. Migration will update schema while preserving your data.</p>
                    </div>
                    <?php else: ?>
                    <div class="info-box">
                        <p><strong>Note:</strong> This will create tables for companies, employees, templates, subscriptions, and more.</p>
                    </div>
                    <?php endif; ?>
                    
                    <form method="post">
                        <input type="hidden" name="action" value="run_migration">
                        <div class="flex space-x-3">
                            <a href="?step=database" class="btn-secondary px-6 py-3 rounded-xl text-gray-700 flex-1 text-center">← Back</a>
                            <button type="submit" class="btn-primary px-6 py-3 rounded-xl text-white flex-1">Run Migration →</button>
                        </div>
                    </form>
                    
                <?php elseif ($step === 'site_config'): ?>
                    <h2 class="text-2xl font-bold mb-4">Site Configuration</h2>
                    <p class="text-gray-600 mb-6">Configure your site name and basic settings.</p>
                    
                    <form method="post">
                        <input type="hidden" name="action" value="save_site_config">
                        
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Site Name</label>
                                <input type="text" name="site_name" value="<?php echo htmlspecialchars($_SESSION['site_config']['site_name'] ?? 'Business Cards'); ?>" required class="input-field w-full px-4 py-3 rounded-xl text-gray-900">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Site Description</label>
                                <input type="text" name="site_description" value="<?php echo htmlspecialchars($_SESSION['site_config']['site_description'] ?? 'Professional Business Card Generator'); ?>" class="input-field w-full px-4 py-3 rounded-xl text-gray-900">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Timezone</label>
                                <select name="timezone" class="input-field w-full px-4 py-3 rounded-xl text-gray-900">
                                    <option value="Asia/Muscat" <?php echo ($_SESSION['site_config']['timezone'] ?? 'Asia/Muscat') === 'Asia/Muscat' ? 'selected' : ''; ?>>Asia/Muscat</option>
                                    <option value="UTC">UTC</option>
                                    <option value="America/New_York">America/New_York</option>
                                    <option value="Europe/London">Europe/London</option>
                                    <option value="Asia/Dubai">Asia/Dubai</option>
                                    <option value="Asia/Riyadh">Asia/Riyadh</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mt-6 flex space-x-3">
                            <a href="?step=migrate" class="btn-secondary px-6 py-3 rounded-xl text-gray-700 flex-1 text-center">← Back</a>
                            <button type="submit" class="btn-primary px-6 py-3 rounded-xl text-white flex-1">Continue →</button>
                        </div>
                    </form>
                    
                <?php elseif ($step === 'billing'): ?>
                    <h2 class="text-2xl font-bold mb-4">Billing Configuration</h2>
                    <p class="text-gray-600 mb-6">Configure your payment gateway. You can skip this and configure later.</p>
                    
                    <form method="post" id="billingForm">
                        <input type="hidden" name="action" value="save_billing_config">
                        
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Payment Gateway</label>
                                <select name="billing_gateway" id="gatewaySelect" class="input-field w-full px-4 py-3 rounded-xl text-gray-900" data-cardify-change-fn="toggleGatewayFields">
                                    <option value="none">Skip (Configure Later)</option>
                                    <option value="amwal">Amwal Pay</option>
                                    <option value="stripe">Stripe</option>
                                </select>
                            </div>
                            
                            <!-- Amwal Pay Fields -->
                            <div id="amwalFields" style="display: none;">
                                <h3 class="text-lg font-semibold mb-3 mt-4">Amwal Pay Settings</h3>
                                <p class="text-sm text-gray-600 mb-4">Get these credentials from your Amwal Pay merchant dashboard after signing up.</p>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Merchant ID</label>
                                    <input type="text" name="amwal_merchant_id" value="<?php echo htmlspecialchars($_SESSION['billing_config']['amwal_merchant_id'] ?? ''); ?>" class="input-field w-full px-4 py-3 rounded-xl text-gray-900" placeholder="Your Merchant ID" required>
                                    <p class="text-xs text-gray-500 mt-1">Provided by Amwal Pay after account setup</p>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Terminal ID</label>
                                    <input type="text" name="amwal_terminal_id" value="<?php echo htmlspecialchars($_SESSION['billing_config']['amwal_terminal_id'] ?? ''); ?>" class="input-field w-full px-4 py-3 rounded-xl text-gray-900" placeholder="Your Terminal ID" required>
                                    <p class="text-xs text-gray-500 mt-1">Provided by Amwal Pay after account setup</p>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Secure Key</label>
                                    <input type="password" name="amwal_secure_key" value="<?php echo htmlspecialchars($_SESSION['billing_config']['amwal_secure_key'] ?? ''); ?>" class="input-field w-full px-4 py-3 rounded-xl text-gray-900" placeholder="Your Secure Key" required>
                                    <p class="text-xs text-gray-500 mt-1">Keep this secret! Used for payment signature verification</p>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">API URL</label>
                                    <input type="text" name="amwal_api_url" value="<?php echo htmlspecialchars($_SESSION['billing_config']['amwal_api_url'] ?? 'https://backend.sa.amwal.tech'); ?>" class="input-field w-full px-4 py-3 rounded-xl text-gray-900">
                                    <p class="text-xs text-gray-500 mt-1">Default: https://backend.sa.amwal.tech</p>
                                </div>
                                
                                <div class="info-box">
                                    <p><strong>Callback URL:</strong> <?php echo (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . getBasePath(); ?>amwalpay/callback.php</p>
                                    <p class="mt-2">Configure this URL in your Amwal Pay merchant dashboard settings.</p>
                                </div>
                            </div>
                            
                            <!-- Stripe Fields -->
                            <div id="stripeFields" style="display: none;">
                                <h3 class="text-lg font-semibold mb-3 mt-4">Stripe Settings</h3>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Secret Key</label>
                                    <input type="text" name="stripe_secret_key" value="<?php echo htmlspecialchars($_SESSION['billing_config']['stripe_secret_key'] ?? ''); ?>" class="input-field w-full px-4 py-3 rounded-xl text-gray-900" placeholder="sk_live_...">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Public Key</label>
                                    <input type="text" name="stripe_public_key" value="<?php echo htmlspecialchars($_SESSION['billing_config']['stripe_public_key'] ?? ''); ?>" class="input-field w-full px-4 py-3 rounded-xl text-gray-900" placeholder="pk_live_...">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-6 flex space-x-3">
                            <a href="?step=site_config" class="btn-secondary px-6 py-3 rounded-xl text-gray-700 flex-1 text-center">← Back</a>
                            <button type="button" data-cardify-action="submit-form" data-form="skipForm" class="btn-secondary px-6 py-3 rounded-xl text-gray-700">Skip</button>
                            <button type="submit" class="btn-primary px-6 py-3 rounded-xl text-white flex-1">Continue →</button>
                        </div>
                    </form>
                    
                    <form method="post" id="skipForm" style="display: none;">
                        <input type="hidden" name="action" value="skip_billing">
                    </form>
                    
                    <script<?= cspNonceAttr() ?>>
                        function toggleGatewayFields() {
                            const gateway = document.getElementById('gatewaySelect').value;
                            document.getElementById('amwalFields').style.display = gateway === 'amwal' ? 'block' : 'none';
                            document.getElementById('stripeFields').style.display = gateway === 'stripe' ? 'block' : 'none';
                        }
                        toggleGatewayFields();
                    </script>
                    
                <?php elseif ($step === 'admin'): ?>
                    <h2 class="text-2xl font-bold mb-4">Admin Account</h2>
                    <p class="text-gray-600 mb-6">Create your first admin company account. This will be used to manage the platform.</p>
                    
                    <div class="info-box">
                        <p>This will create a company account that you can use to login and manage employees, templates, and billing.</p>
                    </div>
                    
                    <form method="post">
                        <input type="hidden" name="action" value="create_admin">
                        
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Company/Admin Name</label>
                                <input type="text" name="admin_name" value="<?php echo htmlspecialchars($_SESSION['admin_config']['name'] ?? 'Admin'); ?>" required class="input-field w-full px-4 py-3 rounded-xl text-gray-900" placeholder="My Company">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Admin Email</label>
                                <input type="email" name="admin_email" value="<?php echo htmlspecialchars($_SESSION['admin_config']['email'] ?? ''); ?>" required class="input-field w-full px-4 py-3 rounded-xl text-gray-900" placeholder="admin@company.com">
                                <p class="text-xs text-gray-500 mt-1">This will be your login email</p>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Password</label>
                                <input type="password" name="admin_password" required class="input-field w-full px-4 py-3 rounded-xl text-gray-900" placeholder="Minimum 8 characters">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Confirm Password</label>
                                <input type="password" name="admin_password_confirm" required class="input-field w-full px-4 py-3 rounded-xl text-gray-900">
                            </div>
                        </div>
                        
                        <div class="mt-6 flex space-x-3">
                            <a href="?step=billing" class="btn-secondary px-6 py-3 rounded-xl text-gray-700 flex-1 text-center">← Back</a>
                            <button type="submit" class="btn-primary px-6 py-3 rounded-xl text-white flex-1">Continue →</button>
                        </div>
                    </form>
                    
                <?php elseif ($step === 'finalize'): ?>
                    <h2 class="text-2xl font-bold mb-4">Finalize Installation</h2>
                    <p class="text-gray-600 mb-6">Review your configuration and complete the installation.</p>
                    
                    <div class="space-y-4 mb-6">
                        <div class="p-4 rounded-xl bg-gray-50 border border-gray-200">
                            <h3 class="font-semibold mb-2">Database</h3>
                            <p class="text-sm text-gray-600"><?php echo htmlspecialchars($_SESSION['db_config']['type']); ?> - <?php echo htmlspecialchars($_SESSION['db_config']['host']); ?>:<?php echo htmlspecialchars($_SESSION['db_config']['port']); ?></p>
                            <p class="text-sm text-gray-600">Database: <?php echo htmlspecialchars($_SESSION['db_config']['database']); ?></p>
                        </div>
                        
                        <div class="p-4 rounded-xl bg-gray-50 border border-gray-200">
                            <h3 class="font-semibold mb-2">Site Settings</h3>
                            <p class="text-sm text-gray-600">Name: <?php echo htmlspecialchars($_SESSION['site_config']['site_name']); ?></p>
                            <p class="text-sm text-gray-600">Timezone: <?php echo htmlspecialchars($_SESSION['site_config']['timezone']); ?></p>
                        </div>
                        
                        <div class="p-4 rounded-xl bg-gray-50 border border-gray-200">
                            <h3 class="font-semibold mb-2">Billing</h3>
                            <p class="text-sm text-gray-600">Gateway: <?php echo htmlspecialchars($_SESSION['billing_config']['gateway'] ?? 'none'); ?></p>
                        </div>
                        
                        <?php if (isset($_SESSION['admin_config'])): ?>
                        <div class="p-4 rounded-xl bg-gray-50 border border-gray-200">
                            <h3 class="font-semibold mb-2">Admin Account</h3>
                            <p class="text-sm text-gray-600">Email: <?php echo htmlspecialchars($_SESSION['admin_config']['email']); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <form method="post">
                        <input type="hidden" name="action" value="finalize_installation">
                        <div class="flex space-x-3">
                            <a href="?step=admin" class="btn-secondary px-6 py-3 rounded-xl text-gray-700 flex-1 text-center">← Back</a>
                            <button type="submit" class="btn-primary px-6 py-3 rounded-xl text-white flex-1">Complete Installation →</button>
                        </div>
                    </form>
                    
                <?php elseif ($step === 'complete'): ?>
                    <div class="text-center">
                        <div class="w-20 h-20 mx-auto mb-6 rounded-full bg-green-100 flex items-center justify-center border border-green-200">
                            <svg class="w-10 h-10 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                        </div>
                        <h2 class="text-2xl font-bold mb-4">Installation Complete! 🎉</h2>
                        <p class="text-gray-600 mb-6">Your Business Card Generator SaaS is ready to use.</p>
                        
                        <?php if (isset($_SESSION['admin_config'])): ?>
                        <div class="mb-6 p-4 rounded-xl bg-blue-50 border border-blue-200">
                            <p class="text-blue-700 text-sm mb-2"><strong>Admin Login Credentials:</strong></p>
                            <p class="text-blue-600 text-sm">Email: <?php echo htmlspecialchars($_SESSION['admin_config']['email']); ?></p>
                            <p class="text-blue-600 text-sm">Company Code: Check your email or login page</p>
                        </div>
                        <?php endif; ?>
                        
                        <div class="space-y-3 mb-6">
                            <a href="<?php echo getBasePath(); ?>" class="btn-primary block px-6 py-3 rounded-xl text-white">
                                Go to Homepage
                            </a>
                            <?php if (isset($_SESSION['admin_config'])): ?>
                            <a href="<?php echo getBasePath(); ?>company/login.php" class="btn-secondary block px-6 py-3 rounded-xl text-white">
                                Login to Admin Panel
                            </a>
                            <?php else: ?>
                            <a href="<?php echo getBasePath(); ?>company/register.php" class="btn-secondary block px-6 py-3 rounded-xl text-white">
                                Create Your First Company
                            </a>
                            <?php endif; ?>
                        </div>
                        
                        <div class="mt-6 p-4 rounded-xl bg-amber-50 border border-amber-200">
                            <p class="text-amber-700 text-sm"><strong>Next Steps:</strong></p>
                            <ul class="text-left text-sm text-gray-600 mt-2 space-y-1">
                                <li>• Configure billing webhook in your payment gateway dashboard</li>
                                <li>• Set up your first company and add employees</li>
                                <li>• Create business card templates</li>
                                <li>• Review security settings for production</li>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php installerFooter(); ?>
