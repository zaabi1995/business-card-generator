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
        // Known third-party hosts used across the app. Report-only first
        // so nothing blocks; tighten over time.
        $hosts = [
            'script' => [
                "'self'", "'nonce-$nonce'",
                'https://cdn.tailwindcss.com',
                'https://cdnjs.cloudflare.com',
                'https://cdn.jsdelivr.net',
                'https://unpkg.com',
                'https://www.googletagmanager.com',
                'https://www.google-analytics.com',
                'https://oman.paymob.com',
                'https://chart.googleapis.com',
            ],
            'style' => [
                "'self'", "'unsafe-inline'",
                'https://fonts.bhd.om',
                'https://cdnjs.cloudflare.com',
                'https://cdn.jsdelivr.net',
                'https://unpkg.com',
                'https://cdn.tailwindcss.com',
            ],
            'font' => [
                "'self'", 'data:',
                'https://fonts.bhd.om',
                'https://cdnjs.cloudflare.com',
                'https://cdn.jsdelivr.net', // FA Pro webfonts via rizmyabdulla mirror
            ],
            'img' => [
                "'self'", 'data:', 'blob:',
                'https://cardify.om',
                'https://*.cardify.om',
                'https://chart.googleapis.com',
                'https://www.google-analytics.com',
                'https://cdn.jsdelivr.net',
            ],
            'connect' => [
                "'self'",
                'https://cardify.om',
                'https://*.cardify.om',
                'https://www.google-analytics.com',
                'https://region1.google-analytics.com',
                'https://oman.paymob.com',
            ],
            'frame' => [
                "'self'",
                'https://oman.paymob.com',
            ],
            'media'    => ["'self'", 'data:', 'blob:'],
            'object'   => ["'none'"],
        ];

        $parts = [
            "default-src 'self'",
            'base-uri \'self\'',
            'form-action \'self\' https://oman.paymob.com',
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
