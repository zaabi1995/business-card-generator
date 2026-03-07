<?php
// Test page to check JavaScript syntax
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/CardEditor.php';

// Initialize Auth
Auth::init();
$companyId = getCurrentCompanyId();

// Load sample data
try {
    $templates = loadTemplates($companyId);
} catch (Exception $e) {
    $templates = [];
}

$activeFrontId = null;
$activeBackId = null;
$sampleEmployee = [
    'name' => 'Test User',
    'title' => 'Test Title',
    'email' => 'test@example.com',
    'phone' => '+1234567890'
];
$companySlug = 'test';
$baseUrl = 'https://example.com';

header('Content-Type: application/javascript');
?>
// Test JavaScript syntax
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
            standard: { 
                widthIn: 3.5, heightIn: 2, 
                widthMm: 89, heightMm: 51,
                label: 'Standard US', region: 'US/Canada'
            },
            eu: { 
                widthIn: 3.346, heightIn: 2.165, 
                widthMm: 85, heightMm: 55,
                label: 'European', region: 'EU/UK'
            },
            custom: { 
                widthIn: 3.5, heightIn: 2, 
                widthMm: 89, heightMm: 51,
                label: 'Custom', region: 'Your size'
            }
        },
        
        customWidth: 3.5,
        customHeight: 2,
        customUnit: 'in',
        maxDisplayWidth: 480,
        
        // Methods
        inchesToPixels(inches) {
            return Math.round(inches * this.dpi);
        },
        
        getCanvasDimensions() {
            var widthIn, heightIn;
            var size = this.cardSizes[this.cardSize] || this.cardSizes.standard;
            widthIn = size.widthIn;
            heightIn = size.heightIn;
            return { 
                width: this.inchesToPixels(widthIn), 
                height: this.inchesToPixels(heightIn)
            };
        },
        
        test() {
            console.log('Test passed');
        }
    };
}

console.log('JavaScript syntax is valid!');
console.log('templateEditor function:', typeof templateEditor);
