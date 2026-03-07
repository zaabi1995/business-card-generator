<?php
/**
 * Public Templates API - Returns active templates for a company (no auth required)
 * Used by the employee portal
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$companySlug = $_GET['company_slug'] ?? '';

if (empty($companySlug)) {
    http_response_code(400);
    echo json_encode(['error' => 'Company slug required']);
    exit;
}

// Find company
$company = findCompanyBySlug($companySlug);
if (!$company) {
    http_response_code(404);
    echo json_encode(['error' => 'Company not found', 'slug' => $companySlug]);
    exit;
}

$companyId = $company['id'];

try {
    $config = loadTemplates($companyId);
    
    // Only include debug info in development
    if (function_exists('isProduction') && !isProduction()) {
        $config['_debug'] = [
            'company_id' => $companyId,
            'company_name' => $company['name'] ?? $company['name_en'] ?? 'Unknown',
            'template_count' => count($config['templates'] ?? [])
        ];
        
        if (!empty($config['templates'])) {
            foreach ($config['templates'] as &$tpl) {
                $enabledFields = [];
                if (!empty($tpl['fields'])) {
                    foreach ($tpl['fields'] as $key => $field) {
                        if (!empty($field['enabled'])) {
                            $enabledFields[] = $key;
                        }
                    }
                }
                $tpl['_enabledFields'] = $enabledFields;
            }
        }
    }
    
    echo json_encode($config);
} catch (Exception $e) {
    error_log('get_templates_public error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load templates']);
}
