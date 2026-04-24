<?php
/**
 * Customer Card Customizer
 * Customer fills in their details, sees live canvas preview, and submits an order request
 * Route: /customize.php?template=TEMPLATE_ID
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/PrintShop.php';

$templateId = trim($_GET['template'] ?? '');
if (!$templateId) {
    http_response_code(404);
    include __DIR__ . '/404.php';
    exit;
}

$db = Database::getInstance();
$template = $db->fetchOne(
    "SELECT t.*, s.name as shop_name, s.whatsapp as shop_whatsapp, s.phone as shop_phone, s.logo_url as shop_logo
     FROM shop_templates t
     LEFT JOIN print_shops s ON t.print_shop_id = s.id
     WHERE t.id = :id AND t.is_active = 1",
    ['id' => $templateId]
);

if (!$template) {
    http_response_code(404);
    include __DIR__ . '/404.php';
    exit;
}

$fieldDefinitions = json_decode($template['field_definitions'] ?? '[]', true) ?: [];

$pageTitle = 'Customize: ' . $template['name'];
$pageDescription = 'Personalize your ' . $template['name'] . ' business card from ' . ($template['shop_name'] ?? 'BHD Printing');
$bodyClass = 'bg-gray-50';

$extraHead = '
<style>
    #canvas-wrap canvas { max-width: 100%; border-radius: 8px; box-shadow: 0 4px 24px rgba(0,0,0,0.10); }
    .field-input { transition: border-color 0.15s; }
    .field-input:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.15); }
    @media (max-width: 767px) {
        #canvas-wrap { overflow-x: auto; }
    }
</style>
';

require_once INCLUDES_DIR . '/ui-header.php';
?>

<!-- Nav -->
<nav class="bg-white border-b border-gray-200">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-14">
            <div class="flex items-center gap-3">
                <a href="<?= getBasePath() ?>bhd/templates.php" class="text-sm text-gray-500 hover:text-gray-700 flex items-center gap-1">
                    <i class="fa-solid fa-arrow-left text-xs"></i> All Templates
                </a>
                <span class="text-gray-300">/</span>
                <span class="text-sm font-medium text-gray-700 truncate max-w-40 sm:max-w-none"><?= sanitize($template['name']) ?></span>
            </div>
            <div class="flex items-center gap-2">
                <?php if ($template['shop_logo']): ?>
                    <img src="<?= sanitize($template['shop_logo']) ?>" alt="<?= sanitize($template['shop_name'] ?? '') ?>" class="h-7 w-auto">
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>

<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

    <div class="lg:grid lg:grid-cols-5 lg:gap-8">

        <!-- Canvas preview (left, wider) -->
        <div class="lg:col-span-3 mb-6 lg:mb-0">
            <div class="bg-white rounded-2xl border border-gray-200 p-4 sm:p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="font-semibold text-gray-900">Live Preview</h2>
                    <span class="text-xs text-gray-400 bg-gray-100 px-2 py-1 rounded-full">Updates as you type</span>
                </div>
                <div id="canvas-wrap" class="flex justify-center">
                    <canvas id="fabric-canvas"></canvas>
                </div>
                <p class="text-xs text-gray-400 text-center mt-3">Preview only, actual print may vary slightly</p>
            </div>
        </div>

        <!-- Form (right) -->
        <div class="lg:col-span-2">
            <div class="bg-white rounded-2xl border border-gray-200 p-5">
                <h2 class="font-semibold text-gray-900 mb-1">Your Details</h2>
                <p class="text-xs text-gray-500 mb-4">Fill in your information below</p>

                <div id="field-form" class="space-y-3">
                    <?php foreach ($fieldDefinitions as $field): ?>
                    <?php $fieldKey = sanitize($field['key'] ?? ''); $fieldLabel = sanitize($field['label'] ?? $fieldKey); ?>
                    <div>
                        <label class="text-xs font-medium text-gray-700 block mb-1">
                            <?= $fieldLabel ?>
                            <?php if (!empty($field['required'])): ?><span class="text-red-500 ml-0.5">*</span><?php endif; ?>
                        </label>
                        <input type="text"
                               class="field-input w-full text-sm border border-gray-200 rounded-xl px-3 py-2.5 outline-none"
                               data-field-key="<?= $fieldKey ?>"
                               placeholder="<?= sanitize($field['placeholder'] ?? $fieldLabel) ?>"
                               autocomplete="off">
                    </div>
                    <?php endforeach; ?>

                    <?php if (empty($fieldDefinitions)): ?>
                    <p class="text-xs text-gray-400 italic">No editable fields defined for this template.</p>
                    <?php endif; ?>
                </div>

                <hr class="my-4 border-gray-100">

                <!-- Contact info for order -->
                <h3 class="text-xs font-semibold text-gray-700 uppercase tracking-wide mb-3">Order Contact</h3>
                <div class="space-y-3">
                    <div>
                        <label class="text-xs font-medium text-gray-700 block mb-1">Your Name <span class="text-red-500">*</span></label>
                        <input type="text" id="customer-name" placeholder="Full name"
                               class="field-input w-full text-sm border border-gray-200 rounded-xl px-3 py-2.5 outline-none">
                    </div>
                    <div>
                        <label class="text-xs font-medium text-gray-700 block mb-1">WhatsApp / Phone <span class="text-red-500">*</span></label>
                        <input type="tel" id="customer-phone" placeholder="+968 XXXX XXXX"
                               class="field-input w-full text-sm border border-gray-200 rounded-xl px-3 py-2.5 outline-none">
                    </div>
                    <div>
                        <label class="text-xs font-medium text-gray-700 block mb-1">Email (optional)</label>
                        <input type="email" id="customer-email" placeholder="you@company.com"
                               class="field-input w-full text-sm border border-gray-200 rounded-xl px-3 py-2.5 outline-none">
                    </div>
                    <div>
                        <label class="text-xs font-medium text-gray-700 block mb-1">Quantity</label>
                        <select id="customer-quantity" class="w-full text-sm border border-gray-200 rounded-xl px-3 py-2.5 outline-none bg-white">
                            <option value="100">100 cards</option>
                            <option value="200">200 cards</option>
                            <option value="500">500 cards</option>
                            <option value="1000">1000 cards</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-medium text-gray-700 block mb-1">Special notes (optional)</label>
                        <textarea id="customer-notes" rows="2" placeholder="Any special requests..."
                                  class="field-input w-full text-sm border border-gray-200 rounded-xl px-3 py-2.5 outline-none resize-none"></textarea>
                    </div>
                </div>

                <button id="btn-order" class="mt-4 w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 rounded-xl text-sm transition-colors flex items-center justify-center gap-2">
                    <i class="fa-solid fa-paper-plane"></i>
                    Submit Card Request
                </button>

                <p class="text-xs text-gray-400 text-center mt-2">BHD Printing will contact you to confirm your order</p>
            </div>
        </div>
    </div>
</div>

<!-- Success modal -->
<div id="success-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 px-4">
    <div class="bg-white rounded-2xl max-w-sm w-full p-6 text-center shadow-xl">
        <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <i class="fa-solid fa-check text-2xl text-green-600"></i>
        </div>
        <h3 class="text-xl font-bold text-gray-900 mb-2">Request Sent!</h3>
        <p class="text-sm text-gray-600 mb-5">BHD Printing has received your card request and will contact you soon to confirm.</p>
        <a id="wa-link" href="#" target="_blank"
           class="flex items-center justify-center gap-2 w-full bg-green-500 hover:bg-green-600 text-white font-medium py-3 rounded-xl text-sm transition-colors mb-3">
            <i class="fa-brands fa-whatsapp text-base"></i>
            Chat with BHD on WhatsApp
        </a>
        <a href="<?= getBasePath() ?>bhd/templates.php" class="text-sm text-gray-400 hover:text-gray-600">Browse more templates</a>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.1/fabric.min.js"></script>
<script>
(function() {
    const basePath = <?= json_encode(getBasePath()) ?>;
    const templateData = <?= json_encode($template) ?>;
    const fieldDefs = <?= json_encode($fieldDefinitions) ?>;
    const csrfToken = <?= json_encode(generateCSRFToken()) ?>;
    const BHD_WA = '96899999100'; // BHD WhatsApp fallback

    // ── Canvas setup ──
    const canvasWidth = 900, canvasHeight = 514;
    const canvas = new fabric.Canvas('fabric-canvas', {
        width: canvasWidth,
        height: canvasHeight,
        selection: false,
        hoverCursor: 'default',
    });

    // Disable interaction
    canvas.on('mouse:down', e => { if (e.target) canvas.discardActiveObject(); });

    // Scale to container
    function scaleCanvas() {
        const wrap = document.getElementById('canvas-wrap');
        const maxW = wrap.clientWidth - 0;
        const scale = Math.min(1, maxW / canvasWidth);
        canvas.setZoom(scale);
        canvas.setWidth(Math.floor(canvasWidth * scale));
        canvas.setHeight(Math.floor(canvasHeight * scale));
    }
    window.addEventListener('resize', scaleCanvas);

    // Load canvas JSON
    let canvasReady = false;
    if (templateData.canvas_json) {
        canvas.loadFromJSON(templateData.canvas_json, function() {
            // Make all objects non-interactive
            canvas.getObjects().forEach(obj => {
                obj.selectable = false;
                obj.evented = false;
                obj.hoverCursor = 'default';
            });
            scaleCanvas();
            canvas.renderAll();
            canvasReady = true;
        });
    } else if (templateData.background_path) {
        fabric.Image.fromURL(basePath + templateData.background_path.replace(/^\//, ''), function(img) {
            img.scaleToWidth(canvasWidth);
            img.scaleToHeight(canvasHeight);
            img.set({ selectable: false, evented: false });
            canvas.add(img);
            scaleCanvas();
            canvas.renderAll();
            canvasReady = true;
        });
    } else {
        canvas.setBackgroundColor('#f3f4f6', canvas.renderAll.bind(canvas));
        scaleCanvas();
        canvasReady = true;
    }

    // ── Live preview: update field text objects ──
    function updateCanvas(fieldKey, value) {
        const objectName = 'field_' + fieldKey;
        const objects = canvas.getObjects('i-text');
        objects.forEach(obj => {
            if (obj.name === objectName || obj.name === 'field_' + fieldKey) {
                const displayText = value || ('[' + (fieldDefs.find(f => f.key === fieldKey)?.label || fieldKey) + ']');
                obj.set('text', displayText);
            }
        });
        canvas.renderAll();
    }

    // Bind field inputs
    document.querySelectorAll('.field-input[data-field-key]').forEach(input => {
        input.addEventListener('input', function() {
            updateCanvas(this.dataset.fieldKey, this.value.trim());
        });
    });

    // ── Submit order ──
    document.getElementById('btn-order').addEventListener('click', async function() {
        const customerName = document.getElementById('customer-name').value.trim();
        const customerPhone = document.getElementById('customer-phone').value.trim();
        const customerEmail = document.getElementById('customer-email').value.trim();
        const quantity = document.getElementById('customer-quantity').value;
        const notes = document.getElementById('customer-notes').value.trim();

        if (!customerName || !customerPhone) {
            alert('Please enter your name and phone number.');
            return;
        }

        // Collect field values
        const fieldValues = {};
        document.querySelectorAll('.field-input[data-field-key]').forEach(input => {
            fieldValues[input.dataset.fieldKey] = input.value.trim();
        });

        // Check required fields
        const missingRequired = fieldDefs.filter(f => f.required && !fieldValues[f.key]);
        if (missingRequired.length > 0) {
            alert('Please fill in: ' + missingRequired.map(f => f.label).join(', '));
            return;
        }

        this.disabled = true;
        this.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i>Sending...';

        // Capture canvas preview
        let previewData = '';
        try { previewData = canvas.toDataURL({ format: 'png', multiplier: 0.5 }); } catch(e) {}

        // Canvas snapshot JSON
        let canvasSnapshot = '';
        try {
            canvasSnapshot = JSON.stringify(canvas.toJSON(['name', 'fieldKey', 'fieldLabel']));
        } catch(e) {}

        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('action', 'submit_request');
        fd.append('template_id', templateData.id);
        fd.append('customer_name', customerName);
        fd.append('customer_phone', customerPhone);
        fd.append('customer_email', customerEmail);
        fd.append('field_values', JSON.stringify(fieldValues));
        fd.append('canvas_snapshot', canvasSnapshot);
        fd.append('preview_data', previewData);
        fd.append('quantity', quantity);
        fd.append('notes', notes);

        try {
            const resp = await fetch(basePath + 'api/shop-templates.php', { method: 'POST', body: fd });
            const data = await resp.json();
            if (data.success) {
                // Show success modal
                const modal = document.getElementById('success-modal');
                const waPhone = data.shop_whatsapp || BHD_WA;
                const waMsg = encodeURIComponent(
                    'Hi BHD Printing! I just submitted a business card request on Cardify.\n' +
                    'Name: ' + customerName + '\n' +
                    'Template: ' + templateData.name + '\n' +
                    'Quantity: ' + quantity + '\n' +
                    (notes ? 'Notes: ' + notes : '')
                );
                document.getElementById('wa-link').href = 'https://api.whatsapp.com/send?phone=' + waPhone.replace(/\D/g,'') + '&text=' + waMsg;
                modal.classList.remove('hidden');
            } else {
                alert('Error: ' + (data.error || 'Request failed. Please try again.'));
                this.disabled = false;
                this.innerHTML = '<i class="fa-solid fa-paper-plane mr-1"></i>Submit Card Request';
            }
        } catch(err) {
            alert('Network error. Please try again.');
            this.disabled = false;
            this.innerHTML = '<i class="fa-solid fa-paper-plane mr-1"></i>Submit Card Request';
        }
    });

})();
</script>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
