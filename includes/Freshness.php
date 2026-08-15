<?php
/**
 * r6-95: cardify.om published no freshness signal at all: no dateModified in
 * schema and no visible "last updated" line on any page. A model asked how
 * current the page is has nothing to read, and Google has nothing to prefer.
 *
 * The date is taken from file mtime, never from date('Y-m-d'). A today-stamp
 * on every URL is a false freshness signal: it tells a crawler the whole site
 * changed every night, which is both untrue and the exact pattern that gets
 * freshness discounted.
 *
 * r250, CHANGE OF APPROACH #65. Approaches 1-64 all argued about WHERE the
 * date comes from while agreeing, silently, that ONE file owns a page. It does
 * not. A cardify page is a composition: about.php requires ui-header.php,
 * ui-footer.php, Seo.php, ArTwins.php and the rest, and every one of them puts
 * bytes on the wire. MEASURED on the box, 15 Aug 2026:
 *
 *     about.php               2026-08-05   <- what the page claimed
 *     includes/Seo.php        2026-08-09
 *     includes/ui-header.php  2026-08-12
 *     includes/ui-footer.php  2026-08-12   <- when the bytes last changed
 *
 * so /about published 2026-08-05 over markup last rewritten on the 12th, and
 * freshness_gate has held it as CHANGED-WITHOUT-DATING ever since. The date was
 * not missing and not stale by neglect: it was keyed to the wrong thing.
 *
 * The dependency closure of a rendered page is not a guess, PHP already knows
 * it: get_included_files() is exactly the list of files this request read. So
 * the page is dated by the NEWEST mtime in that closure, restricted to files
 * under the site root that can actually reach the response.
 *
 * This deliberately makes a shared-include change move every page's date. That
 * is not the nightly-today-stamp failure mode: those pages genuinely changed,
 * because the footer they render is different bytes. A date only moves here
 * when some file that composed the response was actually rewritten.
 */
class Freshness
{
    /**
     * Path fragments that are read during a request but put nothing on the
     * wire. Touching one of these must NOT re-date the estate. Kept short and
     * explicit on purpose: an over-broad deny list re-creates the r250 bug one
     * directory down, by hiding a file that does render.
     */
    private const NON_RENDERING = [
        // The instrument does not date the thing it measures. Freshness.php is
        // in every page's closure by construction, so leaving it in makes the
        // date self-referential: MEASURED on the box 15 Aug 2026, deploying
        // this very file moved /about from the honest 2026-08-12 (when its
        // header and footer were last rewritten) to the deploy date. The only
        // bytes this file contributes ARE the date, so it cannot be evidence
        // that the content changed.
        '/includes/Freshness.php',
        '/config.php',        // credentials + constants, no output
        '/config.local.php',
        '/vendor/',           // third-party, not our content
        '/storage/',
        '/cache/',
        '/logs/',
        '/tmp/',
    ];

    /** Absolute path of the file that owns this page (still the anchor). */
    public static function sourceFile(): string
    {
        // A page rendered through router.php can name its own source with
        // $GLOBALS['pageSourceFile'] so the date follows the content, not the
        // front controller.
        $named = $GLOBALS['pageSourceFile'] ?? null;
        if (is_string($named) && $named !== '' && is_file($named)) {
            return $named;
        }
        $script = $_SERVER['SCRIPT_FILENAME'] ?? '';
        if (is_string($script) && $script !== '' && is_file($script)) {
            return $script;
        }
        return __FILE__;
    }

    /** The site root every rendering dependency must live under. */
    public static function siteRoot(): string
    {
        return dirname(__DIR__);
    }

