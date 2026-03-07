<?php
/**
 * Debug - Output the full templateEditor script as PHP would render it
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

header('Content-Type: application/javascript; charset=utf-8');
header('Content-Disposition: attachment; filename="templateEditor.js"');
?>
function templateEditor() {
    return {
        activeTab: 'front',
        templates: <?php echo json_encode(array_values($templates), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        activeFrontId: <?php echo json_encode($activeFrontId, JSON_HEX_TAG); ?>,
        activeBackId: <?php echo json_encode($activeBackId, JSON_HEX_TAG); ?>,
        selectedTemplate: null,
        showAddModal: false,
        newTemplate: { name: '', side: 'front', imageFile: null },
        statusMessage: '',
        statusType: 'success',
        cardEditor: null,
        basePath: '<?php echo getBasePath(); ?>',
        sampleEmployee: <?php echo json_encode($sampleEmployee, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        companySlug: '<?php echo $companySlug; ?>',
        baseUrl: '<?php echo $baseUrl; ?>',
        fontsLoaded: false,
        initialized: false,
        
        cardSize: 'standard',
        cardOrientation: 'landscape',
        dpi: 300,
        bleedEnabled: false,
        bleedSize: 0.125,
        bleedUnit: 'in',
        MM_PER_INCH: 25.4,
        
        cardSizes: {
            standard: { widthIn: 3.5, heightIn: 2, widthMm: 89, heightMm: 51, label: 'Standard US', region: 'US/Canada' },
            eu: { widthIn: 3.346, heightIn: 2.165, widthMm: 85, heightMm: 55, label: 'European', region: 'EU/UK' },
            custom: { widthIn: 3.5, heightIn: 2, widthMm: 89, heightMm: 51, label: 'Custom', region: 'Your size' }
        },
        
        customWidth: 3.5,
        customHeight: 2,
        customUnit: 'in',
        maxDisplayWidth: 480,
        
        init: function() {
            console.log('init called');
        },
        
        test: function() {
            console.log('test');
            return true;
        }
    };
}

console.log('Script loaded, templateEditor type:', typeof templateEditor);
