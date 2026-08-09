<?php
/**
 * r66 / bhd-group-seo-llm27-30: a <lastmod> that is a build stamp.
 *
 * MEASURED FIRST, live through the edge on 2026-08-05
 * (evidence/r66/baseline-cardify-sitemap-ahead.txt): 179 cardify.om sitemap
 * entries carried <lastmod> equal to the day the sitemap was requested, and
 * 172 of them pointed at a page whose OWN dateModified was older. 21 of those
 * pages last changed on 2026-04-29 and the sitemap told a crawler they had
 * changed 98 days later. That is not a stale date, it is a false one, and it
 * is the channel a crawler schedules against.
 *
 * The cause was one line, sitemap.php:18, `$today = date('Y-m-d')`, passed as
 * the lastmod for every static, tools, solutions, directory-hub, logos-hub and
 * print-shop URL.
 *
 * The page already knew the right answer. includes/Freshness.php dates a page
 * from filemtime() of the file that rendered it, never from today, and emits
 * that as dateModified in the WebPage node and as the visible "last updated"
 * line. So the two channels had different SOURCES, and the sitemap's source
 * was the clock. This class gives the sitemap the page's source: it resolves a
 * public path to the PHP file nginx executes for it, and dates it with the
 * same filemtime()/gmdate() pair Freshness uses, so the two channels cannot
 * disagree by construction.
 *
 * WHEN IT CANNOT RESOLVE A PATH IT RETURNS NULL, and the caller omits the
 * <lastmod> element. An unknown date must not become today's: that is the bug
 * this file exists to remove, and a fallback to $today would reintroduce it
 * for exactly the paths the resolver does not understand, which are the ones
 * nobody is watching. A <url> with no <lastmod> is valid sitemap XML.
 *
 * The rules below mirror /www/server/panel/vhost/rewrite/cardify.om.conf. That
 * file is 0640 root:root and PHP runs as www, so it cannot be parsed at render
 * time; tools/verify-sitemap-freshness.php reads it as root from the deploy
 * step and fails if any rule here disagrees with the nginx rule that actually
 * serves the path.
 */
class SitemapFreshness
{
    /**
     * Ordered path -> source-file rules, first match wins, exactly as nginx
     * evaluates its rewrite list. $1 in the replacement is capture group 1.
     *
     * Every /ar/ route in the rewrite table maps to the SAME php file as its
     * English twin with ?lang=ar appended (/ar/about -> about.php?lang=ar,
     * /ar/companies -> companies.php?lang=ar, /ar/ -> index.php?lang=ar), so
     * the prefix is stripped before matching rather than doubling the table.
     */
    private const RULES = [
        ['#^/$#',                              'index.php'],
        ['#^/tools$#',                         'tools.php'],
        ['#^/tools/([a-z0-9-]+)$#',            'tools/$1.php'],
        ['#^/solutions$#',                     'solutions.php'],
        ['#^/solutions/([a-z0-9-]+)$#',        'solutions/$1.php'],
        ['#^/industries$#',                    'industries/index.php'],
        ['#^/industries/([a-z0-9-]+)$#',       'industries/$1.php'],
        ['#^/gcc/(saudi-arabia|uae|qatar|bahrain|kuwait|oman)$#', 'gcc/country.php'],
        ['#^/companies(/.*)?$#',               'companies.php'],
        ['#^/logos(/.*)?$#',                   'logos.php'],
        ['#^/(press-kit|press|media-kit)$#',   'press.php'],
        ['#^/oman-business-index$#',           'oman-business-index.php'],
        ['#^/gcc-business-index$#',            'gcc-business-index.php'],
        ['#^/print-shops$#',                   'print-shops.php'],
        ['#^/blog$#',                          'blog.php'],
        ['#^/careers$#',                       'careers.php'],
        // The plain one-segment pages: /about, /faq, /terms, /pricing, ...
        // Constrained to a single segment so a deeper unknown path falls
        // through to null instead of resolving to a file it does not use.
        ['#^/([a-z0-9-]+)$#',                  '$1.php'],
    ];

    /** Absolute path of the file that renders $path, or null if unresolved. */
    public static function sourceFile(string $path, ?string $docroot = null): ?string
    {
        $docroot = rtrim($docroot ?? dirname(__DIR__), '/');
        $p = parse_url($path, PHP_URL_PATH);
        if (!is_string($p) || $p === '') {
            return null;
        }
        // A trailing slash is the same document; nginx's /?$ says so.
        if ($p !== '/' && substr($p, -1) === '/') {
            $p = rtrim($p, '/');
        }
        // /ar/x is x rendered with lang=ar, from the same file. /ar alone
        // (with or without the slash) is the Arabic home page.
        if ($p === '/ar') {
            $p = '/';
        } elseif (strpos($p, '/ar/') === 0) {
            $p = substr($p, 3);
            if ($p === '') {
                $p = '/';
            }
        }
        foreach (self::RULES as [$re, $repl]) {
            if (preg_match($re, $p, $m)) {
                $rel = $repl;
                if (strpos($repl, '$1') !== false) {
                    $rel = str_replace('$1', $m[1] ?? '', $repl);
                }
                $abs = $docroot . '/' . ltrim($rel, '/');
                return is_file($abs) ? $abs : null;
            }
        }
        return null;
    }

    /**
     * ISO date for a public path, from the mtime of the file that renders it.
     * Null when the path does not resolve: the caller must then emit no
     * <lastmod> rather than substituting today.
     */
    public static function lastmod(string $path, ?string $docroot = null): ?string
    {
        $file = self::sourceFile($path, $docroot);
        if ($file === null) {
            return null;
        }
        $mtime = @filemtime($file);
        return $mtime ? gmdate('Y-m-d', $mtime) : null;
    }

    /**
     * The row-dated URLs are DELIBERATELY LEFT ALONE, and it is worth writing
     * down why, because "make every lastmod agree with the page" is the
     * obvious next step and it would be a regression.
     *
     * MEASURED, same run, 60 randomly sampled row-dated URLs
     * (evidence/r66/baseline-rowdated-sample.txt): 0 ahead of their page, 57
     * behind it, 3 equal. /companies/{slug} renders through companies.php, so
     * the PAGE reports companies.php's mtime for every company alike, while
     * the sitemap reports that company's own updated_at. Replacing the row
     * date with the file date, or with max(row, file), would move 57 of those
     * 60 from "behind" to "equal-to-the-deploy-date", which is the frozen
     * sitemap freshness_gate.py already fails bhdoman.com's for. Behind is
     * stale and honest; ahead is the lie. Only the lie is being removed.
     */
}