    /**
     * r252, CHANGE OF APPROACH #66. The closure is keyed to the ROUTE, not to
     * the running request, because a route has TWO readers and only one of them
     * is ever inside the request.
     *
     * r250 dated the page by get_included_files(), which is the truth of THIS
     * request and is unavailable to sitemap.php, whose whole job is to date
     * OTHER routes. So the composition rule reached the page channel and not the
     * sitemap channel, and the two disagreed: measured on the wire 15 Aug 2026,
     * 14 of 20 cardify URLs published a page date the sitemap contradicted, and
     * /about (page 2026-08-05 = sitemap 2026-08-05 before r250) was broken BY the
     * fix. That is bhd-r6-95's own root cause, one fact with two authors, moved
     * one channel along.
     *
     * So the closure is now computed from the renderer path alone, by code both
     * channels call: a static walk of the require/include statements (this tree
     * writes them as __DIR__ / INCLUDES_DIR / BASE_DIR plus a literal, 1,922 of
     * 1,922 of them), plus the lang namespaces those files ask for by name. The
     * page passes its own renderer, the sitemap passes the route's renderer, and
     * the same function answers both, so the two claims cannot drift apart.
     *
     * MEASURED against the runtime closure on six pages (about, index, pricing,
     * faq, contact, careers): the static answer is a SUPERSET of what PHP
     * actually read, every time, and the newest mtime is identical. A superset is
     * the safe direction: it can only see a change earlier, never later.
     */
    public static function renderDependencies(): array
    {
        return self::routeFiles(self::sourceFile(), self::siteRoot());
    }

    /**
     * Pure given the filesystem: every file that can put bytes on the wire for
     * the route rendered by $entry. Same answer inside a request and outside
     * one, which is the whole point.
     */
    public static function routeFiles(string $entry, ?string $root = null): array
    {
        $root = rtrim($root ?? self::siteRoot(), '/');
        $reInclude = '/\\b(?:require|include)(?:_once)?\\s*\\(?\\s*(__DIR__|INCLUDES_DIR|BASE_DIR)\\s*\\.\\s*([\'"])([^\'"]+)\\2/';
        $reLangNs  = '/\\bt\\(\\s*([\'"])([a-zA-Z0-9_]+)\\./';
        $seen = [];
        $files = [];
        $namespaces = [];
        $queue = [$entry];
        // A tree this size closes in well under 200 nodes; the cap is a
        // backstop against a cycle in a future include graph, not a budget.
        $guard = 0;
        while ($queue && $guard++ < 500) {
            $path = realpath(array_shift($queue));
            if ($path === false || isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;
            $files[] = $path;
            if (substr($path, -4) !== '.php') {
                continue;
            }
            $src = @file_get_contents($path);
            if ($src === false) {
                continue;
            }
            if (preg_match_all($reInclude, $src, $m, PREG_SET_ORDER)) {
                foreach ($m as $hit) {
                    $base = $hit[1] === '__DIR__' ? dirname($path)
                          : ($hit[1] === 'INCLUDES_DIR' ? $root . '/includes' : $root);
                    $queue[] = $base . $hit[3];
                }
            }
            if (preg_match_all($reLangNs, $src, $m2, PREG_SET_ORDER)) {
                foreach ($m2 as $hit) {
                    $namespaces[$hit[2]] = true;
                }
            }
        }
        // Both locales, deliberately. The sitemap publishes ONE lastmod for a
        // twin pair (smUrl emits /x and /ar/x from one call), so dating the two
        // editions differently would re-open the same disagreement one row down.
        // A route's content is both of its language editions; the claim "this
        // route last changed on X" stays true of the pair.
        foreach (array_keys($namespaces) as $ns) {
            foreach (['en', 'ar'] as $locale) {
                $lang = $root . '/lang/' . $locale . '/' . $ns . '.php';
                if (is_file($lang)) {
                    $files[] = $lang;
                }
            }
        }
        return self::keepRendering($files, $root);
    }

    /**
     * The route's timestamp, for a caller that is not inside that route's
     * request. sitemap.php's only entry point.
     */
    public static function routeTimestamp(string $entry, ?string $root = null): ?int
    {
        return self::newestMtime(self::routeFiles($entry, $root));
    }

    /**
     * Pure, so the selftest can drive it without a request (llm52-3). Keeps the
     * paths that are inside $root and are not on the NON_RENDERING list.
     */
    public static function keepRendering(array $files, string $root): array
    {
        $root = rtrim($root, '/') . '/';
        $kept = [];
        foreach ($files as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }
            if (strncmp($path, $root, strlen($root)) !== 0) {
                continue;                       // outside the site, not ours
            }
            $rel = substr($path, strlen($root) - 1);   // keep the leading '/'
            $skip = false;
            foreach (self::NON_RENDERING as $frag) {
                if (strpos($rel, $frag) !== false) {
                    $skip = true;
                    break;
                }
            }
            if (!$skip) {
                $kept[$path] = true;
            }
        }
        return array_keys($kept);
    }

