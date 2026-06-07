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

// HD card generation is free for every team since the Apr 2026 pricing reset.
$planInfo = Billing::getCompanyPlanInfo($companyId);
$qualityMultiplier = max(4, $planInfo['quality_multiplier'] ?? 4); // force HD
$isFreePlan = false; // hides the legacy upgrade-for-HD nag
$hasHighQuality = true;
$employeeId = $_GET['employee_id'] ?? null;
// Whitelist + map logical return targets to real admin files. The JS success
// handler builds the redirect as `returnTo + '.php?generated=1'`, so the value
// here MUST name an existing file. 'dashboard' is the logical name for the
// admin dashboard, which lives at index.php (NOT dashboard.php) - mapping it
// fixes the 404 after the onboarding "generate first card" flow. Any unknown
// value falls back to 'employees' so a tampered ?return= cannot build an
// arbitrary same-origin redirect target.
$returnReqRaw = $_GET['return'] ?? 'employees';
$returnTargetMap = [
    'employees' => 'employees',
    'index'     => 'index',
    'dashboard' => 'index',
];
$returnTo = $returnTargetMap[$returnReqRaw] ?? 'employees';
$isNew = isset($_GET['new']);
$isUpdated = isset($_GET['updated']);
$isRegenerate = isset($_GET['regenerate']);
// Batch mode: when admin/batch_generate.php embeds this page in an iframe
// for each employee, suppress the success-state UI + auto-redirect and
// postMessage completion to the parent window so the batch loop can move on.
$isBatchMode = isset($_GET['batch']) && $_GET['batch'] === '1';

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

// Pre-designed layout fallback when no Fabric.js templates exist
$usePreDesigned = !$hasTemplates;
$selectedLayout = $_GET['layout'] ?? null;
$preDesignedLayouts = [];
$layoutFrontHtml = '';
$layoutBackHtml = '';

if ($usePreDesigned) {
    require_once INCLUDES_DIR . '/CardLayouts.php';
    $preDesignedLayouts = CardLayouts::getAll();

    // Load company theme for branding
    $db = Database::getInstance();
    $companyTheme = null;
    try {
        $companyTheme = $db->fetchOne("SELECT * FROM company_themes WHERE company_id = :cid", ['cid' => $companyId]);
    } catch (Exception $e) {}

    // Default to 'classic' if not selected
    if (!$selectedLayout) {
        $selectedLayout = 'classic';
    }

    // Render the card HTML for the selected layout
    $layoutFrontHtml = CardLayouts::renderFront($selectedLayout, $employee, $company, $companyTheme);
    $layoutBackHtml = CardLayouts::renderBack($selectedLayout, $employee, $company, $companyTheme);
}

adminHeader(t('autogen.page_title'), 'employees');
?>

<?php if ($isFreePlan): ?>
<!-- Free Plan Quality Notice -->
<div class="max-w-lg mx-auto mb-4">
    <div class="bg-amber-50 border border-amber-200 rounded-xl p-4">
        <div class="flex items-start gap-3">
            <i class="fa-solid fa-info-circle text-amber-500 text-xl mt-0.5"></i>
            <div class="flex-1">
                <h4 class="font-semibold text-amber-800"><?= htmlspecialchars(t('autogen.quality_h4')) ?></h4>
                <p class="text-sm text-amber-700 mt-1">
                    <?= htmlspecialchars(t('autogen.quality_body')) ?>
                </p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <a href="billing.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-amber-600 hover:bg-amber-700 text-white rounded-lg text-sm font-medium transition-colors">
                        <i class="fa-solid fa-crown"></i>
                        <?= htmlspecialchars(t('autogen.upgrade_cta')) ?>
                    </a>
                    <a href="billing.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-amber-700 hover:text-amber-800 text-sm font-medium">
                        <?= htmlspecialchars(t('autogen.view_plans')) ?> <i class="fa-solid fa-arrow-right"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- AUTOGEN_I18N is referenced by BOTH the layoutGenerator() and the
     autoGenerator() Alpine components below. Define it here so both
     branches of the if/else have it; was previously only inside the
     usePreDesigned branch which broke the Fabric template path. -->
<script>
const AUTOGEN_I18N = <?php echo json_encode([
    'initializing'       => t('autogen.js_initializing'),
    'preparing_layout'   => t('autogen.js_preparing_layout'),
    'generating_qr'      => t('autogen.js_generating_qr'),
    'rendering_front'    => t('autogen.js_rendering_front'),
    'rendering_back'     => t('autogen.js_rendering_back'),
    'saving_front'       => t('autogen.js_saving_front'),
    'saving_back'        => t('autogen.js_saving_back'),
    'logging_gen'        => t('autogen.js_logging_gen'),
    'init_editor'        => t('autogen.js_init_editor'),
    'generating_front'   => t('autogen.js_generating_front'),
    'generating_back'    => t('autogen.js_generating_back'),
    'saving_cards'       => t('autogen.js_saving_cards'),
    'generic_error'      => t('autogen.js_generic_error'),
], JSON_UNESCAPED_UNICODE); ?>;
</script>

