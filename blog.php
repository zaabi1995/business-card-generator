<?php
require_once __DIR__ . '/includes/PlatformStats.php';
/**
 * Cardify - Blog (Cat R action 440: bilingual).
 *
 * When ?lang=ar (served via /ar/blog nginx rewrite), title/excerpt/
 * content/slug fall back to {field}_ar if present, else show the EN
 * original so English-only legacy posts keep working.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/BlogSlugRedirects.php';
require_once INCLUDES_DIR . '/ArTwins.php';
require_once INCLUDES_DIR . '/Seo.php';

$lang  = ($_GET['lang'] ?? '') === 'ar' ? 'ar' : 'en';
$isAr  = $lang === 'ar';
$baseUrl = 'https://cardify.om';
$blogBase = $baseUrl . ArTwins::navLink('blog', '/', $isAr);

/** Pick locale-preferred column on a post row, EN fallback. */
$L = function(array $row, string $field) use ($isAr) {
    if ($isAr) {
        $ar = $row[$field . '_ar'] ?? null;
        if (is_string($ar) && trim($ar) !== '') return $ar;
    }
    return $row[$field] ?? '';
};

$pageTitle = t('blog.page_title');
$pageDescription = t('blog.page_desc');
$canonicalUrl = $blogBase;
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

// Enable dynamic navigation
$showNavigation = true;

// Fetch blog posts from database
$db = Database::getInstance();
$posts = [];
$singlePost = null;

// Check if viewing single post. Validate slug charset before hitting the DB so
// weird inputs (unicode, SQL-like strings, length bombs) never reach PDO.
$postSlug = $_GET['post'] ?? null;
if (is_string($postSlug) && !preg_match('~^[a-z0-9_-]{1,120}$~i', $postSlug)) {
    $postSlug = null;
}

if ($db->tableExists('blog_posts')) {
    if ($postSlug) {
        // Single post view. Accept either the EN or AR slug. PDO has emulated
        // prepares OFF in this project, so the same placeholder cannot appear
        // twice in one query (HY093). Bind the slug under two distinct names.
        $singlePost = $db->fetchOne(
            "SELECT * FROM blog_posts WHERE (slug = :slug_en OR slug_ar = :slug_ar) AND status = 'published' LIMIT 1",
            ['slug_en' => $postSlug, 'slug_ar' => $postSlug]
        );
        if ($singlePost) {
            $displayTitle   = $L($singlePost, 'title');
            $displayExcerpt = $L($singlePost, 'excerpt');
            $displayContent = $L($singlePost, 'content');
            $displaySlug    = $isAr && !empty($singlePost['slug_ar']) ? $singlePost['slug_ar'] : $singlePost['slug'];
            $pageTitle = t('blog.single_page_title', ['title' => $displayTitle]);
            $pageDescription = htmlspecialchars($displayExcerpt !== '' ? $displayExcerpt : mb_substr(strip_tags($displayContent), 0, 155));
            $canonicalUrl = $blogBase . '/' . $displaySlug;
            $ogType = 'article';
            if (!empty($singlePost['featured_image'])) {
                $ogImage = 'https://cardify.om/' . ltrim($singlePost['featured_image'], '/');
            }

            // Conditional crawl support: respect If-Modified-Since from Googlebot
            $lastMod = strtotime($singlePost['updated_at'] ?? $singlePost['published_at'] ?? $singlePost['created_at']);
            if ($lastMod) {
                $lastModHttp = gmdate('D, d M Y H:i:s \G\M\T', $lastMod);
                header('Last-Modified: ' . $lastModHttp);
                $ifModSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
                if ($ifModSince && strtotime($ifModSince) >= $lastMod) {
                    header('HTTP/1.1 304 Not Modified');
                    exit;
                }
            }
        } else {
            // A retired slug gets its successor, not a 404.
            //
            // Always the EN tree, deliberately: nginx retired /ar/blog wholesale
            // (`rewrite ^/ar/blog/([a-z0-9-]+)/?$ /blog/$1 permanent`, because the
            // post bodies are English at a 0.13 Arabic letter share), so an AR
            // request is already on /blog by the time PHP runs and a
            // language-preserving branch here would be dead code that reads like
            // a fix. /ar/blog/<old> therefore costs two hops and lands on the
            // live EN post; collapsing that to one would mean duplicating this
            // map in nginx, which is how the two got out of step in the first place.
            $moved = BlogSlugRedirects::target(strtolower($postSlug));
            if ($moved !== null) {
                header('Location: https://cardify.om/blog/' . $moved, true, 301);
                exit;
            }
            // Unknown slug, return proper 404 so Google doesn't index a fallback listing
            http_response_code(404);
            header('Cache-Control: no-store');
            include __DIR__ . '/404.php';
            exit;
        }
    }
    
    // Get all published posts for listing
    $posts = $db->fetchAll(
        "SELECT * FROM blog_posts WHERE status = 'published' ORDER BY published_at DESC, created_at DESC"
    );
}

