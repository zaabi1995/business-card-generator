<?php
// Always serve fresh, the wizard's JS evolves quickly during onboarding.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
/**
 * 3-step onboarding wizard. First-login redirect brings a fresh tenant
 * here; admins can also land on /admin/onboarding manually to pick up
 * where they left off.
 *
 * All step data persists to company_onboarding via Onboarding::saveStep()
 * through admin/onboarding-save.php (POST, CSRF-guarded).
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';
require_once INCLUDES_DIR . '/Onboarding.php';
require_once INCLUDES_DIR . '/CardLayouts.php';
require_once INCLUDES_DIR . '/CardPrintPricing.php';
require_once INCLUDES_DIR . '/DemoData.php';

requireAdmin();
$companyId = getCurrentCompanyId();
if (!$companyId) {
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

$state = Onboarding::get($companyId);
if (!empty($state['completed_at'])) {
    // Already done, send to dashboard with a small toast.
    header('Location: ' . getAdminBasePath() . 'index.php?wizard=already_done');
    exit;
}

// First visit (no step data, no employees): seed 5 placeholder employees
// so the admin has something to play with outside the wizard. The seeded
// ids are stored on company_onboarding.data.demo_employee_ids so the
// dashboard "Clear demo data" button knows exactly which rows to remove.
if (DemoData::shouldSeed($companyId)) {
    DemoData::seed($companyId);
}

$company  = null;
try {
    $db = Database::getInstance();
    $company = $db->fetchOne("SELECT id, name, slug FROM companies WHERE id = :id", ['id' => $companyId]);
} catch (Throwable $e) { /* fall through to empty company */ }
$companyName = ($company['name'] ?? '') !== '' ? $company['name'] : 'your company';
$companySlug = $company['slug'] ?? '';

$initialStep = max(1, min(Onboarding::TOTAL_STEPS, (int)$state['step']));
$initialData = $state['data'] ?? [];

// Pre-render Fabric.js-style card previews for step 3 template picker using
// step-2 colors and step-4 employee data. The wizard's three options map to
// CardLayouts methods: minimal -> modern, bold -> corporate, classic ->
// classic. Previews use current state if present, or sensible placeholders
// so new tenants see rendered cards on first visit.
$colorsData = $initialData['colors'] ?? [];
$employeeData = $initialData['first_employee'] ?? [];
$logoData = $initialData['logo'] ?? [];
$previewEmployee = [
    'name_en'     => $employeeData['name']  ?: ('Sample ' . ($companyName !== 'your company' ? substr($companyName, 0, 20) : 'Employee')),
    'position_en' => $employeeData['title'] ?: 'Your job title',
    'phone'       => $employeeData['phone'] ?: '+968 9000 0000',
    'email'       => $employeeData['email'] ?: 'name@example.com',
];
$previewTheme = [
    'primary_color'   => $colorsData['primary'] ?? '#009bc1',
    'secondary_color' => $colorsData['accent']  ?? '#824598',
    'logo_path'       => !empty($logoData['url']) && strncmp($logoData['url'], 'data:', 5) === 0 ? $logoData['url'] : '',
];
$previewCompany = ['name' => $companyName];
$previewLayoutMap = ['minimal' => 'modern', 'bold' => 'corporate', 'classic' => 'classic'];
$previewRenders = [];
foreach ($previewLayoutMap as $tplKey => $layoutId) {
    try {
        $previewRenders[$tplKey] = CardLayouts::renderFront($layoutId, $previewEmployee, $previewCompany, $previewTheme);
    } catch (Throwable $e) {
        $previewRenders[$tplKey] = '';
    }
}

$csrf = generateCSRFToken();
$saveUrl = getAdminBasePath() . 'onboarding-save' . ((defined('COMPANY_ADMIN_BASE') || !empty($_SESSION['company_slug'])) ? '' : '.php');
$dashboardUrl = getAdminBasePath() . 'index' . ((defined('COMPANY_ADMIN_BASE') || !empty($_SESSION['company_slug'])) ? '' : '.php');
$printUrl     = getAdminBasePath() . 'print' . ((defined('COMPANY_ADMIN_BASE') || !empty($_SESSION['company_slug'])) ? '' : '.php');

