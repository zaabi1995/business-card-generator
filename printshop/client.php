<?php
/**
 * Internal-provider mode: one client company. Shows employee grid
 * with card thumbnails + per-row buttons to order or download a
 * production sheet.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/PrintShopAuth.php';

$ctx = PrintShopAuth::requireInternalProvider();
$shop = $ctx['shop'];

$companyId = trim($_GET['company'] ?? '');
if ($companyId === '') {
    header('Location: ' . getBasePath() . 'printshop/clients.php');
    exit;
}

$db  = Database::getInstance();
$pdo = $db->getConnection();

$company = $pdo->prepare("SELECT * FROM companies WHERE id = ? LIMIT 1");
$company->execute([$companyId]);
$company = $company->fetch(PDO::FETCH_ASSOC);
if (!$company) {
    header('Location: ' . getBasePath() . 'printshop/clients.php');
    exit;
}

$empStmt = $pdo->prepare(
    "SELECT e.id, e.name_en, e.name_ar, e.position_en, e.position_ar, e.email, e.mobile,
            gc.front_web_path, gc.back_web_path, gc.front_file_path, gc.back_file_path,
            gc.generated_at
     FROM employees e
     LEFT JOIN generated_cards gc ON gc.id = (
        SELECT id FROM generated_cards
        WHERE employee_id = e.id AND company_id = e.company_id
        ORDER BY generated_at DESC LIMIT 1
     )
     WHERE e.company_id = ? ORDER BY e.name_en ASC"
);
$empStmt->execute([$companyId]);
$employees = $empStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Existing card designs (template pairs) for this client
$tplStmt = $pdo->prepare(
    "SELECT pair_id, MAX(name) AS name, MAX(side) AS side, MAX(updated_at) AS updated_at,
            SUM(CASE WHEN side='front' THEN 1 ELSE 0 END) AS has_front,
            SUM(CASE WHEN side='back'  THEN 1 ELSE 0 END) AS has_back
     FROM templates
     WHERE company_id = ? AND deleted_at IS NULL AND pair_id IS NOT NULL
     GROUP BY pair_id ORDER BY MAX(updated_at) DESC"
);
$tplStmt->execute([$companyId]);
$designs = $tplStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$cardsBase = getBasePath() . 'uploads/companies/' . $companyId . '/cards/';
$companyLogo = !empty($company['logo_path']) ? getBasePath() . ltrim($company['logo_path'], '/') : '';

require_once INCLUDES_DIR . '/printshop-layout.php';
printshopHeader($company['name'] . ' , ' . $shop['name'], 'clients');
?>
    <div class="mb-2 text-sm text-gray-500"><a href="clients.php" class="hover:underline"><i class="fa-solid fa-arrow-left mr-1"></i><?= htmlspecialchars(t('printshopinternal.back_to_clients')) ?></a></div>

    <div class="mb-6 flex flex-wrap items-center gap-4">
        <?php if ($companyLogo): ?>
        <img src="<?= sanitize($companyLogo) ?>" alt="" class="w-14 h-14 rounded-lg object-contain bg-white border border-gray-100">
        <?php else: ?>
        <div class="w-14 h-14 rounded-lg bg-gray-100 flex items-center justify-center text-lg font-bold text-gray-500"><?= strtoupper(substr($company['name'], 0, 2)) ?></div>
        <?php endif; ?>
        <div class="flex-1">
            <h1 class="text-2xl font-bold text-gray-900"><?= sanitize($company['name']) ?></h1>
            <p class="text-sm text-gray-500">/<?= sanitize($company['slug']) ?> &middot; <?= count($employees) ?> employees &middot; <?= count($designs) ?> designs</p>
        </div>
        <a href="client-templates.php?company=<?= urlencode($companyId) ?>"
           class="inline-flex items-center gap-2 px-4 py-2 bg-[#00718c] hover:bg-[#005b73] text-white rounded-lg text-sm font-medium shadow-sm">
            <i class="fa-solid fa-id-card"></i> Apply a design
        </a>
        <button type="button" onclick="document.getElementById('newDesignModal').showModal()"
                class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium shadow-sm">
            <i class="fa-solid fa-plus"></i> <?= htmlspecialchars(t('printshopinternal.create_design_btn')) ?>
        </button>
    </div>

    <?php if (!empty($designs)): ?>
    <div class="mb-6">
        <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-2"><?= htmlspecialchars(t('printshopinternal.existing_designs_h')) ?></h2>
        <div class="flex flex-wrap gap-2">
            <?php foreach ($designs as $d): ?>
            <span class="inline-flex items-center gap-2 px-3 py-1.5 bg-white border border-gray-200 rounded-lg text-xs text-gray-700">
                <i class="fa-regular fa-id-card text-gray-400"></i>
                <?= sanitize($d['name'] ?: 'Design') ?>
                <?php if ((int)$d['has_front'] > 0): ?><span class="text-green-600">F</span><?php endif; ?>
                <?php if ((int)$d['has_back']  > 0): ?><span class="text-blue-600">B</span><?php endif; ?>
            </span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (empty($employees)): ?>
    <div class="p-12 bg-white rounded-2xl border-2 border-dashed border-gray-200 text-center">
        <i class="fa-solid fa-id-card text-4xl text-gray-300 mb-3"></i>
        <p class="text-gray-700 font-medium"><?= htmlspecialchars(t('printshopinternal.no_employees')) ?></p>
    </div>
    <?php else: ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php foreach ($employees as $emp):
            $front = $emp['front_web_path'] ?: ($emp['front_file_path'] ? $cardsBase . basename($emp['front_file_path']) : '');
            $back  = $emp['back_web_path']  ?: ($emp['back_file_path']  ? $cardsBase . basename($emp['back_file_path'])  : '');
        ?>
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="aspect-[1.7/1] bg-gray-50 flex items-center justify-center">
                <?php if ($front): ?>
                <img src="<?= sanitize($front) ?>" alt="" class="w-full h-full object-contain">
                <?php else: ?>
                <i class="fa-solid fa-id-card text-3xl text-gray-300"></i>
                <?php endif; ?>
            </div>
            <div class="p-4">
                <p class="font-semibold text-gray-900 truncate"><?= sanitize($emp['name_en'] ?? $emp['name_ar'] ?? 'Employee') ?></p>
                <p class="text-xs text-gray-500 truncate"><?= sanitize($emp['position_en'] ?? $emp['position_ar'] ?? '') ?></p>
                <div class="mt-3 flex items-center gap-2">
                    <a href="order-on-behalf.php?employee=<?= urlencode($emp['id']) ?>&company=<?= urlencode($companyId) ?>"
                       class="flex-1 inline-flex items-center justify-center gap-1 px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-xs font-medium">
                        <i class="fa-solid fa-paper-plane"></i> <?= htmlspecialchars(t('printshopinternal.order_btn')) ?>
                    </a>
                    <a href="print-sheet.php?employee=<?= urlencode($emp['id']) ?>&company=<?= urlencode($companyId) ?>" target="_blank"
                       class="inline-flex items-center justify-center gap-1 px-3 py-2 bg-gray-50 hover:bg-gray-100 text-gray-700 border border-gray-200 rounded-md text-xs font-medium">
                        <i class="fa-solid fa-print"></i> <?= htmlspecialchars(t('printshopinternal.sheet_btn')) ?>
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<dialog id="newDesignModal" class="rounded-2xl p-0 w-full max-w-lg shadow-2xl backdrop:bg-black/40">
    <form id="newDesignForm" method="POST" enctype="multipart/form-data" action="create-design-for-client.php" class="p-6 space-y-4">
        <?= csrfField() ?>
        <input type="hidden" name="company_id" value="<?= sanitize($companyId) ?>">

        <div class="flex items-start justify-between">
            <div>
                <h3 class="text-lg font-bold text-gray-900"><?= htmlspecialchars(t('printshopinternal.create_design_h')) ?></h3>
                <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(str_replace(':client', $company['name'], t('printshopinternal.create_design_sub'))) ?></p>
            </div>
            <button type="button" onclick="document.getElementById('newDesignModal').close()" aria-label="<?= htmlspecialchars(t('printshopinternal.cancel_btn')) ?>" title="<?= htmlspecialchars(t('printshopinternal.cancel_btn')) ?>" class="text-gray-400 hover:text-gray-700 hover:bg-gray-100 rounded-md p-1.5 transition-colors">
                <i class="fa-solid fa-xmark text-lg" aria-hidden="true"></i>
            </button>
        </div>

        <div>
            <label for="newdesign-name" class="block text-xs font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('printshopinternal.design_name_label')) ?></label>
            <input type="text" id="newdesign-name" name="name" required maxlength="100"
                   aria-label="<?= htmlspecialchars(t('printshopinternal.design_name_label')) ?>"
                   placeholder="<?= htmlspecialchars(t('printshopinternal.design_name_ph')) ?>"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label for="newdesign-front" class="block text-xs font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('printshopinternal.front_pdf_label')) ?></label>
                <input type="file" id="newdesign-front" name="front_pdf" accept="application/pdf,.pdf"
                       aria-label="<?= htmlspecialchars(t('printshopinternal.front_pdf_label')) ?>"
                       class="block w-full text-xs file:mr-2 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:bg-blue-50 file:text-blue-700">
            </div>
            <div>
                <label for="newdesign-back" class="block text-xs font-medium text-gray-700 mb-1"><?= htmlspecialchars(t('printshopinternal.back_pdf_label')) ?></label>
                <input type="file" id="newdesign-back" name="back_pdf" accept="application/pdf,.pdf"
                       aria-label="<?= htmlspecialchars(t('printshopinternal.back_pdf_label')) ?>"
                       class="block w-full text-xs file:mr-2 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:bg-gray-50 file:text-gray-700">
            </div>
        </div>
        <p class="text-xs text-gray-400"><?= htmlspecialchars(t('printshopinternal.upload_hint')) ?></p>

        <div id="newDesignStatus" class="hidden text-sm rounded-lg px-3 py-2"></div>

        <div class="flex items-center justify-end gap-2 pt-2 border-t border-gray-100">
            <button type="button" onclick="document.getElementById('newDesignModal').close()"
                    class="px-3 py-2 text-sm text-gray-600 hover:text-gray-900"><?= htmlspecialchars(t('printshopinternal.cancel_btn')) ?></button>
            <button type="submit" id="newDesignSubmit"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-blue-300 text-white rounded-lg text-sm font-medium">
                <i class="fa-solid fa-cloud-arrow-up"></i>
                <span><?= htmlspecialchars(t('printshopinternal.upload_btn')) ?></span>
            </button>
        </div>
    </form>
</dialog>

<script>
(function () {
    var form = document.getElementById('newDesignForm');
    if (!form) return;
    var status = document.getElementById('newDesignStatus');
    var submit = document.getElementById('newDesignSubmit');
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var fd = new FormData(form);
        var hasFront = (fd.get('front_pdf') && fd.get('front_pdf').size > 0);
        var hasBack  = (fd.get('back_pdf')  && fd.get('back_pdf').size  > 0);
        if (!hasFront && !hasBack) {
            status.className = 'text-sm rounded-lg px-3 py-2 bg-red-50 text-red-700';
            status.textContent = 'Please attach at least one PDF.';
            status.classList.remove('hidden');
            return;
        }
        submit.disabled = true;
        status.className = 'text-sm rounded-lg px-3 py-2 bg-blue-50 text-blue-700';
        status.textContent = 'Uploading and parsing PDF, this can take ~30s...';
        status.classList.remove('hidden');

        fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
            .then(function (resp) {
                if (resp.ok && resp.body && resp.body.ok) {
                    status.className = 'text-sm rounded-lg px-3 py-2 bg-green-50 text-green-700';
                    status.textContent = 'Design created. Reloading...';
                    setTimeout(function () { window.location.reload(); }, 800);
                } else {
                    status.className = 'text-sm rounded-lg px-3 py-2 bg-red-50 text-red-700';
                    status.textContent = (resp.body && resp.body.error) ? resp.body.error : 'Upload failed';
                    submit.disabled = false;
                }
            })
            .catch(function (err) {
                status.className = 'text-sm rounded-lg px-3 py-2 bg-red-50 text-red-700';
                status.textContent = 'Network error: ' + err.message;
                submit.disabled = false;
            });
    });
})();
</script>
<?php printshopFooter(); ?>
