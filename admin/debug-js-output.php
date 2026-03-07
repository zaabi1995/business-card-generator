<?php
/**
 * Debug - Output the exact JavaScript that will be rendered
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';

Auth::init();

$companyId = getCurrentCompanyId();

// Load templates
$templatesConfig = loadTemplates();
$templates = $templatesConfig['templates'] ?? [];

$activeFrontId = $templatesConfig['active_front'] ?? null;
$activeBackId = $templatesConfig['active_back'] ?? null;

$sampleEmployee = [
    'name_en' => 'John Doe',
    'name_ar' => 'جون دو',
    'position_en' => 'Software Engineer',
    'position_ar' => 'مهندس برمجيات',
    'company_en' => 'Company Name',
    'company_ar' => 'اسم الشركة',
    'phone' => '+968 1234 5678',
    'mobile' => '+968 9876 5432',
    'email' => 'john.doe@company.com',
    'website' => 'www.company.com',
    'address' => '123 Business Street'
];

// Get company slug
$companySlug = '';
$baseUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'bc.bhd.om');
if ($companyId && DatabaseAdapter::useDatabase()) {
    try {
        $db = Database::getInstance();
        $company = $db->fetchOne("SELECT slug FROM companies WHERE id = :id", ['id' => $companyId]);
        $companySlug = $company['slug'] ?? '';
    } catch (Exception $e) {
        // Ignore
    }
}

header('Content-Type: text/plain; charset=utf-8');

echo "=== JavaScript variable definitions (lines 612-624) ===\n\n";
echo "templates: " . json_encode(array_values($templates), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ",\n\n";
echo "activeFrontId: " . json_encode($activeFrontId, JSON_HEX_TAG) . ",\n\n";
echo "activeBackId: " . json_encode($activeBackId, JSON_HEX_TAG) . ",\n\n";
echo "basePath: '" . getBasePath() . "',\n\n";
echo "sampleEmployee: " . json_encode($sampleEmployee, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ",\n\n";
echo "companySlug: '" . $companySlug . "',\n\n";
echo "baseUrl: '" . $baseUrl . "',\n\n";
echo "getDefaultFieldSettings: " . json_encode(getDefaultFieldSettings(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . "\n\n";

echo "=== All values look valid? ===\n";
echo "If you see any unusual characters or broken JSON above, that's the problem.\n";
