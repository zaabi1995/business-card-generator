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

// Get employee data, always scoped to the current company
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
    $frontTemplate['fields'] = convertLegacyFieldPositions($frontTemplate['fields'], 1050, 600, $frontTemplate['settings']['fields_format'] ?? null);
}
if ($backTemplate && isset($backTemplate['fields'])) {
    $backTemplate['fields'] = convertLegacyFieldPositions($backTemplate['fields'], 1050, 600, $backTemplate['settings']['fields_format'] ?? null);
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

// Generate VCF URL for QR code, short format produces smaller, faster-scanning QR codes
require_once INCLUDES_DIR . '/VCF.php';
require_once INCLUDES_DIR . '/Billing.php';
$vcfUrl = '';
if ($currentCompany && $employee) {
    // Tenant share URL (https://<slug>.cardify.om/<localpart>), so a scan opens
    // the card page and matches what the print pipeline encodes. Shorter than
    // the old tracker form too, which keeps the QR sparse and quick to scan.
    if (!class_exists('CardifyConvention')) {
        require_once INCLUDES_DIR . '/CardifyConvention.php';
    }
    $vcfUrl = CardifyConvention::employeeShareUrl((string)($currentCompany['slug'] ?? ''), $employee);
    if ($vcfUrl === '') {
        $vcfUrl = (defined('APP_HOST') ? 'https://' . APP_HOST : 'https://cardify.om')
                  . '/qr.php?i=' . urlencode($employee['id'] ?? '');
    }
}

// Get quality multiplier based on plan (free users get low DPI)
$isPrintShop = isset($_GET['print_shop']) && $_GET['print_shop'] === 'true';
$qualityMultiplier = Billing::getQualityMultiplier($companyId, $isPrintShop);
$planInfo = Billing::getCompanyPlanInfo($companyId);
$isFreePlan = $planInfo['plan'] === 'free';

// Build absolute URLs for backgrounds
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
$basePath = getBasePath();

// Cache-bust the bg URL with the template's current_version so CDNs
// (Cloudflare) don't serve a stale bg after re-import. Otherwise the
// rendered card includes the old bg and any newly-baked-in static text
// goes missing.
$_bgVer = function ($tpl) {
    return (int)($tpl['current_version'] ?? 1);
};
$frontBgUrl = $frontTemplate && $frontTemplate['backgroundImage']
    ? $baseUrl . $basePath . ltrim($frontTemplate['backgroundImage'], '/') . '?v=' . $_bgVer($frontTemplate)
    : '';
$backBgUrl = $backTemplate && $backTemplate['backgroundImage']
    ? $baseUrl . $basePath . ltrim($backTemplate['backgroundImage'], '/') . '?v=' . $_bgVer($backTemplate)
    : '';

$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Business Card | <?php echo $brandName; ?></title>
    
    <link rel="preconnect" href="https://fonts.bhd.om">
    <link rel="preconnect" href="https://fonts.bhd.om" crossorigin>
    <!-- Arabic Fonts -->
    <link href="https://fonts.bhd.om/css2?family=Cairo:wght@400;500;600;700&family=Tajawal:wght@200;300;400;500;700;800;900&family=Almarai:wght@300;400;700;800&family=Noto+Kufi+Arabic:wght@400;500;600;700&family=IBM+Plex+Sans+Arabic:wght@100;200;300;400;500;600;700&family=Noto+Sans+Arabic:wght@400;500;600;700&family=Readex+Pro:wght@200;300;400;500;600;700&family=El+Messiri:wght@400;500;600;700&family=Changa:wght@200;300;400;500;600;700;800&family=Reem+Kufi:wght@400;500;600;700&family=Amiri:wght@400;700&family=Scheherazade+New:wght@400;500;600;700&family=Mada:wght@200;300;400;500;600;700;800;900&family=Lalezar&family=Lemonada:wght@300;400;500;600;700&family=Aref+Ruqaa:wght@400;700&family=Mirza:wght@400;500;600;700&family=Rakkas&family=Baloo+Bhaijaan+2:wght@400;500;600;700;800&family=Noto+Naskh+Arabic:wght@400;500;600;700&family=Noto+Nastaliq+Urdu:wght@400;500;600;700&family=Lateef:wght@200;300;400;500;600;700;800&family=Harmattan:wght@400;500;600;700&family=Markazi+Text:wght@400;500;600;700&family=Gulzar&display=swap" rel="stylesheet">
    <!-- English Fonts -->
    <link href="https://fonts.bhd.om/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Montserrat:wght@400;500;600;700&family=Roboto:wght@400;500;700&family=Poppins:wght@400;500;600;700&family=Open+Sans:wght@400;600;700&family=Lato:wght@400;700&family=Nunito:wght@400;600;700&family=Raleway:wght@400;500;600;700&family=Work+Sans:wght@400;500;600;700&family=DM+Sans:wght@400;500;700&family=Outfit:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&family=Urbanist:wght@400;500;600;700&family=Lexend:wght@400;500;600;700&family=Sora:wght@400;500;600;700&family=Rubik:wght@400;500;600;700&family=Quicksand:wght@400;500;600;700&family=Ubuntu:wght@400;500;700&family=Barlow:wght@400;500;600;700&family=Jost:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Serif, Display, Handwriting, Monospace -->
    <link href="https://fonts.bhd.om/css2?family=Playfair+Display:wght@400;500;600;700&family=Merriweather:wght@400;700&family=Lora:wght@400;500;600;700&family=PT+Serif:wght@400;700&family=Libre+Baskerville:wght@400;700&family=EB+Garamond:wght@400;500;600;700&family=Cormorant+Garamond:wght@400;500;600;700&family=Spectral:wght@400;500;600;700&family=Noto+Serif:wght@400;700&family=Vollkorn:wght@400;500;600;700&family=Bodoni+Moda:wght@400;500;600;700&family=Bebas+Neue&family=Oswald:wght@400;500;600;700&family=Anton&family=Archivo+Black&family=Righteous&family=Teko:wght@400;500;600;700&family=Big+Shoulders+Display:wght@400;500;600;700;800&family=Fredoka:wght@400;500;600;700&family=Dancing+Script:wght@400;500;600;700&family=Pacifico&family=Great+Vibes&family=Sacramento&family=Allura&family=Lobster&family=Caveat:wght@400;500;600;700&family=Kaushan+Script&family=Roboto+Mono:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600;700&family=Fira+Code:wght@400;500;600;700&family=Source+Code+Pro:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    
    <!-- Tailwind CSS (Local) -->
    <?php $tailwindVersion = @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/techwind/css/tailwind.min.css') ?: time(); ?>
    <link rel="stylesheet" href="/assets/techwind/css/tailwind.min.css?v=<?php echo $tailwindVersion; ?>">
    
    <!-- Fabric.js 7.1.0 -->
    <script src="https://cdn.jsdelivr.net/npm/fabric@7.1.0/dist/index.min.js"></script>
    
    <!-- WebFontLoader -->
    <script src="https://ajax.googleapis.com/ajax/libs/webfont/1.6.26/webfont.js"></script>
    
    <!-- QR Code Generator -->
    <script src="<?= htmlspecialchars(getBasePath()) ?>assets/js/qrcode-generator-1.4.4.min.js"></script>
    
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

    <?php
    // CRITICAL: emit @font-face for every family the active templates
    // reference, sourced from the company's own /uploads/templates/imports/
    // /fonts dir. Without this, fontFamily="Lato" fontWeight=500 (Lato
    // Medium) is requested by Fabric, and since Google Fonts only ships
    // Lato in 400/700, canvas2D falls back to Times serif. Same registry
    // emission portal.php has done since rule 17 was codified. Without
    // it, every saved card with Lato-Medium dynamic text exports as serif.
    require_once INCLUDES_DIR . '/CompanyFonts.php';
    $__importTokens = [];
    foreach ([$frontTemplate, $backTemplate] as $tpl) {
        if ($tpl && !empty($tpl['settings']['import_token'])) {
            $__importTokens[] = $tpl['settings']['import_token'];
        }
    }
    $__importedFonts = [];
    foreach ([$frontTemplate, $backTemplate] as $tpl) {
        if ($tpl && !empty($tpl['settings']['fonts_used'])) {
            foreach ($tpl['settings']['fonts_used'] as $fam) {
                $fam = trim((string)$fam);
                if ($fam !== '') $__importedFonts[$fam] = true;
            }
        }
        if ($tpl && !empty($tpl['settings']['import_token'])) {
            $__manifestPath = realpath(__DIR__) . '/uploads/templates/imports/'
                . preg_replace('/[^a-z0-9_-]/i', '', $tpl['settings']['import_token'])
                . '/fonts/manifest.json';
            if (is_file($__manifestPath)) {
                $__m = json_decode(file_get_contents($__manifestPath), true);
                if (is_array($__m)) {
                    foreach ($__m as $__entry) {
                        $__fam = trim((string)($__entry['family'] ?? ''));
                        if ($__fam !== '') $__importedFonts[$__fam] = true;
                    }
                }
            }
        }
    }
    $__registryCss = CompanyFonts::fontFaceCss(
        realpath(__DIR__),
        $companyId,
        array_keys($__importedFonts),
        $__importTokens
    );
    if ($__registryCss) {
        echo "<style id=\"cardify-font-registry\">\n" . $__registryCss . "</style>\n";
    }
    ?>
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
    
    <script<?= cspNonceAttr() ?>>
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
        
        // Convert each template's stored design dimensions (mm/pt/in) into pixels so
        // the rendered PNG matches the source PDF aspect ratio. Falls back to the
        // legacy 1050x600 canvas only when the template has no settings.
        function getTemplatePixelDims(template) {
            const fallback = { w: 1050, h: 600 };
            if (!template || !template.settings) return fallback;
            const s = template.settings;
            const cw = parseFloat(s.customWidth);
            const ch = parseFloat(s.customHeight);
            const dpi = parseFloat(s.dpi) || 300;
            if (!cw || !ch) return fallback;
            const unit = (s.customUnit || 'mm').toLowerCase();
            const toIn = unit === 'mm' ? 1 / 25.4
                       : unit === 'pt' ? 1 / 72
                       : unit === 'in' ? 1
                       : 1 / 25.4;
            return {
                w: Math.round(cw * toIn * dpi),
                h: Math.round(ch * toIn * dpi),
            };
        }

        // Resolve a field's stored fontFamily to one actually registered in
        // document.fonts. For Arabic text, prefer an `<base>-Arabic-Antiqua` or
        // similar variant; for Latin, fall back to `<base>-Antiqua/-Regular`.
        // Same implementation as portal.php so the saved-card render path picks
        // the same font as the live preview.
        const __cardifyArabicRe = new RegExp('[\\u0590-\\u08FF\\u0750-\\u077F\\uFB50-\\uFDFF\\uFE70-\\uFEFF]');
        function pickFontFamily(stored, text) {
            const want = (stored || '').trim() || 'Inter';
            const isAr = __cardifyArabicRe.test(text || '');
            try {
                const registered = new Set();
                if (document.fonts && typeof document.fonts.values === 'function') {
                    for (const f of document.fonts.values()) {
                        registered.add(String(f.family).replace(/^['"]|['"]$/g, ''));
                    }
                }
                const has = (n) => registered.has(n);
                if (isAr) {
                    // BHD convention: a dedicated <base>-Arabic[-Antiqua] sibling
                    // (e.g. Arsenica -> Arsenica-Arabic-Antiqua). Prefer it.
                    if (has(want + '-Arabic-Antiqua')) return want + '-Arabic-Antiqua';
                    const m = want.match(/^(.+?)(-(.+))?$/);
                    if (m) {
                        const base = m[1], suffix = m[3] || '';
                        const cand = base + '-Arabic' + (suffix ? '-' + suffix : '');
                        if (has(cand)) return cand;
                        if (has(base + '-Arabic')) return base + '-Arabic';
                        if (has(base + '-Arabic-Antiqua')) return base + '-Arabic-Antiqua';
                    }
                    // No dedicated Arabic sibling: KEEP the designer's font. Many
                    // faces are bilingual (e.g. DialogueME / "Dialogue ME") and
                    // contain Arabic themselves. The old code swapped to "any
                    // registered font whose name contains 'arabic'" (e.g. IBM Plex
                    // Sans Arabic), which overrode the bilingual font with the
                    // wrong typeface. Respect `want`; if it truly lacks Arabic the
                    // browser falls back on its own.
                    return want;
                }
                if (!has(want)) {
                    if (has(want + '-Antiqua')) return want + '-Antiqua';
                    if (has(want + '-Regular')) return want + '-Regular';
                    if (has(want + ' Antiqua')) return want + ' Antiqua';
                }
                return want;
            } catch (e) { return want; }
        }

        // Build a field-key map of the actual values that will be drawn so
        // preloadTemplateFonts can pass the real Arabic string to
        // document.fonts.load(spec, sample). Without sample text Google Fonts
        // only fetches the Latin subset and the canvas-image RTL bypass falls
        // back to a serif that lacks contextual joining tables.
        function buildFieldSamples(template, emp) {
            const out = {};
            if (!template || !template.fields || !emp) return out;
            for (const key in template.fields) {
                const f = template.fields[key];
                if (!f || !f.enabled) continue;
                if (f.is_static) {
                    out[key] = String(f.detected_text || '');
                } else {
                    out[key] = String(emp[key] || f.detected_text || '');
                }
            }
            return out;
        }

        // Build the unique (family, weight, style, sample) specs the template's
        // enabled fields reference, then call document.fonts.load() for each
        // one with the actual text so Arabic glyph subsets get fetched.
        // Mirrors portal.php's preload loop. Both the stored family AND the
        // pickFontFamily-resolved variant get queued so cold-cache canvas-image
        // rebuilds find the right Arabic-Antiqua weight.
        async function preloadTemplateFonts(template, data) {
            if (!document.fonts || !template || !template.fields) return;
            const tasks = [];
            for (const key in template.fields) {
                const f = template.fields[key];
                if (!f || !f.enabled) continue;
                const storedFam = (f.fontFamily || 'Inter').trim();
                if (!storedFam) continue;
                const w = f.fontWeight || 400;
                const style = (f.italic === true || f.fontStyle === 'italic') ? 'italic' : 'normal';
                const sample = f.is_static
                    ? String(f.detected_text || '')
                    : String((data && data[key]) || f.detected_text || '');
                const resolvedFam = (typeof pickFontFamily === 'function')
                    ? pickFontFamily(storedFam, sample) : storedFam;
                const fams = new Set([storedFam, resolvedFam].filter(Boolean));
                for (const fam of fams) {
                    const spec = `${style} ${w} 16px "${fam}"`;
                    tasks.push(document.fonts.load(spec, sample || ' ').catch(() => {}));
                }
            }
            try { await Promise.all(tasks); await document.fonts.ready; } catch (e) {}
        }

        // Re-anchor consecutive static decoration tokens (static_1, static_2, ...) so
        // adjacent multi-color runs don't overlap when Fabric's actual measured width
        // differs from the importer's stored width. Same fix as portal.php.
        function reanchorStaticDecorationRuns(template) {
            if (!template || !template.fields || !cardEditor || !cardEditor.fields) return;
            const tokens = [];
            for (const key in template.fields) {
                if (!/^static_\d+$/.test(key)) continue;
                const f = template.fields[key];
                if (!f || !f.enabled || !f.is_static) continue;
                const obj = cardEditor.fields[key];
                if (!obj) continue;
                tokens.push({ key, idx: parseInt(key.split('_')[1], 10), x: f.x, y: f.y, obj });
            }
            tokens.sort((a, b) => a.idx - b.idx);
            const ROW_TOL = 4;
            let runStart = 0;
            for (let i = 1; i <= tokens.length; i++) {
                const breakRun = (i === tokens.length) ||
                    Math.abs(tokens[i].y - tokens[i - 1].y) > ROW_TOL ||
                    tokens[i].x < tokens[i - 1].x;
                if (breakRun) {
                    if (i - runStart > 1) {
                        let cursor = tokens[runStart].x;
                        for (let j = runStart; j < i; j++) {
                            const tok = tokens[j];
                            tok.obj.set({ left: cursor });
                            tok.obj.setCoords();
                            const w = tok.obj.width || 0;
                            cursor = cursor + w;
                        }
                    }
                    runStart = i;
                }
            }
            cardEditor.canvas.requestRenderAll();
        }

        async function generateCards() {
            try {
                // Load fonts
                await FontLoader.load();
                // Preload exact (family, weight, style, sample text) tuples for
                // each template so Lato-Medium etc don't fall back to serif on
                // cold paint AND so Arabic glyph subsets get fetched (not just
                // Latin). Pass per-field samples derived from the actual
                // employee values so document.fonts.load(spec, sample) gets the
                // real Arabic string. Mirrors portal.php exactly.
                const __frontSamples = buildFieldSamples(config.frontTemplate, config.employee);
                const __backSamples  = buildFieldSamples(config.backTemplate, config.employee);
                await preloadTemplateFonts(config.frontTemplate, __frontSamples);
                await preloadTemplateFonts(config.backTemplate, __backSamples);
                await document.fonts.ready;
                await new Promise(r => setTimeout(r, 300));

                // Pick the larger of the two card sides as the initial canvas size.
                const frontDims = getTemplatePixelDims(config.frontTemplate);
                const backDims  = getTemplatePixelDims(config.backTemplate);
                const initW = Math.max(frontDims.w, backDims.w);
                const initH = Math.max(frontDims.h, backDims.h);

                // Initialize card editor at the actual template dimensions.
                // CardEditor's _init() is async (waits for Fabric to load + creates
                // the Canvas), so we must await isReady before kicking off renders.
                // Otherwise clear()/loadBackground silently no-op for the first
                // side and the front exports as blank-with-QR.
                cardEditor = new CardEditor('renderCanvas', {
                    width: initW,
                    height: initH,
                    backgroundColor: '#ffffff'
                });
                await new Promise(resolve => {
                    if (cardEditor.isReady) return resolve();
                    const start = Date.now();
                    const tick = () => {
                        if (cardEditor.isReady) return resolve();
                        if (Date.now() - start > 8000) return resolve();
                        setTimeout(tick, 30);
                    };
                    tick();
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

            // Compute this side's pixel dims from the template's stored design
            // (customWidth/customHeight + dpi). Otech back = 92.6x59.9mm @ 300 DPI
            // = 1094x708, NOT the legacy 1050x600 default.
            const dims = getTemplatePixelDims(template);

            // Clear FIRST, then resize. Fabric's canvas.clear() resets canvas
            // dims to its default 300x150, so setting dimensions before clear
            // gets clobbered and loadBackground scales using the wrong size
            // (background ends up tiny in the corner or misaligned).
            cardEditor.clear();
            try { cardEditor.canvas.setDimensions({ width: dims.w, height: dims.h }); }
            catch (e) {}
            cardEditor.options.width = dims.w;
            cardEditor.options.height = dims.h;

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
            // Per-employee field overrides (migration 104). HR can set these
            // in the admin employee edit modal for one-off cases (very long
            // name, special edition card with gold ink, etc.) without
            // forking the template. Shape: { fieldKey: { fontSize?, fill?, ... } }
            let __fieldOverrides = {};
            if (emp && emp.field_overrides) {
                if (typeof emp.field_overrides === 'string') {
                    try { __fieldOverrides = JSON.parse(emp.field_overrides) || {}; }
                    catch (e) { __fieldOverrides = {}; }
                } else if (typeof emp.field_overrides === 'object') {
                    __fieldOverrides = emp.field_overrides;
                }
            }
            const fieldValues = {
                'name_en': emp.name_en || '',
                'name_ar': emp.name_ar || '',
                'position_en': emp.position_en || '',
                'position_ar': emp.position_ar || '',
                'position_en_2': emp.position_en_2 || '',
                'position_ar_2': emp.position_ar_2 || '',
                'position_en_3': emp.position_en_3 || '',
                'position_ar_3': emp.position_ar_3 || '',
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

                // Fields flagged render_in_bg are baked into the bg image
                // by the importer (PyMuPDF render at print DPI = pixel-
                // identical to the source PDF). Skip them entirely so we
                // don't double-draw with Fabric's font metrics offset.
                if (field.render_in_bg) continue;

                // Static fields render their detected_text as part of the design,
                // not driven by employee data. Preserve whitespace verbatim, skip
                // truly blank tokens but keep " " (used for layout spacing).
                let textToDraw;
                if (field.is_static) {
                    textToDraw = (field.detected_text != null) ? String(field.detected_text) : '';
                    if (!textToDraw.replace(/\s+/g, '')) continue;
                } else {
                    const value = fieldValues[key];
                    if (!value) continue;
                    textToDraw = value;
                }

                // Determine text alignment based on field type
                const textAlign = field.textAlign || (key.endsWith('_ar') ? 'right' : 'left');
                const originX = field.originX || (textAlign === 'center' ? 'center' : (textAlign === 'right' ? 'right' : 'left'));

                const __ov = __fieldOverrides[key] || {};
                // Resolve the actual font family the canvas-image RTL bypass
                // will draw with: for Arabic text, swap Arsenica-Antiqua ->
                // ArsenicaArabicAntiqua so contextual joining tables apply.
                // Without this, every Arabic field falls back to a Latin-only
                // family and shaping breaks (letters disconnected, words
                // reordered). Same swap portal.php does.
                const __storedFam = __ov.fontFamily || field.fontFamily || 'Inter';
                const __resolvedFam = (typeof pickFontFamily === 'function')
                    ? pickFontFamily(__storedFam, textToDraw) : __storedFam;
                // For static decoration fields, skip the width constraint
                // + auto-shrink entirely. The PDF parser sizes static bboxes
                // tightly to the original glyph run (e.g. width=30px for
                // "An "), and addTextField's auto-shrink + IText width
                // constraint can collapse the visible glyphs. Pass width=0
                // so addTextField bypasses shrink and renders at the
                // detected font size. (Dynamic fields keep their bbox width
                // so longer employee values still auto-shrink to fit.)
                const widthForField = field.is_static ? 0 : field.width;
                cardEditor.addTextField(key, {
                    text: textToDraw,
                    x: field.x,
                    y: field.y,
                    width: widthForField,
                    height: field.height,
                    fontSize: __ov.fontSize || field.fontSize,
                    fontFamily: __resolvedFam,
                    // Pass numeric weight straight through (Lato-Medium=500, etc).
                    fontWeight: __ov.fontWeight || field.fontWeight || 400,
                    fontStyle: field.fontStyle || 'normal',
                    fill: __ov.fill || field.fill || field.color,
                    textAlign: textAlign,
                    originX: originX,
                    autoShrink: (typeof __ov.autoShrink === 'boolean') ? __ov.autoShrink : field.auto_shrink,
                    shrinkFloorPct: __ov.shrinkFloorPct || field.shrink_floor_pct,
                    // Card is for download/print, never user-interactive.
                    selectable: false,
                });
            }

            // Add QR code if enabled. Pass the design language (orange eyes,
            // panel padding, brand border) sampled at PDF-import time so the
            // saved card matches the source instead of dropping in as plain
            // black-on-white. Same fix portal.php + admin/index.php carry.
            if (fields.qr_code && fields.qr_code.enabled && config.vcfUrl) {
                await cardEditor.addQRCode(config.vcfUrl, {
                    x: fields.qr_code.x,
                    y: fields.qr_code.y,
                    size: fields.qr_code.size,
                    style: fields.qr_code.qr_style || null
                });
            }

            // Re-anchor adjacent static decoration runs (static_1/2/3 with same y
            // and adjacent x) so Fabric's measured widths don't overlap or gap.
            // This is the same fix that resolved "An Omantel Company" overlap on
            // portal.php.
            reanchorStaticDecorationRuns(template);

            // Wait for the RTL canvas-image rebuild queue to drain. card-editor.js
            // schedules an 80ms rebuild per RTL field that replaces the Fabric
            // IText (which splits Arabic per-character and breaks shaping) with
            // a fabric.Image of the offscreen-rendered Arabic. The Hosn back
            // has 6+ RTL fields, so 100ms isn't enough -- the export captures
            // a half-rebuilt canvas where some fields are still ITexts. 250ms
            // covers ~3 rebuild cycles plus margin; the two rAF ticks make
            // sure the canvas has actually drawn the new images before export.
            await new Promise(r => setTimeout(r, 250));
            await new Promise(r => requestAnimationFrame(r));
            cardEditor.canvas.requestRenderAll();
            await new Promise(r => requestAnimationFrame(r));

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
                    banner.innerHTML = (data.error || 'Card generation limit reached.') + ' <a href="https://api.whatsapp.com/send?phone=96898899100" class="underline font-bold">Contact us</a>';
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
