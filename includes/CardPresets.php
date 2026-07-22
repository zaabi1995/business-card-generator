<?php
/**
 * CardPresets
 *
 * Built-in, auto-branded card designs. A company admin (or a BHD internal
 * provider operator) picks a preset; it bakes a front+back card for every
 * employee using the company logo + brand colours + the employee's own
 * bilingual data, and stores them as the live cards.
 *
 * The actual drawing lives in scripts/render-preset.py (SVG -> rsvg-convert,
 * Arabic shaped via Noto). This class is the PHP bridge: list presets, render
 * branded thumbnails, and apply a preset across a company.
 *
 * Colours are passed through ColorContrast::safeAccent (also mirrored in the
 * Python) so white text never lands on a near-white brand fill.
 */
require_once __DIR__ . '/ColorContrast.php';

class CardPresets
{
    /** id => [label, bilingual]. MUST mirror scripts/render-preset.py PRESETS. */
    const PRESETS = [
        'corp_left'      => ['Corporate Left Bar',   false],
        'centered_min'   => ['Centered Minimal',     false],
        'bold_band'      => ['Bold Header Band',     false],
        'split_v'        => ['Split Vertical',       false],
        'monogram'       => ['Monogram Modern',      false],
        'biling_stacked' => ['Bilingual Two-Column', true],
        'biling_corp'    => ['Bilingual Corporate',  true],
        'biling_band'    => ['Bilingual Band',       true],
        'gov_formal'     => ['Government Formal',     true],
        'biling_split'   => ['Bilingual Split',       true],
    ];

    public static function all(): array
    {
        $out = [];
        foreach (self::PRESETS as $id => [$label, $bil]) {
            $out[] = ['id' => $id, 'label' => $label, 'bilingual' => $bil];
        }
        return $out;
    }

    public static function exists(string $id): bool
    {
        return isset(self::PRESETS[$id]);
    }

    /** Absolute filesystem path of the company logo, or '' if none. */
    private static function logoFsPath(?array $theme): string
    {
        $p = $theme['logo_path'] ?? '';
        if (!$p) return '';
        if (strncmp($p, '/', 1) === 0) return BASE_DIR . $p;
        return BASE_DIR . '/' . ltrim($p, '/');
    }

    /** Brand context (colours + logo + org) for a company. */
    private static function companyBrand(array $company, ?array $theme): array
    {
        return [
            'logo'      => self::logoFsPath($theme),
            'primary'   => ColorContrast::safeAccent($theme['primary_color'] ?? '#204080'),
            'secondary' => ColorContrast::safeAccent($theme['secondary_color'] ?? '#00b060'),
            'org_en'    => $company['name'] ?? '',
            'org_ar'    => $company['name_ar'] ?? '',
        ];
    }

    /** Full brand context including one person's bilingual details. */
    public static function employeeBrand(array $company, ?array $theme, array $emp): array
    {
        $brand = self::companyBrand($company, $theme);
        $phone = trim((string)($emp['mobile'] ?? '')) ?: trim((string)($emp['phone'] ?? ''));
        return $brand + [
            'name_en'  => $emp['name_en'] ?? ($emp['name'] ?? ''),
            'name_ar'  => $emp['name_ar'] ?? '',
            'title_en' => $emp['position_en'] ?? ($emp['job_title'] ?? ''),
            'title_ar' => $emp['position_ar'] ?? '',
            'phone'    => $phone,
            'email'    => $emp['email'] ?? '',
            'website'  => $emp['website'] ?? ($company['website'] ?? ''),
        ];
    }

    /** Design-only brand (blank person text => renders just the decoration). */
    private static function designBrand(array $company, ?array $theme): array
    {
        return self::companyBrand($company, $theme) + [
            'name_en' => '', 'name_ar' => '', 'title_en' => '', 'title_ar' => '',
            'phone' => '', 'email' => '',
        ];
    }

