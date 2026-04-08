<?php
/**
 * Save Template - Handles template CRUD operations
 */
require_once __DIR__ . '/../config.php';
requireAdmin();

header('Content-Type: application/json');

try {
    // CSRF protection
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        throw new Exception('Invalid request token. Please refresh and try again.');
    }

    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'add':
            $result = addNewTemplate();
            break;
        case 'add_pair':
            $result = addTemplatePair();
            break;
        case 'update':
            $result = updateTemplate();
            break;
        case 'update_background':
            $result = updateTemplateBackground();
            break;
        case 'delete':
            $result = deleteTemplateAction();
            break;
        case 'delete_pair':
            $result = deleteTemplatePair();
            break;
        case 'activate':
            $result = activateTemplate();
            break;
        default:
            throw new Exception('Invalid action');
    }
    
    echo json_encode($result);
    
} catch (Exception $e) {
    error_log("save_template: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'An error occurred. Please try again.']);
}

/**
 * Add a new template pair (front + back)
 */
function addTemplatePair() {
    $name = trim($_POST['name'] ?? '');
    $fieldsJson = $_POST['fields'] ?? '{}';
    $settingsJson = $_POST['settings'] ?? '';

    if (empty($name)) {
        throw new Exception('Template name is required');
    }

    $companyId = getCurrentCompanyId();
    if (!$companyId) {
        throw new Exception('No company context - please log in again');
    }

    // Enforce plan template limit
    require_once INCLUDES_DIR . '/Billing.php';
    $billing = new Billing();
    if (!$billing->checkLimit($companyId, 'templates')) {
        $limits = $billing->getPlanLimits($companyId);
        throw new Exception('Template limit reached (' . ($limits['templates'] ?? 2) . ' max on your plan). Upgrade to add more templates.');
    }

    $destination = getCompanyTemplatesDir($companyId);
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
    
    $fields = json_decode($fieldsJson, true);
    if (!$fields) {
        // Use front field settings for new template pairs
        $fields = getFrontFieldSettings();
    }
    
    $settings = null;
    if (!empty($settingsJson)) {
        $settings = json_decode($settingsJson, true);
    }
    
    // Generate a pair_id to link front and back
    $pairId = generateUUID();
    $createdAt = date('Y-m-d H:i:s');
    
    $templates = [];
    
    // Handle front image upload
    $frontImage = '';
    $frontOriginalPdf = null;
    if (isset($_FILES['front_image']) && $_FILES['front_image']['error'] === UPLOAD_ERR_OK) {
        $uploadResult = handleFileUpload($_FILES['front_image'], $destination, $allowedTypes);
        if ($uploadResult['success']) {
            $frontImage = getWebPath($uploadResult['path']);
            if (!empty($uploadResult['originalPdf'])) {
                $frontOriginalPdf = getWebPath($uploadResult['originalPdf']);
            }
        }
    }
    
    // Handle back image upload
    $backImage = '';
    $backOriginalPdf = null;
    if (isset($_FILES['back_image']) && $_FILES['back_image']['error'] === UPLOAD_ERR_OK) {
        $uploadResult = handleFileUpload($_FILES['back_image'], $destination, $allowedTypes);
        if ($uploadResult['success']) {
            $backImage = getWebPath($uploadResult['path']);
            if (!empty($uploadResult['originalPdf'])) {
                $backOriginalPdf = getWebPath($uploadResult['originalPdf']);
            }
        }
    }
    
    // Create front template
    $frontTemplate = [
        'id' => generateTemplateId($name . '-front'),
        'pair_id' => $pairId,
        'name' => $name,
        'side' => 'front',
        'backgroundImage' => $frontImage,
        'originalPdf' => $frontOriginalPdf,
        'fields' => $fields,
        'settings' => $settings,
        'created_at' => $createdAt
    ];
    $templates[] = $frontTemplate;
    
    // Create back template with minimal fields for back of card
    $backFields = getBackFieldSettings();
    $backTemplate = [
        'id' => generateTemplateId($name . '-back'),
        'pair_id' => $pairId,
        'name' => $name,
        'side' => 'back',
        'backgroundImage' => $backImage,
        'originalPdf' => $backOriginalPdf,
        'fields' => $backFields,
        'settings' => $settings,
        'created_at' => $createdAt
    ];
    $templates[] = $backTemplate;
    
    // Save to database
    $config = loadTemplates($companyId);
    $config['templates'] = array_merge($config['templates'], $templates);
    
    if (!saveTemplates($config, $companyId)) {
        $dbError = DatabaseAdapter::getLastError();
        throw new Exception('Failed to save template pair' . ($dbError ? ': ' . $dbError : ''));
    }
    
    return [
        'success' => true, 
        'pair_id' => $pairId,
        'templates' => $templates,
        'front' => $frontTemplate,
        'back' => $backTemplate
    ];
}

/**
 * Get default field settings for front of card
 * Front cards typically show name, position, company info
 */
function getFrontFieldSettings() {
    return [
        // English fields (left-aligned)
        'name_en' => ['enabled' => true, 'x' => 50, 'y' => 60, 'fontSize' => 28, 'fontFamily' => 'Inter', 'fontWeight' => 'bold', 'fill' => '#1f2937', 'color' => '#1f2937', 'textAlign' => 'left', 'originX' => 'left', 'originY' => 'top'],
        'position_en' => ['enabled' => true, 'x' => 50, 'y' => 100, 'fontSize' => 16, 'fontFamily' => 'Inter', 'fontWeight' => 'normal', 'fill' => '#6b7280', 'color' => '#6b7280', 'textAlign' => 'left', 'originX' => 'left', 'originY' => 'top'],
        'company_en' => ['enabled' => true, 'x' => 50, 'y' => 130, 'fontSize' => 14, 'fontFamily' => 'Inter', 'fontWeight' => '500', 'fill' => '#374151', 'color' => '#374151', 'textAlign' => 'left', 'originX' => 'left', 'originY' => 'top'],
        
        // Arabic fields (right-aligned)
        'name_ar' => ['enabled' => false, 'x' => 1000, 'y' => 60, 'fontSize' => 28, 'fontFamily' => 'Cairo', 'fontWeight' => 'bold', 'fill' => '#1f2937', 'color' => '#1f2937', 'textAlign' => 'right', 'originX' => 'right', 'originY' => 'top'],
        'position_ar' => ['enabled' => false, 'x' => 1000, 'y' => 100, 'fontSize' => 16, 'fontFamily' => 'Cairo', 'fontWeight' => 'normal', 'fill' => '#6b7280', 'color' => '#6b7280', 'textAlign' => 'right', 'originX' => 'right', 'originY' => 'top'],
        'company_ar' => ['enabled' => false, 'x' => 1000, 'y' => 130, 'fontSize' => 14, 'fontFamily' => 'Cairo', 'fontWeight' => '500', 'fill' => '#374151', 'color' => '#374151', 'textAlign' => 'right', 'originX' => 'right', 'originY' => 'top'],
        
        // Contact fields - English
        'phone' => ['enabled' => true, 'x' => 50, 'y' => 480, 'fontSize' => 13, 'fontFamily' => 'Inter', 'fontWeight' => 'normal', 'fill' => '#4b5563', 'color' => '#4b5563', 'textAlign' => 'left', 'originX' => 'left', 'originY' => 'top'],
        'mobile' => ['enabled' => true, 'x' => 50, 'y' => 510, 'fontSize' => 13, 'fontFamily' => 'Inter', 'fontWeight' => 'normal', 'fill' => '#4b5563', 'color' => '#4b5563', 'textAlign' => 'left', 'originX' => 'left', 'originY' => 'top'],
        'email' => ['enabled' => true, 'x' => 50, 'y' => 540, 'fontSize' => 13, 'fontFamily' => 'Inter', 'fontWeight' => 'normal', 'fill' => '#4b5563', 'color' => '#4b5563', 'textAlign' => 'left', 'originX' => 'left', 'originY' => 'top'],
        'website' => ['enabled' => false, 'x' => 50, 'y' => 570, 'fontSize' => 13, 'fontFamily' => 'Inter', 'fontWeight' => 'normal', 'fill' => '#4b5563', 'color' => '#4b5563', 'textAlign' => 'left', 'originX' => 'left', 'originY' => 'top'],
        'address_en' => ['enabled' => false, 'x' => 50, 'y' => 160, 'fontSize' => 12, 'fontFamily' => 'Inter', 'fontWeight' => 'normal', 'fill' => '#6b7280', 'color' => '#6b7280', 'textAlign' => 'left', 'originX' => 'left', 'originY' => 'top'],
        
        // Contact fields - Arabic
        'phone_ar' => ['enabled' => false, 'x' => 1000, 'y' => 480, 'fontSize' => 13, 'fontFamily' => 'Cairo', 'fontWeight' => 'normal', 'fill' => '#4b5563', 'color' => '#4b5563', 'textAlign' => 'right', 'originX' => 'right', 'originY' => 'top'],
        'mobile_ar' => ['enabled' => false, 'x' => 1000, 'y' => 510, 'fontSize' => 13, 'fontFamily' => 'Cairo', 'fontWeight' => 'normal', 'fill' => '#4b5563', 'color' => '#4b5563', 'textAlign' => 'right', 'originX' => 'right', 'originY' => 'top'],
        'website_ar' => ['enabled' => false, 'x' => 1000, 'y' => 540, 'fontSize' => 13, 'fontFamily' => 'Cairo', 'fontWeight' => 'normal', 'fill' => '#4b5563', 'color' => '#4b5563', 'textAlign' => 'right', 'originX' => 'right', 'originY' => 'top'],
        'address_ar' => ['enabled' => false, 'x' => 1000, 'y' => 160, 'fontSize' => 12, 'fontFamily' => 'Cairo', 'fontWeight' => 'normal', 'fill' => '#6b7280', 'color' => '#6b7280', 'textAlign' => 'right', 'originX' => 'right', 'originY' => 'top'],
        
        // QR Code
        'qr_code' => ['enabled' => false, 'x' => 850, 'y' => 400, 'size' => 150]
    ];
}

/**
 * Get default field settings for back of card
 * Back cards typically show contact info and QR code
 */
function getBackFieldSettings() {
    return [
        // English fields (left-aligned)
        'name_en' => ['enabled' => false, 'x' => 50, 'y' => 60, 'fontSize' => 28, 'fontFamily' => 'Inter', 'fontWeight' => 'bold', 'fill' => '#1f2937', 'color' => '#1f2937', 'textAlign' => 'left', 'originX' => 'left', 'originY' => 'top'],
        'position_en' => ['enabled' => false, 'x' => 50, 'y' => 100, 'fontSize' => 16, 'fontFamily' => 'Inter', 'fontWeight' => 'normal', 'fill' => '#6b7280', 'color' => '#6b7280', 'textAlign' => 'left', 'originX' => 'left', 'originY' => 'top'],
        'company_en' => ['enabled' => false, 'x' => 50, 'y' => 130, 'fontSize' => 14, 'fontFamily' => 'Inter', 'fontWeight' => '500', 'fill' => '#374151', 'color' => '#374151', 'textAlign' => 'left', 'originX' => 'left', 'originY' => 'top'],
        
        // Arabic fields (right-aligned)
        'name_ar' => ['enabled' => false, 'x' => 1000, 'y' => 60, 'fontSize' => 28, 'fontFamily' => 'Cairo', 'fontWeight' => 'bold', 'fill' => '#1f2937', 'color' => '#1f2937', 'textAlign' => 'right', 'originX' => 'right', 'originY' => 'top'],
        'position_ar' => ['enabled' => false, 'x' => 1000, 'y' => 100, 'fontSize' => 16, 'fontFamily' => 'Cairo', 'fontWeight' => 'normal', 'fill' => '#6b7280', 'color' => '#6b7280', 'textAlign' => 'right', 'originX' => 'right', 'originY' => 'top'],
        'company_ar' => ['enabled' => false, 'x' => 1000, 'y' => 130, 'fontSize' => 14, 'fontFamily' => 'Cairo', 'fontWeight' => '500', 'fill' => '#374151', 'color' => '#374151', 'textAlign' => 'right', 'originX' => 'right', 'originY' => 'top'],
        
        // Contact fields - English
        'phone' => ['enabled' => false, 'x' => 50, 'y' => 480, 'fontSize' => 13, 'fontFamily' => 'Inter', 'fontWeight' => 'normal', 'fill' => '#4b5563', 'color' => '#4b5563', 'textAlign' => 'left', 'originX' => 'left', 'originY' => 'top'],
        'mobile' => ['enabled' => false, 'x' => 50, 'y' => 510, 'fontSize' => 13, 'fontFamily' => 'Inter', 'fontWeight' => 'normal', 'fill' => '#4b5563', 'color' => '#4b5563', 'textAlign' => 'left', 'originX' => 'left', 'originY' => 'top'],
        'email' => ['enabled' => false, 'x' => 50, 'y' => 540, 'fontSize' => 13, 'fontFamily' => 'Inter', 'fontWeight' => 'normal', 'fill' => '#4b5563', 'color' => '#4b5563', 'textAlign' => 'left', 'originX' => 'left', 'originY' => 'top'],
        'website' => ['enabled' => true, 'x' => 700, 'y' => 480, 'fontSize' => 13, 'fontFamily' => 'Inter', 'fontWeight' => 'normal', 'fill' => '#4b5563', 'color' => '#4b5563', 'textAlign' => 'left', 'originX' => 'left', 'originY' => 'top'],
        'address_en' => ['enabled' => true, 'x' => 700, 'y' => 510, 'fontSize' => 13, 'fontFamily' => 'Inter', 'fontWeight' => 'normal', 'fill' => '#4b5563', 'color' => '#4b5563', 'textAlign' => 'left', 'originX' => 'left', 'originY' => 'top'],
        
        // Contact fields - Arabic
        'phone_ar' => ['enabled' => false, 'x' => 350, 'y' => 480, 'fontSize' => 13, 'fontFamily' => 'Cairo', 'fontWeight' => 'normal', 'fill' => '#4b5563', 'color' => '#4b5563', 'textAlign' => 'right', 'originX' => 'right', 'originY' => 'top'],
        'mobile_ar' => ['enabled' => false, 'x' => 350, 'y' => 510, 'fontSize' => 13, 'fontFamily' => 'Cairo', 'fontWeight' => 'normal', 'fill' => '#4b5563', 'color' => '#4b5563', 'textAlign' => 'right', 'originX' => 'right', 'originY' => 'top'],
        'website_ar' => ['enabled' => false, 'x' => 350, 'y' => 540, 'fontSize' => 13, 'fontFamily' => 'Cairo', 'fontWeight' => 'normal', 'fill' => '#4b5563', 'color' => '#4b5563', 'textAlign' => 'right', 'originX' => 'right', 'originY' => 'top'],
        'address_ar' => ['enabled' => false, 'x' => 350, 'y' => 570, 'fontSize' => 13, 'fontFamily' => 'Cairo', 'fontWeight' => 'normal', 'fill' => '#4b5563', 'color' => '#4b5563', 'textAlign' => 'right', 'originX' => 'right', 'originY' => 'top'],
        
        // QR Code
        'qr_code' => ['enabled' => true, 'x' => 850, 'y' => 250, 'size' => 150]
    ];
}

/**
 * Update template background image
 */
function updateTemplateBackground() {
    $id = $_POST['id'] ?? '';
    
    if (empty($id)) {
        throw new Exception('Template ID is required');
    }
    
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Background image is required');
    }
    
    $companyId = getCurrentCompanyId();
    if (!$companyId) {
        throw new Exception('No company context - please log in again');
    }
    
    $destination = getCompanyTemplatesDir($companyId);
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
    $uploadResult = handleFileUpload($_FILES['image'], $destination, $allowedTypes);
    
    if (!$uploadResult['success']) {
        throw new Exception($uploadResult['error']);
    }
    
    $config = loadTemplates($companyId);
    $found = false;
    $oldImagePath = null;
    $oldPdfPath = null;
    
    foreach ($config['templates'] as &$template) {
        if ($template['id'] === $id) {
            $oldImagePath = $template['backgroundImage'];
            $oldPdfPath = $template['originalPdf'] ?? null;
            $template['backgroundImage'] = getWebPath($uploadResult['path']);
            
            // Save original PDF path if uploaded file was a PDF
            if (!empty($uploadResult['originalPdf'])) {
                $template['originalPdf'] = getWebPath($uploadResult['originalPdf']);
            } else {
                $template['originalPdf'] = null;
            }
            
            $template['updated_at'] = date('Y-m-d H:i:s');
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        throw new Exception('Template not found');
    }
    
    if (!saveTemplates($config, $companyId)) {
        $dbError = DatabaseAdapter::getLastError();
        throw new Exception('Failed to save template' . ($dbError ? ': ' . $dbError : ''));
    }
    
    // Delete old image and PDF
    if ($oldImagePath) {
        $fullPath = getFilePath($oldImagePath);
        if (file_exists($fullPath)) {
            @unlink($fullPath);
        }
    }
    if ($oldPdfPath) {
        $fullPath = getFilePath($oldPdfPath);
        if (file_exists($fullPath)) {
            @unlink($fullPath);
        }
    }
    
    $result = [
        'success' => true, 
        'backgroundImage' => getWebPath($uploadResult['path'])
    ];
    
    if (!empty($uploadResult['originalPdf'])) {
        $result['originalPdf'] = getWebPath($uploadResult['originalPdf']);
    }
    
    return $result;
}

/**
 * Delete a template pair (both front and back)
 */
function deleteTemplatePair() {
    $pairId = $_POST['pair_id'] ?? '';
    
    if (empty($pairId)) {
        throw new Exception('Pair ID is required');
    }
    
    $companyId = getCurrentCompanyId();
    if (!$companyId) {
        throw new Exception('No company context - please log in again');
    }
    
    // Delete both templates in the pair
    $deletedTemplates = DatabaseAdapter::deleteTemplatePair($pairId, $companyId);
    
    if (!$deletedTemplates) {
        $dbError = DatabaseAdapter::getLastError();
        throw new Exception('Template pair not found or could not be deleted' . ($dbError ? ': ' . $dbError : ''));
    }
    
    // Delete background images
    foreach ($deletedTemplates as $tpl) {
        $imagePath = $tpl['background_image_path'] ?? '';
        if (!empty($imagePath)) {
            $fullPath = getFilePath($imagePath);
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
        }
    }
    
    return ['success' => true];
}

/**
 * Add a single template (legacy support)
 */
function addNewTemplate() {
    $name = trim($_POST['name'] ?? '');
    $side = $_POST['side'] ?? 'front';
    $fieldsJson = $_POST['fields'] ?? '{}';
    $settingsJson = $_POST['settings'] ?? '';
    
    if (empty($name)) {
        throw new Exception('Template name is required');
    }
    
    if (!in_array($side, ['front', 'back'])) {
        $side = 'front';
    }
    
    // Handle image upload
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Background image is required');
    }
    
    $companyId = getCurrentCompanyId();
    if (!$companyId) {
        throw new Exception('No company context - please log in again');
    }
    
    $destination = getCompanyTemplatesDir($companyId);
    // Allow images and PDFs for templates
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
    $uploadResult = handleFileUpload($_FILES['image'], $destination, $allowedTypes);
    if (!$uploadResult['success']) {
        throw new Exception($uploadResult['error']);
    }
    
    $fields = json_decode($fieldsJson, true);
    if (!$fields) {
        $fields = getDefaultFieldSettings();
    }
    
    // Generate pair_id even for single templates
    $pairId = generateUUID();
    
    $template = [
        'id' => generateTemplateId($name),
        'pair_id' => $pairId,
        'name' => $name,
        'side' => $side,
        'backgroundImage' => getWebPath($uploadResult['path']),
        'fields' => $fields,
        'settings' => null,
        'created_at' => date('Y-m-d H:i:s')
    ];

    if (!empty($settingsJson)) {
        $settings = json_decode($settingsJson, true);
        if (is_array($settings)) {
            $template['settings'] = $settings;
        }
    }
    
    // Save to config
    $config = loadTemplates($companyId);
    $config['templates'][] = $template;
    
    if (!saveTemplates($config, $companyId)) {
        $dbError = DatabaseAdapter::getLastError();
        throw new Exception('Failed to save template' . ($dbError ? ': ' . $dbError : ''));
    }
    
    return ['success' => true, 'template' => $template];
}

