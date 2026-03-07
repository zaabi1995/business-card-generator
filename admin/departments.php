<?php
/**
 * Department Management - Cardify
 * Manage departments and assign template pairs
 */
require_once __DIR__ . '/../config.php';
requireAdmin();
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';

$db = Database::getInstance();
$companyId = getCurrentCompanyId();

if (!$companyId) {
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

$company = findCompanyById($companyId);
$companySlug = $company['slug'] ?? '';

$message = null;
$messageType = 'success';

// Get departments with template pair info
$departments = $db->fetchAll(
    "SELECT d.*, 
            t.name as template_name,
            tp.name as pair_name
     FROM departments d 
     LEFT JOIN templates t ON d.template_id = t.id 
     LEFT JOIN templates tp ON d.template_pair_id = tp.pair_id AND tp.side = 'front'
     WHERE d.company_id = :id 
     ORDER BY d.name",
    ['id' => $companyId]
);

// Get available template pairs (grouped by pair_id)
$templatePairs = $db->fetchAll(
    "SELECT DISTINCT t.pair_id, t.name, 
            (SELECT background_image_path FROM templates WHERE pair_id = t.pair_id AND side = 'front' LIMIT 1) as front_image
     FROM templates t 
     WHERE t.company_id = :id AND t.pair_id IS NOT NULL
     GROUP BY t.pair_id, t.name
     ORDER BY t.name",
    ['id' => $companyId]
);

// Get legacy templates (no pair_id) for backward compatibility
$legacyTemplates = $db->fetchAll(
    "SELECT * FROM templates WHERE company_id = :id AND pair_id IS NULL AND is_active = 1 ORDER BY name",
    ['id' => $companyId]
);

// Handle create/update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { die('Invalid request'); }
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $name = $_POST['name'] ?? '';
        $description = $_POST['description'] ?? '';
        $templatePairId = $_POST['template_pair_id'] ?? null;
        $slug = trim($_POST['slug'] ?? '');
        $portalPasscode = trim($_POST['portal_passcode'] ?? '');
        
        // Validate passcode (only digits, max 4)
        if (!empty($portalPasscode) && !preg_match('/^\d{1,4}$/', $portalPasscode)) {
            $portalPasscode = '';
        }
        
        // Auto-generate slug if not provided
        if (empty($slug)) {
            $slug = generateSlug($name);
        }
        
        // Ensure slug is unique for this company
        $baseSlug = $slug;
        $counter = 1;
        while ($db->fetchOne("SELECT id FROM departments WHERE company_id = :cid AND portal_slug = :slug", ['cid' => $companyId, 'slug' => $slug])) {
            $slug = $baseSlug . '-' . $counter++;
        }
        
        if (!empty($name)) {
            $deptId = generateUUID();
            $insertData = [
                'id' => $deptId,
                'company_id' => $companyId,
                'name' => $name,
                'description' => $description,
                'portal_slug' => $slug,
                'access_code' => $portalPasscode ?: null,
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            // Handle template pair assignment
            if (!empty($templatePairId)) {
                $insertData['template_pair_id'] = $templatePairId;
            }
            
            $db->insert('departments', $insertData);
            $message = 'Department created successfully!';
        }
    } elseif ($action === 'update') {
        $deptId = $_POST['department_id'] ?? '';
        $name = $_POST['name'] ?? '';
        $description = $_POST['description'] ?? '';
        $templatePairId = $_POST['template_pair_id'] ?? null;
        $slug = trim($_POST['slug'] ?? '');
        $portalPasscode = trim($_POST['portal_passcode'] ?? '');
        
        // Validate passcode (only digits, max 4)
        if (!empty($portalPasscode) && !preg_match('/^\d{1,4}$/', $portalPasscode)) {
            $portalPasscode = '';
        }
        
        // Auto-generate slug if not provided
        if (empty($slug)) {
            $slug = generateSlug($name);
        }
        
        // Ensure slug is unique for this company (excluding current department)
        $baseSlug = $slug;
        $counter = 1;
        while ($db->fetchOne("SELECT id FROM departments WHERE company_id = :cid AND portal_slug = :slug AND id != :id", ['cid' => $companyId, 'slug' => $slug, 'id' => $deptId])) {
            $slug = $baseSlug . '-' . $counter++;
        }
        
        if (!empty($deptId) && !empty($name)) {
            $updateData = [
                'name' => $name,
                'description' => $description,
                'portal_slug' => $slug,
                'template_pair_id' => $templatePairId ?: null,
                'access_code' => $portalPasscode ?: null
            ];
            
            $db->update('departments', $updateData, 'id = :id AND company_id = :company_id', [
                'id' => $deptId,
                'company_id' => $companyId
            ]);
            $message = 'Department updated successfully!';
        }
    } elseif ($action === 'delete') {
        $deptId = $_POST['department_id'] ?? '';
        if (!empty($deptId)) {
            // Unlink employees from this department first
            $db->update('employees', ['department_id' => null], 'department_id = :id', ['id' => $deptId]);
            
            $db->delete('departments', 'id = :id AND company_id = :company_id', [
                'id' => $deptId,
                'company_id' => $companyId
            ]);
            $message = 'Department deleted successfully!';
        }
    }
    
    // Reload departments
    $departments = $db->fetchAll(
        "SELECT d.*, 
                t.name as template_name,
                tp.name as pair_name
         FROM departments d 
         LEFT JOIN templates t ON d.template_id = t.id 
         LEFT JOIN templates tp ON d.template_pair_id = tp.pair_id AND tp.side = 'front'
         WHERE d.company_id = :id 
         ORDER BY d.name",
        ['id' => $companyId]
    );
}

