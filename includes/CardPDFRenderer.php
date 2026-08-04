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
     * Bump whenever render-card-pdf.py output can change for identical inputs
     * (shaping libs, baseline/font logic, layout fixes). Part of the cache
     * signature, so a bump invalidates every previously cached PDF on disk.
     * v2 (20 May 2026): Arabic reshaper + bidi visual-order shaping.
     * v3 (20 May 2026): isolated Arabic forms drawn via nominal glyph
     *   (fixes decorative-tail isolated heh, matches HarfBuzz/browser).
     * v15 (10 Jun 2026): Latin right/center alignment anchors from the
     *   field right edge (x_pt + w_pt), matching Fabric + Arabic htmlbox
     *   (rule 47 convention: x = bbox LEFT edge).
     */
    const RENDERER_VERSION = 23;

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
    public static function render(string $employeeId, string $profile = 'web', array $opts = []): array
    {
        if ($employeeId === '') {
            return ['success' => false, 'error' => 'empty employee id'];
        }
        // include_qr defaults true; when explicitly false the QR is suppressed in
        // this render (MHD portal "no QR" tickbox). Cache key differs so the
        // with-QR and without-QR PDFs coexist on disk.
        $noQr = array_key_exists('include_qr', $opts) && !$opts['include_qr'];
        // Explicit include_qr=true must DRAW the QR even when the template's
        // qr_code slot ships enabled=false (MHD slots default off; the tickbox
        // is the opt-in). Forced below onto each page's qr spec.
        $forceQr = array_key_exists('include_qr', $opts) && (bool)$opts['include_qr'];
        // 'press' = the clean per-card print download: print font-embed + 3mm
        // bleed + crop marks + DeviceCMYK (exact tenant brand values) + a
        // CutContour cut-line layer. 'print' stays RGB/no-bleed so the A4
        // imposition slot math (imposition-vector.py) is unaffected.
        // 'vector' = all-vector card: uses the original vector source.pdf as the
        // background (designer sample redacted) + the full-font dynamic overlay,
        // so type matches the design exactly and the file stays vector (clean
        // CMYK-convertible). Used by the vector cutting sheet.
        $profile = in_array($profile, ['web', 'print', 'sample', 'press', 'vector'], true) ? $profile : 'web';

        $db = Database::getInstance();
        $employee = $db->fetchOne(
            'SELECT id, name_en, name_ar, position_en, position_ar,
                    position_en_2, position_ar_2,
                    position_en_3, position_ar_3,
                    mobile, mobile_ar, phone, phone_ar, email, website,
                    address_en, address_ar, department_id,
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
        // Department-specific template (e.g. MHD, where each division has its
        // own card): if the employee's department pins a template pair, use that
        // pair's sides. This must win over the company-wide newest-template pick
        // so a multi-division tenant renders each employee on their division card.
        $tplFront = null; $tplBack = null;
        $deptPinned = false;
        if (!empty($employee['department_id'])) {
            $dept = $db->fetchOne(
                "SELECT template_pair_id FROM departments WHERE id = :d AND deleted_at IS NULL LIMIT 1",
                ['d' => $employee['department_id']]
            );
            $pairId = is_array($dept) ? ($dept['template_pair_id'] ?? null) : null;
            if (!empty($pairId)) {
                $tplFront = $db->fetchOne("SELECT * FROM templates WHERE pair_id = :p AND side = 'front' AND is_active = 1 LIMIT 1", ['p' => $pairId]);
                $tplBack  = $db->fetchOne("SELECT * FROM templates WHERE pair_id = :p AND side = 'back'  AND is_active = 1 LIMIT 1", ['p' => $pairId]);
                // A pinned pair is authoritative for BOTH sides: a single-sided
                // pair (e.g. MHD Consumer, front only) renders one page and must
                // never borrow the missing side from an unrelated template.
                $deptPinned = is_array($tplFront) || is_array($tplBack);
            }
        }
        // Fallback: prefer a vector-capable (uploaded/imported) template over any
        // non-vector seed. Without `has_vector_source DESC` a leftover seed
        // template (e.g. "BHD Classic", created AFTER the real upload) wins on
        // created_at and the renderer falls back to the generic raster layout.
        if (!is_array($tplFront) && !$deptPinned) {
            $tplFront = $db->fetchOne(
                "SELECT * FROM templates
                  WHERE company_id = :cid AND side = 'front' AND is_active = 1
                  ORDER BY has_vector_source DESC, created_at DESC LIMIT 1",
                ['cid' => $companyId]
            );
        }
        if (!is_array($tplBack) && !$deptPinned) {
            $tplBack = $db->fetchOne(
                "SELECT * FROM templates
                  WHERE company_id = :cid AND side = 'back' AND is_active = 1
                  ORDER BY has_vector_source DESC, created_at DESC LIMIT 1",
                ['cid' => $companyId]
            );
        }
        if (!is_array($tplFront) && !is_array($tplBack)) {
            return ['success' => false, 'error' => 'no active templates'];
        }
        // At least one side must be vector-capable. Single-sided cards (front
        // only, e.g. personal cards imported from a one-page PDF) render their
        // single side through the vector pipeline as a one-page PDF.
        $frontVector = is_array($tplFront) && (int)($tplFront['has_vector_source'] ?? 0) === 1;
        $backVector  = is_array($tplBack)  && (int)($tplBack['has_vector_source']  ?? 0) === 1;
        // If a side exists but isn't vector-capable, fall back to the raster
        // path for that whole render (mixing vector + raster across sides
        // would produce visually inconsistent output).
        if (is_array($tplFront) && !$frontVector) {
            return ['success' => false, 'error' => 'template lacks vector source'];
        }
        if (is_array($tplBack) && !$backVector) {
            return ['success' => false, 'error' => 'template lacks vector source'];
        }
        if (!$frontVector && !$backVector) {
            return ['success' => false, 'error' => 'template lacks vector source'];
        }

        // Look up the active brand theme so a color change busts the cache key.
        $theme = $db->fetchOne(
            'SELECT updated_at FROM company_themes WHERE company_id = :cid LIMIT 1',
            ['cid' => $companyId]
        );

        // Cache signature: anything that changes the visible card busts it.
        // Profile is included so 'web' and 'print' renders coexist on disk.
        // RENDERER_VERSION is bumped whenever the render script's output can
        // change for the same inputs (e.g. Arabic shaping libs installed,
        // baseline/font logic fixed) so stale PDFs cached by an older renderer
        // are invalidated automatically. v2: Arabic reshaper+bidi shaping.
        $sig = sha1(implode('|', [
            self::RENDERER_VERSION,
            $employee['id'],
            is_array($tplFront) ? (int)($tplFront['current_version'] ?? 1) : 0,
            is_array($tplBack)  ? (int)($tplBack['current_version']  ?? 1) : 0,
            $employee['updated_at']  ?? '',
            is_array($theme) ? ($theme['updated_at'] ?? '') : '',
            $profile,
            $noQr ? 'noqr' : ($forceQr ? 'qrf' : 'qr'),
        ]));
        $cacheDir = BASE_DIR . '/tmp/pdf-vector';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
        $cachePath = $cacheDir . '/' . $sig . '.pdf';
        if (is_file($cachePath) && filesize($cachePath) > 1024) {
            // A cache file written by a root CLI/cron render lands root:root 0640,
            // so PHP-FPM (www) can't read it -> readfile() Permission denied ->
            // truncated FastCGI response -> 502. Make it world-readable on hit.
            if ((fileperms($cachePath) & 0044) !== 0044) @chmod($cachePath, 0644);
            return ['success' => true, 'path' => $cachePath, 'cached' => true];
        }

        // Build template + employee JSON for the Python renderer.
        $importDirFront = is_array($tplFront) ? self::importDirOf($tplFront) : null;
        $importDirBack  = is_array($tplBack)  ? self::importDirOf($tplBack)  : null;
        // Both sides currently come from the same import_token in
        // practice. Pick whichever side exists as the source for the
        // shared font + svg paths.
        $importDir = $importDirBack ?: $importDirFront;
        if (!$importDir || !is_dir($importDir)) {
            return ['success' => false, 'error' => 'import_dir missing'];
        }

        // Use whichever side has a fonts_dir set. Single-sided cards only have
        // a front template, so prefer back -> front.
        $fontsDirRel = '';
        if (is_array($tplBack))  $fontsDirRel = (string)($tplBack['fonts_dir']  ?? '');
        if ($fontsDirRel === '' && is_array($tplFront)) $fontsDirRel = (string)($tplFront['fonts_dir'] ?? '');
        $fontsDir = self::resolveFs($fontsDirRel);
        if (!$fontsDir || !is_dir($fontsDir)) {
            return ['success' => false, 'error' => 'fonts_dir missing, run extract-template-fonts.py'];
        }

        // Company-uploaded fonts override extracted subsets so user-uploaded
        // full fonts (with all GSUB features + full Latin/Arabic glyph
        // coverage) win over parser-extracted subset TTFs limited to the
        // source PDF's character set.
        $companyId = (string)(($tplBack['company_id'] ?? $tplFront['company_id']) ?? '');
        $companyFontsDir = $companyId
            ? (BASE_DIR . '/uploads/fonts/companies/' . $companyId)
            : null;
        if ($companyFontsDir && !is_dir($companyFontsDir)) {
            $companyFontsDir = null;
        }

        // Base URL for QR data — defaults to the apex; tenant subdomain
        // (otech.cardify.om) also resolves to the same QR tracker endpoint.
        $baseUrl = (defined('APP_HOST') ? 'https://' . APP_HOST . '/' : 'https://cardify.om/');

        $pages = [];
        if (is_array($tplFront)) $pages[] = self::pageSpec($tplFront, 'front');
        if (is_array($tplBack))  $pages[] = self::pageSpec($tplBack,  'back');
        if ($forceQr) {
            foreach ($pages as &$ps) {
                if (is_array($ps['qr_code'] ?? null)) $ps['qr_code']['enabled'] = true;
            }
            unset($ps);
        }
        // Printed-card QR target. Built here, not in Python, so the one
        // canonical share-URL builder stays in PHP (reserved slugs, dotted
        // localparts, /card/<id> fallback all live in CardifyConvention).
        $qrUrl = '';
        try {
            if (!class_exists('CardifyConvention')) {
                require_once INCLUDES_DIR . '/CardifyConvention.php';
            }
            if ($companySlug !== '') {
                $qrUrl = CardifyConvention::employeeShareUrl($companySlug, $employee);
            }
        } catch (Throwable $e) {
            $qrUrl = ''; // fall back to the qr.php tracker form in Python
        }

        $template = [
            'import_dir'         => $importDir,
            'fonts_dir'          => $fontsDir,
            'company_fonts_dir'  => $companyFontsDir,
            'company_name'       => $companyName,
            'company_slug'       => $companySlug,
            'base_url'           => $baseUrl,
            'qr_url'             => $qrUrl,
            'pages'              => $pages,
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
        $pyProfile  = in_array($profile, ['sample'], true) ? 'web'
                    : (in_array($profile, ['press', 'vector'], true) ? 'print' : $profile);
        // Short watermark text on per-card pages (~85x55mm); long text gets
        // clipped after the -30deg rotation on this small canvas. The
        // imposition sheet (A4) uses the longer "SAMPLE - NOT FOR PRINT"
        // string since it has the room.
        $watermark  = ($profile === 'sample') ? 'SAMPLE' : '';

        // Press profile: bleed + crop marks, plus a CMYK config sidecar when the
        // tenant has a brand-colour map (seeded for known tenants, overridable
        // via the front template's settings.print_cmyk). No config -> press is
        // still a clean RGB bleed+crop file (no CMYK/cut).
        $forPrint = ($profile === 'press');
        $tmpCmyk  = '';
        // press = raster CMYK + cut; vector = clean content-stream CMYK on the
        // vector card. Both want the tenant brand-colour map.
        if (in_array($profile, ['press', 'vector'], true)) {
            $cmykCfg = self::pressCmykConfig($companyId, $companySlug, $tplFront, $tplBack);
            if (is_array($cmykCfg)) {
                $tmpCmyk = tempnam(sys_get_temp_dir(), 'cpdfcmyk_') . '.json';
                file_put_contents($tmpCmyk, json_encode($cmykCfg, JSON_UNESCAPED_UNICODE));
            }
        }

        $py  = trim((string)@shell_exec('command -v python3 2>/dev/null')) ?: 'python3';
        $cmd = escapeshellarg($py)
             . ' ' . escapeshellarg(BASE_DIR . '/scripts/render-card-pdf.py')
             . ' --template ' . escapeshellarg($tmpTpl)
             . ' --employee ' . escapeshellarg($tmpEmp)
             . ' --out '      . escapeshellarg($cachePath)
             . ' --profile '  . escapeshellarg($pyProfile)
             . ($forPrint ? ' --for-print' : '')
             . ($profile === 'vector' ? ' --vector-bg' : '')
             . ($noQr ? ' --no-qr' : '')
             . ($tmpCmyk !== '' ? ' --cmyk ' . escapeshellarg($tmpCmyk) : '')
             . ($watermark !== '' ? ' --watermark ' . escapeshellarg($watermark) : '')
             . ($tmpVcf !== '' ? ' --vcard ' . escapeshellarg($tmpVcf) : '')
             . ' 2>&1';
        // Press needs longer: the CMYK bg conversion + Ghostscript pass on the
        // 1200-DPI artwork takes more than the 30s a plain vector render needs.
        $timeoutSecs = in_array($profile, ['press','vector'], true) ? 90 : 30;
        if (trim((string)@shell_exec('command -v timeout 2>/dev/null')) !== '') {
            $cmd = 'timeout ' . $timeoutSecs . ' ' . $cmd;
        }
        $rc = 0; $out = [];
        exec($cmd, $out, $rc);
        @unlink($tmpTpl);
        @unlink($tmpEmp);
        if ($tmpVcf !== '') @unlink($tmpVcf);
        if ($tmpCmyk !== '') @unlink($tmpCmyk);

        if ($rc === 124) {
            error_log('CardPDFRenderer timed out after ' . $timeoutSecs . 's');
            return ['success' => false, 'error' => 'render timed out'];
        }
        if ($rc !== 0 || !is_file($cachePath) || filesize($cachePath) < 1024) {
            // Remove any truncated/0-byte artifact a partial render left behind.
            // A lingering empty cachePath later crashed re-renders that re-open
            // it (PyMuPDF EmptyFileError), poisoning that employee permanently.
            if (is_file($cachePath) && filesize($cachePath) < 1024) {
                @unlink($cachePath);
            }
            error_log('CardPDFRenderer rc=' . $rc . ' out=' . implode("\n", $out));
            return ['success' => false, 'error' => 'render failed'];
        }

        // Surface non-fatal WARN lines (e.g. arabic_reshaper/python-bidi not
        // installed -> Arabic renders UNSHAPED with no rc!=0 failure). Without
        // this, broken Arabic cards ship silently. See scripts/requirements.txt.
        foreach ($out as $line) {
            if (stripos($line, 'WARN') !== false) {
                error_log('CardPDFRenderer WARN (rc=0): ' . trim($line));
            }
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

        // World-readable so a root CLI/cron render's cache file is servable by
        // PHP-FPM (www) on the next download (else readfile() -> 502).
        @chmod($cachePath, 0644);
        return ['success' => true, 'path' => $cachePath, 'cached' => false];
    }

    /**
     * Resolve the CMYK press config (brand colour map + corner radius) for a
     * company, or null when none is configured (press then stays RGB bleed+crop).
     *
     * Resolution order:
     *   1. The front/back template's settings.print_cmyk JSON (data-driven,
     *      editable per tenant without code).
     *   2. A built-in seed for known tenants (Otech today).
     *
     * Config shape:
     *   {
     *     "enabled": true,
     *     "corner_radius_mm": 1.5,          // rounded-corner cut radius
     *     "cut_line_width_pt": 0.5,
     *     "colors": [
     *       {"rgb":[45,19,234], "cmyk":[100,90,0,2], "tol":30},   // Deep Sea
     *       {"rgb":[255,120,0], "cmyk":[0,70,100,0], "tol":45}    // Gold Mountains
     *     ]
     *   }
     */
    private static function pressCmykConfig(
        string $companyId, string $companySlug, ?array $tplFront = null, ?array $tplBack = null
    ): ?array {
        // When templates weren't supplied (e.g. a UI capability check), fetch the
        // active front/back so settings.print_cmyk can still be read.
        if ($tplFront === null && $tplBack === null && $companyId !== '') {
            try {
                $rows = Database::getInstance()->fetchAll(
                    "SELECT id, side, settings_json FROM templates
                      WHERE company_id = :c AND deleted_at IS NULL AND side IN ('front','back')
                      ORDER BY has_vector_source DESC, created_at DESC",
                    ['c' => $companyId]
                );
                foreach ($rows as $r) {
                    if ($r['side'] === 'front' && $tplFront === null) $tplFront = $r;
                    if ($r['side'] === 'back'  && $tplBack  === null) $tplBack  = $r;
                }
            } catch (Throwable $e) { /* fall through to seed */ }
        }
        // 1. Per-template override (settings_json.print_cmyk).
        foreach ([$tplFront, $tplBack] as $tpl) {
            if (!is_array($tpl)) continue;
            $settings = $tpl['settings_json'] ?? ($tpl['settings'] ?? null);
            if (is_string($settings)) $settings = json_decode($settings, true);
            if (is_array($settings) && isset($settings['print_cmyk'])
                && is_array($settings['print_cmyk'])) {
                return $settings['print_cmyk'];
            }
        }

        // 2. Built-in tenant seeds. Brand specs come from the tenant brand sheet;
        //    Otech: Deep Sea (PANTONE 2736C) + Gold Mountains (PANTONE 151C).
        $seeds = [
            'otech' => [
                'enabled'          => true,
                // Otech card = 90x55mm trim, 4.259mm rounded corners (per the
                // Illustrator source). The imported design is 91x61mm bleed-
                // inclusive, so the renderer crops to the centred 90x55 trim.
                'corner_radius_mm' => 4.259,
                'trim'             => ['w_mm' => 90, 'h_mm' => 55],
                'cut_line_width_pt' => 0.5,
                'colors' => [
                    // Deep Sea blue is the field + the dot-mesh/gradient texture,
                    // so tint_family maps every blue shade to a tint of it (else
                    // light blues convert to magenta = a pink mesh artifact).
                    ['rgb' => [45, 19, 234], 'cmyk' => [100, 90, 0, 2], 'tol' => 30, 'tint_family' => true],
                    ['rgb' => [255, 120, 0], 'cmyk' => [0, 70, 100, 0], 'tol' => 45],
                ],
            ],
        ];
        return $seeds[$companySlug] ?? null;
    }

    /**
     * True when the 'press' download for this company produces DeviceCMYK with
     * exact brand values + a CutContour cut layer (vs a plain RGB bleed file).
     * Used by the admin UI to label the print-ready download accurately.
     */
    public static function companyHasPressCmyk(string $companyId, string $companySlug = ''): bool
    {
        return self::pressCmykConfig($companyId, $companySlug) !== null;
    }

    /**
     * Public accessor for the tenant CMYK brand config (or null). Used by the
     * cutting sheet to colour-match its background fill + convert to CMYK.
     */
    public static function pressCmykConfigFor(string $companyId, string $companySlug = ''): ?array
    {
        return self::pressCmykConfig($companyId, $companySlug);
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
            // Skip switched-off fields. Fabric honours `enabled`, so without
            // this the print PDF carries text the on-screen card does not:
            // Otech shipped with mobile_ar disabled but populated on 218 of
            // 261 staff, and it printed as a dark string over the card edge.
            if (array_key_exists('enabled', $f) && !$f['enabled']) continue;

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
                'w_pt'         => (float)($f['w_pt'] ?? (($f['width'] ?? 0) / 4.166)),
                'font_family'  => (string)($f['fontFamily'] ?? $f['font_family'] ?? 'Lato'),
                'font_weight'  => (int)($f['fontWeight'] ?? $f['font_weight'] ?? 400),
                'font_size_pt' => (float)($f['font_size_pt'] ?? ($f['fontSize'] ?? 10) / 4.166),
                'color'        => (string)($f['fill'] ?? $f['color'] ?? '#ffffff'),
                'text_align'   => (string)($f['textAlign'] ?? 'left'),
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
