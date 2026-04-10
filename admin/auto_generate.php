<?php
/**
 * Auto Generate Business Card - Cardify
 * Automatically generates a card for a single employee
 */
require_once __DIR__ . '/../config.php';
requireAdmin();
require_once INCLUDES_DIR . '/admin-layout.php';
require_once INCLUDES_DIR . '/Billing.php';

$companyId = getCurrentCompanyId();

// Get company plan info for quality settings
$planInfo = Billing::getCompanyPlanInfo($companyId);
$qualityMultiplier = $planInfo['quality_multiplier'];
$isFreePlan = $planInfo['plan'] === 'free' || empty($planInfo['plan']);
$hasHighQuality = $planInfo['features']['high_quality'] ?? false;
$employeeId = $_GET['employee_id'] ?? null;
$returnTo = $_GET['return'] ?? 'employees';
$isNew = isset($_GET['new']);
$isUpdated = isset($_GET['updated']);
$isRegenerate = isset($_GET['regenerate']);

if (!$employeeId) {
    header('Location: employees.php?error=no_employee');
    exit;
}

// Load employee
$employees = loadEmployees($companyId) ?: [];
$employee = null;
foreach ($employees as $emp) {
    if ($emp['id'] === $employeeId) {
        $employee = $emp;
        break;
    }
}

if (!$employee) {
    header('Location: employees.php?error=employee_not_found');
    exit;
}

// Load templates
$frontTemplate = getActiveFrontTemplate($companyId);
$backTemplate = getActiveBackTemplate($companyId);

// Check if department has custom templates
if (!empty($employee['department_id'])) {
    $db = Database::getInstance();
    try {
        $dept = $db->fetchOne(
            "SELECT template_pair_id, front_template_id, back_template_id FROM departments WHERE id = :id",
            ['id' => $employee['department_id']]
        );
        if ($dept) {
            // Method 1: Use template_pair_id (paired templates - preferred)
            if (!empty($dept['template_pair_id'])) {
                // Get front template from pair
                $customFront = $db->fetchOne(
                    "SELECT * FROM templates WHERE pair_id = :pair_id AND side = 'front'",
                    ['pair_id' => $dept['template_pair_id']]
                );
                if ($customFront) {
                    $frontTemplate = [
                        'id' => $customFront['id'],
                        'name' => $customFront['name'],
                        'backgroundImage' => $customFront['background_image_path'],
                        'originalPdf' => $customFront['original_pdf_path'] ?? null,
                        'fields' => json_decode($customFront['fields_json'] ?? '{}', true),
                        'settings' => json_decode($customFront['settings_json'] ?? '{}', true)
                    ];
                }
                
                // Get back template from pair
                $customBack = $db->fetchOne(
                    "SELECT * FROM templates WHERE pair_id = :pair_id AND side = 'back'",
                    ['pair_id' => $dept['template_pair_id']]
                );
                if ($customBack) {
                    $backTemplate = [
                        'id' => $customBack['id'],
                        'name' => $customBack['name'],
                        'backgroundImage' => $customBack['background_image_path'],
                        'originalPdf' => $customBack['original_pdf_path'] ?? null,
                        'fields' => json_decode($customBack['fields_json'] ?? '{}', true),
                        'settings' => json_decode($customBack['settings_json'] ?? '{}', true)
                    ];
                }
            }
            // Method 2: Use individual front_template_id and back_template_id (fallback)
            else {
                if (!empty($dept['front_template_id'])) {
                    $customFront = $db->fetchOne(
                        "SELECT * FROM templates WHERE id = :id",
                        ['id' => $dept['front_template_id']]
                    );
                    if ($customFront) {
                        $frontTemplate = [
                            'id' => $customFront['id'],
                            'name' => $customFront['name'],
                            'backgroundImage' => $customFront['background_image_path'],
                            'originalPdf' => $customFront['original_pdf_path'] ?? null,
                            'fields' => json_decode($customFront['fields_json'] ?? '{}', true),
                            'settings' => json_decode($customFront['settings_json'] ?? '{}', true)
                        ];
                    }
                }
                if (!empty($dept['back_template_id'])) {
                    $customBack = $db->fetchOne(
                        "SELECT * FROM templates WHERE id = :id",
                        ['id' => $dept['back_template_id']]
                    );
                    if ($customBack) {
                        $backTemplate = [
                            'id' => $customBack['id'],
                            'name' => $customBack['name'],
                            'backgroundImage' => $customBack['background_image_path'],
                            'originalPdf' => $customBack['original_pdf_path'] ?? null,
                            'fields' => json_decode($customBack['fields_json'] ?? '{}', true),
                            'settings' => json_decode($customBack['settings_json'] ?? '{}', true)
                        ];
                    }
                }
            }
        }
    } catch (Exception $e) {
        // Use company default templates
    }
}

