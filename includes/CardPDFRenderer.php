<?php
/**
 * CardPDFRenderer, single-source-of-truth vector PDF for one employee.
 *
 * Shells out to scripts/render-card-pdf.py, caches the resulting PDF
 * in tmp/pdf-vector/ keyed by:
 *   sha1(employee_id . current_version . employee.updated_at . theme.updated_at)
 * so it stays warm until any of those bump.
 */
class CardPDFRenderer
{
    /**
     * Render or fetch a cached vector PDF for one employee.
     * Returns ['success'=>true, 'path'=>absolute fs path, 'cached'=>bool]
     * or ['success'=>false, 'error'=>string].
     *
     * Caller (card-pdf.php) is responsible for falling back to the
     * raster path when has_vector_source=0 or success=false.
     */
    public static function render(string $employeeId): array
    {
        if ($employeeId === '') {
            return ['success' => false, 'error' => 'empty employee id'];
        }

        $db = Database::getInstance();
        $employee = $db->fetchOne(
            'SELECT * FROM employees WHERE id = :id LIMIT 1',
            ['id' => $employeeId]
        );
        if (!is_array($employee)) {
            return ['success' => false, 'error' => 'employee not found'];
        }

        $companyId = $employee['company_id'];
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

        // Cache signature, anything that changes the visible card busts.
        $sig = sha1(implode('|', [
            $employee['id'],
            (int)($tplFront['current_version'] ?? 1),
            (int)($tplBack['current_version']  ?? 1),
            $employee['updated_at']  ?? '',
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

        $template = [
            'import_dir' => $importDir,
            'fonts_dir'  => $fontsDir,
            'pages' => [
                self::pageSpec($tplFront, 'front'),
                self::pageSpec($tplBack,  'back'),
            ],
        ];
        $tmpTpl = tempnam(sys_get_temp_dir(), 'cpdftpl_') . '.json';
        $tmpEmp = tempnam(sys_get_temp_dir(), 'cpdfemp_') . '.json';
        file_put_contents($tmpTpl, json_encode($template, JSON_UNESCAPED_UNICODE));
        file_put_contents($tmpEmp, json_encode($employee, JSON_UNESCAPED_UNICODE));

        $py  = trim((string)@shell_exec('command -v python3 2>/dev/null')) ?: 'python3';
        $cmd = escapeshellarg($py)
             . ' ' . escapeshellarg(BASE_DIR . '/scripts/render-card-pdf.py')
             . ' --template ' . escapeshellarg($tmpTpl)
             . ' --employee ' . escapeshellarg($tmpEmp)
             . ' --out '      . escapeshellarg($cachePath)
             . ' 2>&1';
        if (trim((string)@shell_exec('command -v timeout 2>/dev/null')) !== '') {
            $cmd = 'timeout 30 ' . $cmd;
        }
        $rc = 0; $out = [];
        exec($cmd, $out, $rc);
        @unlink($tmpTpl);
        @unlink($tmpEmp);

        if ($rc === 124) {
            error_log('CardPDFRenderer timed out after 30s');
            return ['success' => false, 'error' => 'render timed out'];
        }
        if ($rc !== 0 || !is_file($cachePath) || filesize($cachePath) < 1024) {
            error_log('CardPDFRenderer rc=' . $rc . ' out=' . implode("\n", $out));
            return ['success' => false, 'error' => 'render failed'];
        }
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
        // alongside background_image_path.
        $svgRel = $settings['background_svg_path'] ?? str_replace('.png', '.svg', basename((string)($tpl['background_image_path'] ?? '')));

        $fieldList = [];
        foreach ($fields as $key => $f) {
            if (!is_array($f)) continue;
            // Only DYNAMIC fields go through PyMuPDF's text drawing.
            // Statics are already in the SVG bg.
            if (!empty($f['render_in_bg'])) continue;
            if (!empty($f['is_static']))    continue;
            if ($key === 'qr_code')         continue;
            $fieldList[] = [
                'field_key'    => $key,
                'x_pt'         => (float)($f['x_pt']     ?? ($f['x'] ?? 0) / 4.166),
                'y_pt'         => (float)($f['y_pt']     ?? ($f['y'] ?? 0) / 4.166),
                'font_family'  => (string)($f['fontFamily'] ?? $f['font_family'] ?? 'Lato'),
                'font_weight'  => (int)($f['fontWeight'] ?? $f['font_weight'] ?? 400),
                'font_size_pt' => (float)($f['font_size_pt'] ?? ($f['fontSize'] ?? 10) / 4.166),
                'color'        => (string)($f['fill'] ?? $f['color'] ?? '#ffffff'),
            ];
        }
        return [
            'side'                 => $side,
            'width_pt'             => $widthPt,
            'height_pt'            => $heightPt,
            'background_svg_path'  => $svgRel,
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
