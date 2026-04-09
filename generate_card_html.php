<?php
/**
 * Generate Business Card using Fabric.js
 * Renders both front and back sides
 */
require_once __DIR__ . '/config.php';

// Multi-tenant: set company context from query param when provided
$companySlug = $_GET['company'] ?? null;
if ($companySlug && isMultiTenantEnabled()) {
    $company = findCompanyBySlug($companySlug);
    if ($company) {
        setCompanyContext($company);
    }
}

// Require a valid company context to prevent cross-company IDOR
if (!getCurrentCompanyId()) {
    http_response_code(400);
    die('Company context required. Please provide a valid company parameter.');
}

// Get employee data — always scoped to the current company
$employeeId = $_GET['id'] ?? '';
$employee = null;

if ($employeeId) {
    $employee = findEmployeeById($employeeId, getCurrentCompanyId());
}

if (!$employee) {
    header('Location: ' . getBasePath());
    exit;
}

// Get templates based on employee's department or company default
$employeeTemplates = getEmployeeTemplates($employee, getCurrentCompanyId());
$frontTemplate = $employeeTemplates['front'];
$backTemplate = $employeeTemplates['back'];
$templateSource = $employeeTemplates['source'] ?? 'company'; // 'department' or 'company'

// Convert legacy field positions
if ($frontTemplate && isset($frontTemplate['fields'])) {
    $frontTemplate['fields'] = convertLegacyFieldPositions($frontTemplate['fields']);
}
if ($backTemplate && isset($backTemplate['fields'])) {
    $backTemplate['fields'] = convertLegacyFieldPositions($backTemplate['fields']);
}

if (!$frontTemplate && !$backTemplate) {
    die('No active templates configured. Please contact administrator.');
}

// Get company for VCF URL
$currentCompany = null;
$companyId = getCurrentCompanyId();
if ($companyId) {
    $currentCompany = findCompanyById($companyId);
}

// Generate VCF URL for QR code — short format produces smaller, faster-scanning QR codes
require_once INCLUDES_DIR . '/VCF.php';
require_once INCLUDES_DIR . '/Billing.php';
$vcfUrl = '';
if ($currentCompany && $employee) {
    // Use short employee ID format: /qr.php?i={id} — minimal URL = smallest QR code
    $vcfUrl = (defined('APP_HOST') ? 'https://' . APP_HOST : 'https://cardify.om')
              . '/qr.php?i=' . urlencode($employee['id'] ?? '');
}

// Get quality multiplier based on plan (free users get low DPI)
$isPrintShop = isset($_GET['print_shop']) && $_GET['print_shop'] === 'true';
$qualityMultiplier = Billing::getQualityMultiplier($companyId, $isPrintShop);
$planInfo = Billing::getCompanyPlanInfo($companyId);
$isFreePlan = $planInfo['plan'] === 'free';

// Build absolute URLs for backgrounds
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
$basePath = getBasePath();

$frontBgUrl = $frontTemplate && $frontTemplate['backgroundImage'] 
    ? $baseUrl . $basePath . ltrim($frontTemplate['backgroundImage'], '/') 
    : '';
$backBgUrl = $backTemplate && $backTemplate['backgroundImage'] 
    ? $baseUrl . $basePath . ltrim($backTemplate['backgroundImage'], '/') 
    : '';

