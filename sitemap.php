<?php
/**
 * Cardify.om sitemap controller.
 *
 * Outputs either:
 *   - A sitemap index at /sitemap.xml (default)
 *   - One of the child sitemaps at /sitemap-{part}.xml (via .htaccess rewrite → sitemap.php?part=X)
 *
 * Splitting by topic helps Google prioritise crawling (the whole 4,974-URL
 * flat list was sitting at "Discovered, not indexed" indefinitely).
 */

header('Content-Type: application/xml; charset=UTF-8');

require_once __DIR__ . '/config.php';

$baseUrl = 'https://cardify.om';
$part    = (string) ($_GET['part'] ?? 'index');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";

/**
 * Escape a URL for XML.
 */
function smX($s) { return htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }

require_once __DIR__ . '/includes/ArTwins.php';
require_once __DIR__ . '/includes/CompanyIndex.php';

/**
 * Render a single <url> entry.
 *
 * When the path is in the ArTwins map BOTH twins are emitted, each carrying
 * the same xhtml:link alternate set as the page head. A caller therefore
 * cannot list one side and forget the other, which is how /pricing shipped
 * with no /ar/pricing entry while /ar/oman-business-index was hand-listed on
 * its own line, and the sitemap can no longer contradict the tags.
 *
 * Repeat <loc>s are dropped: the directory section already calls this once
 * per twin, and a duplicated <loc> inside one urlset is an invalid sitemap.
 */
function smUrl($loc, $lastmod, $changefreq = 'monthly', $priority = '0.5', $imageXml = '') {
    static $seen = [];

    // The image extension is attached to the EN declaration only. Repeating it
    // on the Arabic twin would be a second claim about the same asset, and one
    // claim per fact is the whole point of this round's change.
    $emit = function ($url, $imageXml = '') use ($lastmod, $changefreq, $priority, &$seen) {
        if (isset($seen[$url])) return;
        $seen[$url] = true;
        echo "    <url>\n";
        echo "        <loc>" . smX($url) . "</loc>\n";
        echo "        <lastmod>{$lastmod}</lastmod>\n";
        echo "        <changefreq>{$changefreq}</changefreq>\n";
        echo "        <priority>{$priority}</priority>\n";
        // ONE oracle, the same one the page head asks. ar() was asked here and
        // covers only the enumerated PATHS, so every /logos/{sector} shipped
        // with no alternate in the sitemap while its own page declared an
        // Arabic twin, and the Arabic twin was never listed at all (r80).
        // A monolingual URL emits no xhtml:link at all: the page's honest
        // en+x-default self-pair is already the whole claim, and repeating it
        // here would add a second declaration that can drift.
        $alts = ArTwins::alternates($url);
        if (count($alts) === 3) {
            foreach ($alts as [$hrefLang, $hrefUrl]) {
                echo "        <xhtml:link rel=\"alternate\" hreflang=\"" . $hrefLang
                   . "\" href=\"" . smX($hrefUrl) . "\" />\n";
            }
        }
        if ($imageXml !== '') echo $imageXml;
        echo "    </url>\n";
    };

    $ar = ArTwins::arPath($loc);
    if ($ar !== null) {
        $emit(ArTwins::en($loc), $imageXml);
        $emit(ArTwins::SITE . $ar);
        return;
    }
    $emit($loc, $imageXml);
}

/**
 * r82 / llm81-1. The honest lastmod for a route: the mtime of the file that
 * renders it.
 *
 * Every static/tools/solutions/directory/logos/printshops URL was stamped
 * $today = date('Y-m-d'), so 192 URLs and all 9 index children told crawlers
 * they had changed TODAY, on every request, forever. Measured 2026-08-06
 * before this change: static 50/50, directory 74/74, logos 38/38,
 * solutions 22/22, tools 6/6, printshops 2/2. The DB-driven parts already do
 * this correctly (companies 0/5004 and blog 0/36 stamped today, because they
 * read updated_at), which is why this is a gap and not a design.
 *
 * Same family as llm66-1 (a page publishing date('c') as dateModified) and
 * llm71-3 (a sitemap re-dating a URL a regen did not change). A freshness
 * claim nothing can falsify is worth less than no claim.
 *
 * Falls back to sitemap.php's own mtime, which is honest rather than
 * convenient: if no file renders the route, the newest thing that can have
 * changed the claim is the generator that emits it.
 */
