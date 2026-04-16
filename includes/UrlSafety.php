<?php
/**
 * UrlSafety — shared URL/host/IP safety helpers.
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
        // Disallow "//" (protocol-relative) — open redirect vector
        if (isset($dest[1]) && $dest[1] === '/') {
            return null;
        }
        if (preg_match('/[\r\n]/', $dest)) {
            return null;
        }
        return $dest;
    }

    $lower = strtolower($dest);

    // Non-http(s) schemes: tel:, mailto:, sms:, whatsapp: — accept as-is.
    $passthroughSchemes = ['tel:', 'mailto:', 'sms:', 'whatsapp:'];
    foreach ($passthroughSchemes as $prefix) {
        if (strpos($lower, $prefix) === 0) {
            if (preg_match('/[\r\n]/', $dest)) {
                return null;
            }
            return $dest;
        }
    }

    // https:// — validate URL, then check host whitelist.
    if (strpos($lower, 'https://') === 0) {
        if (!filter_var($dest, FILTER_VALIDATE_URL)) {
            return null;
        }
        $host = canonicalHostFromUrl($dest);
        if ($host === '') {
            return null;
        }
        // Reject URL shorteners unconditionally — never laundered even if on
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
    $candidates = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'HTTP_CLIENT_IP',
        'REMOTE_ADDR',
    ];
    foreach ($candidates as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}

/**
 * True if the current request is (effectively) HTTPS, taking proxy headers
 * into account. Cloudflare in "Flexible" mode hits us over HTTP but the
 * visitor is on HTTPS — so without this check cookies would be mint'd without
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
