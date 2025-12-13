<?php
/**
 * Example configuration file.
 *
 * Copy to `config.php` and customize for your installation.
 * IMPORTANT: `config.php` is intentionally excluded from Git (contains secrets).
 */

// Start session for admin authentication
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Admin password (change this!)
define('ADMIN_PASSWORD', 'change-me');

// Base paths
define('BASE_DIR', __DIR__);
define('INCLUDES_DIR', BASE_DIR . '/includes');
define('DATA_DIR', BASE_DIR . '/data');
define('UPLOADS_DIR', BASE_DIR . '/uploads');
define('TEMPLATES_DIR', UPLOADS_DIR . '/templates');
define('CARDS_DIR', UPLOADS_DIR . '/cards');
define('EXCEL_DIR', UPLOADS_DIR . '/excel');
define('ASSETS_DIR', BASE_DIR . '/assets');

// Data files (local JSON storage for MVP; future SaaS uses database)
define('EMPLOYEES_JSON', DATA_DIR . '/employees.json');
define('TEMPLATES_JSON', DATA_DIR . '/templates.json');
define('GENERATED_JSON', DATA_DIR . '/generated.json');

// Site settings
define('SITE_NAME', 'Business Cards');
define('SITE_DESCRIPTION', 'Professional Business Card Generator');

// Timezone
date_default_timezone_set('Asia/Muscat');

// Error reporting (set to 0 in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include helper functions
require_once BASE_DIR . '/includes/functions.php';


