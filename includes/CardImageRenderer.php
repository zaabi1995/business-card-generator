<?php

class CardRenderException extends RuntimeException
{
    private $renderErrorCode;
    private $renderOperationId;

    public function __construct(string $errorCode, string $operationId, ?Throwable $previous = null)
    {
        parent::__construct($errorCode, 0, $previous);
        $this->renderErrorCode = $errorCode;
        $this->renderOperationId = $operationId;
    }

    public function errorCode(): string
    {
        return $this->renderErrorCode;
    }

    public function operationId(): string
    {
        return $this->renderOperationId;
    }
}

class CardImageRenderer
{
    public static function renderAndPromote(string $employeeId, string $reason): array
    {
        $operationId = self::operationId();
        $stagingDir = BASE_DIR . '/storage/generated-staging/' . $operationId;
        $newFinalFiles = [];
        $transactionStarted = false;
        $db = Database::getInstance();

        try {
            if ($employeeId === '') {
                throw new RuntimeException('employee_required');
            }
            self::ensureDirectory($stagingDir);
            $context = self::resolvedContext($db, $employeeId);
            $inputPath = $stagingDir . '/input.json';
            $encoded = json_encode(
                $context['payload'],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
            if (file_put_contents($inputPath, $encoded, LOCK_EX) === false) {
                throw new RuntimeException('render_input_write_failed');
            }

            $script = BASE_DIR . '/scripts/render-card-images.py';
            $python = defined('PYTHON_BINARY') && PYTHON_BINARY !== ''
                ? PYTHON_BINARY
                : 'python3';
            $command = escapeshellarg($python)
                . ' ' . escapeshellarg($script)
                . ' --input ' . escapeshellarg($inputPath)
                . ' --out-dir ' . escapeshellarg($stagingDir)
                . ' 2>&1';
            $output = [];
            $exitCode = 0;
            exec($command, $output, $exitCode);
            $result = self::decodeProcessResult($output, $exitCode);
            $frontStaged = self::validatedStagedImage($result['front'] ?? '', $stagingDir, 'front', $context['front_template'] ?? null);
            $backStaged = self::validatedStagedImage($result['back'] ?? '', $stagingDir, 'back', $context['back_template'] ?? null);

            $companyId = (string)$context['company']['id'];
            $finalDir = UPLOADS_DIR . '/companies/' . $companyId . '/cards';
            self::ensureDirectory($finalDir);
            $baseName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $employeeId);
            $frontName = 'card_' . $baseName . '_' . $operationId . '_front.webp';
            $backName = 'card_' . $baseName . '_' . $operationId . '_back.webp';
            $frontFinal = $finalDir . '/' . $frontName;
            $backFinal = $finalDir . '/' . $backName;
            $frontStored = '/uploads/companies/' . $companyId . '/cards/' . $frontName;
            $backStored = '/uploads/companies/' . $companyId . '/cards/' . $backName;

            $db->beginTransaction();
            $transactionStarted = true;
            if (!rename($frontStaged, $frontFinal)) {
                throw new RuntimeException('front_promotion_failed');
            }
            $newFinalFiles[] = $frontFinal;
            if (!rename($backStaged, $backFinal)) {
                throw new RuntimeException('back_promotion_failed');
            }
            $newFinalFiles[] = $backFinal;

            $now = date('Y-m-d H:i:s');
            $card = $db->fetchOne(
                'SELECT id FROM generated_cards
                  WHERE employee_id = :employee_id AND company_id = :company_id
                  ORDER BY generated_at DESC LIMIT 1',
                ['employee_id' => $employeeId, 'company_id' => $companyId]
            );
            $data = [
                'front_template_id' => $context['front_template']['id'] ?? null,
                'back_template_id' => $context['back_template']['id'] ?? null,
                'front_file_path' => $frontStored,
                'back_file_path' => $backStored,
                'front_web_path' => $frontStored,
                'back_web_path' => $backStored,
                'generated_at' => $now,
            ];
            $frontVersion = self::templateVersion($context['front_template']);
            $backVersion = self::templateVersion($context['back_template']);
            if ($frontVersion !== null) {
                $data['front_template_version'] = $frontVersion;
            }
            if ($backVersion !== null) {
                $data['back_template_version'] = $backVersion;
            }
            if (is_array($card) && !empty($card['id'])) {
                $db->update('generated_cards', $data, 'id = :id', ['id' => $card['id']]);
            } else {
                $db->insert('generated_cards', array_merge($data, [
                    'id' => function_exists('generateUUID') ? generateUUID() : self::operationId(),
                    'company_id' => $companyId,
                    'employee_id' => $employeeId,
                ]));
            }
            $db->commit();
            $transactionStarted = false;

            $signature = hash_file('sha256', $frontFinal)
                . ':' . hash_file('sha256', $backFinal);
            self::removeDirectory($stagingDir);
            error_log(sprintf(
                'CardImageRenderer promoted employee=%s reason=%s operation=%s',
                $employeeId,
                preg_replace('/[^a-zA-Z0-9._:-]/', '_', $reason),
                $operationId
            ));
            return [
                'front_file_path' => $frontStored,
                'back_file_path' => $backStored,
                'signature' => hash('sha256', $signature),
                'generated_at' => $now,
            ];
        } catch (Throwable $error) {
            if ($transactionStarted) {
                try {
                    $db->rollback();
                } catch (Throwable $ignored) {
                }
            }
            foreach ($newFinalFiles as $newFile) {
                if (is_file($newFile)) {
                    @unlink($newFile);
                }
            }
            self::removeDirectory($stagingDir);
            error_log(sprintf(
                'CardImageRenderer failed operation=%s error=%s',
                $operationId,
                $error->getMessage()
            ));
            if ($error instanceof CardRenderException) {
                throw $error;
            }
            throw new CardRenderException('render_failed', $operationId, $error);
        }
    }

