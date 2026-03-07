<?php
require_once __DIR__ . '/../config.php';
requireAdmin();

echo "<pre>";
echo "=== Debug Employee Import ===\n\n";

$companyId = getCurrentCompanyId();
echo "Current Company ID: $companyId\n\n";

// Test adding an employee directly
echo "=== Testing addEmployee Function ===\n";

$testData = [
    'email' => 'test@example.com',
    'name_en' => 'Test User',
    'name_ar' => 'مستخدم تجريبي',
    'position_en' => 'Developer',
    'position_ar' => 'مطور',
    'phone' => '+968 1234 5678',
    'mobile' => '+968 9876 5432',
    'company_en' => 'Test Company',
    'company_ar' => 'شركة تجريبية',
    'website' => 'www.test.com',
    'address_en' => '123 Test Street',
    'address_ar' => 'شارع الاختبار 123'
];

echo "Test data:\n";
print_r($testData);

echo "\nCalling addEmployee()...\n";
$result = addEmployee($testData, $companyId);

echo "Result:\n";
print_r($result);

echo "\n=== Checking Database After Insert ===\n";
$db = Database::getInstance();
$allEmployees = $db->fetchAll("SELECT id, company_id, email, name_en FROM employees");
echo "Employees in database: " . count($allEmployees) . "\n";
foreach ($allEmployees as $emp) {
    echo "- {$emp['email']} ({$emp['name_en']})\n";
}

echo "\n=== Checking employees table structure ===\n";
try {
    $columns = $db->fetchAll("SHOW COLUMNS FROM employees");
    echo "Columns in employees table:\n";
    foreach ($columns as $col) {
        echo "- {$col['Field']} ({$col['Type']})\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "</pre>";