<?php if ($usePreDesigned): ?>
<!-- ═══ PRE-DESIGNED LAYOUT PATH (no Fabric.js needed) ═══ -->
<div class="max-w-4xl mx-auto" x-data="layoutGenerator()" x-init="init()">
    <!-- Layout Picker (shown first, auto-generates with default) -->
    <div x-show="status === 'picking'" x-cloak class="space-y-6">
        <div class="text-center mb-2">
            <h2 class="text-xl font-bold text-gray-900"><?= htmlspecialchars(t('autogen.choose_layout')) ?></h2>
            <p class="text-gray-500 text-sm mt-1"><?= htmlspecialchars(t('autogen.for_employee', ['name' => sanitize($employee['name_en'] ?? $employee['email'])])) ?></p>
        </div>
        <!-- Layout Options -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <?php foreach ($preDesignedLayouts as $lid => $layout): ?>
            <button @click="selectLayout('<?php echo $lid; ?>')"
                    :class="selectedLayout === '<?php echo $lid; ?>' ? 'ring-2 ring-blue-500 border-blue-300' : 'border-gray-200 hover:border-gray-300'"
                    class="bg-white rounded-xl border p-4 text-left transition-all cursor-pointer">
                <div class="font-semibold text-gray-900 text-sm"><?php echo htmlspecialchars($layout['name']); ?></div>
                <div class="text-xs text-gray-500 mt-1"><?php echo htmlspecialchars($layout['description']); ?></div>
                <!-- Mini preview -->
                <div class="mt-3 rounded-lg overflow-hidden border border-gray-100" style="height:80px;">
                    <div id="preview-<?php echo $lid; ?>" style="transform:scale(0.076);transform-origin:top left;width:1050px;height:600px;pointer-events:none;">
                    </div>
                </div>
            </button>
            <?php endforeach; ?>
        </div>
        <div class="text-center">
            <button @click="generate()" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-medium transition-colors">
                <i class="fa-solid fa-wand-magic-sparkles mr-2"></i><?= htmlspecialchars(t('autogen.generate_card')) ?>
            </button>
        </div>
    </div>

    <!-- Generating State -->
    <div x-show="status === 'generating'" class="max-w-lg mx-auto bg-white rounded-2xl border border-gray-200 shadow-sm p-8 text-center">
        <div class="w-16 h-16 mx-auto bg-blue-100 rounded-full flex items-center justify-center mb-4">
            <i class="fa-solid fa-wand-magic-sparkles text-2xl text-blue-600 animate-pulse"></i>
        </div>
        <h2 class="text-xl font-bold text-gray-900 mb-2"><?= htmlspecialchars(t('autogen.generating_card')) ?></h2>
        <p class="text-gray-600 mb-4"><?= htmlspecialchars(t('autogen.creating_for', ['name' => sanitize($employee['name_en'] ?? $employee['email'])])) ?></p>
        <div class="flex items-center justify-center gap-2">
            <div class="w-2 h-2 bg-blue-500 rounded-full animate-bounce"></div>
            <div class="w-2 h-2 bg-blue-500 rounded-full animate-bounce" style="animation-delay: 0.1s"></div>
            <div class="w-2 h-2 bg-blue-500 rounded-full animate-bounce" style="animation-delay: 0.2s"></div>
        </div>
        <p class="text-sm text-gray-500 mt-4" x-text="statusMessage"></p>
    </div>

    <!-- Success State -->
    <div x-show="status === 'success'" x-cloak class="max-w-lg mx-auto bg-white rounded-2xl border border-gray-200 shadow-sm p-8 text-center">
        <div class="w-16 h-16 mx-auto bg-green-100 rounded-full flex items-center justify-center mb-4">
            <i class="fa-solid fa-check text-2xl text-green-600"></i>
        </div>
        <h2 class="text-xl font-bold text-gray-900 mb-2"><?= htmlspecialchars(t('autogen.card_generated')) ?></h2>
        <p class="text-gray-500 text-sm mb-6"><?= htmlspecialchars(t('autogen.live_and_ready')) ?></p>
        <div class="bg-gray-50 border border-gray-200 rounded-xl p-4 mb-6 text-left">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2"><?= htmlspecialchars(t('autogen.digital_link_label')) ?></p>
            <div class="flex items-center gap-2">
                <input type="text" readonly :value="cardShareUrl"
                       class="flex-1 px-3 py-2 bg-white border border-gray-200 rounded-lg text-sm text-gray-700 font-mono truncate">
                <button @click="copyUrl()"
                        class="flex-shrink-0 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition-colors"
                        :class="copied ? 'bg-green-600 hover:bg-green-600' : ''">
                    <i :class="copied ? 'fa-solid fa-check' : 'fa-solid fa-copy'"></i>
                    <span x-text='copied ? <?= json_encode(t("autogen.copied")) ?> : <?= json_encode(t("autogen.copy")) ?>'></span>
                </button>
            </div>
        </div>
        <div class="flex flex-col sm:flex-row gap-3 justify-center mb-6">
            <a :href="cardShareUrl" target="_blank" class="inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-medium transition-colors">
                <i class="fa-solid fa-eye"></i> <?= htmlspecialchars(t('autogen.view_card')) ?>
            </a>
            <a :href="waShareUrl" target="_blank" class="inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-green-500 hover:bg-green-600 text-white rounded-xl font-medium transition-colors">
                <i class="fa-brands fa-whatsapp"></i> <?= htmlspecialchars(t('autogen.share_whatsapp')) ?>
            </a>
            <a :href="cardPdfUrl" target="_blank" class="inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-purple-600 hover:bg-purple-700 text-white rounded-xl font-medium transition-colors">
                <i class="fa-solid fa-file-pdf"></i>
                Download print PDF
            </a>
            <a :href="continueUrl" class="inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-xl font-medium transition-colors">
                <i class="fa-solid fa-arrow-right"></i> <?= htmlspecialchars(t('autogen.continue')) ?>
            </a>
        </div>
        <p class="text-xs text-gray-400"><?= htmlspecialchars(str_replace(':sec', '', t('autogen.redirecting_in'))) ?><span x-text="countdown"></span>
            <button @click="cancelRedirect()" class="text-blue-500 hover:underline ml-1"><?= htmlspecialchars(t('autogen.stay_here')) ?></button>
        </p>
    </div>

    <!-- Error State -->
    <div x-show="status === 'error'" x-cloak class="max-w-lg mx-auto bg-white rounded-2xl border border-gray-200 shadow-sm p-8 text-center">
        <div class="w-16 h-16 mx-auto bg-red-100 rounded-full flex items-center justify-center mb-4">
            <i class="fa-solid fa-exclamation-triangle text-2xl text-red-600"></i>
        </div>
        <h2 class="text-xl font-bold text-gray-900 mb-2"><?= htmlspecialchars(t('autogen.generation_failed')) ?></h2>
        <p class="text-gray-600 mb-4" x-text="errorMessage"></p>
        <button @click="status = 'picking'" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors">
            <i class="fa-solid fa-arrow-left mr-2"></i><?= htmlspecialchars(t('autogen.try_again')) ?>
        </button>
    </div>
