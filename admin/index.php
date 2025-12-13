<?php
/**
 * Admin Dashboard - BHD Business Cards
 * Template Management with Visual Editor
 */
require_once __DIR__ . '/../config.php';
requireAdmin();

// Handle logout
if (isset($_GET['logout'])) {
    logoutAdmin();
    header('Location: login.php');
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

// Separate templates by side
$frontTemplates = array_filter($templates, fn($t) => ($t['side'] ?? 'front') === 'front');
$backTemplates = array_filter($templates, fn($t) => ($t['side'] ?? 'back') === 'back');
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | <?php echo SITE_NAME; ?></title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Cairo:wght@400;500;600;700&family=Tajawal:wght@400;500;700&family=Almarai:wght@400;700&family=Amiri:wght@400;700&display=swap" rel="stylesheet">
    
    <!-- Tailwind CSS -->
    <link rel="stylesheet" href="<?php echo assetUrl('css/tailwind.css'); ?>?v=<?php echo filemtime(ASSETS_DIR . '/css/tailwind.css'); ?>">
    
    <!-- Alpine.js -->
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    
    <style>
        [x-cloak] { display: none !important; }
        
        .glass-card {
            background: rgba(255, 255, 255, 0.03);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .input-bhd {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.15);
            transition: all 0.3s ease;
        }
        
        .input-bhd:focus {
            background: rgba(255, 255, 255, 0.08);
            border-color: rgba(212, 175, 55, 0.6);
            box-shadow: 0 0 0 3px rgba(212, 175, 55, 0.1);
        }
        
        .btn-bhd {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            transition: all 0.3s ease;
            border: 1px solid rgba(212, 175, 55, 0.3);
        }
        
        .btn-bhd:hover {
            box-shadow: 0 0 20px rgba(212, 175, 55, 0.2);
            border-color: rgba(212, 175, 55, 0.5);
        }
        
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
        }
        
        .field-handle:hover {
            transform: translate(-50%, -50%) scale(1.1);
        }
        
        .template-preview {
            position: relative;
            background-size: contain;
            background-repeat: no-repeat;
            background-position: center;
        }
        
        .modal-scrollable {
            overflow-y: auto !important;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
            scrollbar-color: rgba(255, 255, 255, 0.3) transparent;
        }
        
        .modal-scrollable::-webkit-scrollbar {
            width: 8px;
        }
        
        .modal-scrollable::-webkit-scrollbar-track {
            background: transparent;
        }
        
        .modal-scrollable::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.3);
            border-radius: 4px;
        }
        
        .modal-scrollable::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.5);
        }
        
        /* Ensure modal content can scroll */
        [x-show*="showAddModal"] .glass-card[style*="overflow-y"] {
            overflow-y: auto !important;
            -webkit-overflow-scrolling: touch !important;
        }
    </style>
