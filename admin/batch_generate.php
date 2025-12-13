<?php
/**
 * Batch Generate Business Cards - BHD Business Cards
 */
require_once __DIR__ . '/../config.php';
requireAdmin();

$employees = loadEmployees();
$templatesConfig = loadTemplates();
$frontTemplate = getActiveFrontTemplate();
$backTemplate = getActiveBackTemplate();

$hasTemplates = $frontTemplate || $backTemplate;
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Batch Generate | <?php echo SITE_NAME; ?></title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="<?php echo assetUrl('css/tailwind.css'); ?>?v=<?php echo filemtime(ASSETS_DIR . '/css/tailwind.css'); ?>">
    
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script src="https://html2canvas.hertzen.com/dist/html2canvas.min.js"></script>
    
    <style>
        [x-cloak] { display: none !important; }
        .glass-card { background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.1); }
        .btn-bhd { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%); transition: all 0.3s ease; border: 1px solid rgba(212, 175, 55, 0.3); }
        .btn-bhd:hover { box-shadow: 0 0 20px rgba(212, 175, 55, 0.2); border-color: rgba(212, 175, 55, 0.5); }
        .card-container { position: relative; overflow: hidden; }
        .card-field { position: absolute; transform: translate(-50%, -50%); white-space: nowrap; }
    </style>
