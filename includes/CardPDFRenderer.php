<?php
/**
 * CardPDFRenderer, single-source-of-truth vector PDF for one employee.
 *
 * Shells out to scripts/render-card-pdf.py, caches the resulting PDF
 * in tmp/pdf-vector/ keyed by:
 *   sha1(employee_id | front_version | back_version | employee.updated_at | theme.updated_at)
 * so it stays warm until any of those bump.
 */
class CardPDFRenderer
{
    /**
     * Render or fetch a cached vector PDF for one employee.
     * Returns ['success'=>true, 'path'=>absolute fs path, 'cached'=>bool]
     * or ['success'=>false, 'error'=>string].
     *
     * Cache signature covers: employee_id, front template version, back
     * template version, employee.updated_at, and company_themes.updated_at.
     * Any of those changing busts the key. The theme sweep in
     * CardRenderer::invalidateForCompany also deletes all files in
     * tmp/pdf-vector/, so this signature is belt-and-suspenders for cases
     * where the sweep is missed (e.g. a CLI script updates the theme row
     * without going through the normal save path).
     *
     * Caller (card-pdf.php) is responsible for falling back to the
     * raster path when has_vector_source=0 or success=false.
     */
    /**
     * @param string $employeeId
     * @param string $profile 'web' (default), 'print', or 'sample'.
     *   'web'    - subset fonts, no bleed, no watermark; for screen/download.
     *   'print'  - full font embed; api/print-ready.php pairs this with
     *              --for-print for bleed + crop marks. Goes to print shops.
     *   'sample' - same as 'web' but renders a diagonal "SAMPLE - NOT FOR
     *              PRINT" watermark on every page. Tenant admins always
     *              get this when downloading the per-card PDF; print shops
     *              never do.
     *   Cache key differs per profile so all three can coexist on disk.
     */
    public static function render(string $employeeId, string $profile = 'web'): array
    {
        if ($employeeId === '') {
            return ['success' => false, 'error' => 'empty employee id'];
        }
        $profile = in_array($profile, ['web', 'print', 'sample'], true) ? $profile : 'web';

        $db = Database::getInstance();
        $employee = $db->fetchOne(
            'SELECT id, name_en, name_ar, position_en, position_ar,
                    mobile, phone, email, website,
                    address_en, address_ar,
                    company_id, updated_at
               FROM employees WHERE id = :id LIMIT 1',
            ['id' => $employeeId]
        );
        if (!is_array($employee)) {
            return ['success' => false, 'error' => 'employee not found'];
        }

        $companyId = $employee['company_id'];
        $company = $db->fetchOne(
            'SELECT id, name, slug FROM companies WHERE id = :cid LIMIT 1',
            ['cid' => $companyId]
        );
        $companyName = is_array($company) ? ($company['name'] ?? '') : '';
        $companySlug = is_array($company) ? ($company['slug'] ?? '') : '';
        $tplFront = $db->fetchOne(
            "SELECT * FROM templates
              WHERE company_id = :cid AND side = 'front' AND is_active = 1
              ORDER BY created_at DESC LIMIT 1",
            ['cid' => $companyId]
        );
        $tplBack = $db->fetchOne(
            "SELECT * FROM templates
              WHERE company_id = :cid AND side = 'back' AND is_active = 1
              ORDER BY created_at DESC LIMIT 1",
            ['cid' => $companyId]
        );
        if (!is_array($tplFront) && !is_array($tplBack)) {
            return ['success' => false, 'error' => 'no active templates'];
        }
        // Front + back must both be vector-capable; otherwise we fall back.
        $vectorOk = is_array($tplFront) && (int)($tplFront['has_vector_source'] ?? 0) === 1
                 && is_array($tplBack)  && (int)($tplBack['has_vector_source']  ?? 0) === 1;
        if (!$vectorOk) {
            return ['success' => false, 'error' => 'template lacks vector source'];
        }

        // Look up the active brand theme so a color change busts the cache key.
        $theme = $db->fetchOne(
            'SELECT updated_at FROM company_themes WHERE company_id = :cid LIMIT 1',
            ['cid' => $companyId]
        );

        // Cache signature: anything that changes the visible card busts it.
        // Profile is included so 'web' and 'print' renders coexist on disk.
        $sig = sha1(implode('|', [
            $employee['id'],
            (int)($tplFront['current_version'] ?? 1),
            (int)($tplBack['current_version']  ?? 1),
            $employee['updated_at']  ?? '',
            is_array($theme) ? ($theme['updated_at'] ?? '') : '',
            $profile,
        ]));
        $cacheDir = BASE_DIR . '/tmp/pdf-vector';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
        $cachePath = $cacheDir . '/' . $sig . '.pdf';
        if (is_file($cachePath) && filesize($cachePath) > 1024) {
            return ['success' => true, 'path' => $cachePath, 'cached' => true];
        }

        // Build template + employee JSON for the Python renderer.
        $importDirFront = self::importDirOf($tplFront);
        $importDirBack  = self::importDirOf($tplBack);
        // Both sides currently come from the same import_token in
        // practice. Pick the back's import_dir as the source for the
        // shared font + svg paths; if they ever diverge, refactor to
        // pass per-page import_dir.
        $importDir = $importDirBack ?: $importDirFront;
        if (!$importDir || !is_dir($importDir)) {
            return ['success' => false, 'error' => 'import_dir missing'];
        }

        $fontsDir = self::resolveFs((string)($tplBack['fonts_dir'] ?? ''));
        if (!$fontsDir || !is_dir($fontsDir)) {
            return ['success' => false, 'error' => 'fonts_dir missing, run extract-template-fonts.py'];
        }

        // Company-uploaded fonts override extracted subsets so user-uploaded
        // full fonts (with all GSUB features + full Latin/Arabic glyph
        // coverage) win over parser-extracted subset TTFs limited to the
        // source PDF's character set.
        $companyId = (string)($tplBack['company_id'] ?? '');
        $companyFontsDir = $companyId
            ? (BASE_DIR . '/uploads/fonts/companies/' . $companyId)
            : null;
        if ($companyFontsDir && !is_dir($companyFontsDir)) {
            $companyFontsDir = null;
        }

        // Base URL for QR data — defaults to the apex; tenant subdomain
        // (otech.cardify.om) also resolves to the same QR tracker endpoint.
        $baseUrl = (defined('APP_HOST') ? 'https://' . APP_HOST . '/' : 'https://cardify.om/');

        $template = [
            'import_dir'         => $importDir,
            'fonts_dir'          => $fontsDir,
            'company_fonts_dir'  => $companyFontsDir,
            'company_name'       => $companyName,
            'company_slug'       => $companySlug,
            'base_url'           => $baseUrl,
            'pages' => [
                self::pageSpec($tplFront, 'front'),
                self::pageSpec($tplBack,  'back'),
            ],
        ];
        $tmpTpl = tempnam(sys_get_temp_dir(), 'cpdftpl_') . '.json';
        $tmpEmp = tempnam(sys_get_temp_dir(), 'cpdfemp_') . '.json';
        file_put_contents($tmpTpl, json_encode($template, JSON_UNESCAPED_UNICODE));
        file_put_contents($tmpEmp, json_encode($employee, JSON_UNESCAPED_UNICODE));

        // Phase 7: generate a vCard and write to a temp file for embedding.
        // Lazy-load VCF class if not already available in this context.
        if (!class_exists('VCF') && defined('INCLUDES_DIR') && is_file(INCLUDES_DIR . '/VCF.php')) {
            require_once INCLUDES_DIR . '/VCF.php';
        }
        $tmpVcf = '';
        if (class_exists('VCF') && is_array($company)) {
            $vcfContent = VCF::generate($employee, $company);
            $tmpVcf     = tempnam(sys_get_temp_dir(), 'cpdfvcf_') . '.vcf';
            file_put_contents($tmpVcf, $vcfContent);
        }

        // The 'sample' profile is the same render path as 'web' but with the
        // watermark overlay. The Python script accepts the watermark text as
        // a flag; profile=web is what render-card-pdf.py expects (it has no
        // 'sample' enum). Map sample → web internally + watermark text.
        $pyProfile  = ($profile === 'sample') ? 'web' : $profile;
        // Short watermark text on per-card pages (~85x55mm); long text gets
        // clipped after the -30deg rotation on this small canvas. The
        // imposition sheet (A4) uses the longer "SAMPLE - NOT FOR PRINT"
        // string since it has the room.
        $watermark  = ($profile === 'sample') ? 'SAMPLE' : '';

        $py  = trim((string)@shell_exec('command -v python3 2>/dev/null')) ?: 'python3';
        $cmd = escapeshellarg($py)
             . ' ' . escapeshellarg(BASE_DIR . '/scripts/render-card-pdf.py')
             . ' --template ' . escapeshellarg($tmpTpl)
             . ' --employee ' . escapeshellarg($tmpEmp)
             . ' --out '      . escapeshellarg($cachePath)
             . ' --profile '  . escapeshellarg($pyProfile)
             . ($watermark !== '' ? ' --watermark ' . escapeshellarg($watermark) : '')
             . ($tmpVcf !== '' ? ' --vcard ' . escapeshellarg($tmpVcf) : '')
             . ' 2>&1';
        if (trim((string)@shell_exec('command -v timeout 2>/dev/null')) !== '') {
            $cmd = 'timeout 30 ' . $cmd;
        }
        $rc = 0; $out = [];
        exec($cmd, $out, $rc);
        @unlink($tmpTpl);
        @unlink($tmpEmp);
        if ($tmpVcf !== '') @unlink($tmpVcf);

        if ($rc === 124) {
            error_log('CardPDFRenderer timed out after 30s');
            return ['success' => false, 'error' => 'render timed out'];
        }
        if ($rc !== 0 || !is_file($cachePath) || filesize($cachePath) < 1024) {
            error_log('CardPDFRenderer rc=' . $rc . ' out=' . implode("\n", $out));
            return ['success' => false, 'error' => 'render failed'];
        }

        // Phase 8: write sidecar .meta so invalidation can prune per-employee.
        $themeUpdatedAt = is_array($theme) ? ($theme['updated_at'] ?? '') : '';
        @file_put_contents($cacheDir . '/' . $sig . '.meta', json_encode([
            'employee_id'         => $employee['id'],
            'company_id'          => $companyId,
            'generated_at'        => date('c'),
            'employee_updated_at' => $employee['updated_at'] ?? '',
            'theme_updated_at'    => $themeUpdatedAt,
        ]));

        return ['success' => true, 'path' => $cachePath, 'cached' => false];
    }