function smRouteDate($path) {
    static $cache = [];
    $path = '/' . ltrim(parse_url($path, PHP_URL_PATH) ?? '', '/');
    if (isset($cache[$path])) return $cache[$path];
    $rel = trim($path, '/');
    // Routes whose renderer is not <path>.php. Read out of .htaccess, not
    // guessed: without these seven entries /press-kit and the six /gcc/*
    // pages resolved to nothing and took the fallback SILENTLY, which is the
    // same shape as the defect this function exists to fix. A route that
    // cannot find its renderer should be a short list somebody has to edit,
    // not a date nobody can question.
    static $RENDERER = [
        'press-kit'         => 'press.php',
        'gcc/saudi-arabia'  => 'gcc/country.php',
        'gcc/uae'           => 'gcc/country.php',
        'gcc/qatar'         => 'gcc/country.php',
        'gcc/bahrain'       => 'gcc/country.php',
        'gcc/kuwait'        => 'gcc/country.php',
        'gcc/oman'          => 'gcc/country.php',
    ];
    $roots = [];
    if (isset($RENDERER[$rel])) $roots[] = __DIR__ . '/' . $RENDERER[$rel];
    $roots[] = __DIR__ . '/' . ($rel === '' ? 'index.php' : $rel . '.php');
    $roots[] = __DIR__ . '/' . ($rel === '' ? 'index.php' : $rel . '/index.php');
    $t = 0;
    foreach ($roots as $f) {
        if (is_file($f)) { $t = (int) filemtime($f); break; }
    }
    if ($t === 0) $t = (int) filemtime(__FILE__);
    return $cache[$path] = date('Y-m-d', $t);
}

/**
 * r82 / llm81-1. A child sitemap's lastmod is the newest lastmod it contains.
 *
 * Computed, not stamped. For the DB-backed children that is a MAX(updated_at)
 * the child's own rows already agree with; for the file-backed ones it is the
 * newest mtime among the files that render them. companies-ar is deliberately
 * empty (see its branch below), so the only thing that can change it is this
 * file.
 */
