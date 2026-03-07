<?php
/**
 * Super Admin - Careers Management
 * CRUD for career listings
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';

Auth::requireRole('super_admin');

$db = Database::getInstance();
$currentUser = Auth::getCurrentUser();
$message = '';
$messageType = '';

// Check if table exists
if (!$db->tableExists('career_listings')) {
    $message = 'Career listings table does not exist. Please run database migrations.';
    $messageType = 'error';
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    die('Invalid request');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($message)) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create' || $action === 'update') {
        $id = $_POST['id'] ?? null;
        $title = trim($_POST['title'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $employmentType = $_POST['employment_type'] ?? 'full-time';
        $description = trim($_POST['description'] ?? '');
        $requirements = trim($_POST['requirements'] ?? '');
        $benefits = trim($_POST['benefits'] ?? '');
        $salaryRange = trim($_POST['salary_range'] ?? '');
        $applicationEmail = trim($_POST['application_email'] ?? 'careers@cardify.om');
        $status = $_POST['status'] ?? 'draft';
        
        // Generate slug from title if empty
        if (empty($slug) && !empty($title)) {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title));
            $slug = trim($slug, '-');
        }
        
        if (empty($title) || empty($description)) {
            $message = 'Title and description are required.';
            $messageType = 'error';
        } else {
            try {
                $data = [
                    'title' => $title,
                    'slug' => $slug,
                    'department' => $department,
                    'location' => $location,
                    'employment_type' => $employmentType,
                    'description' => $description,
                    'requirements' => $requirements,
                    'benefits' => $benefits,
                    'salary_range' => $salaryRange,
                    'application_email' => $applicationEmail,
                    'status' => $status
                ];
                
                if ($action === 'create') {
                    $db->insert('career_listings', $data);
                    $message = 'Career listing created successfully.';
                    $messageType = 'success';
                } else {
                    $db->update('career_listings', $data, ['id' => $id]);
                    $message = 'Career listing updated successfully.';
                    $messageType = 'success';
                }
            } catch (Exception $e) {
                $message = 'Error: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    } elseif ($action === 'delete') {
        $id = $_POST['id'] ?? null;
        if ($id) {
            try {
                $db->delete('career_listings', ['id' => $id]);
                $message = 'Career listing deleted successfully.';
                $messageType = 'success';
            } catch (Exception $e) {
                $message = 'Error: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
}

// Get all listings
$listings = [];
if ($db->tableExists('career_listings')) {
    $listings = $db->fetchAll("SELECT * FROM career_listings ORDER BY created_at DESC");
}

// Edit mode
$editListing = null;
if (isset($_GET['edit']) && $db->tableExists('career_listings')) {
    $editListing = $db->fetchOne("SELECT * FROM career_listings WHERE id = ?", [$_GET['edit']]);
}

adminHeader('Careers Management', 'content');
?>

<?php if ($message): ?>
<div class="mb-4 p-4 rounded-lg <?php echo $messageType === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'; ?>">
    <?php echo htmlspecialchars($message); ?>
</div>
<?php endif; ?>

<div class="grid lg:grid-cols-3 gap-6">
    <!-- Listing Form -->
    <div class="lg:col-span-1">
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">
                <?php echo $editListing ? 'Edit Listing' : 'New Listing'; ?>
            </h2>
            <form method="POST" class="space-y-4">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="<?php echo $editListing ? 'update' : 'create'; ?>">
                <?php if ($editListing): ?>
                <input type="hidden" name="id" value="<?php echo $editListing['id']; ?>">
                <?php endif; ?>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Job Title *</label>
                    <input type="text" name="title" required
                           value="<?php echo htmlspecialchars($editListing['title'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="e.g. Full Stack Developer">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Slug</label>
                    <input type="text" name="slug"
                           value="<?php echo htmlspecialchars($editListing['slug'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="auto-generated-from-title">
                </div>
                
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Department</label>
                        <input type="text" name="department"
                               value="<?php echo htmlspecialchars($editListing['department'] ?? ''); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               placeholder="e.g. Engineering">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                        <select name="employment_type" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="full-time" <?php echo ($editListing['employment_type'] ?? '') === 'full-time' ? 'selected' : ''; ?>>Full-time</option>
                            <option value="part-time" <?php echo ($editListing['employment_type'] ?? '') === 'part-time' ? 'selected' : ''; ?>>Part-time</option>
                            <option value="contract" <?php echo ($editListing['employment_type'] ?? '') === 'contract' ? 'selected' : ''; ?>>Contract</option>
                            <option value="internship" <?php echo ($editListing['employment_type'] ?? '') === 'internship' ? 'selected' : ''; ?>>Internship</option>
                            <option value="remote" <?php echo ($editListing['employment_type'] ?? '') === 'remote' ? 'selected' : ''; ?>>Remote</option>
                        </select>
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Location</label>
                    <input type="text" name="location"
                           value="<?php echo htmlspecialchars($editListing['location'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="e.g. Muscat, Oman (Hybrid)">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description *</label>
                    <textarea name="description" rows="4" required
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                              placeholder="Job description..."><?php echo htmlspecialchars($editListing['description'] ?? ''); ?></textarea>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Requirements</label>
                    <textarea name="requirements" rows="4"
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                              placeholder="- 3+ years experience..."><?php echo htmlspecialchars($editListing['requirements'] ?? ''); ?></textarea>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Benefits</label>
                    <textarea name="benefits" rows="3"
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                              placeholder="- Competitive salary..."><?php echo htmlspecialchars($editListing['benefits'] ?? ''); ?></textarea>
                </div>
                
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Salary Range</label>
                        <input type="text" name="salary_range"
                               value="<?php echo htmlspecialchars($editListing['salary_range'] ?? ''); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               placeholder="e.g. Competitive">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                        <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="draft" <?php echo ($editListing['status'] ?? '') === 'draft' ? 'selected' : ''; ?>>Draft</option>
                            <option value="open" <?php echo ($editListing['status'] ?? '') === 'open' ? 'selected' : ''; ?>>Open</option>
                            <option value="closed" <?php echo ($editListing['status'] ?? '') === 'closed' ? 'selected' : ''; ?>>Closed</option>
                        </select>
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Application Email</label>
                    <input type="email" name="application_email"
                           value="<?php echo htmlspecialchars($editListing['application_email'] ?? 'careers@cardify.om'); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                
                <div class="flex gap-2">
                    <button type="submit" class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                        <?php echo $editListing ? 'Update Listing' : 'Create Listing'; ?>
                    </button>
                    <?php if ($editListing): ?>
                    <a href="careers.php" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                        Cancel
                    </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Listings List -->
    <div class="lg:col-span-2">
        <div class="bg-white rounded-lg shadow">
            <div class="p-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900">Career Listings (<?php echo count($listings); ?>)</h2>
            </div>
            
            <?php if (empty($listings)): ?>
            <div class="p-8 text-center text-gray-500">
                <i class="fa-solid fa-briefcase text-4xl mb-4"></i>
                <p>No career listings yet. Create your first listing!</p>
            </div>
            <?php else: ?>
            <div class="divide-y divide-gray-200">
                <?php foreach ($listings as $listing): ?>
                <div class="p-4 hover:bg-gray-50">
                    <div class="flex items-start justify-between">
                        <div class="flex-1 min-w-0">
                            <h3 class="font-medium text-gray-900"><?php echo htmlspecialchars($listing['title']); ?></h3>
                            <div class="flex flex-wrap gap-2 mt-2">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                    <?php echo $listing['status'] === 'open' ? 'bg-green-100 text-green-700' : ($listing['status'] === 'draft' ? 'bg-gray-100 text-gray-700' : 'bg-red-100 text-red-700'); ?>">
                                    <?php echo ucfirst($listing['status']); ?>
                                </span>
                                <?php if ($listing['department']): ?>
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700">
                                    <?php echo htmlspecialchars($listing['department']); ?>
                                </span>
                                <?php endif; ?>
                                <?php if ($listing['employment_type']): ?>
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-700">
                                    <?php echo ucfirst(str_replace('-', ' ', $listing['employment_type'])); ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <p class="text-sm text-gray-500 mt-2">
                                <?php if ($listing['location']): ?>
                                <i class="fa-solid fa-location-dot mr-1"></i>
                                <?php echo htmlspecialchars($listing['location']); ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="flex items-center gap-2 ml-4">
                            <a href="?edit=<?php echo $listing['id']; ?>" class="p-2 text-gray-500 hover:text-blue-600 hover:bg-blue-50 rounded">
                                <i class="fa-solid fa-pen-to-square"></i>
                            </a>
                            <form method="POST" class="inline" onsubmit="return confirm('Delete this listing?');">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $listing['id']; ?>">
                                <button type="submit" class="p-2 text-gray-500 hover:text-red-600 hover:bg-red-50 rounded">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php adminFooter(); ?>
