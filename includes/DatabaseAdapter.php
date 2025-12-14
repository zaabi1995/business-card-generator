<?php
/**
 * Database Adapter
 * Provides database-backed implementations of data functions
 * Falls back to JSON if database not available
 */
class DatabaseAdapter {
    private static $db = null;
    private static $useDatabase = false;
    
    public static function init() {
        if (defined('DB_HOST') && !empty(DB_HOST) && !empty(DB_NAME)) {
            self::$db = Database::getInstance();
            if (!self::$db->isConnected()) {
                self::$useDatabase = self::$db->connect(
                    DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_PORT, DB_TYPE
                );
            } else {
                self::$useDatabase = true;
            }
        }
    }
    
    public static function useDatabase() {
        return self::$useDatabase && self::$db && self::$db->isConnected() && self::$db->isSetup();
    }
    
    // Company functions
    public static function loadCompanies() {
        if (!self::useDatabase()) {
            return loadCompanies(); // Fallback to JSON
        }
        
        return self::$db->fetchAll("SELECT * FROM companies ORDER BY created_at DESC");
    }
    
    public static function findCompanyBySlug($slug) {
        if (!self::useDatabase()) {
            return findCompanyBySlug($slug);
        }
        
        return self::$db->fetchOne("SELECT * FROM companies WHERE slug = :slug", ['slug' => $slug]);
    }
    
    public static function findCompanyById($id) {
        if (!self::useDatabase()) {
            return findCompanyById($id);
        }
        
        return self::$db->fetchOne("SELECT * FROM companies WHERE id = :id", ['id' => $id]);
    }
    