    /**
     * Render one preset side to $outPath. Returns true on success.
     */
    public static function render(array $brand, string $presetId, string $side, string $outPath): bool
    {
        if (!self::exists($presetId)) return false;
        $tmp = tempnam(sys_get_temp_dir(), 'brand');
        if ($tmp === false) return false;
        file_put_contents($tmp, json_encode($brand, JSON_UNESCAPED_UNICODE));
        $py = trim((string)@shell_exec('command -v python3 2>/dev/null')) ?: 'python3';
        $cmd = '';
        if (trim((string)@shell_exec('command -v timeout 2>/dev/null')) !== '') {
            $cmd .= 'timeout 30 ';
        }
        $cmd .= escapeshellarg($py)
             . ' ' . escapeshellarg(BASE_DIR . '/scripts/render-preset.py')
             . ' --brand ' . escapeshellarg($tmp)
             . ' --out ' . escapeshellarg($outPath)
             . ' --preset ' . escapeshellarg($presetId)
             . ' --side ' . escapeshellarg($side)
             . ' 2>&1';
        $out = (string)@shell_exec($cmd);
        @unlink($tmp);
        if (!is_file($outPath) || filesize($outPath) < 256) {
            error_log("[CardPresets] render failed ($presetId/$side): " . substr($out, 0, 300));
            return false;
        }
        @chmod($outPath, 0644);
        return true;
    }

    /**
     * Branded front thumbnail for the gallery. Cached per company+preset and
     * busted by the theme's updated_at. Returns a web URL or '' on failure.
     */
    public static function thumbnailUrl(array $company, ?array $theme, ?array $sampleEmp = null): array
    {
        $cid = $company['id'];
        $dir = UPLOADS_DIR . '/companies/' . $cid . '/preset-thumbs';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $ver = substr(md5(($theme['updated_at'] ?? '') . ($theme['primary_color'] ?? '') . ($theme['logo_path'] ?? '')), 0, 8);
        $sample = $sampleEmp ?: [
            'name_en' => 'Your Name', 'name_ar' => 'الاسم الكامل',
            'position_en' => 'Job Title', 'position_ar' => 'المسمى الوظيفي',
            'phone' => '+968 0000 0000', 'email' => 'name@company.om',
        ];
        $brand = self::employeeBrand($company, $theme, $sample);
        $urls = [];
        foreach (self::PRESETS as $id => $_) {
            $file = $dir . '/' . $id . '_' . $ver . '.png';
            if (!is_file($file)) {
                self::render($brand, $id, 'front', $file);
            }
            $urls[$id] = is_file($file)
                ? '/uploads/companies/' . $cid . '/preset-thumbs/' . $id . '_' . $ver . '.png'
                : '';
        }
        return $urls;
    }

    /**
     * Filesystem path of a single branded front thumbnail (rendered + cached
     * on first call). For the lazy <img> endpoint so the gallery loads fast.
     */
    public static function thumbFile(array $company, ?array $theme, string $presetId, ?array $sampleEmp = null): ?string
    {
        if (!self::exists($presetId)) return null;
        $cid = $company['id'];
        $dir = UPLOADS_DIR . '/companies/' . $cid . '/preset-thumbs';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $ver = substr(md5(($theme['updated_at'] ?? '') . ($theme['primary_color'] ?? '')
            . ($theme['secondary_color'] ?? '') . ($theme['logo_path'] ?? '')), 0, 8);
        $file = $dir . '/' . $presetId . '_' . $ver . '.png';
        if (is_file($file) && filesize($file) > 256) return $file;
        $sample = $sampleEmp ?: [
            'name_en' => 'Your Name', 'name_ar' => 'الاسم الكامل',
            'position_en' => 'Job Title', 'position_ar' => 'المسمى الوظيفي',
            'phone' => '+968 0000 0000', 'email' => 'name@company.om',
        ];
        $brand = self::employeeBrand($company, $theme, $sample);
        return self::render($brand, $presetId, 'front', $file) ? $file : null;
    }

