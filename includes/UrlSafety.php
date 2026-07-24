<?php
/**
 * UrlSafety, shared URL/host/IP safety helpers.
 *
 * DRY helpers used by card_click.php, admin/short-links.php, s.php,
 * api/offer/redeem.php, api/appointment/book.php, api/lead.php, api/testimonial.php.
 *
 * Hardened against (Codex round-2):
 *  - URL shortener chaining via redirect allowlists (Finding 1)
 *  - Trailing-dot host bypass: `bit.ly.` / `cardify.om.` (Finding 6)
 *  - Wrong client-IP key (REMOTE_ADDR behind Cloudflare) (Finding 3)
 *  - Cookie Secure flag on proxied HTTPS (Finding 7)
 */

/**
 * Known URL shorteners. Used as a blocklist everywhere (redirect allowlists,
 * short-link destinations, etc.) to prevent reputation laundering / chaining.
 *
 * Keep lower-case, hostnames only (no scheme, no trailing dot).
 */
const UNSAFE_SHORTENERS = [
    'bit.ly', 'bitly.com', 'j.mp',
    't.co',
    'goo.gl',
    'tinyurl.com',
    'ow.ly',
    'is.gd', 'v.gd',
    'buff.ly',
    'cutt.ly',
    'rebrand.ly',
    'shorte.st', 'adf.ly',
    'lnkd.in',
    't.ly', 'rb.gy',
    'short.io',
    'yourls.org',
    's.id',
    'tiny.cc',
    'shorturl.at',
    'trib.al',
    'x.co',
    'soo.gd',
    'qr.net',
    'clck.ru',
    'bc.vc',
];

/**
 * Risky free/disposable TLDs commonly abused by phishing kits.
 * Block at mint-time for short-link destinations.
 *
 * Lower-case, no leading dot.
 */
const UNSAFE_TLDS = [
    'tk', 'ml', 'ga', 'cf', 'gq',
    'xyz', 'top', 'click', 'country', 'stream',
    'zip', 'mov',
    'rest',
];

/**
 * Canonicalise a hostname string.
 *
 *  - lowercases
 *  - strips one-or-more trailing dots (DNS "root label" form, e.g. `bit.ly.`)
 *  - strips surrounding whitespace
 *
 * Callers MUST pass the returned value (not the raw parse_url host) into any
 * blocklist/allowlist comparison. Prevents `bit.ly.` → bypass `bit.ly` list.
 */
function canonicalHost(string $host): string
{
    $host = trim($host);
    $host = strtolower($host);
    $host = rtrim($host, '.');
    return $host;
}

/**
 * Canonicalise the host of a URL. Returns empty string if URL is malformed or
 * has no host.
 */
function canonicalHostFromUrl(string $url): string
{
    $h = parse_url($url, PHP_URL_HOST);
    if (!is_string($h) || $h === '') {
        return '';
    }
    return canonicalHost($h);
}

/**
 * Accept only HTTPS URLs on cardify.om or one of its subdomains.
 *
 * Credentials, explicit ports, malformed hosts, and fragments are rejected or
 * removed to match the native app's link policy.
 */
function normalizeCardifyUrl(string $value): ?string
{
    $value = trim($value);
    if ($value === '' || !filter_var($value, FILTER_VALIDATE_URL)) {
        return null;
    }
    $parts = parse_url($value);
    if (!is_array($parts)
        || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['port'])) {
        return null;
    }
    $host = canonicalHost((string) ($parts['host'] ?? ''));
    if ($host !== 'cardify.om' && !str_ends_with($host, '.cardify.om')) {
        return null;
    }
    $path = (string) ($parts['path'] ?? '/');
    if ($path === '' || $path[0] !== '/') {
        $path = '/' . $path;
    }
    $normalized = 'https://' . $host . $path;
    if (isset($parts['query']) && $parts['query'] !== '') {
        $normalized .= '?' . $parts['query'];
    }
    return $normalized;
}

/**
 * Returns true if $host (already canonicalised or raw) is on the shortener
 * blocklist. Call on parse_url()'d hosts.
 */
function isUrlShortener(string $host): bool
{
    return in_array(canonicalHost($host), UNSAFE_SHORTENERS, true);
}

/**
 * Returns true if $host sits on a blocked TLD (free/disposable/abused).
 */
function hasUnsafeTld(string $host): bool
{
    $host = canonicalHost($host);
    $dot = strrchr($host, '.');
    if ($dot === false || strlen($dot) < 2) {
        return false;
    }
    return in_array(substr($dot, 1), UNSAFE_TLDS, true);
}

