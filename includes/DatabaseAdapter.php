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
            return [];
        }
        
        return self::$db->fetchAll("SELECT * FROM companies ORDER BY created_at DESC");
    }
    
    public static function findCompanyBySlug($slug) {
        if (!self::useDatabase()) {
            return null;
        }
        
        return self::$db->fetchOne("SELECT * FROM companies WHERE slug = :slug", ['slug' => $slug]);
    }
    
    public static function findCompanyById($id) {
        if (!self::useDatabase()) {
            return null;
        }
        
        return self::$db->fetchOne("SELECT * FROM companies WHERE id = :id", ['id' => $id]);
    }
    
    public static function createCompany($name, $adminEmail, $password, $parentCompanyId = null, $customSlug = null) {
        if (!self::useDatabase()) {
            return ['success' => false, 'error' => 'Database not available'];
        }
        
        $hasParentCompany = self::$db->columnExists('companies', 'parent_company_id');
        $hasCompanyPath = self::$db->columnExists('companies', 'company_path');
        $hasCompanyType = self::$db->columnExists('companies', 'company_type');

        // Use custom slug if provided, otherwise generate from name
        if (!empty($customSlug)) {
            // Validate custom slug
            $customSlug = strtolower(trim($customSlug));
            $customSlug = preg_replace('/[^a-z0-9-]/', '', $customSlug);
            $customSlug = trim($customSlug, '-');
            
            if (empty($customSlug)) {
                return ['success' => false, 'error' => 'Invalid company abbreviation'];
            }
            
            // Check if custom slug is available
            if (self::findCompanyBySlug($customSlug)) {
                return ['success' => false, 'error' => 'Company abbreviation already taken. Please choose another.'];
            }
            
            $slug = $customSlug;
        } else {
            // Auto-generate slug from name
            $slug = slugify($name);
            $baseSlug = $slug;
            $i = 1;
            while (self::findCompanyBySlug($slug)) {
                $slug = $baseSlug . '-' . $i;
                $i++;
            }
        }
        
        // Build company path if parent exists
        $companyPath = $name;
        $companyType = $parentCompanyId ? 'child' : 'standalone';
        
        if ($parentCompanyId) {
            $parent = self::$db->fetchOne("SELECT * FROM companies WHERE id = :id", ['id' => $parentCompanyId]);
            if ($parent) {
                $parentPath = $parent['company_path'] ?? $parent['name'];
                $companyPath = $parentPath . ' > ' . $name;
            }
        }
        
        $company = [
            'id' => generateUUID(),
            'name' => $name,
            'slug' => $slug,
            'admin_email' => $adminEmail,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'plan' => 'enterprise', // free-forever: every new company gets top tier, all features unlocked
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s')
        ];

        if ($hasParentCompany) {
            $company['parent_company_id'] = $parentCompanyId;
        }
        if ($hasCompanyPath) {
            $company['company_path'] = $companyPath;
        }
        if ($hasCompanyType) {
            $company['company_type'] = $companyType;
        }
        
        try {
            self::$db->insert('companies', $company);
            
            // Initialize company directories
            getCompanyUploadsDir($company['id']);
            getCompanyTemplatesDir($company['id']);
            getCompanyCardsDir($company['id']);
            
            return ['success' => true, 'company' => $company];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Failed to create company: ' . $e->getMessage()];
        }
    }
    
    /**
     * Update company details
     */
    public static function updateCompany($companyId, $data) {
        if (!self::useDatabase()) {
            return ['success' => false, 'error' => 'Database not available'];
        }
        
        $hasParentCompany = self::$db->columnExists('companies', 'parent_company_id');
        $hasCompanyPath = self::$db->columnExists('companies', 'company_path');
        $hasCompanyType = self::$db->columnExists('companies', 'company_type');

        $company = self::findCompanyById($companyId);
        if (!$company) {
            return ['success' => false, 'error' => 'Company not found'];
        }
        
        $updateData = [];
        
        // Update name
        if (isset($data['name']) && !empty($data['name'])) {
            $updateData['name'] = trim($data['name']);
        }
        
        // Update slug (with validation)
        if (isset($data['slug']) && !empty($data['slug'])) {
            $newSlug = strtolower(trim($data['slug']));
            $newSlug = preg_replace('/[^a-z0-9-]/', '', $newSlug);
            $newSlug = trim($newSlug, '-');
            
            if (empty($newSlug)) {
                return ['success' => false, 'error' => 'Invalid company abbreviation'];
            }
            
            // Check if slug is taken by another company
            $existing = self::findCompanyBySlug($newSlug);
            if ($existing && $existing['id'] !== $companyId) {
                return ['success' => false, 'error' => 'Company abbreviation already taken'];
            }
            
            $updateData['slug'] = $newSlug;
        }
        
        // Update email
        if (isset($data['admin_email']) && !empty($data['admin_email'])) {
            $updateData['admin_email'] = sanitizeEmail($data['admin_email']);
        }
        
        // Update password
        if (isset($data['password']) && !empty($data['password'])) {
            $updateData['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }
        
        // Update plan
        if (isset($data['plan'])) {
            $updateData['plan'] = $data['plan'];
        }
        
        // Update status
        if (isset($data['status'])) {
            $updateData['status'] = $data['status'];
        }
        
        // Update currency
        if (isset($data['currency'])) {
            $updateData['currency'] = $data['currency'];
        }
        
        // Update billing email
        if (isset($data['billing_email'])) {
            $updateData['billing_email'] = sanitizeEmail($data['billing_email']);
        }
        
        // Update parent company
        if (isset($data['parent_company_id'])) {
            $parentId = $data['parent_company_id'] ?: null;
            
            // Prevent circular reference
            if ($parentId === $companyId) {
                return ['success' => false, 'error' => 'Company cannot be its own parent'];
            }
            
            // Build company path
            $companyPath = $updateData['name'] ?? $company['name'];
            $companyType = $parentId ? 'child' : 'standalone';
            
            if ($parentId) {
                $parent = self::$db->fetchOne("SELECT * FROM companies WHERE id = :id", ['id' => $parentId]);
                if ($parent) {
                    $parentPath = $parent['company_path'] ?? $parent['name'];
                    $companyPath = $parentPath . ' > ' . ($updateData['name'] ?? $company['name']);
                }
            }
            
            if ($hasParentCompany) {
                $updateData['parent_company_id'] = $parentId;
            }
            if ($hasCompanyPath) {
                $updateData['company_path'] = $companyPath;
            }
            if ($hasCompanyType) {
                $updateData['company_type'] = $companyType;
            }
        }
        
        if (empty($updateData)) {
            return ['success' => false, 'error' => 'No data to update'];
        }
        
        $updateData['updated_at'] = date('Y-m-d H:i:s');
        
        try {
            self::$db->update('companies', $updateData, 'id = :id', ['id' => $companyId]);
            return ['success' => true, 'company' => self::findCompanyById($companyId)];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Failed to update company: ' . $e->getMessage()];
        }
    }
    
    // Employee functions
    public static function loadEmployees($companyId = null) {
        if (!self::useDatabase()) {
            return [];
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
            return null;
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
            return null;
        }
        
        if ($companyId) {
            return self::$db->fetchOne(
                "SELECT * FROM employees WHERE id = :id AND company_id = :cid",
                ['id' => $id, 'cid' => $companyId]
            );
        }
        
        return self::$db->fetchOne("SELECT * FROM employees WHERE id = :id", ['id' => $id]);
    }

    public static function findDepartmentById($id, $companyId = null) {
        if (!self::useDatabase()) {
            return null;
        }

        if ($companyId) {
            return self::$db->fetchOne(
                "SELECT * FROM departments WHERE id = :id AND company_id = :cid",
                ['id' => $id, 'cid' => $companyId]
            );
        }

        return self::$db->fetchOne("SELECT * FROM departments WHERE id = :id", ['id' => $id]);
    }

    /**
     * Validate & normalize a Dynamic QR redirect URL.
     * Returns null for empty/invalid input. Only http(s) URLs accepted.
     * Max 1024 chars (column width).
     */
    private static function sanitizeQrRedirectUrl($raw) {
        if ($raw === null) return null;
        $url = trim((string)$raw);
        if ($url === '') return null;
        if (strlen($url) > 1024) return null;
        if (!filter_var($url, FILTER_VALIDATE_URL)) return null;
        if (!preg_match('~^https?://~i', $url)) return null;
        return $url;
    }

    public static function addEmployee($data, $companyId = null) {
        if (!self::useDatabase()) {
            return ['success' => false, 'error' => 'Database not available'];
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
            'department_id' => !empty($data['department_id']) ? $data['department_id'] : null,
            'email' => trim(strtolower($data['email'] ?? '')),
            'name_en' => trim($data['name_en'] ?? ''),
            'name_ar' => trim($data['name_ar'] ?? ''),
            'position_en' => trim($data['position_en'] ?? ''),
            'position_ar' => trim($data['position_ar'] ?? ''),
            'phone' => trim($data['phone'] ?? ''),
            'phone_ar' => trim($data['phone_ar'] ?? ''),
            'mobile' => trim($data['mobile'] ?? ''),
            'mobile_ar' => trim($data['mobile_ar'] ?? ''),
            'company_en' => trim($data['company_en'] ?? ''),
            'company_ar' => trim($data['company_ar'] ?? ''),
            'website' => trim($data['website'] ?? ''),
            'website_ar' => trim($data['website_ar'] ?? ''),
            'address_en' => trim($data['address_en'] ?? $data['address'] ?? ''),
            'address_ar' => trim($data['address_ar'] ?? ''),
            'qr_redirect_url' => self::sanitizeQrRedirectUrl($data['qr_redirect_url'] ?? null),
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        try {
            self::$db->insert('employees', $employee);
            return ['success' => true, 'id' => $employee['id'], 'employee' => $employee];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Failed to save employee: ' . $e->getMessage()];
        }
    }
    
    public static function updateEmployee($id, $data, $companyId = null) {
        if (!self::useDatabase()) {
            return ['success' => false, 'error' => 'Database not available'];
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
            'department_id' => !empty($data['department_id']) ? $data['department_id'] : null,
            'name_en' => trim($data['name_en'] ?? ''),
            'name_ar' => trim($data['name_ar'] ?? ''),
            'position_en' => trim($data['position_en'] ?? ''),
            'position_ar' => trim($data['position_ar'] ?? ''),
            'phone' => trim($data['phone'] ?? ''),
            'phone_ar' => trim($data['phone_ar'] ?? ''),
            'mobile' => trim($data['mobile'] ?? ''),
            'mobile_ar' => trim($data['mobile_ar'] ?? ''),
            'company_en' => trim($data['company_en'] ?? ''),
            'company_ar' => trim($data['company_ar'] ?? ''),
            'website' => trim($data['website'] ?? ''),
            'website_ar' => trim($data['website_ar'] ?? ''),
            'address_en' => trim($data['address_en'] ?? $data['address'] ?? ''),
            'address_ar' => trim($data['address_ar'] ?? ''),
            'qr_redirect_url' => self::sanitizeQrRedirectUrl($data['qr_redirect_url'] ?? null),
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
            return ['success' => false, 'error' => 'Database not available'];
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
            return getDefaultTemplatesConfig();
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
                'pair_id' => $tpl['pair_id'] ?? null,
                'name' => $tpl['name'],
                'side' => $tpl['side'],
                'backgroundImage' => $tpl['background_image_path'],
                'originalPdf' => $tpl['original_pdf_path'] ?? null,
                'fields' => json_decode($tpl['fields_json'], true) ?: getDefaultFieldSettings(),
                'settings' => isset($tpl['settings_json']) ? json_decode($tpl['settings_json'], true) : null,
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
            return false;
        }
        
        $companyId = $companyId ?: getCurrentCompanyId();
        if (!$companyId) {
            return false;
        }
        
        try {
            self::$db->beginTransaction();
            
            // Update active status (use integers 0/1 for MySQL compatibility)
            self::$db->update('templates', ['is_active' => 0], 'company_id = :id', ['id' => $companyId]);
            
            if (!empty($config['activeFrontId'])) {
                self::$db->update('templates', 
                    ['is_active' => 1], 
                    'id = :id AND company_id = :cid AND side = "front"',
                    ['id' => $config['activeFrontId'], 'cid' => $companyId]
                );
            }
            
            if (!empty($config['activeBackId'])) {
                self::$db->update('templates', 
                    ['is_active' => 1], 
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
                
                $fieldsToEncode = $template['fields'] ?? [];
                if (empty($fieldsToEncode) || !is_array($fieldsToEncode)) {
                    $fieldsToEncode = [];
                }
                
                $settingsToEncode = $template['settings'] ?? null;
                
                $data = [
                    'name' => $template['name'] ?? 'Untitled',
                    'side' => $template['side'] ?? 'front',
                    'background_image_path' => $template['backgroundImage'] ?? '',
                    'fields_json' => json_encode($fieldsToEncode),
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                
                // Add pair_id if available
                if (!empty($template['pair_id'])) {
                    $data['pair_id'] = $template['pair_id'];
                }
                
                // Add original PDF path if available (for high-quality exports)
                if (!empty($template['originalPdf'])) {
                    $data['original_pdf_path'] = $template['originalPdf'];
                }
                
                // Add settings_json if available (check if column exists)
                if ($settingsToEncode !== null) {
                    $data['settings_json'] = json_encode($settingsToEncode);
                }
                
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
            $errorMsg = "Template save error: " . $e->getMessage() . " | File: " . $e->getFile() . ":" . $e->getLine();
            error_log($errorMsg);
            // Store the last error for debugging
            self::$lastError = $errorMsg;
            return false;
        }
    }
    
    /**
     * Get the last error message
     */
    public static function getLastError() {
        return self::$lastError ?? null;
    }
    
    private static $lastError = null;
    
    /**
     * Delete a template from the database
     */
    public static function deleteTemplate($templateId, $companyId = null) {
        if (!self::useDatabase()) {
            return false;
        }
        
        $companyId = $companyId ?: getCurrentCompanyId();
        if (!$companyId) {
            return false;
        }
        
        try {
            // Verify template belongs to company before deleting
            $template = self::$db->fetchOne(
                "SELECT * FROM templates WHERE id = :id AND company_id = :cid",
                ['id' => $templateId, 'cid' => $companyId]
            );
            
            if (!$template) {
                return false;
            }
            
            // Delete from database
            self::$db->delete('templates', 'id = :id AND company_id = :cid', [
                'id' => $templateId,
                'cid' => $companyId
            ]);
            
            return $template; // Return deleted template data
        } catch (Exception $e) {
            $errorMsg = "Template delete error: " . $e->getMessage() . " | File: " . $e->getFile() . ":" . $e->getLine();
            error_log($errorMsg);
            self::$lastError = $errorMsg;
            return false;
        }
    }
    
    /**
     * Delete a template pair (both front and back)
     */
    public static function deleteTemplatePair($pairId, $companyId = null) {
        if (!self::useDatabase()) {
            return false;
        }
        
        $companyId = $companyId ?: getCurrentCompanyId();
        if (!$companyId) {
            return false;
        }
        
        try {
            // Get all templates in the pair
            $templates = self::$db->fetchAll(
                "SELECT * FROM templates WHERE pair_id = :pid AND company_id = :cid",
                ['pid' => $pairId, 'cid' => $companyId]
            );
            
            if (empty($templates)) {
                return false;
            }
            
            // Delete all templates in the pair
            self::$db->delete('templates', 'pair_id = :pid AND company_id = :cid', [
                'pid' => $pairId,
                'cid' => $companyId
            ]);
            
            return $templates; // Return deleted templates data
        } catch (Exception $e) {
            $errorMsg = "Template pair delete error: " . $e->getMessage() . " | File: " . $e->getFile() . ":" . $e->getLine();
            error_log($errorMsg);
            self::$lastError = $errorMsg;
            return false;
        }
    }
    
    public static function logGeneratedCard($employeeId, $frontTemplateId, $backTemplateId, $frontFile, $backFile, $pdfFile = null, $companyId = null) {
        if (!self::useDatabase()) {
            return null;
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
            return [];
        }
        
        $companyId = $companyId ?: getCurrentCompanyId();
        if (!$companyId) {
            return [];
        }
        
        $rows = self::$db->fetchAll(
            "SELECT * FROM generated_cards WHERE company_id = :id ORDER BY generated_at DESC LIMIT 500",
            ['id' => $companyId]
        );
        
        // Transform to match expected format (camelCase keys)
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => $row['id'],
                'employeeId' => $row['employee_id'],
                'templateId' => $row['front_template_id'],
                'frontPath' => $row['front_file_path'],
                'backPath' => $row['back_file_path'],
                'pdfPath' => $row['pdf_file_path'] ?? null,
                'generatedAt' => $row['generated_at'],
                'companyId' => $row['company_id']
            ];
        }
        
        return $result;
    }
    
    public static function deleteGeneratedCard($entryId, $companyId = null) {
        if (!self::useDatabase()) {
            return false;
        }
        
        $companyId = $companyId ?: getCurrentCompanyId();
        if (!$companyId) {
            return false;
        }
        
        try {
            self::$db->delete('generated_cards', 'id = :id AND company_id = :company_id', [
                'id' => $entryId,
                'company_id' => $companyId
            ]);
            return true;
        } catch (Exception $e) {
            error_log("Delete generated card error: " . $e->getMessage());
            return false;
        }
    }
}

// Initialize adapter
DatabaseAdapter::init();