    /**
     * Pure: the newest readable mtime in a closure, or null when nothing in it
     * can be read. Null is honest; 0 or time() would both be lies.
     */
    public static function newestMtime(array $files): ?int
    {
        $newest = null;
        foreach ($files as $path) {
            $mtime = @filemtime($path);
            if ($mtime && ($newest === null || $mtime > $newest)) {
                $newest = $mtime;
            }
        }
        return $newest;
    }

    /**
     * Pure: a page's DECLARED content date, or null. A row-backed page (a
     * company, a post) knows the instant its content changed, and that instant
     * is what the sitemap publishes for the same URL; a render closure cannot
     * see it and would answer the shared layout date for every row alike.
     * Accepts an epoch int or a 'Y-m-d' string, and refuses anything else
     * rather than guessing.
     */
    public static function declaredTimestamp($declared): ?int
    {
        if (is_int($declared) && $declared > 0) {
            return $declared;
        }
        if (is_string($declared) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $declared)) {
            $t = strtotime($declared . ' 00:00:00 UTC');
            return $t ?: null;
        }
        return null;
    }

    /** Unix mtime that dates this page, or null if nothing is readable. */
    public static function timestamp(): ?int
    {
        $declared = self::declaredTimestamp($GLOBALS['pageContentDate'] ?? null);
        if ($declared !== null) {
            return $declared;
        }
        $newest = self::newestMtime(self::renderDependencies());
        if ($newest !== null) {
            return $newest;
        }
        // The closure told us nothing (CLI, odd SAPI, unreadable tree): fall
        // back to the page's own file rather than publishing no date at all.
        $own = @filemtime(self::sourceFile());
        return $own ?: null;
    }

    /**
     * True when this page's date came from a DECLARED row timestamp rather
     * than a file mtime. r231's rule, and it is a rule about the timestamp:
     * filemtime() is an absolute instant, so gmdate(); a DB updated_at is a
     * wall-clock string already written in Asia/Muscat, so date() round-trips
     * it in its own calendar and gmdate() would shift a 02:00 Muscat row back
     * a day. MEASURED: /companies/aramex-muscat read 2026-05-30 under gmdate
     * while its own sitemap leg said 2026-05-31.
     */
    public static function isDeclared(): bool
    {
        return self::declaredTimestamp($GLOBALS['pageContentDate'] ?? null) !== null;
    }

    /** ISO-8601 date (YYYY-MM-DD) of the page, or null if unreadable. */
    public static function isoDate(): ?string
    {
        $mtime = self::timestamp();
        if (!$mtime) {
            return null;
        }
        return self::isDeclared() ? date('Y-m-d', $mtime) : gmdate('Y-m-d', $mtime);
    }

    /** Human date for the visible line, in the current locale's numerals. */
    public static function displayDate(): ?string
    {
        $mtime = self::timestamp();
        if (!$mtime) {
            return null;
        }
        $en = self::isDeclared() ? date('j F Y', $mtime) : gmdate('j F Y', $mtime);
        // An Arabic page carrying an English month name is the same
        // half-translated surface r6-74 was raised for, one line further down.
        if (function_exists('currentLocale') && currentLocale() === 'ar') {
            $months = [
                'January' => 'يناير', 'February' => 'فبراير', 'March' => 'مارس',
                'April' => 'أبريل', 'May' => 'مايو', 'June' => 'يونيو',
                'July' => 'يوليو', 'August' => 'أغسطس', 'September' => 'سبتمبر',
                'October' => 'أكتوبر', 'November' => 'نوفمبر', 'December' => 'ديسمبر',
            ];
            return strtr($en, $months);
        }
        return $en;
    }
}
