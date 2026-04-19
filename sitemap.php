<?php
/**
 * Cardify.om sitemap controller.
 *
 * Outputs either:
 *   - A sitemap index at /sitemap.xml (default)
 *   - One of the child sitemaps at /sitemap-{part}.xml (via .htaccess rewrite → sitemap.php?part=X)
 *
 * Splitting by topic helps Google prioritise crawling (the whole 4,974-URL
 * flat list was sitting at "Discovered — not indexed" indefinitely).
 */

header('Content-Type: application/xml; charset=UTF-8');

require_once __DIR__ . '/config.php';

$baseUrl = 'https://cardify.om';
$today   = date('Y-m-d');
$part    = (string) ($_GET['part'] ?? 'index');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";

/**
 * Escape a URL for XML.
 */
function smX($s) { return htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }

/**
 * Render a single <url> entry.
 */
function smUrl($loc, $lastmod, $changefreq = 'monthly', $priority = '0.5') {
    echo "    <url>\n";
    echo "        <loc>" . smX($loc) . "</loc>\n";
    echo "        <lastmod>{$lastmod}</lastmod>\n";
    echo "        <changefreq>{$changefreq}</changefreq>\n";
    echo "        <priority>{$priority}</priority>\n";
    echo "    </url>\n";
}

/**
 * Render a single <sitemap> entry in the index.
 */
function smChild($loc, $lastmod) {
    echo "    <sitemap>\n";
    echo "        <loc>" . smX($loc) . "</loc>\n";
    echo "        <lastmod>{$lastmod}</lastmod>\n";
    echo "    </sitemap>\n";
}

// --- Sitemap index -----------------------------------------------------
if ($part === 'index') {
    echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    smChild("{$baseUrl}/sitemap-static.xml",       $today);
    smChild("{$baseUrl}/sitemap-tools.xml",        $today);
    smChild("{$baseUrl}/sitemap-solutions.xml",    $today);
    smChild("{$baseUrl}/sitemap-directory.xml",    $today);
    smChild("{$baseUrl}/sitemap-companies.xml",    $today);
    smChild("{$baseUrl}/sitemap-companies-ar.xml", $today);
    smChild("{$baseUrl}/sitemap-blog.xml",         $today);
    smChild("{$baseUrl}/sitemap-logos.xml",        $today);
    echo '</sitemapindex>' . "\n";
    exit;
}

// --- Child sitemaps ----------------------------------------------------
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

try {
    $db = Database::getInstance();
} catch (Throwable $e) {
    $db = null;
}

