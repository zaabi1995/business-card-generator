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
        case 'set_as_default':
            $result = setAsCompanyDefault();
            break;
        case 'revert_version':
            $result = revertTemplateVersion();
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
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'image/svg+xml'];
    
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
    
    // Helper: generate a solid-color PNG background using GD
    $bgColor = trim($_POST['bg_color'] ?? '');
    $bgColor = preg_match('/^#[0-9a-fA-F]{6}$/', $bgColor) ? $bgColor : '';

    $generateColorBg = function($bgHex, $destDir, $label) use ($settings) {
        if (!$bgHex || !function_exists('imagecreatetruecolor')) return '';
        // Determine dimensions from settings (default: 1050×600 for standard landscape card at 300dpi)
        $w = 1050; $h = 600;
        if (!empty($settings['width']) && !empty($settings['height'])) {
            $w = max(100, (int)$settings['width']);
            $h = max(100, (int)$settings['height']);
        }
        $r = hexdec(substr($bgHex, 1, 2));
        $g = hexdec(substr($bgHex, 3, 2));
        $b = hexdec(substr($bgHex, 5, 2));
        $img = imagecreatetruecolor($w, $h);
        $fill = imagecolorallocate($img, $r, $g, $b);
        imagefill($img, 0, 0, $fill);
        $filename = $label . '-' . substr(md5($bgHex . $w . $h), 0, 8) . '.png';
        $path = rtrim($destDir, '/') . '/' . $filename;
        imagepng($img, $path, 9);
        imagedestroy($img);
        return getWebPath($path);
    };

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
    } elseif ($bgColor) {
        $frontImage = $generateColorBg($bgColor, $destination, 'bg-front');
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
    } elseif ($bgColor) {
        $backImage = $generateColorBg($bgColor, $destination, 'bg-back');
    }
    
    // Stamp coord format so loadTemplates skips the legacy % conversion.
    $settings = is_array($settings) ? $settings : [];
    $settings['fields_format'] = 'px';

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
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'image/svg+xml'];
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

    // Enforce plan template limit
    require_once INCLUDES_DIR . '/Billing.php';
    $billing = new Billing();
    if (!$billing->checkLimit($companyId, 'templates')) {
        $limits = $billing->getPlanLimits($companyId);
        throw new Exception('Template limit reached (' . ($limits['templates'] ?? 2) . ' max on your plan). Upgrade to add more templates.');
    }

    $destination = getCompanyTemplatesDir($companyId);
    // Allow images and PDFs for templates
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'image/svg+xml'];
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

    // Stamp coord format so loadTemplates skips the legacy % conversion.
    $template['settings'] = is_array($template['settings']) ? $template['settings'] : [];
    $template['settings']['fields_format'] = 'px';

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

            // Stamp coord format so loadTemplates skips the legacy % conversion.
            $template['settings'] = is_array($template['settings'] ?? null) ? $template['settings'] : [];
            $template['settings']['fields_format'] = 'px';

            // Handle new image upload if provided
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $destination = $companyId ? getCompanyTemplatesDir($companyId) : TEMPLATES_DIR;
                // Allow images and PDFs for templates
                $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'image/svg+xml'];
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

/**
 * Set the current template as the company-wide default for new employees.
 * Writes to companies.default_front_template_id or default_back_template_id
 * based on the supplied side. Template must belong to the current company.
 */
function setAsCompanyDefault() {
    $id   = trim($_POST['id']   ?? '');
    $side = $_POST['side'] ?? 'front';
    if (empty($id)) {
        throw new Exception('Template ID is required');
    }
    if (!in_array($side, ['front', 'back'], true)) {
        throw new Exception('Invalid side');
    }

    $companyId = getCurrentCompanyId();
    if (!$companyId) {
        throw new Exception('Company context required');
    }

    // Verify template belongs to this company before writing the id to
    // companies.default_*_template_id (cross-tenant guard).
    $config = loadTemplates($companyId);
    $found  = false;
    foreach ($config['templates'] ?? [] as $t) {
        if (($t['id'] ?? null) === $id) { $found = true; break; }
    }
    if (!$found) {
        throw new Exception('Template not found');
    }

    $col = $side === 'front' ? 'default_front_template_id' : 'default_back_template_id';
    try {
        $db = Database::getInstance();
        $db->update('companies', [$col => $id], 'id = :id', ['id' => $companyId]);
    } catch (Throwable $e) {
        throw new Exception('Failed to set company default: ' . $e->getMessage());
    }

    if (class_exists('AuditLog')) {
        try {
            AuditLog::log('template_set_as_default', 'company', $companyId, null,
                ['side' => $side, 'template_id' => $id], $companyId);
        } catch (Throwable $_) { /* best-effort */ }
    }

    return ['success' => true, 'side' => $side, 'template_id' => $id];
}

/**
 * Revert a template to a previous version stored in template_versions.
 * Restores fields_json + settings_json + background_image_path onto the
 * live templates row, bumps current_version + 1 (does NOT overwrite the
 * historic row), records an AuditLog entry. Cross-tenant-guarded.
 */
function revertTemplateVersion() {
    $id      = trim($_POST['id']      ?? '');
    $version = (int) ($_POST['version'] ?? 0);
    if ($id === '' || $version <= 0) {
        throw new Exception('Template id and version are required');
    }

    $companyId = getCurrentCompanyId();
    if (!$companyId) {
        throw new Exception('Company context required');
    }

    $db = Database::getInstance();

    // Cross-tenant guard: template must belong to this company.
    $tpl = $db->fetchOne(
        "SELECT id, company_id, current_version FROM templates WHERE id = :id",
        ['id' => $id]
    );
    if (!$tpl || ($tpl['company_id'] ?? null) !== $companyId) {
        throw new Exception('Template not found');
    }

    $snap = $db->fetchOne(
        "SELECT fields_json, settings_json, background_image_path
           FROM template_versions
          WHERE template_id = :id AND version_number = :v LIMIT 1",
        ['id' => $id, 'v' => $version]
    );
    if (!$snap) {
        throw new Exception('Version not found');
    }

    $newVersion = ((int) ($tpl['current_version'] ?? 1)) + 1;

    try {
        // Snapshot the current live row into template_versions first so
        // users can re-revert. Pull the live row then insert a new
        // version row with the next sequential number BEFORE applying
        // the restore (so current_version points at the historic copy).
        $live = $db->fetchOne(
            "SELECT fields_json, settings_json, background_image_path
               FROM templates WHERE id = :id",
            ['id' => $id]
        );
        $db->insert('template_versions', [
            'template_id'            => $id,
            'version_number'         => $newVersion,
            'fields_json'            => $snap['fields_json'],
            'settings_json'          => $snap['settings_json'],
            'background_image_path'  => $snap['background_image_path'],
            'created_by'             => $_SESSION['user_id'] ?? null,
            'change_summary'         => 'Revert to version ' . $version,
        ]);

        $db->update('templates', [
            'fields_json'           => $snap['fields_json'],
            'settings_json'         => $snap['settings_json'],
            'background_image_path' => $snap['background_image_path'],
            'current_version'       => $newVersion,
        ], 'id = :id', ['id' => $id]);
    } catch (Throwable $e) {
        throw new Exception('Revert failed: ' . $e->getMessage());
    }

    if (class_exists('AuditLog')) {
        try {
            AuditLog::log('template_reverted', 'template', $id, null,
                ['restored_from_version' => $version, 'new_version' => $newVersion],
                $companyId);
        } catch (Throwable $_) { /* best-effort */ }
    }

    return ['success' => true, 'template_id' => $id, 'restored_from' => $version, 'new_version' => $newVersion];
}