    private static function pageSpec(?array $tpl, string $side): array
    {
        if (!is_array($tpl)) {
            return ['side' => $side, 'width_pt' => 262.55, 'height_pt' => 169.89, 'fields' => []];
        }
        $settings = json_decode((string)($tpl['settings_json'] ?? ''), true) ?: [];
        $widthMm  = (float)($settings['customWidth']  ?? 92.62);
        $heightMm = (float)($settings['customHeight'] ?? 59.93);
        $widthPt  = $widthMm  * 72 / 25.4;
        $heightPt = $heightMm * 72 / 25.4;
        $fields   = json_decode((string)($tpl['fields_json'] ?? '[]'), true) ?: [];

        // settings_json from the importer carries a background_svg_path
        // alongside background_image_path. The renderer now prefers the
        // PNG (redacted) over the SVG (still has source PDF baked text).
        $svgRel = $settings['background_svg_path'] ?? str_replace('.png', '.svg', basename((string)($tpl['background_image_path'] ?? '')));
        $pngRel = basename((string)($tpl['background_image_path'] ?? '')) ?: str_replace('.svg', '.png', $svgRel);

        $fieldList = [];
        foreach ($fields as $key => $f) {
            if (!is_array($f)) continue;
            // Skip baked-in fields (already in the bg PNG) and the QR slot.
            if (!empty($f['render_in_bg'])) continue;
            if ($key === 'qr_code')         continue;

            // Two runtime-drawn kinds:
            //  - typed dynamic (is_static=false, value resolved from employee)
            //  - static text   (is_static=true,  value = detected_text literal)
            // Pass a static_text field for the latter so render-card-pdf.py
            // can draw the literal instead of trying to resolve a binding.
            $staticText = !empty($f['is_static']) ? (string)($f['detected_text'] ?? $f['static_text'] ?? '') : null;
            $fieldList[] = [
                'field_key'    => $key,
                'static_text'  => $staticText,
                // Template sample, used as fallback for tenant-constant
                // fields (website/company/address) when the employee row
                // has no value.
                'detected_text' => (string)($f['detected_text'] ?? ''),
                'x_pt'         => (float)($f['x_pt']     ?? ($f['x'] ?? 0) / 4.166),
                'y_pt'         => (float)($f['y_pt']     ?? ($f['y'] ?? 0) / 4.166),
                'font_family'  => (string)($f['fontFamily'] ?? $f['font_family'] ?? 'Lato'),
                'font_weight'  => (int)($f['fontWeight'] ?? $f['font_weight'] ?? 400),
                'font_size_pt' => (float)($f['font_size_pt'] ?? ($f['fontSize'] ?? 10) / 4.166),
                'color'        => (string)($f['fill'] ?? $f['color'] ?? '#ffffff'),
            ];
        }
        // Pass the qr_code field spec separately so render-card-pdf.py
        // can generate a real styled QR (the field-list loop skips qr_code).
        $qrSpec = isset($fields['qr_code']) && is_array($fields['qr_code']) ? $fields['qr_code'] : null;

        return [
            'side'                 => $side,
            'width_pt'             => $widthPt,
            'height_pt'            => $heightPt,
            'background_png_path'  => $pngRel,
            'background_svg_path'  => $svgRel,
            'qr_code'              => $qrSpec,
            'fields'               => $fieldList,
        ];
    }

    private static function importDirOf(?array $tpl): ?string
    {
        if (!is_array($tpl)) return null;
        $bg = (string)($tpl['background_image_path'] ?? '');
        if ($bg === '') return null;
        $bg = ltrim($bg, '/');
        $abs = BASE_DIR . '/' . $bg;
        return is_file($abs) ? dirname($abs) : null;
    }

    private static function resolveFs(string $rel): string
    {
        if ($rel === '') return '';
        if ($rel[0] === '/') return BASE_DIR . $rel;
        return BASE_DIR . '/' . ltrim($rel, '/');
    }
}