function smChildDate($part, $db) {
    $newest = function (array $globs) {
        $t = (int) filemtime(__FILE__);
        foreach ($globs as $g) {
            foreach (glob(__DIR__ . '/' . $g) ?: [] as $f) {
                $t = max($t, (int) filemtime($f));
            }
        }
        return date('Y-m-d', $t);
    };
    $maxCol = function ($table, $col, $where = '') use ($db) {
        if (!$db) return null;
        try {
            $r = $db->fetchOne("SELECT MAX({$col}) AS m FROM {$table} {$where}");
            return ($r && $r['m']) ? date('Y-m-d', strtotime($r['m'])) : null;
        } catch (Throwable $e) { return null; }
    };
    switch ($part) {
        case 'companies':
            return $maxCol('om_companies', 'updated_at') ?? smRouteDate('/companies');
        case 'blog':
            return $maxCol('blog_posts', 'updated_at', "WHERE status = 'published'")
                   ?? smRouteDate('/blog');
        case 'companies-ar':
            return date('Y-m-d', (int) filemtime(__FILE__));
        case 'static':
            return $newest(['*.php', 'gcc/*.php', 'industries/*.php']);
        case 'tools':      return $newest(['tools.php', 'tools/*.php']);
        case 'solutions':  return $newest(['solutions.php', 'solutions/*.php']);
        case 'directory':  return $newest(['companies.php', 'directory/*.php']);
        case 'logos':      return $newest(['logos.php', 'logos/*.php']);
        case 'printshops': return $newest(['print-shops.php', 'printshop/*.php']);
    }
    return date('Y-m-d', (int) filemtime(__FILE__));
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

// r82: opened BEFORE the index branch, not after it. It used to sit below the
// child-sitemap dispatch, so smChildDate('companies', $db) in the index would
// have been handed an undefined $db, silently taken the null path and dated
// the companies and blog children off a file mtime instead of MAX(updated_at)
// — a degradation with no error and no way to see it in the output.
try {
    $db = Database::getInstance();
} catch (Throwable $e) {
    $db = null;
}

// --- Sitemap index -----------------------------------------------------
if ($part === 'index') {
    echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    smChild("{$baseUrl}/sitemap-static.xml", smChildDate('static', $db));
    smChild("{$baseUrl}/sitemap-tools.xml", smChildDate('tools', $db));
    smChild("{$baseUrl}/sitemap-solutions.xml", smChildDate('solutions', $db));
    smChild("{$baseUrl}/sitemap-directory.xml", smChildDate('directory', $db));
    smChild("{$baseUrl}/sitemap-companies.xml", smChildDate('companies', $db));
    // r145 / sitemap_index_gate: sitemap-companies-ar.xml is NOT listed here.
    // r80 emptied it for a good reason and then kept it in the index "so an
    // already-crawled child URL keeps answering valid XML rather than 404ing".
    // Those are two separate things and only the first needed the route: the
    // branch below still answers 200 with a well-formed empty urlset for any
    // crawler that already knows the URL. Listing it as well published a
    // pointer to nothing, and measured worse than nothing, because the file
    // NAMED for Arabic company pages carried zero of them while
    // sitemap-companies.xml carried all 2,502 — so anything partitioning the
    // estate by the sitemap a URL was listed in read 2,502 EN and 0 AR.
    smChild("{$baseUrl}/sitemap-blog.xml", smChildDate('blog', $db));
    smChild("{$baseUrl}/sitemap-logos.xml", smChildDate('logos', $db));
    smChild("{$baseUrl}/sitemap-printshops.xml", smChildDate('printshops', $db));
    echo '</sitemapindex>' . "\n";
    exit;
}

// --- Child sitemaps ----------------------------------------------------
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

/**
 * RETIRED (r80). smUrlBilingual() built the Arabic URL by concatenating '/ar'
 * onto the path, which is the one construction that cannot return null, so it
 * asserted a twin for whatever it was handed and its callers had to know, out
 * of band, which paths were safe. smUrl() now asks ArTwins::arPath() and emits
 * the pair itself, so there is one function, one oracle, and one declaration
 * per URL. Kept as a shim only so a stale caller fails loudly rather than
 * silently emitting a second, contradicting <url>.
 */
function smUrlBilingual($path, $lastmod, $changefreq = 'monthly', $priority = '0.5') {
    global $baseUrl;
    smUrl($baseUrl . '/' . ltrim($path, '/'), $lastmod, $changefreq, $priority);
}

/**
 * slug => the <image:image> block for that company's logo, ready to nest
 * inside its <url>. Returns [] when the columns or table are absent.
 *
 * One query, one place, so the image extension can be attached to the URL's
 * single declaration instead of justifying a second one.
 */
function logoImageXml($db, $baseUrl) {
    $out = [];
    try {
        $logos = $db->fetchAll(
            "SELECT slug, name_en, logo_svg_path, logo_png_path,
                    logo_png_2048_path, logo_webp_path
               FROM om_companies
              WHERE logo_status IN ('indexed','verified')"
        );
    } catch (Throwable $e) {
        return $out; // om_companies may lack logo_status until migration runs
    }
    $licenseUrl = "{$baseUrl}/logos/terms";
    foreach ($logos as $l) {
        // Highest-fidelity public URL for the image loc.
        $rel = $l['logo_svg_path']
            ?: $l['logo_png_2048_path']
            ?: $l['logo_png_path']
            ?: $l['logo_webp_path'];
        if (!$rel) continue;
        $caption = trim(($l['name_en'] ?? '') . ' logo');
        $title   = trim(($l['name_en'] ?? '') . ' logo, Omani Logo Library');
        $out[$l['slug']] =
              "        <image:image>\n"
            . "            <image:loc>" . smX($baseUrl . $rel) . "</image:loc>\n"
            . "            <image:title>" . smX($title) . "</image:title>\n"
            . "            <image:caption>" . smX($caption) . "</image:caption>\n"
            . "            <image:license>" . smX($licenseUrl) . "</image:license>\n"
            . "        </image:image>\n";
    }
    return $out;
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
        ['/delete-account', 'yearly', '0.4'],
        ['/security',     'yearly',  '0.4'],
        ['/cookies',      'yearly',  '0.4'],
        // /print-shops is NOT here: sitemap-printshops.xml is its home, and
        // listing it in both put the same two URLs in the index twice (r80).
        ['/app',          'monthly', '0.8'],
        // These four were body-translated, nginx-routed and hreflang-tagged,
        // and named in ArTwins::PATHS, yet listed in no sitemap at all, in
        // either language. /pricing is the highest commercial-intent page on
        // the site. tools/verify-ar-twins.php now fails if a PATHS entry is
        // missing here, so the list cannot drift out of the map again.
        ['/pricing',      'monthly', '0.9'],
        ['/case-studies', 'monthly', '0.8'],
        ['/changelog',    'weekly',  '0.5'],
        ['/status',       'daily',   '0.4'],
        ['/business-card-scanner', 'monthly', '0.8'],
        ['/industries',              'monthly', '0.85'],
        ['/press-kit',               'monthly', '0.85'],
        ['/gcc/saudi-arabia',        'monthly', '0.9'],
        ['/gcc/uae',                 'monthly', '0.9'],
        ['/gcc/qatar',               'monthly', '0.85'],
        ['/gcc/bahrain',             'monthly', '0.85'],
        ['/gcc/kuwait',              'monthly', '0.85'],
        ['/gcc/oman',                'monthly', '0.9'],
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
        smUrl($baseUrl . $path, smRouteDate($path), $freq, $prio);
    }

} elseif ($part === 'tools') {
    smUrl("{$baseUrl}/tools", smRouteDate('/tools'), 'monthly', '0.9');
    $tools = [
        'vcard-qr-generator',
        'email-signature-generator',
        'whatsapp-qr-generator',
        'nfc-business-card-guide',
    ];
    foreach ($tools as $t) {
        smUrl("{$baseUrl}/tools/{$t}", smRouteDate("/tools/{$t}"), 'monthly', '0.8');
    }

} elseif ($part === 'solutions') {
    smUrl("{$baseUrl}/solutions", smRouteDate('/solutions'), 'monthly', '0.9');
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
        smUrl("{$baseUrl}/solutions/{$s}", smRouteDate("/solutions/{$s}"), 'monthly', '0.7');
    }

} elseif ($part === 'directory') {
    // Flagship + directory hubs (indexes, sector hubs, wilayat hubs), NOT individual companies.
    // Map-backed hubs: smUrl emits both twins with their alternates.
    smUrl("{$baseUrl}/oman-business-index", smRouteDate('/oman-business-index'), 'monthly', '0.9');
    smUrl("{$baseUrl}/gcc-business-index", smRouteDate('/gcc-business-index'), 'weekly',  '0.95');
    smUrl("{$baseUrl}/companies", smRouteDate('/companies'), 'weekly',  '0.9');

    // Parameterised hubs cannot live in the map, so they go through
    // smUrlBilingual(), which emits the same EN/AR/x-default alternate block.
    // Listing the two sides as bare smUrl() calls (what this did before) put
    // 34 /ar/ URLs in the sitemap with no alternates at all, contradicting the
    // reciprocal tags the pages themselves emit.
    if ($db) {
        try {
            foreach ($db->fetchAll("SELECT DISTINCT sector FROM om_companies ORDER BY sector ASC") as $s) {
                smUrlBilingual("/companies/sector/{$s['sector']}", smRouteDate('/companies'), 'weekly', '0.7');
            }
            foreach ($db->fetchAll("SELECT DISTINCT wilayat FROM om_companies ORDER BY wilayat ASC") as $w) {
                smUrlBilingual("/companies/wilayat/{$w['wilayat']}", smRouteDate('/companies'), 'weekly', '0.7');
            }
        } catch (Throwable $e) { /* table may not exist */ }
    }

} elseif ($part === 'companies') {
    // English company profiles. Each <url> declares both EN and AR
    // alternates so Google treats /companies/slug and /ar/companies/slug
    // as a confirmed bilingual pair (hreflang reciprocity).
    if ($db) {
        // The logo image extension rides on THIS declaration. It used to be a
        // second <url> for the same /companies/{slug} emitted from
        // sitemap-logos.xml, so 106 company pages were declared twice across
        // the index with different priorities and only one copy carrying the
        // hreflang block (r80). All 106 are in this list, measured, so nothing
        // is lost by moving the image here.
        $logoXml = logoImageXml($db, $baseUrl);
        try {
            // r6-99: one definition of the index population, shared with the
            // Dataset count on /oman-business-index. See includes/CompanyIndex.php.
            $rows = CompanyIndex::rows($db);
            foreach ($rows as $c) {
                $lastmod = date('Y-m-d', strtotime($c['updated_at']));
                smUrl("{$baseUrl}/companies/" . $c['slug'], $lastmod, 'monthly', '0.5',
                      $logoXml[$c['slug']] ?? '');
            }
        } catch (Throwable $e) { /* table may not exist */ }
    }

} elseif ($part === 'companies-ar') {
    // Intentionally EMPTY. It used to re-declare /companies and /ar/companies,
    // which sitemap-directory.xml already declares at priority 0.9, so the
    // index shipped two contradicting claims for the estate's two busiest hub
    // URLs (r80). The child stays in the index so an already-crawled child
    // URL keeps answering valid XML rather than 404ing.

} elseif ($part === 'blog') {
    // Blog posts (with image metadata) + career listings.
    if ($db) {
        try {
            $posts = $db->fetchAll(
                "SELECT slug, slug_ar, title, featured_image, updated_at
                   FROM blog_posts
                  WHERE status = 'published'
                  ORDER BY updated_at DESC"
            );
            foreach ($posts as $post) {
                $lastmod = date('Y-m-d', strtotime($post['updated_at']));
                // If an AR translation ships (slug_ar populated), emit the
                // AR URL too and mark both with xhtml:link alternates so
                // Google treats them as one post in two languages.
                $hasAr = !empty($post['slug_ar']);
                $enUrl = $baseUrl . '/blog/' . $post['slug'];
                $arUrl = $baseUrl . '/ar/blog/' . ($post['slug_ar'] ?? $post['slug']);
                $url = $enUrl;
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
                if ($hasAr) {
                    echo "        <xhtml:link rel=\"alternate\" hreflang=\"en\" href=\"" . smX($enUrl) . "\" />\n";
                    echo "        <xhtml:link rel=\"alternate\" hreflang=\"ar\" href=\"" . smX($arUrl) . "\" />\n";
                    echo "        <xhtml:link rel=\"alternate\" hreflang=\"x-default\" href=\"" . smX($enUrl) . "\" />\n";
                }
                echo "    </url>\n";
                if ($hasAr) {
                    echo "    <url>\n";
                    echo "        <loc>" . smX($arUrl) . "</loc>\n";
                    echo "        <lastmod>{$lastmod}</lastmod>\n";
                    echo "        <changefreq>weekly</changefreq>\n";
                    echo "        <priority>0.7</priority>\n";
                    echo "        <xhtml:link rel=\"alternate\" hreflang=\"en\" href=\"" . smX($enUrl) . "\" />\n";
                    echo "        <xhtml:link rel=\"alternate\" hreflang=\"ar\" href=\"" . smX($arUrl) . "\" />\n";
                    echo "        <xhtml:link rel=\"alternate\" hreflang=\"x-default\" href=\"" . smX($enUrl) . "\" />\n";
                    echo "    </url>\n";
                }
            }
            // SELECT slug_ar too for bilingual emission
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
    // Omani Logo Library, hub + terms/press + 23 sector pages + image entries
    // for every indexed/verified logo (Google Images surfaces them from
    // <image:image> blocks, not just <img> tags on a page).
    smUrl("{$baseUrl}/logos", smRouteDate('/logos'), 'daily',   '0.9');
    smUrl("{$baseUrl}/logos/press", smRouteDate('/logos/press'), 'monthly', '0.5');
    smUrl("{$baseUrl}/logos/terms", smRouteDate('/logos/terms'), 'yearly',  '0.3');

    if ($db) {
        try {
            $sectors = $db->fetchAll(
                "SELECT DISTINCT sector FROM om_companies
                  WHERE logo_status IN ('indexed','verified')
                  ORDER BY sector ASC"
            );
            foreach ($sectors as $s) {
                smUrl("{$baseUrl}/logos/{$s['sector']}", smRouteDate('/logos'), 'weekly', '0.7');
            }
        } catch (Throwable $e) { /* om_companies may lack logo_status until migration runs */ }

        // The per-logo <image:image> blocks used to be emitted HERE, as a
        // second <url> for /companies/{slug}. They now ride on the single
        // declaration in sitemap-companies.xml (see logoImageXml()). A URL
        // belongs to exactly one child sitemap.
    }

} elseif ($part === 'printshops') {
    // Public-facing print shop surfaces. Individual shops don't have
    // public profile URLs yet; adding the listing hub + per-shop
    // slug pages whenever they ship is tracked in action 788.
    smUrlBilingual('/print-shops', smRouteDate('/print-shops'), 'weekly', '0.8');
    // Per-shop detail URLs (/print-shops/{slug}) are not yet built; the
    // /print-shops index page is the only public surface. Skip emitting
    // detail URLs so Google doesn't crawl them as 404s.

} else {
    // Unknown part, empty urlset (Google will just see no URLs; still valid XML).
}

echo '</urlset>' . "\n";
