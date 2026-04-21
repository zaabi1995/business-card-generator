<?php
/**
 * Cardify API for BHD File Manager
 */

header('Access-Control-Allow-Origin: https://upload.bhdoman.com');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../config.php';

// API key lives in config so it can be rotated without a git commit.
// Falls back to the old hardcoded key only if nothing is configured, to keep
// existing integrations working during the rotation window.
$expectedKey = defined('FILE_MANAGER_API_KEY') && FILE_MANAGER_API_KEY !== ''
    ? FILE_MANAGER_API_KEY
    : 'bhd-fm-cardify-2026';

$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['key'] ?? '';
if (!is_string($apiKey) || !hash_equals($expectedKey, $apiKey)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once INCLUDES_DIR . '/VCF.php';

$action = $_GET['action'] ?? '';
$db = Database::getInstance();

if (!$db->isConnected()) {
    http_response_code(500);
    echo json_encode(['error' => 'Database not available']);
    exit;
}

function vcfUrl($slug, $email) {
    return "https://cardify.om/{$slug}/" . urlencode($email) . ".vcf";
}

switch ($action) {
    case 'companies':
        $companies = $db->fetchAll(
            "SELECT id, name, slug, admin_email, email_domain, domain FROM companies WHERE status = 'active' ORDER BY name"
        );
        foreach ($companies as &$c) {
            $count = $db->fetchOne("SELECT COUNT(*) as cnt FROM employees WHERE company_id = :id", ['id' => $c['id']]);
            $c['employee_count'] = (int)($count['cnt'] ?? 0);
            
            // Get common fields from first employee for auto-fill hints
            if ($c['employee_count'] > 0) {
                $sample = $db->fetchOne(
                    "SELECT company_en, company_ar, phone, website, website_ar, address_en, address_ar 
                     FROM employees WHERE company_id = :id AND company_en IS NOT NULL AND company_en != '' LIMIT 1",
                    ['id' => $c['id']]
                );
                if ($sample) {
                    $c['defaults'] = [
                        'company_en' => $sample['company_en'] ?? $c['name'],
                        'company_ar' => $sample['company_ar'] ?? '',
                        'website' => $sample['website'] ?? '',
                        'address_en' => $sample['address_en'] ?? '',
                    ];
                }
            }
            if (!isset($c['defaults'])) {
                $c['defaults'] = ['company_en' => $c['name'], 'company_ar' => '', 'website' => '', 'address_en' => ''];
            }
            // Derive email domain
            if (empty($c['email_domain']) && !empty($c['admin_email'])) {
                $c['email_domain'] = substr($c['admin_email'], strpos($c['admin_email'], '@') + 1);
            }
        }
        echo json_encode(['companies' => $companies]);
        break;

    case 'employees':
        $slug = $_GET['company'] ?? '';
        if (!$slug) { echo json_encode(['error' => 'Missing company slug']); break; }
        
        $company = findCompanyBySlug($slug);
        if (!$company) { echo json_encode(['error' => 'Company not found']); break; }
        
        $employees = $db->fetchAll(
            "SELECT id, email, name_en, name_ar, position_en, position_ar, phone, mobile 
             FROM employees WHERE company_id = :id ORDER BY name_en",
            ['id' => $company['id']]
        );
        
        foreach ($employees as &$emp) {
            $emp['vcf_url'] = vcfUrl($slug, $emp['email']);
            $emp['qr_url'] = vcfUrl($slug, $emp['email']);
        }
        
        echo json_encode([
            'company' => ['id' => $company['id'], 'name' => $company['name'], 'slug' => $slug],
            'employees' => $employees
        ]);
        break;

    case 'create_employee':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['error' => 'POST required']); break; }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $slug = $input['company'] ?? '';
        if (!$slug) { echo json_encode(['error' => 'Missing company slug']); break; }
        
        $company = findCompanyBySlug($slug);
        if (!$company) { echo json_encode(['error' => 'Company not found']); break; }
        
        $data = [
            'email' => $input['email'] ?? '',
            'name_en' => $input['name_en'] ?? '',
            'name_ar' => $input['name_ar'] ?? '',
            'position_en' => $input['position_en'] ?? '',
            'position_ar' => $input['position_ar'] ?? '',
            'phone' => $input['phone'] ?? '',
            'mobile' => $input['mobile'] ?? '',
            'company_en' => $input['company_en'] ?? $company['name'],
            'company_ar' => $input['company_ar'] ?? '',
            'website' => $input['website'] ?? '',
            'address_en' => $input['address_en'] ?? '',
        ];
        
        if (!$data['email'] || !$data['name_en']) {
            echo json_encode(['error' => 'Name and email are required']);
            break;
        }
        
        $existing = findEmployeeByEmail($data['email'], $company['id']);
        if ($existing) {
            $result = updateEmployee($existing['id'], $data, $company['id']);
            $action_done = 'updated';
        } else {
            $result = addEmployee($data, $company['id']);
            $action_done = 'created';
        }
        
        if ($result['success'] ?? false) {
            echo json_encode([
                'success' => true,
                'action' => $action_done,
                'vcf_url' => vcfUrl($slug, $data['email']),
                'qr_url' => vcfUrl($slug, $data['email']),
            ]);
        } else {
            echo json_encode(['error' => 'Failed to save employee']);
        }
        break;

    default:
        echo json_encode(['error' => 'Unknown action', 'available' => ['companies', 'employees', 'create_employee']]);
}