    private static function resolvedContext($db, string $employeeId): array
    {
        $employee = $db->fetchOne(
            'SELECT * FROM employees WHERE id = :id AND deleted_at IS NULL LIMIT 1',
            ['id' => $employeeId]
        );
        if (!is_array($employee)) {
            throw new RuntimeException('employee_not_found');
        }
        $company = $db->fetchOne(
            'SELECT * FROM companies WHERE id = :id LIMIT 1',
            ['id' => $employee['company_id']]
        );
        if (!is_array($company)) {
            throw new RuntimeException('company_not_found');
        }
        $theme = null;
        try {
            $themeRow = $db->fetchOne(
                'SELECT * FROM company_themes WHERE company_id = :company_id LIMIT 1',
                ['company_id' => $company['id']]
            );
            $theme = is_array($themeRow) ? $themeRow : null;
        } catch (Throwable $ignored) {
        }

        if (!function_exists('getEmployeeTemplates')) {
            require_once __DIR__ . '/functions.php';
        }
        $templates = getEmployeeTemplates($employee, (string)$company['id']);
        $front = self::normalizeTemplate($templates['front'] ?? null, 'front');
        $back = self::normalizeTemplate($templates['back'] ?? null, 'back');

        $presetId = trim((string)($employee['card_template_id'] ?? ''));
        if ($presetId !== '' && empty($theme['managed'])) {
            require_once __DIR__ . '/CardPresets.php';
            $personal = $db->fetchOne(
                "SELECT id FROM card_designs
                  WHERE employee_id = :employee_id AND is_active = 1
                    AND fields_json IS NOT NULL AND fields_json <> ''
                  LIMIT 1",
                ['employee_id' => $employeeId]
            );
            if (!is_array($personal) && CardPresets::exists($presetId)) {
                $front = ['preset_id' => $presetId, 'side' => 'front'];
                $back = ['preset_id' => $presetId, 'side' => 'back'];
            }
        }

        $employeePayload = self::only($employee, [
            'id', 'name', 'name_en', 'name_ar', 'position', 'position_en',
            'position_ar', 'phone', 'phone_ar', 'mobile', 'mobile_ar', 'email',
            'website', 'website_ar', 'fax', 'fax_ar', 'company_en', 'company_ar',
            'address', 'address_en', 'address_2_en', 'address_ar', 'address_2_ar',
            'photo', 'photo_path',
        ]);
        $companyPayload = self::only($company, [
            'id', 'name', 'name_en', 'name_ar', 'slug', 'phone', 'email',
            'website', 'address', 'address_en', 'address_ar', 'logo',
            'default_website', 'default_fax', 'default_address_en',
            'default_address_2_en', 'default_address_ar', 'default_address_2_ar',
        ]);
        $themePayload = is_array($theme) ? self::only($theme, [
            'primary_color', 'secondary_color', 'accent_color', 'logo_path',
            'logo_white_path', 'background_color',
        ]) : [];
        $publicUrl = getTenantUrl(
            (string)($company['slug'] ?? ''),
            '/' . rawurlencode($employeeId)
        );

        return [
            'company' => $company,
            'front_template' => $front,
            'back_template' => $back,
            'payload' => [
                'employee' => $employeePayload,
                'company' => $companyPayload,
                'theme' => $themePayload,
                'front_template' => $front,
                'back_template' => $back,
                'public_url' => $publicUrl,
            ],
        ];
    }