    /**
     * Apply a preset across a whole company: bake the design-only background
     * onto the active template pair, and bake every employee's full card.
     * Returns ['ok'=>bool, 'employees'=>int, 'error'=>?string].
     */
    /**
     * Render ONE employee's card via a named preset and bake it into
     * generated_cards, so the public card page / wallet / OG all show the design
     * the user picked in the app. Same file layout + row shape as apply(). Returns
     * true on success. Called from my-card.php and card-render.php.
     */
    public static function applyForEmployee(array $company, ?array $theme, array $emp, string $presetId): bool
    {
        if (!self::exists($presetId)) return false;
        // A managed brand is locked: a personal preset never overrides the
        // company's designed card (only the admin/super-admin sets that brand).
        if (!empty($theme['managed'])) return false;
        $db = Database::getInstance();
        $cid = (string) $company['id'];
        $cardsDir = function_exists('getCompanyCardsDir')
            ? getCompanyCardsDir($cid)
            : UPLOADS_DIR . '/companies/' . $cid . '/cards';
        if (!is_dir($cardsDir)) @mkdir($cardsDir, 0755, true);
        $brand  = self::employeeBrand($company, $theme, $emp);
        $design = self::designBrand($company, $theme);
        $stamp  = date('Ymd_His');
        $u  = substr(md5($emp['id'] . $stamp . mt_rand()), 0, 13);
        $fn = 'card_front_' . $stamp . '_' . $u . '.png';
        $bn = 'card_back_'  . $stamp . '_' . $u . '.png';
        $okF = self::render($brand, $presetId, 'front', $cardsDir . '/' . $fn);
        $okB = self::render($design, $presetId, 'back',  $cardsDir . '/' . $bn);
        if (!$okF) return false;
        $existing = $db->fetchOne(
            'SELECT id FROM generated_cards WHERE company_id = :c AND employee_id = :e',
            ['c' => $cid, 'e' => $emp['id']]
        );
        if (is_array($existing)) {
            $db->query(
                'UPDATE generated_cards SET front_file_path = :f, back_file_path = :b,
                   front_web_path = NULL, back_web_path = NULL, generated_at = NOW() WHERE id = :id',
                ['f' => $fn, 'b' => ($okB ? $bn : null), 'id' => $existing['id']]
            );
        } else {
            $db->insert('generated_cards', [
                'id' => function_exists('generateUUID') ? generateUUID() : bin2hex(random_bytes(16)),
                'company_id' => $cid, 'employee_id' => $emp['id'],
                'front_file_path' => $fn, 'back_file_path' => ($okB ? $bn : null),
                'generated_at' => date('Y-m-d H:i:s'),
            ]);
        }
        return true;
    }

