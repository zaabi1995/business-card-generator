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
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
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
        <loc><?= $baseUrl ?>/get-started</loc>
        <lastmod><?= $today ?></lastmod>
        <changefreq>monthly</changefreq>
        <priority>0.9</priority>
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

    <!-- Industry Landing Pages -->
    <url>
        <loc><?= $baseUrl ?>/industries/restaurants</loc>
        <lastmod><?= $today ?></lastmod>
        <changefreq>monthly</changefreq>
        <priority>0.7</priority>
    </url>
    <url>
        <loc><?= $baseUrl ?>/industries/construction</loc>
        <lastmod><?= $today ?></lastmod>
        <changefreq>monthly</changefreq>
        <priority>0.7</priority>
    </url>
    <url>
        <loc><?= $baseUrl ?>/industries/healthcare</loc>
        <lastmod><?= $today ?></lastmod>
        <changefreq>monthly</changefreq>
        <priority>0.7</priority>
    </url>
    <url>
        <loc><?= $baseUrl ?>/industries/real-estate</loc>
        <lastmod><?= $today ?></lastmod>
        <changefreq>monthly</changefreq>
        <priority>0.7</priority>
    </url>
    <url>
        <loc><?= $baseUrl ?>/industries/tourism</loc>
        <lastmod><?= $today ?></lastmod>
        <changefreq>monthly</changefreq>
        <priority>0.7</priority>
    </url>

