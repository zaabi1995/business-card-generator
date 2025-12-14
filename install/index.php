<?php
/**
 * Complete Installation Wizard
 * Handles full setup: database, site config, billing, admin account
 */
session_start();

// Prevent access if already installed
if (file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
    if (defined('DB_HOST') && !empty(DB_HOST)) {
        $db = Database::getInstance();
        if ($db->isConnected() && $db->isSetup()) {
            try {
                $setting = $db->fetchOne("SELECT setting_value FROM system_settings WHERE setting_key = 'installation_complete'");
                if ($setting && $setting['setting_value'] === '1') {
                    header('Location: ' . getBasePath());
                    exit;
                }
            } catch (Exception $e) {
                // Continue installation
            }
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
                $step = 'site_config';
                $success[] = 'Database migration completed successfully!';
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
            
            if (!file_put_contents(__DIR__ . '/../config.php', $configContent)) {
                $errors[] = 'Failed to write config.php. Please check file permissions.';
                $step = 'finalize';
                break;
            }
            
            // Initialize database and complete setup
            require_once __DIR__ . '/../config.php';
            require_once __DIR__ . '/../includes/functions.php';
            require_once __DIR__ . '/../includes/DatabaseAdapter.php';
            
            $db = Database::getInstance();
            if (!$db->isConnected()) {
                $db->connect(DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_PORT, DB_TYPE);
            }
            
            // Update system settings
            $db->query("UPDATE system_settings SET setting_value = '1' WHERE setting_key = 'installation_complete'");
            $db->query("UPDATE system_settings SET setting_value = :value WHERE setting_key = 'site_name'", 
                ['value' => $siteConfig['site_name']]);
            $db->query("UPDATE system_settings SET setting_value = :value WHERE setting_key = 'site_description'", 
                ['value' => $siteConfig['site_description']]);
            
            // Create admin company if admin config provided
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

// Data files (JSON fallback if database not available)
define('EMPLOYEES_JSON', DATA_DIR . '/employees.json');
define('TEMPLATES_JSON', DATA_DIR . '/templates.json');
define('GENERATED_JSON', DATA_DIR . '/generated.json');

// Site settings
define('SITE_NAME', '{$siteName}');
define('SITE_DESCRIPTION', '{$siteDescription}');

// Timezone
date_default_timezone_set('{$timezone}');

// Error reporting (set to 0 in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);
{$billingSection}

// Include required files
require_once INCLUDES_DIR . '/Database.php';
require_once INCLUDES_DIR . '/functions.php';
require_once INCLUDES_DIR . '/DatabaseAdapter.php';

// Initialize database connection (if configured)
if (defined('DB_HOST') && !empty(DB_HOST) && !empty(DB_NAME)) {
    \$db = Database::getInstance();
    if (!\$db->isConnected()) {
        \$db->connect(DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_PORT, DB_TYPE);
    }
    // Initialize DatabaseAdapter
    DatabaseAdapter::init();
}

PHP;
}

function getBasePath() {
    $scriptPath = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '/install/index.php';
    $scriptDir = dirname(dirname($scriptPath));
    return $scriptDir === '/' ? '/' : rtrim($scriptDir, '/') . '/';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation Wizard | Business Card Generator</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo getBasePath(); ?>assets/css/tailwind.css">
    <style>
        .glass-card { background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.1); }
        .input-bhd { background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.15); transition: all 0.3s ease; }
        .input-bhd:focus { background: rgba(255, 255, 255, 0.08); border-color: rgba(212, 175, 55, 0.6); box-shadow: 0 0 0 4px rgba(212, 175, 55, 0.1); }
        .btn-bhd { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%); transition: all 0.3s ease; border: 1px solid rgba(212, 175, 55, 0.3); }
        .btn-bhd:hover { box-shadow: 0 0 30px rgba(212, 175, 55, 0.3); border-color: rgba(212, 175, 55, 0.6); }
        .btn-secondary { background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.15); }
        .btn-secondary:hover { background: rgba(255, 255, 255, 0.1); }
        .step-indicator { display: flex; justify-content: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 0.5rem; }
        .step { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; }
        .step.active { background: rgba(212, 175, 55, 0.3); border: 2px solid rgba(212, 175, 55, 0.6); color: #d4af37; }
        .step.completed { background: rgba(34, 197, 94, 0.3); border: 2px solid rgba(34, 197, 94, 0.6); color: #22c55e; }
        .step.pending { background: rgba(255, 255, 255, 0.05); border: 2px solid rgba(255, 255, 255, 0.1); color: #9ca3af; }
        .info-box { background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3); padding: 1rem; border-radius: 0.75rem; margin-bottom: 1rem; }
        .info-box p { color: #93c5fd; font-size: 0.875rem; }
    </style>
</head>
<body class="bg-alzayani-dark text-white font-sans min-h-screen antialiased">
    <div class="min-h-screen flex items-center justify-center px-4 py-12">
        <div class="w-full max-w-3xl">
            <div class="text-center mb-8">
                <h1 class="text-4xl font-bold text-white mb-2">Installation Wizard</h1>
                <p class="text-gray-400">Complete setup for your Business Card Generator SaaS</p>
            </div>
            
            <!-- Step Indicator -->
            <div class="step-indicator">
                <div class="step <?php echo $step === 'welcome' || $step === 'requirements' ? 'active' : ($step !== 'welcome' && $step !== 'requirements' ? 'completed' : 'pending'); ?>">1</div>
                <div class="step <?php echo $step === 'database' ? 'active' : ($step === 'migrate' || $step === 'site_config' || $step === 'billing' || $step === 'admin' || $step === 'finalize' || $step === 'complete' ? 'completed' : 'pending'); ?>">2</div>
                <div class="step <?php echo $step === 'migrate' ? 'active' : ($step === 'site_config' || $step === 'billing' || $step === 'admin' || $step === 'finalize' || $step === 'complete' ? 'completed' : 'pending'); ?>">3</div>
                <div class="step <?php echo $step === 'site_config' ? 'active' : ($step === 'billing' || $step === 'admin' || $step === 'finalize' || $step === 'complete' ? 'completed' : 'pending'); ?>">4</div>
                <div class="step <?php echo $step === 'billing' ? 'active' : ($step === 'admin' || $step === 'finalize' || $step === 'complete' ? 'completed' : 'pending'); ?>">5</div>
                <div class="step <?php echo $step === 'admin' ? 'active' : ($step === 'finalize' || $step === 'complete' ? 'completed' : 'pending'); ?>">6</div>
                <div class="step <?php echo $step === 'finalize' ? 'active' : ($step === 'complete' ? 'completed' : 'pending'); ?>">7</div>
            </div>
            
            <!-- Messages -->
            <?php if (!empty($errors)): ?>
            <div class="mb-6 p-4 rounded-xl bg-red-500/10 border border-red-500/30">
                <?php foreach ($errors as $error): ?>
                <p class="text-red-400 text-sm"><?php echo htmlspecialchars($error); ?></p>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($success)): ?>
            <div class="mb-6 p-4 rounded-xl bg-green-500/10 border border-green-500/30">
                <?php foreach ($success as $msg): ?>
                <p class="text-green-400 text-sm"><?php echo htmlspecialchars($msg); ?></p>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <!-- Step Content -->
            <div class="glass-card rounded-2xl p-8">
                <?php if ($step === 'welcome' || $step === 'requirements'): ?>
                    <h2 class="text-2xl font-bold mb-4">Welcome</h2>
                    <p class="text-gray-400 mb-6">This wizard will guide you through the complete setup of your Business Card Generator SaaS platform.</p>
                    
                    <h3 class="text-lg font-semibold mb-4">Requirements Check</h3>
                    <ul class="space-y-2 mb-6">
                        <li class="flex items-center space-x-2">
                            <span class="<?php echo version_compare(PHP_VERSION, '7.4.0', '>=') ? 'text-green-400' : 'text-red-400'; ?>">
                                <?php echo version_compare(PHP_VERSION, '7.4.0', '>=') ? '✓' : '✗'; ?>
                            </span>
                            <span>PHP <?php echo version_compare(PHP_VERSION, '7.4.0', '>=') ? '≥7.4 (' . PHP_VERSION . ')' : PHP_VERSION . ' (Need ≥7.4)'; ?></span>
                        </li>
                        <li class="flex items-center space-x-2">
                            <span class="<?php echo extension_loaded('pdo') ? 'text-green-400' : 'text-red-400'; ?>">
                                <?php echo extension_loaded('pdo') ? '✓' : '✗'; ?>
                            </span>
                            <span>PDO Extension: <?php echo extension_loaded('pdo') ? 'Installed' : 'Missing'; ?></span>
                        </li>
                        <li class="flex items-center space-x-2">
                            <span class="<?php echo extension_loaded('json') ? 'text-green-400' : 'text-red-400'; ?>">
                                <?php echo extension_loaded('json') ? '✓' : '✗'; ?>
                            </span>
                            <span>JSON Extension: <?php echo extension_loaded('json') ? 'Installed' : 'Missing'; ?></span>
                        </li>
                        <li class="flex items-center space-x-2">
                            <span class="<?php echo is_writable(__DIR__ . '/../data') ? 'text-green-400' : 'text-red-400'; ?>">
                                <?php echo is_writable(__DIR__ . '/../data') ? '✓' : '✗'; ?>
                            </span>
                            <span>Data directory writable</span>
                        </li>
                        <li class="flex items-center space-x-2">
                            <span class="<?php echo is_writable(__DIR__ . '/../uploads') ? 'text-green-400' : 'text-red-400'; ?>">
                                <?php echo is_writable(__DIR__ . '/../uploads') ? '✓' : '✗'; ?>
                            </span>
                            <span>Uploads directory writable</span>
                        </li>
                        <li class="flex items-center space-x-2">
                            <span class="<?php echo is_writable(__DIR__ . '/../') ? 'text-green-400' : 'text-red-400'; ?>">
                                <?php echo is_writable(__DIR__ . '/../') ? '✓' : '✗'; ?>
                            </span>
                            <span>Root directory writable (for config.php)</span>
                        </li>
                    </ul>
                    
                    <form method="post">
                        <input type="hidden" name="action" value="check_requirements">
                        <button type="submit" class="btn-bhd w-full py-4 rounded-xl text-white font-bold">
                            Continue to Database Setup →
                        </button>
                    </form>
                    
                <?php elseif ($step === 'database'): ?>
                    <h2 class="text-2xl font-bold mb-4">Database Configuration</h2>
                    <p class="text-gray-400 mb-6">Enter your database connection details. Make sure the database exists.</p>
                    
                    <form method="post">
                        <input type="hidden" name="action" value="test_database">
                        
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-2">Database Type</label>
                                <select name="db_type" class="input-bhd w-full px-4 py-3 rounded-xl text-white">
                                    <option value="mysql">MySQL/MariaDB</option>
                                    <option value="pgsql">PostgreSQL</option>
                                </select>
                            </div>
                            
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-2">Database Host</label>
                                    <input type="text" name="db_host" value="localhost" required class="input-bhd w-full px-4 py-3 rounded-xl text-white">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-2">Database Port</label>
                                    <input type="number" name="db_port" placeholder="Auto" class="input-bhd w-full px-4 py-3 rounded-xl text-white">
                                    <p class="text-xs text-gray-500 mt-1">Leave empty for default (3306 MySQL, 5432 PostgreSQL)</p>
                                </div>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-2">Database Name</label>
                                <input type="text" name="db_name" required class="input-bhd w-full px-4 py-3 rounded-xl text-white" placeholder="business_cards">
                                <p class="text-xs text-gray-500 mt-1">Create this database first if it doesn't exist</p>
                            </div>
                            
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-2">Database Username</label>
                                    <input type="text" name="db_user" required class="input-bhd w-full px-4 py-3 rounded-xl text-white">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-2">Database Password</label>
                                    <input type="password" name="db_pass" class="input-bhd w-full px-4 py-3 rounded-xl text-white">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-6 flex space-x-3">
                            <a href="?step=requirements" class="btn-secondary px-6 py-3 rounded-xl text-white flex-1 text-center">← Back</a>
                            <button type="submit" class="btn-bhd px-6 py-3 rounded-xl text-white flex-1">Test Connection →</button>
                        </div>
                    </form>
                    
                <?php elseif ($step === 'migrate'): ?>
                    <h2 class="text-2xl font-bold mb-4">Database Migration</h2>
                    <p class="text-gray-400 mb-6">Ready to create database tables and set up the schema. This will create all necessary tables.</p>
                    
                    <div class="info-box">
                        <p><strong>Note:</strong> This will create tables for companies, employees, templates, subscriptions, and more. Existing data will be preserved if tables already exist.</p>
                    </div>
                    
                    <form method="post">
                        <input type="hidden" name="action" value="run_migration">
                        <div class="flex space-x-3">
                            <a href="?step=database" class="btn-secondary px-6 py-3 rounded-xl text-white flex-1 text-center">← Back</a>
                            <button type="submit" class="btn-bhd px-6 py-3 rounded-xl text-white flex-1">Run Migration →</button>
                        </div>
                    </form>
                    
                <?php elseif ($step === 'site_config'): ?>
                    <h2 class="text-2xl font-bold mb-4">Site Configuration</h2>
                    <p class="text-gray-400 mb-6">Configure your site name and basic settings.</p>
                    
                    <form method="post">
                        <input type="hidden" name="action" value="save_site_config">
                        
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-2">Site Name</label>
                                <input type="text" name="site_name" value="<?php echo htmlspecialchars($_SESSION['site_config']['site_name'] ?? 'Business Cards'); ?>" required class="input-bhd w-full px-4 py-3 rounded-xl text-white">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-2">Site Description</label>
                                <input type="text" name="site_description" value="<?php echo htmlspecialchars($_SESSION['site_config']['site_description'] ?? 'Professional Business Card Generator'); ?>" class="input-bhd w-full px-4 py-3 rounded-xl text-white">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-2">Timezone</label>
                                <select name="timezone" class="input-bhd w-full px-4 py-3 rounded-xl text-white">
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
                            <a href="?step=migrate" class="btn-secondary px-6 py-3 rounded-xl text-white flex-1 text-center">← Back</a>
                            <button type="submit" class="btn-bhd px-6 py-3 rounded-xl text-white flex-1">Continue →</button>
                        </div>
                    </form>
                    
                <?php elseif ($step === 'billing'): ?>
                    <h2 class="text-2xl font-bold mb-4">Billing Configuration</h2>
                    <p class="text-gray-400 mb-6">Configure your payment gateway. You can skip this and configure later.</p>
                    
                    <form method="post" id="billingForm">
                        <input type="hidden" name="action" value="save_billing_config">
                        
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-2">Payment Gateway</label>
                                <select name="billing_gateway" id="gatewaySelect" class="input-bhd w-full px-4 py-3 rounded-xl text-white" onchange="toggleGatewayFields()">
                                    <option value="none">Skip (Configure Later)</option>
                                    <option value="amwal">Amwal Pay</option>
                                    <option value="stripe">Stripe</option>
                                </select>
                            </div>
                            
                            <!-- Amwal Pay Fields -->
                            <div id="amwalFields" style="display: none;">
                                <h3 class="text-lg font-semibold mb-3 mt-4">Amwal Pay Settings</h3>
                                <p class="text-sm text-gray-400 mb-4">Get these credentials from your Amwal Pay merchant dashboard after signing up.</p>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-2">Merchant ID</label>
                                    <input type="text" name="amwal_merchant_id" value="<?php echo htmlspecialchars($_SESSION['billing_config']['amwal_merchant_id'] ?? ''); ?>" class="input-bhd w-full px-4 py-3 rounded-xl text-white" placeholder="Your Merchant ID" required>
                                    <p class="text-xs text-gray-500 mt-1">Provided by Amwal Pay after account setup</p>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-2">Terminal ID</label>
                                    <input type="text" name="amwal_terminal_id" value="<?php echo htmlspecialchars($_SESSION['billing_config']['amwal_terminal_id'] ?? ''); ?>" class="input-bhd w-full px-4 py-3 rounded-xl text-white" placeholder="Your Terminal ID" required>
                                    <p class="text-xs text-gray-500 mt-1">Provided by Amwal Pay after account setup</p>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-2">Secure Key</label>
                                    <input type="password" name="amwal_secure_key" value="<?php echo htmlspecialchars($_SESSION['billing_config']['amwal_secure_key'] ?? ''); ?>" class="input-bhd w-full px-4 py-3 rounded-xl text-white" placeholder="Your Secure Key" required>
                                    <p class="text-xs text-gray-500 mt-1">Keep this secret! Used for payment signature verification</p>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-2">API URL</label>
                                    <input type="text" name="amwal_api_url" value="<?php echo htmlspecialchars($_SESSION['billing_config']['amwal_api_url'] ?? 'https://backend.sa.amwal.tech'); ?>" class="input-bhd w-full px-4 py-3 rounded-xl text-white">
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
                                    <label class="block text-sm font-medium text-gray-300 mb-2">Secret Key</label>
                                    <input type="text" name="stripe_secret_key" value="<?php echo htmlspecialchars($_SESSION['billing_config']['stripe_secret_key'] ?? ''); ?>" class="input-bhd w-full px-4 py-3 rounded-xl text-white" placeholder="sk_live_...">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-2">Public Key</label>
                                    <input type="text" name="stripe_public_key" value="<?php echo htmlspecialchars($_SESSION['billing_config']['stripe_public_key'] ?? ''); ?>" class="input-bhd w-full px-4 py-3 rounded-xl text-white" placeholder="pk_live_...">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-6 flex space-x-3">
                            <a href="?step=site_config" class="btn-secondary px-6 py-3 rounded-xl text-white flex-1 text-center">← Back</a>
                            <button type="button" onclick="document.getElementById('skipForm').submit()" class="btn-secondary px-6 py-3 rounded-xl text-white">Skip</button>
                            <button type="submit" class="btn-bhd px-6 py-3 rounded-xl text-white flex-1">Continue →</button>
                        </div>
                    </form>
                    
                    <form method="post" id="skipForm" style="display: none;">
                        <input type="hidden" name="action" value="skip_billing">
                    </form>
                    
                    <script>
                        function toggleGatewayFields() {
                            const gateway = document.getElementById('gatewaySelect').value;
                            document.getElementById('amwalFields').style.display = gateway === 'amwal' ? 'block' : 'none';
                            document.getElementById('stripeFields').style.display = gateway === 'stripe' ? 'block' : 'none';
                        }
                        toggleGatewayFields();
                    </script>
                    
                <?php elseif ($step === 'admin'): ?>
                    <h2 class="text-2xl font-bold mb-4">Admin Account</h2>
                    <p class="text-gray-400 mb-6">Create your first admin company account. This will be used to manage the platform.</p>
                    
                    <div class="info-box">
                        <p>This will create a company account that you can use to login and manage employees, templates, and billing.</p>
                    </div>
                    
                    <form method="post">
                        <input type="hidden" name="action" value="create_admin">
                        
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-2">Company/Admin Name</label>
                                <input type="text" name="admin_name" value="<?php echo htmlspecialchars($_SESSION['admin_config']['name'] ?? 'Admin'); ?>" required class="input-bhd w-full px-4 py-3 rounded-xl text-white" placeholder="My Company">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-2">Admin Email</label>
                                <input type="email" name="admin_email" value="<?php echo htmlspecialchars($_SESSION['admin_config']['email'] ?? ''); ?>" required class="input-bhd w-full px-4 py-3 rounded-xl text-white" placeholder="admin@company.com">
                                <p class="text-xs text-gray-500 mt-1">This will be your login email</p>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-2">Password</label>
                                <input type="password" name="admin_password" required class="input-bhd w-full px-4 py-3 rounded-xl text-white" placeholder="Minimum 8 characters">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-2">Confirm Password</label>
                                <input type="password" name="admin_password_confirm" required class="input-bhd w-full px-4 py-3 rounded-xl text-white">
                            </div>
                        </div>
                        
                        <div class="mt-6 flex space-x-3">
                            <a href="?step=billing" class="btn-secondary px-6 py-3 rounded-xl text-white flex-1 text-center">← Back</a>
                            <button type="submit" class="btn-bhd px-6 py-3 rounded-xl text-white flex-1">Continue →</button>
                        </div>
                    </form>
                    
                <?php elseif ($step === 'finalize'): ?>
                    <h2 class="text-2xl font-bold mb-4">Finalize Installation</h2>
                    <p class="text-gray-400 mb-6">Review your configuration and complete the installation.</p>
                    
                    <div class="space-y-4 mb-6">
                        <div class="p-4 rounded-xl bg-white/5">
                            <h3 class="font-semibold mb-2">Database</h3>
                            <p class="text-sm text-gray-400"><?php echo htmlspecialchars($_SESSION['db_config']['type']); ?> - <?php echo htmlspecialchars($_SESSION['db_config']['host']); ?>:<?php echo htmlspecialchars($_SESSION['db_config']['port']); ?></p>
                            <p class="text-sm text-gray-400">Database: <?php echo htmlspecialchars($_SESSION['db_config']['database']); ?></p>
                        </div>
                        
                        <div class="p-4 rounded-xl bg-white/5">
                            <h3 class="font-semibold mb-2">Site Settings</h3>
                            <p class="text-sm text-gray-400">Name: <?php echo htmlspecialchars($_SESSION['site_config']['site_name']); ?></p>
                            <p class="text-sm text-gray-400">Timezone: <?php echo htmlspecialchars($_SESSION['site_config']['timezone']); ?></p>
                        </div>
                        
                        <div class="p-4 rounded-xl bg-white/5">
                            <h3 class="font-semibold mb-2">Billing</h3>
                            <p class="text-sm text-gray-400">Gateway: <?php echo htmlspecialchars($_SESSION['billing_config']['gateway'] ?? 'none'); ?></p>
                        </div>
                        
                        <?php if (isset($_SESSION['admin_config'])): ?>
                        <div class="p-4 rounded-xl bg-white/5">
                            <h3 class="font-semibold mb-2">Admin Account</h3>
                            <p class="text-sm text-gray-400">Email: <?php echo htmlspecialchars($_SESSION['admin_config']['email']); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <form method="post">
                        <input type="hidden" name="action" value="finalize_installation">
                        <div class="flex space-x-3">
                            <a href="?step=admin" class="btn-secondary px-6 py-3 rounded-xl text-white flex-1 text-center">← Back</a>
                            <button type="submit" class="btn-bhd px-6 py-3 rounded-xl text-white flex-1">Complete Installation →</button>
                        </div>
                    </form>
                    
                <?php elseif ($step === 'complete'): ?>
                    <div class="text-center">
                        <div class="w-20 h-20 mx-auto mb-6 rounded-full bg-green-500/20 flex items-center justify-center border border-green-500/30">
                            <svg class="w-10 h-10 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                        </div>
                        <h2 class="text-2xl font-bold mb-4">Installation Complete! 🎉</h2>
                        <p class="text-gray-400 mb-6">Your Business Card Generator SaaS is ready to use.</p>
                        
                        <?php if (isset($_SESSION['admin_config'])): ?>
                        <div class="mb-6 p-4 rounded-xl bg-blue-500/10 border border-blue-500/30">
                            <p class="text-blue-400 text-sm mb-2"><strong>Admin Login Credentials:</strong></p>
                            <p class="text-blue-300 text-sm">Email: <?php echo htmlspecialchars($_SESSION['admin_config']['email']); ?></p>
                            <p class="text-blue-300 text-sm">Company Code: Check your email or login page</p>
                        </div>
                        <?php endif; ?>
                        
                        <div class="space-y-3 mb-6">
                            <a href="<?php echo getBasePath(); ?>" class="btn-bhd block px-6 py-3 rounded-xl text-white">
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
                        
                        <div class="mt-6 p-4 rounded-xl bg-amber-500/10 border border-amber-500/30">
                            <p class="text-amber-400 text-sm"><strong>Next Steps:</strong></p>
                            <ul class="text-left text-sm text-gray-400 mt-2 space-y-1">
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
</body>
</html>
