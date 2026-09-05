<?php
/**
 * Cardify security headers (Category Q actions 412-415).
 *
 * Call SecurityHeaders::send() early, before any output. Idempotent;
 * no-op once headers have flushed.
 *
 * What it sets:
 *   - Content-Security-Policy (report-only first, then enforce):
 *     default-src 'self'; script-src with per-request nonce + trusted
 *     CDNs (Tailwind, Fabric.js, Alpine, jsPDF, JSZip, PDF-lib,
 *     Google Fonts, Paymob iframe, Dardasha). We ship as
 *     report-only for the first deploy so existing inline scripts
 *     don't break; promote to enforcing once the reports come back
 *     clean.
 *   - Strict-Transport-Security: 2 years + includeSubDomains + preload
 *     (action 413).
 *   - X-Frame-Options: DENY (embed exceptions handled per-page via
 *     SecurityHeaders::allowFrameFor) (action 414).
 *   - X-Content-Type-Options: nosniff.
 *   - Referrer-Policy: strict-origin-when-cross-origin.
 *   - Permissions-Policy: geolocation, camera, microphone off by
 *     default (future widgets can relax per-page).
 *   - Cross-Origin-Opener-Policy: same-origin.
 *   - Cookie reinforcement: SessionCookieParams with HttpOnly +
 *     Secure (when HTTPS) + SameSite=Lax if session_start not yet
 *     called (action 415).
 *
 * Per-request CSP nonce is available via SecurityHeaders::nonce() so
 * future inline <script nonce="..."> blocks can satisfy a strict CSP.
 */
class SecurityHeaders
{
    private static ?string $nonce = null;
    private static bool $framingAllowed = false;

    public static function nonce(): string
    {
        if (self::$nonce === null) {
            self::$nonce = bin2hex(random_bytes(16));
        }
        return self::$nonce;
    }

    /** Call before any header/output. Honours CSP_REPORT_ONLY constant. */
    public static function send(): void
    {
        if (headers_sent()) return;

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        // Session cookie hardening (action 415). Only works when a session
        // hasn't started yet; otherwise ini_set silently no-ops.
        if (session_status() === PHP_SESSION_NONE) {
            $params = [
                'lifetime' => 0,
                'path'     => '/',
                'secure'   => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ];
            // Scope the cookie to the apex so `ohb.cardify.om` + bare
            // `cardify.om` share the same session. Skips when the host
            // is an IP, localhost, or doesn't match the expected apex.
            $apex = defined('APP_HOST') ? APP_HOST : 'cardify.om';
            $host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
            if ($apex !== '' && ($host === $apex || str_ends_with($host, '.' . $apex))) {
                $params['domain'] = '.' . $apex;
            }
            // PHP 7.3+ array form.
            @session_set_cookie_params($params);
        }

        // HSTS only on https so local dev isn't pinned.
        if ($isHttps) {
            header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload');
        }

        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(self), payment=(self "https://oman.paymob.com")');
        header('Cross-Origin-Opener-Policy: same-origin');

        if (!self::$framingAllowed) {
            header('X-Frame-Options: DENY');
            header('Content-Security-Policy-Report-Only: frame-ancestors \'none\'', false);
        }

        $nonce = self::nonce();
        $csp = self::buildCsp($nonce);
        $cspHeader = defined('CSP_REPORT_ONLY') && CSP_REPORT_ONLY
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';
        header("$cspHeader: $csp");
    }

    /** Per-page relaxation: /logo-download + /card-pdf want their own iframe embeds allowed. */
    public static function allowFrameFor(array $origins = []): void
    {
        self::$framingAllowed = true;
        if (headers_sent()) return;
        if (!empty($origins)) {
            $src = implode(' ', array_map(fn($o) => "'$o'", array_filter($origins, 'strlen')));
            header("Content-Security-Policy: frame-ancestors 'self' $src", false);
        } else {
            header("Content-Security-Policy: frame-ancestors 'self'", false);
        }
    }

