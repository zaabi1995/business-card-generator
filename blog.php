<?php
/**
 * Cardify - Blog
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = 'Cardify Blog — Business Card Tips & Trends in Oman';
$pageDescription = 'Expert tips on business card design, networking, and professional branding for Omani businesses and entrepreneurs.';
$canonicalUrl = 'https://cardify.om/blog';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

// Enable dynamic navigation
$showNavigation = true;

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
            $pageTitle = htmlspecialchars($singlePost['title']) . ' — Cardify Blog';
            $pageDescription = htmlspecialchars($singlePost['excerpt'] ?? substr(strip_tags($singlePost['content']), 0, 155));
            $canonicalUrl = 'https://cardify.om/blog/' . $singlePost['slug'];
            $ogType = 'article';
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
            <a href="<?php echo getBasePath(); ?>blog" class="inline-flex items-center gap-2 text-blue-200 hover:text-white mb-4">
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

        <!-- Social Sharing -->
        <?php
        $shareUrl = urlencode('https://cardify.om/blog/' . $singlePost['slug']);
        $shareTitle = urlencode($singlePost['title']);
        ?>
        <div class="mt-8 bg-white rounded-xl shadow-sm p-6 flex flex-col sm:flex-row items-center justify-between gap-4">
            <span class="text-gray-600 font-medium">Share this article:</span>
            <div class="flex items-center gap-3">
                <a href="https://api.whatsapp.com/send?text=<?= $shareTitle ?>%20<?= $shareUrl ?>" target="_blank" rel="noopener"
                   class="w-10 h-10 rounded-full bg-green-500 text-white flex items-center justify-center hover:bg-green-600 transition-colors">
                    <i class="fa-brands fa-whatsapp text-lg"></i>
                </a>
                <a href="https://twitter.com/intent/tweet?text=<?= $shareTitle ?>&url=<?= $shareUrl ?>" target="_blank" rel="noopener"
                   class="w-10 h-10 rounded-full bg-black text-white flex items-center justify-center hover:bg-gray-800 transition-colors">
                    <i class="fa-brands fa-x-twitter text-lg"></i>
                </a>
                <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?= $shareUrl ?>" target="_blank" rel="noopener"
                   class="w-10 h-10 rounded-full bg-blue-700 text-white flex items-center justify-center hover:bg-blue-800 transition-colors">
                    <i class="fa-brands fa-linkedin-in text-lg"></i>
                </a>
                <button onclick="navigator.clipboard.writeText('https://cardify.om/blog/<?= $singlePost['slug'] ?>').then(()=>{this.innerHTML='<i class=\'fa-solid fa-check\'></i>';setTimeout(()=>{this.innerHTML='<i class=\'fa-solid fa-link\'></i>'},2000)})"
                   class="w-10 h-10 rounded-full bg-gray-200 text-gray-700 flex items-center justify-center hover:bg-gray-300 transition-colors">
                    <i class="fa-solid fa-link"></i>
                </button>
            </div>
        </div>

        <!-- CTA Banner -->
        <div class="mt-8 bg-gradient-to-r from-blue-600 to-blue-800 rounded-xl p-8 text-center text-white">
            <h3 class="text-2xl font-bold mb-2">Ready to create your business cards?</h3>
            <p class="text-blue-100 mb-6">Join 500+ Omani companies using Cardify. Free to start.</p>
            <a href="<?= getBasePath() ?>intro" class="inline-block bg-white text-blue-700 font-semibold px-8 py-3 rounded-lg hover:bg-blue-50 transition-colors">
                Get Started Free
            </a>
        </div>

        <div class="mt-8 text-center">
            <a href="<?php echo getBasePath(); ?>blog" class="inline-flex items-center gap-2 text-blue-600 hover:text-blue-700 font-medium">
                <i class="fa-solid fa-arrow-left"></i>
                Back to Blog
            </a>
        </div>
    </div>

    <?php if (isset($singlePost) && $singlePost): ?>
    <script type="application/ld+json">
    <?= json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Article',
        'headline' => $singlePost['title'],
        'datePublished' => $singlePost['published_at'] ?? $singlePost['created_at'],
        'dateModified' => $singlePost['updated_at'] ?? $singlePost['created_at'],
        'author' => [
            '@type' => 'Person',
            'name' => $singlePost['author_name'] ?? 'Cardify Team'
        ],
        'publisher' => [
            '@type' => 'Organization',
            'name' => 'Cardify',
            'logo' => ['@type' => 'ImageObject', 'url' => 'https://cardify.om/assets/images/logo.svg']
        ],
        'description' => $singlePost['excerpt'] ?? substr(strip_tags($singlePost['content']), 0, 160),
        'mainEntityOfPage' => 'https://cardify.om/blog/' . $singlePost['slug']
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
    </script>
    <?php endif; ?>

    <?php else: ?>
    <!-- Blog Listing -->
    <div class="bg-white pt-28 pb-12">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <h1 class="text-4xl font-bold text-gray-900 mb-3">Blog</h1>
            <p class="text-gray-500 text-lg max-w-2xl mx-auto">
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
                    <!-- Post Image / Gradient Header -->
                    <?php if (!empty($post['featured_image'])): ?>
                    <div class="h-40 overflow-hidden">
                        <img src="<?= getBasePath() . htmlspecialchars($post['featured_image']) ?>"
                             alt="<?= htmlspecialchars($post['title']) ?>"
                             class="w-full h-full object-cover" loading="lazy">
                    </div>
                    <?php else: ?>
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
                    <?php endif; ?>
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
                        <a href="<?php echo getBasePath(); ?>blog/<?php echo urlencode($post['slug']); ?>"
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
