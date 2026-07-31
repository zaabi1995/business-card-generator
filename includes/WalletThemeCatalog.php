<?php
require_once __DIR__ . '/WalletThemePolicy.php';

/**
 * Shared database authority for website and mobile Wallet appearance.
 */
class WalletThemeCatalog
{
    public static function listForProfile(
        string $employeeId,
        string $companyId
    ): array {
        if ($employeeId === '' || $companyId === '') {
            return [];
        }
        $rows = Database::getInstance()->fetchAll(
            "SELECT *
               FROM wallet_themes
              WHERE is_active = 1
                AND (company_id IS NULL OR company_id = :company_id)
              ORDER BY (company_id = :company_order) DESC,
                       is_default DESC,
                       sort_order ASC,
                       name_en ASC",
            [
                'company_id' => $companyId,
                'company_order' => $companyId,
            ]
        );
        $themes = [];
        foreach ($rows as $row) {
            try {
                $themes[] = self::publicTheme(
                    WalletThemePolicy::validateTheme($row)
                );
            } catch (InvalidArgumentException $e) {
                error_log(
                    '[WalletThemeCatalog] invalid published theme '
                    . (string) ($row['id'] ?? '')
                );
            }
        }
        return $themes;
    }

    public static function resolvePreference(
        string $employeeId,
        string $companyId
    ): array {
        $db = Database::getInstance();
        $preference = $db->fetchOne(
            "SELECT wallet_theme_id, overrides_json, updated_at
               FROM profile_wallet_preferences
              WHERE employee_id = :employee_id AND company_id = :company_id
              LIMIT 1",
            [
                'employee_id' => $employeeId,
                'company_id' => $companyId,
            ]
        );
        $overrides = self::decodeObject(
            is_array($preference) ? ($preference['overrides_json'] ?? null) : null
        );
        $themeId = is_array($preference)
            ? trim((string) ($preference['wallet_theme_id'] ?? ''))
            : '';
        $theme = $themeId !== ''
            ? self::findVisibleTheme($themeId, $companyId)
            : null;
        $source = 'selected';
        if ($theme === null) {
            $theme = self::defaultTheme($companyId);
            $source = (string) ($theme['_source'] ?? 'cardify_default');
        }
        unset($theme['_source']);
        $resolved = WalletThemePolicy::validateTheme(
            array_merge($theme, WalletThemePolicy::validateOverrides($overrides))
        );
        return [
            'theme_id' => $themeId !== '' && $source === 'selected'
                ? $themeId
                : ($resolved['id'] ?? null),
            'overrides' => WalletThemePolicy::validateOverrides($overrides),
            'resolved' => self::publicTheme($resolved),
            'source' => $source,
            'updated_at' => is_array($preference)
                ? ($preference['updated_at'] ?? null)
                : null,
        ];
    }