</head>
<body class="bg-alzayani-dark text-white font-sans min-h-screen antialiased">
    <div class="min-h-screen">
        <!-- Header -->
        <header class="glass-card border-b border-white/10">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-4">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-amber-500/30 to-amber-600/10 flex items-center justify-center border border-amber-500/40">
                            <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0"></path>
                            </svg>
                        </div>
                        <div>
                            <h1 class="text-xl font-bold text-white"><?php echo SITE_NAME; ?></h1>
                            <p class="text-gray-500 text-xs">Admin Dashboard</p>
                        </div>
                    </div>
                    
                    <nav class="flex items-center space-x-4">
                        <a href="employees.php" class="text-gray-400 hover:text-amber-400 transition-colors text-sm flex items-center space-x-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                            </svg>
                            <span>Employees</span>
                        </a>
                        <a href="generated.php" class="text-gray-400 hover:text-amber-400 transition-colors text-sm flex items-center space-x-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                            <span>Generated</span>
                        </a>
                        <a href="?logout=1" class="text-gray-500 hover:text-red-400 transition-colors text-sm">
                            Logout
                        </a>
                    </nav>
                </div>
            </div>
        </header>
        
        <!-- Main Content -->
        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <!-- Stats Cards -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
                <div class="glass-card rounded-xl p-4">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 rounded-lg bg-blue-500/20 flex items-center justify-center">
                            <svg class="w-5 h-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                            </svg>
                        </div>
                        <div>
                            <p class="text-2xl font-bold text-white"><?php echo count($frontTemplates); ?></p>
                            <p class="text-gray-500 text-xs">Front Templates</p>
                        </div>
                    </div>
                </div>
                
                <div class="glass-card rounded-xl p-4">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 rounded-lg bg-purple-500/20 flex items-center justify-center">
                            <svg class="w-5 h-5 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                            </svg>
                        </div>
                        <div>
                            <p class="text-2xl font-bold text-white"><?php echo count($backTemplates); ?></p>
                            <p class="text-gray-500 text-xs">Back Templates</p>
                        </div>
                    </div>
                </div>
                
                <div class="glass-card rounded-xl p-4">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 rounded-lg bg-green-500/20 flex items-center justify-center">
                            <svg class="w-5 h-5 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            </svg>
                        </div>
                        <div>
                            <p class="text-2xl font-bold text-white"><?php echo $employeeCount; ?></p>
                            <p class="text-gray-500 text-xs">Employees</p>
                        </div>
                    </div>
                </div>
                
                <div class="glass-card rounded-xl p-4">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 rounded-lg bg-amber-500/20 flex items-center justify-center">
                            <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                        </div>
                        <div>
                            <p class="text-2xl font-bold text-white"><?php echo $generatedCount; ?></p>
                            <p class="text-gray-500 text-xs">Generated</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Template Editor Section -->
            <div x-data="templateEditor()" x-init="init()">
                <!-- Tabs -->
                <div class="flex items-center space-x-4 mb-6">
                    <button 
                        @click="activeTab = 'front'"
                        :class="activeTab === 'front' ? 'bg-blue-500/20 text-blue-400 border-blue-500/50' : 'bg-white/5 text-gray-400 border-white/10 hover:bg-white/10'"
                        class="px-6 py-3 rounded-xl border transition-all flex items-center space-x-2"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                        <span>Front Templates</span>
                    </button>
                    <button 
                        @click="activeTab = 'back'"
                        :class="activeTab === 'back' ? 'bg-purple-500/20 text-purple-400 border-purple-500/50' : 'bg-white/5 text-gray-400 border-white/10 hover:bg-white/10'"
                        class="px-6 py-3 rounded-xl border transition-all flex items-center space-x-2"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                        <span>Back Templates</span>
                    </button>
                    
                    <div class="flex-1"></div>
                    
                    <button 
                        @click="showAddModal = !showAddModal"
                        class="btn-bhd px-4 py-2 rounded-lg text-sm flex items-center space-x-2"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                        </svg>
                        <span x-text="showAddModal ? 'Cancel' : 'Add Template'"></span>
                    </button>
                </div>
                
                <!-- Add Template Form (Inline) -->
                <div x-show="showAddModal" x-cloak class="mb-6">
                    <div class="glass-card rounded-xl p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-semibold text-white">Add New Template</h3>
                            <button @click="showAddModal = false" class="text-gray-400 hover:text-white transition-colors">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            </button>
                        </div>
                        
                        <form @submit.prevent="addTemplate()">
                            <div class="grid md:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-2">Template Name</label>
                                    <input type="text" x-model="newTemplate.name" required class="input-bhd w-full px-4 py-3 rounded-xl text-white" placeholder="e.g., Modern Blue">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-2">Card Side</label>
                                    <select x-model="newTemplate.side" class="input-bhd w-full px-4 py-3 rounded-xl text-white">
                                        <option value="front">Front</option>
                                        <option value="back">Back</option>
                                    </select>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-2">Background Image</label>
                                    <input type="file" accept="image/*" @change="handleNewTemplateImage($event)" class="input-bhd w-full px-4 py-3 rounded-xl text-white file:mr-4 file:py-1 file:px-3 file:rounded file:border-0 file:text-sm file:bg-white/10 file:text-gray-300">
                                </div>
                            </div>
                            <p class="text-xs text-gray-500 mt-2">Recommended: 1050 x 600 px (business card ratio)</p>
                            
                            <div class="flex items-center justify-end space-x-3 mt-4">
                                <button type="button" @click="showAddModal = false" class="px-4 py-2 text-gray-400 hover:text-white transition-colors">
                                    Cancel
                                </button>
                                <button type="submit" class="btn-bhd px-6 py-2 rounded-xl">
                                    Create Template
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Templates Grid -->
                <div class="grid lg:grid-cols-2 gap-6">
                    <!-- Template List -->
                    <div class="glass-card rounded-xl p-6">
                        <h3 class="text-lg font-semibold text-white mb-4" x-text="activeTab === 'front' ? 'Front Templates' : 'Back Templates'"></h3>
                        
                        <div class="space-y-3" x-show="getTemplatesForTab().length > 0">
                            <template x-for="template in getTemplatesForTab()" :key="template.id">
                                <div 
                                    @click="selectTemplate(template)"
                                    :class="selectedTemplate && selectedTemplate.id === template.id ? 'border-amber-500/50 bg-amber-500/10' : 'border-white/10 bg-white/5 hover:bg-white/10'"
                                    class="p-4 rounded-xl border cursor-pointer transition-all"
                                >
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center space-x-3">
                                            <div class="w-12 h-8 rounded bg-gray-700 overflow-hidden">
                                                <img :src="template.backgroundImage ? '<?php echo getBasePath(); ?>' + template.backgroundImage.replace(/^\\//, '') : ''" class="w-full h-full object-cover" x-show="template.backgroundImage">
                                            </div>
                                            <div>
                                                <p class="font-medium text-white" x-text="template.name"></p>
                                                <p class="text-xs text-gray-500" x-text="template.side === 'front' ? 'Front Side' : 'Back Side'"></p>
                                            </div>
                                        </div>
                                        <div class="flex items-center space-x-2">
                                            <template x-if="(template.side === 'front' && template.id === activeFrontId) || (template.side === 'back' && template.id === activeBackId)">
                                                <span class="px-2 py-1 text-xs bg-green-500/20 text-green-400 rounded">Active</span>
                                            </template>
                                            <button @click.stop="deleteTemplate(template.id)" class="p-1 text-gray-500 hover:text-red-400 transition-colors">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                        
                        <div x-show="getTemplatesForTab().length === 0" class="text-center py-12 text-gray-500">
                            <svg class="w-12 h-12 mx-auto mb-4 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                            </svg>
                            <p>No templates yet</p>
                            <p class="text-sm mt-1">Click "Add Template" to create one</p>
                        </div>
                    </div>
                    
                    <!-- Template Editor -->
                    <div class="glass-card rounded-xl p-6" x-show="selectedTemplate" x-cloak>
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-semibold text-white">Edit Template</h3>
                            <div class="flex items-center space-x-2">
                                <button 
                                    @click="setActiveTemplate()"
                                    class="px-3 py-1.5 text-sm bg-green-500/20 text-green-400 rounded-lg hover:bg-green-500/30 transition-colors"
                                >
                                    Set Active
                                </button>
                                <button 
                                    @click="saveTemplate()"
                                    class="px-3 py-1.5 text-sm bg-amber-500/20 text-amber-400 rounded-lg hover:bg-amber-500/30 transition-colors"
                                >
                                    Save Changes
                                </button>
                            </div>
                        </div>
                        
                        <!-- Visual Preview -->
                        <div 
                            x-show="selectedTemplate && selectedTemplate.fields"
                            class="template-preview rounded-xl overflow-hidden mb-6 relative"
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
                                    :class="draggedField === key ? 'ring-2 ring-amber-400' : 'hover:ring-2 hover:ring-white/50'"
                                    x-text="getFieldLabel(key)"
                                ></div>
                            </template>
                            
                            <!-- QR Code Handle -->
                            <div 
                                x-show="selectedTemplate && selectedTemplate.fields && selectedTemplate.fields.qr_code && selectedTemplate.fields.qr_code.enabled"
                                data-field="qr_code"
                                class="field-handle bg-white/20 border border-white/40"
                                :style="getQRCodeStyle()"
                                :class="draggedField === 'qr_code' ? 'ring-2 ring-amber-400' : 'hover:ring-2 hover:ring-white/50'"
                            >
                                <svg class="w-full h-full p-1 text-white/60" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M3 3h6v6H3V3zm2 2v2h2V5H5zm8-2h6v6h-6V3zm2 2v2h2V5h-2zM3 13h6v6H3v-6zm2 2v2h2v-2H5zm13-2h1v1h-1v-1zm-3 0h1v1h-1v-1zm-1 1h1v1h-1v-1zm2 0h1v1h-1v-1zm1 1h1v1h-1v-1zm-1 1h1v1h-1v-1zm1 1h1v1h-1v-1zm-1 1h1v1h-1v-1zm3-4h1v1h-1v-1zm0 2h1v1h-1v-1zm0 2h1v1h-1v-1zm-1 1h1v1h-1v-1z"/>
                                </svg>
                            </div>
                        </div>
                        
                        <!-- Field Settings -->
                        <div class="space-y-4 max-h-96 overflow-y-auto pr-2" x-show="selectedTemplate && selectedTemplate.fields">
                            <template x-for="(field, key) in (selectedTemplate && selectedTemplate.fields ? selectedTemplate.fields : [])" :key="key">
                                <div class="p-3 rounded-lg bg-white/5 border border-white/10">
                                    <div class="flex items-center justify-between mb-2">
                                        <label class="text-sm font-medium text-gray-300 flex items-center space-x-2">
                                            <input type="checkbox" x-model="field.enabled" class="rounded bg-white/10 border-white/30 text-amber-500 focus:ring-amber-500/50">
                                            <span x-text="getFieldLabel(key)"></span>
                                        </label>
                                        <span class="text-xs text-gray-500" x-text="'(' + Math.round(field.x) + '%, ' + Math.round(field.y) + '%)'"></span>
                                    </div>
                                    
                                    <div x-show="field.enabled" class="grid grid-cols-2 gap-2 mt-2">
                                        <template x-if="key !== 'qr_code'">
                                            <div>
                                                <label class="text-xs text-gray-500 block mb-1">Font Size</label>
                                                <input type="number" x-model.number="field.fontSize" min="8" max="72" class="input-bhd w-full px-2 py-1 rounded text-sm text-white">
                                            </div>
                                        </template>
                                        <template x-if="key === 'qr_code'">
                                            <div>
                                                <label class="text-xs text-gray-500 block mb-1">QR Size</label>
                                                <input type="number" x-model.number="field.size" min="40" max="200" class="input-bhd w-full px-2 py-1 rounded text-sm text-white">
                                            </div>
                                        </template>
                                        <template x-if="key !== 'qr_code'">
                                            <div>
                                                <label class="text-xs text-gray-500 block mb-1">Color</label>
                                                <input type="color" x-model="field.color" class="w-full h-8 rounded cursor-pointer bg-transparent">
                                            </div>
                                        </template>
                                        <template x-if="key !== 'qr_code'">
                                            <div class="col-span-2">
                                                <label class="text-xs text-gray-500 block mb-1">Font</label>
                                                <select x-model="field.fontFamily" class="input-bhd w-full px-2 py-1 rounded text-sm text-white">
                                                    <optgroup label="English Fonts">
                                                        <option value="'Plus Jakarta Sans', sans-serif">Plus Jakarta Sans</option>
                                                        <option value="'Inter', sans-serif">Inter</option>
                                                        <option value="'Montserrat', sans-serif">Montserrat</option>
                                                        <option value="'Roboto', sans-serif">Roboto</option>
                                                        <option value="'Poppins', sans-serif">Poppins</option>
                                                    </optgroup>
                                                    <optgroup label="Arabic Fonts">
                                                        <option value="'Cairo', sans-serif">Cairo</option>
                                                        <option value="'Tajawal', sans-serif">Tajawal</option>
                                                        <option value="'Almarai', sans-serif">Almarai</option>
                                                        <option value="'Amiri', serif">Amiri</option>
                                                    </optgroup>
                                                </select>
                                            </div>
                                        </template>
                                        <template x-if="key !== 'qr_code'">
                                            <div class="col-span-2">
                                                <label class="text-xs text-gray-500 block mb-1">Weight</label>
                                                <select x-model="field.fontWeight" class="input-bhd w-full px-2 py-1 rounded text-sm text-white">
                                                    <option value="normal">Normal</option>
                                                    <option value="bold">Bold</option>
                                                </select>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                    
                    <!-- Empty State -->
                    <div class="glass-card rounded-xl p-6 flex items-center justify-center" x-show="!selectedTemplate">
                        <div class="text-center text-gray-500">
                            <svg class="w-16 h-16 mx-auto mb-4 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5M7.188 2.239l.777 2.897M5.136 7.965l-2.898-.777M13.95 4.05l-2.122 2.122m-5.657 5.656l-2.12 2.122"></path>
                            </svg>
                            <p>Select a template to edit</p>
                            <p class="text-sm mt-1">Or create a new one</p>
                        </div>
                    </div>
                </div>
                
                <!-- Status Message -->
                <div x-show="statusMessage" x-transition class="fixed bottom-4 right-4 px-4 py-3 rounded-xl" :class="statusType === 'success' ? 'bg-green-500/20 text-green-400 border border-green-500/30' : 'bg-red-500/20 text-red-400 border border-red-500/30'">
                    <span x-text="statusMessage"></span>
                </div>
            </div>
        </main>
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
                    // Auto-select first template
                    const tabTemplates = this.getTemplatesForTab();
                    if (tabTemplates.length > 0) {
                        this.selectTemplate(tabTemplates[0]);
                    }
                },
                
                getTemplatesForTab() {
                    return this.templates.filter(t => (t.side || 'front') === this.activeTab);
                },
                
                selectTemplate(template) {
                    // Ensure template has all fields
                    const defaultFields = <?php echo json_encode(getDefaultFieldSettings()); ?>;
                    if (!template.fields) {
                        template.fields = JSON.parse(JSON.stringify(defaultFields));
                    } else {
                        // Merge with defaults
                        for (const key in defaultFields) {
                            if (!template.fields[key]) {
                                template.fields[key] = JSON.parse(JSON.stringify(defaultFields[key]));
                            }
                        }
                    }
                    this.selectedTemplate = template;
                    
                    // Calculate preview scale based on container
                    this.$nextTick(() => {
                        this.updatePreviewScale();
                    });
                },
                
                updatePreviewScale() {
                    const preview = this.$refs.templatePreview;
                    if (preview) {
                        const width = preview.offsetWidth;
                        this.previewScale = width / 1050; // Base width of 1050px
                    }
                },
                
                getPreviewStyle() {
                    if (!this.selectedTemplate || !this.selectedTemplate.fields) {
                        return {};
                    }
                    const bgImage = this.selectedTemplate.backgroundImage 
                        ? 'url(' + '<?php echo getBasePath(); ?>' + this.selectedTemplate.backgroundImage.replace(/^\//, '') + ')'
                        : 'none';
                    return {
                        backgroundImage: bgImage,
                        backgroundColor: '#1a1a2e',
                        aspectRatio: this.previewAspectRatio
                    };
                },
                
                getQRCodeStyle() {
                    if (!this.selectedTemplate || !this.selectedTemplate.fields || !this.selectedTemplate.fields.qr_code) {
                        return {};
                    }
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
                        'name_en': 'Name (EN)',
                        'name_ar': 'Name (AR)',
                        'position_en': 'Position (EN)',
                        'position_ar': 'Position (AR)',
                        'phone': 'Phone',
                        'mobile': 'Mobile',
                        'email': 'Email',
                        'company_en': 'Company (EN)',
                        'company_ar': 'Company (AR)',
                        'website': 'Website',
                        'address': 'Address',
                        'qr_code': 'QR Code'
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
                    
                    const x = ((event.clientX - rect.left) / rect.width) * 100;
                    const y = ((event.clientY - rect.top) / rect.height) * 100;
                    
                    // Clamp values
                    const clampedX = Math.max(0, Math.min(100, x));
                    const clampedY = Math.max(0, Math.min(100, y));
                    
                    this.selectedTemplate.fields[this.draggedField].x = clampedX;
                    this.selectedTemplate.fields[this.draggedField].y = clampedY;
                },
                
                endDrag() {
                    this.draggedField = null;
                },
                
                handleNewTemplateImage(event) {
                    const file = event.target.files[0];
                    if (file) {
                        this.newTemplate.imageFile = file;
                    }
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
                        const response = await fetch('save_template.php', {
                            method: 'POST',
                            body: formData
                        });
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
                        console.error('Error creating template:', error);
                        this.showStatus('Error creating template: ' + (error.message || error), 'error');
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
                        const response = await fetch('save_template.php', {
                            method: 'POST',
                            body: formData
                        });
                        const result = await response.json();
                        
                        if (result.success) {
                            this.showStatus('Template saved successfully', 'success');
                        } else {
                            this.showStatus(result.error || 'Failed to save template', 'error');
                        }
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
                        const response = await fetch('save_template.php', {
                            method: 'POST',
                            body: formData
                        });
                        const result = await response.json();
                        
                        if (result.success) {
                            if (this.selectedTemplate.side === 'front') {
                                this.activeFrontId = this.selectedTemplate.id;
                            } else {
                                this.activeBackId = this.selectedTemplate.id;
                            }
                            this.showStatus('Template set as active', 'success');
                        } else {
                            this.showStatus(result.error || 'Failed to activate template', 'error');
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
                        const response = await fetch('save_template.php', {
                            method: 'POST',
                            body: formData
                        });
                        const result = await response.json();
                        
                        if (result.success) {
                            this.templates = this.templates.filter(t => t.id !== id);
                            if (this.selectedTemplate && this.selectedTemplate.id === id) {
                                this.selectedTemplate = null;
                            }
                            this.showStatus('Template deleted', 'success');
                        } else {
                            this.showStatus(result.error || 'Failed to delete template', 'error');
                        }
                    } catch (error) {
                        this.showStatus('Error deleting template', 'error');
                    }
                },
                
                showStatus(message, type) {
                    this.statusMessage = message;
                    this.statusType = type;
                    setTimeout(() => {
                        this.statusMessage = '';
                    }, 3000);
                }
            };
        }
    </script>
</body>
</html>

