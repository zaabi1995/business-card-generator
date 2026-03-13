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
    <div class="bg-white pt-28 pb-12">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <a href="<?php echo getBasePath(); ?>blog" class="inline-flex items-center gap-2 text-blue-600 hover:text-blue-700 mb-4">
                <i class="fa-solid fa-arrow-left"></i>
                Back to Blog
            </a>
            <h1 class="text-4xl font-bold text-gray-900 mb-4"><?php echo htmlspecialchars($singlePost['title']); ?></h1>
            <div class="flex items-center gap-4 text-gray-500">
                <?php if ($singlePost['author_name']): ?>
                <span>By <?php echo htmlspecialchars($singlePost['author_name']); ?></span>
                <span>•</span>
                <?php endif; ?>
                <span><?php echo date('F j, Y', strtotime($singlePost['published_at'] ?? $singlePost['created_at'])); ?></span>
            </div>
        </div>
    </div>
    
    <style>
        .blog-content { color: #374151; line-height: 1.8; font-size: 1.125rem; }
        .blog-content h2 { font-size: 1.5rem; font-weight: 700; color: #111827; margin: 2rem 0 1rem; }
        .blog-content h3 { font-size: 1.25rem; font-weight: 600; color: #1f2937; margin: 1.5rem 0 0.75rem; }
        .blog-content p { margin-bottom: 1.25rem; }
        .blog-content ul, .blog-content ol { margin: 1rem 0 1.5rem 1.5rem; }
        .blog-content ul { list-style-type: disc; }
        .blog-content ol { list-style-type: decimal; }
        .blog-content li { margin-bottom: 0.5rem; }
        .blog-content a { color: #2563eb; text-decoration: underline; }
        .blog-content blockquote { border-left: 4px solid #3b82f6; padding: 1rem 1.5rem; margin: 1.5rem 0; background: #f9fafb; border-radius: 0.5rem; }
        .blog-content strong { color: #111827; }
        .blog-content hr { border-color: #e5e7eb; margin: 2rem 0; }
    </style>
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <article class="bg-white rounded-2xl shadow-sm p-8 lg:p-12 blog-content">
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
                    <svg viewBox="0 0 24 24" class="w-5 h-5" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                </a>
                <a href="https://twitter.com/intent/tweet?text=<?= $shareTitle ?>&url=<?= $shareUrl ?>" target="_blank" rel="noopener"
                   class="w-10 h-10 rounded-full bg-black text-white flex items-center justify-center hover:bg-gray-800 transition-colors">
                    <svg viewBox="0 0 24 24" class="w-5 h-5" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                </a>
                <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?= $shareUrl ?>" target="_blank" rel="noopener"
                   class="w-10 h-10 rounded-full bg-blue-600 text-white flex items-center justify-center hover:bg-blue-700 transition-colors">
                    <svg viewBox="0 0 24 24" class="w-5 h-5" fill="currentColor"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>
                </a>
                <button onclick="navigator.clipboard.writeText('https://cardify.om/blog/<?= $singlePost['slug'] ?>').then(()=>{this.querySelector('svg').innerHTML='<path d=\'M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z\' fill=\'currentColor\'/>';setTimeout(()=>{this.querySelector('svg').innerHTML='<path d=\'M3.9 12c0-1.71 1.39-3.1 3.1-3.1h4V7H7c-2.76 0-5 2.24-5 5s2.24 5 5 5h4v-1.9H7c-1.71 0-3.1-1.39-3.1-3.1zM8 13h8v-2H8v2zm9-6h-4v1.9h4c1.71 0 3.1 1.39 3.1 3.1s-1.39 3.1-3.1 3.1h-4V17h4c2.76 0 5-2.24 5-5s-2.24-5-5-5z\' fill=\'currentColor\'/>';},2000)})"
                   class="w-10 h-10 rounded-full bg-gray-200 text-gray-600 flex items-center justify-center hover:bg-gray-300 transition-colors">
                    <svg viewBox="0 0 24 24" class="w-5 h-5"><path d="M3.9 12c0-1.71 1.39-3.1 3.1-3.1h4V7H7c-2.76 0-5 2.24-5 5s2.24 5 5 5h4v-1.9H7c-1.71 0-3.1-1.39-3.1-3.1zM8 13h8v-2H8v2zm9-6h-4v1.9h4c1.71 0 3.1 1.39 3.1 3.1s-1.39 3.1-3.1 3.1h-4V17h4c2.76 0 5-2.24 5-5s-2.24-5-5-5z" fill="currentColor"/></svg>
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

        <!-- Related Posts -->
        <?php
        $relatedPosts = $db->fetchAll(
            "SELECT id, title, slug, excerpt, published_at, created_at, featured_image FROM blog_posts
             WHERE status = 'published' AND id != ?
             ORDER BY RAND() LIMIT 3",
            [$singlePost['id']]
        );
        if (!empty($relatedPosts)):
        ?>
        <div class="mt-10">
            <h3 class="text-xl font-bold text-gray-900 mb-6">Related Articles</h3>
            <div class="grid md:grid-cols-3 gap-6">
                <?php foreach ($relatedPosts as $rp): ?>
                <a href="<?= getBasePath() ?>blog/<?= urlencode($rp['slug']) ?>" class="bg-white rounded-xl shadow-sm p-5 hover:shadow-md transition-shadow block">
                    <span class="text-xs text-gray-400"><?= date('M j, Y', strtotime($rp['published_at'] ?? $rp['created_at'])) ?></span>
                    <h4 class="font-semibold text-gray-900 mt-1 line-clamp-2"><?= htmlspecialchars($rp['title']) ?></h4>
                    <?php if ($rp['excerpt']): ?>
                    <p class="text-gray-500 text-sm mt-2 line-clamp-2"><?= htmlspecialchars($rp['excerpt']) ?></p>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

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
