<?php
/**
 * 7-step onboarding wizard. First-login redirect brings a fresh tenant
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
    $company = $db->fetchOne("SELECT id, name, name_en, name_ar, slug FROM companies WHERE id = :id", ['id' => $companyId]);
} catch (Throwable $e) { /* fall through to empty company */ }
$companyName = $company['name'] ?? ($company['name_en'] ?? 'your company');
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

        <!-- Step 2: Colors -->
        <div class="wizard-step" x-show="step === 2" x-cloak>
            <h2 class="text-xl font-bold text-gray-900 mb-1"><?= htmlspecialchars(t('onboarding.step_colors')) ?></h2>
            <p class="text-sm text-gray-500 mb-6"><?= htmlspecialchars(t('onboarding.step_colors_help')) ?></p>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2"><?= htmlspecialchars(t('onboarding.primary_color')) ?></label>
                    <div class="wizard-color-row flex items-center gap-2">
                        <input type="color" x-model="data.colors.primary" class="h-11 w-16 border border-gray-200 rounded-lg cursor-pointer">
                        <input type="text" x-model="data.colors.primary" class="form-input flex-1 font-mono" dir="ltr">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2"><?= htmlspecialchars(t('onboarding.accent_color')) ?></label>
                    <div class="wizard-color-row flex items-center gap-2">
                        <input type="color" x-model="data.colors.accent" class="h-11 w-16 border border-gray-200 rounded-lg cursor-pointer">
                        <input type="text" x-model="data.colors.accent" class="form-input flex-1 font-mono" dir="ltr">
                    </div>
                </div>
            </div>
        </div>

        <!-- Step 3: Template -->
        <div class="wizard-step" x-show="step === 3" x-cloak>
            <h2 class="text-xl font-bold text-gray-900 mb-1"><?= htmlspecialchars(t('onboarding.step_template')) ?></h2>
            <p class="text-sm text-gray-500 mb-6"><?= htmlspecialchars(t('onboarding.step_template_help')) ?></p>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <?php foreach (['minimal','bold','classic'] as $tplKey): ?>
                <button type="button" @click="data.template = <?= json_encode($tplKey) ?>"
                        :class="data.template === <?= json_encode($tplKey) ?> ? 'ring-2 ring-blue-500 border-blue-300' : 'border-gray-200 hover:border-gray-300'"
                        class="bg-white border rounded-xl p-4 text-left transition">
                    <div class="aspect-[1050/600] rounded-lg mb-3 overflow-hidden bg-gray-100 relative">
                        <div style="position:absolute;top:0;left:0;transform-origin:top left;transform:scale(0.22);pointer-events:none;">
                            <?= $previewRenders[$tplKey] ?? '' ?>
                        </div>
                    </div>
                    <p class="font-semibold text-gray-900 text-sm"><?= htmlspecialchars(t('onboarding.template_' . $tplKey)) ?></p>
                </button>
                <?php endforeach; ?>
            </div>
            <p class="mt-3 text-xs text-gray-500"><?= htmlspecialchars(t('onboarding.template_preview_hint')) ?></p>
        </div>

        <!-- Step 4: First employee -->
        <div class="wizard-step" x-show="step === 4" x-cloak>
            <h2 class="text-xl font-bold text-gray-900 mb-1"><?= htmlspecialchars(t('onboarding.step_first_employee')) ?></h2>
            <p class="text-sm text-gray-500 mb-6"><?= htmlspecialchars(t('onboarding.step_first_employee_help')) ?></p>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2"><?= htmlspecialchars(t('onboarding.first_name')) ?></label>
                    <input type="text" x-model="data.first_employee.name" class="form-input" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2"><?= htmlspecialchars(t('onboarding.first_title')) ?></label>
                    <input type="text" x-model="data.first_employee.title" class="form-input">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2"><?= htmlspecialchars(t('onboarding.first_email')) ?></label>
                    <input type="email" x-model="data.first_employee.email" class="form-input" dir="ltr">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2"><?= htmlspecialchars(t('onboarding.first_phone')) ?></label>
                    <input type="tel" x-model="data.first_employee.phone" class="form-input" dir="ltr">
                </div>
            </div>
        </div>

        <!-- Step 5: Preview -->
        <div class="wizard-step" x-show="step === 5" x-cloak>
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

        <!-- Step 6: Invite team -->
        <div class="wizard-step" x-show="step === 6" x-cloak>
            <h2 class="text-xl font-bold text-gray-900 mb-1"><?= htmlspecialchars(t('onboarding.step_invite_team')) ?></h2>
            <p class="text-sm text-gray-500 mb-6"><?= htmlspecialchars(t('onboarding.step_invite_team_help')) ?></p>

            <label class="block text-sm font-medium text-gray-700 mb-2"><?= htmlspecialchars(t('onboarding.paste_list_label')) ?></label>
            <textarea x-model="data.invite_team.paste" rows="6" class="form-input font-mono text-sm"
                      :placeholder="<?= htmlspecialchars(json_encode(t('onboarding.paste_list_ph')), ENT_QUOTES) ?>"></textarea>
            <p class="mt-1 text-xs"
               :class="parsedPaste.errors.length ? 'text-amber-600' : 'text-green-600'"
               x-show="parsedPaste.count || parsedPaste.errors.length"
               x-text="pasteStatus()"></p>

            <label class="block text-sm font-medium text-gray-700 mt-4 mb-2"><?= htmlspecialchars(t('onboarding.upload_csv_label')) ?></label>
            <input type="file" accept=".csv,text/csv" class="form-input"
                   @change="handleCsv($event)">
            <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(t('onboarding.csv_headers_hint')) ?></p>

            <div x-show="csvReport.total > 0 || csvReport.errors.length"
                 x-cloak
                 class="mt-4 text-xs rounded-lg border p-3"
                 :class="csvReport.errors.length ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-green-200 bg-green-50 text-green-800'">
                <p x-text="csvReport.summary"></p>
                <ul class="mt-1 list-disc ps-5" x-show="csvReport.errors.length">
                    <template x-for="err in csvReport.errors.slice(0, 5)" :key="err.row + ':' + err.msg">
                        <li x-text="err.row + ': ' + err.msg"></li>
                    </template>
                </ul>
            </div>
        </div>

        <!-- Step 7: Order -->
        <div class="wizard-step" x-show="step === 7" x-cloak>
            <h2 class="text-xl font-bold text-gray-900 mb-1"><?= htmlspecialchars(t('onboarding.step_order_cards')) ?></h2>
            <p class="text-sm text-gray-500 mb-6"><?= htmlspecialchars(t('onboarding.step_order_cards_help')) ?></p>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2"><?= htmlspecialchars(t('onboarding.order_qty_label')) ?></label>
                    <input type="number" min="0" step="50" x-model.number="data.order_cards.per_person" class="form-input" dir="ltr">
                    <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(t('onboarding.order_min_qty')) ?></p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2"><?= htmlspecialchars(t('onboarding.order_per_card')) ?></label>
                    <input type="text" readonly :value="pricePerCardLabel()" class="form-input bg-gray-50 font-mono text-sm" dir="ltr">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2"><?= htmlspecialchars(t('onboarding.order_estimate')) ?></label>
                    <input type="text" readonly :value="estimateTotal()" class="form-input bg-gray-50 font-mono text-sm" dir="ltr">
                </div>
            </div>
            <div class="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs text-gray-600">
                <p class="font-semibold mb-1"><?= htmlspecialchars(t('onboarding.order_tiers_title')) ?></p>
                <ul class="space-y-0.5">
                    <?php foreach (CardPrintPricing::TIERS as $minQty => $price): ?>
                        <li dir="ltr"><?= (int) $minQty ?>+ cards, OMR <?= number_format((float) $price, 3) ?>/card</li>
                    <?php endforeach; ?>
                </ul>
            </div>
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

        stepLabels: { 1: <?= json_encode(t('onboarding.step_logo')) ?>, 2: <?= json_encode(t('onboarding.step_colors')) ?>, 3: <?= json_encode(t('onboarding.step_template')) ?>, 4: <?= json_encode(t('onboarding.step_first_employee')) ?>, 5: <?= json_encode(t('onboarding.step_preview')) ?>, 6: <?= json_encode(t('onboarding.step_invite_team')) ?>, 7: <?= json_encode(t('onboarding.step_order_cards')) ?> },
        tplLabels: { minimal: <?= json_encode(t('onboarding.template_minimal')) ?>, bold: <?= json_encode(t('onboarding.template_bold')) ?>, classic: <?= json_encode(t('onboarding.template_classic')) ?> },

        init() {
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
            const slug = (this.data.first_employee.name || '').toLowerCase().replace(/[^a-z0-9]+/g,'-');
            return 'https://cardify.om/' + this.companySlug + '/' + (slug || 'preview');
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
            this.saving = true;
            const payload = this.stepPayload();
            try {
                const res = await fetch(this.saveUrl, {
                    method: 'POST',
                    headers: {'Content-Type':'application/json','X-CSRF-Token': this.csrf},
                    body: JSON.stringify({ step: this.step, payload })
                });
                const json = await res.json();
                if (!json.ok) throw new Error(json.error || 'save failed');
                // If the server extracted a dominant color from the logo
                // on step 1, prefill step 2 primary (skip if user has
                // already customized off the default Cardify teal).
                if (json.extracted_color && this.step === 1) {
                    const current = (this.data.colors.primary || '').toLowerCase();
                    if (!current || current === '#009bc1') {
                        this.data.colors.primary = json.extracted_color;
                    }
                }
                if (json.csv && this.step === 6) {
                    const tpl  = <?= json_encode(t('onboarding.csv_parsed_summary')) ?>;
                    const etpl = <?= json_encode(t('onboarding.csv_errors_summary')) ?>;
                    let summary = tpl.replace(':n', json.csv.total || 0);
                    if (json.csv.errors && json.csv.errors.length) {
                        summary += ' · ' + etpl.replace(':n', json.csv.errors.length);
                    }
                    this.csvReport = {
                        total: json.csv.total || 0,
                        errors: json.csv.errors || [],
                        summary,
                    };
                }
                if (this.step < this.totalSteps) { this.step++; window.scrollTo({top:0,behavior:'smooth'}); }
                else {
                    // If the admin picked a printed-card quantity at or
                    // above the minimum order size, skip straight into
                    // the real print-order form with qty pre-filled.
                    const qty = Math.max(0, parseInt(this.data.order_cards.per_person || 0));
                    if (qty >= <?= (int) CardPrintPricing::MIN_QTY ?>) {
                        const base = this.dashboardUrl.replace(/index\.php.*$/, '') + 'print.php';
                        window.location.href = base + '?tab=create&wizard=done&qty=' + qty;
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