</div>

<!-- Hidden render containers for html2canvas -->
<div id="layout-render-front" style="position:absolute;left:-9999px;top:0;"><?php echo $layoutFrontHtml; ?></div>
<div id="layout-render-back" style="position:absolute;left:-9999px;top:0;"><?php echo $layoutBackHtml; ?></div>

<!-- Layout previews data -->
<?php
// Pre-render all layout previews (front + back) server-side
$allFrontHtml = [];
$allBackHtml = [];
foreach (array_keys($preDesignedLayouts) as $lid) {
    $allFrontHtml[$lid] = CardLayouts::renderFront($lid, $employee, $company, $companyTheme);
    $allBackHtml[$lid] = CardLayouts::renderBack($lid, $employee, $company, $companyTheme);
}
?>
<script>
const layoutFronts = <?php echo json_encode($allFrontHtml, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const layoutBacks = <?php echo json_encode($allBackHtml, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const layoutIds = <?php echo json_encode(array_keys($preDesignedLayouts)); ?>;
</script>

<!-- Fonts for pre-designed layouts -->
<link rel="preconnect" href="https://fonts.bhd.om">
<link rel="preconnect" href="https://fonts.bhd.om" crossorigin>
<link href="https://fonts.bhd.om/css2?family=Inter:wght@400;500;600;700&family=DM+Sans:wght@400;500;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Playfair+Display:wght@400;500;600;700&family=Lato:wght@400;700&family=Sora:wght@400;500;600;700&family=Noto+Kufi+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">

<?php
// CRITICAL: emit @font-face for company-extracted TTFs (Lato Medium 500
// etc.) so canvas2D doesn't fall back to Times serif when fontWeight=500
// is requested. Same fix portal.php + generate_card_html.php carry.
require_once INCLUDES_DIR . '/CompanyFonts.php';
$__importTokens = [];
$__importedFonts = [];
foreach ([$frontTemplate, $backTemplate] as $tpl) {
    if ($tpl && !empty($tpl['settings']['import_token'])) {
        $__importTokens[] = $tpl['settings']['import_token'];
    }
    if ($tpl && !empty($tpl['settings']['fonts_used'])) {
        foreach ($tpl['settings']['fonts_used'] as $fam) {
            $fam = trim((string)$fam);
            if ($fam !== '') $__importedFonts[$fam] = true;
        }
    }
    // Pull suffixed face names (Lato-Medium, DINNextLTArabic-Medium) from
    // fields_json so they get their own @font-face entries; the duplicate
    // family-name registration in CompanyFonts guarantees a hard weight match.
    if ($tpl && !empty($tpl['fields_json'])) {
        $__fj = json_decode($tpl['fields_json'], true);
        if (is_array($__fj)) {
            foreach ($__fj as $__f) {
                if (!is_array($__f) || empty($__f['fontFamily'])) continue;
                $__fam = trim((string)$__f['fontFamily']);
                $__importedFonts[$__fam] = true;
                $__bare = preg_replace('/-(Regular|Medium|Bold|Light|SemiBold|ExtraBold|Heavy|Black|Thin)(Italic)?$/', '', $__fam);
                if ($__bare !== '' && $__bare !== $__fam) $__importedFonts[$__bare] = true;
            }
        }
    }
    if ($tpl && !empty($tpl['settings']['import_token'])) {
        $__manifestPath = realpath(__DIR__ . '/..') . '/uploads/templates/imports/'
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
    realpath(__DIR__ . '/..'),
    $companyId,
    array_keys($__importedFonts),
    $__importTokens
);
if ($__registryCss) {
    echo "<style id=\"cardify-font-registry\">\n" . $__registryCss . "</style>\n";
}
?>

<!-- html2canvas -->
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>

<!-- QR Code Generator -->
<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/dist/qrcode.min.js"></script>

<script>
// AUTOGEN_I18N defined earlier (above the usePreDesigned if/else split).

// Inject layout previews
document.addEventListener('DOMContentLoaded', function() {
    layoutIds.forEach(function(id) {
        var el = document.getElementById('preview-' + id);
        if (el) el.innerHTML = layoutFronts[id];
    });
});

function layoutGenerator() {
    return {
        status: <?php echo ($isNew || $isRegenerate) ? "'generating'" : "'picking'"; ?>,
        selectedLayout: '<?php echo $selectedLayout; ?>',
        statusMessage: AUTOGEN_I18N.initializing,
        errorMessage: '',
        copied: false,
        countdown: 8,
        redirectTimer: null,
        countdownTimer: null,
        companySlug: <?php echo json_encode($companySlug); ?>,
        employeeId: <?php echo json_encode($employeeId); ?>,
        <?php
            // Resolve clean URL slug from email localpart so the success screen
            // shares /jarwish9 instead of the raw UUID.
            $__empEmail = strtolower((string)($employee['email'] ?? ''));
            $__atPos    = strpos($__empEmail, '@');
            $__empSlug  = $__atPos > 0
                ? preg_replace('/[^a-z0-9._-]/', '', substr($__empEmail, 0, $__atPos))
                : '';
        ?>
        employeeSlug: <?php echo json_encode($__empSlug); ?>,
        baseUrl: <?php echo json_encode(rtrim($baseUrl, '/')); ?>,
        apexHost: <?php echo json_encode(cardifyApexHost()); ?>,
        basePath: <?php echo json_encode(rtrim($basePath, '/')); ?>,
        returnTo: <?php echo json_encode($returnTo); ?>,
        vcfUrl: <?php echo json_encode($vcfUrl); ?>,
        isNew: <?php echo $isNew ? 'true' : 'false'; ?>,

        get cardShareUrl() {
            // Clean tenant URL using the email localpart (e.g. /jarwish9). Falls
            // back to the long UUID slug only when no email is on file.
            var slug = this.employeeSlug || this.employeeId;
            return 'https://' + this.companySlug + '.' + this.apexHost + '/' + slug;
        },
        get waShareUrl() {
            return 'https://wa.me/?text=' + encodeURIComponent('Here is my digital business card: ' + this.cardShareUrl);
        },
        get continueUrl() {
            var p = this.isNew ? 'generated' : 'regenerated';
            return this.returnTo + '.php?' + p + '=1';
        },
        get cardPdfUrl() {
            return this.baseUrl + '/card-pdf.php?i=' + encodeURIComponent(this.employeeId || '');
        },

        selectLayout(id) {
            this.selectedLayout = id;
            // Update render containers with the selected layout HTML
            var frontEl = document.getElementById('layout-render-front');
            var backEl = document.getElementById('layout-render-back');
            if (frontEl && layoutFronts[id]) frontEl.innerHTML = layoutFronts[id];
            if (backEl && layoutBacks[id]) backEl.innerHTML = layoutBacks[id];
        },

        async init() {
            // If auto-generating (new employee or regenerate), start immediately
            if (this.status === 'generating') {
                await this.generate();
            }
        },

        async generate() {
            this.status = 'generating';
            this.statusMessage = AUTOGEN_I18N.preparing_layout;

            try {
                // Wait for html2canvas to be available
                await this.waitFor('html2canvas');

                // Generate QR code and inject into back card
                if (this.vcfUrl && typeof qrcode !== 'undefined') {
                    this.statusMessage = AUTOGEN_I18N.generating_qr;
                    var qr = qrcode(0, 'M');
                    qr.addData(this.vcfUrl);
                    qr.make();
                    var qrDataUrl = qr.createDataURL(4, 0);
                    // Re-render back with QR by replacing the empty qrHtml slot
                    // The back HTML from server has no QR - we re-render via PHP endpoint
                    // Simpler: just inject a QR img into the back container's centered area
                    var backEl = document.getElementById('layout-render-back');
                    if (backEl) {
                        // Find divs that are empty (QR placeholder area) and inject
                        var allDivs = backEl.querySelectorAll('div');
                        var injected = false;
                        allDivs.forEach(function(div) {
                            // The qrHtml was empty, so there's an empty div where QR should be
                            if (!injected && div.children.length === 0 && div.textContent.trim() === '' && div.style.marginTop) {
                                var qrImg = document.createElement('img');
                                qrImg.src = qrDataUrl;
                                qrImg.style.cssText = 'width:120px;height:120px;';
                                div.appendChild(qrImg);
                                injected = true;
                            }
                        });
                    }
                }

                // Render front card
                this.statusMessage = AUTOGEN_I18N.rendering_front;
                var frontEl = document.getElementById('layout-render-front');
                var frontTarget = frontEl.firstElementChild;
                if (!frontTarget) throw new Error('No front card to render');

                var frontCanvas = await html2canvas(frontTarget, {
                    width: 1050,
                    height: 600,
                    scale: 2,
                    useCORS: true,
                    allowTaint: false,
                    backgroundColor: null,
                    logging: false
                });

                // Render back card
                this.statusMessage = AUTOGEN_I18N.rendering_back;
                var backEl = document.getElementById('layout-render-back');
                var backTarget = backEl.firstElementChild;
                var backCanvas = null;
                if (backTarget) {
                    backCanvas = await html2canvas(backTarget, {
                        width: 1050,
                        height: 600,
                        scale: 2,
                        useCORS: true,
                        allowTaint: false,
                        backgroundColor: null,
                        logging: false
                    });
                }

                // Save front
                this.statusMessage = AUTOGEN_I18N.saving_front;
                var frontBlob = await this.canvasToBlob(frontCanvas);
                var frontResult = await this.saveCard(frontBlob, 'front');
                if (!frontResult.success) throw new Error(frontResult.error || 'Failed to save front card');

                // Save back
                var backFile = null;
                if (backCanvas) {
                    this.statusMessage = AUTOGEN_I18N.saving_back;
                    var backBlob = await this.canvasToBlob(backCanvas);
                    var backResult = await this.saveCard(backBlob, 'back');
                    if (!backResult.success) throw new Error(backResult.error || 'Failed to save back card');
                    backFile = backResult.png_filename;
                }

                // Log generation
                this.statusMessage = AUTOGEN_I18N.logging_gen;
                var logResp = await fetch(this.baseUrl + '/log_generation.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        employee_id: this.employeeId,
                        front_url: frontResult.png_filename,
                        back_url: backFile
                    })
                });
                var logData = await logResp.json();
                if (!logData.success && logData.limit_reached) {
                    this.status = 'error';
                    this.errorMessage = logData.error || 'Monthly card limit reached.';
                    return;
                }

                this.status = 'success';
                if (<?php echo $isBatchMode ? 'true' : 'false'; ?>) {
                    try {
                        if (window.parent && window.parent !== window) {
                            window.parent.postMessage({
                                type: 'cardify:batch:card-done',
                                employee_id: this.employeeId || (this.employee && this.employee.id) || '',
                                ok: true
                            }, '*');
                        }
                    } catch (e) {}
                    return;
                }
                this.startRedirect();

            } catch (err) {
                console.error('Generation error:', err);
                this.status = 'error';
                this.errorMessage = err.message;
                if (<?php echo $isBatchMode ? 'true' : 'false'; ?>) {
                    try {
                        if (window.parent && window.parent !== window) {
                            window.parent.postMessage({
                                type: 'cardify:batch:card-done',
                                employee_id: this.employeeId || (this.employee && this.employee.id) || '',
                                ok: false,
                                error: err.message
                            }, '*');
                        }
                    } catch (e) {}
                }
            }
        },

        canvasToBlob(canvas) {
            return new Promise(function(resolve) {
                canvas.toBlob(function(blob) { resolve(blob); }, 'image/png');
            });
        },

        async saveCard(blob, side) {
            var fd = new FormData();
            fd.append('png', blob, side + '.png');
            fd.append('side', side);
            fd.append('employee_id', this.employeeId);
            var resp = await fetch(this.baseUrl + '/save_card_both.php', { method: 'POST', body: fd });
            return await resp.json();
        },

        waitFor(name) {
            return new Promise(function(resolve, reject) {
                var attempts = 0;
                var check = function() {
                    if (typeof window[name] !== 'undefined') return resolve();
                    if (++attempts > 50) return reject(new Error(name + ' failed to load'));
                    setTimeout(check, 100);
                };
                check();
            });
        },

        copyUrl() {
            var self = this;
            navigator.clipboard.writeText(this.cardShareUrl).then(function() {
                self.copied = true;
                setTimeout(function() { self.copied = false; }, 2000);
            });
        },

        startRedirect() {
            var self = this;
            this.countdown = 8;
            this.countdownTimer = setInterval(function() {
                if (typeof self.countdown === 'number') self.countdown--;
            }, 1000);
            this.redirectTimer = setTimeout(function() {
                window.location.href = self.continueUrl;
            }, 8000);
        },

        cancelRedirect() {
            clearTimeout(this.redirectTimer);
            clearInterval(this.countdownTimer);
            this.countdown = '...';
        }
    };
}
</script>

