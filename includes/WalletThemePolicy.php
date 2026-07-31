<?php
/**
 * Pure validation and tenant-scope policy for Apple Wallet appearance.
 */
class WalletThemePolicy
{
    private const STYLES = ['eventTicket', 'storeCard', 'generic'];
    private const LOGO_MODES = ['company', 'cardify', 'none'];
    private const OVERRIDE_KEYS = [
        'background_color',
        'foreground_color',
        'label_color',
        'logo_mode',
    ];

    public static function isVisible(?string $themeCompanyId, string $profileCompanyId): bool
    {
        return $themeCompanyId === null
            || (
                $themeCompanyId !== ''
                && $profileCompanyId !== ''
                && hash_equals($themeCompanyId, $profileCompanyId)
            );
    }

    public static function normalizeColor(string $value): string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^#[0-9a-f]{3}$/', $value) === 1) {
            return '#'
                . $value[1] . $value[1]
                . $value[2] . $value[2]
                . $value[3] . $value[3];
        }
        if (preg_match('/^#[0-9a-f]{6}$/', $value) !== 1) {
            throw new InvalidArgumentException('wallet_theme_invalid');
        }
        return $value;
    }

    public static function rgb(string $hex): string
    {
        $hex = self::normalizeColor($hex);
        return sprintf(
            'rgb(%d, %d, %d)',
            hexdec(substr($hex, 1, 2)),
            hexdec(substr($hex, 3, 2)),
            hexdec(substr($hex, 5, 2))
        );
    }

    public static function validateTheme(array $theme): array
    {
        $style = trim((string) ($theme['style'] ?? ''));
        $logoMode = trim((string) ($theme['logo_mode'] ?? ''));
        if (
            !in_array($style, self::STYLES, true)
            || !in_array($logoMode, self::LOGO_MODES, true)
        ) {
            throw new InvalidArgumentException('wallet_theme_invalid');
        }
        $background = self::normalizeColor(
            (string) ($theme['background_color'] ?? '')
        );
        $foreground = self::normalizeColor(
            (string) ($theme['foreground_color'] ?? '')
        );
        $label = self::normalizeColor((string) ($theme['label_color'] ?? ''));
        if (
            self::contrastRatio($background, $foreground) < 3.0
            || self::contrastRatio($background, $label) < 3.0
        ) {
            throw new InvalidArgumentException('wallet_theme_invalid');
        }
        return array_merge($theme, [
            'style' => $style,
            'background_color' => $background,
            'foreground_color' => $foreground,
            'label_color' => $label,
            'logo_mode' => $logoMode,
        ]);
    }

    public static function validateOverrides(array $overrides): array
    {
        foreach (array_keys($overrides) as $key) {
            if (!in_array($key, self::OVERRIDE_KEYS, true)) {
                throw new InvalidArgumentException('wallet_theme_invalid');
            }
        }
        $normalized = [];
        foreach (['background_color', 'foreground_color', 'label_color'] as $key) {
            if (array_key_exists($key, $overrides)) {
                $normalized[$key] = self::normalizeColor((string) $overrides[$key]);
            }
        }
        if (array_key_exists('logo_mode', $overrides)) {
            $logoMode = trim((string) $overrides['logo_mode']);
            if (!in_array($logoMode, self::LOGO_MODES, true)) {
                throw new InvalidArgumentException('wallet_theme_invalid');
            }
            $normalized['logo_mode'] = $logoMode;
        }
        if (
            isset($normalized['background_color'], $normalized['foreground_color'])
            && self::contrastRatio(
                $normalized['background_color'],
                $normalized['foreground_color']
            ) < 3.0
        ) {
            throw new InvalidArgumentException('wallet_theme_invalid');
        }
        if (
            isset($normalized['background_color'], $normalized['label_color'])
            && self::contrastRatio(
                $normalized['background_color'],
                $normalized['label_color']
            ) < 3.0
        ) {
            throw new InvalidArgumentException('wallet_theme_invalid');
        }
        return $normalized;
    }

    public static function contrastRatio(string $first, string $second): float
    {
        $light = self::relativeLuminance($first);
        $dark = self::relativeLuminance($second);
        if ($dark > $light) {
            [$light, $dark] = [$dark, $light];
        }
        return ($light + 0.05) / ($dark + 0.05);
    }

    private static function relativeLuminance(string $hex): float
    {
        $hex = self::normalizeColor($hex);
        $channels = [
            hexdec(substr($hex, 1, 2)) / 255,
            hexdec(substr($hex, 3, 2)) / 255,
            hexdec(substr($hex, 5, 2)) / 255,
        ];
        foreach ($channels as &$channel) {
            $channel = $channel <= 0.04045
                ? $channel / 12.92
                : (($channel + 0.055) / 1.055) ** 2.4;
        }
        unset($channel);
        return (0.2126 * $channels[0])
            + (0.7152 * $channels[1])
            + (0.0722 * $channels[2]);
    }
}
