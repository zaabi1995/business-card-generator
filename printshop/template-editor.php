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

$pageTitle = $template ? 'Edit Template' : 'New Template';
$bodyClass = 'bg-gray-50';

// Extra head for Fabric.js
$extraHead = '
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
                    <i class="fa-solid fa-arrow-left"></i> Templates
                </a>
                <span class="text-gray-300">/</span>
                <span class="font-semibold text-gray-900 text-sm"><?= $pageTitle ?></span>
            </div>
            <div class="flex items-center gap-3">
                <button id="btn-save" class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">
                    <i class="fa-solid fa-floppy-disk"></i>
                    Save Template
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
        <label class="text-sm font-medium text-gray-700">Background Image:</label>
        <label for="bg-upload" class="cursor-pointer text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1.5 rounded-lg transition-colors">
            <i class="fa-solid fa-upload mr-1"></i>
            <?= $template && $template['background_path'] ? 'Change Image' : 'Upload Image' ?>
        </label>
        <input type="file" id="bg-upload" accept="image/*" class="hidden">
        <span class="text-xs text-gray-400" id="bg-filename">
            <?= $template && $template['background_path'] ? basename($template['background_path']) : 'No file chosen' ?>
        </span>
        <div class="ml-auto flex items-center gap-2">
            <span class="text-xs text-gray-500">Canvas size:</span>
            <select id="canvas-size" class="text-xs border border-gray-200 rounded px-2 py-1">
                <option value="900,514">Business Card (Standard)</option>
                <option value="1050,600">Business Card (Large)</option>
                <option value="900,900">Square Card</option>
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
        <span class="text-xs font-medium text-gray-600 mr-1">Add Field:</span>
        <?php
        $fieldOptions = [
            ['key' => 'full_name',   'label' => 'Full Name',   'icon' => 'user'],
            ['key' => 'job_title',   'label' => 'Job Title',   'icon' => 'briefcase'],
            ['key' => 'company',     'label' => 'Company',     'icon' => 'building'],
            ['key' => 'phone',       'label' => 'Phone',       'icon' => 'phone'],
            ['key' => 'email',       'label' => 'Email',       'icon' => 'envelope'],
            ['key' => 'website',     'label' => 'Website',     'icon' => 'globe'],
            ['key' => 'address',     'label' => 'Address',     'icon' => 'location-dot'],
            ['key' => 'custom',      'label' => 'Custom',      'icon' => 'font'],
        ];
        foreach ($fieldOptions as $fo): ?>
        <button class="add-field-btn text-xs bg-gray-100 hover:bg-blue-50 hover:text-blue-700 text-gray-600 px-2.5 py-1.5 rounded-lg transition-colors flex items-center gap-1.5"
                data-key="<?= $fo['key'] ?>" data-label="<?= $fo['label'] ?>">
            <i class="fa-solid fa-<?= $fo['icon'] ?> text-xs"></i>
            <?= $fo['label'] ?>
        </button>
        <?php endforeach; ?>
        <div class="ml-auto flex items-center gap-2">
            <button id="btn-delete-selected" class="text-xs text-red-500 hover:text-red-700 px-2 py-1.5 rounded transition-colors hidden">
                <i class="fa-solid fa-trash mr-1"></i>Remove
            </button>
            <button id="btn-bring-front" class="text-xs text-gray-500 hover:text-gray-700 px-2 py-1.5 rounded transition-colors hidden">
                <i class="fa-solid fa-layer-group mr-1"></i>Front
            </button>
        </div>
    </div>
</div>

<!-- Right: Settings panel -->
<div class="w-72 bg-white border-l border-gray-200 flex flex-col overflow-hidden">
    <div class="px-4 py-3 border-b border-gray-100">
        <h2 class="font-semibold text-sm text-gray-900">Template Settings</h2>
    </div>
    <div id="fields-panel" class="flex-1 overflow-y-auto px-4 py-3 space-y-4">

        <!-- Template Name -->
        <div>
            <label class="text-xs font-medium text-gray-700 block mb-1">Template Name *</label>
            <input type="text" id="tmpl-name" placeholder="e.g. Classic Business Card"
                   value="<?= sanitize($template['name'] ?? '') ?>"
                   class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
        </div>

        <!-- Description -->
        <div>
            <label class="text-xs font-medium text-gray-700 block mb-1">Description</label>
            <textarea id="tmpl-description" placeholder="Brief description..." rows="2"
                      class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent resize-none"><?= sanitize($template['description'] ?? '') ?></textarea>
        </div>

        <hr class="border-gray-100">

        <!-- Selected object properties -->
        <div id="object-props" class="hidden">
            <h3 class="text-xs font-semibold text-gray-700 mb-3 flex items-center gap-1">
                <i class="fa-solid fa-sliders text-blue-500"></i>
                Selected Field
            </h3>
            <div class="space-y-3">
                <div>
                    <label class="text-xs text-gray-600 block mb-1">Placeholder Text</label>
                    <input type="text" id="prop-placeholder" placeholder="e.g. John Smith"
                           class="w-full text-xs border border-gray-200 rounded px-2 py-1.5 focus:ring-1 focus:ring-blue-500">
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="text-xs text-gray-600 block mb-1">Font Size</label>
                        <input type="number" id="prop-fontsize" value="24" min="8" max="120"
                               class="w-full text-xs border border-gray-200 rounded px-2 py-1.5 focus:ring-1 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="text-xs text-gray-600 block mb-1">Color</label>
                        <input type="color" id="prop-color" value="#000000"
                               class="w-full h-8 border border-gray-200 rounded cursor-pointer">
                    </div>
                </div>
                <div>
                    <label class="text-xs text-gray-600 block mb-1">Font</label>
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
                    <button id="prop-bold" class="flex-1 text-xs border border-gray-200 rounded px-2 py-1.5 hover:bg-gray-50 font-bold transition-colors" title="Bold">B</button>
                    <button id="prop-italic" class="flex-1 text-xs border border-gray-200 rounded px-2 py-1.5 hover:bg-gray-50 italic transition-colors" title="Italic">I</button>
                    <button id="prop-left" class="flex-1 text-xs border border-gray-200 rounded px-2 py-1.5 hover:bg-gray-50 transition-colors" title="Left align"><i class="fa-solid fa-align-left"></i></button>
                    <button id="prop-center" class="flex-1 text-xs border border-gray-200 rounded px-2 py-1.5 hover:bg-gray-50 transition-colors" title="Center"><i class="fa-solid fa-align-center"></i></button>
                    <button id="prop-right" class="flex-1 text-xs border border-gray-200 rounded px-2 py-1.5 hover:bg-gray-50 transition-colors" title="Right align"><i class="fa-solid fa-align-right"></i></button>
                </div>
                <div>
                    <label class="text-xs text-gray-600 block mb-1">Required field?</label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="prop-required" class="rounded border-gray-300 text-blue-600">
                        <span class="text-xs text-gray-600">Customers must fill this</span>
                    </label>
                </div>
            </div>
        </div>

        <!-- Field list -->
        <div>
            <h3 class="text-xs font-semibold text-gray-700 mb-2 flex items-center gap-1">
                <i class="fa-solid fa-list text-gray-400"></i>
                Fields on Canvas
            </h3>
            <div id="field-list" class="space-y-1 text-xs text-gray-500">
                <p id="field-list-empty" class="italic">No fields added yet. Click a field type above.</p>
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
            canvas.loadFromJSON(existingTemplate.canvas_json, function() {
                canvas.setWidth(canvasWidth); canvas.setHeight(canvasHeight);
                scaleCanvas();
                canvas.renderAll();
                updateFieldList();
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
