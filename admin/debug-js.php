<?php
/**
 * Debug - Check PHP output for JavaScript
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

echo "<h1>PHP Variable Debug</h1>\n";

echo "<h2>1. templates (json_encode)</h2>\n";
$json1 = json_encode(array_values($templates), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
echo "<pre style='background:#f0f0f0;padding:10px;overflow:auto'>" . htmlspecialchars($json1) . "</pre>\n";
echo "<p>JSON valid: " . (json_last_error() === JSON_ERROR_NONE ? 'YES' : 'NO - ' . json_last_error_msg()) . "</p>\n";

echo "<h2>2. activeFrontId (json_encode)</h2>\n";
$json2 = json_encode($activeFrontId, JSON_HEX_TAG);
echo "<pre style='background:#f0f0f0;padding:10px'>" . htmlspecialchars($json2) . "</pre>\n";
echo "<p>JSON valid: " . (json_last_error() === JSON_ERROR_NONE ? 'YES' : 'NO - ' . json_last_error_msg()) . "</p>\n";

echo "<h2>3. activeBackId (json_encode)</h2>\n";
$json3 = json_encode($activeBackId, JSON_HEX_TAG);
echo "<pre style='background:#f0f0f0;padding:10px'>" . htmlspecialchars($json3) . "</pre>\n";
echo "<p>JSON valid: " . (json_last_error() === JSON_ERROR_NONE ? 'YES' : 'NO - ' . json_last_error_msg()) . "</p>\n";

echo "<h2>4. sampleEmployee (json_encode)</h2>\n";
$json4 = json_encode($sampleEmployee, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
echo "<pre style='background:#f0f0f0;padding:10px'>" . htmlspecialchars($json4) . "</pre>\n";
echo "<p>JSON valid: " . (json_last_error() === JSON_ERROR_NONE ? 'YES' : 'NO - ' . json_last_error_msg()) . "</p>\n";

echo "<h2>5. getDefaultFieldSettings (json_encode)</h2>\n";
$json5 = json_encode(getDefaultFieldSettings(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
echo "<pre style='background:#f0f0f0;padding:10px'>" . htmlspecialchars($json5) . "</pre>\n";
echo "<p>JSON valid: " . (json_last_error() === JSON_ERROR_NONE ? 'YES' : 'NO - ' . json_last_error_msg()) . "</p>\n";

echo "<h2>6. basePath</h2>\n";
echo "<pre style='background:#f0f0f0;padding:10px'>" . htmlspecialchars(getBasePath()) . "</pre>\n";

echo "<h2>7. Simple JavaScript Test</h2>\n";
?>
<script>
try {
    var testTemplates = <?php echo $json1; ?>;
    var testActiveFront = <?php echo $json2; ?>;
    var testActiveBack = <?php echo $json3; ?>;
    var testEmployee = <?php echo $json4; ?>;
    var testFields = <?php echo $json5; ?>;
    
    console.log('Templates:', testTemplates);
    console.log('ActiveFront:', testActiveFront);
    console.log('ActiveBack:', testActiveBack);
    console.log('Employee:', testEmployee);
    console.log('Fields:', testFields);
    
    document.write('<p style="color:green;font-weight:bold">✓ JavaScript parsed all PHP variables successfully!</p>');
} catch(e) {
    document.write('<p style="color:red;font-weight:bold">✗ JavaScript Error: ' + e.message + '</p>');
    console.error(e);
}
</script>
