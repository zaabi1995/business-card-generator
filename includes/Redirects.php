<?php
/**
 * Cardify legacy URL 301 router (Category R action 431).
 *
 * Two-layer approach:
 *   1. Nginx (preferred, zero PHP cost) — handled in the VPS rewrite file.
 *      See /www/server/panel/vhost/rewrite/cardify.om.conf "Legacy URL 301s".
 *   2. This file — PHP fallback for dynamic/DB-backed redirects (renamed
 *      slugs, campaign aliases, anything the nginx layer can't statically
 *      match).
 *
 * Call Redirects::check() early in a router. It looks up the request path
 * against a static curated map + an optional DB table (url_redirects) and
 * issues a 301 if a match is found. Always terminates with exit().
 */
class Redirects
{
    /** Curated alias → canonical map. Keys are paths with leading slash, no query. */
    private const STATIC_MAP = [
        '/home'           => '/',
        '/home.html'      => '/',
        '/index.html'     => '/',
        // /index.php intentionally NOT remapped here. Nginx's default index
        // directive serves / via an internal rewrite to index.php; mapping
        // it to / causes a 301 loop.
        '/sign-in'        => '/login.php',
        '/signin'         => '/login.php',
        '/log-in'         => '/login.php',
        '/sign-up'        => '/get-started',
        '/signup.html'    => '/get-started',
        '/register.html'  => '/get-started',
        '/contact-us'     => '/contact',
        '/about-us'       => '/about',
        '/faqs'           => '/faq',
        '/faq.html'       => '/faq',
        '/pricing.html'   => '/pricing',
        '/terms.html'     => '/terms',
        '/privacy.html'   => '/privacy',
        '/business-cards' => '/',
        '/digital-cards'  => '/',
        '/demo'           => '/get-started',
        '/try'            => '/get-started',
        '/free-trial'     => '/get-started',
        '/logo-library'   => '/logos',
        '/logos-library'  => '/logos',
        // Old AR paths that used ?lang=ar instead of /ar prefix.
        '/ar'             => '/ar/',
    ];

    public static function check(): void
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        // Strip a single trailing slash (except root) for lookup, so both /foo and /foo/ match.
        $lookup = $path === '/' ? '/' : rtrim($path, '/');

        if (isset(self::STATIC_MAP[$lookup])) {
            self::send(self::STATIC_MAP[$lookup]);
        }

        // DB-backed dynamic aliases, e.g. renamed company slugs. Table is
        // optional; skip silently if it doesn't exist yet.
        try {
            $db = Database::getInstance();
            $row = $db->fetchOne(
                "SELECT target FROM url_redirects WHERE source = ? AND active = 1 LIMIT 1",
                [$lookup]
            );
            if ($row && !empty($row['target'])) self::send($row['target']);
        } catch (Throwable $_) { /* url_redirects table may not exist */ }
    }

    /** Issue a 301 with the query string preserved, then exit. */
    private static function send(string $target): void
    {
        $qs = $_SERVER['QUERY_STRING'] ?? '';
        if ($qs !== '' && strpos($target, '?') === false) $target .= '?' . $qs;
        header('Location: ' . $target, true, 301);
        header('Cache-Control: public, max-age=2592000');
        exit;
    }

    /** Helper for admin tooling: record a new dynamic redirect. */
    public static function add(string $source, string $target): void
    {
        $db = Database::getInstance();
        // PDO emulated prepares are OFF, so :t cannot appear twice in one
        // query (HY093). Bind the target under two distinct names.
        $db->exec(
            "INSERT INTO url_redirects (source, target, active, created_at)
             VALUES (:s, :t_ins, 1, NOW())
             ON DUPLICATE KEY UPDATE target = :t_upd, active = 1, updated_at = NOW()",
            ['s' => $source, 't_ins' => $target, 't_upd' => $target]
        );
    }
}