adminHeader(t('onboarding.welcome_title', ['name' => $companyName]), 'onboarding');
?>
<style>
    .wizard-step[x-cloak] { display: none !important; }
    .wizard-progress-dot { width: 32px; height: 32px; border-radius: 9999px; display:flex; align-items:center; justify-content:center; font-weight:600; flex-shrink: 0; }
    .wizard-progress-dot.done { background: #16a34a; color:#fff; }
    .wizard-progress-dot.active { background: var(--cardify-primary, #009bc1); color:#fff; box-shadow: 0 0 0 4px rgba(0,155,193,0.2); }
    .wizard-progress-dot.pending { background: #e5e7eb; color:#6b7280; }
    .wizard-progress-line { flex:1; height:3px; background: #e5e7eb; min-width: 8px; }
    .wizard-progress-line.done { background: #16a34a; }

    /* Mobile QA: 375/414 viewport safeguards */
    .wizard-shell { overflow-x: hidden; }
    .wizard-nav-btn { min-height: 44px; }
    .wizard-color-row { flex-wrap: wrap; }
    @media (max-width: 480px) {
        .wizard-progress-dot { width: 26px; height: 26px; font-size: 0.75rem; box-shadow: none !important; }
        .wizard-progress-dot.active { box-shadow: 0 0 0 3px rgba(0,155,193,0.25) !important; }
        .wizard-progress-line { min-width: 4px; height: 2px; }
        .wizard-color-row input[type="color"] { width: 3rem; }
        .wizard-color-row input[type="text"] { min-width: 0; }
    }
</style>

<div class="max-w-3xl mx-auto"
     x-data='onboarding(<?= json_encode([
         "step" => $initialStep,
         "data" => $initialData,
         "totalSteps" => Onboarding::TOTAL_STEPS,
         "csrf" => $csrf,
         "saveUrl" => $saveUrl,
         "dashboardUrl" => $dashboardUrl,
         "printUrl" => $printUrl,
         "companyName" => $companyName,
         "companySlug" => $companySlug,
     ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
     x-init="init()">

    <!-- Header -->
    <div class="text-center mb-6">
        <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 mb-2">
            <?= htmlspecialchars(t('onboarding.welcome_title', ['name' => $companyName])) ?>
        </h1>
        <p class="text-gray-500"><?= htmlspecialchars(t('onboarding.welcome_subtitle')) ?></p>
        <p class="text-xs text-gray-400 mt-2" x-text="stepOfLabel()"></p>
    </div>

    <!-- Progress -->
    <div class="flex items-center gap-1 mb-8 max-w-2xl mx-auto px-2">
        <template x-for="(n, idx) in totalSteps" :key="n">
            <div class="flex items-center flex-1" :class="idx === totalSteps - 1 ? 'flex-none' : ''">
                <div class="wizard-progress-dot"
                     :class="n < step ? 'done' : (n === step ? 'active' : 'pending')">
                    <span x-show="n < step"><i class="fa-solid fa-check"></i></span>
                    <span x-show="n >= step" x-text="n"></span>
                </div>
                <div class="wizard-progress-line" x-show="idx < totalSteps - 1"
                     :class="n < step ? 'done' : ''"></div>
            </div>
        </template>
    </div>

    <!-- Steps -->
    <div class="wizard-shell bg-white border border-gray-200 rounded-2xl shadow-sm p-4 sm:p-8">

        <!-- Step 1: Logo -->
        <div class="wizard-step" x-show="step === 1" x-cloak>
            <h2 class="text-xl font-bold text-gray-900 mb-1"><?= htmlspecialchars(t('onboarding.step_logo')) ?></h2>
            <p class="text-sm text-gray-500 mb-6"><?= htmlspecialchars(t('onboarding.step_logo_help')) ?></p>

            <label class="block border-2 border-dashed border-gray-300 rounded-xl p-8 text-center cursor-pointer hover:border-blue-400 transition">
                <template x-if="data.logo && data.logo.url">
                    <div class="space-y-3">
                        <img :src="data.logo.url" alt="Logo preview" class="max-h-28 mx-auto">
                        <p class="text-sm text-gray-500"><?= htmlspecialchars(t('onboarding.logo_change')) ?></p>
                    </div>
                </template>
                <template x-if="!(data.logo && data.logo.url)">
                    <div class="space-y-3">
                        <i class="fa-solid fa-cloud-arrow-up text-4xl text-gray-300"></i>
                        <p class="text-sm text-gray-600"><?= htmlspecialchars(t('onboarding.logo_drag_drop')) ?></p>
                        <p class="text-xs text-gray-400"><?= htmlspecialchars(t('onboarding.logo_upload_hint')) ?></p>
                    </div>
                </template>
                <input type="file" accept="image/png,image/svg+xml,image/jpeg" class="hidden"
                       @change="handleLogo($event)">
            </label>
        </div>

        <!-- Step 2: Card design (PDF) -->
        <div class="wizard-step" x-show="step === 2" x-cloak>
            <!-- 2a: dropzone (no review yet) -->
            <div x-show="!cardPdfReview">
                <h2 class="text-xl font-bold text-gray-900 mb-1">Upload your card design (PDF)</h2>
                <p class="text-sm text-gray-500 mb-2">Cardify reads the layout, fonts and QR area automatically, then asks you to confirm what each text block represents. Skip if you don't have one yet.</p>
                <a href="<?= htmlspecialchars(getBasePath() . 'uploads/docs/Cardify-PDF-Design-Guide.pdf') ?>" target="_blank" class="inline-flex items-center gap-1 text-xs text-blue-600 hover:text-blue-700 font-semibold mb-5">
                    <i class="fa-solid fa-circle-info text-xs"></i> How to prepare your PDF
                </a>

                <div id="card-pdf-error" class="hidden bg-red-50 border-l-4 border-red-500 px-4 py-2 rounded mb-4 text-sm text-red-700"></div>

                <label for="card-pdf-input" id="card-pdf-zone"
                    tabindex="0" role="button"
                    class="block border-2 border-dashed border-gray-200 hover:border-blue-400 focus-within:border-blue-500 rounded-2xl p-8 text-center cursor-pointer transition">
                    <div id="card-pdf-empty">
                        <div class="w-12 h-12 rounded-full bg-blue-50 flex items-center justify-center mx-auto mb-3">
                            <i class="fa-solid fa-file-pdf text-lg text-blue-600"></i>
                        </div>
                        <p class="text-gray-700 font-semibold text-sm">Drop your business card PDF</p>
                        <p class="text-gray-400 text-xs mt-1">2 pages preferred (front + back) · 25 MB max</p>
                    </div>
                    <div id="card-pdf-loaded" class="hidden">
                        <p id="card-pdf-status" class="text-sm" role="status"></p>
                        <div id="card-pdf-summary" class="text-xs text-gray-500 mt-2"></div>
                    </div>
                </label>
                <input type="file" id="card-pdf-input" accept="application/pdf,.pdf" class="hidden">

                <p class="text-xs text-gray-400 mt-4">
                    <i class="fa-solid fa-shield-halved mr-1 text-green-600"></i>
                    Skip is fine, you can upload a PDF later from the template editor.
                </p>
            </div>

            <!-- 2b: review (parser ran, user confirms each text block) -->
            <template x-if="cardPdfReview">
            <div x-cloak>
                <div class="flex items-start justify-between gap-3 mb-3">
                    <div>
                        <h2 class="text-xl font-bold text-gray-900 mb-1">Match each text block to a Cardify field</h2>
                        <p class="text-sm text-gray-500">Click a block on the design (or use the list) and tell us what it represents. Anything left as <span class="font-semibold text-gray-700">Decoration</span> stays exactly as it is on every card.</p>
                    </div>
                    <button type="button" @click="resetCardPdf()" class="text-xs text-gray-500 hover:text-red-600 underline whitespace-nowrap">
                        <i class="fa-solid fa-rotate-left mr-1"></i> Upload different PDF
                    </button>
                </div>

                <template x-for="page in cardPdfReview.pages" :key="page.page_number">
                    <div class="mb-6">
                        <div class="flex items-center justify-between mb-2">
                            <div class="text-xs uppercase tracking-wider font-semibold text-gray-500">
                                <span x-text="page.side === 'front' ? 'Front' : 'Back'"></span>
                                <span class="text-gray-300 ml-2">page <span x-text="page.page_number"></span></span>
                            </div>
                            <div class="text-xs text-gray-400">
                                <span x-text="page.blocks.length"></span> text block<span x-show="page.blocks.length !== 1">s</span>
                            </div>
                        </div>

                        <!-- Card preview with clickable block overlays.
                             Renders bg-with-text so the user can see their actual
                             design. Block chips are outline-only to avoid
                             obscuring the rendered text underneath; the detected
                             text + font + size is shown in the list below. -->
                        <div class="card-preview-wrap relative bg-gray-100 rounded-xl overflow-hidden border border-gray-200"
                             :style="'aspect-ratio: ' + page.width_px + ' / ' + page.height_px">
                            <img :src="page.background_with_text_url"
                                 alt="Imported card"
                                 class="absolute inset-0 w-full h-full object-contain">

                            <!-- QR area: detected QR code that Cardify will replace
                                 per-employee with a tracking QR (vCard download +
                                 scan analytics). Only shown if the parser found one.
                                 Border surrounds the QR; the label floats ABOVE the
                                 box so it never covers the QR pixels themselves. -->
                            <template x-if="page.qr_area">
                                <div class="absolute pointer-events-none z-20"
                                     :style="
                                         'left:'   + (page.qr_area.x_pt * 100 / page.width_pt)  + '%;' +
                                         'top:'    + (page.qr_area.y_pt * 100 / page.height_pt) + '%;' +
                                         'width:'  + (page.qr_area.w_pt * 100 / page.width_pt)  + '%;' +
                                         'height:' + (page.qr_area.h_pt * 100 / page.height_pt) + '%;'
                                     ">
                                    <!-- Outline only, no inner fill, so the QR underneath stays fully visible -->
                                    <div class="absolute inset-0 border-2 border-dashed border-fuchsia-500 rounded-md"></div>
                                    <!-- Floating badge sitting just above the box, anchored top-left -->
                                    <span class="absolute -top-5 left-0 bg-fuchsia-600 text-white text-[9px] font-bold px-1.5 py-0.5 rounded shadow whitespace-nowrap">
                                        <i class="fa-solid fa-qrcode mr-0.5"></i>QR &rarr; Cardify
                                    </span>
                                </div>
                            </template>

                            <template x-for="b in page.blocks" :key="b.id">
                                <button type="button"
                                        @click="focusBlock(page.page_number, b.id)"
                                        :class="[
                                            'absolute border-2 rounded-md transition cursor-pointer',
                                            isFocused(page.page_number, b.id) ? 'border-blue-500 ring-2 ring-blue-200 z-10 bg-blue-100/20' :
                                                bindingFor(page.page_number, b.id) === 'static' ? 'border-amber-400/80 hover:border-amber-500 hover:bg-amber-100/10' :
                                                bindingFor(page.page_number, b.id) === 'skip'   ? 'border-red-300/80 opacity-60 hover:border-red-500' :
                                                                                                  'border-blue-400/80 hover:border-blue-600 hover:bg-blue-100/10'
                                        ]"
                                        :style="
                                            'left:'   + (b.x      * 100 / page.width_px)  + '%;' +
                                            'top:'    + (b.y      * 100 / page.height_px) + '%;' +
                                            'width:'  + (b.width  * 100 / page.width_px)  + '%;' +
                                            'height:' + (b.height * 100 / page.height_px) + '%;'
                                        "
                                        :aria-label="b.detected_text + ' - ' + bindingLabelShort(bindingFor(page.page_number, b.id))"></button>
                            </template>
                        </div>

                        <!-- List of every block with bind dropdown. Sticky list mirrors overlays so the user can
                             change a binding even when the overlay is tiny. -->
                        <div class="mt-3 divide-y divide-gray-100 border border-gray-100 rounded-lg overflow-hidden">
                            <template x-for="b in page.blocks" :key="'list-' + b.id">
                                <div class="flex items-center gap-3 px-3 py-2 transition"
                                     :class="isFocused(page.page_number, b.id) ? 'bg-blue-50' : 'bg-white hover:bg-gray-50'"
                                     @click="focusBlock(page.page_number, b.id)">
                                    <div class="flex-1 min-w-0">
                                        <div class="text-sm text-gray-800 truncate" x-text="b.detected_text" dir="auto"></div>
                                        <div class="text-[11px] text-gray-400">
                                            <span x-text="b.font_family"></span>
                                            <span x-show="b.font_size_pt"> · <span x-text="(b.font_size_pt || 0).toFixed(1)"></span>pt</span>
                                            <span class="inline-block w-3 h-3 rounded ml-2 align-middle border border-gray-300" :style="'background:' + b.color"></span>
                                        </div>
                                    </div>
                                    <select class="form-input text-xs py-1 px-2 w-44"
                                            :value="bindingFor(page.page_number, b.id)"
                                            @change="setBinding(page.page_number, b.id, $event.target.value)">
                                        <template x-for="opt in bindingOptions" :key="opt.value">
                                            <option :value="opt.value" x-text="opt.label" :selected="bindingFor(page.page_number, b.id) === opt.value"></option>
                                        </template>
                                    </select>
                                </div>
                            </template>
                            <div x-show="page.blocks.length === 0" class="px-3 py-4 text-xs text-gray-400 text-center">
                                No text blocks detected on this page. The background image will still be used.
                            </div>
                        </div>
                    </div>
                </template>

                <div class="text-xs text-gray-500 bg-blue-50 border border-blue-100 rounded-lg px-3 py-2">
                    <i class="fa-solid fa-circle-info text-blue-500 mr-1"></i>
                    Anything you leave as <strong>Decoration</strong> stays fixed on every card (e.g. brand taglines, social handles).
                    Anything you bind to a Cardify field becomes a per-employee placeholder.
                </div>

                <!-- Missing fonts: source PDF references font faces we don't have on the
                     server. Without the actual face the browser uses the nearest weight,
                     so e.g. a Lato-Medium tagline reads slightly thinner than the source.
                     Drop the .woff2 / .ttf / .otf and Cardify wires it in. -->
                <template x-if="cardPdfReview.missing_fonts && cardPdfReview.missing_fonts.length">
                    <div class="mt-4 border border-amber-200 bg-amber-50 rounded-lg p-3">
                        <div class="flex items-start gap-2">
                            <i class="fa-solid fa-triangle-exclamation text-amber-600 mt-0.5"></i>
                            <div class="flex-1">
                                <p class="text-sm font-semibold text-amber-900">Some fonts are not on the server yet</p>
                                <p class="text-xs text-amber-700">Upload the actual .woff2 / .ttf / .otf files so your card renders in the same face as your PDF (otherwise the closest available weight is used).</p>
                            </div>
                        </div>
                        <div class="mt-3 space-y-2">
                            <template x-for="mf in cardPdfReview.missing_fonts" :key="mf.raw_name">
                                <div class="flex items-center gap-3 bg-white rounded px-3 py-2 border border-amber-100">
                                    <div class="flex-1 min-w-0">
                                        <div class="text-sm font-mono text-gray-800 truncate" x-text="mf.raw_name"></div>
                                        <div class="text-[11px] text-gray-500">family <span class="font-semibold" x-text="mf.family"></span></div>
                                    </div>
                                    <span x-show="fontUploadStatus[mf.raw_name] === 'done'"
                                          class="text-xs text-green-700 font-semibold whitespace-nowrap">
                                        <i class="fa-solid fa-circle-check mr-1"></i> Uploaded
                                    </span>
                                    <label x-show="fontUploadStatus[mf.raw_name] !== 'done'"
                                           class="text-xs px-3 py-1.5 rounded bg-amber-600 hover:bg-amber-700 text-white font-medium cursor-pointer whitespace-nowrap">
                                        <span x-show="fontUploadStatus[mf.raw_name] !== 'uploading'"><i class="fa-solid fa-upload mr-1"></i> Upload</span>
                                        <span x-show="fontUploadStatus[mf.raw_name] === 'uploading'"><i class="fa-solid fa-spinner fa-spin mr-1"></i> Uploading…</span>
                                        <input type="file" class="hidden" accept=".woff2,.woff,.ttf,.otf"
                                               @change="uploadMissingFont(mf, $event.target.files[0])">
                                    </label>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                <div id="card-pdf-persist-error" class="hidden bg-red-50 border-l-4 border-red-500 px-4 py-2 rounded mt-3 text-sm text-red-700"></div>
            </div>
            </template>
        </div>

        <!-- Step 3: Preview / Launch -->
        <div class="wizard-step" x-show="step === 3" x-cloak>
            <h2 class="text-xl font-bold text-gray-900 mb-1"><?= htmlspecialchars(t('onboarding.step_preview')) ?></h2>
            <p class="text-sm text-gray-500 mb-6"><?= htmlspecialchars(t('onboarding.step_preview_help')) ?></p>

            <div class="rounded-xl p-6 mb-5 text-white"
                 :style="'background: linear-gradient(135deg,' + (data.colors.primary || '#009bc1') + ',' + (data.colors.accent || '#824598') + ')'">
                <div class="text-lg font-bold" x-text="data.first_employee.name || companyName"></div>
                <div class="text-sm opacity-90" x-text="data.first_employee.title"></div>
                <div class="mt-4 text-xs opacity-80" x-text="data.first_employee.email" dir="ltr"></div>
                <div class="text-xs opacity-80" x-text="data.first_employee.phone" dir="ltr"></div>
            </div>

            <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1"><?= htmlspecialchars(t('onboarding.card_url_label')) ?></label>
            <div class="flex gap-2">
                <input type="text" readonly :value="previewUrl()" class="form-input flex-1 font-mono text-sm" dir="ltr">
                <button type="button" @click="copyUrl()"
                        class="px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium"
                        :class="copied ? 'bg-green-600 hover:bg-green-600' : ''">
                    <span x-text="copied ? <?= htmlspecialchars(json_encode(t('onboarding.copied')), ENT_QUOTES) ?> : <?= htmlspecialchars(json_encode(t('onboarding.copy')), ENT_QUOTES) ?>"></span>
                </button>
            </div>
        </div>

        <!-- Nav -->
    <div class="flex items-center justify-between mt-6">
        <button type="button" @click="back()" x-show="step > 1"
                class="wizard-nav-btn px-4 py-2 text-gray-600 hover:text-gray-900 font-medium">
            <i class="fa-solid fa-arrow-left mr-1"></i>
            <?= htmlspecialchars(t('onboarding.nav_back')) ?>
        </button>
        <div x-show="step === 1"></div>

        <div class="flex items-center gap-3">
            <span class="hidden sm:inline text-xs text-gray-400 mr-2"><?= htmlspecialchars(t('onboarding.kbd_hint')) ?></span>
            <button type="button" @click="skipForNow()" class="wizard-nav-btn px-3 text-sm text-gray-500 hover:text-gray-700">
                <?= htmlspecialchars(t('onboarding.skip_for_now')) ?>
            </button>
            <button type="button" @click="next()"
                    class="wizard-nav-btn px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium">
                <span x-show="saving"><i class="fa-solid fa-spinner fa-spin mr-1"></i><?= htmlspecialchars(t('onboarding.saving')) ?></span>
                <span x-show="!saving">
                    <span x-text="step < totalSteps ? <?= htmlspecialchars(json_encode(t('onboarding.nav_next')), ENT_QUOTES) ?> : <?= htmlspecialchars(json_encode(t('onboarding.finish')), ENT_QUOTES) ?>"></span>
                    <i class="fa-solid fa-arrow-right ml-1"></i>
                </span>
            </button>
        </div>
    </div>
</div>

<script>
function onboarding(init) {
    return {
        step: init.step,
        totalSteps: init.totalSteps,
        csrf: init.csrf,
        saveUrl: init.saveUrl,
        dashboardUrl: init.dashboardUrl,
        printUrl: init.printUrl,
        companyName: init.companyName,
        companySlug: init.companySlug,
        saving: false,
        copied: false,

        data: {
            logo:  init.data.logo  || {},
            colors: {
                primary: (init.data.colors && init.data.colors.primary) || '#009bc1',
                accent:  (init.data.colors && init.data.colors.accent)  || '#824598',
            },
            template: init.data.template || 'minimal',
            first_employee: Object.assign({name:'',title:'',email:'',phone:''}, init.data.first_employee || {}),
            preview: init.data.preview || {},
            invite_team: Object.assign({paste:'',csv:null}, init.data.invite_team || {}),
            order_cards: Object.assign({per_person: 100}, init.data.order_cards || {}),
        },

        stepLabels: { 1: 'Brand', 2: 'Card design', 3: 'Launch' },
        cardPdfReady: false,

        // Review state set after a successful import_pdf parse:
        //   cardPdfReview = { token, original_filename, pages: [...], missing_fonts: [...] }
        //   cardPdfBindings = { 1: { block_0: 'name_en', block_1: 'static', ... }, 2: {...} }
        //   focusedBlock    = { page: 1, id: 'block_0' } | null
        cardPdfReview: null,
        cardPdfBindings: {},
        focusedBlock: null,
        cardPdfPersisting: false,
        fontUploadStatus: {},

        async uploadMissingFont(missing, file) {
            if (!file) return;
            this.fontUploadStatus[missing.raw_name] = 'uploading';
            const fd = new FormData();
            fd.append('font_file', file);
            fd.append('raw_name',  missing.raw_name);
            try {
                const r = await fetch('<?= getBasePath() ?>printshop/upload_font.php', {
                    method: 'POST', credentials: 'same-origin',
                    headers: {'X-CSRF-Token': this.csrf},
                    body: fd
                });
                const j = await r.json();
                if (!r.ok || !j.ok) {
                    this.fontUploadStatus[missing.raw_name] = 'error';
                    alert('Upload failed: ' + (j.error || ('HTTP ' + r.status)));
                    return;
                }
                this.fontUploadStatus[missing.raw_name] = 'done';
            } catch (e) {
                this.fontUploadStatus[missing.raw_name] = 'error';
                alert('Upload failed: ' + e.message);
            }
        },

        bindingOptions: [
            { value: 'static',       label: 'Decoration (keep fixed)' },
            { value: 'skip',         label: 'Skip / delete' },
            { value: 'name_en',      label: 'Name (EN)' },
            { value: 'name_ar',      label: 'Name (AR)' },
            { value: 'position_en',  label: 'Position (EN)' },
            { value: 'position_ar',  label: 'Position (AR)' },
            { value: 'company_en',   label: 'Company (EN)' },
            { value: 'company_ar',   label: 'Company (AR)' },
            { value: 'mobile',       label: 'Mobile' },
            { value: 'mobile_ar',    label: 'Mobile (AR)' },
            { value: 'phone',        label: 'Phone' },
            { value: 'phone_ar',     label: 'Phone (AR)' },
            { value: 'fax',          label: 'Fax' },
            { value: 'email',        label: 'Email' },
            { value: 'website',      label: 'Website' },
            { value: 'website_ar',   label: 'Website (AR)' },
            { value: 'address',      label: 'Address line' },
            { value: 'address_en',   label: 'Address 01 (EN)' },
            { value: 'address_2_en', label: 'Address 02 (EN)' },
            { value: 'address_ar',   label: 'Address 01 (AR)' },
            { value: 'address_2_ar', label: 'Address 02 (AR)' },
            { value: 'social',       label: 'Social handle' },
        ],
        bindingShort: {
            static: 'Decoration', skip: 'Skip',
            name_en: 'Name', name_ar: 'Name AR',
            position_en: 'Position', position_ar: 'Position AR',
            company_en: 'Company', company_ar: 'Company AR',
            mobile: 'Mobile', mobile_ar: 'Mobile AR',
            phone: 'Phone', phone_ar: 'Phone AR', fax: 'Fax',
            email: 'Email', website: 'Website', website_ar: 'Website AR',
            address: 'Address', address_en: 'Addr 1', address_2_en: 'Addr 2',
            address_ar: 'Addr 1 AR', address_2_ar: 'Addr 2 AR',
            social: 'Social',
        },
        bindingLabelShort(v) { return this.bindingShort[v] || v; },
        bindingFor(pageNum, blockId) {
            const p = this.cardPdfBindings[pageNum] || {};
            return p[blockId] || 'static';
        },
        setBinding(pageNum, blockId, value) {
            if (!this.cardPdfBindings[pageNum]) this.cardPdfBindings[pageNum] = {};
            this.cardPdfBindings[pageNum][blockId] = value;
        },
        focusBlock(pageNum, blockId) { this.focusedBlock = { page: pageNum, id: blockId }; },
        isFocused(pageNum, blockId) {
            return this.focusedBlock && this.focusedBlock.page === pageNum && this.focusedBlock.id === blockId;
        },
        resetCardPdf() {
            this.cardPdfReview = null;
            this.cardPdfBindings = {};
            this.focusedBlock = null;
            this.cardPdfReady = false;
            this.data.card_design = {};
            // Reset the dropzone visual.
            const empty = document.getElementById('card-pdf-empty');
            const loaded = document.getElementById('card-pdf-loaded');
            if (empty)  empty.classList.remove('hidden');
            if (loaded) loaded.classList.add('hidden');
            const input = document.getElementById('card-pdf-input');
            if (input) input.value = '';
        },
        bindCardPdf() {
            const zone = document.getElementById('card-pdf-zone');
            const input = document.getElementById('card-pdf-input');
            const empty = document.getElementById('card-pdf-empty');
            const loaded = document.getElementById('card-pdf-loaded');
            const status = document.getElementById('card-pdf-status');
            const summary = document.getElementById('card-pdf-summary');
            const errBox = document.getElementById('card-pdf-error');
            if (!zone || !input || zone.dataset.bound === '1') return;
            zone.dataset.bound = '1';

            const showErr = msg => { errBox.textContent = msg; errBox.classList.remove('hidden'); };
            const clearErr = () => errBox.classList.add('hidden');

            const accept = file => {
                clearErr();
                if (!file) return;
                if (file.type !== 'application/pdf') { showErr('Please upload a PDF file.'); return; }
                if (file.size > 25 * 1024 * 1024) { showErr('PDF is too large. Max 25 MB.'); return; }
                empty.classList.add('hidden');
                loaded.classList.remove('hidden');
                status.innerHTML = '<span class="text-blue-600"><i class="fa-solid fa-spinner fa-spin mr-1"></i>Analysing card design...</span>';
                summary.textContent = '';

                const fd = new FormData(); fd.append('pdf', file);
                fetch('<?= getBasePath() ?>printshop/import_pdf.php', { method: 'POST', credentials: 'same-origin', body: fd })
                    .then(r => r.json())
                    .then(data => {
                        if (data.error) {
                            showErr(data.error);
                            status.innerHTML = '<span class="text-red-600"><i class="fa-solid fa-triangle-exclamation mr-1"></i>Import failed.</span>';
                            return;
                        }
                        const blockTotal = data.pages.reduce((n,p)=>n+(p.blocks||[]).length,0);
                        // Switch the wizard from upload-mode to review-mode.
                        // Pre-populate every block with the parser's
                        // suggested binding (user can override per-block).
                        const bindings = {};
                        data.pages.forEach(p => {
                            bindings[p.page_number] = {};
                            (p.blocks || []).forEach(b => {
                                bindings[p.page_number][b.id] = b.suggested_binding || 'static';
                            });
                        });
                        this.cardPdfReview   = data;
                        this.cardPdfBindings = bindings;
                        this.focusedBlock    = null;
                        this.cardPdfReady    = true;
                        // Stash a draft card_design payload so saveStep
                        // can persist the upload metadata on Next.
                        this.data.card_design = {
                            imported: true,
                            import_token: data.import_token,
                            pages: data.pages.length,
                            blocks_total: blockTotal,
                            qr_found: data.pages.some(p => p.qr_area),
                            missing_fonts: data.missing_fonts || [],
                            uploaded_at: new Date().toISOString(),
                            original_filename: data.original_filename || file.name,
                        };
                    })
                    .catch(err => showErr('Network error: ' + err.message));
            };

            input.addEventListener('change', e => accept(e.target.files[0]));
            zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('drag-over'); });
            zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
            zone.addEventListener('drop', e => { e.preventDefault(); zone.classList.remove('drag-over'); accept(e.dataTransfer.files[0]); });
            zone.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }});
        },
        tplLabels: { minimal: <?= json_encode(t('onboarding.template_minimal')) ?>, bold: <?= json_encode(t('onboarding.template_bold')) ?>, classic: <?= json_encode(t('onboarding.template_classic')) ?> },

        init() { this.bindCardPdf();
            // Keyboard nav: Enter = next, Esc = save and close.
            // Skip when the user is typing in a text field.
            window.addEventListener('keydown', (e) => {
                if (e.target && (e.target.tagName === 'TEXTAREA' || e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT')) return;
                if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); this.next(); }
                if (e.key === 'Escape')               { e.preventDefault(); this.skipForNow(); }
            });
            this.$watch('data.invite_team.paste', (v) => { this.parsedPaste = this.parsePaste(v); });
            this.parsedPaste = this.parsePaste(this.data.invite_team.paste || '');
        },
        parsedPaste: { count: 0, errors: [] },
        csvReport: { total: 0, errors: [], summary: '' },
        validationErrors: [],
        parsePaste(raw) {
            const out = { count: 0, errors: [] };
            if (!raw || !raw.trim()) return out;
            raw.split(/\r?\n/).forEach((line, i) => {
                const parts = line.split(/[,،|]/).map(s => s.trim()).filter(Boolean);
                if (parts.length === 0) return;
                const email = parts.find(p => /@/.test(p));
                if (!email || parts.length < 2) { out.errors.push(i + 1); return; }
                out.count++;
            });
            return out;
        },
        pasteStatus() {
            if (!this.parsedPaste.count && !this.parsedPaste.errors.length) return '';
            const tpl = <?= json_encode(t('onboarding.paste_parsed')) ?>;
            let s = tpl.replace(':n', this.parsedPaste.count);
            if (this.parsedPaste.errors.length) {
                s += ' · ' + (<?= json_encode(t('onboarding.paste_error')) ?>);
            }
            return s;
        },

        stepOfLabel() {
            return (<?= json_encode(t('onboarding.step_of')) ?>)
                .replace(':current', this.step).replace(':total', this.totalSteps);
        },
        tplLabel(t) { return this.tplLabels[t] || t; },
        previewUrl() {
            const empSlug = (this.data.first_employee.name || '').toLowerCase().replace(/[^a-z0-9]+/g,'-') || 'preview';
            const apex = <?= json_encode(cardifyApexHost()) ?>;
            return 'https://' + this.companySlug + '.' + apex + '/' + empSlug;
        },
        pricingTiers: <?= json_encode(CardPrintPricing::tiersForJs()) ?>,
        pricePerCard() {
            const q = Math.max(0, parseInt(this.data.order_cards.per_person || 0));
            let price = this.pricingTiers[0].price;
            for (const t of this.pricingTiers) {
                if (q >= t.min) price = t.price;
                else break;
            }
            return price;
        },
        pricePerCardLabel() {
            return 'OMR ' + this.pricePerCard().toFixed(3);
        },
        estimateTotal() {
            const q = Math.max(0, parseInt(this.data.order_cards.per_person || 0));
            const total = q * this.pricePerCard();
            return 'OMR ' + total.toFixed(3);
        },

        handleLogo(ev) {
            const f = ev.target.files && ev.target.files[0];
            if (!f) return;
            const reader = new FileReader();
            reader.onload = () => { this.data.logo = { url: reader.result, filename: f.name, size: f.size }; };
            reader.readAsDataURL(f);
        },
        handleCsv(ev) {
            const f = ev.target.files && ev.target.files[0];
            if (!f) return;
            const reader = new FileReader();
            reader.onload = () => {
                this.data.invite_team.csv = {
                    filename: f.name,
                    size: f.size,
                    content: reader.result,
                    preview: reader.result.slice(0, 2000),
                };
            };
            reader.readAsText(f);
        },
        copyUrl() {
            navigator.clipboard.writeText(this.previewUrl()).then(() => {
                this.copied = true;
                setTimeout(() => this.copied = false, 2000);
            });
        },

        async next() {
            if (this.saving) return;

            // Step 2 with a pending review must persist bindings first.
            // We only advance after the templates rows are written so the
            // dashboard / portal don't show a half-imported card.
            if (this.step === 2 && this.cardPdfReview && !this.cardPdfPersisting) {
                this.cardPdfPersisting = true;
                const errBox = document.getElementById('card-pdf-persist-error');
                if (errBox) errBox.classList.add('hidden');
                try {
                    const res = await fetch('<?= getBasePath() ?>printshop/persist_template.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': this.csrf},
                        body: JSON.stringify({
                            import_token: this.cardPdfReview.import_token,
                            bindings: this.cardPdfBindings,
                        })
                    });
                    const j = await res.json();
                    if (!res.ok || !j.ok) {
                        const msg = (j && (j.message || j.error)) || ('persist_failed (' + res.status + ')');
                        if (errBox) { errBox.textContent = msg; errBox.classList.remove('hidden'); }
                        return;
                    }
                    // Success: stamp the pair_id so the dashboard
                    // checklist can verify the design without rerunning
                    // the parser.
                    this.data.card_design.template_pair_id = j.pair_id;
                    this.data.card_design.confirmed_at = new Date().toISOString();
                } catch (e) {
                    if (errBox) { errBox.textContent = e.message; errBox.classList.remove('hidden'); }
                    return;
                } finally {
                    this.cardPdfPersisting = false;
                }
            }

            this.saving = true;
            const payload = this.stepPayload();
            try {
                const res = await fetch(this.saveUrl, {
                    method: 'POST',
                    headers: {'Content-Type':'application/json','X-CSRF-Token': this.csrf},
                    body: JSON.stringify({ step: this.step, payload })
                });
                const json = await res.json();
                if (!json.ok) {
                    if (res.status === 422 && Array.isArray(json.errors) && json.errors.length) {
                        this.validationErrors = json.errors;
                        const labels = <?= json_encode([
                            'missing_logo'       => t('onboarding.err_missing_logo'),
                            'invalid_hex'        => t('onboarding.err_invalid_hex'),
                            'invalid_template'   => t('onboarding.err_invalid_template'),
                            'missing_name'       => t('onboarding.err_missing_name'),
                            'invalid_email'      => t('onboarding.err_invalid_email'),
                            'qty_below_min'      => t('onboarding.err_qty_below_min'),
                        ]) ?>;
                        const msg = json.errors.map(e => labels[e.code] || e.code).join(' · ');
                        alert(msg);
                        return;
                    }
                    throw new Error(json.error || 'save failed');
                }
                this.validationErrors = [];
                // If the server extracted a dominant color from the logo
                // on step 1, prefill step 2 primary (skip if user has
                // already customized off the default Cardify teal).
                if (json.extracted_color && this.step === 1) {
                    const current = (this.data.colors.primary || '').toLowerCase();
                    if (!current || current === '#009bc1') {
                        this.data.colors.primary = json.extracted_color;
                    }
                }

                if (this.step < this.totalSteps) { this.step++; window.scrollTo({top:0,behavior:'smooth'}); }
                else {
                    // If the admin picked a printed-card quantity at or
                    // above the minimum order size, skip straight into
                    // the real print-order form with qty pre-filled.
                    const qty = Math.max(0, parseInt(this.data.order_cards.per_person || 0));
                    if (qty >= <?= (int) CardPrintPricing::MIN_QTY ?>) {
                        window.location.href = this.printUrl + '?tab=create&wizard=done&qty=' + qty;
                        return;
                    }
                    const q = json.import && json.import.invites_sent > 0
                        ? '?wizard=done&invites=' + json.import.invites_sent
                        : '?wizard=done';
                    window.location.href = this.dashboardUrl + q;
                }
            } catch (e) {
                alert(e.message);
            } finally { this.saving = false; }
        },
        back() { if (this.step > 1) { this.step--; window.scrollTo({top:0,behavior:'smooth'}); } },

        async skipForNow() {
            try {
                await fetch(this.saveUrl + '?skip=1', { method: 'POST', headers: {'X-CSRF-Token': this.csrf} });
            } finally { window.location.href = this.dashboardUrl; }
        },

        stepPayload() {
            const k = ['logo','colors','template','first_employee','preview','invite_team','order_cards'][this.step - 1];
            return this.data[k];
        },
    };
}
</script>

<?php adminFooter(); ?>
