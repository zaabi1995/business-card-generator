<?php
/**
 * Generate Business Card using HTML2Canvas
 * Renders both front and back sides
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/GoogleFonts.php';

// Multi-tenant: set company context from query param when provided
$companySlug = $_GET['company'] ?? null;
if ($companySlug && isMultiTenantEnabled()) {
    $company = findCompanyBySlug($companySlug);
    if ($company) {
        setCompanyContext($company);
    }
}

// Get employee data
$employeeId = $_GET['id'] ?? '';
$employee = null;

if ($employeeId) {
    $employee = findEmployeeById($employeeId, getCurrentCompanyId());
}

if (!$employee) {
    header('Location: ' . getBasePath());
    exit;
}

// Get active templates
$frontTemplate = getActiveFrontTemplate(getCurrentCompanyId());
$backTemplate = getActiveBackTemplate(getCurrentCompanyId());

if (!$frontTemplate && !$backTemplate) {
    die('No active templates configured. Please contact administrator.');
}

// Get background image dimensions
function getImageDimensions($imagePath) {
    $filePath = getFilePath($imagePath);
    if (file_exists($filePath)) {
        $size = getimagesize($filePath);
        if ($size) {
            return ['width' => $size[0], 'height' => $size[1]];
        }
    }
    return ['width' => 1050, 'height' => 600]; // Default business card ratio
}

$frontDimensions = $frontTemplate ? getImageDimensions($frontTemplate['backgroundImage']) : ['width' => 1050, 'height' => 600];
$backDimensions = $backTemplate ? getImageDimensions($backTemplate['backgroundImage']) : ['width' => 1050, 'height' => 600];

// Build absolute URLs for backgrounds
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
$frontBgUrl = $frontTemplate ? $baseUrl . imageUrl($frontTemplate['backgroundImage']) : '';
$backBgUrl = $backTemplate ? $baseUrl . imageUrl($backTemplate['backgroundImage']) : '';

// Font size multiplier for generation (preview to full size)
$fontMultiplier = 1.7;

// Collect all unique fonts
$fonts = [];
if ($frontTemplate && isset($frontTemplate['fields'])) {
    foreach ($frontTemplate['fields'] as $field) {
        if (!empty($field['fontFamily'])) {
            $fonts[] = $field['fontFamily'];
        }
    }
}
if ($backTemplate && isset($backTemplate['fields'])) {
    foreach ($backTemplate['fields'] as $field) {
        if (!empty($field['fontFamily'])) {
            $fonts[] = $field['fontFamily'];
        }
    }
}
$fonts = array_unique($fonts);

// Parse font names for Google Fonts URL
$googleFonts = [];
foreach ($fonts as $font) {
    if (preg_match("/['\"]?([^'\"]+)['\"]?/", $font, $matches)) {
        $fontName = trim($matches[1]);
        if (!in_array($fontName, ['sans-serif', 'serif', 'monospace'])) {
            $googleFonts[] = str_replace(' ', '+', $fontName) . ':wght@400;500;600;700';
        }
    }
}
$googleFontsUrl = !empty($googleFonts) ? 'https://fonts.googleapis.com/css2?family=' . implode('&family=', $googleFonts) . '&display=swap' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Business Card | <?php echo SITE_NAME; ?></title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <?php if ($googleFontsUrl): ?>
    <link href="<?php echo $googleFontsUrl; ?>" rel="stylesheet">
    <?php endif; ?>
    
    <link rel="stylesheet" href="<?php echo assetUrl('css/tailwind.css'); ?>?v=<?php echo filemtime(ASSETS_DIR . '/css/tailwind.css'); ?>">
    
    <script src="https://html2canvas.hertzen.com/dist/html2canvas.min.js"></script>
    
    <style>
        .glass-card { background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.1); }
        .btn-bhd { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%); transition: all 0.3s ease; border: 1px solid rgba(212, 175, 55, 0.3); }
        .btn-bhd:hover { box-shadow: 0 0 20px rgba(212, 175, 55, 0.2); border-color: rgba(212, 175, 55, 0.5); }
        .card-container { position: relative; overflow: hidden; }
        .card-field { position: absolute; transform: translate(-50%, -50%); white-space: nowrap; }
        .loading-overlay { position: fixed; inset: 0; background: rgba(10, 22, 40, 0.95); display: flex; align-items: center; justify-content: center; z-index: 100; }
        .ambient-bg { position: fixed; inset: 0; pointer-events: none; background: radial-gradient(ellipse at 20% 30%, rgba(212, 175, 55, 0.04) 0%, transparent 50%), radial-gradient(ellipse at 80% 70%, rgba(15, 52, 96, 0.06) 0%, transparent 50%); }
    </style>
</head>
<body class="bg-alzayani-dark text-white font-sans min-h-screen antialiased">
    <div class="ambient-bg"></div>
    
    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="loading-overlay">
        <div class="text-center">
            <div class="w-16 h-16 border-4 border-amber-500/30 border-t-amber-500 rounded-full animate-spin mx-auto mb-4"></div>
            <p class="text-gray-400">Generating your business cards...</p>
        </div>
    </div>
    
    <!-- Main Content -->
    <div id="mainContent" class="hidden min-h-screen py-12 px-4">
        <div class="max-w-4xl mx-auto">
            <!-- Header -->
            <div class="text-center mb-10">
                <h1 class="text-3xl font-bold text-white mb-2">Your Business Card is Ready</h1>
                <p class="text-gray-400">Preview your personalized business card below</p>
            </div>
            
            <!-- Cards Display -->
            <div class="space-y-8">
                <?php if ($frontTemplate): ?>
                <!-- Front Card -->
                <div class="glass-card rounded-2xl p-6">
                    <h3 class="text-lg font-semibold text-white mb-4">Front Side</h3>
                    <div class="flex justify-center">
                        <img id="frontCardImage" src="" alt="Front of Business Card" class="max-w-full rounded-xl shadow-2xl" style="display: none;">
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($backTemplate): ?>
                <!-- Back Card -->
                <div class="glass-card rounded-2xl p-6">
                    <h3 class="text-lg font-semibold text-white mb-4">Back Side</h3>
                    <div class="flex justify-center">
                        <img id="backCardImage" src="" alt="Back of Business Card" class="max-w-full rounded-xl shadow-2xl" style="display: none;">
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Action Buttons -->
            <div class="flex flex-wrap justify-center gap-4 mt-10">
                <?php if ($frontTemplate): ?>
                <a id="downloadFrontBtn" href="#" download="business_card_front.png" class="btn-bhd px-6 py-3 rounded-xl flex items-center space-x-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                    </svg>
                    <span>Download Front (PNG)</span>
                </a>
                <?php endif; ?>
                
                <?php if ($backTemplate): ?>
                <a id="downloadBackBtn" href="#" download="business_card_back.png" class="btn-bhd px-6 py-3 rounded-xl flex items-center space-x-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                    </svg>
                    <span>Download Back (PNG)</span>
                </a>
                <?php endif; ?>
                
                <a id="downloadPdfBtn" href="#" class="px-6 py-3 bg-green-500/20 text-green-400 rounded-xl hover:bg-green-500/30 transition-colors flex items-center space-x-2" style="display: none;">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                    </svg>
                    <span>Download PDF</span>
                </a>
            </div>
            
            <!-- Back Link -->
            <div class="text-center mt-10">
                <a href="<?php echo getBasePath(); ?>" class="text-gray-500 hover:text-amber-400 transition-colors text-sm">
                    Generate another card
                </a>
            </div>
        </div>
    </div>
    
    <!-- Hidden rendering containers -->
    <div id="renderContainer" style="position: fixed; left: -9999px; top: 0;">
        <?php if ($frontTemplate): ?>
        <!-- Front Card Render -->
        <div id="frontCardRender" class="card-container" style="width: <?php echo $frontDimensions['width']; ?>px; height: <?php echo $frontDimensions['height']; ?>px; background-image: url('<?php echo $frontBgUrl; ?>'); background-size: cover; background-position: center;">
            <?php
            $fields = $frontTemplate['fields'] ?? [];
            foreach ($fields as $key => $field):
                if (empty($field['enabled']) || $key === 'qr_code') continue;
                
                $value = '';
                switch ($key) {
                    case 'name_en': $value = $employee['name_en'] ?? ''; break;
                    case 'name_ar': $value = $employee['name_ar'] ?? ''; break;
                    case 'position_en': $value = $employee['position_en'] ?? ''; break;
                    case 'position_ar': $value = $employee['position_ar'] ?? ''; break;
                    case 'phone': $value = $employee['phone'] ?? ''; break;
                    case 'mobile': $value = $employee['mobile'] ?? ''; break;
                    case 'email': $value = $employee['email'] ?? ''; break;
                    case 'company_en': $value = $employee['company_en'] ?? ''; break;
                    case 'company_ar': $value = $employee['company_ar'] ?? ''; break;
                    case 'website': $value = $employee['website'] ?? ''; break;
                    case 'address': $value = $employee['address'] ?? ''; break;
                }
                
                if (empty($value)) continue;
                
                $isArabic = strpos($key, '_ar') !== false;
                $fontSize = ($field['fontSize'] ?? 16) * $fontMultiplier;
            ?>
            <div class="card-field" style="
                left: <?php echo $field['x'] ?? 50; ?>%;
                top: <?php echo $field['y'] ?? 50; ?>%;
                font-size: <?php echo $fontSize; ?>px;
                font-family: <?php echo $field['fontFamily'] ?? "'Plus Jakarta Sans', sans-serif"; ?>;
                font-weight: <?php echo $field['fontWeight'] ?? 'normal'; ?>;
                color: <?php echo $field['color'] ?? '#ffffff'; ?>;
                direction: <?php echo $isArabic ? 'rtl' : 'ltr'; ?>;
            "><?php echo htmlspecialchars($value); ?></div>
            <?php endforeach; ?>
            
            <?php if (!empty($fields['qr_code']['enabled'])): ?>
            <div class="card-field" style="
                left: <?php echo $fields['qr_code']['x'] ?? 85; ?>%;
                top: <?php echo $fields['qr_code']['y'] ?? 50; ?>%;
                width: <?php echo ($fields['qr_code']['size'] ?? 80) * $fontMultiplier; ?>px;
                height: <?php echo ($fields['qr_code']['size'] ?? 80) * $fontMultiplier; ?>px;
            ">
                <img id="frontQrCode" src="" alt="QR Code" style="width: 100%; height: 100%;">
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <?php if ($backTemplate): ?>
        <!-- Back Card Render -->
        <div id="backCardRender" class="card-container" style="width: <?php echo $backDimensions['width']; ?>px; height: <?php echo $backDimensions['height']; ?>px; background-image: url('<?php echo $backBgUrl; ?>'); background-size: cover; background-position: center;">
            <?php
            $fields = $backTemplate['fields'] ?? [];
            foreach ($fields as $key => $field):
                if (empty($field['enabled']) || $key === 'qr_code') continue;
                
                $value = '';
                switch ($key) {
                    case 'name_en': $value = $employee['name_en'] ?? ''; break;
                    case 'name_ar': $value = $employee['name_ar'] ?? ''; break;
                    case 'position_en': $value = $employee['position_en'] ?? ''; break;
                    case 'position_ar': $value = $employee['position_ar'] ?? ''; break;
                    case 'phone': $value = $employee['phone'] ?? ''; break;
                    case 'mobile': $value = $employee['mobile'] ?? ''; break;
                    case 'email': $value = $employee['email'] ?? ''; break;
                    case 'company_en': $value = $employee['company_en'] ?? ''; break;
                    case 'company_ar': $value = $employee['company_ar'] ?? ''; break;
                    case 'website': $value = $employee['website'] ?? ''; break;
                    case 'address': $value = $employee['address'] ?? ''; break;
                }
                
                if (empty($value)) continue;
                
                $isArabic = strpos($key, '_ar') !== false;
                $fontSize = ($field['fontSize'] ?? 16) * $fontMultiplier;
            ?>
            <div class="card-field" style="
                left: <?php echo $field['x'] ?? 50; ?>%;
                top: <?php echo $field['y'] ?? 50; ?>%;
                font-size: <?php echo $fontSize; ?>px;
                font-family: <?php echo $field['fontFamily'] ?? "'Plus Jakarta Sans', sans-serif"; ?>;
                font-weight: <?php echo $field['fontWeight'] ?? 'normal'; ?>;
                color: <?php echo $field['color'] ?? '#ffffff'; ?>;
                direction: <?php echo $isArabic ? 'rtl' : 'ltr'; ?>;
            "><?php echo htmlspecialchars($value); ?></div>
            <?php endforeach; ?>
            
            <?php if (!empty($fields['qr_code']['enabled'])): ?>
            <div class="card-field" style="
                left: <?php echo $fields['qr_code']['x'] ?? 85; ?>%;
                top: <?php echo $fields['qr_code']['y'] ?? 50; ?>%;
                width: <?php echo ($fields['qr_code']['size'] ?? 80) * $fontMultiplier; ?>px;
                height: <?php echo ($fields['qr_code']['size'] ?? 80) * $fontMultiplier; ?>px;
            ">
                <img id="backQrCode" src="" alt="QR Code" style="width: 100%; height: 100%;">
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
        const employeeId = '<?php echo $employeeId; ?>';
        const basePath = '<?php echo getBasePath(); ?>';
        const hasFront = <?php echo $frontTemplate ? 'true' : 'false'; ?>;
        const hasBack = <?php echo $backTemplate ? 'true' : 'false'; ?>;
        
        let frontImageUrl = null;
        let backImageUrl = null;
        
        async function generateCards() {
            try {
                // Wait for fonts to load
                await document.fonts.ready;
                await new Promise(resolve => setTimeout(resolve, 500));
                
                const results = [];
                
                // Generate front card
                if (hasFront) {
                    const frontCanvas = await html2canvas(document.getElementById('frontCardRender'), {
                        useCORS: true,
                        allowTaint: true,
                        backgroundColor: null,
                        scale: 1
                    });
                    
                    frontCanvas.toBlob(async (blob) => {
                        const formData = new FormData();
                        formData.append('image', blob, 'front.png');
                        formData.append('side', 'front');
                        formData.append('employee_id', employeeId);
                        
                        const response = await fetch(basePath + 'save_card_image.php', {
                            method: 'POST',
                            body: formData
                        });
                        
                        const result = await response.json();
                        if (result.success) {
                            frontImageUrl = result.url;
                            document.getElementById('frontCardImage').src = result.url;
                            document.getElementById('frontCardImage').style.display = 'block';
                            document.getElementById('downloadFrontBtn').href = result.url;
                        }
                        
                        checkComplete();
                    }, 'image/png');
                }
                
                // Generate back card
                if (hasBack) {
                    const backCanvas = await html2canvas(document.getElementById('backCardRender'), {
                        useCORS: true,
                        allowTaint: true,
                        backgroundColor: null,
                        scale: 1
                    });
                    
                    backCanvas.toBlob(async (blob) => {
                        const formData = new FormData();
                        formData.append('image', blob, 'back.png');
                        formData.append('side', 'back');
                        formData.append('employee_id', employeeId);
                        
                        const response = await fetch(basePath + 'save_card_image.php', {
                            method: 'POST',
                            body: formData
                        });
                        
                        const result = await response.json();
                        if (result.success) {
                            backImageUrl = result.url;
                            document.getElementById('backCardImage').src = result.url;
                            document.getElementById('backCardImage').style.display = 'block';
                            document.getElementById('downloadBackBtn').href = result.url;
                        }
                        
                        checkComplete();
                    }, 'image/png');
                }
                
                // If no cards to generate
                if (!hasFront && !hasBack) {
                    showContent();
                }
                
            } catch (error) {
                console.error('Error generating cards:', error);
                document.getElementById('loadingOverlay').innerHTML = `
                    <div class="text-center">
                        <div class="text-red-400 mb-4">Error generating cards</div>
                        <a href="${basePath}" class="text-amber-400">Try again</a>
                    </div>
                `;
            }
        }
        
        function checkComplete() {
            const frontDone = !hasFront || frontImageUrl;
            const backDone = !hasBack || backImageUrl;
            
            if (frontDone && backDone) {
                // Log generation
                logGeneration();
                showContent();
            }
        }
        
        function logGeneration() {
            fetch(basePath + 'log_generation.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    employee_id: employeeId,
                    front_url: frontImageUrl,
                    back_url: backImageUrl
                })
            }).catch(() => {}); // Silent fail
        }
        
        function showContent() {
            document.getElementById('loadingOverlay').style.display = 'none';
            document.getElementById('mainContent').classList.remove('hidden');
        }
        
        // Start generation when page loads
        window.addEventListener('load', generateCards);
    </script>
</body>
</html>