$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Business Card | <?php echo $brandName; ?></title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <!-- Arabic Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&family=Tajawal:wght@200;300;400;500;700;800;900&family=Almarai:wght@300;400;700;800&family=Noto+Kufi+Arabic:wght@400;500;600;700&family=IBM+Plex+Sans+Arabic:wght@100;200;300;400;500;600;700&family=Noto+Sans+Arabic:wght@400;500;600;700&family=Readex+Pro:wght@200;300;400;500;600;700&family=El+Messiri:wght@400;500;600;700&family=Changa:wght@200;300;400;500;600;700;800&family=Reem+Kufi:wght@400;500;600;700&family=Amiri:wght@400;700&family=Scheherazade+New:wght@400;500;600;700&family=Mada:wght@200;300;400;500;600;700;800;900&family=Lalezar&family=Lemonada:wght@300;400;500;600;700&family=Aref+Ruqaa:wght@400;700&family=Mirza:wght@400;500;600;700&family=Rakkas&family=Baloo+Bhaijaan+2:wght@400;500;600;700;800&family=Noto+Naskh+Arabic:wght@400;500;600;700&family=Noto+Nastaliq+Urdu:wght@400;500;600;700&family=Lateef:wght@200;300;400;500;600;700;800&family=Harmattan:wght@400;500;600;700&family=Markazi+Text:wght@400;500;600;700&family=Gulzar&display=swap" rel="stylesheet">
    <!-- English Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Montserrat:wght@400;500;600;700&family=Roboto:wght@400;500;700&family=Poppins:wght@400;500;600;700&family=Open+Sans:wght@400;600;700&family=Lato:wght@400;700&family=Nunito:wght@400;600;700&family=Raleway:wght@400;500;600;700&family=Work+Sans:wght@400;500;600;700&family=DM+Sans:wght@400;500;700&family=Outfit:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&family=Urbanist:wght@400;500;600;700&family=Lexend:wght@400;500;600;700&family=Sora:wght@400;500;600;700&family=Rubik:wght@400;500;600;700&family=Quicksand:wght@400;500;600;700&family=Ubuntu:wght@400;500;700&family=Barlow:wght@400;500;600;700&family=Jost:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Serif, Display, Handwriting, Monospace -->
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Merriweather:wght@400;700&family=Lora:wght@400;500;600;700&family=PT+Serif:wght@400;700&family=Libre+Baskerville:wght@400;700&family=EB+Garamond:wght@400;500;600;700&family=Cormorant+Garamond:wght@400;500;600;700&family=Spectral:wght@400;500;600;700&family=Noto+Serif:wght@400;700&family=Vollkorn:wght@400;500;600;700&family=Bodoni+Moda:wght@400;500;600;700&family=Bebas+Neue&family=Oswald:wght@400;500;600;700&family=Anton&family=Archivo+Black&family=Righteous&family=Teko:wght@400;500;600;700&family=Big+Shoulders+Display:wght@400;500;600;700;800&family=Fredoka:wght@400;500;600;700&family=Dancing+Script:wght@400;500;600;700&family=Pacifico&family=Great+Vibes&family=Sacramento&family=Allura&family=Lobster&family=Caveat:wght@400;500;600;700&family=Kaushan+Script&family=Roboto+Mono:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600;700&family=Fira+Code:wght@400;500;600;700&family=Source+Code+Pro:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    
    <!-- Tailwind CSS (Local) -->
    <?php $tailwindVersion = @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/techwind/css/tailwind.min.css') ?: time(); ?>
    <link rel="stylesheet" href="/assets/techwind/css/tailwind.min.css?v=<?php echo $tailwindVersion; ?>">
    
    <!-- Fabric.js 7.1.0 -->
    <script src="https://cdn.jsdelivr.net/npm/fabric@7.1.0/dist/index.min.js"></script>
    
    <!-- WebFontLoader -->
    <script src="https://ajax.googleapis.com/ajax/libs/webfont/1.6.26/webfont.js"></script>
    
    <!-- QR Code Generator -->
    <script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
    
    <!-- jsPDF -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    
    <!-- Card Editor -->
    <script src="<?php echo $basePath; ?>assets/js/font-loader.js"></script>
    <script src="<?php echo $basePath; ?>assets/js/card-editor.js?v=<?php echo time(); ?>"></script>
    
    <style>
        body { font-family: 'Inter', sans-serif; }
        .glass-card { background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(20px); }
        .loading-overlay { position: fixed; inset: 0; background: rgba(249, 250, 251, 0.95); display: flex; align-items: center; justify-content: center; z-index: 100; }
    </style>