function updateTemplate() {
    $id = $_POST['id'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $fieldsJson = $_POST['fields'] ?? '';
    $settingsJson = $_POST['settings'] ?? '';
    
    if (empty($id)) {
        throw new Exception('Template ID is required');
    }
    
    $companyId = getCurrentCompanyId();
    if (!$companyId) {
        throw new Exception('No company context - please log in again');
    }
    
    $config = loadTemplates($companyId);
    $found = false;
    
    foreach ($config['templates'] as &$template) {
        if ($template['id'] === $id) {
            if (!empty($name)) {
                $template['name'] = $name;
            }
            
            if (!empty($fieldsJson)) {
                $fields = json_decode($fieldsJson, true);
                if ($fields) {
                    $template['fields'] = $fields;
                }
            }

            if (!empty($settingsJson)) {
                $settings = json_decode($settingsJson, true);
                if (is_array($settings)) {
                    $template['settings'] = $settings;
                }
            }
            
            // Handle new image upload if provided
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $destination = $companyId ? getCompanyTemplatesDir($companyId) : TEMPLATES_DIR;
                // Allow images and PDFs for templates
                $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
                $uploadResult = handleFileUpload($_FILES['image'], $destination, $allowedTypes);
                if ($uploadResult['success']) {
                    // Delete old image
                    $oldPath = getFilePath($template['backgroundImage']);
                    if (file_exists($oldPath)) {
                        @unlink($oldPath);
                    }
                    $template['backgroundImage'] = getWebPath($uploadResult['path']);
                }
            }
            
            $template['updated_at'] = date('Y-m-d H:i:s');
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        throw new Exception('Template not found');
    }
    
    if (!saveTemplates($config, $companyId)) {
        $dbError = DatabaseAdapter::getLastError();
        throw new Exception('Failed to save template' . ($dbError ? ': ' . $dbError : ''));
    }
    
    return ['success' => true];
}

function deleteTemplateAction() {
    $id = $_POST['id'] ?? '';
    
    if (empty($id)) {
        throw new Exception('Template ID is required');
    }
    
    $companyId = getCurrentCompanyId();
    if (!$companyId) {
        throw new Exception('No company context - please log in again');
    }
    
    // Delete from database and get template data
    $deletedTemplate = DatabaseAdapter::deleteTemplate($id, $companyId);
    
    if (!$deletedTemplate) {
        $dbError = DatabaseAdapter::getLastError();
        throw new Exception('Template not found or could not be deleted' . ($dbError ? ': ' . $dbError : ''));
    }
    
    // Delete background image file
    $imagePath = $deletedTemplate['background_image_path'] ?? $deletedTemplate['backgroundImage'] ?? '';
    if (!empty($imagePath)) {
        $fullPath = getFilePath($imagePath);
        if (file_exists($fullPath)) {
            @unlink($fullPath);
        }
    }
    
    return ['success' => true];
}

function activateTemplate() {
    $id = $_POST['id'] ?? '';
    $side = $_POST['side'] ?? 'front';
    
    if (empty($id)) {
        throw new Exception('Template ID is required');
    }
    
    $companyId = getCurrentCompanyId();
    $config = loadTemplates($companyId);
    
    // Verify template exists
    $found = false;
    foreach ($config['templates'] as $template) {
        if ($template['id'] === $id) {
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        throw new Exception('Template not found');
    }
    
    if ($side === 'front') {
        $config['activeFrontId'] = $id;
    } else {
        $config['activeBackId'] = $id;
    }
    
    if (!saveTemplates($config, $companyId)) {
        throw new Exception('Failed to activate template');
    }
    
    return ['success' => true];
}