    public static function savePreference(
        string $employeeId,
        string $companyId,
        string $themeId,
        array $overrides
    ): array {
        if ($employeeId === '' || $companyId === '') {
            throw new InvalidArgumentException('profile_forbidden');
        }
        $themeId = trim($themeId);
        $normalizedOverrides = WalletThemePolicy::validateOverrides($overrides);
        $db = Database::getInstance();
        $pdo = $db->getConnection();
        try {
            $pdo->beginTransaction();
            $db->fetchOne(
                "SELECT employee_id
                   FROM profile_wallet_preferences
                  WHERE employee_id = :employee_id AND company_id = :company_id
                  FOR UPDATE",
                [
                    'employee_id' => $employeeId,
                    'company_id' => $companyId,
                ]
            );
            $theme = $themeId === ''
                ? self::defaultTheme($companyId)
                : $db->fetchOne(
                    "SELECT *
                       FROM wallet_themes
                      WHERE id = :theme_id AND is_active = 1
                      FOR UPDATE",
                    ['theme_id' => $themeId]
                );
            if (
                !is_array($theme)
                || !WalletThemePolicy::isVisible(
                    isset($theme['company_id'])
                        ? (string) $theme['company_id']
                        : null,
                    $companyId
                )
            ) {
                throw new InvalidArgumentException('wallet_theme_not_found');
            }
            unset($theme['_source']);
            WalletThemePolicy::validateTheme(
                array_merge($theme, $normalizedOverrides)
            );
            $statement = $pdo->prepare(
                "INSERT INTO profile_wallet_preferences
                    (employee_id, company_id, wallet_theme_id, overrides_json, updated_at)
                 VALUES
                    (:employee_id, :company_id, :theme_id, :overrides_json, NOW())
                 ON DUPLICATE KEY UPDATE
                    company_id = VALUES(company_id),
                    wallet_theme_id = VALUES(wallet_theme_id),
                    overrides_json = VALUES(overrides_json),
                    updated_at = NOW()"
            );
            $statement->execute([
                'employee_id' => $employeeId,
                'company_id' => $companyId,
                'theme_id' => $themeId !== '' ? $themeId : null,
                'overrides_json' => json_encode(
                    $normalizedOverrides,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return self::resolvePreference($employeeId, $companyId);
    }

    private static function findVisibleTheme(
        string $themeId,
        string $companyId
    ): ?array {
        $row = Database::getInstance()->fetchOne(
            "SELECT *
               FROM wallet_themes
              WHERE id = :theme_id AND is_active = 1
              LIMIT 1",
            ['theme_id' => $themeId]
        );
        if (!is_array($row)) {
            return null;
        }
        $themeCompanyId = isset($row['company_id'])
            ? (string) $row['company_id']
            : null;
        return WalletThemePolicy::isVisible($themeCompanyId, $companyId)
            ? $row
            : null;
    }

    private static function defaultTheme(string $companyId): array
    {
        $db = Database::getInstance();
        $row = $db->fetchOne(
            "SELECT *
               FROM wallet_themes
              WHERE company_id = :company_id
                AND is_active = 1
                AND is_default = 1
              ORDER BY sort_order ASC, updated_at DESC
              LIMIT 1",
            ['company_id' => $companyId]
        );
        if (is_array($row)) {
            $row['_source'] = 'company_default';
            return $row;
        }
        $row = $db->fetchOne(
            "SELECT *
               FROM wallet_themes
              WHERE company_id IS NULL
                AND is_active = 1
                AND is_default = 1
              ORDER BY sort_order ASC, updated_at DESC
              LIMIT 1"
        );
        if (is_array($row)) {
            $row['_source'] = 'cardify_default';
            return $row;
        }
        $companyTheme = $db->fetchOne(
            "SELECT primary_color
               FROM company_themes
              WHERE company_id = :company_id
              LIMIT 1",
            ['company_id' => $companyId]
        );
        $background = '#009bc1';
        if (
            is_array($companyTheme)
            && is_string($companyTheme['primary_color'] ?? null)
        ) {
            try {
                $background = WalletThemePolicy::normalizeColor(
                    (string) $companyTheme['primary_color']
                );
            } catch (InvalidArgumentException $e) {
                $background = '#009bc1';
            }
        }
        $whiteContrast = WalletThemePolicy::contrastRatio(
            $background,
            '#ffffff'
        );
        $text = $whiteContrast >= 3.0 ? '#ffffff' : '#111827';
        return [
            'id' => null,
            'company_id' => $companyId,
            'name_en' => 'Company',
            'name_ar' => 'الشركة',
            'style' => 'eventTicket',
            'background_color' => $background,
            'foreground_color' => $text,
            'label_color' => $text,
            'logo_mode' => 'company',
            'preview_path' => null,
            '_source' => 'company_theme',
        ];
    }

    private static function decodeObject($value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function publicTheme(array $theme): array
    {
        $previewPath = trim((string) ($theme['preview_path'] ?? ''));
        $previewUrl = null;
        if ($previewPath !== '') {
            $previewUrl = function_exists('imageUrl')
                ? imageUrl($previewPath)
                : $previewPath;
        }
        return [
            'id' => isset($theme['id']) && $theme['id'] !== ''
                ? (string) $theme['id']
                : null,
            'company_id' => isset($theme['company_id'])
                && $theme['company_id'] !== ''
                ? (string) $theme['company_id']
                : null,
            'name_en' => (string) ($theme['name_en'] ?? 'Wallet'),
            'name_ar' => (string) ($theme['name_ar'] ?? ''),
            'style' => (string) $theme['style'],
            'background_color' => (string) $theme['background_color'],
            'foreground_color' => (string) $theme['foreground_color'],
            'label_color' => (string) $theme['label_color'],
            'logo_mode' => (string) $theme['logo_mode'],
            'preview_url' => $previewUrl,
        ];
    }
}
