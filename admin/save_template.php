<?php
/**
 * Save Template - Handles template CRUD operations
 */
require_once __DIR__ . '/../config.php';
requireAdmin();

header('Content-Type: application/json');

try {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'add':
            $result = addNewTemplate();
            break;
        case 'update':
            $result = updateTemplate();
            break;
        case 'delete':
            $result = deleteTemplateAction();
            break;
        case 'activate':
            $result = activateTemplate();
            break;
        default:
            throw new Exception('Invalid action');
    }
    
    echo json_encode($result);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function addNewTemplate() {
    $name = trim($_POST['name'] ?? '');
    $side = $_POST['side'] ?? 'front';
    $fieldsJson = $_POST['fields'] ?? '{}';
    
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
    $destination = $companyId ? getCompanyTemplatesDir($companyId) : TEMPLATES_DIR;
    $uploadResult = handleFileUpload($_FILES['image'], $destination);
    if (!$uploadResult['success']) {
        throw new Exception($uploadResult['error']);
    }
    
    $fields = json_decode($fieldsJson, true);
    if (!$fields) {
        $fields = getDefaultFieldSettings();
    }
    
    $template = [
        'id' => generateTemplateId($name),
        'name' => $name,
        'side' => $side,
        'backgroundImage' => getWebPath($uploadResult['path']),
        'fields' => $fields,
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    // Save to config
    $config = loadTemplates($companyId);
    $config['templates'][] = $template;
    
    if (!saveTemplates($config, $companyId)) {
        throw new Exception('Failed to save template');
    }
    
    return ['success' => true, 'template' => $template];
}

function updateTemplate() {
    $id = $_POST['id'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $fieldsJson = $_POST['fields'] ?? '';
    
    if (empty($id)) {
        throw new Exception('Template ID is required');
    }
    
    $companyId = getCurrentCompanyId();
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
            
            // Handle new image upload if provided
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $destination = $companyId ? getCompanyTemplatesDir($companyId) : TEMPLATES_DIR;
                $uploadResult = handleFileUpload($_FILES['image'], $destination);
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
        throw new Exception('Failed to save template');
    }
    
    return ['success' => true];
}

function deleteTemplateAction() {
    $id = $_POST['id'] ?? '';
    
    if (empty($id)) {
        throw new Exception('Template ID is required');
    }
    
    $companyId = getCurrentCompanyId();
    $config = loadTemplates($companyId);
    $newTemplates = [];
    $deletedTemplate = null;
    
    foreach ($config['templates'] as $template) {
        if ($template['id'] === $id) {
            $deletedTemplate = $template;
        } else {
            $newTemplates[] = $template;
        }
    }
    
    if (!$deletedTemplate) {
        throw new Exception('Template not found');
    }
    
    // Delete background image
    $imagePath = getFilePath($deletedTemplate['backgroundImage']);
    if (file_exists($imagePath)) {
        @unlink($imagePath);
    }
    
    // Clear active if this was active
    if ($config['activeFrontId'] === $id) {
        $config['activeFrontId'] = null;
    }
    if ($config['activeBackId'] === $id) {
        $config['activeBackId'] = null;
    }
    
    $config['templates'] = $newTemplates;
    
    if (!saveTemplates($config, $companyId)) {
        throw new Exception('Failed to delete template');
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