</head>
<body class="bg-gray-50 text-gray-900 min-h-screen">
    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="loading-overlay">
        <div class="text-center">
            <div class="w-16 h-16 border-4 border-blue-200 border-t-blue-600 rounded-full animate-spin mx-auto mb-4"></div>
            <p class="text-gray-600">Generating your business cards...</p>
        </div>
    </div>
    
    <!-- Main Content -->
    <div id="mainContent" class="hidden min-h-screen py-12 px-4">
        <div class="max-w-4xl mx-auto">
            <!-- Header -->
            <div class="text-center mb-10">
                <h1 class="text-3xl font-bold text-gray-900 mb-2">Your Business Card is Ready</h1>
                <p class="text-gray-600">Preview your personalized business card below</p>
            </div>
            
            <!-- Cards Display -->
            <div class="space-y-8">
                <?php if ($frontTemplate): ?>
                <!-- Front Card -->
                <div class="glass-card rounded-2xl p-6 border border-gray-200 shadow-sm">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Front Side</h3>
                    <div class="flex justify-center">
                        <img id="frontCardImage" src="" alt="Front of Business Card" class="max-w-full rounded-xl shadow-lg" style="display: none;">
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($backTemplate): ?>
                <!-- Back Card -->
                <div class="glass-card rounded-2xl p-6 border border-gray-200 shadow-sm">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Back Side</h3>
                    <div class="flex justify-center">
                        <img id="backCardImage" src="" alt="Back of Business Card" class="max-w-full rounded-xl shadow-lg" style="display: none;">
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Action Buttons -->
            <div class="flex flex-wrap justify-center gap-4 mt-10">
                <?php if ($frontTemplate): ?>
                <a id="downloadFrontBtn" href="#" download="business_card_front.png" class="px-6 py-3 bg-blue-600 text-white rounded-xl flex items-center space-x-2 hover:bg-blue-700 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                    </svg>
                    <span>Download Front (PNG)</span>
                </a>
                <?php endif; ?>
                
                <?php if ($backTemplate): ?>
                <a id="downloadBackBtn" href="#" download="business_card_back.png" class="px-6 py-3 bg-purple-600 text-white rounded-xl flex items-center space-x-2 hover:bg-purple-700 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                    </svg>
                    <span>Download Back (PNG)</span>
                </a>
                <?php endif; ?>
                
                <button id="downloadPdfBtn" class="px-6 py-3 bg-red-600 text-white rounded-xl flex items-center space-x-2 hover:bg-red-700 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                    </svg>
                    <span>Download PDF</span>
                </button>
            </div>
            
            <!-- Back Link -->
            <div class="text-center mt-10">
                <a href="<?php echo $basePath; ?>" class="text-gray-500 hover:text-blue-600 transition-colors text-sm">
                    Generate another card
                </a>
            </div>
        </div>
    </div>
    
    <!-- Hidden canvas for rendering -->
    <canvas id="renderCanvas" style="display: none;"></canvas>
    
    <script>
        const config = {
            employeeId: '<?php echo $employeeId; ?>',
            basePath: '<?php echo $basePath; ?>',
            hasFront: <?php echo $frontTemplate ? 'true' : 'false'; ?>,
            hasBack: <?php echo $backTemplate ? 'true' : 'false'; ?>,
            vcfUrl: '<?php echo addslashes($vcfUrl); ?>',
            frontTemplate: <?php echo json_encode($frontTemplate, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
            backTemplate: <?php echo json_encode($backTemplate, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
            frontBgUrl: '<?php echo addslashes($frontBgUrl); ?>',
            backBgUrl: '<?php echo addslashes($backBgUrl); ?>',
            employee: <?php echo json_encode($employee, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
            // Quality settings based on plan
            qualityMultiplier: <?php echo $qualityMultiplier; ?>,
            isFreePlan: <?php echo $isFreePlan ? 'true' : 'false'; ?>,
            isPrintShop: <?php echo $isPrintShop ? 'true' : 'false'; ?>
        };
        
        let frontImageUrl = null;
        let backImageUrl = null;
        let cardEditor = null;
        
        async function generateCards() {
            try {
                // Load fonts
                await FontLoader.load();
                await document.fonts.ready;
                await new Promise(r => setTimeout(r, 300));
                
                // Initialize card editor
                cardEditor = new CardEditor('renderCanvas', {
                    width: 1050,
                    height: 600,
                    backgroundColor: '#ffffff'
                });
                
                // Generate front card
                if (config.hasFront && config.frontTemplate) {
                    frontImageUrl = await generateCard(config.frontTemplate, config.frontBgUrl, 'front');
                    if (frontImageUrl) {
                        document.getElementById('frontCardImage').src = frontImageUrl;
                        document.getElementById('frontCardImage').style.display = 'block';
                        document.getElementById('downloadFrontBtn').href = frontImageUrl;
                    }
                }
                
                // Generate back card
                if (config.hasBack && config.backTemplate) {
                    backImageUrl = await generateCard(config.backTemplate, config.backBgUrl, 'back');
                    if (backImageUrl) {
                        document.getElementById('backCardImage').src = backImageUrl;
                        document.getElementById('backCardImage').style.display = 'block';
                        document.getElementById('downloadBackBtn').href = backImageUrl;
                    }
                }
                
                // Log generation
                logGeneration();
                showContent();
                
            } catch (error) {
                console.error('Error generating cards:', error);
                document.getElementById('loadingOverlay').innerHTML = `
                    <div class="text-center">
                        <div class="text-red-600 mb-4">Error generating cards</div>
                        <a href="${config.basePath}" class="text-blue-600">Try again</a>
                    </div>
                `;
            }
        }
        
        async function generateCard(template, bgUrl, side) {
            if (!cardEditor) return null;
            
            // Clear canvas
            cardEditor.clear();
            
            // Load background
            if (bgUrl) {
                try {
                    await cardEditor.loadBackground(bgUrl);
                } catch (e) {
                    console.warn('Background load error:', e);
                }
            }
            
            // Add text fields
            const fields = template.fields || {};
            const emp = config.employee;
            const fieldValues = {
                'name_en': emp.name_en || '',
                'name_ar': emp.name_ar || '',
                'position_en': emp.position_en || '',
                'position_ar': emp.position_ar || '',
                'company_en': emp.company_en || '',
                'company_ar': emp.company_ar || '',
                'phone': emp.phone || '',
                'phone_ar': emp.phone_ar || '',
                'mobile': emp.mobile || '',
                'mobile_ar': emp.mobile_ar || '',
                'email': emp.email || '',
                'website': emp.website || '',
                'website_ar': emp.website_ar || '',
                'address': emp.address_en || emp.address || '',
                'address_en': emp.address_en || emp.address || '',
                'address_ar': emp.address_ar || ''
            };
            
            for (const [key, field] of Object.entries(fields)) {
                if (key === 'qr_code') continue;
                if (!field.enabled) continue;
                
                const value = fieldValues[key];
                if (!value) continue;
                
                // Determine text alignment based on field type
                const textAlign = field.textAlign || (key.endsWith('_ar') ? 'right' : 'left');
                const originX = field.originX || (textAlign === 'center' ? 'center' : (textAlign === 'right' ? 'right' : 'left'));
                
                cardEditor.addTextField(key, {
                    text: value,
                    x: field.x,
                    y: field.y,
                    fontSize: field.fontSize,
                    fontFamily: field.fontFamily,
                    fontWeight: field.fontWeight,
                    fill: field.fill || field.color,
                    textAlign: textAlign,
                    originX: originX
                });
            }
            
            // Add QR code if enabled
            if (fields.qr_code && fields.qr_code.enabled && config.vcfUrl) {
                await cardEditor.addQRCode(config.vcfUrl, {
                    x: fields.qr_code.x,
                    y: fields.qr_code.y,
                    size: fields.qr_code.size
                });
            }
            
            // Wait for rendering
            await new Promise(r => setTimeout(r, 100));
            
            // Export and save (quality multiplier based on plan)
            // Free users: 1x (~100 DPI), Paid users: 4x (~400 DPI)
            const blob = await cardEditor.exportPNGBlob(config.qualityMultiplier);
            return await saveCard(blob, side);
        }
        
        async function saveCard(blob, side) {
            const formData = new FormData();
            formData.append('image', blob, side + '.png');
            formData.append('side', side);
            formData.append('employee_id', config.employeeId);
            
            try {
                const response = await fetch(config.basePath + 'save_card_image.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                return result.success ? result.url : null;
            } catch (e) {
                console.error('Save error:', e);
                return null;
            }
        }
        
        function logGeneration() {
            fetch(config.basePath + 'log_generation.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    employee_id: config.employeeId,
                    front_url: frontImageUrl,
                    back_url: backImageUrl
                })
            }).then(function(r) {
                return r.json();
            }).then(function(data) {
                if (!data.success && data.limit_reached) {
                    var banner = document.createElement('div');
                    banner.className = 'fixed top-0 left-0 right-0 z-50 bg-amber-500 text-white text-center py-3 px-4 text-sm font-medium';
                    banner.innerHTML = (data.error || 'Monthly card limit reached.') + ' <a href="' + config.basePath + 'billing" class="underline font-bold">Upgrade your plan</a>';
                    document.body.prepend(banner);
                }
            }).catch(function() {});
        }
        
        function showContent() {
            document.getElementById('loadingOverlay').style.display = 'none';
            document.getElementById('mainContent').classList.remove('hidden');
        }
        
        // PDF download
        document.getElementById('downloadPdfBtn')?.addEventListener('click', function() {
            if (typeof jspdf === 'undefined' && typeof jsPDF === 'undefined') {
                alert('PDF library not loaded');
                return;
            }
            
            const { jsPDF } = window.jspdf || window;
            const pdf = new jsPDF({
                orientation: 'landscape',
                unit: 'mm',
                format: [89, 51]
            });
            
            let pageAdded = false;
            
            if (frontImageUrl) {
                pdf.addImage(frontImageUrl, 'PNG', 0, 0, 89, 51);
                pageAdded = true;
            }
            
            if (backImageUrl) {
                if (pageAdded) pdf.addPage([89, 51], 'landscape');
                pdf.addImage(backImageUrl, 'PNG', 0, 0, 89, 51);
            }
            
            const empName = config.employee?.name_en || 'business_card';
            pdf.save(empName.replace(/[^a-z0-9]/gi, '_') + '.pdf');
        });
        
        // Start generation when page loads
        window.addEventListener('load', generateCards);
    </script>
</body>
</html>
