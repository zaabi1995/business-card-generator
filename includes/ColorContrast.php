<?php
/**
 * Luminance + contrast helpers for auto-flipping card field colors
 * against their background. WCAG 2.x relative-luminance formula.
 *
 * Use when a Fabric.js template renders over a brand-color background
 * and the field defaults (e.g. #111) become unreadable on dark hex.
 */
class ColorContrast
{
    /**
     * Return WCAG relative luminance of a hex color in the 0..1 range.
     * Accepts "#rgb", "#rrggbb", or bare variants. Returns 0.0 on parse
     * failure so callers can still pick a safe default.
     */
    public static function luminance(string $hex): float
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) return 0.0;

        $channel = function (int $n) {
            $s = $n / 255.0;
            return $s <= 0.03928 ? $s / 12.92 : pow(($s + 0.055) / 1.055, 2.4);
        };
        $r = $channel(hexdec(substr($hex, 0, 2)));
        $g = $channel(hexdec(substr($hex, 2, 2)));
        $b = $channel(hexdec(substr($hex, 4, 2)));
        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /**
     * Return '#ffffff' for dark backgrounds and '#111111' for light
     * backgrounds. Threshold 0.45 tuned so brand teal #009bc1 (lum
     * ~0.30) picks white and amber #fb0 (lum ~0.72) picks dark.
     */
    public static function readableOn(string $bgHex, float $threshold = 0.45): string
    {
        return self::luminance($bgHex) < $threshold ? '#ffffff' : '#111111';
    }

    /**
     * WCAG contrast ratio between two hex colors (1..21).
     */
    public static function ratio(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        $light = max($la, $lb);
        $dark  = min($la, $lb);
        return ($light + 0.05) / ($dark + 0.05);
    }
}
