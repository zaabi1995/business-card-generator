<?php
/**
 * Admin Dashboard - Cardify
 * Template Management with Visual Editor
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';

// Check authentication and role
if (!Auth::isLoggedIn()) {
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

$currentUser = Auth::getCurrentUser();
$userRole = Auth::getCurrentRole();

// Auto-redirect super admin to super admin panel
if ($userRole === 'super_admin') {
    header('Location: ' . getBasePath() . 'admin/super/');
    exit;
}

$companyId = getCurrentCompanyId();

// Get company theme for styling
$companyTheme = null;
if ($companyId && DatabaseAdapter::useDatabase()) {
    $db = Database::getInstance();
    $companyTheme = $db->fetchOne(
        "SELECT * FROM company_themes WHERE company_id = :id",
        ['id' => $companyId]
    );
}

// Apply theme colors
$primaryColor = $companyTheme['primary_color'] ?? '#2563eb';
$secondaryColor = $companyTheme['secondary_color'] ?? '#0f3460';

// Handle logout (legacy support)
if (isset($_GET['logout'])) {
    Auth::logout();
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

// Load templates
$templatesConfig = loadTemplates();
$templates = $templatesConfig['templates'] ?? [];
$activeFrontId = $templatesConfig['activeFrontId'] ?? null;
$activeBackId = $templatesConfig['activeBackId'] ?? null;

// Get employees count
$employees = loadEmployees();
$employeeCount = count($employees);

// Get generated cards count
$generatedLog = loadGeneratedLog();
$generatedCount = count($generatedLog);

// Get departments count
$departments = [];
if ($companyId && DatabaseAdapter::useDatabase()) {
    $departments = $db->fetchAll(
        "SELECT * FROM departments WHERE company_id = :id",
        ['id' => $companyId]
    );
}
$departmentCount = count($departments);

// Separate templates by side
$frontTemplates = array_filter($templates, fn($t) => ($t['side'] ?? 'front') === 'front');
$backTemplates = array_filter($templates, fn($t) => ($t['side'] ?? 'back') === 'back');

// Start admin layout
adminHeader('Dashboard', 'dashboard');
?>

<!-- Custom Styles for Template Editor -->
<style>
    .field-handle {
        cursor: move;
        position: absolute;
        transform: translate(-50%, -50%);
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 10px;
        white-space: nowrap;
        z-index: 10;
        transition: all 0.15s ease;
        background: rgba(255,255,255,0.9);
        border: 1px solid rgba(0,0,0,0.1);
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    .field-handle:hover {
        transform: translate(-50%, -50%) scale(1.1);
        box-shadow: 0 4px 8px rgba(0,0,0,0.15);
    }
    .template-preview {
        position: relative;
        background-size: contain;
        background-repeat: no-repeat;
        background-position: center;
    }
</style>

<!-- Stats Cards -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <div class="bg-white rounded-xl p-6 border border-gray-100 shadow-sm hover:shadow-md transition-shadow">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-blue-100 flex items-center justify-center">
                <i class="fa-solid fa-id-card text-blue-600 text-xl"></i>
            </div>
            <div>
                <p class="text-3xl font-bold text-gray-900"><?php echo count($frontTemplates); ?></p>
                <p class="text-gray-500 text-sm">Front Templates</p>
            </div>
        </div>
    </div>
    
    <div class="bg-white rounded-xl p-6 border border-gray-100 shadow-sm hover:shadow-md transition-shadow">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-purple-100 flex items-center justify-center">
                <i class="fa-solid fa-clone text-purple-600 text-xl"></i>
            </div>
            <div>
                <p class="text-3xl font-bold text-gray-900"><?php echo count($backTemplates); ?></p>
                <p class="text-gray-500 text-sm">Back Templates</p>
            </div>
        </div>
    </div>
    
    <div class="bg-white rounded-xl p-6 border border-gray-100 shadow-sm hover:shadow-md transition-shadow">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-green-100 flex items-center justify-center">
                <i class="fa-solid fa-users text-green-600 text-xl"></i>
            </div>
            <div>
                <p class="text-3xl font-bold text-gray-900"><?php echo $employeeCount; ?></p>
                <p class="text-gray-500 text-sm">Employees</p>
            </div>
        </div>
    </div>
    
    <div class="bg-white rounded-xl p-6 border border-gray-100 shadow-sm hover:shadow-md transition-shadow">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-amber-100 flex items-center justify-center">
                <i class="fa-solid fa-file-lines text-amber-600 text-xl"></i>
            </div>
            <div>
                <p class="text-3xl font-bold text-gray-900"><?php echo $generatedCount; ?></p>
                <p class="text-gray-500 text-sm">Generated Cards</p>
            </div>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="grid md:grid-cols-3 gap-6 mb-8">
    <a href="employees.php?action=add" class="bg-white rounded-xl p-6 border border-gray-100 shadow-sm hover:shadow-md hover:border-blue-200 transition-all group">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-blue-50 group-hover:bg-blue-100 flex items-center justify-center transition-colors">
                <i class="fa-solid fa-user-plus text-blue-600 text-xl"></i>
            </div>
            <div>
                <p class="font-semibold text-gray-900">Add Employee</p>
                <p class="text-gray-500 text-sm">Create a new team member</p>
            </div>
        </div>
    </a>
    
    <a href="batch_generate.php" class="bg-white rounded-xl p-6 border border-gray-100 shadow-sm hover:shadow-md hover:border-green-200 transition-all group">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-green-50 group-hover:bg-green-100 flex items-center justify-center transition-colors">
                <i class="fa-solid fa-wand-magic-sparkles text-green-600 text-xl"></i>
            </div>
            <div>
                <p class="font-semibold text-gray-900">Generate Cards</p>
                <p class="text-gray-500 text-sm">Batch generate business cards</p>
            </div>
        </div>
    </a>
    
    <a href="share.php" class="bg-white rounded-xl p-6 border border-gray-100 shadow-sm hover:shadow-md hover:border-purple-200 transition-all group">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-purple-50 group-hover:bg-purple-100 flex items-center justify-center transition-colors">
                <i class="fa-solid fa-share-nodes text-purple-600 text-xl"></i>
            </div>
            <div>
                <p class="font-semibold text-gray-900">Share Links</p>
                <p class="text-gray-500 text-sm">Manage sharing options</p>
            </div>
        </div>
    </a>
</div>

<!-- Template Editor Section -->
<div x-data="templateEditor()" x-init="init()">
    <!-- Section Header with Tabs -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm mb-6">
        <div class="flex items-center justify-between p-4 border-b border-gray-100">
            <div class="flex items-center gap-2">
                <button 
                    @click="activeTab = 'front'"
                    :class="activeTab === 'front' ? 'bg-blue-50 text-blue-700 border-blue-200' : 'bg-gray-50 text-gray-600 border-gray-200 hover:bg-gray-100'"
                    class="px-4 py-2 rounded-lg border text-sm font-medium transition-all flex items-center gap-2"
                >
                    <i class="fa-solid fa-id-card"></i>
                    <span>Front Templates</span>
                </button>
                <button 
                    @click="activeTab = 'back'"
                    :class="activeTab === 'back' ? 'bg-purple-50 text-purple-700 border-purple-200' : 'bg-gray-50 text-gray-600 border-gray-200 hover:bg-gray-100'"
                    class="px-4 py-2 rounded-lg border text-sm font-medium transition-all flex items-center gap-2"
                >
                    <i class="fa-solid fa-clone"></i>
                    <span>Back Templates</span>
                </button>
            </div>
            
            <button 
                @click="showAddModal = !showAddModal"
                class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors flex items-center gap-2"
            >
                <i class="fa-solid fa-plus"></i>
                <span x-text="showAddModal ? 'Cancel' : 'Add Template'"></span>
            </button>
        </div>
        
        <!-- Add Template Form -->
        <div x-show="showAddModal" x-cloak class="p-6 border-b border-gray-100 bg-gray-50">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Add New Template</h3>
            <form @submit.prevent="addTemplate()">
                <div class="grid md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Template Name</label>
                        <input type="text" x-model="newTemplate.name" required 
                               class="w-full px-4 py-2.5 bg-white border border-gray-200 rounded-lg text-gray-900 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20" 
                               placeholder="e.g., Modern Blue">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Card Side</label>
                        <select x-model="newTemplate.side" 
                                class="w-full px-4 py-2.5 bg-white border border-gray-200 rounded-lg text-gray-900 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                            <option value="front">Front</option>
                            <option value="back">Back</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Background Image</label>
                        <input type="file" accept="image/*" @change="handleNewTemplateImage($event)" 
                               class="w-full px-4 py-2 bg-white border border-gray-200 rounded-lg text-gray-900 file:mr-4 file:py-1 file:px-3 file:rounded file:border-0 file:text-sm file:bg-blue-50 file:text-blue-700">
                    </div>
                </div>
                <p class="text-xs text-gray-500 mt-2">Recommended size: 1050 x 600 px (business card ratio)</p>
                
                <div class="flex items-center justify-end gap-3 mt-4">
                    <button type="button" @click="showAddModal = false" class="px-4 py-2 text-gray-600 hover:text-gray-900 transition-colors">
                        Cancel
                    </button>
                    <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors">
                        Create Template
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Templates Grid -->
    <div class="grid lg:grid-cols-2 gap-6">
        <!-- Template List -->
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm">
            <div class="p-4 border-b border-gray-100">
                <h3 class="font-semibold text-gray-900" x-text="activeTab === 'front' ? 'Front Templates' : 'Back Templates'"></h3>
            </div>
            
            <div class="p-4 space-y-3" x-show="getTemplatesForTab().length > 0">
                <template x-for="template in getTemplatesForTab()" :key="template.id">
                    <div 
                        @click="selectTemplate(template)"
                        :class="selectedTemplate && selectedTemplate.id === template.id ? 'border-blue-300 bg-blue-50' : 'border-gray-200 bg-white hover:bg-gray-50'"
                        class="p-4 rounded-xl border cursor-pointer transition-all"
                    >
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-14 h-9 rounded-lg bg-gray-100 overflow-hidden border border-gray-200">
                                    <img :src="template.backgroundImage ? '<?php echo getBasePath(); ?>' + template.backgroundImage.replace(/^\\//, '') : ''" 
                                         class="w-full h-full object-cover" x-show="template.backgroundImage">
                                </div>
                                <div>
                                    <p class="font-medium text-gray-900" x-text="template.name"></p>
                                    <p class="text-xs text-gray-500" x-text="template.side === 'front' ? 'Front Side' : 'Back Side'"></p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <template x-if="(template.side === 'front' && template.id === activeFrontId) || (template.side === 'back' && template.id === activeBackId)">
                                    <span class="px-2 py-1 text-xs bg-green-100 text-green-700 rounded-full font-medium">Active</span>
                                </template>
                                <button @click.stop="deleteTemplate(template.id)" class="p-2 text-gray-400 hover:text-red-600 transition-colors rounded-lg hover:bg-red-50">
                                    <i class="fa-solid fa-trash-can text-sm"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
            
            <div x-show="getTemplatesForTab().length === 0" class="p-12 text-center text-gray-500">
                <i class="fa-solid fa-image text-4xl mb-4 opacity-30"></i>
                <p>No templates yet</p>
                <p class="text-sm mt-1">Click "Add Template" to create one</p>
            </div>
        </div>
        
        <!-- Template Editor -->
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm" x-show="selectedTemplate" x-cloak>
            <div class="p-4 border-b border-gray-100 flex items-center justify-between">
                <h3 class="font-semibold text-gray-900">Edit Template</h3>
                <div class="flex items-center gap-2">
                    <button 
                        @click="setActiveTemplate()"
                        class="px-3 py-1.5 text-sm bg-green-50 text-green-700 rounded-lg hover:bg-green-100 transition-colors font-medium"
                    >
                        <i class="fa-solid fa-check mr-1"></i>
                        Set Active
                    </button>
                    <button 
                        @click="saveTemplate()"
                        class="px-3 py-1.5 text-sm bg-blue-50 text-blue-700 rounded-lg hover:bg-blue-100 transition-colors font-medium"
                    >
                        <i class="fa-solid fa-floppy-disk mr-1"></i>
                        Save
                    </button>
                </div>
            </div>
            
            <div class="p-4">
                <!-- Visual Preview -->
                <div 
                    x-show="selectedTemplate && selectedTemplate.fields"
                    class="template-preview rounded-xl overflow-hidden mb-4 relative border border-gray-200"
                    :style="getPreviewStyle()"
                    x-ref="templatePreview"
                    @mousedown="startDrag($event)"
                    @mousemove="drag($event)"
                    @mouseup="endDrag()"
                    @mouseleave="endDrag()"
                >
                    <!-- Field Handles -->
                    <template x-for="(field, key) in (selectedTemplate && selectedTemplate.fields ? selectedTemplate.fields : [])" :key="key">
                        <div 
                            x-show="field.enabled && key !== 'qr_code'"
                            :data-field="key"
                            class="field-handle"
                            :style="{
                                left: field.x + '%',
                                top: field.y + '%',
                                fontSize: (field.fontSize * previewScale) + 'px',
                                fontFamily: field.fontFamily,
                                fontWeight: field.fontWeight || 'normal',
                                color: field.color,
                                direction: key.includes('_ar') ? 'rtl' : 'ltr'
                            }"
                            :class="draggedField === key ? 'ring-2 ring-blue-500' : 'hover:ring-2 hover:ring-blue-300'"
                            x-text="getFieldLabel(key)"
                        ></div>
                    </template>
                    
                    <!-- QR Code Handle -->
                    <div 
                        x-show="selectedTemplate && selectedTemplate.fields && selectedTemplate.fields.qr_code && selectedTemplate.fields.qr_code.enabled"
                        data-field="qr_code"
                        class="field-handle bg-gray-100 border border-gray-300"
                        :style="getQRCodeStyle()"
                        :class="draggedField === 'qr_code' ? 'ring-2 ring-blue-500' : 'hover:ring-2 hover:ring-blue-300'"
                    >
                        <i class="fa-solid fa-qrcode text-gray-600"></i>
                    </div>
                </div>
                
                <!-- Field Settings -->
                <div class="space-y-3 max-h-80 overflow-y-auto" x-show="selectedTemplate && selectedTemplate.fields">
                    <template x-for="(field, key) in (selectedTemplate && selectedTemplate.fields ? selectedTemplate.fields : [])" :key="key">
                        <div class="p-3 rounded-lg bg-gray-50 border border-gray-100">
                            <div class="flex items-center justify-between mb-2">
                                <label class="text-sm font-medium text-gray-700 flex items-center gap-2">
                                    <input type="checkbox" x-model="field.enabled" class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                    <span x-text="getFieldLabel(key)"></span>
                                </label>
                                <span class="text-xs text-gray-400" x-text="'(' + Math.round(field.x) + '%, ' + Math.round(field.y) + '%)'"></span>
                            </div>
                            
                            <div x-show="field.enabled" class="grid grid-cols-2 gap-2 mt-2">
                                <template x-if="key !== 'qr_code'">
                                    <div>
                                        <label class="text-xs text-gray-500 block mb-1">Font Size</label>
                                        <input type="number" x-model.number="field.fontSize" min="8" max="72" 
                                               class="w-full px-2 py-1.5 bg-white border border-gray-200 rounded text-sm text-gray-900">
                                    </div>
                                </template>
                                <template x-if="key === 'qr_code'">
                                    <div>
                                        <label class="text-xs text-gray-500 block mb-1">QR Size</label>
                                        <input type="number" x-model.number="field.size" min="40" max="200" 
                                               class="w-full px-2 py-1.5 bg-white border border-gray-200 rounded text-sm text-gray-900">
                                    </div>
                                </template>
                                <template x-if="key !== 'qr_code'">
                                    <div>
                                        <label class="text-xs text-gray-500 block mb-1">Color</label>
                                        <input type="color" x-model="field.color" class="w-full h-8 rounded cursor-pointer border border-gray-200">
                                    </div>
                                </template>
                                <template x-if="key !== 'qr_code'">
                                    <div class="col-span-2">
                                        <label class="text-xs text-gray-500 block mb-1">Font</label>
                                        <select x-model="field.fontFamily" class="w-full px-2 py-1.5 bg-white border border-gray-200 rounded text-sm text-gray-900">
                                            <optgroup label="English Fonts">
                                                <option value="'Inter', sans-serif">Inter</option>
                                                <option value="'Plus Jakarta Sans', sans-serif">Plus Jakarta Sans</option>
                                                <option value="'Montserrat', sans-serif">Montserrat</option>
                                                <option value="'Roboto', sans-serif">Roboto</option>
                                            </optgroup>
                                            <optgroup label="Arabic Fonts">
                                                <option value="'Cairo', sans-serif">Cairo</option>
                                                <option value="'Tajawal', sans-serif">Tajawal</option>
                                                <option value="'Almarai', sans-serif">Almarai</option>
                                            </optgroup>
                                        </select>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>
        
        <!-- Empty State -->
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm flex items-center justify-center p-12" x-show="!selectedTemplate">
            <div class="text-center text-gray-400">
                <i class="fa-solid fa-mouse-pointer text-4xl mb-4 opacity-50"></i>
                <p class="text-gray-600">Select a template to edit</p>
                <p class="text-sm mt-1">Or create a new one</p>
            </div>
        </div>
    </div>
    
    <!-- Status Message Toast -->
    <div x-show="statusMessage" x-transition 
         class="fixed bottom-6 right-6 px-4 py-3 rounded-xl shadow-lg flex items-center gap-3 z-50" 
         :class="statusType === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'">
        <i :class="statusType === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-exclamation'"></i>
        <span x-text="statusMessage"></span>
    </div>
</div>

<script>
    function templateEditor() {
        return {
            activeTab: 'front',
            templates: <?php echo json_encode($templates); ?>,
            activeFrontId: <?php echo json_encode($activeFrontId); ?>,
            activeBackId: <?php echo json_encode($activeBackId); ?>,
            selectedTemplate: null,
            showAddModal: false,
            newTemplate: { name: '', side: 'front', imageFile: null },
            draggedField: null,
            dragStartX: 0,
            dragStartY: 0,
            previewScale: 0.5,
            previewAspectRatio: '1.75',
            statusMessage: '',
            statusType: 'success',
            
            init() {
                const tabTemplates = this.getTemplatesForTab();
                if (tabTemplates.length > 0) {
                    this.selectTemplate(tabTemplates[0]);
                }
            },
            
            getTemplatesForTab() {
                return this.templates.filter(t => (t.side || 'front') === this.activeTab);
            },
            
            selectTemplate(template) {
                const defaultFields = <?php echo json_encode(getDefaultFieldSettings()); ?>;
                if (!template.fields) {
                    template.fields = JSON.parse(JSON.stringify(defaultFields));
                } else {
                    for (const key in defaultFields) {
                        if (!template.fields[key]) {
                            template.fields[key] = JSON.parse(JSON.stringify(defaultFields[key]));
                        }
                    }
                }
                this.selectedTemplate = template;
                this.$nextTick(() => this.updatePreviewScale());
            },
            
            updatePreviewScale() {
                const preview = this.$refs.templatePreview;
                if (preview) {
                    this.previewScale = preview.offsetWidth / 1050;
                }
            },
            
            getPreviewStyle() {
                if (!this.selectedTemplate || !this.selectedTemplate.fields) return {};
                const bgImage = this.selectedTemplate.backgroundImage 
                    ? 'url(<?php echo getBasePath(); ?>' + this.selectedTemplate.backgroundImage.replace(/^\//, '') + ')'
                    : 'none';
                return {
                    backgroundImage: bgImage,
                    backgroundColor: '#f3f4f6',
                    aspectRatio: this.previewAspectRatio
                };
            },
            
            getQRCodeStyle() {
                if (!this.selectedTemplate?.fields?.qr_code) return {};
                const qr = this.selectedTemplate.fields.qr_code;
                return {
                    left: (qr.x || 0) + '%',
                    top: (qr.y || 0) + '%',
                    width: ((qr.size || 100) * this.previewScale) + 'px',
                    height: ((qr.size || 100) * this.previewScale) + 'px'
                };
            },
            
            getFieldLabel(key) {
                const labels = {
                    'name_en': 'Name (EN)', 'name_ar': 'Name (AR)',
                    'position_en': 'Position (EN)', 'position_ar': 'Position (AR)',
                    'phone': 'Phone', 'mobile': 'Mobile', 'email': 'Email',
                    'company_en': 'Company (EN)', 'company_ar': 'Company (AR)',
                    'website': 'Website', 'address': 'Address', 'qr_code': 'QR Code'
                };
                return labels[key] || key;
            },
            
            startDrag(event) {
                const target = event.target.closest('[data-field]');
                if (target) {
                    this.draggedField = target.dataset.field;
                    this.dragStartX = event.clientX;
                    this.dragStartY = event.clientY;
                }
            },
            
            drag(event) {
                if (!this.draggedField || !this.selectedTemplate) return;
                const preview = this.$refs.templatePreview;
                const rect = preview.getBoundingClientRect();
                const x = Math.max(0, Math.min(100, ((event.clientX - rect.left) / rect.width) * 100));
                const y = Math.max(0, Math.min(100, ((event.clientY - rect.top) / rect.height) * 100));
                this.selectedTemplate.fields[this.draggedField].x = x;
                this.selectedTemplate.fields[this.draggedField].y = y;
            },
            
            endDrag() { this.draggedField = null; },
            
            handleNewTemplateImage(event) {
                const file = event.target.files[0];
                if (file) this.newTemplate.imageFile = file;
            },
            
            async addTemplate() {
                if (!this.newTemplate.name || !this.newTemplate.imageFile) {
                    this.showStatus('Please fill all required fields', 'error');
                    return;
                }
                const formData = new FormData();
                formData.append('action', 'add');
                formData.append('name', this.newTemplate.name);
                formData.append('side', this.newTemplate.side);
                formData.append('image', this.newTemplate.imageFile);
                formData.append('fields', JSON.stringify(<?php echo json_encode(getDefaultFieldSettings()); ?>));
                
                try {
                    const response = await fetch('save_template.php', { method: 'POST', body: formData });
                    const result = await response.json();
                    if (result.success) {
                        this.templates.push(result.template);
                        this.showAddModal = false;
                        this.newTemplate = { name: '', side: 'front', imageFile: null };
                        this.showStatus('Template created successfully', 'success');
                        this.selectTemplate(result.template);
                    } else {
                        this.showStatus(result.error || 'Failed to create template', 'error');
                    }
                } catch (error) {
                    this.showStatus('Error creating template', 'error');
                }
            },
            
            async saveTemplate() {
                if (!this.selectedTemplate) return;
                const formData = new FormData();
                formData.append('action', 'update');
                formData.append('id', this.selectedTemplate.id);
                formData.append('name', this.selectedTemplate.name);
                formData.append('fields', JSON.stringify(this.selectedTemplate.fields));
                
                try {
                    const response = await fetch('save_template.php', { method: 'POST', body: formData });
                    const result = await response.json();
                    this.showStatus(result.success ? 'Template saved successfully' : (result.error || 'Failed to save'), result.success ? 'success' : 'error');
                } catch (error) {
                    this.showStatus('Error saving template', 'error');
                }
            },
            
            async setActiveTemplate() {
                if (!this.selectedTemplate) return;
                const formData = new FormData();
                formData.append('action', 'activate');
                formData.append('id', this.selectedTemplate.id);
                formData.append('side', this.selectedTemplate.side);
                
                try {
                    const response = await fetch('save_template.php', { method: 'POST', body: formData });
                    const result = await response.json();
                    if (result.success) {
                        if (this.selectedTemplate.side === 'front') this.activeFrontId = this.selectedTemplate.id;
                        else this.activeBackId = this.selectedTemplate.id;
                        this.showStatus('Template set as active', 'success');
                    } else {
                        this.showStatus(result.error || 'Failed to activate', 'error');
                    }
                } catch (error) {
                    this.showStatus('Error activating template', 'error');
                }
            },
            
            async deleteTemplate(id) {
                if (!confirm('Are you sure you want to delete this template?')) return;
                const formData = new FormData();
                formData.append('action', 'delete');
                formData.append('id', id);
                
                try {
                    const response = await fetch('save_template.php', { method: 'POST', body: formData });
                    const result = await response.json();
                    if (result.success) {
                        this.templates = this.templates.filter(t => t.id !== id);
                        if (this.selectedTemplate?.id === id) this.selectedTemplate = null;
                        this.showStatus('Template deleted', 'success');
                    } else {
                        this.showStatus(result.error || 'Failed to delete', 'error');
                    }
                } catch (error) {
                    this.showStatus('Error deleting template', 'error');
                }
            },
            
            showStatus(message, type) {
                this.statusMessage = message;
                this.statusType = type;
                setTimeout(() => this.statusMessage = '', 3000);
            }
        };
    }
</script>

<?php adminFooter(); ?>