    public static function createCompany($name, $adminEmail, $password) {
        if (!self::useDatabase()) {
            return createCompany($name, $adminEmail, $password);
        }
        
        $slug = slugify($name);
        $baseSlug = $slug;
        $i = 1;
        while (self::findCompanyBySlug($slug)) {
            $slug = $baseSlug . '-' . $i;
            $i++;
        }
        
        $company = [
            'id' => generateUUID(),
            'name' => $name,
            'slug' => $slug,
            'admin_email' => $adminEmail,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'plan' => 'free',
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        try {
            self::$db->insert('companies', $company);
            
            // Initialize company directories
            getCompanyDataDir($company['id']);
            getCompanyUploadsDir($company['id']);
            getCompanyTemplatesDir($company['id']);
            getCompanyCardsDir($company['id']);
            
            return ['success' => true, 'company' => $company];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Failed to create company: ' . $e->getMessage()];
        }
    }
    
    // Employee functions
    public static function loadEmployees($companyId = null) {
        if (!self::useDatabase()) {
            return loadEmployees($companyId);
        }
        
        if ($companyId) {
            return self::$db->fetchAll(
                "SELECT * FROM employees WHERE company_id = :id ORDER BY created_at DESC",
                ['id' => $companyId]
            );
        }
        
        return self::$db->fetchAll("SELECT * FROM employees ORDER BY created_at DESC");
    }
    
    public static function findEmployeeByEmail($email, $companyId = null) {
        if (!self::useDatabase()) {
            return findEmployeeByEmail($email, $companyId);
        }
        
        $email = trim(strtolower($email));
        if ($companyId) {
            return self::$db->fetchOne(
                "SELECT * FROM employees WHERE company_id = :cid AND LOWER(email) = :email",
                ['cid' => $companyId, 'email' => $email]
            );
        }
        
        return self::$db->fetchOne("SELECT * FROM employees WHERE LOWER(email) = :email", ['email' => $email]);
    }
    
    public static function findEmployeeById($id, $companyId = null) {
        if (!self::useDatabase()) {
            return findEmployeeById($id, $companyId);
        }
        
        if ($companyId) {
            return self::$db->fetchOne(
                "SELECT * FROM employees WHERE id = :id AND company_id = :cid",
                ['id' => $id, 'cid' => $companyId]
            );
        }
        
        return self::$db->fetchOne("SELECT * FROM employees WHERE id = :id", ['id' => $id]);
    }
    
    public static function addEmployee($data, $companyId = null) {
        if (!self::useDatabase()) {
            return addEmployee($data, $companyId);
        }
        
        $companyId = $companyId ?: getCurrentCompanyId();
        if (!$companyId) {
            return ['success' => false, 'error' => 'Company ID required'];
        }
        
        // Check if email exists
        $existing = self::findEmployeeByEmail($data['email'] ?? '', $companyId);
        if ($existing) {
            return ['success' => false, 'error' => 'Email already exists'];
        }
        
        $employee = [
            'id' => generateUUID(),
            'company_id' => $companyId,
            'email' => trim(strtolower($data['email'] ?? '')),
            'name_en' => trim($data['name_en'] ?? ''),
            'name_ar' => trim($data['name_ar'] ?? ''),
            'position_en' => trim($data['position_en'] ?? ''),
            'position_ar' => trim($data['position_ar'] ?? ''),
            'phone' => trim($data['phone'] ?? ''),
            'mobile' => trim($data['mobile'] ?? ''),
            'company_en' => trim($data['company_en'] ?? ''),
            'company_ar' => trim($data['company_ar'] ?? ''),
            'website' => trim($data['website'] ?? ''),
            'address' => trim($data['address'] ?? ''),
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        try {
            self::$db->insert('employees', $employee);
            return ['success' => true, 'employee' => $employee];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Failed to save employee: ' . $e->getMessage()];
        }
    }
    
    public static function updateEmployee($id, $data, $companyId = null) {
        if (!self::useDatabase()) {
            return updateEmployee($id, $data, $companyId);
        }
        
        $companyId = $companyId ?: getCurrentCompanyId();
        
        // Check email conflict
        $newEmail = trim(strtolower($data['email'] ?? ''));
        $existing = self::findEmployeeByEmail($newEmail, $companyId);
        if ($existing && $existing['id'] !== $id) {
            return ['success' => false, 'error' => 'Email already exists'];
        }
        
        $updateData = [
            'email' => $newEmail,
            'name_en' => trim($data['name_en'] ?? ''),
            'name_ar' => trim($data['name_ar'] ?? ''),
            'position_en' => trim($data['position_en'] ?? ''),
            'position_ar' => trim($data['position_ar'] ?? ''),
            'phone' => trim($data['phone'] ?? ''),
            'mobile' => trim($data['mobile'] ?? ''),
            'company_en' => trim($data['company_en'] ?? ''),
            'company_ar' => trim($data['company_ar'] ?? ''),
            'website' => trim($data['website'] ?? ''),
            'address' => trim($data['address'] ?? ''),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        try {
            $where = 'id = :id';
            $whereParams = ['id' => $id];
            if ($companyId) {
                $where .= ' AND company_id = :cid';
                $whereParams['cid'] = $companyId;
            }
            
            self::$db->update('employees', $updateData, $where, $whereParams);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Failed to update employee: ' . $e->getMessage()];
        }
    }
    
    public static function deleteEmployee($id, $companyId = null) {
        if (!self::useDatabase()) {
            return deleteEmployee($id, $companyId);
        }
        
        try {
            $where = 'id = :id';
            $params = ['id' => $id];
            if ($companyId) {
                $where .= ' AND company_id = :cid';
                $params['cid'] = $companyId;
            }
            
            $count = self::$db->delete('employees', $where, $params);
            return $count > 0 
                ? ['success' => true] 
                : ['success' => false, 'error' => 'Employee not found'];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Failed to delete employee: ' . $e->getMessage()];
        }
    }
    
    // Template functions
    public static function loadTemplates($companyId = null) {
        if (!self::useDatabase()) {
            return loadTemplates($companyId);
        }
        
        $companyId = $companyId ?: getCurrentCompanyId();
        if (!$companyId) {
            return getDefaultTemplatesConfig();
        }
        
        $templates = self::$db->fetchAll(
            "SELECT * FROM templates WHERE company_id = :id ORDER BY created_at DESC",
            ['id' => $companyId]
        );
        
        $activeFrontId = null;
        $activeBackId = null;
        $templateList = [];
        
        foreach ($templates as $tpl) {
            $template = [
                'id' => $tpl['id'],
                'name' => $tpl['name'],
                'side' => $tpl['side'],
                'backgroundImage' => $tpl['background_image_path'],
                'fields' => json_decode($tpl['fields_json'], true) ?: getDefaultFieldSettings(),
                'created_at' => $tpl['created_at']
            ];
            
            if ($tpl['is_active']) {
                if ($tpl['side'] === 'front') {
                    $activeFrontId = $tpl['id'];
                } else {
                    $activeBackId = $tpl['id'];
                }
            }
            
            $templateList[] = $template;
        }
        
        return [
            'activeFrontId' => $activeFrontId,
            'activeBackId' => $activeBackId,
            'templates' => $templateList
        ];
    }
    
    public static function saveTemplates($config, $companyId = null) {
        if (!self::useDatabase()) {
            return saveTemplates($config, $companyId);
        }
        
        $companyId = $companyId ?: getCurrentCompanyId();
        if (!$companyId) {
            return false;
        }
        
        try {
            self::$db->beginTransaction();
            
            // Update active status
            self::$db->update('templates', ['is_active' => false], 'company_id = :id', ['id' => $companyId]);
            
            if (!empty($config['activeFrontId'])) {
                self::$db->update('templates', 
                    ['is_active' => true], 
                    'id = :id AND company_id = :cid AND side = "front"',
                    ['id' => $config['activeFrontId'], 'cid' => $companyId]
                );
            }
            
            if (!empty($config['activeBackId'])) {
                self::$db->update('templates', 
                    ['is_active' => true], 
                    'id = :id AND company_id = :cid AND side = "back"',
                    ['id' => $config['activeBackId'], 'cid' => $companyId]
                );
            }
            
            // Update templates
            foreach ($config['templates'] ?? [] as $template) {
                $existing = self::$db->fetchOne(
                    "SELECT id FROM templates WHERE id = :id",
                    ['id' => $template['id']]
                );
                
                $data = [
                    'name' => $template['name'],
                    'side' => $template['side'],
                    'background_image_path' => $template['backgroundImage'] ?? '',
                    'fields_json' => json_encode($template['fields'] ?? []),
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                
                if ($existing) {
                    self::$db->update('templates', $data, 'id = :id', ['id' => $template['id']]);
                } else {
                    $data['id'] = $template['id'];
                    $data['company_id'] = $companyId;
                    $data['created_at'] = $template['created_at'] ?? date('Y-m-d H:i:s');
                    self::$db->insert('templates', $data);
                }
            }
            
            self::$db->commit();
            return true;
        } catch (Exception $e) {
            self::$db->rollback();
            error_log("Template save error: " . $e->getMessage());
            return false;
        }
    }
    
    public static function logGeneratedCard($employeeId, $frontTemplateId, $backTemplateId, $frontFile, $backFile, $pdfFile = null, $companyId = null) {
        if (!self::useDatabase()) {
            return logGeneratedCard($employeeId, $frontTemplateId, $backTemplateId, $frontFile, $backFile, $pdfFile, $companyId);
        }
        
        $companyId = $companyId ?: getCurrentCompanyId();
        if (!$companyId) {
            return null;
        }
        
        try {
            $entry = [
                'id' => generateUUID(),
                'company_id' => $companyId,
                'employee_id' => $employeeId,
                'front_template_id' => $frontTemplateId,
                'back_template_id' => $backTemplateId,
                'front_file_path' => $frontFile,
                'back_file_path' => $backFile,
                'pdf_file_path' => $pdfFile,
                'generated_at' => date('Y-m-d H:i:s')
            ];
            
            self::$db->insert('generated_cards', $entry);
            return $entry;
        } catch (Exception $e) {
            error_log("Log generation error: " . $e->getMessage());
            return null;
        }
    }
    
    public static function loadGeneratedLog($companyId = null) {
        if (!self::useDatabase()) {
            return loadGeneratedLog($companyId);
        }
        
        $companyId = $companyId ?: getCurrentCompanyId();
        if (!$companyId) {
            return [];
        }
        
        return self::$db->fetchAll(
            "SELECT * FROM generated_cards WHERE company_id = :id ORDER BY generated_at DESC LIMIT 500",
            ['id' => $companyId]
        );
    }
}

// Initialize adapter
DatabaseAdapter::init();
