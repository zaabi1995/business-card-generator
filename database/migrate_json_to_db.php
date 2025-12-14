<?php
/**
 * Migration Script: JSON to Database
 * Migrates existing JSON data to database
 * Run this after installation if you have existing JSON data
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/Database.php';

$db = Database::getInstance();

if (!$db->isConnected()) {
    die("Database not connected. Please run installation first.\n");
}

echo "Starting JSON to Database migration...\n\n";

// Migrate companies
if (file_exists(COMPANIES_JSON)) {
    echo "Migrating companies...\n";
    $companies = json_decode(file_get_contents(COMPANIES_JSON), true);
    if (is_array($companies)) {
        foreach ($companies as $company) {
            try {
                // Check if company exists
                $existing = $db->fetchOne("SELECT id FROM companies WHERE id = :id", ['id' => $company['id']]);
                
                if (!$existing) {
                    $db->insert('companies', [
                        'id' => $company['id'],
                        'name' => $company['name'] ?? '',
                        'slug' => $company['slug'] ?? '',
                        'admin_email' => $company['admin_email'] ?? '',
                        'password_hash' => $company['password_hash'] ?? '',
                        'plan' => $company['plan'] ?? 'free',
                        'status' => $company['status'] ?? 'active',
                        'created_at' => $company['created_at'] ?? date('Y-m-d H:i:s')
                    ]);
                    echo "  ✓ Migrated company: {$company['name']}\n";
                } else {
                    echo "  - Company already exists: {$company['name']}\n";
                }
            } catch (Exception $e) {
                echo "  ✗ Error migrating company {$company['name']}: " . $e->getMessage() . "\n";
            }
        }
    }
}

// Migrate employees (per company)
$companies = $db->fetchAll("SELECT id FROM companies");
foreach ($companies as $company) {
    $companyId = $company['id'];
    $employeesPath = getCompanyEmployeesJsonPath($companyId);
    
    if (file_exists($employeesPath)) {
        echo "\nMigrating employees for company {$companyId}...\n";
        $employees = json_decode(file_get_contents($employeesPath), true);
        if (is_array($employees)) {
            foreach ($employees as $employee) {
                try {
                    $existing = $db->fetchOne(
                        "SELECT id FROM employees WHERE id = :id",
                        ['id' => $employee['id']]
                    );
                    
                    if (!$existing) {
                        $db->insert('employees', [
                            'id' => $employee['id'],
                            'company_id' => $companyId,
                            'email' => $employee['email'] ?? '',
                            'name_en' => $employee['name_en'] ?? '',
                            'name_ar' => $employee['name_ar'] ?? '',
                            'position_en' => $employee['position_en'] ?? '',
                            'position_ar' => $employee['position_ar'] ?? '',
                            'phone' => $employee['phone'] ?? '',
                            'mobile' => $employee['mobile'] ?? '',
                            'company_en' => $employee['company_en'] ?? '',
                            'company_ar' => $employee['company_ar'] ?? '',
                            'website' => $employee['website'] ?? '',
                            'address' => $employee['address'] ?? '',
                            'created_at' => $employee['created_at'] ?? date('Y-m-d H:i:s')
                        ]);
                        echo "  ✓ Migrated employee: {$employee['email']}\n";
                    } else {
                        echo "  - Employee already exists: {$employee['email']}\n";
                    }
                } catch (Exception $e) {
                    echo "  ✗ Error migrating employee {$employee['email']}: " . $e->getMessage() . "\n";
                }
            }
        }
    }
}

// Migrate templates (per company)
foreach ($companies as $company) {
    $companyId = $company['id'];
    $templatesPath = getCompanyTemplatesJsonPath($companyId);
    
    if (file_exists($templatesPath)) {
        echo "\nMigrating templates for company {$companyId}...\n";
        $config = json_decode(file_get_contents($templatesPath), true);
        if (is_array($config) && isset($config['templates'])) {
            foreach ($config['templates'] as $template) {
                try {
                    $existing = $db->fetchOne(
                        "SELECT id FROM templates WHERE id = :id",
                        ['id' => $template['id']]
                    );
                    
                    if (!$existing) {
                        $db->insert('templates', [
                            'id' => $template['id'],
                            'company_id' => $companyId,
                            'name' => $template['name'] ?? '',
                            'side' => $template['side'] ?? 'front',
                            'background_image_path' => $template['backgroundImage'] ?? '',
                            'fields_json' => json_encode($template['fields'] ?? []),
                            'is_active' => ($config['activeFrontId'] === $template['id'] || $config['activeBackId'] === $template['id']),
                            'created_at' => $template['created_at'] ?? date('Y-m-d H:i:s')
                        ]);
                        echo "  ✓ Migrated template: {$template['name']}\n";
                    } else {
                        echo "  - Template already exists: {$template['name']}\n";
                    }
                } catch (Exception $e) {
                    echo "  ✗ Error migrating template {$template['name']}: " . $e->getMessage() . "\n";
                }
            }
        }
    }
}

// Migrate generated cards log (per company)
foreach ($companies as $company) {
    $companyId = $company['id'];
    $generatedPath = getCompanyGeneratedJsonPath($companyId);
    
    if (file_exists($generatedPath)) {
        echo "\nMigrating generated cards log for company {$companyId}...\n";
        $log = json_decode(file_get_contents($generatedPath), true);
        if (is_array($log)) {
            foreach ($log as $entry) {
                try {
                    $existing = $db->fetchOne(
                        "SELECT id FROM generated_cards WHERE id = :id",
                        ['id' => $entry['id']]
                    );
                    
                    if (!$existing) {
                        $db->insert('generated_cards', [
                            'id' => $entry['id'],
                            'company_id' => $companyId,
                            'employee_id' => $entry['employee_id'] ?? null,
                            'front_template_id' => $entry['front_template_id'] ?? null,
                            'back_template_id' => $entry['back_template_id'] ?? null,
                            'front_file_path' => $entry['front_file'] ?? null,
                            'back_file_path' => $entry['back_file'] ?? null,
                            'pdf_file_path' => $entry['pdf_file'] ?? null,
                            'generated_at' => $entry['generated_at'] ?? date('Y-m-d H:i:s')
                        ]);
                        echo "  ✓ Migrated generated card entry\n";
                    }
                } catch (Exception $e) {
                    echo "  ✗ Error migrating generated card: " . $e->getMessage() . "\n";
                }
            }
        }
    }
}

echo "\n\nMigration completed!\n";
echo "You can now safely remove JSON files if desired (they are kept as backup).\n";
