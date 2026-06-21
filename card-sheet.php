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

    $paper = ($_GET['paper'] ?? 'A4') === 'A3' ? 'A3' : 'A4';
    // Auto-fit unless an explicit rows/cols was requested. Landscape cards on
    // portrait A4 typically yield 4x2 = 8-up; densest-that-fits is chosen.
    $autoFit = !isset($_GET['rows']) && !isset($_GET['cols']);
    $rows  = max(1, min(10, (int) ($_GET['rows'] ?? 4)));
    $cols  = max(1, min(5,  (int) ($_GET['cols'] ?? 2)));

    // All-vector per-card render (original vector source.pdf as bg, designer
    // sample redacted, full-font overlay). Scalable + clean, type matches the
    // design. Falls back to the raster 'print' render if vector is unavailable.
    $rendered = CardPDFRenderer::render((string) $employee['id'], 'vector');
    if (empty($rendered['success']) || empty($rendered['path']) || !is_file($rendered['path'])) {
        $rendered = CardPDFRenderer::render((string) $employee['id'], 'print');
    }
    if (empty($rendered['success']) || empty($rendered['path']) || !is_file($rendered['path'])) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Card PDF unavailable (vector source missing)';
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

    $outDir = BASE_DIR . '/data/print-sheets';
    if (!is_dir($outDir)) @mkdir($outDir, 0775, true);
    $slug = preg_replace('/[^a-z0-9]+/i', '-', (string) $employee['id']);
    $outPath = $outDir . '/cutsheet-' . $slug . '-' . $paper . '-' . date('Ymd-His') . '.pdf';

    $py = trim((string) @shell_exec('command -v python3 2>/dev/null')) ?: 'python3';
    $timeoutPrefix = (trim((string) @shell_exec('command -v timeout 2>/dev/null')) !== '') ? 'timeout 60 ' : '';
    // Densest-first layouts that fit a landscape card on portrait A4.
    $layouts = $autoFit ? [[5, 2], [4, 2], [4, 1], [3, 2], [3, 1], [2, 2], [2, 1], [1, 1]] : [[$rows, $cols]];
    $out = []; $rc = -1;
    foreach ($layouts as [$r, $c]) {
        $cmd = $timeoutPrefix . escapeshellarg($py)
             . ' ' . escapeshellarg(BASE_DIR . '/scripts/imposition-vector.py')
             . ' --card ' . escapeshellarg($rendered['path'])
             . ' --paper ' . escapeshellarg($paper)
             . ' --rows ' . $r . ' --cols ' . $c
             . ' --bleed-mm 3'                       // 3mm background bleed past the card edges
             . ' --all-pages'                        // front sheet + back sheet
             . ' --cut-radius-mm ' . $cutRadius
             . ' --reg-marks'
             . ' --sheet-bg ' . escapeshellarg($sheetBg)   // brand colour (exact) or auto
             . ($tmpCmyk !== '' ? ' --cmyk ' . escapeshellarg($tmpCmyk) : '')
             . ' --out ' . escapeshellarg($outPath)
             . ' 2>&1';
        $out = []; $rc = 0;
        exec($cmd, $out, $rc);
        if ($rc === 0 && is_file($outPath) && filesize($outPath) >= 1024) break;
        if (is_file($outPath)) @unlink($outPath);
    }
    if ($tmpCmyk !== '' && is_file($tmpCmyk)) @unlink($tmpCmyk);
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
    $downloadName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) . '-A4-cutting-sheet.pdf';
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