    private static function normalizeTemplate($template, string $side): array
    {
        if (!is_array($template)) {
            return ['side' => $side, 'fields' => [], 'settings' => []];
        }
        $normalized = $template;
        if (!isset($normalized['fields'])) {
            $decoded = json_decode((string)($normalized['fields_json'] ?? ''), true);
            $normalized['fields'] = is_array($decoded) ? $decoded : [];
        }
        if (!isset($normalized['settings'])) {
            $decoded = json_decode((string)($normalized['settings_json'] ?? ''), true);
            $normalized['settings'] = is_array($decoded) ? $decoded : [];
        }
        if (!isset($normalized['backgroundImage'])) {
            $normalized['backgroundImage'] = $normalized['background_image_path'] ?? '';
        }
        $normalized['side'] = $side;
        return $normalized;
    }

    private static function only(array $source, array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $source)) {
                $result[$key] = $source[$key];
            }
        }
        return $result;
    }

    private static function templateVersion(array $template): ?int
    {
        if (!isset($template['current_version']) || !is_numeric($template['current_version'])) {
            return null;
        }
        return (int)$template['current_version'];
    }

    private static function decodeProcessResult(array $output, int $exitCode): array
    {
        $lastLine = $output ? (string)end($output) : '';
        $decoded = json_decode($lastLine, true);
        if ($exitCode !== 0 || !is_array($decoded) || empty($decoded['success'])) {
            $detail = is_array($decoded)
                ? preg_replace('/[^a-zA-Z0-9._:-]/', '_', (string)($decoded['error'] ?? ''))
                : '';
            throw new RuntimeException(
                'renderer_process_failed' . ($detail !== '' ? ':' . $detail : '')
            );
        }
        return $decoded;
    }

    /**
     * The pixel canvas a template renders at.
     *
     * Port of getTemplatePixelDims() in generate_card_html.php and of
     * _canvas_dims() in scripts/render-card-images.py, so all three agree. A
     * template with no stored physical size falls back to the legacy 1050x600.
     */
    public static function templatePixelDims(?array $template): array
    {
        $settings = $template['settings'] ?? ($template['settings_json'] ?? null);
        if (is_string($settings)) {
            $decoded = json_decode($settings, true);
            $settings = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($settings)) return [1050, 600];

        $cw  = (float) ($settings['customWidth'] ?? 0);
        $ch  = (float) ($settings['customHeight'] ?? 0);
        $dpi = (float) ($settings['dpi'] ?? 300);
        if ($dpi <= 0) $dpi = 300.0;
        if ($cw <= 0 || $ch <= 0) return [1050, 600];

        $unit = strtolower((string) ($settings['customUnit'] ?? 'mm'));
        $toIn = $unit === 'pt' ? 1 / 72 : ($unit === 'in' ? 1 : 1 / 25.4);
        $w = (int) round($cw * $toIn * $dpi);
        $h = (int) round($ch * $toIn * $dpi);
        return ($w > 0 && $h > 0) ? [$w, $h] : [1050, 600];
    }

    /**
     * @param array|null $template the template this side was rendered from, so
     *                             the check uses its real canvas
     */
    private static function validatedStagedImage(string $path, string $stagingDir, string $side, ?array $template = null): string
    {
        $realStaging = realpath($stagingDir);
        $realPath = $path !== '' ? realpath($path) : false;
        if ($realStaging === false || $realPath === false
            || strpos($realPath, $realStaging . DIRECTORY_SEPARATOR) !== 0
            || !is_file($realPath)
            || filesize($realPath) < 32) {
            throw new RuntimeException($side . '_render_missing');
        }
        // The size the TEMPLATE renders at, not a constant.
        //
        // This asserted 1050x600 exactly. Every template imported at another
        // size, portrait cards included, produced a correctly sized image that
        // was then rejected as invalid, so "Regenerate cards" failed for those
        // tenants and any repair pass failed with them. Found on 5 Sep 2026
        // while re-baking the cards that had leaked another person's details:
        // all 11 came back front_render_invalid, and the renderer had done
        // nothing wrong.
        [$expectW, $expectH] = self::templatePixelDims($template);
        $dimensions = @getimagesize($realPath);
        if (!is_array($dimensions)
            || (int) $dimensions[0] !== $expectW
            || (int) $dimensions[1] !== $expectH) {
            $got = is_array($dimensions) ? ($dimensions[0] . 'x' . $dimensions[1]) : 'unreadable';
            error_log("CardImageRenderer {$side} expected {$expectW}x{$expectH}, got {$got}");
            throw new RuntimeException($side . '_render_invalid');
        }
        return $realPath;
    }

    private static function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('render_directory_failed');
        }
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (glob($directory . '/*') ?: [] as $path) {
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }

    private static function operationId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
