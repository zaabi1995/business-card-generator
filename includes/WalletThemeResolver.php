<?php
require_once __DIR__ . '/WalletThemeCatalog.php';
require_once __DIR__ . '/WalletThemePolicy.php';

/**
 * Resolves the final Wallet appearance for one existing Cardify profile.
 */
class WalletThemeResolver
{
    public static function forEmployee(
        array $employee,
        array $company,
        ?array $companyTheme,
        ?callable $preferenceResolver = null
    ): array {
        $employeeId = trim((string) ($employee['id'] ?? ''));
        $companyId = trim((string) (
            $company['id'] ?? ($employee['company_id'] ?? '')
        ));
        if ($employeeId === '' || $companyId === '') {
            throw new InvalidArgumentException('profile_forbidden');
        }
        try {
            $preference = $preferenceResolver !== null
                ? $preferenceResolver($employeeId, $companyId)
                : WalletThemeCatalog::resolvePreference(
                    $employeeId,
                    $companyId
                );
            if (!is_array($preference['resolved'] ?? null)) {
                throw new InvalidArgumentException('wallet_theme_invalid');
            }
            $resolved = WalletThemePolicy::validateTheme(
                $preference['resolved']
            );
            $resolved['source'] = (string) (
                $preference['source'] ?? 'selected'
            );
            $resolved['theme_id'] = isset($preference['theme_id'])
                && $preference['theme_id'] !== ''
                ? (string) $preference['theme_id']
                : null;
            return $resolved;
        } catch (Throwable $e) {
            error_log(
                '[WalletThemeResolver] using company fallback for profile '
                . $employeeId
            );
            return self::companyFallback($companyId, $companyTheme);
        }
    }

    private static function companyFallback(
        string $companyId,
        ?array $companyTheme
    ): array {
        $background = '#009bc1';
        try {
            if (
                is_array($companyTheme)
                && is_string($companyTheme['primary_color'] ?? null)
            ) {
                $background = WalletThemePolicy::normalizeColor(
                    (string) $companyTheme['primary_color']
                );
            }
        } catch (InvalidArgumentException $e) {
            $background = '#009bc1';
        }
        $text = WalletThemePolicy::contrastRatio(
            $background,
            '#ffffff'
        ) >= 3.0 ? '#ffffff' : '#111827';
        return WalletThemePolicy::validateTheme([
            'id' => null,
            'theme_id' => null,
            'company_id' => $companyId,
            'name_en' => 'Company',
            'name_ar' => 'الشركة',
            'style' => 'eventTicket',
            'background_color' => $background,
            'foreground_color' => $text,
            'label_color' => $text,
            'logo_mode' => 'company',
            'preview_url' => null,
            'source' => 'company_default',
        ]);
    }
}