    private static function buildCsp(string $nonce): string
    {

        // Built from what the estate actually loads, measured across 19 public
        // pages on 5 Sep 2026 rather than guessed: self, design.bhd.om,
        // fonts.bhd.om, cdn.jsdelivr.net, the Google Maps embed, Cloudflare
        // insights, GA, reCAPTCHA and Paymob.
        //
        // script-src carries a per-request nonce and NO 'unsafe-inline'.
        //
        // It could not, until 5 Sep 2026. The estate held 224 on* attribute
        // handlers across 76 files and 117 executable inline <script> blocks,
        // and a nonce silently disables 'unsafe-inline' for every browser that
        // understands it, so shipping one would have blanked the site. Both
        // were cleared first: every handler moved to a data attribute served by
        // assets/js/cardify-actions.js, and every inline block now carries
        // cspNonceAttr(). tests/php/csp_contract_test.php fails if either
        // comes back.
        //
        // An injected inline <script> therefore does not run, which is the
        // class the stored XSS on the public digital card belonged to. An
        // injected <script src="https://evil/"> is refused too, so is an
        // injected <object> or <base> and a cross-origin form post, and
        // frame-ancestors closes clickjacking.
        //
        // 'unsafe-eval' remains, and the reason is measured rather than
        // assumed: see the note on the script list below.
        $hosts = [
            'script' => [
                // 'unsafe-eval' is here because Alpine 3.15.12 compiles every
                // x- binding with `new Function`. Shipping without it blanked
                // the interactive layer across the whole site: "Alpine
                // Expression Error: Evaluating a string as JavaScript violates
                // the following Content Security Policy directive" on 16 of 22
                // pages, one minute after deploy.
                //
                // The alternative is @alpinejs/csp, which does not evaluate
                // expressions at all. It accepts only a bare property path or a
                // method name, so every expression with an operator, a call or
                // a literal has to become a method on a component registered
                // through Alpine.data(). Counted on this tree: 1,175 Alpine
                // expression attributes, of which 828 are not a bare property
                // path, across 55 x-data roots of which 26 are inline object
                // literals that the CSP build cannot parse either. admin/index
                // alone holds 235. That is the exact remaining dependency, and
                // it is a rewrite, not a header change.
                "'self'", "'nonce-" . $nonce . "'", "'unsafe-eval'",
                'https://design.bhd.om',
                'https://cdn.jsdelivr.net',
                'https://cdnjs.cloudflare.com',
                'https://static.cloudflareinsights.com',
                'https://www.googletagmanager.com',
                'https://www.google-analytics.com',
                'https://www.google.com',
                'https://www.gstatic.com',
                'https://maps.googleapis.com',
                'https://maps.gstatic.com',
                'https://oman.paymob.com',
            ],
            // No fonts.googleapis.com or fonts.gstatic.com. The house rule is
            // fonts.bhd.om, and the measurement found zero loads from Google's
            // font hosts, so allowing them would only invite drift back.
            'style' => [
                "'self'", "'unsafe-inline'",
                'https://fonts.bhd.om',
                'https://design.bhd.om',
                'https://cdnjs.cloudflare.com',
                'https://cdn.jsdelivr.net',
            ],
            'font' => [
                "'self'", 'data:',
                'https://fonts.bhd.om',
                'https://design.bhd.om',
                'https://cdnjs.cloudflare.com',
                'https://cdn.jsdelivr.net',
            ],
            'img' => [
                "'self'", 'data:', 'blob:',
                'https://cardify.om',
                'https://*.cardify.om',
                'https://design.bhd.om',
                'https://fonts.bhd.om',
                'https://cdn.jsdelivr.net',
                'https://chart.googleapis.com',
                'https://maps.googleapis.com',
                'https://maps.gstatic.com',
                // Named hosts, not https://*.googleapis.com and
                // https://*.gstatic.com. Those wildcards were broad enough to
                // re-admit fonts.gstatic.com through the back door, which is the
                // host the style-src note above says the house does not use.
                'https://www.google-analytics.com',
                'https://www.googletagmanager.com',
            ],
            'connect' => [
                "'self'",
                'https://cardify.om',
                'https://*.cardify.om',
                'https://design.bhd.om',
                'https://www.google-analytics.com',
                'https://region1.google-analytics.com',
                'https://*.google-analytics.com',
                'https://www.googletagmanager.com',
                'https://www.google.com',
                'https://maps.googleapis.com',
                'https://cloudflareinsights.com',
                'https://static.cloudflareinsights.com',
                'https://oman.paymob.com',
            ],
            'frame' => [
                "'self'",
                'https://oman.paymob.com',
                'https://www.google.com',
                'https://www.google.com/maps/',
                'https://maps.google.com',
            ],
            'media'    => ["'self'", 'data:', 'blob:'],
            'object'   => ["'none'"],
        ];

        $parts = [
            "default-src 'self'",
            "base-uri 'self'",
            "form-action 'self' https://oman.paymob.com",
            "frame-ancestors 'self'",
            'script-src '  . implode(' ', $hosts['script']),
            'style-src '   . implode(' ', $hosts['style']),
            'font-src '    . implode(' ', $hosts['font']),
            'img-src '     . implode(' ', $hosts['img']),
            'connect-src ' . implode(' ', $hosts['connect']),
            'frame-src '   . implode(' ', $hosts['frame']),
            'media-src '   . implode(' ', $hosts['media']),
            'object-src '  . implode(' ', $hosts['object']),
            'upgrade-insecure-requests',
        ];
        return implode('; ', $parts);
    }
}

if (!function_exists('cspNonceAttr')) {
    /**
     * The nonce attribute for an inline <script>, or '' when no policy is set.
     *
     * Defined here as well as in includes/functions.php, both guarded, because
     * three shared partials that emit inline script (Currency.php,
     * printshop-cancel-modal.php, JsonLd.php) are pulled in by entry points
     * that do not all load functions.php. An undefined function here would be
     * a fatal on the page, not a missing attribute.
     */
    function cspNonceAttr(): string
    {
        return ' nonce="' . htmlspecialchars(SecurityHeaders::nonce(), ENT_QUOTES) . '"';
    }
}
