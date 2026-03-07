<?php
/**
 * Cardify - Blog
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = 'Blog';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

// Enable dynamic navigation
$showNavigation = true;
$navLinks = [
    ['href' => getBasePath() . '#features', 'label' => 'Features'],
    ['href' => getBasePath() . '#pricing', 'label' => 'Pricing'],
    ['href' => getBasePath() . 'about.php', 'label' => 'About'],
    ['href' => getBasePath() . 'contact.php', 'label' => 'Contact'],
];

// Fetch blog posts from database
$db = Database::getInstance();
$posts = [];
$singlePost = null;

// Check if viewing single post
$postSlug = $_GET['post'] ?? null;

if ($db->tableExists('blog_posts')) {
    if ($postSlug) {
        // Single post view
        $singlePost = $db->fetchOne(
            "SELECT * FROM blog_posts WHERE slug = ? AND status = 'published'",
            [$postSlug]
        );
        if ($singlePost) {
            $pageTitle = $singlePost['title'] . ' - Blog';
        }
    }
    
    // Get all published posts for listing
    $posts = $db->fetchAll(
        "SELECT * FROM blog_posts WHERE status = 'published' ORDER BY published_at DESC, created_at DESC"
    );
}

require_once INCLUDES_DIR . '/ui-header.php';
?>

<div class="min-h-screen bg-gray-50">
    <?php if ($singlePost): ?>
    <!-- Single Post View -->
    <div class="bg-gradient-to-br from-blue-600 to-blue-800 text-white py-16 pt-28">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <a href="<?php echo getBasePath(); ?>blog.php" class="inline-flex items-center gap-2 text-blue-200 hover:text-white mb-4">
                <i class="fa-solid fa-arrow-left"></i>
                Back to Blog
            </a>
            <h1 class="text-4xl font-bold mb-4"><?php echo htmlspecialchars($singlePost['title']); ?></h1>
            <div class="flex items-center gap-4 text-blue-100">
                <?php if ($singlePost['author_name']): ?>
                <span>By <?php echo htmlspecialchars($singlePost['author_name']); ?></span>
                <span>•</span>
                <?php endif; ?>
                <span><?php echo date('F j, Y', strtotime($singlePost['published_at'] ?? $singlePost['created_at'])); ?></span>
            </div>
        </div>
    </div>
    
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <article class="bg-white rounded-2xl shadow-sm p-8 lg:p-12 prose prose-lg max-w-none">
            <?php echo $singlePost['content']; ?>
        </article>
        
        <div class="mt-8 text-center">
            <a href="<?php echo getBasePath(); ?>blog.php" class="inline-flex items-center gap-2 text-blue-600 hover:text-blue-700 font-medium">
                <i class="fa-solid fa-arrow-left"></i>
                Back to Blog
            </a>
        </div>
    </div>
    
    <?php else: ?>
    <!-- Blog Listing -->
    <div class="bg-gradient-to-br from-blue-600 to-blue-800 text-white py-16 pt-28">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <h1 class="text-4xl font-bold mb-4">Blog</h1>
            <p class="text-blue-100 text-lg max-w-2xl mx-auto">
                Insights, tips, and updates from the <?php echo $brandName; ?> team.
            </p>
        </div>
    </div>

    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
        
        <?php if (empty($posts)): ?>
        <!-- No Posts Banner -->
        <div class="bg-gradient-to-br from-blue-50 to-blue-100 rounded-2xl p-8 lg:p-12 mb-12 text-center">
            <div class="w-20 h-20 rounded-full bg-blue-200 flex items-center justify-center mx-auto mb-6">
                <i class="fa-solid fa-newspaper text-3xl text-blue-600"></i>
            </div>
            <h2 class="text-2xl font-bold text-gray-900 mb-4">Blog Coming Soon!</h2>
            <p class="text-gray-600 max-w-2xl mx-auto">
                We're working on creating valuable content about digital networking, productivity tips, 
                and industry insights. Check back soon!
            </p>
        </div>
        <?php else: ?>
        
        <!-- Blog Posts Grid -->
        <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8 mb-12">
            <?php foreach ($posts as $index => $post): ?>
                <article class="bg-white rounded-xl shadow-sm overflow-hidden hover:shadow-md transition-shadow">
                    <!-- Gradient Header -->
                    <div class="h-32 <?php 
                        $gradients = [
                            'bg-gradient-to-br from-blue-500 to-purple-600',
                            'bg-gradient-to-br from-green-500 to-teal-600',
                            'bg-gradient-to-br from-orange-500 to-red-600',
                            'bg-gradient-to-br from-pink-500 to-rose-600',
                            'bg-gradient-to-br from-indigo-500 to-blue-600',
                        ];
                        echo $gradients[$index % count($gradients)];
                    ?> flex items-center justify-center">
                        <i class="fa-solid fa-newspaper text-4xl text-white/50"></i>
                    </div>
                    <div class="p-6">
                        <div class="flex items-center gap-3 mb-3">
                            <span class="text-xs text-gray-500">
                                <?php echo date('M j, Y', strtotime($post['published_at'] ?? $post['created_at'])); ?>
                            </span>
                            <?php if ($post['author_name']): ?>
                            <span class="text-xs text-gray-400">•</span>
                            <span class="text-xs text-gray-500"><?php echo htmlspecialchars($post['author_name']); ?></span>
                            <?php endif; ?>
                        </div>
                        <h3 class="font-bold text-gray-900 mb-2 line-clamp-2">
                            <?php echo htmlspecialchars($post['title']); ?>
                        </h3>
                        <?php if ($post['excerpt']): ?>
                        <p class="text-gray-600 text-sm line-clamp-3 mb-4">
                            <?php echo htmlspecialchars($post['excerpt']); ?>
                        </p>
                        <?php endif; ?>
                        <a href="<?php echo getBasePath(); ?>blog.php?post=<?php echo urlencode($post['slug']); ?>" 
                           class="text-blue-600 hover:text-blue-700 text-sm font-medium inline-flex items-center gap-1">
                            Read More
                            <i class="fa-solid fa-arrow-right text-xs"></i>
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Back to Home -->
        <div class="text-center">
            <a href="<?php echo getBasePath(); ?>" class="inline-flex items-center gap-2 text-blue-600 hover:text-blue-700 font-medium">
                <i class="fa-solid fa-arrow-left"></i>
                Back to Home
            </a>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