// Convert legacy field positions
if ($frontTemplate && isset($frontTemplate['fields'])) {
    $frontTemplate['fields'] = convertLegacyFieldPositions($frontTemplate['fields']);
}
if ($backTemplate && isset($backTemplate['fields'])) {
    $backTemplate['fields'] = convertLegacyFieldPositions($backTemplate['fields']);
}

$hasTemplates = $frontTemplate || $backTemplate;
$companySlug = getCurrentCompanySlug() ?? '';
$baseUrl = getBaseUrl();
$basePath = getBasePath();

// Get company info
$company = findCompanyById($companyId);
$companyName = $company['name_en'] ?? $company['name'] ?? 'Company';

// Include VCF for QR code
if (file_exists(INCLUDES_DIR . '/VCF.php')) {
    require_once INCLUDES_DIR . '/VCF.php';
}

// Get VCF URL (requires employee array and company array)
$vcfUrl = '';
if (class_exists('VCF') && $employee && $company) {
    $vcfUrl = VCF::getUrl($employee, $company);
}

adminHeader('Generating Card', 'employees');
?>

<?php if ($isFreePlan): ?>
<!-- Free Plan Quality Notice -->
<div class="max-w-lg mx-auto mb-4">
    <div class="bg-amber-50 border border-amber-200 rounded-xl p-4">
        <div class="flex items-start gap-3">
            <i class="fa-solid fa-info-circle text-amber-500 text-xl mt-0.5"></i>
            <div class="flex-1">
                <h4 class="font-semibold text-amber-800">Preview Quality</h4>
                <p class="text-sm text-amber-700 mt-1">
                    You're on the <strong>Free Plan</strong>. Card previews are generated at lower resolution. 
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
</div>
<?php endif; ?>

<div class="max-w-lg mx-auto" x-data="autoGenerator()" x-init="init()">
    <!-- Generating State -->
    <div x-show="status === 'generating'" class="bg-white rounded-2xl border border-gray-200 shadow-sm p-8 text-center">
        <div class="w-16 h-16 mx-auto bg-blue-100 rounded-full flex items-center justify-center mb-4">
            <i class="fa-solid fa-wand-magic-sparkles text-2xl text-blue-600 animate-pulse"></i>
        </div>
        <h2 class="text-xl font-bold text-gray-900 mb-2">
            <?php echo $isRegenerate ? 'Regenerating' : 'Generating'; ?> Business Card
        </h2>
        <p class="text-gray-600 mb-4">
            Creating card for <strong><?php echo sanitize($employee['name_en'] ?? $employee['email']); ?></strong>
        </p>
        <div class="flex items-center justify-center gap-2">
            <div class="w-2 h-2 bg-blue-500 rounded-full animate-bounce"></div>
            <div class="w-2 h-2 bg-blue-500 rounded-full animate-bounce" style="animation-delay: 0.1s"></div>
            <div class="w-2 h-2 bg-blue-500 rounded-full animate-bounce" style="animation-delay: 0.2s"></div>
        </div>
        <p class="text-sm text-gray-500 mt-4" x-text="statusMessage"></p>
    </div>
    
    <!-- Success State -->
    <div x-show="status === 'success'" x-cloak class="bg-white rounded-2xl border border-gray-200 shadow-sm p-8 text-center">
        <div class="w-16 h-16 mx-auto bg-green-100 rounded-full flex items-center justify-center mb-4">
            <i class="fa-solid fa-check text-2xl text-green-600"></i>
        </div>
        <h2 class="text-xl font-bold text-gray-900 mb-2">Card Generated!</h2>
        <p class="text-gray-500 text-sm mb-6">Your digital business card is live and ready to share.</p>

        <!-- Shareable Link -->
        <div class="bg-gray-50 border border-gray-200 rounded-xl p-4 mb-6 text-left">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Your Digital Card Link</p>
            <div class="flex items-center gap-2">
                <input type="text" id="card-share-url" readonly
                       :value="cardShareUrl"
                       class="flex-1 px-3 py-2 bg-white border border-gray-200 rounded-lg text-sm text-gray-700 font-mono truncate">
                <button @click="copyCardUrl()"
                        class="flex-shrink-0 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition-colors flex items-center gap-1.5"
                        :class="copied ? 'bg-green-600 hover:bg-green-600' : ''">
                    <i :class="copied ? 'fa-solid fa-check' : 'fa-solid fa-copy'"></i>
                    <span x-text="copied ? 'Copied!' : 'Copy'"></span>
                </button>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex flex-col sm:flex-row gap-3 justify-center mb-6">
            <a :href="cardShareUrl" target="_blank"
               class="inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-medium transition-colors">
                <i class="fa-solid fa-eye"></i>
                View Card
            </a>
            <a :href="waShareUrl" target="_blank"
               class="inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-green-500 hover:bg-green-600 text-white rounded-xl font-medium transition-colors">
                <i class="fa-brands fa-whatsapp"></i>
                Share on WhatsApp
            </a>
            <a :href="continueUrl"
               class="inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-xl font-medium transition-colors">
                <i class="fa-solid fa-arrow-right"></i>
                Continue
            </a>
        </div>

        <p class="text-xs text-gray-400">Redirecting automatically in <span x-text="redirectCountdown"></span>s&hellip;
            <button @click="cancelRedirect()" class="text-blue-500 hover:underline ml-1">Stay here</button>
        </p>
    </div>
    
    <!-- Error State -->
    <div x-show="status === 'error'" x-cloak class="bg-white rounded-2xl border border-gray-200 shadow-sm p-8 text-center">
        <div class="w-16 h-16 mx-auto bg-red-100 rounded-full flex items-center justify-center mb-4">
            <i class="fa-solid fa-exclamation-triangle text-2xl text-red-600"></i>
        </div>
        <h2 class="text-xl font-bold text-gray-900 mb-2">Generation Failed</h2>
        <p class="text-gray-600 mb-4" x-text="errorMessage"></p>
        <a href="employees.php" class="inline-block px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors">
            <i class="fa-solid fa-arrow-left mr-2"></i>Back to Employees
        </a>
    </div>
