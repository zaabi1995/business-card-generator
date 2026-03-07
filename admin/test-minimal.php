<?php
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';

Auth::init();

$companyId = getCurrentCompanyId();
$templatesConfig = loadTemplates();
$templates = $templatesConfig['templates'] ?? [];
$activeFrontId = $templatesConfig['active_front'] ?? null;
$activeBackId = $templatesConfig['active_back'] ?? null;

$sampleEmployee = [
    'name_en' => 'John Doe',
    'email' => 'john@example.com'
];

$companySlug = '';
$baseUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'bc.bhd.om');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Minimal Test</title>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
</head>
<body>
<h1>Minimal Alpine.js Test</h1>

<div x-data="templateEditor()" x-init="init()">
    <p>Active Tab: <span x-text="activeTab"></span></p>
    <p>Card Size: <span x-text="cardSize"></span></p>
    <p>DPI: <span x-text="dpi"></span></p>
    <button @click="activeTab = 'back'">Switch to Back</button>
</div>

<script>
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
            standard: { widthIn: 3.5, heightIn: 2, widthMm: 89, heightMm: 51, label: 'Standard US' },
            custom: { widthIn: 3.5, heightIn: 2, widthMm: 89, heightMm: 51, label: 'Custom' }
        },
        
        customWidth: 3.5,
        customHeight: 2,
        customUnit: 'in',
        maxDisplayWidth: 480,
        
        init: function() {
            console.log('init called');
            this.initialized = true;
        },
        
        inchesToPixels: function(inches) {
            return Math.round(inches * this.dpi);
        },
        
        getTemplatesForTab: function() {
            var self = this;
            return this.templates.filter(function(t) { 
                return (t.side || 'front') === self.activeTab; 
            });
        },
        
        getSizeDetails: function() {
            return {
                inches: this.cardSizes[this.cardSize].widthIn + '" x ' + this.cardSizes[this.cardSize].heightIn + '"',
                mm: this.cardSizes[this.cardSize].widthMm + ' x ' + this.cardSizes[this.cardSize].heightMm + ' mm',
                pixels: this.inchesToPixels(this.cardSizes[this.cardSize].widthIn) + ' x ' + this.inchesToPixels(this.cardSizes[this.cardSize].heightIn) + ' px',
                bleed: 'None'
            };
        },
        
        getCanvasContainerStyle: function() {
            return 'width: 400px; height: 240px;';
        },
        
        getCanvasDimensionsText: function() {
            return '3.5" x 2"';
        },
        
        showStatus: function(message, type) {
            this.statusMessage = message;
            this.statusType = type;
        }
    };
}
console.log('templateEditor defined:', typeof templateEditor);
</script>
</body>
</html>
