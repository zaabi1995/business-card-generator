<?php
/**
 * Super Admin - Blog Management
 * CRUD for blog posts
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
if (!$db->tableExists('blog_posts')) {
    $message = 'Blog posts table does not exist. Please run database migrations.';
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
        $excerpt = trim($_POST['excerpt'] ?? '');
        $content = $_POST['content'] ?? '';
        $status = $_POST['status'] ?? 'draft';
        $authorName = trim($_POST['author_name'] ?? 'Cardify Team');
        
        // Generate slug from title if empty
        if (empty($slug) && !empty($title)) {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title));
            $slug = trim($slug, '-');
        }
        
        if (empty($title) || empty($content)) {
            $message = 'Title and content are required.';
            $messageType = 'error';
        } else {
            try {
                if ($action === 'create') {
                    $db->insert('blog_posts', [
                        'title' => $title,
                        'slug' => $slug,
                        'excerpt' => $excerpt,
                        'content' => $content,
                        'status' => $status,
                        'author_name' => $authorName,
                        'author_id' => $currentUser['id'] ?? null,
                        'published_at' => $status === 'published' ? date('Y-m-d H:i:s') : null
                    ]);
                    $message = 'Blog post created successfully.';
                    $messageType = 'success';
                } else {
                    $updateData = [
                        'title' => $title,
                        'slug' => $slug,
                        'excerpt' => $excerpt,
                        'content' => $content,
                        'status' => $status,
                        'author_name' => $authorName
                    ];
                    
                    // Set published_at when first published
                    $existing = $db->fetchOne("SELECT status, published_at FROM blog_posts WHERE id = ?", [$id]);
                    if ($status === 'published' && $existing && $existing['status'] !== 'published' && empty($existing['published_at'])) {
                        $updateData['published_at'] = date('Y-m-d H:i:s');
                    }
                    
                    $db->update('blog_posts', $updateData, ['id' => $id]);
                    $message = 'Blog post updated successfully.';
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
                $db->delete('blog_posts', ['id' => $id]);
                $message = 'Blog post deleted successfully.';
                $messageType = 'success';
            } catch (Exception $e) {
                $message = 'Error: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
}

// Get all posts
$posts = [];
if ($db->tableExists('blog_posts')) {
    $posts = $db->fetchAll("SELECT * FROM blog_posts ORDER BY created_at DESC");
}

// Edit mode
$editPost = null;
if (isset($_GET['edit']) && $db->tableExists('blog_posts')) {
    $editPost = $db->fetchOne("SELECT * FROM blog_posts WHERE id = ?", [$_GET['edit']]);
}

adminHeader('Blog Management', 'content');
?>

<?php if ($message): ?>
<div class="mb-4 p-4 rounded-lg <?php echo $messageType === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'; ?>">
    <?php echo htmlspecialchars($message); ?>
</div>
<?php endif; ?>

<div class="grid lg:grid-cols-3 gap-6">
    <!-- Post Form -->
    <div class="lg:col-span-1">
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">
                <?php echo $editPost ? 'Edit Post' : 'New Post'; ?>
            </h2>
            <form method="POST" class="space-y-4">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="<?php echo $editPost ? 'update' : 'create'; ?>">
                <?php if ($editPost): ?>
                <input type="hidden" name="id" value="<?php echo $editPost['id']; ?>">
                <?php endif; ?>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Title *</label>
                    <input type="text" name="title" required
                           value="<?php echo htmlspecialchars($editPost['title'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Slug</label>
                    <input type="text" name="slug"
                           value="<?php echo htmlspecialchars($editPost['slug'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="auto-generated-from-title">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Excerpt</label>
                    <textarea name="excerpt" rows="2"
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                              placeholder="Brief summary for listings..."><?php echo htmlspecialchars($editPost['excerpt'] ?? ''); ?></textarea>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Content * (HTML)</label>
                    <textarea name="content" rows="10" required
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 font-mono text-sm"
                              placeholder="<p>Your content here...</p>"><?php echo htmlspecialchars($editPost['content'] ?? ''); ?></textarea>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Author Name</label>
                    <input type="text" name="author_name"
                           value="<?php echo htmlspecialchars($editPost['author_name'] ?? 'Cardify Team'); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="draft" <?php echo ($editPost['status'] ?? '') === 'draft' ? 'selected' : ''; ?>>Draft</option>
                        <option value="published" <?php echo ($editPost['status'] ?? '') === 'published' ? 'selected' : ''; ?>>Published</option>
                        <option value="archived" <?php echo ($editPost['status'] ?? '') === 'archived' ? 'selected' : ''; ?>>Archived</option>
                    </select>
                </div>
                
                <div class="flex gap-2">
                    <button type="submit" class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                        <?php echo $editPost ? 'Update Post' : 'Create Post'; ?>
                    </button>
                    <?php if ($editPost): ?>
                    <a href="blog.php" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                        Cancel
                    </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Posts List -->
    <div class="lg:col-span-2">
        <div class="bg-white rounded-lg shadow">
            <div class="p-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900">Blog Posts (<?php echo count($posts); ?>)</h2>
            </div>
            
            <?php if (empty($posts)): ?>
            <div class="p-8 text-center text-gray-500">
                <i class="fa-solid fa-newspaper text-4xl mb-4"></i>
                <p>No blog posts yet. Create your first post!</p>
            </div>
            <?php else: ?>
            <div class="divide-y divide-gray-200">
                <?php foreach ($posts as $post): ?>
                <div class="p-4 hover:bg-gray-50">
                    <div class="flex items-start justify-between">
                        <div class="flex-1 min-w-0">
                            <h3 class="font-medium text-gray-900 truncate"><?php echo htmlspecialchars($post['title']); ?></h3>
                            <p class="text-sm text-gray-500 mt-1">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                    <?php echo $post['status'] === 'published' ? 'bg-green-100 text-green-700' : ($post['status'] === 'draft' ? 'bg-gray-100 text-gray-700' : 'bg-yellow-100 text-yellow-700'); ?>">
                                    <?php echo ucfirst($post['status']); ?>
                                </span>
                                <span class="mx-2">•</span>
                                <?php echo date('M j, Y', strtotime($post['created_at'])); ?>
                                <?php if ($post['author_name']): ?>
                                <span class="mx-2">•</span>
                                By <?php echo htmlspecialchars($post['author_name']); ?>
                                <?php endif; ?>
                            </p>
                            <?php if ($post['excerpt']): ?>
                            <p class="text-sm text-gray-600 mt-2 line-clamp-2"><?php echo htmlspecialchars($post['excerpt']); ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="flex items-center gap-2 ml-4">
                            <a href="?edit=<?php echo $post['id']; ?>" class="p-2 text-gray-500 hover:text-blue-600 hover:bg-blue-50 rounded">
                                <i class="fa-solid fa-pen-to-square"></i>
                            </a>
                            <form method="POST" class="inline" onsubmit="return confirm('Delete this post?');">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $post['id']; ?>">
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