    public static function apply(string $companyId, string $presetId): array
    {
        if (!self::exists($presetId)) return ['ok' => false, 'error' => 'unknown_preset'];
        $db = Database::getInstance();
        $company = $db->fetchOne("SELECT * FROM companies WHERE id = :id", ['id' => $companyId]);
        if (!$company) return ['ok' => false, 'error' => 'company_not_found'];
        $theme = function_exists('loadCompanyTheme') ? loadCompanyTheme($companyId)
               : $db->fetchOne("SELECT * FROM company_themes WHERE company_id = :id", ['id' => $companyId]);

        // 1. Design-only background -> active template pair.
        $tplDir = UPLOADS_DIR . '/companies/' . $companyId . '/templates';
        if (!is_dir($tplDir)) @mkdir($tplDir, 0755, true);
        $stamp = date('Ymd_His');
        $frontBg = $tplDir . '/preset_' . $presetId . '_front_' . $stamp . '.png';
        $backBg  = $tplDir . '/preset_' . $presetId . '_back_' . $stamp . '.png';
        $design = self::designBrand($company, $theme);
        self::render($design, $presetId, 'front', $frontBg);
        self::render($design, $presetId, 'back', $backBg);

        $pair = $db->fetchAll(
            "SELECT id, side FROM templates WHERE company_id = :c AND deleted_at IS NULL
             ORDER BY is_active DESC, created_at DESC",
            ['c' => $companyId]
        );
        $frontWeb = '/uploads/companies/' . $companyId . '/templates/' . basename($frontBg);
        $backWeb  = '/uploads/companies/' . $companyId . '/templates/' . basename($backBg);
        $fields = self::genericFieldsJson($theme);
        $didFront = $didBack = false;
        foreach ($pair as $t) {
            if ($t['side'] === 'front' && !$didFront && is_file($frontBg)) {
                $db->query("UPDATE templates SET background_image_path = :bg, fields_json = :fj,
                            current_version = current_version + 1, updated_at = NOW(),
                            description = :d WHERE id = :id",
                    ['bg' => $frontWeb, 'fj' => $fields, 'd' => 'preset:' . $presetId, 'id' => $t['id']]);
                $didFront = true;
            } elseif ($t['side'] === 'back' && !$didBack && is_file($backBg)) {
                $db->query("UPDATE templates SET background_image_path = :bg, fields_json = '{}',
                            current_version = current_version + 1, updated_at = NOW(),
                            description = :d WHERE id = :id",
                    ['bg' => $backWeb, 'd' => 'preset:' . $presetId, 'id' => $t['id']]);
                $didBack = true;
            }
        }

        // 2. Bake every employee's full card (this is what the digital card shows).
        $cardsDir = function_exists('getCompanyCardsDir')
            ? getCompanyCardsDir($companyId)
            : UPLOADS_DIR . '/companies/' . $companyId . '/cards';
        if (!is_dir($cardsDir)) @mkdir($cardsDir, 0755, true);
        $emps = $db->fetchAll(
            "SELECT * FROM employees WHERE company_id = :c AND deleted_at IS NULL",
            ['c' => $companyId]
        );
        $n = 0;
        foreach ($emps as $emp) {
            $brand = self::employeeBrand($company, $theme, $emp);
            $u = substr(md5($emp['id'] . $stamp . mt_rand()), 0, 13);
            $fn = 'card_front_' . $stamp . '_' . $u . '.png';
            $bn = 'card_back_' . $stamp . '_' . $u . '.png';
            $okF = self::render($brand, $presetId, 'front', $cardsDir . '/' . $fn);
            $okB = self::render($design, $presetId, 'back', $cardsDir . '/' . $bn);
            if (!$okF || !$okB) continue;
            $existing = $db->fetchOne(
                "SELECT id FROM generated_cards WHERE company_id = :c AND employee_id = :e",
                ['c' => $companyId, 'e' => $emp['id']]
            );
            if ($existing) {
                $db->query("UPDATE generated_cards SET front_file_path = :f, back_file_path = :b,
                            front_web_path = NULL, back_web_path = NULL, generated_at = NOW()
                            WHERE id = :id",
                    ['f' => $fn, 'b' => $bn, 'id' => $existing['id']]);
            } else {
                $db->insert('generated_cards', [
                    'id' => function_exists('generateUUID') ? generateUUID() : bin2hex(random_bytes(16)),
                    'company_id' => $companyId, 'employee_id' => $emp['id'],
                    'front_file_path' => $fn, 'back_file_path' => $bn,
                    'generated_at' => date('Y-m-d H:i:s'),
                ]);
            }
            $n++;
        }
        @shell_exec('chown -R www:www ' . escapeshellarg($cardsDir) . ' ' . escapeshellarg($tplDir) . ' 2>/dev/null');

        if (class_exists('CardRenderer')) {
            try { CardRenderer::invalidateForCompany($companyId, 'preset:' . $presetId); } catch (\Throwable $e) {}
        }
        return ['ok' => true, 'employees' => $n];
    }

    /**
     * A reasonable left-aligned dynamic field layout for the template's Fabric
     * /PDF fallback. The baked PNG (above) is the canonical display; this only
     * matters if an admin later re-runs the Fabric generator.
     */
    private static function genericFieldsJson(?array $theme): string
    {
        $p = ColorContrast::safeAccent($theme['primary_color'] ?? '#204080');
        $s = ColorContrast::safeAccent($theme['secondary_color'] ?? '#00b060');
        $f = function ($key, $y, $size, $fill, $bold) {
            return [$key => [
                'enabled' => true, 'x' => 70, 'y' => $y, 'fontSize' => $size,
                'fontFamily' => 'Inter', 'fontWeight' => $bold ? 'bold' : 'normal',
                'fill' => $fill, 'textAlign' => 'left', 'originX' => 'left', 'originY' => 'top',
            ]];
        };
        $fields = $f('name_en', 274, 46, $p, true)
            + $f('position_en', 334, 23, $s, false)
            + $f('phone', 446, 22, '#2b2b2b', false)
            + $f('email', 480, 22, '#2b2b2b', false);
        return json_encode($fields, JSON_UNESCAPED_UNICODE);
    }
}