</head>
<body class="bg-alzayani-dark text-white font-sans min-h-screen antialiased">
    <div class="min-h-screen" x-data="batchGenerator()">
        <!-- Header -->
        <header class="glass-card border-b border-white/10">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-4">
                        <a href="generated.php" class="text-gray-400 hover:text-white transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                            </svg>
                        </a>
                        <div>
                            <h1 class="text-xl font-bold text-white">Batch Generate</h1>
                            <p class="text-gray-500 text-xs">Generate cards for multiple employees</p>
                        </div>
                    </div>
                </div>
            </div>
        </header>
        
        <!-- Main Content -->
        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <?php if (!$hasTemplates): ?>
            <div class="glass-card rounded-xl p-8 text-center">
                <svg class="w-16 h-16 mx-auto mb-4 text-amber-500/50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                </svg>
                <h2 class="text-xl font-bold text-white mb-2">No Active Templates</h2>
                <p class="text-gray-400 mb-4">Please set up and activate at least one template (front or back) before batch generating.</p>
                <a href="index.php" class="btn-bhd inline-block px-6 py-3 rounded-xl">
                    Go to Template Settings
                </a>
            </div>
            <?php elseif (empty($employees)): ?>
            <div class="glass-card rounded-xl p-8 text-center">
                <svg class="w-16 h-16 mx-auto mb-4 text-amber-500/50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"></path>
                </svg>
                <h2 class="text-xl font-bold text-white mb-2">No Employees</h2>
                <p class="text-gray-400 mb-4">Add employees first before batch generating cards.</p>
                <a href="employees.php" class="btn-bhd inline-block px-6 py-3 rounded-xl">
                    Manage Employees
                </a>
            </div>
            <?php else: ?>
            
            <!-- Template Status -->
            <div class="grid grid-cols-2 gap-4 mb-8">
                <div class="glass-card rounded-xl p-4">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 rounded-lg <?php echo $frontTemplate ? 'bg-green-500/20' : 'bg-gray-500/20'; ?> flex items-center justify-center">
                            <svg class="w-5 h-5 <?php echo $frontTemplate ? 'text-green-400' : 'text-gray-500'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                        </div>
                        <div>
                            <p class="text-white font-medium">Front Template</p>
                            <p class="text-gray-500 text-sm"><?php echo $frontTemplate ? sanitize($frontTemplate['name']) : 'Not configured'; ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="glass-card rounded-xl p-4">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 rounded-lg <?php echo $backTemplate ? 'bg-green-500/20' : 'bg-gray-500/20'; ?> flex items-center justify-center">
                            <svg class="w-5 h-5 <?php echo $backTemplate ? 'text-green-400' : 'text-gray-500'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                        </div>
                        <div>
                            <p class="text-white font-medium">Back Template</p>
                            <p class="text-gray-500 text-sm"><?php echo $backTemplate ? sanitize($backTemplate['name']) : 'Not configured'; ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Progress -->
            <div x-show="isGenerating" class="glass-card rounded-xl p-6 mb-8">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-white">Generating Cards...</h3>
                    <span class="text-amber-400" x-text="currentIndex + ' / ' + selectedEmployees.length"></span>
                </div>
                <div class="w-full h-3 bg-white/10 rounded-full overflow-hidden">
                    <div class="h-full bg-amber-500 transition-all duration-300" :style="'width: ' + progress + '%'"></div>
                </div>
                <p class="text-gray-500 text-sm mt-2" x-text="currentEmployee"></p>
            </div>
            
            <!-- Completed -->
            <div x-show="isComplete" class="glass-card rounded-xl p-6 mb-8 bg-green-500/10 border-green-500/30">
                <div class="flex items-center space-x-4">
                    <div class="w-12 h-12 rounded-full bg-green-500/20 flex items-center justify-center">
                        <svg class="w-6 h-6 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-white">Generation Complete</h3>
                        <p class="text-gray-400">Successfully generated <span x-text="completedCount"></span> business cards</p>
                    </div>
                </div>
                <div class="mt-4">
                    <a href="generated.php" class="btn-bhd inline-block px-6 py-2 rounded-xl text-sm">
                        View Generated Cards
                    </a>
                </div>
            </div>
            
            <!-- Employee Selection -->
            <div class="glass-card rounded-xl p-6" x-show="!isGenerating && !isComplete">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-white">Select Employees</h3>
                    <div class="flex items-center space-x-4">
                        <label class="flex items-center space-x-2 text-sm text-gray-400 cursor-pointer">
                            <input type="checkbox" @change="toggleAll($event.target.checked)" class="rounded bg-white/10 border-white/30 text-amber-500 focus:ring-amber-500/50">
                            <span>Select All</span>
                        </label>
                        <button 
                            @click="startGeneration()"
                            :disabled="selectedEmployees.length === 0"
                            class="btn-bhd px-6 py-2 rounded-xl text-sm disabled:opacity-50 disabled:cursor-not-allowed flex items-center space-x-2"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                            </svg>
                            <span>Generate (<span x-text="selectedEmployees.length"></span>)</span>
                        </button>
                    </div>
                </div>
                
                <div class="space-y-2 max-h-96 overflow-y-auto pr-2">
                    <?php foreach ($employees as $emp): ?>
                    <label class="flex items-center space-x-4 p-3 rounded-xl hover:bg-white/5 cursor-pointer transition-colors">
                        <input 
                            type="checkbox" 
                            value="<?php echo sanitize($emp['id']); ?>"
                            @change="toggleEmployee('<?php echo $emp['id']; ?>', $event.target.checked)"
                            class="rounded bg-white/10 border-white/30 text-amber-500 focus:ring-amber-500/50"
                        >
                        <div class="flex-1">
                            <p class="text-white"><?php echo sanitize($emp['name_en'] ?? ''); ?></p>
                            <p class="text-gray-500 text-sm"><?php echo sanitize($emp['email'] ?? ''); ?></p>
                        </div>
                        <p class="text-gray-600 text-sm"><?php echo sanitize($emp['position_en'] ?? ''); ?></p>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </main>
        
        <!-- Hidden rendering container -->
        <div id="renderContainer" style="position: fixed; left: -9999px; top: 0;"></div>
    </div>
    
    <script>
        function batchGenerator() {
            return {
                employees: <?php echo json_encode($employees); ?>,
                frontTemplate: <?php echo json_encode($frontTemplate); ?>,
                backTemplate: <?php echo json_encode($backTemplate); ?>,
                selectedEmployees: [],
                isGenerating: false,
                isComplete: false,
                currentIndex: 0,
                currentEmployee: '',
                completedCount: 0,
                fontMultiplier: 1.7,
                
                get progress() {
                    if (this.selectedEmployees.length === 0) return 0;
                    return Math.round((this.currentIndex / this.selectedEmployees.length) * 100);
                },
                
                toggleEmployee(id, checked) {
                    if (checked) {
                        if (!this.selectedEmployees.includes(id)) {
                            this.selectedEmployees.push(id);
                        }
                    } else {
                        this.selectedEmployees = this.selectedEmployees.filter(e => e !== id);
                    }
                },
                
                toggleAll(checked) {
                    if (checked) {
                        this.selectedEmployees = this.employees.map(e => e.id);
                        document.querySelectorAll('input[type="checkbox"][value]').forEach(cb => cb.checked = true);
                    } else {
                        this.selectedEmployees = [];
                        document.querySelectorAll('input[type="checkbox"][value]').forEach(cb => cb.checked = false);
                    }
                },
                
                async startGeneration() {
                    if (this.selectedEmployees.length === 0) return;
                    
                    this.isGenerating = true;
                    this.currentIndex = 0;
                    this.completedCount = 0;
                    
                    // Wait for fonts
                    await document.fonts.ready;
                    await new Promise(r => setTimeout(r, 500));
                    
                    for (const empId of this.selectedEmployees) {
                        const employee = this.employees.find(e => e.id === empId);
                        if (!employee) continue;
                        
                        this.currentEmployee = employee.name_en || employee.email;
                        
                        try {
                            await this.generateForEmployee(employee);
                            this.completedCount++;
                        } catch (error) {
                            console.error('Error generating for', employee.email, error);
                        }
                        
                        this.currentIndex++;
                    }
                    
                    this.isGenerating = false;
                    this.isComplete = true;
                },
                
                async generateForEmployee(employee) {
                    const container = document.getElementById('renderContainer');
                    let frontUrl = null;
                    let backUrl = null;
                    
                    // Generate front
                    if (this.frontTemplate) {
                        const frontHtml = this.buildCardHtml(this.frontTemplate, employee);
                        container.innerHTML = frontHtml;
                        await new Promise(r => setTimeout(r, 100));
                        
                        const frontCanvas = await html2canvas(container.firstChild, {
                            useCORS: true,
                            allowTaint: true,
                            backgroundColor: null,
                            scale: 1
                        });
                        
                        frontUrl = await this.saveCard(frontCanvas, 'front', employee.id);
                    }
                    
                    // Generate back
                    if (this.backTemplate) {
                        const backHtml = this.buildCardHtml(this.backTemplate, employee);
                        container.innerHTML = backHtml;
                        await new Promise(r => setTimeout(r, 100));
                        
                        const backCanvas = await html2canvas(container.firstChild, {
                            useCORS: true,
                            allowTaint: true,
                            backgroundColor: null,
                            scale: 1
                        });
                        
                        backUrl = await this.saveCard(backCanvas, 'back', employee.id);
                    }
                    
                    // Log generation
                    await fetch('<?php echo getBasePath(); ?>log_generation.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            employee_id: employee.id,
                            front_url: frontUrl,
                            back_url: backUrl
                        })
                    });
                    
                    container.innerHTML = '';
                },
                
                buildCardHtml(template, employee) {
                    const bgUrl = '<?php echo (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . getBasePath(); ?>' + template.backgroundImage.replace(/^\//, '');
                    
                    let html = `<div class="card-container" style="width: 1050px; height: 600px; background-image: url('${bgUrl}'); background-size: cover; background-position: center;">`;
                    
                    const fields = template.fields || {};
                    const fieldValues = {
                        'name_en': employee.name_en || '',
                        'name_ar': employee.name_ar || '',
                        'position_en': employee.position_en || '',
                        'position_ar': employee.position_ar || '',
                        'phone': employee.phone || '',
                        'mobile': employee.mobile || '',
                        'email': employee.email || '',
                        'company_en': employee.company_en || '',
                        'company_ar': employee.company_ar || '',
                        'website': employee.website || '',
                        'address': employee.address || ''
                    };
                    
                    for (const [key, field] of Object.entries(fields)) {
                        if (!field.enabled || key === 'qr_code') continue;
                        
                        const value = fieldValues[key] || '';
                        if (!value) continue;
                        
                        const isArabic = key.includes('_ar');
                        const fontSize = (field.fontSize || 16) * this.fontMultiplier;
                        
                        html += `<div class="card-field" style="
                            left: ${field.x || 50}%;
                            top: ${field.y || 50}%;
                            font-size: ${fontSize}px;
                            font-family: ${field.fontFamily || "'Plus Jakarta Sans', sans-serif"};
                            font-weight: ${field.fontWeight || 'normal'};
                            color: ${field.color || '#ffffff'};
                            direction: ${isArabic ? 'rtl' : 'ltr'};
                        ">${this.escapeHtml(value)}</div>`;
                    }
                    
                    html += '</div>';
                    return html;
                },
                
                escapeHtml(text) {
                    const div = document.createElement('div');
                    div.textContent = text;
                    return div.innerHTML;
                },
                
                async saveCard(canvas, side, employeeId) {
                    return new Promise((resolve) => {
                        canvas.toBlob(async (blob) => {
                            const formData = new FormData();
                            formData.append('image', blob, side + '.png');
                            formData.append('side', side);
                            formData.append('employee_id', employeeId);
                            
                            try {
                                const response = await fetch('<?php echo getBasePath(); ?>save_card_image.php', {
                                    method: 'POST',
                                    body: formData
                                });
                                const result = await response.json();
                                resolve(result.success ? result.url : null);
                            } catch {
                                resolve(null);
                            }
                        }, 'image/png');
                    });
                }
            };
        }
    </script>
</body>
</html>