<?php else: ?>
<!-- ═══ FABRIC.JS TEMPLATE PATH (existing) ═══ -->

<div class="max-w-lg mx-auto" x-data="autoGenerator()" x-init="init()">
    <!-- Generating State -->
    <div x-show="status === 'generating'" class="bg-white rounded-2xl border border-gray-200 shadow-sm p-8 text-center">
        <div class="w-16 h-16 mx-auto bg-blue-100 rounded-full flex items-center justify-center mb-4">
            <i class="fa-solid fa-wand-magic-sparkles text-2xl text-blue-600 animate-pulse"></i>
        </div>
        <h2 class="text-xl font-bold text-gray-900 mb-2">
            <?= htmlspecialchars($isRegenerate ? t('autogen.regenerating_card') : t('autogen.generating_card')) ?>
        </h2>
        <p class="text-gray-600 mb-4">
            <?= htmlspecialchars(t('autogen.creating_for', ['name' => sanitize($employee['name_en'] ?? $employee['email'])])) ?>
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
        <h2 class="text-xl font-bold text-gray-900 mb-2"><?= htmlspecialchars(t('autogen.card_generated')) ?></h2>
        <p class="text-gray-500 text-sm mb-6"><?= htmlspecialchars(t('autogen.live_and_ready')) ?></p>

        <!-- Shareable Link -->
        <div class="bg-gray-50 border border-gray-200 rounded-xl p-4 mb-6 text-left">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2"><?= htmlspecialchars(t('autogen.digital_link_label')) ?></p>
            <div class="flex items-center gap-2">
                <input type="text" id="card-share-url" readonly
                       :value="cardShareUrl"
                       class="flex-1 px-3 py-2 bg-white border border-gray-200 rounded-lg text-sm text-gray-700 font-mono truncate">
                <button @click="copyCardUrl()"
                        class="flex-shrink-0 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition-colors flex items-center gap-1.5"
                        :class="copied ? 'bg-green-600 hover:bg-green-600' : ''">
                    <i :class="copied ? 'fa-solid fa-check' : 'fa-solid fa-copy'"></i>
                    <span x-text='copied ? <?= json_encode(t("autogen.copied")) ?> : <?= json_encode(t("autogen.copy")) ?>'></span>
                </button>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex flex-col sm:flex-row gap-3 justify-center mb-6">
            <a :href="cardShareUrl" target="_blank"
               class="inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-medium transition-colors">
                <i class="fa-solid fa-eye"></i>
                <?= htmlspecialchars(t('autogen.view_card')) ?>
            </a>
            <a :href="waShareUrl" target="_blank"
               class="inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-green-500 hover:bg-green-600 text-white rounded-xl font-medium transition-colors">
                <i class="fa-brands fa-whatsapp"></i>
                <?= htmlspecialchars(t('autogen.share_whatsapp')) ?>
            </a>
            <a :href="cardPdfUrl" target="_blank"
               class="inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-purple-600 hover:bg-purple-700 text-white rounded-xl font-medium transition-colors">
                <i class="fa-solid fa-file-pdf"></i>
                Download print PDF
            </a>
            <a :href="continueUrl"
               class="inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-xl font-medium transition-colors">
                <i class="fa-solid fa-arrow-right"></i>
                <?= htmlspecialchars(t('autogen.continue')) ?>
            </a>
        </div>

        <p class="text-xs text-gray-400"><?= htmlspecialchars(str_replace(':sec', '', t('autogen.redirecting_auto'))) ?><span x-text="redirectCountdown"></span>&hellip;
            <button @click="cancelRedirect()" class="text-blue-500 hover:underline ml-1"><?= htmlspecialchars(t('autogen.stay_here')) ?></button>
        </p>
    </div>
    
    <!-- Error State -->
    <div x-show="status === 'error'" x-cloak class="bg-white rounded-2xl border border-gray-200 shadow-sm p-8 text-center">
        <div class="w-16 h-16 mx-auto bg-red-100 rounded-full flex items-center justify-center mb-4">
            <i class="fa-solid fa-exclamation-triangle text-2xl text-red-600"></i>
        </div>
        <h2 class="text-xl font-bold text-gray-900 mb-2"><?= htmlspecialchars(t('autogen.generation_failed')) ?></h2>
        <p class="text-gray-600 mb-4" x-text="errorMessage"></p>
        <a href="employees.php" class="inline-block px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors">
            <i class="fa-solid fa-arrow-left mr-2"></i><?= htmlspecialchars(t('autogen.back_to_employees')) ?>
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
        statusMessage: AUTOGEN_I18N.initializing,
        errorMessage: AUTOGEN_I18N.generic_error,
        
        // Data from PHP
        employee: <?php echo json_encode($employee, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        frontTemplate: <?php echo json_encode($frontTemplate, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        backTemplate: <?php echo json_encode($backTemplate, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        companyId: <?php echo json_encode($companyId); ?>,
        companySlug: <?php echo json_encode($companySlug); ?>,
        company: <?php echo json_encode([
            'name'                  => $company['name'] ?? '',
            'name_ar'               => $company['name_ar'] ?? '',
            'default_website'       => $company['default_website'] ?? '',
            'default_fax'           => $company['default_fax'] ?? '',
            'default_address_en'    => $company['default_address_en'] ?? '',
            'default_address_2_en'  => $company['default_address_2_en'] ?? '',
            'default_address_ar'    => $company['default_address_ar'] ?? '',
            'default_address_2_ar'  => $company['default_address_2_ar'] ?? '',
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        basePath: <?php echo json_encode(rtrim($basePath, '/')); ?>,
        baseUrl: <?php echo json_encode(rtrim($baseUrl, '/')); ?>,
        apexHost: <?php echo json_encode(cardifyApexHost()); ?>,
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
            // Tenant-style URL (no /card/ prefix). Matches digital_card.php's
            // canonical URL and the .vcf / wallet flows.
            return 'https://' + this.companySlug + '.' + this.apexHost + '/' + this.employee.id;
        },
        get waShareUrl() {
            const msg = encodeURIComponent('Here is my digital business card: ' + this.cardShareUrl);
            return 'https://wa.me/?text=' + msg;
        },
        get continueUrl() {
            const param = this.isRegenerate ? 'regenerated' : 'generated';
            return this.returnTo + '.php?' + param + '=1';
        },
        get cardPdfUrl() {
            return this.baseUrl + '/card-pdf.php?i=' + encodeURIComponent(this.employeeId || (this.employee && this.employee.id) || '');
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
            const v = template.current_version || 1;
            // ALWAYS use the PNG, never the SVG. The PyMuPDF-generated SVG
            // references the PDF's internal subset font name (e.g.
            // "GUWOUL+Lato-Medium") which the browser can't resolve, so it
            // falls back to Times serif. The PNG is rendered by Poppler at
            // print DPI and bakes the correct fonts into pixels. Same fix
            // admin/index.php carries since commit e043240.
            const raw = template.backgroundImage;
            const path = raw.replace(/^\//, '');
            const sep = path.indexOf('?') === -1 ? '?' : '&';
            return this.baseUrl + '/' + path + sep + 'v=' + v;
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
                
                this.statusMessage = AUTOGEN_I18N.init_editor;
                
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
                    this.statusMessage = AUTOGEN_I18N.generating_front;
                    
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
                    
                    // Add QR code if enabled. Pass qr_style so the saved card
                    // matches the design (orange eyes, brand border) instead of
                    // dropping in as plain black-on-white.
                    const frontFields = this.frontTemplate.fields || {};
                    if (frontFields.qr_code && frontFields.qr_code.enabled && this.vcfUrl) {
                        await this.editor.addQRCode(this.vcfUrl, {
                            x: frontFields.qr_code.x,
                            y: frontFields.qr_code.y,
                            size: frontFields.qr_code.size,
                            style: frontFields.qr_code.qr_style || null
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
                    this.statusMessage = AUTOGEN_I18N.generating_back;
                    
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
                    
                    // Add QR code if enabled. Pass qr_style so the saved card
                    // matches the design (orange eyes, brand border) instead of
                    // dropping in as plain black-on-white.
                    const backFields = this.backTemplate.fields || {};
                    if (backFields.qr_code && backFields.qr_code.enabled && this.vcfUrl) {
                        await this.editor.addQRCode(this.vcfUrl, {
                            x: backFields.qr_code.x,
                            y: backFields.qr_code.y,
                            size: backFields.qr_code.size,
                            style: backFields.qr_code.qr_style || null
                        });
                    }
                    
                    // Wait for rendering
                    await new Promise(r => setTimeout(r, 100));
                    
                    // Export PNG with plan-based quality
                    const backExportQuality = this.hasHighQuality ? 3 : this.qualityMultiplier;
                    backBlob = await this.editor.exportPNGBlob(backExportQuality);
                }
                
                // Save the cards
                this.statusMessage = AUTOGEN_I18N.saving_cards;
                
                if (!frontBlob && !backBlob) {
                    throw new Error('No cards were generated');
                }
                
                let frontFile = null;
                let backFile = null;
                
                // Save front card
                if (frontBlob) {
                    this.statusMessage = AUTOGEN_I18N.saving_front;
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
                    this.statusMessage = AUTOGEN_I18N.saving_back;
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
                this.statusMessage = AUTOGEN_I18N.logging_gen;
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
                    this.errorMessage = logResult.error || 'Card generation rate limit hit. Please wait a moment and try again.';
                    return;
                }

                if (!logResult.success) {
                    console.warn('Failed to log generation:', logResult.error);
                }

                this.status = 'success';

                // Batch mode: notify parent window and skip the success UI countdown.
                console.log('[auto_generate] Fabric success, batchMode=<?php echo $isBatchMode ? "true" : "false"; ?>, employee=', (this.employeeId || (this.employee && this.employee.id)));
                if (<?php echo $isBatchMode ? 'true' : 'false'; ?>) {
                    try {
                        if (window.parent && window.parent !== window) {
                            console.log('[auto_generate] postMessage cardify:batch:card-done to parent');
                            window.parent.postMessage({
                                type: 'cardify:batch:card-done',
                                employee_id: this.employeeId || (this.employee && this.employee.id) || '',
                                ok: true
                            }, '*');
                        } else {
                            console.warn('[auto_generate] batch=1 but no parent window');
                        }
                    } catch (e) {
                        console.error('[auto_generate] postMessage failed:', e);
                    }
                    return;
                }

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
                if (<?php echo $isBatchMode ? 'true' : 'false'; ?>) {
                    try {
                        if (window.parent && window.parent !== window) {
                            window.parent.postMessage({
                                type: 'cardify:batch:card-done',
                                employee_id: this.employeeId || (this.employee && this.employee.id) || '',
                                ok: false,
                                error: error.message
                            }, '*');
                        }
                    } catch (e) {}
                }
            }
        },
        
        async addFieldsToCard(editor, fields) {
            // Map field keys to employee data values, with company-level fallback
            // for per-company fields (website, address, company name, fax). The
            // public portal hides these inputs from employees, so the company
            // default is the source of truth at render time.
            const co = (this.company || {});
            const coName = co.name || co.company_name || '';
            const fieldValues = {
                'name_en': this.employee.name_en || '',
                'name_ar': this.employee.name_ar || '',
                'position_en': this.employee.position_en || '',
                'position_ar': this.employee.position_ar || '',
                'company_en': this.employee.company_en || coName,
                'company_ar': this.employee.company_ar || co.name_ar || coName,
                'phone': this.employee.phone || '',
                'phone_ar': this.employee.phone_ar || '',
                'mobile': this.employee.mobile || '',
                'mobile_ar': this.employee.mobile_ar || '',
                'email': this.employee.email || '',
                'website': this.employee.website || co.default_website || '',
                'website_ar': this.employee.website_ar || co.default_website || '',
                'fax': this.employee.fax || co.default_fax || '',
                'fax_ar': this.employee.fax_ar || co.default_fax || '',
                'address': this.employee.address_en || this.employee.address || co.default_address_en || '',
                'address_en': this.employee.address_en || co.default_address_en || '',
                'address_2_en': this.employee.address_2_en || co.default_address_2_en || '',
                'address_ar': this.employee.address_ar || co.default_address_ar || '',
                'address_2_ar': this.employee.address_2_ar || co.default_address_2_ar || ''
            };
            
            for (const [key, field] of Object.entries(fields)) {
                // Skip QR code - handled separately
                if (key === 'qr_code') continue;

                // Skip if field is disabled
                if (!field.enabled) continue;

                // Skip fields that the importer baked into the bg PNG (static
                // decorations rendered at source-PDF metrics, no Fabric).
                if (field.render_in_bg) continue;

                // Resolve text: static fields render detected_text as-is,
                // typed dynamic fields look up the employee value with a
                // detected_text fallback so tenant-constants like website
                // ('www.otech.om') show on every employee card.
                let value;
                if (field.is_static) {
                    value = field.detected_text != null ? String(field.detected_text) : '';
                    if (!value.replace(/\s+/g, '')) continue;
                } else {
                    value = fieldValues[key] || (field.detected_text || '');
                    if (!value) continue;
                }

                // Determine text alignment (matching template editor fallback logic)
                const textAlign = field.textAlign || (key.endsWith('_ar') ? 'right' : 'left');
                const originX = field.originX || (textAlign === 'center' ? 'center' : (textAlign === 'right' ? 'right' : 'left'));

                // Add text field with alignment from template. Skip width
                // constraint on static fields (tightly-sized bboxes + auto-
                // shrink would collapse them to invisibility).
                editor.addTextField(key, {
                    text: value,
                    x: field.x,
                    y: field.y,
                    width: field.is_static ? 0 : field.width,
                    fontSize: field.fontSize,
                    fontFamily: field.fontFamily,
                    fontWeight: field.fontWeight || 'normal',
                    fill: field.fill || field.color || '#333333',
                    textAlign: textAlign,
                    originX: originX,
                    selectable: false,
                });
            }
        }
    };
}
</script>

<?php endif; /* end usePreDesigned else */ ?>

<?php adminFooter(); ?>
