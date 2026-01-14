<?php
/**
 * Company Management (Super Admin)
 * Manage companies and their hierarchy
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';

Auth::requireRole('super_admin');

$db = Database::getInstance();
$message = null;

// Get all companies
$companies = $db->fetchAll("SELECT * FROM companies ORDER BY company_path, name");

// Get parent companies for dropdown
$parentCompanies = $db->fetchAll(
    "SELECT id, name, company_path FROM companies WHERE company_type IN ('standalone', 'parent') ORDER BY company_path, name"
);

// Handle create/update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $name = $_POST['name'] ?? '';
        $email = sanitizeEmail($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $parentId = $_POST['parent_company_id'] ?? null;
        
        if (!empty($name) && !empty($email) && !empty($password)) {
            $result = DatabaseAdapter::createCompany($name, $email, $password, $parentId);
            if ($result['success']) {
                $message = 'Company created successfully!';
                $companies = $db->fetchAll("SELECT * FROM companies ORDER BY company_path, name");
            } else {
                $message = $result['error'] ?? 'Failed to create company';
            }
        }
    } elseif ($action === 'update_parent') {
        $companyId = $_POST['company_id'] ?? '';
        $parentId = $_POST['parent_company_id'] ?? null;
        
        if (!empty($companyId)) {
            // Update parent and rebuild path
            $company = $db->fetchOne("SELECT * FROM companies WHERE id = :id", ['id' => $companyId]);
            if ($company) {
                $companyPath = $company['name'];
                $companyType = 'standalone';
                
                if ($parentId) {
                    $parent = $db->fetchOne("SELECT * FROM companies WHERE id = :id", ['id' => $parentId]);
                    if ($parent) {
                        $parentPath = $parent['company_path'] ?? $parent['name'];
                        $companyPath = $parentPath . ' > ' . $company['name'];
                        $companyType = 'child';
                    }
                }
                
                $db->update('companies', [
                    'parent_company_id' => $parentId,
                    'company_path' => $companyPath,
                    'company_type' => $companyType
                ], 'id = :id', ['id' => $companyId]);
                
                $message = 'Company hierarchy updated!';
                $companies = $db->fetchAll("SELECT * FROM companies ORDER BY company_path, name");
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Management | <?php echo SITE_NAME; ?></title>
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
                        <h1 class="text-2xl font-bold text-white">Company Management</h1>
                        <p class="text-gray-400 text-sm">Manage companies and hierarchy</p>
                    </div>
                    <a href="super/" class="px-4 py-2 rounded-lg bg-white/5 hover:bg-white/10 transition-colors">
                        ← Back
                    </a>
                </div>
            </div>
        </header>
        
        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-xl bg-green-500/10 border border-green-500/30 text-green-400">
                <?php echo sanitize($message); ?>
            </div>
            <?php endif; ?>
            
            <!-- Create Company -->
            <div class="glass-card rounded-xl p-6 mb-8">
                <h2 class="text-xl font-bold mb-4">Create New Company</h2>
                <form method="post" class="space-y-4">
                    <input type="hidden" name="action" value="create">
                    <div class="grid md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Company Name</label>
                            <input type="text" name="name" required class="w-full px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-white">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Admin Email</label>
                            <input type="email" name="email" required class="w-full px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-white">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Password</label>
                            <input type="password" name="password" required class="w-full px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-white">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Parent Company (Optional)</label>
                        <select name="parent_company_id" class="w-full px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-white">
                            <option value="">None (Standalone)</option>
                            <?php foreach ($parentCompanies as $parent): ?>
                            <option value="<?php echo sanitize($parent['id']); ?>">
                                <?php echo sanitize($parent['company_path'] ?? $parent['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="px-6 py-2 rounded-lg bg-gradient-to-r from-amber-500 to-amber-600 text-white font-bold">
                        Create Company
                    </button>
                </form>
            </div>
            
            <!-- Companies List -->
            <div class="glass-card rounded-xl p-6">
                <h2 class="text-xl font-bold mb-4">All Companies</h2>
                <div class="space-y-4">
                    <?php foreach ($companies as $comp): ?>
                    <div class="p-4 rounded-lg bg-white/5 flex items-center justify-between">
                        <div class="flex-1">
                            <div class="font-semibold"><?php echo sanitize($comp['name']); ?></div>
                            <div class="text-sm text-gray-400">
                                <span>Slug: </span><code class="bg-black/30 px-2 py-1 rounded"><?php echo sanitize($comp['slug']); ?></code>
                                <span class="ml-4">Path: </span><?php echo sanitize($comp['company_path'] ?? $comp['name']); ?>
                            </div>
                            <div class="text-xs text-gray-500 mt-1">
                                <a href="<?php echo getBaseUrl() . sanitize($comp['slug']); ?>" target="_blank" class="text-amber-400 hover:text-amber-300">
                                    View: <?php echo getBaseUrl() . sanitize($comp['slug']); ?>
                                </a>
                            </div>
                        </div>
                        <div>
                            <form method="post" class="inline">
                                <input type="hidden" name="action" value="update_parent">
                                <input type="hidden" name="company_id" value="<?php echo sanitize($comp['id']); ?>">
                                <select name="parent_company_id" onchange="this.form.submit()" class="px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-white text-sm">
                                    <option value="">None</option>
                                    <?php foreach ($parentCompanies as $parent): ?>
                                    <?php if ($parent['id'] !== $comp['id']): ?>
                                    <option value="<?php echo sanitize($parent['id']); ?>" <?php echo ($comp['parent_company_id'] === $parent['id']) ? 'selected' : ''; ?>>
                                        <?php echo sanitize($parent['company_path'] ?? $parent['name']); ?>
                                    </option>
                                    <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
