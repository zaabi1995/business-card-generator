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

    /**
     * Guard a brand accent that is used BOTH as a button fill behind white
     * text AND as a text/icon colour on a light card. A near-white accent
     * (e.g. a logo's white background auto-picked as primary_color by the
     * onboarding wizard) renders the Call / Save Contact buttons and section
     * headers invisible.
     *
     * Only intervenes when the accent is clearly too light (contrast with
     * white below $trigger); then darkens it along its own hue until it
     * reaches $target, matching the readability of the platform default
     * teal #009bc1 (ratio ~3.0 on white). Good brand colours, teal, navy,
     * green, etc., pass through untouched so existing tenants don't shift.
     *
     * @param string $hex     Candidate accent, "#rrggbb" / "#rgb" / bare.
     * @param float  $trigger Contrast-with-white below which we step in.
     * @param float  $target  Contrast-with-white we darken up to.
     * @return string         A safe "#rrggbb" accent (original when fine).
     */
    public static function safeAccent(string $hex, float $trigger = 2.5, float $target = 3.2): string
    {
        $clean = ltrim(trim($hex), '#');
        if (strlen($clean) === 3) {
            $clean = $clean[0] . $clean[0] . $clean[1] . $clean[1] . $clean[2] . $clean[2];
        }
        if (strlen($clean) !== 6 || !ctype_xdigit($clean)) {
            return '#009bc1'; // unparseable, fall back to platform teal
        }
        if (self::ratio('#' . $clean, '#ffffff') >= $trigger) {
            return '#' . $clean; // already readable, leave the brand colour alone
        }

        $r = hexdec(substr($clean, 0, 2));
        $g = hexdec(substr($clean, 2, 2));
        $b = hexdec(substr($clean, 4, 2));
        // A near-white neutral grey has no hue worth preserving, anchor it to
        // a calm slate so the result still looks deliberate, not muddy.
        if (abs($r - $g) < 8 && abs($g - $b) < 8 && abs($r - $b) < 8) {
            return '#334155';
        }
        for ($i = 0; $i < 40; $i++) {
            $cur = sprintf('#%02x%02x%02x', $r, $g, $b);
            if (self::ratio($cur, '#ffffff') >= $target) {
                return $cur;
            }
            $r = (int) floor($r * 0.9);
            $g = (int) floor($g * 0.9);
            $b = (int) floor($b * 0.9);
            if ($r + $g + $b === 0) {
                return '#1f2937';
            }
        }
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }
}
