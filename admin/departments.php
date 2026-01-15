<?php
/**
 * Department Management - Cardify
 * Manage departments and assign templates
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

$message = null;
$messageType = 'success';

// Get departments
$departments = $db->fetchAll(
    "SELECT d.*, t.name as template_name FROM departments d 
     LEFT JOIN templates t ON d.template_id = t.id 
     WHERE d.company_id = :id ORDER BY d.name",
    ['id' => $companyId]
);

// Get available templates
$templates = $db->fetchAll(
    "SELECT * FROM templates WHERE company_id = :id AND is_active = 1 ORDER BY name",
    ['id' => $companyId]
);

// Handle create/update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $name = $_POST['name'] ?? '';
        $description = $_POST['description'] ?? '';
        $templateId = $_POST['template_id'] ?? null;
        
        if (!empty($name)) {
            $deptId = generateUUID();
            $db->insert('departments', [
                'id' => $deptId,
                'company_id' => $companyId,
                'name' => $name,
                'description' => $description,
                'template_id' => $templateId ?: null
            ]);
            $message = 'Department created successfully!';
        }
    } elseif ($action === 'update') {
        $deptId = $_POST['department_id'] ?? '';
        $name = $_POST['name'] ?? '';
        $description = $_POST['description'] ?? '';
        $templateId = $_POST['template_id'] ?? null;
        
        if (!empty($deptId) && !empty($name)) {
            $db->update('departments', [
                'name' => $name,
                'description' => $description,
                'template_id' => $templateId ?: null
            ], 'id = :id AND company_id = :company_id', [
                'id' => $deptId,
                'company_id' => $companyId
            ]);
            $message = 'Department updated successfully!';
        }
    } elseif ($action === 'delete') {
        $deptId = $_POST['department_id'] ?? '';
        if (!empty($deptId)) {
            $db->delete('departments', 'id = :id AND company_id = :company_id', [
                'id' => $deptId,
                'company_id' => $companyId
            ]);
            $message = 'Department deleted successfully!';
        }
    }
    
    // Reload departments
    $departments = $db->fetchAll(
        "SELECT d.*, t.name as template_name FROM departments d 
         LEFT JOIN templates t ON d.template_id = t.id 
         WHERE d.company_id = :id ORDER BY d.name",
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

<div x-data="{ showModal: false, editMode: false, formData: { id: '', name: '', description: '', template_id: '' } }">
    <!-- Page Header Actions -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <p class="text-gray-600"><?php echo count($departments); ?> departments</p>
        </div>
        <button @click="showModal = true; editMode = false; formData = { id: '', name: '', description: '', template_id: '' }" 
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
                            @click="showModal = true; editMode = true; formData = { id: '<?php echo $dept['id']; ?>', name: '<?php echo addslashes($dept['name']); ?>', description: '<?php echo addslashes($dept['description'] ?? ''); ?>', template_id: '<?php echo $dept['template_id'] ?? ''; ?>' }"
                            class="p-2 text-gray-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
                        >
                            <i class="fa-solid fa-pen-to-square"></i>
                        </button>
                        <form method="post" class="inline" onsubmit="return confirm('Delete this department?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="department_id" value="<?php echo $dept['id']; ?>">
                            <button type="submit" class="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors">
                                <i class="fa-solid fa-trash-can"></i>
                            </button>
                        </form>
                    </div>
                </div>
                
                <h3 class="text-lg font-bold text-gray-900 mb-2"><?php echo sanitize($dept['name']); ?></h3>
                
                <?php if (!empty($dept['description'])): ?>
                <p class="text-gray-600 text-sm mb-4"><?php echo sanitize($dept['description']); ?></p>
                <?php endif; ?>
                
                <div class="flex items-center justify-between pt-4 border-t border-gray-100">
                    <div class="flex items-center gap-2 text-sm text-gray-500">
                        <i class="fa-solid fa-users"></i>
                        <span><?php echo $deptCounts[$dept['id']] ?? 0; ?> employees</span>
                    </div>
                    
                    <?php if (!empty($dept['template_name'])): ?>
                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-purple-50 text-purple-700">
                        <i class="fa-solid fa-palette mr-1"></i>
                        <?php echo sanitize($dept['template_name']); ?>
                    </span>
                    <?php else: ?>
                    <span class="text-xs text-gray-400">No template</span>
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
                <input type="hidden" name="action" :value="editMode ? 'update' : 'create'">
                <input type="hidden" name="department_id" x-model="formData.id">
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Department Name <span class="text-red-500">*</span></label>
                        <input type="text" name="name" x-model="formData.name" required 
                               class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                               placeholder="e.g., Marketing">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Default Template</label>
                        <select name="template_id" x-model="formData.template_id" 
                                class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                            <option value="">No default template</option>
                            <?php foreach ($templates as $template): ?>
                            <option value="<?php echo sanitize($template['id']); ?>"><?php echo sanitize($template['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Employees in this department will use this template by default</p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Description</label>
                        <textarea name="description" x-model="formData.description" rows="3" 
                                  class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                                  placeholder="Brief description of this department"></textarea>
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

<?php adminFooter(); ?>
