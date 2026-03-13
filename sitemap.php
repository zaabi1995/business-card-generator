<?php
/**
 * Dynamic XML Sitemap Generator for Cardify.om
 * Outputs a valid XML sitemap with static pages, blog posts, careers, companies, and digital cards.
 */

header('Content-Type: application/xml; charset=UTF-8');

require_once __DIR__ . '/config.php';

$baseUrl = 'https://cardify.om';
$today = date('Y-m-d');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <!-- Static Pages -->
    <url>
        <loc><?= $baseUrl ?>/</loc>
        <lastmod><?= $today ?></lastmod>
        <changefreq>weekly</changefreq>
        <priority>1.0</priority>
    </url>
    <url>
        <loc><?= $baseUrl ?>/about</loc>
        <lastmod><?= $today ?></lastmod>
        <changefreq>monthly</changefreq>
        <priority>0.8</priority>
    </url>
    <url>
        <loc><?= $baseUrl ?>/blog</loc>
        <lastmod><?= $today ?></lastmod>
        <changefreq>weekly</changefreq>
        <priority>0.9</priority>
    </url>
    <url>
        <loc><?= $baseUrl ?>/careers</loc>
        <lastmod><?= $today ?></lastmod>
        <changefreq>weekly</changefreq>
        <priority>0.8</priority>
    </url>
    <url>
        <loc><?= $baseUrl ?>/faq</loc>
        <lastmod><?= $today ?></lastmod>
        <changefreq>monthly</changefreq>
        <priority>0.7</priority>
    </url>
    <url>
        <loc><?= $baseUrl ?>/contact</loc>
        <lastmod><?= $today ?></lastmod>
        <changefreq>monthly</changefreq>
        <priority>0.8</priority>
    </url>
    <url>
        <loc><?= $baseUrl ?>/intro</loc>
        <lastmod><?= $today ?></lastmod>
        <changefreq>monthly</changefreq>
        <priority>0.8</priority>
    </url>
    <url>
        <loc><?= $baseUrl ?>/terms</loc>
        <lastmod><?= $today ?></lastmod>
        <changefreq>yearly</changefreq>
        <priority>0.8</priority>
    </url>
    <url>
        <loc><?= $baseUrl ?>/privacy</loc>
        <lastmod><?= $today ?></lastmod>
        <changefreq>yearly</changefreq>
        <priority>0.8</priority>
    </url>
    <url>
        <loc><?= $baseUrl ?>/security</loc>
        <lastmod><?= $today ?></lastmod>
        <changefreq>yearly</changefreq>
        <priority>0.8</priority>
    </url>
    <url>
        <loc><?= $baseUrl ?>/cookies</loc>
        <lastmod><?= $today ?></lastmod>
        <changefreq>yearly</changefreq>
        <priority>0.8</priority>
    </url>

<?php
// Blog posts
try {
    $db = Database::getInstance();
    $posts = $db->fetchAll("SELECT slug, updated_at FROM blog_posts WHERE status = 'published' ORDER BY updated_at DESC");
    foreach ($posts as $post) {
        $lastmod = date('Y-m-d', strtotime($post['updated_at']));
        echo "    <url>\n";
        echo "        <loc>{$baseUrl}/blog/" . htmlspecialchars($post['slug']) . "</loc>\n";
        echo "        <lastmod>{$lastmod}</lastmod>\n";
        echo "        <changefreq>weekly</changefreq>\n";
        echo "        <priority>0.6</priority>\n";
        echo "    </url>\n";
    }
} catch (Exception $e) {
    // Blog posts table may not exist yet
}

// Career listings
try {
    $careers = $db->fetchAll("SELECT slug, updated_at FROM career_listings WHERE status = 'open' ORDER BY updated_at DESC");
    foreach ($careers as $career) {
        $lastmod = date('Y-m-d', strtotime($career['updated_at']));
        echo "    <url>\n";
        echo "        <loc>{$baseUrl}/careers/" . htmlspecialchars($career['slug']) . "</loc>\n";
        echo "        <lastmod>{$lastmod}</lastmod>\n";
        echo "        <changefreq>weekly</changefreq>\n";
        echo "        <priority>0.6</priority>\n";
        echo "    </url>\n";
    }
} catch (Exception $e) {
    // Career listings table may not exist yet
}

// Active companies
try {
    $companies = $db->fetchAll("SELECT slug, updated_at FROM companies WHERE status = 'active' ORDER BY updated_at DESC");
    foreach ($companies as $company) {
        $lastmod = date('Y-m-d', strtotime($company['updated_at']));
        echo "    <url>\n";
        echo "        <loc>{$baseUrl}/" . htmlspecialchars($company['slug']) . "/</loc>\n";
        echo "        <lastmod>{$lastmod}</lastmod>\n";
        echo "        <changefreq>weekly</changefreq>\n";
        echo "        <priority>0.7</priority>\n";
        echo "    </url>\n";
    }
} catch (Exception $e) {
    // Companies table may not exist yet
}

// Digital cards
try {
    $cards = $db->fetchAll("
        SELECT gc.updated_at, e.id AS employee_id, c.slug AS company_slug
        FROM generated_cards gc
        JOIN employees e ON gc.employee_id = e.id
        JOIN companies c ON e.company_id = c.id
        WHERE c.status = 'active'
        ORDER BY gc.updated_at DESC
    ");
    foreach ($cards as $card) {
        $lastmod = date('Y-m-d', strtotime($card['updated_at']));
        echo "    <url>\n";
        echo "        <loc>{$baseUrl}/" . htmlspecialchars($card['company_slug']) . "/card/" . htmlspecialchars($card['employee_id']) . "</loc>\n";
        echo "        <lastmod>{$lastmod}</lastmod>\n";
        echo "        <changefreq>monthly</changefreq>\n";
        echo "        <priority>0.4</priority>\n";
        echo "    </url>\n";
    }
} catch (Exception $e) {
    // Generated cards table may not exist yet
}
?>
</urlset>
