<?php
/**
 * Cardify, React island loader
 *
 * Emits the tags for web-react/ (React 19 + Tailwind v4 + the Beautiful UI
 * kit). Call cardify_react_island() from any page that renders a
 * <div data-cardify-react="..."> mount point.
 *
 * Deliberately opt-in per page. The bundle is ~123 kB gzipped and only a
 * handful of surfaces use it; the other ~180 PHP pages must not pay for it, so
 * there is no global include.
 *
 * Fonts come from fonts.bhd.om, never fonts.googleapis.com (house rule), and
 * only on pages that mount the island.
 *
 * Build with:  cd web-react && npm run build
 * Output:      assets/react/cardify-react.{js,css}   (committed)
 */

if (!function_exists('cardify_react_island')) {
    /**
     * @param bool $preload Add preload hints. Worth it when the mount point is
     *                      above the fold; pointless (and wasteful) when the
     *                      surface sits far down the page.
     */
    function cardify_react_island(bool $preload = false): void
    {
        static $emitted = false;
        if ($emitted) {
            return; // a page may hold several mount points; one loader is enough
        }
        $emitted = true;

        // BASE_DIR is config.php's document-root constant (config.example.php:135).
        $docRoot = defined('BASE_DIR') ? BASE_DIR : dirname(__DIR__);
        $js  = '/assets/react/cardify-react.js';
        $css = '/assets/react/cardify-react.css';

        // Cache-bust on the built file's mtime so a deploy invalidates it and
        // an unchanged bundle stays cached.
        $ver = static function (string $rel) use ($docRoot): string {
            $abs = $docRoot . $rel;
            $t = is_readable($abs) ? filemtime($abs) : false;
            return $t === false ? '' : ('?v=' . $t);
        };

        $jsUrl  = htmlspecialchars($js . $ver($js), ENT_QUOTES, 'UTF-8');
        $cssUrl = htmlspecialchars($css . $ver($css), ENT_QUOTES, 'UTF-8');
        $fonts  = 'https://fonts.bhd.om/css2?family=Inter:wght@400;500;600;700'
                . '&family=JetBrains+Mono:wght@400;500&display=swap';

        echo "\n<!-- Beautiful UI island (web-react/) -->\n";
        echo '<link rel="stylesheet" href="' . $fonts . '">' . "\n";
        echo '<link rel="stylesheet" href="' . $cssUrl . '">' . "\n";
        if ($preload) {
            echo '<link rel="modulepreload" href="' . $jsUrl . '">' . "\n";
        }
        echo '<script defer src="' . $jsUrl . '"></script>' . "\n";
    }
}

if (!function_exists('cardify_react_mount')) {
    /**
     * A mount point for a registered surface (see SURFACES in web-react/src/main.tsx).
     *
     * @param array<string,mixed> $props Serialised to data-props and read by the
     *                                   island. JSON-encoded and attribute-escaped,
     *                                   so callers may pass DB values directly.
     */
    function cardify_react_mount(string $surface, array $props = []): void
    {
        $json = json_encode($props, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        printf(
            '<div data-cardify-react="%s"%s></div>',
            htmlspecialchars($surface, ENT_QUOTES, 'UTF-8'),
            $props ? ' data-props="' . htmlspecialchars((string) $json, ENT_QUOTES, 'UTF-8') . '"' : ''
        );
    }
}
