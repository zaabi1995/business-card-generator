<?php
/**
 * Batch Generate Business Cards - Using Fabric.js
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';
require_once INCLUDES_DIR . '/Mailer.php';
require_once INCLUDES_DIR . '/Billing.php';

// Check authentication
if (!Auth::isLoggedIn()) {
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

$companyId = getCurrentCompanyId();

// Get company plan info for quality settings
$planInfo = Billing::getCompanyPlanInfo($companyId);
$qualityMultiplier = $planInfo['quality_multiplier'];
$isFreePlan = $planInfo['plan'] === 'free' || empty($planInfo['plan']);
$hasHighQuality = $planInfo['features']['high_quality'] ?? false;

// Check for pre-selected employee (from card request approval)
$preSelectedEmployeeId = $_GET['employee_id'] ?? null;
$autoGenerate = isset($_GET['auto_generate']) && $_GET['auto_generate'] === '1';
$sendEmail = isset($_GET['send_email']) && $_GET['send_email'] === '1';

// Get company info for emails
$company = findCompanyById($companyId);
$companyName = $company['name_en'] ?? $company['name'] ?? 'Company';

try {
    $employees = loadEmployees($companyId) ?: [];
    $templatesConfig = loadTemplates($companyId);
    $frontTemplate = getActiveFrontTemplate($companyId);
    $backTemplate = getActiveBackTemplate($companyId);
    
    // Convert legacy field positions
    if ($frontTemplate && isset($frontTemplate['fields'])) {
        $frontTemplate['fields'] = convertLegacyFieldPositions($frontTemplate['fields']);
    }
    if ($backTemplate && isset($backTemplate['fields'])) {
        $backTemplate['fields'] = convertLegacyFieldPositions($backTemplate['fields']);
    }
    
    // Load department templates (keyed by department_id)
    $departmentTemplates = [];
    $db = Database::getInstance();
    $departments = $db->fetchAll(
        "SELECT id, template_pair_id FROM departments WHERE company_id = :id AND template_pair_id IS NOT NULL",
        ['id' => $companyId]
    );
    
    foreach ($departments as $dept) {
        if (!empty($dept['template_pair_id'])) {
            // Get front template from pair
            $deptFront = $db->fetchOne(
                "SELECT * FROM templates WHERE pair_id = :pair_id AND side = 'front'",
                ['pair_id' => $dept['template_pair_id']]
            );
            // Get back template from pair
            $deptBack = $db->fetchOne(
                "SELECT * FROM templates WHERE pair_id = :pair_id AND side = 'back'",
                ['pair_id' => $dept['template_pair_id']]
            );
            
            $deptTemplates = ['front' => null, 'back' => null];
            
            if ($deptFront) {
                $deptTemplates['front'] = [
                    'id' => $deptFront['id'],
                    'name' => $deptFront['name'],
                    'backgroundImage' => $deptFront['background_image_path'],
                    'originalPdf' => $deptFront['original_pdf_path'] ?? null,
                    'fields' => json_decode($deptFront['fields_json'] ?? '{}', true),
                    'settings' => json_decode($deptFront['settings_json'] ?? '{}', true)
                ];
                // Convert legacy field positions
                if (isset($deptTemplates['front']['fields'])) {
                    $deptTemplates['front']['fields'] = convertLegacyFieldPositions($deptTemplates['front']['fields']);
                }
            }
            
            if ($deptBack) {
                $deptTemplates['back'] = [
                    'id' => $deptBack['id'],
                    'name' => $deptBack['name'],
                    'backgroundImage' => $deptBack['background_image_path'],
                    'originalPdf' => $deptBack['original_pdf_path'] ?? null,
                    'fields' => json_decode($deptBack['fields_json'] ?? '{}', true),
                    'settings' => json_decode($deptBack['settings_json'] ?? '{}', true)
                ];
                // Convert legacy field positions
                if (isset($deptTemplates['back']['fields'])) {
                    $deptTemplates['back']['fields'] = convertLegacyFieldPositions($deptTemplates['back']['fields']);
                }
            }
            
            $departmentTemplates[$dept['id']] = $deptTemplates;
        }
    }
} catch (Exception $e) {
    $employees = [];
    $templatesConfig = ['templates' => [], 'activeFrontId' => null, 'activeBackId' => null];
    $frontTemplate = null;
    $backTemplate = null;
    $departmentTemplates = [];
    error_log("Batch generate error: " . $e->getMessage());
}

$hasTemplates = $frontTemplate || $backTemplate;
$companySlug = getCurrentCompanySlug() ?? '';
$baseUrl = getBaseUrl();

adminHeader('Batch Generate', 'generated');
?>

<?php if ($isFreePlan): ?>
<!-- Free Plan Quality Notice -->
<div class="mb-6 bg-amber-50 border border-amber-200 rounded-xl p-4">
    <div class="flex items-start gap-3">
        <i class="fa-solid fa-info-circle text-amber-500 text-xl mt-0.5"></i>
        <div class="flex-1">
            <h4 class="font-semibold text-amber-800">Preview Quality</h4>
            <p class="text-sm text-amber-700 mt-1">
                You're on the <strong>Free Plan</strong>. Card previews are generated at lower resolution for faster processing. 
                When ordering prints, cards will be produced at full print quality by the print shop.
            </p>
            <div class="mt-3 flex flex-wrap gap-2">
                <a href="billing.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-amber-600 hover:bg-amber-700 text-white rounded-lg text-sm font-medium transition-colors">
                    <i class="fa-solid fa-crown"></i>
                    Upgrade for HD Quality
                </a>
                <a href="billing.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-amber-700 hover:text-amber-800 text-sm font-medium">
                    View Plans <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div x-data="batchGenerator()" x-init="init()" class="space-y-6">
    <?php if (!$hasTemplates): ?>
    <div class="bg-white border border-gray-200 rounded-xl p-8 text-center shadow-sm">
        <svg class="w-16 h-16 mx-auto mb-4 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
        </svg>
        <h2 class="text-xl font-bold text-gray-900 mb-2">No Active Templates</h2>
        <p class="text-gray-600 mb-4">Please set up and activate at least one template (front or back) before batch generating.</p>
        <a href="index.php" class="inline-block px-6 py-3 bg-blue-600 text-white rounded-xl hover:bg-blue-700 transition-colors">
            Go to Template Settings
        </a>
    </div>
    <?php elseif (empty($employees)): ?>
    <div class="bg-white border border-gray-200 rounded-xl p-8 text-center shadow-sm">
        <svg class="w-16 h-16 mx-auto mb-4 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"></path>
        </svg>
        <h2 class="text-xl font-bold text-gray-900 mb-2">No Employees</h2>
        <p class="text-gray-600 mb-4">Add employees first before batch generating cards.</p>
        <a href="employees.php" class="inline-block px-6 py-3 bg-blue-600 text-white rounded-xl hover:bg-blue-700 transition-colors">
            Manage Employees
        </a>
    </div>
    <?php else: ?>
    
    <!-- Template Status -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8">
        <div class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-lg <?php echo $frontTemplate ? 'bg-green-100' : 'bg-gray-100'; ?> flex items-center justify-center">
                    <svg class="w-5 h-5 <?php echo $frontTemplate ? 'text-green-600' : 'text-gray-400'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                </div>
                <div>
                    <p class="text-gray-900 font-medium">Front Template</p>
                    <p class="text-gray-500 text-sm"><?php echo $frontTemplate ? sanitize($frontTemplate['name']) : 'Not configured'; ?></p>
                </div>
            </div>
        </div>
        
        <div class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-lg <?php echo $backTemplate ? 'bg-green-100' : 'bg-gray-100'; ?> flex items-center justify-center">
                    <svg class="w-5 h-5 <?php echo $backTemplate ? 'text-green-600' : 'text-gray-400'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                </div>
                <div>
                    <p class="text-gray-900 font-medium">Back Template</p>
                    <p class="text-gray-500 text-sm"><?php echo $backTemplate ? sanitize($backTemplate['name']) : 'Not configured'; ?></p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Font Loading Status -->
    <div x-show="!fontsLoaded" class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-8">
        <div class="flex items-center space-x-3">
            <div class="w-6 h-6 border-2 border-blue-600/30 border-t-blue-600 rounded-full animate-spin"></div>
            <p class="text-blue-700">Loading fonts...</p>
        </div>
    </div>
    
    <!-- Progress -->
    <div x-show="isGenerating" class="bg-white border border-gray-200 rounded-xl p-6 mb-8 shadow-sm">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-900">Generating Cards...</h3>
            <span class="text-blue-600" x-text="currentIndex + ' / ' + selectedEmployees.length"></span>
        </div>
        <div class="w-full h-3 bg-gray-100 rounded-full overflow-hidden">
            <div class="h-full bg-blue-600 transition-all duration-300" :style="'width: ' + progress + '%'"></div>
        </div>
        <p class="text-gray-500 text-sm mt-2" x-text="currentEmployee"></p>
    </div>
    
    <!-- Completed -->
    <div x-show="isComplete" class="bg-green-50 border border-green-200 rounded-xl p-6 mb-8">
        <div class="flex items-center space-x-4">
            <div class="w-12 h-12 rounded-full bg-green-100 flex items-center justify-center">
                <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
            </div>
            <div>
                <h3 class="text-lg font-semibold text-gray-900">Generation Complete</h3>
                <p class="text-gray-600">Successfully generated <span x-text="completedCount"></span> business cards</p>
            </div>
        </div>
        <div class="mt-4 flex gap-3">
            <a href="generated.php" class="inline-block px-6 py-2 bg-blue-600 text-white rounded-xl hover:bg-blue-700 transition-colors text-sm">
                View Generated Cards
            </a>
            <button @click="reset()" class="px-6 py-2 bg-gray-100 text-gray-700 rounded-xl hover:bg-gray-200 transition-colors text-sm">
                Generate More
            </button>
        </div>
    </div>
    
    <!-- Employee Selection -->
    <div class="bg-white border border-gray-200 rounded-xl p-6 shadow-sm" x-show="!isGenerating && !isComplete">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-900">Select Employees</h3>
            <div class="flex items-center space-x-4">
                <label class="flex items-center space-x-2 text-sm text-gray-500 cursor-pointer">
                    <input type="checkbox" @change="toggleAll($event.target.checked)" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    <span>Select All</span>
                </label>
                <button 
                    @click="startGeneration()"
                    :disabled="selectedEmployees.length === 0 || !fontsLoaded"
                    class="px-6 py-2 bg-blue-600 text-white rounded-xl text-sm disabled:opacity-50 disabled:cursor-not-allowed flex items-center space-x-2 hover:bg-blue-700 transition-colors"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                    </svg>
                    <span>Generate (<span x-text="selectedEmployees.length"></span>)</span>
                </button>
            </div>
        </div>
        
        <div class="space-y-2 max-h-96 overflow-y-auto pr-2">
            <?php foreach ($employees as $emp): ?>
            <label class="flex items-center space-x-4 p-3 rounded-xl hover:bg-gray-50 cursor-pointer transition-colors border border-gray-100">
                <input 
                    type="checkbox" 
                    value="<?php echo sanitize($emp['id']); ?>"
                    @change="toggleEmployee('<?php echo $emp['id']; ?>', $event.target.checked)"
                    class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 employee-checkbox"
                >
                <div class="flex-1">
                    <p class="text-gray-900"><?php echo sanitize($emp['name_en'] ?? ''); ?></p>
                    <p class="text-gray-500 text-sm"><?php echo sanitize($emp['email'] ?? ''); ?></p>
                </div>
                <p class="text-gray-500 text-sm"><?php echo sanitize($emp['position_en'] ?? ''); ?></p>
            </label>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Hidden canvas for rendering -->
    <canvas id="renderCanvas" style="display: none;"></canvas>
</div>

<script>
function batchGenerator() {
    return {
        employees: <?php echo json_encode($employees, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        frontTemplate: <?php echo json_encode($frontTemplate, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        backTemplate: <?php echo json_encode($backTemplate, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        departmentTemplates: <?php echo json_encode($departmentTemplates ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        companySlug: '<?php echo $companySlug; ?>',
        companyName: '<?php echo addslashes($companyName); ?>',
        baseUrl: '<?php echo $baseUrl; ?>',
        basePath: '<?php echo getBasePath(); ?>',
        // Plan-based quality settings
        qualityMultiplier: <?php echo (int)$qualityMultiplier; ?>,
        isFreePlan: <?php echo $isFreePlan ? 'true' : 'false'; ?>,
        hasHighQuality: <?php echo $hasHighQuality ? 'true' : 'false'; ?>,
        preSelectedEmployeeId: <?php echo $preSelectedEmployeeId ? "'" . addslashes($preSelectedEmployeeId) . "'" : 'null'; ?>,
        autoGenerate: <?php echo $autoGenerate ? 'true' : 'false'; ?>,
        sendEmail: <?php echo $sendEmail ? 'true' : 'false'; ?>,
        selectedEmployees: [],
        isGenerating: false,
        isComplete: false,
        currentIndex: 0,
        currentEmployee: '',
        completedCount: 0,
        fontsLoaded: false,
        cardEditor: null,
        generatedCards: [], // Store generated card paths for email
        
        // Card size presets (matching template editor)
        cardSizes: {
            standard: { widthIn: 3.5, heightIn: 2 },
            eu: { widthIn: 3.346, heightIn: 2.165 },
            japanese: { widthIn: 3.582, heightIn: 2.165 },
            square: { widthIn: 2.5, heightIn: 2.5 },
            mini: { widthIn: 2.75, heightIn: 1.1 },
            custom: { widthIn: 3.5, heightIn: 2 }
        },
        
        // Calculate canvas dimensions from template settings
        getCanvasDimensions(template) {
            const settings = template?.settings || {};
            const dpi = settings.dpi || 300;
            const cardSize = settings.cardSize || 'standard';
            const orientation = settings.cardOrientation || 'landscape';
            
            let size = this.cardSizes[cardSize] || this.cardSizes.standard;
            let widthIn = size.widthIn;
            let heightIn = size.heightIn;
            
            // Handle custom size
            if (cardSize === 'custom' && settings.customWidth && settings.customHeight) {
                if (settings.customUnit === 'mm') {
                    widthIn = settings.customWidth / 25.4;
                    heightIn = settings.customHeight / 25.4;
                } else {
                    widthIn = settings.customWidth;
                    heightIn = settings.customHeight;
                }
            }
            
            // Apply orientation (swap for portrait)
            if (orientation === 'portrait' && cardSize !== 'square') {
                [widthIn, heightIn] = [heightIn, widthIn];
            }
            
            // Add bleed if enabled
            if (settings.bleedEnabled && settings.bleedSize) {
                const bleedIn = settings.bleedUnit === 'mm' ? settings.bleedSize / 25.4 : settings.bleedSize;
                widthIn += bleedIn * 2;
                heightIn += bleedIn * 2;
            }
            
            return {
                width: Math.round(widthIn * dpi),
                height: Math.round(heightIn * dpi)
            };
        },
        
        get progress() {
            if (this.selectedEmployees.length === 0) return 0;
            return Math.round((this.currentIndex / this.selectedEmployees.length) * 100);
        },
        
        async init() {
            // Load fonts
            try {
                await FontLoader.load();
                this.fontsLoaded = true;
            } catch (e) {
                console.warn('Font loading error:', e);
                this.fontsLoaded = true;
            }
            
            await document.fonts.ready;
            
            // Get canvas dimensions from template settings
            const template = this.frontTemplate || this.backTemplate;
            const dims = this.getCanvasDimensions(template);
            
            // Initialize offscreen canvas with correct dimensions
            this.cardEditor = new CardEditor('renderCanvas', {
                width: dims.width,
                height: dims.height,
                backgroundColor: '#ffffff'
            });
            
            console.log('Canvas dimensions:', dims.width, 'x', dims.height);
            
            // Pre-select employee if specified
            if (this.preSelectedEmployeeId) {
                this.selectedEmployees = [this.preSelectedEmployeeId];
                // Check the checkbox
                const checkbox = document.querySelector(`input[value="${this.preSelectedEmployeeId}"]`);
                if (checkbox) checkbox.checked = true;
                
                // Auto-start generation if requested
                if (this.autoGenerate) {
                    setTimeout(() => this.startGeneration(), 500);
                }
            }
        },
        
        toggleEmployee(id, checked) {
            if (checked) {
                if (!this.selectedEmployees.includes(id)) {
                    this.selectedEmployees.push(id);
                }
            } else {
                this.selectedEmployees = this.selectedEmployees.filter(e => e !== id);
            }
        },
        
        toggleAll(checked) {
            if (checked) {
                this.selectedEmployees = this.employees.map(e => e.id);
                document.querySelectorAll('.employee-checkbox').forEach(cb => cb.checked = true);
            } else {
                this.selectedEmployees = [];
                document.querySelectorAll('.employee-checkbox').forEach(cb => cb.checked = false);
            }
        },
        
        async startGeneration() {
            if (this.selectedEmployees.length === 0 || !this.fontsLoaded) return;
            
            this.isGenerating = true;
            this.currentIndex = 0;
            this.completedCount = 0;
            this.generatedCards = [];
            
            // Extra wait for fonts
            await new Promise(r => setTimeout(r, 300));
            
            for (const empId of this.selectedEmployees) {
                const employee = this.employees.find(e => e.id === empId);
                if (!employee) continue;
                
                this.currentEmployee = employee.name_en || employee.email;
                
                try {
                    const result = await this.generateForEmployee(employee);
                    if (result) {
                        this.generatedCards.push({
                            employee: employee,
                            frontUrl: result.frontUrl,
                            backUrl: result.backUrl,
                            frontPdf: result.frontPdf,
                            backPdf: result.backPdf
                        });
                    }
                    this.completedCount++;
                } catch (error) {
                    console.error('Error generating for', employee.email, error);
                }
                
                this.currentIndex++;
            }
            
            this.isGenerating = false;
            this.isComplete = true;
            
            // Send emails if requested
            if (this.sendEmail && this.generatedCards.length > 0) {
                await this.sendCardEmails();
            }
        },
        
        async generateForEmployee(employee) {
            let frontUrl = null;
            let backUrl = null;
            let frontPdf = null;
            let backPdf = null;
            
            const vcfUrl = this.companySlug && employee.email 
                ? this.baseUrl + encodeURIComponent(this.companySlug) + '/' + encodeURIComponent(employee.email) + '.vcf'
                : null;
            
            // Check for department-specific templates
            let employeeFrontTemplate = this.frontTemplate;
            let employeeBackTemplate = this.backTemplate;
            
            if (employee.department_id && this.departmentTemplates[employee.department_id]) {
                const deptTemplates = this.departmentTemplates[employee.department_id];
                if (deptTemplates.front) {
                    employeeFrontTemplate = deptTemplates.front;
                    console.log(`Using department template for ${employee.name_en} - front`);
                }
                if (deptTemplates.back) {
                    employeeBackTemplate = deptTemplates.back;
                    console.log(`Using department template for ${employee.name_en} - back`);
                }
            }
            
            // Generate front card
            if (employeeFrontTemplate) {
                const result = await this.generateCard(employeeFrontTemplate, employee, 'front', vcfUrl);
                if (result) {
                    frontUrl = result.png;
                    frontPdf = result.pdf;
                }
            }
            
            // Generate back card
            if (employeeBackTemplate) {
                const result = await this.generateCard(employeeBackTemplate, employee, 'back', vcfUrl);
                if (result) {
                    backUrl = result.png;
                    backPdf = result.pdf;
                }
            }
            
            // Log generation
            await fetch(this.basePath + 'log_generation.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    employee_id: employee.id,
                    front_url: frontUrl,
                    back_url: backUrl
                })
            });
            
            return { frontUrl, backUrl, frontPdf, backPdf };
        },
        
        async generateCard(template, employee, side, vcfUrl) {
            if (!this.cardEditor) return null;
            
            // Clear canvas
            this.cardEditor.clear();
            
            // Load background
            const bgUrl = this.getBackgroundUrl(template);
            if (bgUrl) {
                try {
                    await this.cardEditor.loadBackground(bgUrl);
                } catch (e) {
                    console.warn('Background load error:', e);
                }
            }
            
            // Add text fields
            const fields = template.fields || {};
            const fieldValues = {
                'name_en': employee.name_en || '',
                'name_ar': employee.name_ar || '',
                'position_en': employee.position_en || '',
                'position_ar': employee.position_ar || '',
                'company_en': employee.company_en || '',
                'company_ar': employee.company_ar || '',
                'phone': employee.phone || '',
                'phone_ar': employee.phone_ar || '',
                'mobile': employee.mobile || '',
                'mobile_ar': employee.mobile_ar || '',
                'email': employee.email || '',
                'website': employee.website || '',
                'website_ar': employee.website_ar || '',
                'address': employee.address_en || employee.address || '',
                'address_en': employee.address_en || '',
                'address_ar': employee.address_ar || ''
            };
            
            for (const [key, field] of Object.entries(fields)) {
                if (key === 'qr_code') continue;
                if (!field.enabled) continue;
                
                const value = fieldValues[key];
                if (!value) continue;
                
                // Determine text alignment (matching template editor fallback logic)
                const textAlign = field.textAlign || (key.endsWith('_ar') ? 'right' : 'left');
                const originX = field.originX || (textAlign === 'center' ? 'center' : (textAlign === 'right' ? 'right' : 'left'));
                
                this.cardEditor.addTextField(key, {
                    text: value,
                    x: field.x,
                    y: field.y,
                    fontSize: field.fontSize,
                    fontFamily: field.fontFamily,
                    fontWeight: field.fontWeight || 'normal',
                    fill: field.fill || field.color || '#333333',
                    textAlign: textAlign,
                    originX: originX
                });
            }
            
            // Add QR code if enabled
            if (fields.qr_code && fields.qr_code.enabled && vcfUrl) {
                await this.cardEditor.addQRCode(vcfUrl, {
                    x: fields.qr_code.x,
                    y: fields.qr_code.y,
                    size: fields.qr_code.size
                });
            }
            
            // Wait for rendering
            await new Promise(r => setTimeout(r, 100));
            
            // Always generate both PNG and PDF (if PDF background available)
            const originalPdf = template.originalPdf;
            
            // Generate PNG with plan-based quality
            // Free plan: 1x multiplier (~100 DPI preview), Paid: 3x multiplier (~300 DPI)
            const exportQuality = this.hasHighQuality ? 3 : this.qualityMultiplier;
            const pngBlob = await this.cardEditor.exportPNGBlob(exportQuality);
            
            // Generate hybrid PDF if original PDF background exists
            // PDF uses 6x multiplier internally for print-quality output (~600 DPI)
            let pdfBlob = null;
            if (originalPdf && typeof PDFLib !== 'undefined') {
                console.log('Generating high-quality PDF (vector bg + 600 DPI text):', originalPdf);
                pdfBlob = await this.cardEditor.exportHybridPDFBlob(originalPdf);
            }
            
            // Save both files and return both URLs
            const saveResult = await this.saveCardBoth(pngBlob, pdfBlob, side, employee.id);
            return saveResult;
        },
        
        getBackgroundUrl(template) {
            if (!template || !template.backgroundImage) return '';
            const path = template.backgroundImage.replace(/^\//, '');
            return window.location.origin + this.basePath + path;
        },
        
        async saveCard(blob, side, employeeId, fileType = 'png') {
            const formData = new FormData();
            const ext = fileType === 'pdf' ? '.pdf' : '.png';
            formData.append('image', blob, side + ext);
            formData.append('side', side);
            formData.append('employee_id', employeeId);
            formData.append('file_type', fileType);
            
            try {
                const response = await fetch(this.basePath + 'save_card_image.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                return result.success ? result.url : null;
            } catch (e) {
                console.error('Save error:', e);
                return null;
            }
        },
        
        // Save both PNG and PDF (returns object with both URLs)
        async saveCardBoth(pngBlob, pdfBlob, side, employeeId) {
            const formData = new FormData();
            formData.append('png', pngBlob, side + '.png');
            if (pdfBlob) {
                formData.append('pdf', pdfBlob, side + '.pdf');
            }
            formData.append('side', side);
            formData.append('employee_id', employeeId);
            
            try {
                const response = await fetch(this.basePath + 'save_card_both.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                if (result.success) {
                    return {
                        png: result.png_url,
                        pdf: result.pdf_url || null
                    };
                }
                return null;
            } catch (e) {
                console.error('Save error:', e);
                return null;
            }
        },
        
        // Send card emails to employees with proof sheet
        // Sends to employee and optionally copies admin/department admin
        async sendCardEmails() {
            for (const card of this.generatedCards) {
                if (!card.employee.email) continue;
                
                try {
                    const response = await fetch(this.basePath + 'admin/send_card_email.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            employee_id: card.employee.id,
                            employee_email: card.employee.email,
                            employee_name: card.employee.name_en || card.employee.name_ar || card.employee.email,
                            position: card.employee.position_en || card.employee.position_ar || '',
                            front_png: card.frontUrl,
                            back_png: card.backUrl,
                            front_pdf: card.frontPdf,
                            back_pdf: card.backPdf,
                            // Send proof sheet with inline images
                            send_proof: true,
                            // Copy admin on card generated from request approval
                            cc_admin: this.sendEmail, // Only cc admin when auto-sending (from request approval)
                            cc_department_admin: this.sendEmail && card.employee.department_id
                        })
                    });
                    const result = await response.json();
                    if (result.success) {
                        console.log('Email sent to', card.employee.email, result.recipients || []);
                    } else {
                        console.error('Email failed for', card.employee.email, result.error);
                    }
                } catch (e) {
                    console.error('Email error:', e);
                }
            }
        },
        
        reset() {
            this.isComplete = false;
            this.currentIndex = 0;
            this.completedCount = 0;
            this.selectedEmployees = [];
            document.querySelectorAll('.employee-checkbox').forEach(cb => cb.checked = false);
        }
    };
}
</script>

<?php adminFooter(); ?>
