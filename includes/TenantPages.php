<?php
declare(strict_types=1);

/**
 * TenantPages: hand-built public pages on a tenant subdomain.
 *
 *   https://<slug>.cardify.om/<page>  ->  includes/tenant-pages/<slug>/<page>.php
 *
 * Why this exists: a tenant sometimes needs a public page that is not an
 * employee card. Mehdi Store's paper-bag QR points at
 * https://mehdistore.cardify.om/main, a link page with WhatsApp, socials, 12
 * branches and two sister brands. digital_card.php renders one person with one
 * location, so it cannot hold that.
 *
 * Rules:
 *  - Opt-in by directory. A subdomain with no includes/tenant-pages/<slug>/
 *    directory never gets past the is_dir() check, which is every other tenant.
 *  - Runs BEFORE the tenant DB lookup (TenantHost) on purpose. These URLs get
 *    printed on packaging, so they must keep answering if the companies row is
 *    suspended or the database is briefly unavailable.
 *  - A page name is one lowercase token. Files starting with "_" (site config,
 *    partials) never match the token pattern, so they are never routable.
 *  - includes/ answers 403 at nginx, so templates and their JSON data are not
 *    web-readable. Public assets live in /assets/tenant-pages/<slug>/.
 *  - Optional includes/tenant-pages/<slug>/_site.php returns
 *    ['root_redirect' => '/<page>'] to send the bare subdomain to a page (302,
 *    so it can be changed later without a cached permanent redirect).
 */
final class TenantPages
{
    public const ROOT = __DIR__ . '/tenant-pages';
    private const TOKEN = '[a-z0-9][a-z0-9-]{0,62}';

    /** Tenant slug for a {slug}.cardify.om host, else null. Mirrors TenantHost's host rule. */
    public static function slugForHost(string $host): ?string
    {
        $host = strtolower((string) preg_replace('/:\d+$/', '', trim($host)));
        if (!preg_match('/^([a-z0-9][a-z0-9-]{1,62})\.cardify\.om$/', $host, $m)) {
            return null;
        }
        return $m[1];
    }

    /** Absolute path of a page template, or null when the tenant has no such page. */
    public static function pageFile(string $slug, string $page): ?string
    {
        $re = '/^' . self::TOKEN . '$/';
        if (!preg_match($re, $slug) || !preg_match($re, $page)) {
            return null;
        }
        $file = self::ROOT . '/' . $slug . '/' . $page . '.php';
        return is_file($file) ? $file : null;
    }

    /** @return array<string,mixed> */
    public static function siteConfig(string $slug): array
    {
        if (!preg_match('/^' . self::TOKEN . '$/', $slug)) {
            return [];
        }
        $file = self::ROOT . '/' . $slug . '/_site.php';
        if (!is_file($file)) {
            return [];
        }
        $config = require $file;
        return is_array($config) ? $config : [];
    }

    /**
     * Decide what to do with a request, without side effects (unit-testable).
     *
     * @return array{action:string,file?:string,location?:string,status?:int,slug?:string,page?:string}
     *   action = pass | serve | redirect | method
     */
    public static function route(string $host, string $uri, string $method = 'GET'): array
    {
        $slug = self::slugForHost($host);
        if ($slug === null || !is_dir(self::ROOT . '/' . $slug)) {
            return ['action' => 'pass'];
        }

        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';
        $query = parse_url($uri, PHP_URL_QUERY);
        $qs = is_string($query) && $query !== '' ? '?' . $query : '';

        if ($path === '/') {
            $target = (string) (self::siteConfig($slug)['root_redirect'] ?? '');
            if ($target !== '' && preg_match('~^/' . self::TOKEN . '$~', $target)) {
                return ['action' => 'redirect', 'location' => $target . $qs, 'status' => 302];
            }
            return ['action' => 'pass'];
        }

        if (!preg_match('~^/(' . self::TOKEN . ')(/?)$~', $path, $m)) {
            return ['action' => 'pass'];
        }
        $file = self::pageFile($slug, $m[1]);
        if ($file === null) {
            return ['action' => 'pass'];
        }

        $method = strtoupper($method);
        if ($method !== 'GET' && $method !== 'HEAD') {
            return ['action' => 'method', 'status' => 405];
        }
        if ($m[2] === '/') {
            // One address per page: /main/ -> /main (the QR encodes /main).
            return ['action' => 'redirect', 'location' => '/' . $m[1] . $qs, 'status' => 301];
        }
        return ['action' => 'serve', 'file' => $file, 'slug' => $slug, 'page' => $m[1]];
    }

    /** Serve a tenant page for the current request and exit, or return to normal routing. */
    public static function dispatch(): void
    {
        $r = self::route(
            (string) ($_SERVER['HTTP_HOST'] ?? ''),
            (string) ($_SERVER['REQUEST_URI'] ?? '/'),
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')
        );

        switch ($r['action']) {
            case 'redirect':
                header('Location: ' . $r['location'], true, (int) $r['status']);
                exit;
            case 'method':
                http_response_code(405);
                header('Allow: GET, HEAD');
                exit;
            case 'serve':
                $tenantPage = [
                    'slug'       => $r['slug'],
                    'page'       => $r['page'],
                    'dir'        => dirname($r['file']),
                    'origin'     => 'https://' . $r['slug'] . '.cardify.om',
                    'asset_base' => '/assets/tenant-pages/' . $r['slug'] . '/',
                    'asset_dir'  => dirname(__DIR__) . '/assets/tenant-pages/' . $r['slug'],
                ];
                (static function (string $file, array $tenantPage): void {
                    require $file;
                })($r['file'], $tenantPage);
                exit;
            default:
                return;
        }
    }
}
