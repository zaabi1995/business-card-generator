<?php
/**
 * Department Management
 * Manage departments and assign templates
 */
require_once __DIR__ . '/../config.php';
requireAdmin();
require_once INCLUDES_DIR . '/Auth.php';

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Departments | <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo assetUrl('css/tailwind.css'); ?>">
    <style>
        .glass-card { background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.1); }
    </style>
</head>
<body class="bg-alzayani-dark text-white font-sans min-h-screen">
    <div class="min-h-screen">
        <header class="glass-card border-b border-white/10">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-white">Departments</h1>
                        <p class="text-gray-400 text-sm">Manage departments and assign templates</p>
                    </div>
                    <a href="index.php" class="px-4 py-2 rounded-lg bg-white/5 hover:bg-white/10 transition-colors">
                        ← Back
                    </a>
                </div>
            </div>
        </header>
        
        <main class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-xl bg-green-500/10 border border-green-500/30 text-green-400">
                <?php echo sanitize($message); ?>
            </div>
            <?php endif; ?>
            
            <!-- Create Department Form -->
            <div class="glass-card rounded-xl p-6 mb-8">
                <h2 class="text-xl font-bold mb-4">Create New Department</h2>
                <form method="post" class="space-y-4">
                    <input type="hidden" name="action" value="create">
                    <div class="grid md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Department Name</label>
                            <input type="text" name="name" required class="w-full px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-white">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Default Template</label>
                            <select name="template_id" class="w-full px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-white">
                                <option value="">None</option>
                                <?php foreach ($templates as $template): ?>
                                <option value="<?php echo sanitize($template['id']); ?>"><?php echo sanitize($template['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Description</label>
                        <textarea name="description" rows="2" class="w-full px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-white"></textarea>
                    </div>
                    <button type="submit" class="px-6 py-2 rounded-lg bg-gradient-to-r from-amber-500 to-amber-600 text-white font-bold hover:from-amber-600 hover:to-amber-700 transition-colors">
                        Create Department
                    </button>
                </form>
            </div>
            
            <!-- Departments List -->
            <div class="glass-card rounded-xl p-6">
                <h2 class="text-xl font-bold mb-4">Departments</h2>
                <div class="space-y-4">
                    <?php if (empty($departments)): ?>
                    <p class="text-gray-400 text-center py-8">No departments yet. Create one above.</p>
                    <?php else: ?>
                    <?php foreach ($departments as $dept): ?>
                    <div class="p-4 rounded-lg bg-white/5 flex items-center justify-between">
                        <div>
                            <div class="font-semibold"><?php echo sanitize($dept['name']); ?></div>
                            <div class="text-sm text-gray-400">
                                <?php echo sanitize($dept['description'] ?? 'No description'); ?>
                                <?php if ($dept['template_name']): ?>
                                <span class="ml-2">• Template: <?php echo sanitize($dept['template_name']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="flex items-center space-x-2">
                            <button onclick="editDepartment('<?php echo $dept['id']; ?>', '<?php echo htmlspecialchars($dept['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($dept['description'] ?? '', ENT_QUOTES); ?>', '<?php echo $dept['template_id'] ?? ''; ?>')" class="px-4 py-2 rounded-lg bg-blue-500/20 text-blue-400 hover:bg-blue-500/30 transition-colors">
                                Edit
                            </button>
                            <form method="post" class="inline" onsubmit="return confirm('Delete this department?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="department_id" value="<?php echo sanitize($dept['id']); ?>">
                                <button type="submit" class="px-4 py-2 rounded-lg bg-red-500/20 text-red-400 hover:bg-red-500/30 transition-colors">
                                    Delete
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Edit Modal -->
    <div id="editModal" class="hidden fixed inset-0 bg-black/50 flex items-center justify-center z-50">
        <div class="glass-card rounded-xl p-6 max-w-md w-full mx-4">
            <h3 class="text-xl font-bold mb-4">Edit Department</h3>
            <form method="post" id="editForm" class="space-y-4">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="department_id" id="edit_dept_id">
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">Department Name</label>
                    <input type="text" name="name" id="edit_name" required class="w-full px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-white">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">Default Template</label>
                    <select name="template_id" id="edit_template" class="w-full px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-white">
                        <option value="">None</option>
                        <?php foreach ($templates as $template): ?>
                        <option value="<?php echo sanitize($template['id']); ?>"><?php echo sanitize($template['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">Description</label>
                    <textarea name="description" id="edit_description" rows="2" class="w-full px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-white"></textarea>
                </div>
                <div class="flex space-x-3">
                    <button type="submit" class="flex-1 px-4 py-2 rounded-lg bg-gradient-to-r from-amber-500 to-amber-600 text-white font-bold">Save</button>
                    <button type="button" onclick="document.getElementById('editModal').classList.add('hidden')" class="flex-1 px-4 py-2 rounded-lg bg-white/5 text-white">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function editDepartment(id, name, description, templateId) {
            document.getElementById('edit_dept_id').value = id;
            document.getElementById('edit_name').value = name;
            document.getElementById('edit_description').value = description;
            document.getElementById('edit_template').value = templateId || '';
            document.getElementById('editModal').classList.remove('hidden');
        }
    </script>
</body>
</html>
