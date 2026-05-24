<?php
/**
 * Batch Auto-Generate Business Cards
 * Generates cards for all employees who don't have one yet
 * Uses pre-designed layouts (html2canvas) or Fabric.js templates
 */
require_once __DIR__ . '/../config.php';
requireAdmin();
require_once INCLUDES_DIR . '/admin-layout.php';
require_once INCLUDES_DIR . '/Billing.php';
require_once INCLUDES_DIR . '/CardLayouts.php';

$companyId = getCurrentCompanyId();
if (!$companyId) {
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

// Get plan info
$planInfo = Billing::getCompanyPlanInfo($companyId);
$qualityMultiplier = $planInfo['quality_multiplier'];
$isFreePlan = $planInfo['plan'] === 'free' || empty($planInfo['plan']);
$hasHighQuality = $planInfo['features']['high_quality'] ?? false;

// Load all employees
$employees = loadEmployees($companyId) ?: [];

// Load generated cards and find employees without cards
$generatedLog = loadGeneratedLog($companyId) ?: [];
$cardsByEmployee = [];
foreach ($generatedLog as $entry) {
    $empId = $entry['employeeId'] ?? null;
    if ($empId) {
        $cardsByEmployee[$empId] = true;
    }
}

$employeesWithoutCards = [];
foreach ($employees as $emp) {
    if (empty($cardsByEmployee[$emp['id']])) {
        $employeesWithoutCards[] = $emp;
    }
}

// Check if templates exist (Fabric.js path)
$frontTemplate = getActiveFrontTemplate($companyId);
$backTemplate = getActiveBackTemplate($companyId);
$hasTemplates = $frontTemplate || $backTemplate;

// Pre-designed layout setup
$selectedLayout = $_GET['layout'] ?? 'classic';
$layouts = CardLayouts::getAll();
$company = findCompanyById($companyId);
$companySlug = getCurrentCompanySlug() ?? '';
$baseUrl = getBaseUrl();
$basePath = getBasePath();

// Load company theme for branding
$db = Database::getInstance();
$companyTheme = null;
try {
    $companyTheme = $db->fetchOne("SELECT * FROM company_themes WHERE company_id = :cid", ['cid' => $companyId]);
} catch (Exception $e) {}

// VCF for QR codes
if (file_exists(INCLUDES_DIR . '/VCF.php')) {
    require_once INCLUDES_DIR . '/VCF.php';
}

// Pre-render HTML for all employees (for pre-designed path)
$employeeCards = [];
if (!$hasTemplates) {
    foreach ($employeesWithoutCards as $emp) {
        $vcfUrl = '';
        if (class_exists('VCF') && $emp && $company) {
            $vcfUrl = VCF::getUrl($emp, $company);
        }
        $employeeCards[] = [
            'id' => $emp['id'],
            'name' => $emp['name_en'] ?? $emp['email'] ?? 'Employee',
            'frontHtml' => CardLayouts::renderFront($selectedLayout, $emp, $company, $companyTheme),
            'backHtml' => CardLayouts::renderBack($selectedLayout, $emp, $company, $companyTheme),
            'vcfUrl' => $vcfUrl,
        ];
    }
}

adminHeader(t('adminchrome.batch_auto_generate'), 'employees');
?>

<!-- Fonts for pre-designed layouts -->
<link rel="preconnect" href="https://fonts.bhd.om">
<link rel="preconnect" href="https://fonts.bhd.om" crossorigin>
<link href="https://fonts.bhd.om/css2?family=Inter:wght@400;500;600;700&family=DM+Sans:wght@400;500;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Playfair+Display:wght@400;500;600;700&family=Sora:wght@400;500;600;700&family=Noto+Kufi+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/dist/qrcode.min.js"></script>

<?php if (empty($employeesWithoutCards)): ?>
<div class="max-w-lg mx-auto bg-white rounded-2xl border border-gray-200 shadow-sm p-8 text-center">
    <div class="w-16 h-16 mx-auto bg-green-100 rounded-full flex items-center justify-center mb-4">
        <i class="fa-solid fa-check text-2xl text-green-600"></i>
    </div>
    <h2 class="text-xl font-bold text-gray-900 mb-2"><?= htmlspecialchars(t('batchgen.all_generated_h2')) ?></h2>
    <p class="text-gray-500 text-sm mb-6"><?= htmlspecialchars(t('batchgen.all_generated_body')) ?></p>
    <a href="employees.php" class="inline-flex items-center gap-2 px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-medium transition-colors">
        <i class="fa-solid fa-arrow-left"></i> <?= htmlspecialchars(t('batchgen.back_to_employees')) ?>
    </a>
</div>
<?php else: ?>

<div class="max-w-2xl mx-auto" x-data="batchGenerator()" x-init="init()">
    <!-- Layout Selection (pre-designed only) -->
    <?php if (!$hasTemplates): ?>
    <div x-show="!started" class="mb-6">
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
            <h3 class="font-semibold text-gray-900 mb-3"><?= htmlspecialchars(t('batchgen.choose_layout')) ?></h3>
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
                <?php foreach ($layouts as $lid => $layout): ?>
                <a href="?layout=<?php echo $lid; ?>"
                   class="block rounded-lg border p-3 text-center text-sm transition-all <?php echo $selectedLayout === $lid ? 'ring-2 ring-blue-500 border-blue-300 bg-blue-50' : 'border-gray-200 hover:border-gray-300'; ?>">
                    <div class="font-medium text-gray-900"><?php echo htmlspecialchars($layout['name']); ?></div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Status Card -->
    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-8">
        <!-- Not started -->
        <div x-show="!started" class="text-center">
            <div class="w-16 h-16 mx-auto bg-blue-100 rounded-full flex items-center justify-center mb-4">
                <i class="fa-solid fa-wand-magic-sparkles text-2xl text-blue-600"></i>
            </div>
            <h2 class="text-xl font-bold text-gray-900 mb-2"><?= htmlspecialchars(t('batchgen.generate_all_h2')) ?></h2>
            <p class="text-gray-500 text-sm mb-6">
                <?= htmlspecialchars(str_replace(':n', (string) count($employeesWithoutCards), t('batchgen.need_cards'))) ?>
                <?php if ($hasTemplates): ?>
                <?= htmlspecialchars(t('batchgen.using_template')) ?>
                <?php else: ?>
                <?= htmlspecialchars(str_replace(':layout', $layouts[$selectedLayout]['name'], t('batchgen.using_layout'))) ?>
                <?php endif; ?>
            </p>
            <button @click="start()" class="px-8 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-semibold transition-colors text-lg">
                <i class="fa-solid fa-bolt mr-2"></i><?= htmlspecialchars(t('batchgen.generate_all_btn')) ?>
            </button>
        </div>

        <!-- In progress -->
        <div x-show="started && !finished" x-cloak>
            <div class="flex items-center gap-4 mb-6">
                <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center flex-shrink-0">
                    <i class="fa-solid fa-wand-magic-sparkles text-xl text-blue-600 animate-pulse"></i>
                </div>
                <div class="flex-1">
                    <h2 class="text-lg font-bold text-gray-900"><?= htmlspecialchars(t('batchgen.generating_h2')) ?></h2>
                    <p class="text-sm text-gray-500" x-text="currentName"></p>
                </div>
                <div class="text-right">
                    <div class="text-2xl font-bold text-blue-600" x-text="completed + '/' + total"></div>
                </div>
            </div>
            <!-- Progress bar -->
            <div class="w-full bg-gray-100 rounded-full h-3 mb-4">
                <div class="bg-blue-600 h-3 rounded-full transition-all duration-300" :style="'width:' + Math.round((completed/total)*100) + '%'"></div>
            </div>
            <p class="text-xs text-gray-400 text-center" x-text="statusMessage"></p>
        </div>

        <!-- Finished -->
        <div x-show="finished" x-cloak class="text-center">
            <div class="w-16 h-16 mx-auto bg-green-100 rounded-full flex items-center justify-center mb-4">
                <i class="fa-solid fa-check text-2xl text-green-600"></i>
            </div>
            <h2 class="text-xl font-bold text-gray-900 mb-2"><?= htmlspecialchars(t('batchgen.all_done_h2')) ?></h2>
            <p class="text-gray-500 text-sm mb-2">
                <span x-text="BATCHGEN_I18N.generated_success.replace(':n', completed)"></span>
                <span x-show="errors > 0" class="text-red-500" x-text="BATCHGEN_I18N.failed_suffix.replace(':n', errors)"></span>
            </p>
            <a href="employees.php?generated=1" class="inline-flex items-center gap-2 px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-medium transition-colors mt-4">
                <i class="fa-solid fa-arrow-left"></i> <?= htmlspecialchars(t('batchgen.back_to_employees')) ?>
            </a>
        </div>
    </div>

    <!-- Results log -->
    <div x-show="log.length > 0" x-cloak class="mt-4 bg-white rounded-xl border border-gray-200 shadow-sm p-4 max-h-60 overflow-y-auto">
        <template x-for="entry in log" :key="entry.id">
            <div class="flex items-center gap-2 py-1.5 text-sm border-b border-gray-50 last:border-0">
                <i :class="entry.ok ? 'fa-solid fa-check-circle text-green-500' : 'fa-solid fa-times-circle text-red-500'"></i>
                <span class="text-gray-700" x-text="entry.name"></span>
                <span x-show="!entry.ok" class="text-red-500 text-xs" x-text="entry.error"></span>
            </div>
        </template>
    </div>
</div>

<!-- Hidden render containers -->
<div id="batch-render-front" style="position:absolute;left:-9999px;top:0;"></div>
<div id="batch-render-back" style="position:absolute;left:-9999px;top:0;"></div>

<script>
var batchEmployees = <?php echo json_encode($employeeCards, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
var hasTemplates = <?php echo $hasTemplates ? 'true' : 'false'; ?>;
var BATCHGEN_I18N = <?php echo json_encode([
    'generating_card_x_of_y' => t('batchgen.js_generating_card_x_of_y'),
    'no_front_html'          => t('batchgen.js_no_front_html'),
    'save_front_failed'      => t('batchgen.js_save_front_failed'),
    'save_back_failed'       => t('batchgen.js_save_back_failed'),
    'log_failed'             => t('batchgen.js_log_failed'),
    'generated_success'      => t('batchgen.generated_success'),
    'failed_suffix'          => t('batchgen.failed_suffix'),
], JSON_UNESCAPED_UNICODE); ?>;

function batchGenerator() {
    return {
        started: false,
        finished: false,
        total: batchEmployees.length,
        completed: 0,
        errors: 0,
        currentName: '',
        statusMessage: '',
        log: [],
        baseUrl: <?php echo json_encode(rtrim($baseUrl, '/')); ?>,

        init() {},

        async start() {
            this.started = true;

            if (hasTemplates) {
                // Redirect to existing batch_generate.php for Fabric.js path
                window.location.href = 'batch_generate.php?auto_generate=1';
                return;
            }

            // Pre-designed layout path: render each employee's card via html2canvas
            for (var i = 0; i < batchEmployees.length; i++) {
                var emp = batchEmployees[i];
                this.currentName = emp.name;
                this.statusMessage = BATCHGEN_I18N.generating_card_x_of_y.replace(':cur', i + 1).replace(':tot', this.total);

                try {
                    await this.generateOne(emp);
                    this.log.push({ id: emp.id, name: emp.name, ok: true, error: '' });
                    this.completed++;
                } catch (err) {
                    this.log.push({ id: emp.id, name: emp.name, ok: false, error: err.message });
                    this.errors++;
                    this.completed++;
                }
            }

            this.finished = true;
        },

        async generateOne(emp) {
            var frontEl = document.getElementById('batch-render-front');
            var backEl = document.getElementById('batch-render-back');

            // Inject front HTML
            frontEl.innerHTML = emp.frontHtml;
            var frontTarget = frontEl.firstElementChild;
            if (!frontTarget) throw new Error(BATCHGEN_I18N.no_front_html);

            // Inject back HTML with QR code
            backEl.innerHTML = emp.backHtml;
            if (emp.vcfUrl && typeof qrcode !== 'undefined') {
                var qr = qrcode(0, 'M');
                qr.addData(emp.vcfUrl);
                qr.make();
                var qrDataUrl = qr.createDataURL(4, 0);
                var backTarget = backEl.firstElementChild;
                if (backTarget) {
                    var allDivs = backTarget.querySelectorAll('div');
                    allDivs.forEach(function(div) {
                        if (div.children.length === 0 && div.textContent.trim() === '' && div.style.marginTop) {
                            var img = document.createElement('img');
                            img.src = qrDataUrl;
                            img.style.cssText = 'width:120px;height:120px;';
                            div.appendChild(img);
                        }
                    });
                }
            }

            // Wait for fonts + images
            await document.fonts.ready;
            await new Promise(function(r) { setTimeout(r, 200); });

            // Render front
            var frontCanvas = await html2canvas(frontTarget, {
                width: 1050, height: 600, scale: 2,
                useCORS: true, allowTaint: false, backgroundColor: null, logging: false
            });

            // Render back
            var backTarget2 = backEl.firstElementChild;
            var backCanvas = null;
            if (backTarget2) {
                backCanvas = await html2canvas(backTarget2, {
                    width: 1050, height: 600, scale: 2,
                    useCORS: true, allowTaint: false, backgroundColor: null, logging: false
                });
            }

            // Save front
            var frontBlob = await this.toBlob(frontCanvas);
            var frontResult = await this.save(frontBlob, 'front', emp.id);
            if (!frontResult.success) throw new Error(frontResult.error || BATCHGEN_I18N.save_front_failed);

            // Save back
            var backFile = null;
            if (backCanvas) {
                var backBlob = await this.toBlob(backCanvas);
                var backResult = await this.save(backBlob, 'back', emp.id);
                if (backResult.success) backFile = backResult.png_filename;
            }

            // Log generation
            await fetch(this.baseUrl + '/log_generation.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    employee_id: emp.id,
                    front_url: frontResult.png_filename,
                    back_url: backFile
                })
            });
        },

        toBlob(canvas) {
            return new Promise(function(resolve) {
                canvas.toBlob(function(blob) { resolve(blob); }, 'image/png');
            });
        },

        async save(blob, side, empId) {
            var fd = new FormData();
            fd.append('png', blob, side + '.png');
            fd.append('side', side);
            fd.append('employee_id', empId);
            var resp = await fetch(this.baseUrl + '/save_card_both.php', { method: 'POST', body: fd });
            return await resp.json();
        }
    };
}
</script>

<?php endif; ?>

<?php adminFooter(); ?>