</div>

<!-- Hidden canvas for generation -->
<div class="hidden">
    <canvas id="cardCanvas" style="display: none;"></canvas>
</div>

<script>
function autoGenerator() {
    return {
        status: 'generating',
        statusMessage: 'Initializing...',
        errorMessage: 'An error occurred',
        
        // Data from PHP
        employee: <?php echo json_encode($employee, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        frontTemplate: <?php echo json_encode($frontTemplate, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        backTemplate: <?php echo json_encode($backTemplate, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        companyId: <?php echo json_encode($companyId); ?>,
        companySlug: <?php echo json_encode($companySlug); ?>,
        basePath: <?php echo json_encode(rtrim($basePath, '/')); ?>,
        baseUrl: <?php echo json_encode(rtrim($baseUrl, '/')); ?>,
        vcfUrl: <?php echo json_encode($vcfUrl); ?>,
        returnTo: <?php echo json_encode($returnTo); ?>,
        isRegenerate: <?php echo $isRegenerate ? 'true' : 'false'; ?>,
        // Plan-based quality settings
        qualityMultiplier: <?php echo (int)$qualityMultiplier; ?>,
        isFreePlan: <?php echo $isFreePlan ? 'true' : 'false'; ?>,
        hasHighQuality: <?php echo $hasHighQuality ? 'true' : 'false'; ?>,
        editor: null,
        copied: false,
        redirectCountdown: 8,
        redirectTimer: null,
        countdownTimer: null,
        get cardShareUrl() {
            return this.baseUrl + '/' + this.companySlug + '/card/' + this.employee.id;
        },
        get waShareUrl() {
            const msg = encodeURIComponent('Here is my digital business card: ' + this.cardShareUrl);
            return 'https://wa.me/?text=' + msg;
        },
        get continueUrl() {
            const param = this.isRegenerate ? 'regenerated' : 'generated';
            return this.returnTo + '.php?' + param + '=1';
        },
        copyCardUrl() {
            navigator.clipboard.writeText(this.cardShareUrl).then(() => {
                this.copied = true;
                setTimeout(() => { this.copied = false; }, 2000);
            });
        },
        cancelRedirect() {
            clearTimeout(this.redirectTimer);
            clearInterval(this.countdownTimer);
            this.redirectCountdown = '∞';
        },
        
        async init() {
            // Wait for CardEditor to be available
            await this.waitForCardEditor();
            
            // Start generation
            await this.generateCard();
        },
        
        waitForCardEditor() {
            return new Promise((resolve, reject) => {
                let attempts = 0;
                const check = () => {
                    if (typeof CardEditor !== 'undefined') {
                        resolve();
                    } else if (attempts++ > 50) {
                        reject(new Error('CardEditor failed to load'));
                    } else {
                        setTimeout(check, 100);
                    }
                };
                check();
            });
        },
        
        getBackgroundUrl(template) {
            if (!template || !template.backgroundImage) return '';
            // Remove leading slash from path
            const path = template.backgroundImage.replace(/^\//, '');
            // Use baseUrl (full site URL) to construct absolute URL
            // Background images are always at site root, not in admin folder
            return this.baseUrl + '/' + path;
        },
        
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
        
        async generateCard() {
            try {
                if (!this.frontTemplate && !this.backTemplate) {
                    throw new Error('No active templates configured. Please set up templates first.');
                }
                
                this.statusMessage = 'Initializing card editor...';
                
                // Get canvas dimensions from template settings
                const template = this.frontTemplate || this.backTemplate;
                const dims = this.getCanvasDimensions(template);
                
                // Initialize editor with correct dimensions
                this.editor = new CardEditor('cardCanvas', {
                    width: dims.width,
                    height: dims.height,
                    backgroundColor: '#ffffff'
                });
                
                console.log('Canvas dimensions:', dims.width, 'x', dims.height);
                console.log('Template settings:', template?.settings);
                console.log('Front template fields:', this.frontTemplate?.fields);
                
                // Wait a moment for canvas to initialize
                await new Promise(resolve => setTimeout(resolve, 100));
                
                let frontBlob = null;
                let backBlob = null;
                
                // Generate front card
                if (this.frontTemplate) {
                    this.statusMessage = 'Generating front card...';
                    
                    // Clear canvas
                    this.editor.clear();
                    
                    // Load background
                    const bgUrl = this.getBackgroundUrl(this.frontTemplate);
                    if (bgUrl) {
                        try {
                            console.log('Loading front background:', bgUrl);
                            await this.editor.loadBackground(bgUrl);
                        } catch (e) {
                            console.warn('Background load error:', e);
                        }
                    }
                    
                    // Add text fields
                    await this.addFieldsToCard(this.editor, this.frontTemplate.fields || {});
                    
                    // Add QR code if enabled
                    const frontFields = this.frontTemplate.fields || {};
                    if (frontFields.qr_code && frontFields.qr_code.enabled && this.vcfUrl) {
                        await this.editor.addQRCode(this.vcfUrl, {
                            x: frontFields.qr_code.x,
                            y: frontFields.qr_code.y,
                            size: frontFields.qr_code.size
                        });
                    }
                    
                    // Wait for rendering
                    await new Promise(r => setTimeout(r, 100));
                    
                    // Export PNG with plan-based quality
                    // Free plan: 1x multiplier (~100 DPI), Paid: 3-4x multiplier (~300-400 DPI)
                    const exportQuality = this.hasHighQuality ? 3 : this.qualityMultiplier;
                    frontBlob = await this.editor.exportPNGBlob(exportQuality);
                }
                
                // Generate back card
                if (this.backTemplate) {
                    this.statusMessage = 'Generating back card...';
                    
                    // Clear canvas
                    this.editor.clear();
                    
                    // Load background
                    const bgUrl = this.getBackgroundUrl(this.backTemplate);
                    if (bgUrl) {
                        try {
                            console.log('Loading back background:', bgUrl);
                            await this.editor.loadBackground(bgUrl);
                        } catch (e) {
                            console.warn('Background load error:', e);
                        }
                    }
                    
                    // Add text fields
                    await this.addFieldsToCard(this.editor, this.backTemplate.fields || {});
                    
                    // Add QR code if enabled
                    const backFields = this.backTemplate.fields || {};
                    if (backFields.qr_code && backFields.qr_code.enabled && this.vcfUrl) {
                        await this.editor.addQRCode(this.vcfUrl, {
                            x: backFields.qr_code.x,
                            y: backFields.qr_code.y,
                            size: backFields.qr_code.size
                        });
                    }
                    
                    // Wait for rendering
                    await new Promise(r => setTimeout(r, 100));
                    
                    // Export PNG with plan-based quality
                    const backExportQuality = this.hasHighQuality ? 3 : this.qualityMultiplier;
                    backBlob = await this.editor.exportPNGBlob(backExportQuality);
                }
                
                // Save the cards
                this.statusMessage = 'Saving cards...';
                
                if (!frontBlob && !backBlob) {
                    throw new Error('No cards were generated');
                }
                
                let frontFile = null;
                let backFile = null;
                
                // Save front card
                if (frontBlob) {
                    this.statusMessage = 'Saving front card...';
                    const frontFormData = new FormData();
                    frontFormData.append('png', frontBlob, 'front.png');
                    frontFormData.append('side', 'front');
                    frontFormData.append('employee_id', this.employee.id);
                    
                    const frontResponse = await fetch(this.baseUrl + '/save_card_both.php', {
                        method: 'POST',
                        body: frontFormData
                    });
                    
                    const frontResult = await frontResponse.json();
                    if (!frontResult.success) {
                        throw new Error(frontResult.error || 'Failed to save front card');
                    }
                    frontFile = frontResult.png_filename;
                }
                
                // Save back card
                if (backBlob) {
                    this.statusMessage = 'Saving back card...';
                    const backFormData = new FormData();
                    backFormData.append('png', backBlob, 'back.png');
                    backFormData.append('side', 'back');
                    backFormData.append('employee_id', this.employee.id);
                    
                    const backResponse = await fetch(this.baseUrl + '/save_card_both.php', {
                        method: 'POST',
                        body: backFormData
                    });
                    
                    const backResult = await backResponse.json();
                    if (!backResult.success) {
                        throw new Error(backResult.error || 'Failed to save back card');
                    }
                    backFile = backResult.png_filename;
                }
                
                // Log the generation
                this.statusMessage = 'Logging generation...';
                const logResponse = await fetch(this.baseUrl + '/log_generation.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        employee_id: this.employee.id,
                        front_url: frontFile,
                        back_url: backFile
                    })
                });
                
                const logResult = await logResponse.json();
                console.log('Log result:', logResult);

                if (!logResult.success && logResult.limit_reached) {
                    this.status = 'error';
                    this.errorMessage = logResult.error || 'Monthly card limit reached. Please upgrade your plan.';
                    return;
                }

                if (!logResult.success) {
                    console.warn('Failed to log generation:', logResult.error);
                }

                this.status = 'success';

                // Countdown + redirect
                this.redirectCountdown = 8;
                this.countdownTimer = setInterval(() => {
                    if (typeof this.redirectCountdown === 'number') {
                        this.redirectCountdown--;
                    }
                }, 1000);
                this.redirectTimer = setTimeout(() => {
                    window.location.href = this.continueUrl;
                }, 8000);
                
            } catch (error) {
                console.error('Generation error:', error);
                this.status = 'error';
                this.errorMessage = error.message;
            }
        },
        
        async addFieldsToCard(editor, fields) {
            // Map field keys to employee data values (matching batch_generate.php exactly)
            const fieldValues = {
                'name_en': this.employee.name_en || '',
                'name_ar': this.employee.name_ar || '',
                'position_en': this.employee.position_en || '',
                'position_ar': this.employee.position_ar || '',
                'company_en': this.employee.company_en || '',
                'company_ar': this.employee.company_ar || '',
                'phone': this.employee.phone || '',
                'phone_ar': this.employee.phone_ar || '',
                'mobile': this.employee.mobile || '',
                'mobile_ar': this.employee.mobile_ar || '',
                'email': this.employee.email || '',
                'website': this.employee.website || '',
                'website_ar': this.employee.website_ar || '',
                'address': this.employee.address_en || this.employee.address || '',
                'address_en': this.employee.address_en || '',
                'address_ar': this.employee.address_ar || ''
            };
            
            for (const [key, field] of Object.entries(fields)) {
                // Skip QR code - handled separately
                if (key === 'qr_code') continue;
                
                // Skip if field is disabled
                if (!field.enabled) continue;
                
                // Get the value for this field
                const value = fieldValues[key];
                if (!value) continue;
                
                // Determine text alignment (matching template editor fallback logic)
                const textAlign = field.textAlign || (key.endsWith('_ar') ? 'right' : 'left');
                const originX = field.originX || (textAlign === 'center' ? 'center' : (textAlign === 'right' ? 'right' : 'left'));
                
                // Debug logging
                console.log(`Field ${key}: x=${field.x}, y=${field.y}, textAlign=${textAlign}, originX=${originX}`);
                
                // Add text field with alignment from template
                editor.addTextField(key, {
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
        }
    };
}
</script>

<?php adminFooter(); ?>
