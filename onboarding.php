<?php
/**
 * Cardify - BHD Customer Onboarding Wizard
 * Guided setup for BHD Printing referral customers
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';

// Must be logged in
if (!Auth::isLoggedIn()) {
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

// Only company admins
$currentRole = Auth::getCurrentRole();
if (!in_array($currentRole, ['company_admin', 'company', 'admin'])) {
    header('Location: ' . getBasePath() . 'admin/');
    exit;
}

$companyId = getCurrentCompanyId();
$companySlug = getCurrentCompanySlug();

if (!$companyId) {
    header('Location: ' . getBasePath() . 'admin/');
    exit;
}

$source = $_GET['source'] ?? 'general';
$isBhd = ($source === 'bhd');

$pageTitle = $isBhd ? t('onboarding_home.page_title_bhd') : t('onboarding_home.page_title_general');
$showNavigation = false;

// BHD template definitions (no background image - colored canvas)
$bhdTemplates = [
    'bhd-classic' => [
        'name' => t('onboarding_home.tpl_classic_name'),
        'description' => t('onboarding_home.tpl_classic_desc'),
        'primary' => '#009bc1',
        'secondary' => '#0f3460',
        'bg' => '#ffffff',
        'textColor' => '#1f2937',
        'accentColor' => '#009bc1',
        'preview_bg' => 'bg-white border border-gray-200',
        'preview_text' => 'text-gray-900',
        'preview_accent' => '#009bc1',
    ],
    'bhd-navy' => [
        'name' => t('onboarding_home.tpl_navy_name'),
        'description' => t('onboarding_home.tpl_navy_desc'),
        'primary' => '#009bc1',
        'secondary' => '#0f172a',
        'bg' => '#0f172a',
        'textColor' => '#ffffff',
        'accentColor' => '#009bc1',
        'preview_bg' => 'bg-gray-900',
        'preview_text' => 'text-white',
        'preview_accent' => '#009bc1',
    ],
    'bhd-sky' => [
        'name' => t('onboarding_home.tpl_sky_name'),
        'description' => t('onboarding_home.tpl_sky_desc'),
        'primary' => '#ffffff',
        'secondary' => '#0f3460',
        'bg' => '#009bc1',
        'textColor' => '#ffffff',
        'accentColor' => '#ffffff',
        'preview_bg' => 'bg-cyan-500',
        'preview_text' => 'text-white',
        'preview_accent' => '#ffffff',
    ],
];

require_once INCLUDES_DIR . '/ui-header.php';
?>

<style>
.step-indicator { transition: all 0.3s ease; }
.step-indicator.active { background: #009bc1; color: white; }
.step-indicator.completed { background: #009bc1; color: white; }
.step-indicator.pending { background: #e5e7eb; color: #6b7280; }

.wizard-step { display: none; }
.wizard-step.active { display: block; }

.template-card { transition: all 0.2s ease; cursor: pointer; }
.template-card:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,155,193,0.2); }
.template-card.selected { border-color: #009bc1; box-shadow: 0 0 0 3px rgba(0,155,193,0.2); }

.upload-zone { transition: all 0.2s ease; }
.upload-zone.drag-over { border-color: #009bc1; background: #f0f9ff; }

@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
.fade-in { animation: fadeIn 0.4s ease; }
</style>

<div class="min-h-screen bg-gradient-to-br from-slate-50 to-blue-50/30">

    <!-- Top Bar -->
    <div class="bg-white border-b border-gray-100 px-4 py-3">
        <div class="max-w-2xl mx-auto flex items-center justify-between">
            <a href="<?= getBasePath() ?>" class="flex items-center gap-2">
                <img src="<?= assetUrl('images/logo.svg') ?>" alt="Cardify" class="h-7 w-auto">
            </a>
            <?php if ($isBhd): ?>
            <div class="flex items-center gap-2 text-xs text-gray-500">
                <div class="w-5 h-5 rounded-full bg-blue-600 flex items-center justify-center">
                    <i class="fa-solid fa-print text-white" style="font-size: 8px;"></i>
                </div>
                <span class="font-medium text-blue-700"><?= htmlspecialchars(t('onboarding_home.bhd_partner_label')) ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="max-w-2xl mx-auto px-4 py-8">

        <!-- Progress Steps -->
        <div class="flex items-center justify-center mb-10" id="progress-steps">
            <div class="flex items-center gap-0">
                <?php $steps = [
                    ['icon' => 'fa-building', 'label' => t('onboarding_home.step_company_info')],
                    ['icon' => 'fa-image', 'label' => t('onboarding_home.step_logo')],
                    ['icon' => 'fa-palette', 'label' => t('onboarding_home.step_template')],
                ]; ?>
                <?php foreach ($steps as $i => $step): ?>
                <?php if ($i > 0): ?>
                <div class="w-12 h-0.5 bg-gray-200 step-connector" id="connector-<?= $i ?>"></div>
                <?php endif; ?>
                <div class="flex flex-col items-center">
                    <div class="step-indicator w-9 h-9 rounded-full flex items-center justify-center font-bold text-sm <?= $i === 0 ? 'active' : 'pending' ?>" id="step-indicator-<?= $i + 1 ?>">
                        <i class="fa-solid <?= $step['icon'] ?> text-xs"></i>
                    </div>
                    <span class="mt-1.5 text-xs font-medium <?= $i === 0 ? 'text-blue-600' : 'text-gray-400' ?>" id="step-label-<?= $i + 1 ?>"><?= $step['label'] ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Step 1: Company Info -->
        <div class="wizard-step active fade-in" id="wizard-step-1">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-8">
                <?php if ($isBhd): ?>
                <div class="flex items-center gap-3 mb-6 p-4 bg-blue-50 rounded-xl border border-blue-100">
                    <div class="w-10 h-10 rounded-full bg-blue-600 flex items-center justify-center flex-shrink-0">
                        <i class="fa-solid fa-handshake text-white"></i>
                    </div>
                    <div>
                        <p class="font-semibold text-blue-900 text-sm"><?= htmlspecialchars(t('onboarding_home.welcome_h1')) ?></p>
                        <p class="text-xs text-blue-600"><?= htmlspecialchars(t('onboarding_home.welcome_sub')) ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <h1 class="text-2xl font-bold text-gray-900 mb-1"><?= htmlspecialchars(t('onboarding_home.step1_h1')) ?></h1>
                <p class="text-gray-500 text-sm mb-8"><?= htmlspecialchars(t('onboarding_home.step1_sub')) ?></p>

                <form id="step1-form" class="space-y-5">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            <?= htmlspecialchars(t('onboarding_home.field_phone')) ?> <span class="text-gray-400 font-normal"><?= htmlspecialchars(t('onboarding_home.optional_tag')) ?></span>
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fa-solid fa-phone text-gray-400 text-sm"></i>
                            </div>
                            <input type="tel" id="company_phone" name="company_phone"
                                   placeholder="<?= htmlspecialchars(t('onboarding_home.ph_phone')) ?>"
                                   class="w-full pl-10 pr-4 py-3 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 outline-none transition-all">
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            <?= htmlspecialchars(t('onboarding_home.field_website')) ?> <span class="text-gray-400 font-normal"><?= htmlspecialchars(t('onboarding_home.optional_tag')) ?></span>
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fa-solid fa-globe text-gray-400 text-sm"></i>
                            </div>
                            <input type="url" id="company_website" name="company_website"
                                   placeholder="<?= htmlspecialchars(t('onboarding_home.ph_website')) ?>"
                                   class="w-full pl-10 pr-4 py-3 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 outline-none transition-all">
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            <?= htmlspecialchars(t('onboarding_home.field_tagline')) ?> <span class="text-gray-400 font-normal"><?= htmlspecialchars(t('onboarding_home.optional_tag')) ?></span>
                        </label>
                        <input type="text" id="company_tagline" name="company_tagline"
                               placeholder="<?= htmlspecialchars(t('onboarding_home.ph_tagline')) ?>"
                               maxlength="100"
                               class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 outline-none transition-all">
                    </div>

                    <div class="pt-2">
                        <button type="button" onclick="goToStep(2)"
                                class="w-full py-3.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl transition-all flex items-center justify-center gap-2">
                            <?= htmlspecialchars(t('onboarding_home.continue')) ?>
                            <i class="fa-solid fa-arrow-right"></i>
                        </button>
                        <button type="button" onclick="skipOnboarding()"
                                class="w-full mt-3 py-2.5 text-sm text-gray-500 hover:text-gray-700 transition-colors">
                            <?= htmlspecialchars(t('onboarding_home.skip_dashboard')) ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Step 2: Logo Upload -->
        <div class="wizard-step" id="wizard-step-2">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-8">
                <h1 class="text-2xl font-bold text-gray-900 mb-1"><?= htmlspecialchars(t('onboarding_home.step2_h1')) ?></h1>
                <p class="text-gray-500 text-sm mb-8"><?= htmlspecialchars(t('onboarding_home.step2_sub')) ?></p>

                <!-- Upload Zone -->
                <div class="upload-zone border-2 border-dashed border-gray-200 rounded-2xl p-10 text-center mb-6 cursor-pointer"
                     id="upload-zone"
                     onclick="document.getElementById('logo-input').click()"
                     ondragover="event.preventDefault(); this.classList.add('drag-over')"
                     ondragleave="this.classList.remove('drag-over')"
                     ondrop="handleLogoDrop(event)">

                    <div id="upload-placeholder">
                        <div class="w-16 h-16 rounded-full bg-blue-50 flex items-center justify-center mx-auto mb-4">
                            <i class="fa-solid fa-cloud-arrow-up text-2xl text-blue-400"></i>
                        </div>
                        <p class="text-gray-700 font-semibold mb-1"><?= htmlspecialchars(t('onboarding_home.drop_logo')) ?></p>
                        <p class="text-gray-400 text-sm"><?= htmlspecialchars(t('onboarding_home.or_click')) ?></p>
                        <p class="text-gray-300 text-xs mt-2"><?= htmlspecialchars(t('onboarding_home.file_hint')) ?></p>
                    </div>

                    <div id="upload-preview" class="hidden">
                        <img id="logo-preview-img" src="" alt="Logo preview"
                             class="max-h-32 max-w-xs mx-auto rounded-lg shadow-sm mb-3">
                        <p class="text-sm text-green-600 font-medium">
                            <i class="fa-solid fa-circle-check mr-1"></i>
                            <span id="logo-filename"><?= htmlspecialchars(t('onboarding_home.logo_uploaded')) ?></span>
                        </p>
                        <button type="button" onclick="event.stopPropagation(); resetLogo()"
                                class="mt-2 text-xs text-gray-400 hover:text-gray-600 underline">
                            <?= htmlspecialchars(t('onboarding_home.change_logo')) ?>
                        </button>
                    </div>
                </div>
                <input type="file" id="logo-input" accept="image/png,image/jpeg,image/jpg,image/svg+xml,image/gif,image/webp" class="hidden" onchange="handleLogoSelect(event)">

                <div class="flex gap-3">
                    <button type="button" onclick="goToStep(1)"
                            class="flex-1 py-3 border border-gray-200 text-gray-600 font-semibold rounded-xl hover:bg-gray-50 transition-all">
                        <i class="fa-solid fa-arrow-left mr-1"></i> <?= htmlspecialchars(t('onboarding_home.back')) ?>
                    </button>
                    <button type="button" onclick="goToStep(3)"
                            class="flex-2 flex-grow py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl transition-all flex items-center justify-center gap-2">
                        <?= htmlspecialchars(t('onboarding_home.continue')) ?>
                        <i class="fa-solid fa-arrow-right"></i>
                    </button>
                </div>
                <button type="button" onclick="goToStep(3)"
                        class="w-full mt-3 py-2 text-sm text-gray-400 hover:text-gray-600 transition-colors">
                    <?= htmlspecialchars(t('onboarding_home.skip_for_now')) ?>
                </button>
            </div>
        </div>

        <!-- Step 3: Pick Template -->
        <div class="wizard-step" id="wizard-step-3">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-8">
                <h1 class="text-2xl font-bold text-gray-900 mb-1"><?= htmlspecialchars(t('onboarding_home.step3_h1')) ?></h1>
                <p class="text-gray-500 text-sm mb-8">
                    <?= htmlspecialchars($isBhd ? t('onboarding_home.step3_sub_bhd') : t('onboarding_home.step3_sub_general')) ?>
                </p>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8" id="template-grid">
                    <?php foreach ($bhdTemplates as $tplKey => $tpl): ?>
                    <div class="template-card rounded-xl border-2 border-gray-100 overflow-hidden"
                         id="tpl-card-<?= $tplKey ?>"
                         onclick="selectTemplate('<?= $tplKey ?>')">
                        <!-- Card Preview -->
                        <div class="<?= $tpl['preview_bg'] ?> p-4 aspect-[1.75/1] flex flex-col justify-between relative overflow-hidden" style="background-color: <?= $tpl['bg'] ?>;">
                            <!-- Decorative accent bar -->
                            <div class="absolute top-0 left-0 w-1 h-full" style="background: <?= $tpl['accentColor'] ?>; opacity: 0.8;"></div>
                            <div class="pl-3">
                                <div class="h-2 rounded w-16 mb-1.5" style="background: <?= $tpl['textColor'] ?>; opacity: 0.9;"></div>
                                <div class="h-1.5 rounded w-10" style="background: <?= $tpl['accentColor'] ?>; opacity: 0.7;"></div>
                            </div>
                            <div class="pl-3 space-y-1">
                                <div class="h-1 rounded w-20" style="background: <?= $tpl['textColor'] ?>; opacity: 0.4;"></div>
                                <div class="h-1 rounded w-14" style="background: <?= $tpl['textColor'] ?>; opacity: 0.4;"></div>
                            </div>
                            <!-- Selected checkmark -->
                            <div class="absolute top-2 right-2 w-5 h-5 rounded-full bg-blue-500 items-center justify-center hidden" id="check-<?= $tplKey ?>">
                                <i class="fa-solid fa-check text-white text-xs"></i>
                            </div>
                        </div>
                        <!-- Card Name -->
                        <div class="p-3 bg-white">
                            <p class="font-semibold text-gray-800 text-sm"><?= $tpl['name'] ?></p>
                            <p class="text-xs text-gray-400 mt-0.5"><?= $tpl['description'] ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <input type="hidden" id="selected-template" value="bhd-classic">

                <div class="flex gap-3">
                    <button type="button" onclick="goToStep(2)"
                            class="flex-1 py-3 border border-gray-200 text-gray-600 font-semibold rounded-xl hover:bg-gray-50 transition-all">
                        <i class="fa-solid fa-arrow-left mr-1"></i> <?= htmlspecialchars(t('onboarding_home.back')) ?>
                    </button>
                    <button type="button" onclick="completeOnboarding()"
                            id="complete-btn"
                            class="flex-2 flex-grow py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl transition-all flex items-center justify-center gap-2">
                        <i class="fa-solid fa-rocket"></i>
                        <?= htmlspecialchars(t('onboarding_home.launch_btn')) ?>
                    </button>
                </div>
            </div>
        </div>

        <!-- Completing Step (hidden, shown during processing) -->
        <div class="wizard-step" id="wizard-step-complete">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-12 text-center">
                <div class="w-20 h-20 rounded-full bg-green-50 flex items-center justify-center mx-auto mb-6">
                    <i class="fa-solid fa-circle-check text-4xl text-green-500"></i>
                </div>
                <h2 class="text-2xl font-bold text-gray-900 mb-2"><?= htmlspecialchars(t('onboarding_home.complete_h2')) ?></h2>
                <p class="text-gray-500 mb-2"><?= htmlspecialchars(t('onboarding_home.complete_sub')) ?></p>
                <div class="flex justify-center">
                    <div class="w-6 h-6 border-2 border-blue-500 border-t-transparent rounded-full animate-spin"></div>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
var currentStep = 1;
var selectedTemplate = 'bhd-classic';
var logoFile = null;

// Select a template
function selectTemplate(key) {
    selectedTemplate = key;
    document.getElementById('selected-template').value = key;

    // Update card styles
    document.querySelectorAll('.template-card').forEach(function(card) {
        card.classList.remove('selected');
        card.style.borderColor = '';
    });
    document.querySelectorAll('[id^="check-"]').forEach(function(el) {
        el.classList.add('hidden');
        el.classList.remove('flex');
    });

    var card = document.getElementById('tpl-card-' + key);
    if (card) {
        card.classList.add('selected');
    }
    var check = document.getElementById('check-' + key);
    if (check) {
        check.classList.remove('hidden');
        check.classList.add('flex');
    }
}

// Initialize with default selection
selectTemplate('bhd-classic');

// Step navigation
function goToStep(n) {
    document.getElementById('wizard-step-' + currentStep).classList.remove('active');
    document.getElementById('wizard-step-' + n).classList.add('active');
    document.getElementById('wizard-step-' + n).classList.add('fade-in');

    // Update step indicators
    for (var i = 1; i <= 3; i++) {
        var ind = document.getElementById('step-indicator-' + i);
        var label = document.getElementById('step-label-' + i);
        if (i < n) {
            ind.className = ind.className.replace(/\b(active|pending)\b/g, 'completed');
            if (label) label.className = label.className.replace('text-gray-400', 'text-blue-600');
        } else if (i === n) {
            ind.className = ind.className.replace(/\b(pending|completed)\b/g, 'active');
            if (label) label.className = label.className.replace('text-gray-400', 'text-blue-600');
        } else {
            ind.className = ind.className.replace(/\b(active|completed)\b/g, 'pending');
            if (label) label.className = label.className.replace('text-blue-600', 'text-gray-400');
        }
    }

    currentStep = n;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// Logo handling
function handleLogoSelect(event) {
    var file = event.target.files[0];
    if (file) setLogo(file);
}

function handleLogoDrop(event) {
    event.preventDefault();
    document.getElementById('upload-zone').classList.remove('drag-over');
    var file = event.dataTransfer.files[0];
    if (file && file.type.startsWith('image/')) setLogo(file);
}

function setLogo(file) {
    logoFile = file;
    var reader = new FileReader();
    reader.onload = function(e) {
        document.getElementById('logo-preview-img').src = e.target.result;
        document.getElementById('logo-filename').textContent = file.name;
        document.getElementById('upload-placeholder').classList.add('hidden');
        document.getElementById('upload-preview').classList.remove('hidden');
    };
    reader.readAsDataURL(file);
}

function resetLogo() {
    logoFile = null;
    document.getElementById('logo-input').value = '';
    document.getElementById('upload-placeholder').classList.remove('hidden');
    document.getElementById('upload-preview').classList.add('hidden');
}

// Complete onboarding
function completeOnboarding() {
    var btn = document.getElementById('complete-btn');
    btn.disabled = true;
    btn.innerHTML = '<div class="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin"></div> ' + <?= json_encode(t('onboarding_home.setting_up')) ?>;

    var formData = new FormData();
    formData.append('action', 'complete_onboarding');
    formData.append('phone', document.getElementById('company_phone').value);
    formData.append('website', document.getElementById('company_website').value);
    formData.append('tagline', document.getElementById('company_tagline').value);
    formData.append('template', selectedTemplate);
    formData.append('source', '<?= htmlspecialchars($source) ?>');
    formData.append('csrf_token', '<?= generateCSRFToken() ?>');
    if (logoFile) {
        formData.append('logo', logoFile);
    }

    fetch('<?= getBasePath() ?>api/onboarding.php', {
        method: 'POST',
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            // Show success screen then redirect
            goToStep('complete');
            setTimeout(function() {
                window.location.href = data.redirect || '<?= htmlspecialchars(getTenantUrl($companySlug, '/admin/'), ENT_QUOTES, 'UTF-8') ?>';
            }, 1500);
        } else {
            // Even on error, redirect to admin
            window.location.href = '<?= htmlspecialchars(getTenantUrl($companySlug, '/admin/'), ENT_QUOTES, 'UTF-8') ?>';
        }
    })
    .catch(function() {
        window.location.href = '<?= htmlspecialchars(getTenantUrl($companySlug, '/admin/'), ENT_QUOTES, 'UTF-8') ?>';
    });
}

// Skip onboarding entirely, wait for API to mark complete before redirecting
function skipOnboarding() {
    var formData = new FormData();
    formData.append('action', 'skip_onboarding');
    formData.append('csrf_token', '<?= generateCSRFToken() ?>');
    var adminUrl = '<?= htmlspecialchars(getTenantUrl($companySlug, '/admin/'), ENT_QUOTES, 'UTF-8') ?>';
    fetch('<?= getBasePath() ?>api/onboarding.php', { method: 'POST', body: formData })
        .finally(function() { window.location.href = adminUrl; });
}
</script>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