/**
 * Validate that $dest is safe to redirect to from a `/card_click.php`-style
 * endpoint, given a set of whitelisted https hosts.
 *
 * Accepts:
 *  - same-origin paths (leading `/`, not `//`)
 *  - tel:, mailto:, sms:, whatsapp: schemes (no host)
 *  - https:// to a host on $allowedHttpsHosts
 *
 * Rejects shorteners even if they somehow pass the host whitelist.
 *
 * @return string|null sanitized URL, or null if rejected.
 */
function isAllowedRedirectHost(string $dest, array $allowedHttpsHosts): ?string
{
    if ($dest === '') {
        return null;
    }
    // Relative same-origin path
    if ($dest[0] === '/') {
        // Disallow "//" (protocol-relative), open redirect vector
        if (isset($dest[1]) && $dest[1] === '/') {
            return null;
        }
        if (preg_match('/[\r\n]/', $dest)) {
            return null;
        }
        return $dest;
    }

    $lower = strtolower($dest);

    // Non-http(s) schemes: tel:, mailto:, sms:, whatsapp:, accept as-is.
    $passthroughSchemes = ['tel:', 'mailto:', 'sms:', 'whatsapp:'];
    foreach ($passthroughSchemes as $prefix) {
        if (strpos($lower, $prefix) === 0) {
            if (preg_match('/[\r\n]/', $dest)) {
                return null;
            }
            return $dest;
        }
    }

    // https://, validate URL, then check host whitelist.
    if (strpos($lower, 'https://') === 0) {
        if (!filter_var($dest, FILTER_VALIDATE_URL)) {
            return null;
        }
        $host = canonicalHostFromUrl($dest);
        if ($host === '') {
            return null;
        }
        // Reject URL shorteners unconditionally, never laundered even if on
        // a caller's allowlist.
        if (isUrlShortener($host)) {
            return null;
        }
        // Canonicalise the caller-supplied allowlist too.
        $canonAllowed = array_map('canonicalHost', $allowedHttpsHosts);
        if (!in_array($host, $canonAllowed, true)) {
            return null;
        }
        return $dest;
    }

    // Everything else (http://, javascript:, data:, file:, ftp:, …) rejected.
    return null;
}

/**
 * Return the best available real client IP, honouring Cloudflare / proxy
 * headers. Mirrors CardAnalytics::getClientIP so rate-limit reads match
 * rate-limit writes.
 */
function getClientIp(): string
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    // Only honour Cloudflare / proxy client-IP headers when the request truly
    // reached us THROUGH Cloudflare (REMOTE_ADDR is a CF edge). A caller hitting
    // the public origin directly could otherwise set CF-Connecting-IP to forge
    // any client IP and defeat every rate limit. Direct hits use REMOTE_ADDR.
    if (isCloudflareIp($remote)) {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = trim(explode(',', $_SERVER[$k])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
    }
    if (filter_var($remote, FILTER_VALIDATE_IP)) {
        return $remote;
    }
    return '0.0.0.0';
}

/**
 * True if $ip falls in a published Cloudflare edge range (v4 + v6).
 * Source: https://www.cloudflare.com/ips/ (stable; refresh if CF changes it).
 */
function isCloudflareIp(string $ip): bool
{
    if ($ip === '') return false;
    static $v4 = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    ];
    static $v6 = [
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];
    $bin = @inet_pton($ip);
    if ($bin === false) return false;
    $ranges = strlen($bin) === 4 ? $v4 : $v6;
    foreach ($ranges as $cidr) {
        [$net, $bits] = explode('/', $cidr);
        $netBin = @inet_pton($net);
        if ($netBin === false || strlen($netBin) !== strlen($bin)) continue;
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        $rem = $bits % 8;
        if ($bytes > 0 && strncmp($bin, $netBin, $bytes) !== 0) continue;
        if ($rem > 0) {
            $m = 0xff << (8 - $rem) & 0xff;
            if ((ord($bin[$bytes]) & $m) !== (ord($netBin[$bytes]) & $m)) continue;
        }
        return true;
    }
    return false;
}

/**
 * True if the current request is (effectively) HTTPS, taking proxy headers
 * into account. Cloudflare in "Flexible" mode hits us over HTTP but the
 * visitor is on HTTPS, so without this check cookies would be mint'd without
 * Secure.
 */
function isHttpsRequest(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
        && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }
    // Cloudflare: CF-Visitor: {"scheme":"https"}
    if (!empty($_SERVER['HTTP_CF_VISITOR'])
        && strpos($_SERVER['HTTP_CF_VISITOR'], '"https"') !== false) {
        return true;
    }
    // aaPanel / nginx forwarded ssl marker
    if (!empty($_SERVER['HTTP_X_FORWARDED_SSL'])
        && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
        return true;
    }
    return false;
}