<?php
// Blog posts (with Google Image sitemap data)
try {
    $db = Database::getInstance();
    $posts = $db->fetchAll(
        "SELECT slug, title, featured_image, updated_at
         FROM blog_posts
         WHERE status = 'published'
         ORDER BY updated_at DESC"
    );
    foreach ($posts as $post) {
        $lastmod = date('Y-m-d', strtotime($post['updated_at']));
        $url = $baseUrl . '/blog/' . htmlspecialchars($post['slug']);
        echo "    <url>\n";
        echo "        <loc>{$url}</loc>\n";
        echo "        <lastmod>{$lastmod}</lastmod>\n";
        echo "        <changefreq>weekly</changefreq>\n";
        echo "        <priority>0.7</priority>\n";
        if (!empty($post['featured_image'])) {
            $imgUrl = $baseUrl . '/' . ltrim(htmlspecialchars($post['featured_image']), '/');
            echo "        <image:image>\n";
            echo "            <image:loc>{$imgUrl}</image:loc>\n";
            echo "            <image:title>" . htmlspecialchars($post['title']) . "</image:title>\n";
            echo "        </image:image>\n";
        }
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

// --- Oman Business Index: flagship + tools + solutions + directory ---

// Flagship landing
echo "    <url>\n        <loc>{$baseUrl}/oman-business-index</loc>\n        <lastmod>{$today}</lastmod>\n        <changefreq>monthly</changefreq>\n        <priority>0.9</priority>\n    </url>\n";
echo "    <url>\n        <loc>{$baseUrl}/ar/oman-business-index</loc>\n        <lastmod>{$today}</lastmod>\n        <changefreq>monthly</changefreq>\n        <priority>0.8</priority>\n    </url>\n";

// Tools hub + individual tools
$tools = ['vcard-qr-generator', 'email-signature-generator', 'whatsapp-qr-generator', 'nfc-business-card-guide'];
echo "    <url>\n        <loc>{$baseUrl}/tools</loc>\n        <lastmod>{$today}</lastmod>\n        <changefreq>monthly</changefreq>\n        <priority>0.8</priority>\n    </url>\n";
foreach ($tools as $t) {
    echo "    <url>\n        <loc>{$baseUrl}/tools/{$t}</loc>\n        <lastmod>{$today}</lastmod>\n        <changefreq>monthly</changefreq>\n        <priority>0.8</priority>\n    </url>\n";
}

// Solution pages hub + individual
$solutions = [
    'digital-business-cards-oman-sales-teams',
    'bilingual-arabic-english-business-cards',
    'qr-code-menu-muscat-restaurants',
    'business-cards-for-ramadan-networking',
    'nfc-business-cards-oman-executives',
    'digital-business-cards-oil-gas-oman',
    'business-cards-omani-law-firms',
    'digital-cards-oman-real-estate-agents',
    'business-cards-muscat-doctors-clinics',
    'business-cards-oman-construction-companies',
    'digital-business-cards-sohar-industrial-port',
    'salalah-tourism-business-cards',
    'business-cards-oman-bank-employees',
    'business-cards-for-oman-trade-fairs',
    'digital-business-cards-oman-hotels',
    'business-cards-oman-government-employees',
    'business-cards-oman-freelancers-consultants',
    'business-cards-oman-startups',
    'business-cards-oman-omanisation',
    'business-cards-duqm-free-zone',
];
echo "    <url>\n        <loc>{$baseUrl}/solutions</loc>\n        <lastmod>{$today}</lastmod>\n        <changefreq>monthly</changefreq>\n        <priority>0.8</priority>\n    </url>\n";
foreach ($solutions as $s) {
    echo "    <url>\n        <loc>{$baseUrl}/solutions/{$s}</loc>\n        <lastmod>{$today}</lastmod>\n        <changefreq>monthly</changefreq>\n        <priority>0.7</priority>\n    </url>\n";
}

// /companies index (EN + AR)
echo "    <url>\n        <loc>{$baseUrl}/companies</loc>\n        <lastmod>{$today}</lastmod>\n        <changefreq>weekly</changefreq>\n        <priority>0.9</priority>\n    </url>\n";
echo "    <url>\n        <loc>{$baseUrl}/ar/companies</loc>\n        <lastmod>{$today}</lastmod>\n        <changefreq>weekly</changefreq>\n        <priority>0.8</priority>\n    </url>\n";

// Sector hubs (EN + AR)
try {
    $sectors = $db->fetchAll("SELECT DISTINCT sector FROM om_companies ORDER BY sector ASC");
    foreach ($sectors as $s) {
        $slug = htmlspecialchars($s['sector']);
        echo "    <url>\n        <loc>{$baseUrl}/companies/sector/{$slug}</loc>\n        <lastmod>{$today}</lastmod>\n        <changefreq>weekly</changefreq>\n        <priority>0.7</priority>\n    </url>\n";
        echo "    <url>\n        <loc>{$baseUrl}/ar/companies/sector/{$slug}</loc>\n        <lastmod>{$today}</lastmod>\n        <changefreq>weekly</changefreq>\n        <priority>0.6</priority>\n    </url>\n";
    }
} catch (Exception $e) {}

// Wilayat hubs (EN + AR)
try {
    $wilayats = $db->fetchAll("SELECT DISTINCT wilayat FROM om_companies ORDER BY wilayat ASC");
    foreach ($wilayats as $w) {
        $slug = htmlspecialchars($w['wilayat']);
        echo "    <url>\n        <loc>{$baseUrl}/companies/wilayat/{$slug}</loc>\n        <lastmod>{$today}</lastmod>\n        <changefreq>weekly</changefreq>\n        <priority>0.7</priority>\n    </url>\n";
        echo "    <url>\n        <loc>{$baseUrl}/ar/companies/wilayat/{$slug}</loc>\n        <lastmod>{$today}</lastmod>\n        <changefreq>weekly</changefreq>\n        <priority>0.6</priority>\n    </url>\n";
    }
} catch (Exception $e) {}

// Individual company pages (EN + AR for each)
try {
    $companies = $db->fetchAll("SELECT slug, updated_at FROM om_companies ORDER BY size_bucket ASC, id ASC");
    foreach ($companies as $c) {
        $slug = htmlspecialchars($c['slug']);
        $lastmod = date('Y-m-d', strtotime($c['updated_at']));
        echo "    <url>\n        <loc>{$baseUrl}/companies/{$slug}</loc>\n        <lastmod>{$lastmod}</lastmod>\n        <changefreq>monthly</changefreq>\n        <priority>0.5</priority>\n    </url>\n";
        echo "    <url>\n        <loc>{$baseUrl}/ar/companies/{$slug}</loc>\n        <lastmod>{$lastmod}</lastmod>\n        <changefreq>monthly</changefreq>\n        <priority>0.4</priority>\n    </url>\n";
    }
} catch (Exception $e) {}

// Digital cards (customer-specific) are still excluded — they're noindexed.
?>
</urlset>
