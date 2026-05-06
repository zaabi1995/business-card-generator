<?php
/**
 * Print Shop Template Editor
 * Upload a card template image and define editable text fields using Fabric.js
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/PrintShop.php';

Auth::requireLogin();
$user = Auth::getCurrentUser();

if ($user['role'] !== 'print_shop' && $user['role'] !== 'super_admin') {
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

$printShop = PrintShop::getByUserId($user['id']);
if (!$printShop && $user['role'] !== 'super_admin') {
    header('Location: ' . getBasePath() . 'printshop/register.php');
    exit;
}
if ($user['role'] === 'super_admin' && isset($_GET['shop'])) {
    $printShop = PrintShop::getById((int)$_GET['shop']);
}
if (!$printShop) {
    header('Location: ' . getBasePath() . 'admin/print_shops.php');
    exit;
}

$shopId = $printShop['id'];
$db = Database::getInstance();

// Load existing template if editing
$template = null;
$templateId = $_GET['id'] ?? '';
if ($templateId) {
    $template = $db->fetchOne(
        "SELECT * FROM shop_templates WHERE id = :id AND print_shop_id = :shop",
        ['id' => $templateId, 'shop' => $shopId]
    );
}

$pageTitle = $template ? t('printshoppages.title_template_edit') : t('printshoppages.title_template_new');
$bodyClass = 'bg-gray-50';

// Extra head for Fabric.js
$extraHead = '
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lato:ital,wght@0,100;0,300;0,400;0,700;0,900;1,100;1,300;1,400;1,700;1,900&family=Sora:wght@100;200;300;400;500;600;700;800&family=Cairo:wght@200;300;400;500;600;700;800;900&family=Inter:wght@100;200;300;400;500;600;700;800;900&family=Roboto:ital,wght@0,100;0,300;0,400;0,500;0,700;0,900;1,100;1,300;1,400;1,500;1,700;1,900&family=Open+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700;1,800&family=Montserrat:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&family=Poppins:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap" rel="stylesheet">
<style>
    #canvas-wrap { position: relative; }
    #fabric-canvas { border: 1px solid #e5e7eb; border-radius: 8px; display: block; }
    .field-pill { cursor: default; }
    #fields-panel { max-height: calc(100vh - 200px); overflow-y: auto; }
</style>
';

require_once INCLUDES_DIR . '/ui-header.php';
?>

<nav class="bg-white border-b border-gray-200 fixed top-0 left-0 right-0 z-50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex items-center gap-3">
                <a href="<?= getBasePath() ?>printshop/templates.php" class="text-gray-500 hover:text-gray-700 text-sm flex items-center gap-1">
                    <i class="fa-solid fa-arrow-left"></i> <?= htmlspecialchars(t('printshoptpl.editor_back')) ?>
                </a>
                <span class="text-gray-300">/</span>
                <span class="font-semibold text-gray-900 text-sm"><?= $pageTitle ?></span>
            </div>
            <div class="flex items-center gap-3">
                <button id="btn-save" class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">
                    <i class="fa-solid fa-floppy-disk"></i>
                    <?= htmlspecialchars(t('printshoptpl.btn_save_template')) ?>
                </button>
            </div>
        </div>
    </div>
</nav>

<div class="pt-16 h-screen flex flex-col">
<div class="flex-1 flex overflow-hidden">

<!-- Left: Canvas area -->
<div class="flex-1 flex flex-col bg-gray-100 overflow-hidden">
    <div class="flex items-center gap-3 px-4 py-2 bg-white border-b border-gray-200">
        <label class="text-sm font-medium text-gray-700"><?= htmlspecialchars(t('printshoptpl.bg_image_label')) ?></label>
        <label for="bg-upload" class="cursor-pointer text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1.5 rounded-lg transition-colors">
            <i class="fa-solid fa-upload mr-1"></i>
            <?= htmlspecialchars($template && $template['background_path'] ? t('printshoptpl.change_image') : t('printshoptpl.upload_image')) ?>
        </label>
        <input type="file" id="bg-upload" accept="image/*" class="hidden">
        <label for="pdf-import-upload" class="cursor-pointer text-xs bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-lg transition-colors" title="Upload a 1-2 page PDF business card. Cardify auto-detects fields, fonts, colors and the QR area.">
            <i class="fa-solid fa-file-pdf mr-1"></i>Import from PDF
        </label>
        <input type="file" id="pdf-import-upload" accept="application/pdf,.pdf" class="hidden">
        <a href="<?= htmlspecialchars(getBasePath() . 'uploads/docs/Cardify-PDF-Design-Guide.pdf') ?>" target="_blank" class="text-xs text-blue-700 hover:text-blue-900 underline" title="How to prepare a PDF so the import detects every field correctly">
            <i class="fa-solid fa-circle-info mr-1"></i>How to prepare your PDF
        </a>
        <span id="pdf-import-status" class="text-xs text-blue-600 hidden"><i class="fa-solid fa-spinner fa-spin mr-1"></i>Analysing PDF...</span>
        <span class="text-xs text-gray-400" id="bg-filename">
            <?= htmlspecialchars($template && $template['background_path'] ? basename($template['background_path']) : t('printshoptpl.no_file_chosen')) ?>
        </span>
        <div class="ml-auto flex items-center gap-2">
            <span class="text-xs text-gray-500"><?= htmlspecialchars(t('printshoptpl.canvas_size_label')) ?></span>
            <select id="canvas-size" class="text-xs border border-gray-200 rounded px-2 py-1">
                <option value="900,514"><?= htmlspecialchars(t('printshoptpl.size_std')) ?></option>
                <option value="1050,600"><?= htmlspecialchars(t('printshoptpl.size_large')) ?></option>
                <option value="900,900"><?= htmlspecialchars(t('printshoptpl.size_square')) ?></option>
            </select>
        </div>
    </div>
    <div class="flex-1 flex items-center justify-center overflow-auto p-4">
        <div id="canvas-wrap">
            <canvas id="fabric-canvas"></canvas>
        </div>
    </div>
    <!-- Toolbar -->
    <div class="bg-white border-t border-gray-200 px-4 py-2 flex items-center gap-3 flex-wrap">
        <span class="text-xs font-medium text-gray-600 mr-1"><?= htmlspecialchars(t('printshoptpl.add_field_label')) ?></span>
        <?php
        $fieldOptions = [
            ['key' => 'full_name',   'label' => t('printshoptpl.field_full_name'), 'icon' => 'user'],
            ['key' => 'job_title',   'label' => t('printshoptpl.field_job_title'), 'icon' => 'briefcase'],
            ['key' => 'company',     'label' => t('printshoptpl.field_company'),   'icon' => 'building'],
            ['key' => 'phone',       'label' => t('printshoptpl.field_phone'),     'icon' => 'phone'],
            ['key' => 'email',       'label' => t('printshoptpl.field_email'),     'icon' => 'envelope'],
            ['key' => 'website',     'label' => t('printshoptpl.field_website'),   'icon' => 'globe'],
            ['key' => 'address',     'label' => t('printshoptpl.field_address'),   'icon' => 'location-dot'],
            ['key' => 'custom',      'label' => t('printshoptpl.field_custom'),    'icon' => 'font'],
        ];
        foreach ($fieldOptions as $fo): ?>
        <button class="add-field-btn text-xs bg-gray-100 hover:bg-blue-50 hover:text-blue-700 text-gray-600 px-2.5 py-1.5 rounded-lg transition-colors flex items-center gap-1.5"
                data-key="<?= $fo['key'] ?>" data-label="<?= htmlspecialchars($fo['label']) ?>">
            <i class="fa-solid fa-<?= $fo['icon'] ?> text-xs"></i>
            <?= htmlspecialchars($fo['label']) ?>
        </button>
        <?php endforeach; ?>
        <div class="ml-auto flex items-center gap-2">
            <button id="btn-delete-selected" class="text-xs text-red-500 hover:text-red-700 px-2 py-1.5 rounded transition-colors hidden">
                <i class="fa-solid fa-trash mr-1"></i><?= htmlspecialchars(t('printshoptpl.btn_remove')) ?>
            </button>
            <button id="btn-bring-front" class="text-xs text-gray-500 hover:text-gray-700 px-2 py-1.5 rounded transition-colors hidden">
                <i class="fa-solid fa-layer-group mr-1"></i><?= htmlspecialchars(t('printshoptpl.btn_front')) ?>
            </button>
        </div>
    </div>
</div>

<!-- Right: Settings panel -->
<div class="w-72 bg-white border-l border-gray-200 flex flex-col overflow-hidden">
    <div class="px-4 py-3 border-b border-gray-100">
        <h2 class="font-semibold text-sm text-gray-900"><?= htmlspecialchars(t('printshoptpl.settings_h')) ?></h2>
    </div>
    <div id="fields-panel" class="flex-1 overflow-y-auto px-4 py-3 space-y-4">

        <!-- Template Name -->
        <div>
            <label class="text-xs font-medium text-gray-700 block mb-1"><?= htmlspecialchars(t('printshoptpl.field_name_label')) ?></label>
            <input type="text" id="tmpl-name" placeholder="<?= htmlspecialchars(t('printshoptpl.field_name_ph')) ?>"
                   value="<?= sanitize($template['name'] ?? '') ?>"
                   class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
        </div>

        <!-- Description -->
        <div>
            <label class="text-xs font-medium text-gray-700 block mb-1"><?= htmlspecialchars(t('printshoptpl.field_desc_label')) ?></label>
            <textarea id="tmpl-description" placeholder="<?= htmlspecialchars(t('printshoptpl.field_desc_ph')) ?>" rows="2"
                      class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent resize-none"><?= sanitize($template['description'] ?? '') ?></textarea>
        </div>

        <hr class="border-gray-100">

        <!-- Selected object properties -->
        <div id="object-props" class="hidden">
            <h3 class="text-xs font-semibold text-gray-700 mb-3 flex items-center gap-1">
                <i class="fa-solid fa-sliders text-blue-500"></i>
                <?= htmlspecialchars(t('printshoptpl.selected_field_h')) ?>
            </h3>
            <div class="space-y-3">
                <div>
                    <label class="text-xs text-gray-600 block mb-1"><?= htmlspecialchars(t('printshoptpl.placeholder_label')) ?></label>
                    <input type="text" id="prop-placeholder" placeholder="<?= htmlspecialchars(t('printshoptpl.placeholder_ph')) ?>"
                           class="w-full text-xs border border-gray-200 rounded px-2 py-1.5 focus:ring-1 focus:ring-blue-500">
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="text-xs text-gray-600 block mb-1"><?= htmlspecialchars(t('printshoptpl.font_size_label')) ?></label>
                        <input type="number" id="prop-fontsize" value="24" min="8" max="120"
                               class="w-full text-xs border border-gray-200 rounded px-2 py-1.5 focus:ring-1 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="text-xs text-gray-600 block mb-1"><?= htmlspecialchars(t('printshoptpl.color_label')) ?></label>
                        <input type="color" id="prop-color" value="#000000"
                               class="w-full h-8 border border-gray-200 rounded cursor-pointer">
                    </div>
                </div>
                <div>
                    <label class="text-xs text-gray-600 block mb-1"><?= htmlspecialchars(t('printshoptpl.font_label')) ?></label>
                    <select id="prop-font" class="w-full text-xs border border-gray-200 rounded px-2 py-1.5 focus:ring-1 focus:ring-blue-500">
                        <option>Arial</option>
                        <option>Georgia</option>
                        <option>Times New Roman</option>
                        <option>Courier New</option>
                        <option>Verdana</option>
                        <option>Trebuchet MS</option>
                        <option>Impact</option>
                    </select>
                </div>
                <div class="flex gap-2">
                    <button id="prop-bold" class="flex-1 text-xs border border-gray-200 rounded px-2 py-1.5 hover:bg-gray-50 font-bold transition-colors" title="<?= htmlspecialchars(t('printshoptpl.tip_bold')) ?>">B</button>
                    <button id="prop-italic" class="flex-1 text-xs border border-gray-200 rounded px-2 py-1.5 hover:bg-gray-50 italic transition-colors" title="<?= htmlspecialchars(t('printshoptpl.tip_italic')) ?>">I</button>
                    <button id="prop-left" class="flex-1 text-xs border border-gray-200 rounded px-2 py-1.5 hover:bg-gray-50 transition-colors" title="<?= htmlspecialchars(t('printshoptpl.tip_align_left')) ?>"><i class="fa-solid fa-align-left"></i></button>
                    <button id="prop-center" class="flex-1 text-xs border border-gray-200 rounded px-2 py-1.5 hover:bg-gray-50 transition-colors" title="<?= htmlspecialchars(t('printshoptpl.tip_align_center')) ?>"><i class="fa-solid fa-align-center"></i></button>
                    <button id="prop-right" class="flex-1 text-xs border border-gray-200 rounded px-2 py-1.5 hover:bg-gray-50 transition-colors" title="<?= htmlspecialchars(t('printshoptpl.tip_align_right')) ?>"><i class="fa-solid fa-align-right"></i></button>
                </div>
                <div>
                    <label class="text-xs text-gray-600 block mb-1"><?= htmlspecialchars(t('printshoptpl.required_field_q')) ?></label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="prop-required" class="rounded border-gray-300 text-blue-600">
                        <span class="text-xs text-gray-600"><?= htmlspecialchars(t('printshoptpl.required_hint')) ?></span>
                    </label>
                </div>
            </div>
        </div>

        <!-- Field list -->
        <div>
            <h3 class="text-xs font-semibold text-gray-700 mb-2 flex items-center gap-1">
                <i class="fa-solid fa-list text-gray-400"></i>
                <?= htmlspecialchars(t('printshoptpl.fields_on_canvas')) ?>
            </h3>
            <div id="field-list" class="space-y-1 text-xs text-gray-500">
                <p id="field-list-empty" class="italic"><?= htmlspecialchars(t('printshoptpl.no_fields_added')) ?></p>
            </div>
        </div>

    </div>
</div>

</div>
</div>

<!-- Hidden form for save -->
<form id="save-form" method="POST" action="<?= getBasePath() ?>api/shop-templates.php" enctype="multipart/form-data" class="hidden">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="shop_id" value="<?= $shopId ?>">
    <input type="hidden" name="template_id" value="<?= sanitize($templateId) ?>">
    <input type="hidden" name="name" id="form-name">
    <input type="hidden" name="description" id="form-description">
    <input type="hidden" name="canvas_json" id="form-canvas-json">
    <input type="hidden" name="field_definitions" id="form-field-defs">
    <input type="hidden" name="canvas_width" id="form-canvas-width">
    <input type="hidden" name="canvas_height" id="form-canvas-height">
</form>

<script src="https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.1/fabric.min.js"></script>
<script>
(function() {
    const basePath = <?= json_encode(getBasePath()) ?>;
    const shopId = <?= json_encode($shopId) ?>;
    const existingTemplate = <?= json_encode($template) ?>;

    // Force browser to fetch the .woff2 files for the (family, weight, style) tuples
    // before Fabric draws into <canvas>. CSS @font-face is declared lazily, so without
    // this Fabric paints a serif/system fallback on cold cache. See memory note
    // feedback_fabric_canvas_font_preload.md.
    async function preloadFonts(specs) {
        if (!document.fonts || !specs || !specs.length) return;
        const seen = new Set();
        const promises = [];
        for (const s of specs) {
            const family = s.family || 'sans-serif';
            const weight = s.weight || 400;
            const style = s.style || 'normal';
            const key = style + ' ' + weight + ' "' + family + '"';
            if (seen.has(key)) continue;
            seen.add(key);
            try { promises.push(document.fonts.load(style + ' ' + weight + ' 16px "' + family + '"')); }
            catch (e) {}
        }
        try { await Promise.all(promises); } catch (e) {}
        try { await document.fonts.ready; } catch (e) {}
    }

    // Inject a Google Fonts <link> for any imported family the static stylesheet
    // doesn't already cover. Idempotent.
    function ensureGoogleFontLink(family) {
        if (!family) return;
        const id = 'gf-' + family.replace(/[^a-z0-9]/gi, '-').toLowerCase();
        if (document.getElementById(id)) return;
        const link = document.createElement('link');
        link.id = id;
        link.rel = 'stylesheet';
        link.href = 'https://fonts.googleapis.com/css2?family=' + encodeURIComponent(family) +
            ':ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap';
        document.head.appendChild(link);
    }

    // ── Canvas setup ──
    let canvasWidth = 900, canvasHeight = 514;
    const canvas = new fabric.Canvas('fabric-canvas', {
        width: canvasWidth,
        height: canvasHeight,
        backgroundColor: '#ffffff',
        selection: true,
    });

    // Scale canvas to fit container
    function scaleCanvas() {
        const wrap = document.getElementById('canvas-wrap');
        const maxW = wrap.parentElement.clientWidth - 32;
        const scale = Math.min(1, maxW / canvasWidth);
        canvas.setZoom(scale);
        canvas.setWidth(canvasWidth * scale);
        canvas.setHeight(canvasHeight * scale);
    }

    window.addEventListener('resize', scaleCanvas);

    document.getElementById('canvas-size').addEventListener('change', function() {
        const [w, h] = this.value.split(',').map(Number);
        canvasWidth = w; canvasHeight = h;
        canvas.setWidth(w); canvas.setHeight(h);
        canvas.setZoom(1);
        scaleCanvas();
        canvas.renderAll();
    });

    // Load existing template
    if (existingTemplate && existingTemplate.canvas_json) {
        try {
            const data = JSON.parse(existingTemplate.canvas_json);
            if (data.width) { canvasWidth = data.width; canvasHeight = data.height; }
            // Collect fonts referenced in saved canvas so we can preload them before draw
            const savedSpecs = [];
            (data.objects || []).forEach(o => {
                if (o.fontFamily) {
                    ensureGoogleFontLink(o.fontFamily);
                    savedSpecs.push({ family: o.fontFamily, weight: o.fontWeight || 400, style: o.fontStyle || 'normal' });
                }
            });
            preloadFonts(savedSpecs).then(() => {
                canvas.loadFromJSON(existingTemplate.canvas_json, function() {
                    canvas.setWidth(canvasWidth); canvas.setHeight(canvasHeight);
                    scaleCanvas();
                    canvas.renderAll();
                    updateFieldList();
                });
            });
        } catch(e) { scaleCanvas(); }
    } else if (existingTemplate && existingTemplate.background_path) {
        fabric.Image.fromURL(basePath + existingTemplate.background_path.replace(/^\//, ''), function(img) {
            img.scaleToWidth(canvasWidth);
            img.scaleToHeight(canvasHeight);
            img.set({ selectable: false, evented: false, name: '_background' });
            canvas.add(img);
            canvas.sendToBack(img);
            canvas.renderAll();
        });
        scaleCanvas();
    } else {
        scaleCanvas();
    }

    // ── Background upload ──
    let bgFile = null;
    document.getElementById('bg-upload').addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (!file) return;
        bgFile = file;
        document.getElementById('bg-filename').textContent = file.name;
        const reader = new FileReader();
        reader.onload = function(ev) {
            fabric.Image.fromURL(ev.target.result, function(img) {
                // Remove old background
                canvas.getObjects().forEach(obj => {
                    if (obj.name === '_background') canvas.remove(obj);
                });
                img.scaleToWidth(canvasWidth);
                img.scaleToHeight(canvasHeight);
                img.set({ selectable: false, evented: false, name: '_background' });
                canvas.add(img);
                canvas.sendToBack(img);
                canvas.renderAll();
            });
        };
        reader.readAsDataURL(file);
    });

    // ── PDF Import (auto-detect fields from a PDF business card) ──
    document.getElementById('pdf-import-upload').addEventListener('change', async function(e) {
        const file = e.target.files[0];
        if (!file) return;
        const status = document.getElementById('pdf-import-status');
        status.classList.remove('hidden');
        status.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i>Analysing PDF...';

        const fd = new FormData();
        fd.append('pdf', file);
        fd.append('csrf_token', '<?= generateCSRFToken() ?>');
        try {
            const res = await fetch(basePath + 'printshop/import_pdf.php', { method: 'POST', body: fd, credentials: 'same-origin' });
            const data = await res.json();
            if (!res.ok || data.error) {
                status.innerHTML = '<span class="text-red-600"><i class="fa-solid fa-triangle-exclamation mr-1"></i>Import failed: ' + (data.error || res.status) + '</span>';
                if (data.parser_output) console.error(data.parser_output);
                return;
            }
            applyImportedTemplate(data);
            status.innerHTML = '<span class="text-green-600"><i class="fa-solid fa-check mr-1"></i>Imported ' + data.pages.length + ' pages, ' +
                data.pages.reduce((n,p)=>n+p.fields.length,0) + ' fields detected.</span>';
            setTimeout(() => status.classList.add('hidden'), 8000);
        } catch (err) {
            status.innerHTML = '<span class="text-red-600"><i class="fa-solid fa-triangle-exclamation mr-1"></i>' + err.message + '</span>';
        }
    });

    // Apply parsed template to the Fabric canvas. For multi-page (front + back),
    // we currently load page 2 (back) by default since that's where fields live;
    // a future tweak is a side-toggle button.
    let importedPages = null;
    let activePageIndex = 0;
    function applyImportedTemplate(data) {
        importedPages = data.pages;
        // Pick the page with the most fields (usually the back)
        let bestIdx = 0;
        data.pages.forEach((p, i) => { if (p.fields.length > (data.pages[bestIdx].fields.length||0)) bestIdx = i; });
        activePageIndex = bestIdx;
        const page = data.pages[bestIdx];
        loadPageOntoCanvas(page);
        renderSideSwitcher(data.pages);
        renderMissingFontsBanner(data.missing_fonts || []);
    }

    function loadPageOntoCanvas(page) {
        // Resize canvas to PDF aspect ratio (constrained to a workable width)
        const targetW = 1100;
        const ratio = page.width_px / page.height_px;
        canvasWidth = targetW;
        canvasHeight = Math.round(targetW / ratio);
        canvas.setWidth(canvasWidth);
        canvas.setHeight(canvasHeight);

        // Clear existing
        canvas.getObjects().slice().forEach(o => canvas.remove(o));

        const scaleX = canvasWidth / page.width_px;
        const scaleY = canvasHeight / page.height_px;

        fabric.Image.fromURL(basePath + page.background_url.replace(/^\//, ''), async function(img) {
            img.scaleToWidth(canvasWidth);
            img.scaleToHeight(canvasHeight);
            img.set({ selectable: false, evented: false, name: '_background' });
            canvas.add(img);
            canvas.sendToBack(img);

            // Preload every (family, weight, style) the imported page references so Fabric
            // doesn't draw with serif fallback on cold cache.
            const detectedFamilies = new Set();
            const fontSpecs = page.fields.map(f => {
                if (f.font_family) detectedFamilies.add(f.font_family);
                ensureGoogleFontLink(f.font_family);
                return {
                    family: f.font_family || 'sans-serif',
                    weight: f.font_weight || 400,
                    style: f.italic ? 'italic' : 'normal',
                };
            });
            await preloadFonts(fontSpecs);
            populateFontDropdown(detectedFamilies);

            // Place each detected field as a Fabric.IText at the matching x/y/font/size/color
            page.fields.forEach(f => {
                const labelMap = {
                    'name_en': 'Full Name', 'position_en': 'Job Title',
                    'mobile': 'Phone', 'email': 'Email', 'website': 'Website',
                    'address': 'Address', 'social': 'Social', 'company_tagline': 'Tagline (static)',
                    'qr_code': 'QR Code', 'custom': null,
                };
                // Preserve detected_text exactly (whitespace included). Fabric needs the raw
                // string for static layout segments to keep their positioning intent.
                const rawDetected = (f.detected_text != null) ? String(f.detected_text) : '';
                const placeholder = (f.is_static || f.field_key === 'custom')
                    ? rawDetected
                    : '[' + (labelMap[f.field_key] || f.field_key) + ']';
                if ((f.is_static || f.field_key === 'custom') && !placeholder.replace(/\s+/g, '')) return;

                const txt = new fabric.IText(placeholder, {
                    left: f.x_px * scaleX,
                    top:  f.y_px * scaleY,
                    fontSize: f.font_size_px * scaleY,
                    fontFamily: f.font_family,
                    // Use numeric weight directly so Lato-Medium (500) doesn't collapse to 'normal'
                    fontWeight: f.font_weight || 400,
                    fontStyle: f.italic ? 'italic' : 'normal',
                    fill: f.color,
                    name: 'field_' + f.field_key,
                    fieldKey: f.field_key,
                    fieldLabel: labelMap[f.field_key] || f.field_key,
                    editable: true,
                    fixed: f.is_static,
                });
                canvas.add(txt);
            });

            // QR placeholder rectangle
            if (page.qr_area) {
                const rect = new fabric.Rect({
                    left: page.qr_area.x_pt * (canvasWidth / page.width_pt),
                    top:  page.qr_area.y_pt * (canvasHeight / page.height_pt),
                    width:  page.qr_area.w_pt * (canvasWidth / page.width_pt),
                    height: page.qr_area.h_pt * (canvasHeight / page.height_pt),
                    fill: 'rgba(255,255,255,0.95)',
                    stroke: '#2d13ea',
                    strokeDashArray: [6, 4],
                    strokeWidth: 2,
                    name: 'field_qr_code',
                    fieldKey: 'qr_code',
                    fieldLabel: 'QR Code',
                });
                rect.toObject = (function(o){return function(p){return Object.assign(o.call(this,p),{fieldKey:this.fieldKey,fieldLabel:this.fieldLabel,name:this.name});};})(rect.toObject);
                canvas.add(rect);
            }

            scaleCanvas();
            canvas.renderAll();
            if (typeof updateFieldList === 'function') updateFieldList();
        });
    }

    // Add detected font families to the prop-font dropdown so the printshop can
    // pick the actual imported fonts (Lato/Sora/Cairo/etc), not just system stock.
    function populateFontDropdown(families) {
        const sel = document.getElementById('prop-font');
        if (!sel || !families) return;
        const existing = new Set(Array.from(sel.options).map(o => o.value));
        let added = false;
        families.forEach(fam => {
            if (!fam || existing.has(fam)) return;
            const opt = document.createElement('option');
            opt.value = fam;
            opt.textContent = fam + ' (imported)';
            sel.insertBefore(opt, sel.firstChild);
            added = true;
        });
        if (added) sel.selectedIndex = 0;
    }

    function renderSideSwitcher(pages) {
        let bar = document.getElementById('pdf-side-switcher');
        if (!bar) {
            bar = document.createElement('div');
            bar.id = 'pdf-side-switcher';
            bar.className = 'flex items-center gap-2 px-4 py-1.5 bg-blue-50 border-b border-blue-100 text-xs';
            const tools = document.querySelector('#bg-upload').closest('.flex').parentNode;
            tools.parentNode.insertBefore(bar, tools.nextSibling);
        }
        bar.innerHTML = '<span class="font-semibold text-blue-900">PDF imported:</span> ' +
            pages.map((p, i) =>
                `<button data-side="${i}" class="pdf-side-btn px-2 py-1 rounded ${i===activePageIndex?'bg-blue-600 text-white':'bg-white text-blue-700 hover:bg-blue-100'}">${p.side} (page ${p.page_number}, ${p.fields.length} fields)</button>`
            ).join(' ');
        bar.querySelectorAll('.pdf-side-btn').forEach(b => {
            b.addEventListener('click', () => {
                activePageIndex = parseInt(b.dataset.side, 10);
                loadPageOntoCanvas(importedPages[activePageIndex]);
                renderSideSwitcher(importedPages);
            });
        });
    }

    function renderMissingFontsBanner(missing) {
        let bar = document.getElementById('missing-fonts-banner');
        if (missing.length === 0) {
            if (bar) bar.remove();
            return;
        }
        if (!bar) {
            bar = document.createElement('div');
            bar.id = 'missing-fonts-banner';
            bar.className = 'flex items-center gap-3 px-4 py-2 bg-amber-50 border-b border-amber-200 text-xs';
            const switcher = document.getElementById('pdf-side-switcher');
            (switcher || document.querySelector('#bg-upload').closest('.flex').parentNode).after(bar);
        }
        bar.innerHTML = '<i class="fa-solid fa-triangle-exclamation text-amber-600"></i>' +
            '<span class="font-semibold text-amber-900">Missing fonts:</span>' +
            missing.map(f => `<span class="px-2 py-0.5 bg-amber-200 text-amber-900 rounded font-mono">${f.family}</span>`).join('') +
            '<label class="ml-auto cursor-pointer bg-amber-600 hover:bg-amber-700 text-white px-3 py-1 rounded">' +
            '<i class="fa-solid fa-upload mr-1"></i>Upload .ttf / .otf' +
            '<input type="file" id="font-upload-input" accept=".ttf,.otf,.woff,.woff2" multiple class="hidden">' +
            '</label>';
        bar.querySelector('#font-upload-input').addEventListener('change', async function(e) {
            const fd = new FormData();
            for (const f of e.target.files) fd.append('fonts[]', f);
            fd.append('csrf_token', '<?= generateCSRFToken() ?>');
            const res = await fetch(basePath + 'printshop/upload_fonts.php', { method: 'POST', body: fd, credentials: 'same-origin' });
            const data = await res.json();
            if (data.uploaded) {
                bar.innerHTML = '<i class="fa-solid fa-check text-green-600"></i><span class="text-green-900">' + data.uploaded.length + ' font(s) uploaded. Reload to apply.</span>';
            } else {
                bar.innerHTML = '<i class="fa-solid fa-triangle-exclamation text-red-600"></i>' + (data.error || 'Upload failed');
            }
        });
    }

    // ── Field tracking ──
    let fieldCounter = {};

    function makeFieldName(key) {
        fieldCounter[key] = (fieldCounter[key] || 0) + 1;
        return 'field_' + key + (fieldCounter[key] > 1 ? '_' + fieldCounter[key] : '');
    }

    // ── Add field buttons ──
    document.querySelectorAll('.add-field-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const key = this.dataset.key;
            const label = this.dataset.label;
            let fieldKey = key === 'custom' ? 'custom_' + Date.now() : key;
            const fieldName = 'field_' + fieldKey;

            const text = new fabric.IText('[' + label + ']', {
                left: 100,
                top: 100,
                fontSize: 28,
                fill: '#000000',
                fontFamily: 'Arial',
                name: fieldName,
                fieldKey: fieldKey,
                fieldLabel: label,
                fieldPlaceholder: '',
                fieldRequired: true,
                padding: 4,
            });

            canvas.add(text);
            canvas.setActiveObject(text);
            canvas.renderAll();
            updateFieldList();
        });
    });

    // ── Selection change ──
    function updatePropPanel(obj) {
        const panel = document.getElementById('object-props');
        const btnDel = document.getElementById('btn-delete-selected');
        const btnFront = document.getElementById('btn-bring-front');

        if (obj && obj.type === 'i-text' && obj.name && obj.name.startsWith('field_')) {
            panel.classList.remove('hidden');
            btnDel.classList.remove('hidden');
            btnFront.classList.remove('hidden');
            document.getElementById('prop-placeholder').value = obj.fieldPlaceholder || '';
            document.getElementById('prop-fontsize').value = obj.fontSize || 24;
            document.getElementById('prop-color').value = obj.fill || '#000000';
            document.getElementById('prop-font').value = obj.fontFamily || 'Arial';
            document.getElementById('prop-required').checked = obj.fieldRequired !== false;
        } else {
            panel.classList.add('hidden');
            btnDel.classList.add('hidden');
            btnFront.classList.add('hidden');
        }
    }

    canvas.on('selection:created', e => updatePropPanel(e.selected[0]));
    canvas.on('selection:updated', e => updatePropPanel(e.selected[0]));
    canvas.on('selection:cleared', () => updatePropPanel(null));

    // Property controls
    function applyProp(fn) {
        const obj = canvas.getActiveObject();
        if (obj && obj.name && obj.name.startsWith('field_')) {
            fn(obj);
            canvas.renderAll();
        }
    }

    document.getElementById('prop-placeholder').addEventListener('input', function() {
        applyProp(obj => obj.fieldPlaceholder = this.value);
    });
    document.getElementById('prop-fontsize').addEventListener('input', function() {
        applyProp(obj => obj.set('fontSize', parseInt(this.value) || 24));
    });
    document.getElementById('prop-color').addEventListener('input', function() {
        applyProp(obj => obj.set('fill', this.value));
    });
    document.getElementById('prop-font').addEventListener('change', function() {
        applyProp(obj => obj.set('fontFamily', this.value));
    });
    document.getElementById('prop-bold').addEventListener('click', function() {
        applyProp(obj => obj.set('fontWeight', obj.fontWeight === 'bold' ? 'normal' : 'bold'));
    });
    document.getElementById('prop-italic').addEventListener('click', function() {
        applyProp(obj => obj.set('fontStyle', obj.fontStyle === 'italic' ? 'normal' : 'italic'));
    });
    document.getElementById('prop-left').addEventListener('click', () => applyProp(obj => obj.set('textAlign', 'left')));
    document.getElementById('prop-center').addEventListener('click', () => applyProp(obj => obj.set('textAlign', 'center')));
    document.getElementById('prop-right').addEventListener('click', () => applyProp(obj => obj.set('textAlign', 'right')));
    document.getElementById('prop-required').addEventListener('change', function() {
        applyProp(obj => obj.fieldRequired = this.checked);
    });

    document.getElementById('btn-delete-selected').addEventListener('click', function() {
        const obj = canvas.getActiveObject();
        if (obj) { canvas.remove(obj); canvas.renderAll(); updateFieldList(); updatePropPanel(null); }
    });
    document.getElementById('btn-bring-front').addEventListener('click', function() {
        const obj = canvas.getActiveObject();
        if (obj) { canvas.bringToFront(obj); canvas.renderAll(); }
    });

    // Update field list on right panel
    canvas.on('object:added', updateFieldList);
    canvas.on('object:removed', updateFieldList);

    function updateFieldList() {
        const list = document.getElementById('field-list');
        const empty = document.getElementById('field-list-empty');
        const fields = canvas.getObjects('i-text').filter(o => o.name && o.name.startsWith('field_'));
        if (fields.length === 0) {
            empty.style.display = '';
            list.querySelectorAll('.field-pill').forEach(el => el.remove());
            return;
        }
        empty.style.display = 'none';
        // Rebuild
        list.querySelectorAll('.field-pill').forEach(el => el.remove());
        fields.forEach(obj => {
            const pill = document.createElement('div');
            pill.className = 'field-pill flex items-center gap-2 bg-gray-50 rounded-lg px-2 py-1.5 cursor-pointer hover:bg-blue-50';
            pill.innerHTML = `
                <i class="fa-solid fa-font text-gray-400 text-xs"></i>
                <span class="flex-1 text-xs text-gray-700">${obj.fieldLabel || obj.name}</span>
                ${obj.fieldRequired ? '<span class="text-red-400 text-xs font-bold">*</span>' : ''}
            `;
            pill.addEventListener('click', () => {
                canvas.setActiveObject(obj);
                canvas.renderAll();
                updatePropPanel(obj);
            });
            list.appendChild(pill);
        });
    }

    // ── Save ──
    document.getElementById('btn-save').addEventListener('click', async function() {
        const name = document.getElementById('tmpl-name').value.trim();
        if (!name) { alert('Please enter a template name.'); return; }

        // Collect field definitions from canvas
        const fields = canvas.getObjects('i-text')
            .filter(o => o.name && o.name.startsWith('field_'))
            .map(obj => ({
                key: obj.fieldKey || obj.name.replace(/^field_/, ''),
                label: obj.fieldLabel || obj.name.replace(/^field_/, ''),
                placeholder: obj.fieldPlaceholder || '',
                required: obj.fieldRequired !== false,
                objectName: obj.name,
            }));

        // Build form data
        const fd = new FormData(document.getElementById('save-form'));
        fd.set('name', name);
        fd.set('description', document.getElementById('tmpl-description').value.trim());
        fd.set('canvas_json', JSON.stringify(canvas.toJSON(['name','fieldKey','fieldLabel','fieldPlaceholder','fieldRequired'])));
        fd.set('field_definitions', JSON.stringify(fields));
        fd.set('canvas_width', canvasWidth);
        fd.set('canvas_height', canvasHeight);

        if (bgFile) fd.set('background_image', bgFile);

        this.disabled = true;
        this.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i>Saving...';

        try {
            const resp = await fetch(basePath + 'api/shop-templates.php', { method: 'POST', body: fd });
            const data = await resp.json();
            if (data.success) {
                window.location.href = basePath + 'printshop/templates.php';
            } else {
                alert('Error: ' + (data.error || 'Save failed'));
                this.disabled = false;
                this.innerHTML = '<i class="fa-solid fa-floppy-disk mr-1"></i>Save Template';
            }
        } catch(err) {
            alert('Network error: ' + err.message);
            this.disabled = false;
            this.innerHTML = '<i class="fa-solid fa-floppy-disk mr-1"></i>Save Template';
        }
    });

    // ── Keyboard: delete selected with Delete key ──
    document.addEventListener('keydown', function(e) {
        if ((e.key === 'Delete' || e.key === 'Backspace') && !['INPUT','TEXTAREA'].includes(document.activeElement.tagName)) {
            const obj = canvas.getActiveObject();
            if (obj && obj.name && obj.name.startsWith('field_')) {
                canvas.remove(obj); canvas.renderAll(); updateFieldList(); updatePropPanel(null);
                e.preventDefault();
            }
        }
    });

})();
</script>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