if ($part === 'static') {
    // Top-level product, legal, marketing pages + industry landings.
    $staticPages = [
        ['/',             'weekly',  '1.0'],
        ['/about',        'monthly', '0.8'],
        ['/blog',         'weekly',  '0.9'],
        ['/careers',      'weekly',  '0.8'],
        ['/faq',          'monthly', '0.7'],
        ['/get-started',  'monthly', '0.9'],
        ['/contact',      'monthly', '0.8'],
        ['/intro',        'monthly', '0.8'],
        ['/terms',        'yearly',  '0.4'],
        ['/privacy',      'yearly',  '0.4'],
        ['/security',     'yearly',  '0.4'],
        ['/cookies',      'yearly',  '0.4'],
        ['/print-shops',  'weekly',  '0.8'],
        ['/industries/restaurants',  'monthly', '0.7'],
        ['/industries/construction', 'monthly', '0.7'],
        ['/industries/healthcare',   'monthly', '0.7'],
        ['/industries/real-estate',  'monthly', '0.7'],
        ['/industries/tourism',      'monthly', '0.7'],
        ['/industries/banking',      'monthly', '0.75'],
        ['/industries/logistics',    'monthly', '0.75'],
        ['/industries/oil-gas',      'monthly', '0.75'],
        ['/industries/government',   'monthly', '0.75'],
    ];
    foreach ($staticPages as [$path, $freq, $prio]) {
        smUrl($baseUrl . $path, $today, $freq, $prio);
    }

} elseif ($part === 'tools') {
    smUrl("{$baseUrl}/tools", $today, 'monthly', '0.9');
    $tools = [
        'vcard-qr-generator',
        'email-signature-generator',
        'whatsapp-qr-generator',
        'nfc-business-card-guide',
    ];
    foreach ($tools as $t) {
        smUrl("{$baseUrl}/tools/{$t}", $today, 'monthly', '0.8');
    }

} elseif ($part === 'solutions') {
    smUrl("{$baseUrl}/solutions", $today, 'monthly', '0.9');
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
    foreach ($solutions as $s) {
        smUrl("{$baseUrl}/solutions/{$s}", $today, 'monthly', '0.7');
    }

} elseif ($part === 'directory') {
    // Flagship + directory hubs (indexes, sector hubs, wilayat hubs) — NOT individual companies.
    smUrl("{$baseUrl}/oman-business-index",    $today, 'monthly', '0.9');
    smUrl("{$baseUrl}/ar/oman-business-index", $today, 'monthly', '0.8');
    smUrl("{$baseUrl}/gcc-business-index",     $today, 'weekly',  '0.95');
    // /ar/gcc-business-index not yet published — English-only flagship for now.
    smUrl("{$baseUrl}/companies",              $today, 'weekly',  '0.9');
    smUrl("{$baseUrl}/ar/companies",           $today, 'weekly',  '0.8');

    if ($db) {
        try {
            foreach ($db->fetchAll("SELECT DISTINCT sector FROM om_companies ORDER BY sector ASC") as $s) {
                $slug = $s['sector'];
                smUrl("{$baseUrl}/companies/sector/{$slug}",    $today, 'weekly', '0.7');
                smUrl("{$baseUrl}/ar/companies/sector/{$slug}", $today, 'weekly', '0.6');
            }
            foreach ($db->fetchAll("SELECT DISTINCT wilayat FROM om_companies ORDER BY wilayat ASC") as $w) {
                $slug = $w['wilayat'];
                smUrl("{$baseUrl}/companies/wilayat/{$slug}",    $today, 'weekly', '0.7');
                smUrl("{$baseUrl}/ar/companies/wilayat/{$slug}", $today, 'weekly', '0.6');
            }
        } catch (Throwable $e) { /* table may not exist */ }
    }

} elseif ($part === 'companies') {
    // English company profiles only — each sitemap stays ≤ 50k URLs.
    if ($db) {
        try {
            $rows = $db->fetchAll("SELECT slug, updated_at FROM om_companies ORDER BY size_bucket ASC, id ASC");
            foreach ($rows as $c) {
                $lastmod = date('Y-m-d', strtotime($c['updated_at']));
                smUrl("{$baseUrl}/companies/" . $c['slug'], $lastmod, 'monthly', '0.5');
            }
        } catch (Throwable $e) { /* table may not exist */ }
    }

} elseif ($part === 'companies-ar') {
    // Arabic company profiles only.
    if ($db) {
        try {
            $rows = $db->fetchAll("SELECT slug, updated_at FROM om_companies ORDER BY size_bucket ASC, id ASC");
            foreach ($rows as $c) {
                $lastmod = date('Y-m-d', strtotime($c['updated_at']));
                smUrl("{$baseUrl}/ar/companies/" . $c['slug'], $lastmod, 'monthly', '0.4');
            }
        } catch (Throwable $e) { /* table may not exist */ }
    }

} elseif ($part === 'blog') {
    // Blog posts (with image metadata) + career listings.
    if ($db) {
        try {
            $posts = $db->fetchAll(
                "SELECT slug, title, featured_image, updated_at
                   FROM blog_posts
                  WHERE status = 'published'
                  ORDER BY updated_at DESC"
            );
            foreach ($posts as $post) {
                $lastmod = date('Y-m-d', strtotime($post['updated_at']));
                $url = $baseUrl . '/blog/' . $post['slug'];
                echo "    <url>\n";
                echo "        <loc>" . smX($url) . "</loc>\n";
                echo "        <lastmod>{$lastmod}</lastmod>\n";
                echo "        <changefreq>weekly</changefreq>\n";
                echo "        <priority>0.7</priority>\n";
                if (!empty($post['featured_image'])) {
                    $imgUrl = $baseUrl . '/' . ltrim($post['featured_image'], '/');
                    echo "        <image:image>\n";
                    echo "            <image:loc>" . smX($imgUrl) . "</image:loc>\n";
                    echo "            <image:title>" . smX($post['title']) . "</image:title>\n";
                    echo "        </image:image>\n";
                }
                echo "    </url>\n";
            }
        } catch (Throwable $e) { /* blog_posts may not exist */ }
        try {
            $careers = $db->fetchAll("SELECT slug, updated_at FROM career_listings WHERE status = 'open' ORDER BY updated_at DESC");
            foreach ($careers as $c) {
                $lastmod = date('Y-m-d', strtotime($c['updated_at']));
                smUrl("{$baseUrl}/careers/" . $c['slug'], $lastmod, 'weekly', '0.6');
            }
        } catch (Throwable $e) { /* career_listings may not exist */ }
    }

} elseif ($part === 'logos') {
    // Omani Logo Library — hub + terms/press + 23 sector pages + image entries
    // for every indexed/verified logo (Google Images surfaces them from
    // <image:image> blocks, not just <img> tags on a page).
    smUrl("{$baseUrl}/logos",       $today, 'daily',   '0.9');
    smUrl("{$baseUrl}/logos/press", $today, 'monthly', '0.5');
    smUrl("{$baseUrl}/logos/terms", $today, 'yearly',  '0.3');

    if ($db) {
        try {
            $sectors = $db->fetchAll(
                "SELECT DISTINCT sector FROM om_companies
                  WHERE logo_status IN ('indexed','verified')
                  ORDER BY sector ASC"
            );
            foreach ($sectors as $s) {
                smUrl("{$baseUrl}/logos/{$s['sector']}", $today, 'weekly', '0.7');
            }
        } catch (Throwable $e) { /* om_companies may lack logo_status until migration runs */ }

        // Per-logo entries with Google Image sitemap extensions.
        // /companies/{slug} is the canonical logo page; the image extension
        // tells Google the logo URL + title + license so Image Search can
        // surface it.
        try {
            $logos = $db->fetchAll(
                "SELECT slug, name_en, name_ar, logo_svg_path, logo_png_path,
                        logo_png_2048_path, logo_webp_path, logo_updated_at, logo_status
                   FROM om_companies
                  WHERE logo_status IN ('indexed','verified')
                  ORDER BY logo_updated_at DESC"
            );
            $licenseUrl = "{$baseUrl}/logos/terms";
            foreach ($logos as $l) {
                // Pick the highest-fidelity public URL for the image loc.
                $rel = $l['logo_svg_path']
                    ?: $l['logo_png_2048_path']
                    ?: $l['logo_png_path']
                    ?: $l['logo_webp_path'];
                if (!$rel) continue;
                $imgUrl = $baseUrl . $rel;
                $pageUrl = "{$baseUrl}/companies/{$l['slug']}";
                $lastmod = $l['logo_updated_at']
                    ? date('Y-m-d', strtotime($l['logo_updated_at']))
                    : $today;
                $caption = trim(($l['name_en'] ?? '') . ' logo');
                $title   = trim(($l['name_en'] ?? '') . ' logo — Omani Logo Library');
                echo "    <url>\n";
                echo "        <loc>" . smX($pageUrl) . "</loc>\n";
                echo "        <lastmod>{$lastmod}</lastmod>\n";
                echo "        <changefreq>monthly</changefreq>\n";
                echo "        <priority>0.6</priority>\n";
                echo "        <image:image>\n";
                echo "            <image:loc>" . smX($imgUrl) . "</image:loc>\n";
                echo "            <image:title>" . smX($title) . "</image:title>\n";
                echo "            <image:caption>" . smX($caption) . "</image:caption>\n";
                echo "            <image:license>" . smX($licenseUrl) . "</image:license>\n";
                echo "        </image:image>\n";
                echo "    </url>\n";
            }
        } catch (Throwable $e) { /* fields may be missing */ }
    }

} else {
    // Unknown part — empty urlset (Google will just see no URLs; still valid XML).
}

echo '</urlset>' . "\n";