// Build JSON-LD structured data (Article for single post, Blog listing otherwise)
$extraHead = '';
if ($singlePost) {
    $published = date('c', strtotime($singlePost['published_at'] ?? $singlePost['created_at']));
    $modified = date('c', strtotime($singlePost['updated_at'] ?? $singlePost['published_at'] ?? $singlePost['created_at']));
    $imageUrl = !empty($singlePost['featured_image'])
        ? 'https://cardify.om/' . ltrim($singlePost['featured_image'], '/')
        : 'https://cardify.om/assets/images/cardify-og.png';
    $articleLd = [
        '@context' => 'https://schema.org',
        '@type' => 'BlogPosting',
        'inLanguage' => $isAr ? 'ar' : 'en',
        'headline' => $L($singlePost, 'title'),
        'description' => $L($singlePost, 'excerpt') ?: mb_substr(strip_tags($L($singlePost, 'content')), 0, 160),
        'image' => $imageUrl,
        'url' => $canonicalUrl,
        'datePublished' => $published,
        'dateModified' => $modified,
        'author' => [
            '@type' => 'Organization',
            'name' => $singlePost['author_name'] ?? 'Cardify Team',
            'url' => 'https://cardify.om/about',
        ],
        // r153 / llm148-1: a REFERENCE, not a fourth body. This slot used to
        // spell out a 5-key Organization under the same @id that
        // includes/Seo.php owns with 24 keys, and it disagreed on three of
        // them: @type (Organization, dropping LocalBusiness), logo (an
        // ImageObject where the owner publishes a URL) and url (no trailing
        // slash). Seo::articleNode already made exactly this correction for
        // /solutions/* under llm20-11; the blog post was the copy nobody
        // carried it to. The owner node is emitted alongside this graph below,
        // so the reference still resolves inside the document that makes it
        // (the r37 rule), which is why this is a ref and not a deletion.
        'publisher' => Seo::publisherNode(),
        'mainEntityOfPage' => [
            '@type' => 'WebPage',
            '@id' => $canonicalUrl,
        ],
    ];
    $breadcrumbLd = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => $isAr ? 'الرئيسية' : 'Home', 'item' => $baseUrl . ($isAr ? '/ar/' : '/')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => $isAr ? 'المدوّنة' : 'Blog',    'item' => $blogBase],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $L($singlePost, 'title'),       'item' => $canonicalUrl],
        ],
    ];
    // hreflang alternates when a translation exists
    $altSlug = $isAr ? $singlePost['slug'] : ($singlePost['slug_ar'] ?? null);
    if ($altSlug) {
        $extraHead .= sprintf('<link rel="alternate" hreflang="%s" href="%s">' . "\n",
            $isAr ? 'en' : 'ar',
            $baseUrl . ($isAr ? '' : '/ar') . '/blog/' . $altSlug
        );
    }
    // r153: the owner body for the @id the BlogPosting now references bare, so
    // author and publisher resolve in this document rather than across pages.
    $extraHead .= '<script type="application/ld+json">'
        . json_encode(['@context' => 'https://schema.org'] + Seo::organizationNode(),
                      JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        . '</script>' . "\n";
    $extraHead .= '<script type="application/ld+json">' . json_encode($articleLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
    $extraHead .= '<script type="application/ld+json">' . json_encode($breadcrumbLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
} else {
    $blogLd = [
        '@context' => 'https://schema.org',
        '@type' => 'Blog',
        'name' => 'Cardify Blog',
        'description' => 'Business card tips, networking advice, and industry guides for Omani professionals.',
        'url' => 'https://cardify.om/blog',
        'publisher' => [
            '@type' => 'Organization',
            'name' => 'Cardify',
            'url' => 'https://cardify.om/',
        ],
    ];
    $extraHead .= '<script type="application/ld+json">' . json_encode($blogLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
}

require_once INCLUDES_DIR . '/ui-header.php';
?>

<div class="min-h-screen bg-gray-50">
    <?php if ($singlePost): ?>
    <!-- Single Post View -->
    <div class="bg-white pt-28 pb-12">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <a href="<?= htmlspecialchars(ArTwins::navLink('blog', '/', $isAr)) ?>" class="inline-flex items-center gap-2 text-blue-600 hover:text-blue-700 mb-4">
                <i class="fa-solid fa-arrow-left"></i>
                <?= htmlspecialchars(t('blog.back_to_blog')) ?>
            </a>
            <h1 class="text-4xl font-bold text-gray-900 mb-4"><?php echo htmlspecialchars($L($singlePost, 'title')); ?></h1>
            <?php
                // Estimated read time: ~200 words/min average
                $wordCount = str_word_count(strip_tags($L($singlePost, 'content')));
                $readMinutes = max(1, (int) ceil($wordCount / 200));
            ?>
            <div class="flex items-center gap-3 text-gray-500 flex-wrap">
                <?php if ($singlePost['author_name']): ?>
                <span class="inline-flex items-center gap-2">
                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-blue-100 text-blue-700 font-bold text-sm">
                        <?= strtoupper(substr($singlePost['author_name'], 0, 1)) ?>
                    </span>
                    <span><?= htmlspecialchars(t('blog.by_author', ['name' => $singlePost['author_name']])) ?></span>
                </span>
                <span class="text-gray-300">•</span>
                <?php endif; ?>
                <time datetime="<?= date('c', strtotime($singlePost['published_at'] ?? $singlePost['created_at'])) ?>"><?= htmlspecialchars(I18n::formatDate(strtotime($singlePost['published_at'] ?? $singlePost['created_at']))) ?></time>
                <span class="text-gray-300">•</span>
                <span class="inline-flex items-center gap-1"><i class="fa-regular fa-clock text-xs"></i> <?= htmlspecialchars(t('blog.min_read', ['n' => $readMinutes])) ?></span>
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
            <?php echo $L($singlePost, 'content'); ?>
        </article>

        <!-- Social Sharing -->
        <?php
        $shareUrl = urlencode($blogBase . '/' . $displaySlug);
        $shareTitle = urlencode($L($singlePost, 'title'));
        ?>
        <div class="mt-8 bg-white rounded-xl shadow-sm p-6 flex flex-col sm:flex-row items-center justify-between gap-4">
            <span class="text-gray-600 font-medium"><?= htmlspecialchars(t('blog.share_article')) ?></span>
            <div class="flex items-center gap-3">
                <a href="https://api.whatsapp.com/send?text=<?= $shareTitle ?>%20<?= $shareUrl ?>" target="_blank" rel="noopener"
                   class="w-10 h-10 rounded-full bg-green-500 flex items-center justify-center hover:bg-green-600 transition-colors">
                    <i class="fa-brands fa-whatsapp text-lg" style="color:#fff"></i>
                </a>
                <a href="https://twitter.com/intent/tweet?text=<?= $shareTitle ?>&url=<?= $shareUrl ?>" target="_blank" rel="noopener"
                   class="w-10 h-10 rounded-full flex items-center justify-center hover:bg-gray-800 transition-colors" style="background-color:#000">
                    <svg viewBox="0 0 512 512" class="w-4 h-4" fill="#fff"><path d="M389.2 48h70.6L305.6 224.2 487 464H345L233.7 318.6 106.5 464H35.8L200.7 275.5 26.8 48H172.4L272.9 180.9 389.2 48zM364.4 421.8h39.1L151.1 88h-42L364.4 421.8z"/></svg>
                </a>
                <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?= $shareUrl ?>" target="_blank" rel="noopener"
                   class="w-10 h-10 rounded-full bg-blue-700 flex items-center justify-center hover:bg-blue-800 transition-colors">
                    <i class="fa-brands fa-linkedin-in text-lg" style="color:#fff"></i>
                </a>
                <button onclick="navigator.clipboard.writeText('<?= $blogBase ?>/<?= $displaySlug ?>').then(()=>{this.innerHTML='<i class=\'fa-solid fa-check\' style=\'color:#374151\'></i>';setTimeout(()=>{this.innerHTML='<i class=\'fa-solid fa-link\' style=\'color:#374151\'></i>'},2000)})"
                   class="w-10 h-10 rounded-full bg-gray-200 flex items-center justify-center hover:bg-gray-300 transition-colors">
                    <i class="fa-solid fa-link" style="color:#374151"></i>
                </button>
            </div>
        </div>

        <!-- CTA Banner -->
        <div class="mt-8 bg-gradient-to-r from-blue-600 to-blue-800 rounded-xl p-8 text-center text-white">
            <h3 class="text-2xl font-bold mb-2"><?= htmlspecialchars(t('blog.cta_h3')) ?></h3>
            <p class="text-blue-100 mb-6"><?= htmlspecialchars(t('blog.cta_body', ['companies' => number_format(PlatformStats::all()['issuing'])])) ?></p>
            <a href="<?= getBasePath() ?>intro" class="inline-block bg-white text-blue-700 font-semibold px-8 py-3 rounded-lg hover:bg-blue-50 transition-colors">
                <?= htmlspecialchars(t('blog.cta_button')) ?>
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
            <h3 class="text-xl font-bold text-gray-900 mb-6"><?= htmlspecialchars(t('blog.related_articles')) ?></h3>
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
            <a href="<?= htmlspecialchars(ArTwins::navLink('blog', '/', $isAr)) ?>" class="inline-flex items-center gap-2 text-blue-600 hover:text-blue-700 font-medium">
                <i class="fa-solid fa-arrow-left"></i>
                <?= htmlspecialchars(t('blog.back_to_blog')) ?>
            </a>
        </div>
    </div>

    <?php /* BlogPosting JSON-LD emitted via $extraHead at top of page */ ?>

    <?php else: ?>
    <!-- Blog Listing -->
    <div class="bg-white pt-28 pb-12">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <h1 class="text-4xl font-bold text-gray-900 mb-3"><?= htmlspecialchars(t('blog.h1')) ?></h1>
            <p class="text-gray-500 text-lg max-w-2xl mx-auto">
                <?= htmlspecialchars(t('blog.hero_sub', ['brand' => $brandName])) ?>
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
            <h2 class="text-2xl font-bold text-gray-900 mb-4"><?= htmlspecialchars(t('blog.coming_soon_h2')) ?></h2>
            <p class="text-gray-600 max-w-2xl mx-auto">
                <?= htmlspecialchars(t('blog.coming_soon_body')) ?>
            </p>
        </div>
        <?php else: ?>
        
        <!-- Blog Posts Grid -->
        <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8 mb-12">
            <?php foreach ($posts as $index => $post): ?>
                <article class="bg-white rounded-xl shadow-sm overflow-hidden hover:shadow-md transition-shadow">
                    <!-- Post Image / Gradient Header -->
                    <?php
                        $cardTitle   = $L($post, 'title');
                        $cardExcerpt = $L($post, 'excerpt');
                        $cardSlug    = $isAr && !empty($post['slug_ar']) ? $post['slug_ar'] : $post['slug'];
                    ?>
                    <?php if (!empty($post['featured_image'])): ?>
                    <div class="h-40 overflow-hidden">
                        <img src="<?= getBasePath() . htmlspecialchars($post['featured_image']) ?>"
                             alt="<?= htmlspecialchars($cardTitle) ?>"
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
                        <h2 class="font-bold text-gray-900 mb-2 line-clamp-2">
                            <?php echo htmlspecialchars($cardTitle); ?>
                        </h2>
                        <?php if ($cardExcerpt): ?>
                        <p class="text-gray-600 text-sm line-clamp-3 mb-4">
                            <?php echo htmlspecialchars($cardExcerpt); ?>
                        </p>
                        <?php endif; ?>
                        <a href="<?= htmlspecialchars($blogBase) ?>/<?php echo urlencode($cardSlug); ?>"
                           class="text-blue-600 hover:text-blue-700 text-sm font-medium inline-flex items-center gap-1">
                            <?= htmlspecialchars(t('blog.read_more')) ?>
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
                <?= htmlspecialchars(t('blog.back_home')) ?>
            </a>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
