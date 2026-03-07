<?php
/**
 * Debug script for admin/index.php
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Admin Index Debug</h2>";

try {
    echo "1. Loading config.php...<br>";
    require_once __DIR__ . '/../config.php';
    echo "   OK<br><br>";
    
    echo "1b. Checking database connection...<br>";
    echo "   DB_HOST: " . (defined('DB_HOST') ? DB_HOST : 'not defined') . "<br>";
    echo "   DB_NAME: " . (defined('DB_NAME') ? DB_NAME : 'not defined') . "<br>";
    echo "   DB_USER: " . (defined('DB_USER') ? DB_USER : 'not defined') . "<br>";
    echo "   DB_PASS: " . (defined('DB_PASS') ? (empty(DB_PASS) ? '(empty)' : '(set)') : 'not defined') . "<br>";
    
    $db = Database::getInstance();
    echo "   Database instance: " . ($db ? 'yes' : 'no') . "<br>";
    echo "   isConnected(): " . ($db->isConnected() ? 'true' : 'false') . "<br>";
    
    if (!$db->isConnected()) {
        echo "   <strong style='color:red'>DATABASE NOT CONNECTED!</strong><br>";
        echo "   Trying to connect manually...<br>";
        $result = $db->connect(DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_PORT, DB_TYPE);
        echo "   connect() result: " . ($result ? 'success' : 'FAILED') . "<br>";
        
        if (!$result) {
            echo "   <strong style='color:red'>Check database credentials in config.php!</strong><br>";
        }
    }
    echo "<br>";
    
    echo "2. Loading Auth.php...<br>";
    require_once INCLUDES_DIR . '/Auth.php';
    echo "   OK<br><br>";
    
    echo "3. Checking Auth::isLoggedIn()...<br>";
    $isLoggedIn = Auth::isLoggedIn();
    echo "   isLoggedIn: " . ($isLoggedIn ? 'true' : 'false') . "<br><br>";
    
    if (!$isLoggedIn) {
        echo "<strong>Not logged in - would redirect to login.php</strong><br>";
        echo "Continuing debug anyway...<br><br>";
    }
    
    echo "4. Getting current user...<br>";
    $currentUser = Auth::getCurrentUser();
    echo "   currentUser: " . json_encode($currentUser) . "<br><br>";
    
    echo "5. Getting user role...<br>";
    $userRole = Auth::getCurrentRole();
    echo "   userRole: " . ($userRole ?? 'null') . "<br><br>";
    
    echo "6. Getting company ID...<br>";
    $companyId = getCurrentCompanyId();
    echo "   companyId: " . ($companyId ?? 'null') . "<br><br>";
    
    echo "7. Loading templates...<br>";
    $templatesConfig = loadTemplates();
    echo "   templatesConfig keys: " . implode(', ', array_keys($templatesConfig)) . "<br>";
    $templates = $templatesConfig['templates'] ?? [];
    echo "   templates count: " . count($templates) . "<br><br>";
    
    echo "8. Converting legacy field positions...<br>";
    foreach ($templates as &$template) {
        if (isset($template['fields'])) {
            $template['fields'] = convertLegacyFieldPositions($template['fields']);
        }
    }
    unset($template);
    echo "   OK<br><br>";
    
    echo "9. Loading employees...<br>";
    $employees = loadEmployees();
    $employeeCount = count($employees);
    echo "   employees count: " . $employeeCount . "<br><br>";
    
    echo "10. Loading generated log...<br>";
    $generatedLog = loadGeneratedLog();
    $generatedCount = count($generatedLog);
    echo "   generatedCount: " . $generatedCount . "<br><br>";
    
    echo "11. Testing getDefaultFieldSettings...<br>";
    $defaultFields = getDefaultFieldSettings();
    echo "   fields: " . implode(', ', array_keys($defaultFields)) . "<br><br>";
    
    echo "12. Testing JSON encoding...<br>";
    $templatesJson = json_encode(array_values($templates), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    echo "   templates JSON length: " . strlen($templatesJson) . "<br>";
    $defaultFieldsJson = json_encode($defaultFields, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    echo "   defaultFields JSON length: " . strlen($defaultFieldsJson) . "<br><br>";
    
    echo "13. Loading admin-layout.php...<br>";
    require_once INCLUDES_DIR . '/admin-layout.php';
    echo "   OK<br><br>";
    
    echo "<h3 style='color: green;'>All checks passed!</h3>";
    
} catch (Throwable $e) {
    echo "<h3 style='color: red;'>ERROR!</h3>";
    echo "Message: " . $e->getMessage() . "<br>";
    echo "File: " . $e->getFile() . "<br>";
    echo "Line: " . $e->getLine() . "<br>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
