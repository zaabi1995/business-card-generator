<?php
/**
 * A4 cutting-sheet download.
 *
 * URL:    /card-sheet.php?i=<employee_id>[&paper=A4|A3][&rows=&cols=]
 * Output: an imposed A4 PDF (page 1 = fronts, page 2 = backs) of the clean
 *         per-card print render, with corner crop marks, a magenta rounded
 *         CutContour line per card, registration targets and a print-margin
 *         border, matching the BHD press reference.
 *
 * Admin-gated + tenant-scoped (same rule as card-pdf.php?print=1): a tenant
 * admin can only pull their own staff; print_shop / super_admin always allowed.
 * The card artwork stays the clean RGB 'print' render (no CMYK mangling); the
 * cutting detail lives on this sheet.
 */

ob_start();
set_error_handler(function ($severity, $message, $file, $line) {
    // Respect the @ operator / error_reporting so a suppressed warning (e.g.
    // @unlink on a not-yet-created file during auto-fit) doesn't become a fatal.
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    require_once __DIR__ . '/config.php';
    require_once INCLUDES_DIR . '/CardRenderer.php';
    require_once INCLUDES_DIR . '/CardPDFRenderer.php';
    require_once INCLUDES_DIR . '/Auth.php';
    require_once INCLUDES_DIR . '/TenantHost.php';

    $employeeId = trim($_GET['i'] ?? '');
    if ($employeeId === '') {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Missing employee id (use ?i=<employee_id>)';
        exit;
    }

    $ctx = CardRenderer::forEmployee($employeeId);
    if (!$ctx) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Card not found';
        exit;
    }
    $employee = $ctx['employee'];
    $company  = $ctx['company'];

    // Tenant isolation on subdomains.
    if (TenantHost::isTenantHost()) {
        $tenantSlug = (string) TenantHost::slug();
        if ($tenantSlug !== '' && $tenantSlug !== ($company['slug'] ?? '')) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Card not found';
            exit;
        }
    }

    // Auth: production file. print_shop / super_admin, or a tenant admin pulling
    // their OWN staff. Everyone else denied (no watermarked public variant).
    $callerRole    = Auth::isLoggedIn() ? (string) Auth::getCurrentRole() : '';
    $adminRoles    = ['admin', 'company', 'company_admin'];
    $callerCompany = function_exists('getCurrentCompanyId') ? (string) getCurrentCompanyId() : '';
    $ownsEmployee  = $callerCompany !== '' && $callerCompany === (string) ($company['id'] ?? '');
    $allowed = in_array($callerRole, ['print_shop', 'super_admin'], true)
            || (in_array($callerRole, $adminRoles, true) && $ownsEmployee);
    if (!$allowed) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden';
        exit;
    }

    // UV screen-print mode (?uv=1): impose the tenant's spot-UV separation
    // (solid black = UV, the screen printer's convention) instead of the card
    // artwork, with the SAME layout ladder, die line and registration marks so
    // the UV film registers with the printed sheet. The mask is a per-tenant
    // asset (see CardPDFRenderer::uvMaskPathFor); the sheet stays white.
    $uvMode = (($_GET['uv'] ?? '') === '1');
    $uvMask = null;
    if ($uvMode) {
        $uvMask = CardPDFRenderer::uvMaskPathFor((string) ($company['id'] ?? ''), (string) ($company['slug'] ?? ''));
        if ($uvMask === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'No UV layer is configured for this company';
            exit;
        }
    }

    $paper = ($_GET['paper'] ?? 'A4') === 'A3' ? 'A3' : 'A4';
    // Auto-fit unless an explicit rows/cols was requested. Landscape cards on
    // portrait A4 typically yield 4x2 = 8-up; densest-that-fits is chosen.
    $autoFit = !isset($_GET['rows']) && !isset($_GET['cols']);
    $rows  = max(1, min(10, (int) ($_GET['rows'] ?? 4)));
    $cols  = max(1, min(5,  (int) ($_GET['cols'] ?? 2)));

    // All-vector per-card render (original vector source.pdf as bg, designer
    // sample redacted, full-font overlay). Scalable + clean, type matches the
    // design. Falls back to the raster 'print' render if vector is unavailable.
    // UV mode skips the artwork render entirely: the mask PDF (same page box
    // as the vector card PDF) IS the card the imposition tiles.
    $rasterTmp = '';
    if ($uvMode) {
        $rendered = ['success' => true, 'path' => $uvMask];
    } else {
        $rendered = CardPDFRenderer::render((string) $employee['id'], 'vector');
        if (empty($rendered['success']) || empty($rendered['path']) || !is_file($rendered['path'])) {
            $rendered = CardPDFRenderer::render((string) $employee['id'], 'print');
        }
        // Raster fallback: templates imported from an uploaded image (has_vector_
        // source=0, e.g. OHB) can't produce a vector PDF, so build a card-sized
        // 2-page PDF from the saved Fabric render PNGs. The imposition step tiles
        // whatever single-card PDF it's given, so a raster card images the same.
        if (empty($rendered['success']) || empty($rendered['path']) || !is_file($rendered['path'])) {
            $rasterTmp = cardsheet_build_raster_card_pdf($employee, $company);
            if ($rasterTmp !== '') {
                $rendered = ['success' => true, 'path' => $rasterTmp];
            }
        }
    }
    if (empty($rendered['success']) || empty($rendered['path']) || !is_file($rendered['path'])) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Card PDF unavailable (no card generated yet)';
        exit;
    }

    // Corner radius for the cut line (Otech spec ~1.5mm; a sane rounded
    // business-card corner for everyone else).
    $cutRadius = 1.5;

    // CMYK brand config (when configured): set the background fill to the EXACT
    // brand RGB so it converts to the same CMYK as the card edge (seamless), and
    // convert the sheet's own marks/fill to CMYK. Cards are already CMYK.
    $cmykCfg = CardPDFRenderer::pressCmykConfigFor((string) ($company['id'] ?? ''), (string) ($company['slug'] ?? ''));
    $sheetBg = 'auto';
    $tmpCmyk = '';
    if (is_array($cmykCfg)) {
        if (!empty($cmykCfg['colors'][0]['rgb']) && is_array($cmykCfg['colors'][0]['rgb'])) {
            $sheetBg = implode(',', array_map('intval', $cmykCfg['colors'][0]['rgb']));
        }
        if (!empty($cmykCfg['corner_radius_mm'])) $cutRadius = (float) $cmykCfg['corner_radius_mm'];
        $tmpCmyk = tempnam(sys_get_temp_dir(), 'sheetcmyk_') . '.json';
        file_put_contents($tmpCmyk, json_encode($cmykCfg, JSON_UNESCAPED_UNICODE));
    }
    // The UV separation prints black on a WHITE sheet: no brand-colour
    // underlay, or the film would carry the background as UV.
    if ($uvMode) $sheetBg = 'none';

    // The magenta rounded CutContour (round-corner die line) is ON by default for
    // any tenant whose press config declares a corner radius, because that radius
    // only exists when their cards are actually die-cut with rounded corners
    // (Otech: 4.259mm, per their Illustrator cutting-mark file). Tenants with no
    // corner_radius_mm keep the plain straight guillotine cut. ?round=0 forces
    // square corners, ?round=1 forces the die line, both override the default.
    $roundDefault = is_array($cmykCfg) && !empty($cmykCfg['corner_radius_mm']);
    $roundCut = isset($_GET['round'])
        ? ($_GET['round'] === '1')
        : $roundDefault;

    // Bleed-tile mode: the finished cards butt together and each card's design
    // bleeds `inset` mm past every cut line (no flat colour margin), so 5x2 = 10
    // fit an A4. Used for RASTER designs, whose PDF page IS the artwork with no
    // baked bleed - we cut ~1mm INTO the design so its background reaches the cut
    // (override with ?inset=<mm>). Vector print PDFs keep their existing,
    // size-stable flat-background geometry so a tenant's finished card size never
    // changes underneath them.
    $bleedTile = ($rasterTmp !== '');
    $trimInset = isset($_GET['inset']) ? (float) $_GET['inset'] : 1.0;
    $trimInset = max(0.3, min(4.0, $trimInset));

    $outDir = BASE_DIR . '/data/print-sheets';
    if (!is_dir($outDir)) @mkdir($outDir, 0775, true);
    $slug = preg_replace('/[^a-z0-9]+/i', '-', (string) $employee['id']);
    $outPath = $outDir . '/' . ($uvMode ? 'uvsheet' : 'cutsheet') . '-' . $slug . '-' . $paper . '-' . date('Ymd-His') . '.pdf';

    $py = trim((string) @shell_exec('command -v python3 2>/dev/null')) ?: 'python3';
    $timeoutPrefix = (trim((string) @shell_exec('command -v timeout 2>/dev/null')) !== '') ? 'timeout 60 ' : '';
    // Densest-first layouts that fit a landscape card on portrait A4 (10-up first).
    $layouts = $autoFit ? [[5, 2], [4, 2], [4, 1], [3, 2], [3, 1], [2, 2], [2, 1], [1, 1]] : [[$rows, $cols]];
    $out = []; $rc = -1;
    foreach ($layouts as [$r, $c]) {
        $cmd = $timeoutPrefix . escapeshellarg($py)
             . ' ' . escapeshellarg(BASE_DIR . '/scripts/imposition-vector.py')
             . ' --card ' . escapeshellarg($rendered['path'])
             . ' --paper ' . escapeshellarg($paper)
             . ' --rows ' . $r . ' --cols ' . $c
             . ' --margin-mm 5'
             . ' --all-pages'                        // front sheet + back sheet
             . ' --reg-marks'
             . ' --sheet-bg ' . escapeshellarg($sheetBg)   // brand colour / safety underlay
             . ($bleedTile
                 ? ' --trim-inset-mm ' . escapeshellarg((string) $trimInset)
                   . ($roundCut ? ' --round-cut --cut-radius-mm ' . escapeshellarg((string) $cutRadius) : '')
                 : ' --gutter-mm 2 --bleed-mm 3'
                   . ($roundCut ? ' --cut-radius-mm ' . escapeshellarg((string) $cutRadius) : ''))
             . ($tmpCmyk !== '' ? ' --cmyk ' . escapeshellarg($tmpCmyk) : '')
             . ' --out ' . escapeshellarg($outPath)
             . ' 2>&1';
        $out = []; $rc = 0;
        exec($cmd, $out, $rc);
        if ($rc === 0 && is_file($outPath) && filesize($outPath) >= 1024) break;
        if (is_file($outPath)) @unlink($outPath);
    }
    if ($tmpCmyk !== '' && is_file($tmpCmyk)) @unlink($tmpCmyk);
    if ($rasterTmp !== '' && is_file($rasterTmp)) @unlink($rasterTmp); // consumed by imposition
    if ($rc !== 0 || !is_file($outPath) || filesize($outPath) < 1024) {
        error_log('card-sheet imposition failed rc=' . $rc . ' out=' . implode("\n", $out));
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Could not build the cutting sheet';
        exit;
    }
    @chmod($outPath, 0644);

    while (ob_get_level()) { ob_end_clean(); }
    $name = trim((string) ($employee['name_en'] ?? $employee['name'] ?? '')) ?: 'card';
    $downloadName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name)
        . ($uvMode ? '-UV-print-file.pdf' : '-A4-cutting-sheet.pdf');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . filesize($outPath));
    header('Cache-Control: private, no-store');
    readfile($outPath);
    @unlink($outPath); // sheets are cheap to regenerate; don't accumulate
    exit;

} catch (Throwable $e) {
    while (ob_get_level()) { ob_end_clean(); }
    error_log('card-sheet error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Server error';
    exit;
}

/**
 * Raster fallback: build a card-sized 2-page PDF (front, back) from the saved
 * Fabric render PNGs for a template that has no vector source. Returns the tmp
 * PDF path, or '' on failure. The imposition step tiles this exactly like the
 * vector card PDF.
 */
function cardsheet_build_raster_card_pdf(array $employee, array $company): string
{
    try {
        $db  = Database::getInstance();
        $cid = (string) ($company['id'] ?? '');
        $eid = (string) ($employee['id'] ?? '');
        if ($cid === '' || $eid === '') return '';

        // Latest saved card PNGs for this employee.
        $row = $db->fetchOne(
            "SELECT front_file_path, back_file_path FROM generated_cards
             WHERE employee_id = :e AND front_file_path IS NOT NULL AND front_file_path <> ''
             ORDER BY generated_at DESC LIMIT 1",
            ['e' => $eid]
        );
        if (!$row || empty($row['front_file_path'])) return '';

        $cardsDir = function_exists('getCompanyCardsDir') ? getCompanyCardsDir($cid) : (BASE_DIR . '/uploads/companies/' . $cid . '/cards');
        $front = $cardsDir . '/' . basename((string) $row['front_file_path']);
        $back  = !empty($row['back_file_path']) ? $cardsDir . '/' . basename((string) $row['back_file_path']) : '';
        if (!is_file($front)) return '';
        if ($back !== '' && !is_file($back)) $back = '';

        // Physical card size from the template settings (mm); default 85x55.
        [$wMm, $hMm] = cardsheet_card_mm($db, $cid);

        $outPath = tempnam(sys_get_temp_dir(), 'rastercard_') . '.pdf';
        $py = trim((string) @shell_exec('command -v python3 2>/dev/null')) ?: 'python3';
        $cmd = escapeshellarg($py) . ' ' . escapeshellarg(BASE_DIR . '/scripts/raster-card-pdf.py')
             . ' --front ' . escapeshellarg($front)
             . ($back !== '' ? ' --back ' . escapeshellarg($back) : '')
             . ' --width-mm ' . escapeshellarg((string) $wMm)
             . ' --height-mm ' . escapeshellarg((string) $hMm)
             . ' --out ' . escapeshellarg($outPath)
             . ' 2>&1';
        $out = []; $rc = 0;
        exec($cmd, $out, $rc);
        if ($rc !== 0 || !is_file($outPath) || filesize($outPath) < 512) {
            error_log('cardsheet raster fallback failed rc=' . $rc . ' out=' . implode("\n", $out));
            if (is_file($outPath)) @unlink($outPath);
            return '';
        }
        return $outPath;
    } catch (Throwable $e) {
        error_log('cardsheet raster fallback error: ' . $e->getMessage());
        return '';
    }
}

/** Card trim size in mm from the front template's settings_json (default 85x55). */
function cardsheet_card_mm(Database $db, string $companyId): array
{
    $wMm = 85.0; $hMm = 55.0;
    try {
        $tpl = $db->fetchOne(
            "SELECT settings_json FROM templates
             WHERE company_id = :c AND deleted_at IS NULL AND side = 'front'
             ORDER BY has_vector_source DESC, created_at DESC LIMIT 1",
            ['c' => $companyId]
        );
        $s = $tpl ? (json_decode((string) ($tpl['settings_json'] ?? ''), true) ?: []) : [];
        $w = (float) ($s['customWidth'] ?? 0);
        $h = (float) ($s['customHeight'] ?? 0);
        $unit = strtolower((string) ($s['customUnit'] ?? 'mm'));
        if ($w > 0 && $h > 0) {
            $toMm = $unit === 'pt' ? (25.4 / 72.0) : ($unit === 'in' ? 25.4 : 1.0);
            $wMm = $w * $toMm;
            $hMm = $h * $toMm;
        }
    } catch (Throwable $e) { /* defaults */ }
    return [round($wMm, 3), round($hMm, 3)];
}