// Get employee counts per department
$deptCounts = [];
foreach ($departments as $dept) {
    $count = $db->fetchOne(
        "SELECT COUNT(*) as count FROM employees WHERE department_id = :id",
        ['id' => $dept['id']]
    );
    $deptCounts[$dept['id']] = $count['count'] ?? 0;
}

adminHeader('Departments', 'departments');
?>

<div x-data="{ showModal: false, editMode: false, formData: { id: '', name: '', description: '', portal_slug: '', template_pair_id: '' } }">
    <!-- Page Header Actions -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <p class="text-gray-600"><?php echo count($departments); ?> departments</p>
        </div>
        <button @click="showModal = true; editMode = false; formData = { id: '', name: '', description: '', portal_slug: '', template_pair_id: '', access_code: '' }" 
                class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors text-sm font-medium flex items-center gap-2">
            <i class="fa-solid fa-plus"></i>
            <span>Add Department</span>
        </button>
    </div>

    <!-- Alert Message -->
    <?php if ($message): ?>
    <div class="mb-6 p-4 rounded-xl flex items-center gap-3 bg-green-50 border border-green-200 text-green-700">
        <i class="fa-solid fa-circle-check"></i>
        <?php echo sanitize($message); ?>
    </div>
    <?php endif; ?>

    <!-- Departments Grid -->
    <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($departments as $dept): ?>
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm hover:shadow-md transition-shadow overflow-hidden">
            <div class="p-6">
                <div class="flex items-start justify-between mb-4">
                    <div class="w-12 h-12 rounded-xl bg-blue-100 flex items-center justify-center">
                        <i class="fa-solid fa-sitemap text-blue-600 text-xl"></i>
                    </div>
                    <div class="flex items-center gap-1">
                        <button 
                            @click="showModal = true; editMode = true; formData = { id: '<?php echo $dept['id']; ?>', name: '<?php echo addslashes($dept['name']); ?>', description: '<?php echo addslashes($dept['description'] ?? ''); ?>', portal_slug: '<?php echo addslashes($dept['portal_slug'] ?? ''); ?>', template_pair_id: '<?php echo $dept['template_pair_id'] ?? ''; ?>', access_code: '<?php echo addslashes($dept['access_code'] ?? ''); ?>' }"
                            class="p-2 text-gray-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
                        >
                            <i class="fa-solid fa-pen-to-square"></i>
                        </button>
                        <form method="post" class="inline" onsubmit="return confirm('Delete this department?')">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="department_id" value="<?php echo $dept['id']; ?>">
                            <button type="submit" class="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors">
                                <i class="fa-solid fa-trash-can"></i>
                            </button>
                        </form>
                    </div>
                </div>
                
                <h3 class="text-lg font-bold text-gray-900 mb-1"><?php echo sanitize($dept['name']); ?></h3>
                
                <?php if (!empty($dept['portal_slug']) && !empty($companySlug)): ?>
                <div class="flex items-center gap-1.5 text-xs text-blue-600 mb-2">
                    <i class="fa-solid fa-link"></i>
                    <code class="bg-blue-50 px-1.5 py-0.5 rounded">/<?php echo $companySlug; ?>/portal/<?php echo $dept['portal_slug']; ?></code>
                    <button type="button" onclick="copyToClipboard('<?php echo getBaseUrl() . $companySlug . '/portal/' . $dept['portal_slug']; ?>')" 
                            class="text-gray-400 hover:text-blue-600 transition-colors" title="Copy link">
                        <i class="fa-solid fa-copy"></i>
                    </button>
                    <?php if (!empty($dept['access_code'])): ?>
                    <span class="inline-flex items-center px-1.5 py-0.5 rounded bg-amber-100 text-amber-700 text-[10px] font-medium ml-1" title="Access code: <?php echo $dept['access_code']; ?>">
                        <i class="fa-solid fa-lock mr-0.5"></i>Protected
                    </span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($dept['description'])): ?>
                <p class="text-gray-600 text-sm mb-4"><?php echo sanitize($dept['description']); ?></p>
                <?php endif; ?>
                
                <div class="flex items-center justify-between pt-4 border-t border-gray-100">
                    <div class="flex items-center gap-2 text-sm text-gray-500">
                        <i class="fa-solid fa-users"></i>
                        <span><?php echo $deptCounts[$dept['id']] ?? 0; ?> employees</span>
                    </div>
                    
                    <?php 
                    $templateName = $dept['pair_name'] ?? $dept['template_name'] ?? null;
                    if (!empty($templateName)): 
                    ?>
                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-purple-50 text-purple-700">
                        <i class="fa-solid fa-palette mr-1"></i>
                        <?php echo sanitize($templateName); ?>
                    </span>
                    <?php else: ?>
                    <span class="text-xs text-gray-400">Company default</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        
        <?php if (empty($departments)): ?>
        <div class="md:col-span-2 lg:col-span-3 bg-white rounded-xl border border-gray-200 p-12 text-center">
            <div class="text-gray-400">
                <i class="fa-solid fa-sitemap text-4xl mb-4 opacity-50"></i>
                <p class="text-gray-600 font-medium">No departments yet</p>
                <p class="text-sm mt-1">Create departments to organize your employees</p>
                <button @click="showModal = true; editMode = false" class="mt-4 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium">
                    <i class="fa-solid fa-plus mr-2"></i>Create First Department
                </button>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Add/Edit Modal -->
    <div x-show="showModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" @keydown.escape.window="showModal = false">
        <div class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" @click="showModal = false"></div>
        <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-md">
            <div class="p-6 border-b border-gray-100">
                <h3 class="text-xl font-bold text-gray-900" x-text="editMode ? 'Edit Department' : 'Create Department'"></h3>
            </div>
            
            <form method="post" class="p-6">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" :value="editMode ? 'update' : 'create'">
                <input type="hidden" name="department_id" x-model="formData.id">
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Department Name <span class="text-red-500">*</span></label>
                        <input type="text" name="name" x-model="formData.name" required 
                               @input="if(!editMode) formData.portal_slug = $event.target.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')"
                               class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                               placeholder="e.g., Marketing">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            Portal Slug
                            <span class="text-xs font-normal text-gray-500">(URL identifier)</span>
                        </label>
                        <div class="flex items-center gap-2">
                            <span class="text-gray-500 text-sm whitespace-nowrap">/<?php echo $companySlug; ?>/portal/</span>
                            <input type="text" name="slug" x-model="formData.portal_slug" 
                                   class="flex-1 px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 font-mono text-sm"
                                   placeholder="marketing" pattern="[a-z0-9-]+">
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Lowercase letters, numbers and dashes only. Auto-generated from name.</p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Card Design Template</label>
                        <select name="template_pair_id" x-model="formData.template_pair_id" 
                                class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                            <option value="">Use company default</option>
                            <?php foreach ($templatePairs as $pair): ?>
                            <option value="<?php echo sanitize($pair['pair_id']); ?>"><?php echo sanitize($pair['name']); ?></option>
                            <?php endforeach; ?>
                            <?php if (!empty($legacyTemplates)): ?>
                            <optgroup label="Legacy Templates">
                            <?php foreach ($legacyTemplates as $template): ?>
                            <option value="legacy_<?php echo sanitize($template['id']); ?>"><?php echo sanitize($template['name']); ?> (<?php echo $template['side']; ?>)</option>
                            <?php endforeach; ?>
                            </optgroup>
                            <?php endif; ?>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Employees in this department will use this card design instead of company default</p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Description</label>
                        <textarea name="description" x-model="formData.description" rows="3" 
                                  class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                                  placeholder="Brief description of this department"></textarea>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            Portal Access Code
                            <span class="text-xs font-normal text-gray-500">(optional)</span>
                        </label>
                        <input type="text" name="portal_passcode" x-model="formData.access_code" 
                               maxlength="4" pattern="[0-9]*" inputmode="numeric"
                               class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 font-mono text-lg tracking-widest"
                               placeholder="e.g., 1234">
                        <p class="text-xs text-gray-500 mt-1">4-digit code to protect department portal. Leave empty for no restriction.</p>
                    </div>
                </div>
                
                <div class="flex items-center justify-end gap-3 mt-6 pt-6 border-t border-gray-100">
                    <button type="button" @click="showModal = false" class="px-4 py-2 text-gray-600 hover:text-gray-900 font-medium transition-colors">
                        Cancel
                    </button>
                    <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors">
                        <span x-text="editMode ? 'Save Changes' : 'Create Department'"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        // Show brief feedback
        const btn = event.target.closest('button');
        if (btn) {
            const icon = btn.querySelector('i');
            if (icon) {
                icon.className = 'fa-solid fa-check';
                setTimeout(() => {
                    icon.className = 'fa-solid fa-copy';
                }, 2000);
            }
        }
    });
}
</script>

<?php adminFooter(); ?>
