<?php
/**
 * Cardify Blog RSS Feed
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/rss+xml; charset=UTF-8');

$db = Database::getInstance();
$posts = [];
if ($db->tableExists('blog_posts')) {
    $posts = $db->fetchAll(
        "SELECT title, slug, excerpt, content, author_name, published_at, created_at, updated_at
         FROM blog_posts WHERE status = 'published'
         ORDER BY published_at DESC, created_at DESC LIMIT 20"
    );
}

echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
    <title>Cardify Blog — Business Cards in Oman</title>
    <link>https://cardify.om/blog</link>
    <description>Tips on business card design, team management, and printing for Omani businesses.</description>
    <language>en</language>
    <lastBuildDate><?= date('r') ?></lastBuildDate>
    <atom:link href="https://cardify.om/feed" rel="self" type="application/rss+xml"/>
    <?php foreach ($posts as $post):
        $pubDate = date('r', strtotime($post['published_at'] ?? $post['created_at']));
        $desc = htmlspecialchars($post['excerpt'] ?? substr(strip_tags($post['content']), 0, 300));
    ?>
    <item>
        <title><?= htmlspecialchars($post['title']) ?></title>
        <link>https://cardify.om/blog/<?= htmlspecialchars($post['slug']) ?></link>
        <guid>https://cardify.om/blog/<?= htmlspecialchars($post['slug']) ?></guid>
        <pubDate><?= $pubDate ?></pubDate>
        <description><?= $desc ?></description>
        <?php if ($post['author_name']): ?>
        <author>blog@cardify.om (<?= htmlspecialchars($post['author_name']) ?>)</author>
        <?php endif; ?>
    </item>
    <?php endforeach; ?>
</channel>
</rss>
