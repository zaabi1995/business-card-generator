<?php
/**
 * Public "Download Card as PDF" endpoint.
 *
 * URL:    /card-pdf.php?i=<employee_id>
 * Output: A4 PDF with employee contact details on page 1 and a QR code
 *         pointing to the public card URL on page 2.
 *
 * Implementation notes:
 *   - Uses the `wkhtmltopdf` binary (already installed on the VPS).
 *     No composer additions required.
 *   - QR image is generated via chart.googleapis.com, mirroring the
 *     pattern already used by includes/VCF.php::getQRCodeUrl().
 *   - Caches rendered PDFs under BASE_DIR/tmp/pdf-cards/ for 1 hour to
 *     keep response time < 2s on repeat downloads.
 */

ob_start();

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    require_once __DIR__ . '/config.php';
    require_once INCLUDES_DIR . '/VCF.php';
    require_once INCLUDES_DIR . '/QRTracker.php';

    $employeeId = trim($_GET['i'] ?? '');
    if ($employeeId === '') {
        throw new Exception('Missing employee id');
    }

    $db = Database::getInstance();
    $employee = $db->fetchOne(
        "SELECT e.*, c.id AS _cid, c.slug AS _cslug, c.name AS _cname, c.country AS _ccountry
           FROM employees e
           JOIN companies c ON c.id = e.company_id
          WHERE e.id = :id AND e.status = 'active'
          LIMIT 1",
        ['id' => $employeeId]
    );
    if (!$employee) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Card not found';
        exit;
    }

    $company = [
        'id'      => $employee['_cid'],
        'slug'    => $employee['_cslug'],
        'name'    => $employee['_cname'],
        'country' => $employee['_ccountry'] ?? '',
    ];

    // Theme (for accent colour). Missing theme is fine — we fall back.
    // We deliberately query directly instead of relying on loadCompanyTheme(),
    // which is defined inside digital_card.php rather than includes/functions.php.
    $theme = null;
    try {
        $theme = $db->fetchOne(
            "SELECT * FROM company_themes WHERE company_id = :cid",
            ['cid' => $company['id']]
        );
    } catch (Throwable $e) {
        error_log('card-pdf theme lookup: ' . $e->getMessage());
    }
    $accent = ($theme && !empty($theme['primary_color'])) ? $theme['primary_color'] : '#d4af37';
    // Sanity-clamp to CSS-safe hex; anything else falls back.
    if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $accent)) {
        $accent = '#d4af37';
    }

    // Canonical URL of the public card page (used in QR).
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'cardify.om';
    $cardUrl = $scheme . '://' . $host . '/' . rawurlencode($company['slug']) . '/card/' . rawurlencode($employee['id']);

    $name       = trim((string)($employee['name_en'] ?? $employee['name'] ?? ''));
    if ($name === '') { $name = 'Employee'; }
    $position   = trim((string)($employee['position'] ?? $employee['job_title'] ?? ''));
    $companyNm  = trim((string)$company['name']);
    $phone      = trim((string)($employee['phone'] ?? ''));
    $mobile     = trim((string)($employee['mobile'] ?? ''));
    $email      = trim((string)($employee['email'] ?? ''));
    $website    = trim((string)($employee['website'] ?? ''));
    $address    = trim((string)($company['address'] ?? ''));

    $safeName   = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safePos    = htmlspecialchars($position, ENT_QUOTES, 'UTF-8');
    $safeCoName = htmlspecialchars($companyNm, ENT_QUOTES, 'UTF-8');
    $safePhone  = htmlspecialchars($phone, ENT_QUOTES, 'UTF-8');
    $safeMobile = htmlspecialchars($mobile, ENT_QUOTES, 'UTF-8');
    $safeEmail  = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    $safeWeb    = htmlspecialchars($website, ENT_QUOTES, 'UTF-8');
    $safeAddr   = htmlspecialchars($address, ENT_QUOTES, 'UTF-8');
    $safeUrl    = htmlspecialchars($cardUrl, ENT_QUOTES, 'UTF-8');
    $safeAccent = htmlspecialchars($accent, ENT_QUOTES, 'UTF-8');
    $year       = date('Y');

    // QR code generated locally with phpqrcode (LGPL) — no external API
    // calls, survives PHP-FPM with no outbound network.
    $qrSrc = '';
    $qrTmp = '';
    try {
        require_once INCLUDES_DIR . '/phpqrcode.php';
        $qrTmp = tempnam(sys_get_temp_dir(), 'card-qr-') . '.png';
        // QR_ECLEVEL_M = ~15% recovery, size 8, margin 2 → ~500x500
        QRcode::png($cardUrl, $qrTmp, 'M', 8, 2);
        if (is_file($qrTmp) && filesize($qrTmp) > 0) {
            $qrSrc = 'file://' . $qrTmp;
        }
    } catch (Throwable $e) {
        error_log('card-pdf QR gen: ' . $e->getMessage());
    }

    // -------- Build printable HTML ----------------------------------------
    $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{$safeName} — Business Card</title>
<style>
    @page { size: A4; margin: 25mm 20mm; }
    * { box-sizing: border-box; }
    body {
        font-family: "Helvetica", "Arial", sans-serif;
        color: #1f2937;
        margin: 0;
        font-size: 12pt;
        line-height: 1.55;
    }
    .accent-bar {
        height: 6px;
        background: {$safeAccent};
        margin-bottom: 28px;
        border-radius: 3px;
    }
    h1 {
        font-size: 28pt;
        margin: 0 0 6px 0;
        letter-spacing: -0.5px;
        color: #111827;
    }
    .position { font-size: 13pt; color: #4b5563; margin: 0 0 4px 0; }
    .company  { font-size: 13pt; color: {$safeAccent}; font-weight: 600; margin: 0 0 24px 0; }
    .divider  { border: 0; border-top: 1px solid #e5e7eb; margin: 24px 0; }
    .label    { display: inline-block; width: 85px; color: #6b7280; font-size: 10pt; text-transform: uppercase; letter-spacing: 0.06em; }
    .row      { margin: 8px 0; font-size: 12pt; }
    .row a    { color: #1f2937; text-decoration: none; }
    .qr-page  { page-break-before: always; text-align: center; padding-top: 40mm; }
    .qr-page img { width: 70mm; height: 70mm; }
    .qr-page .hint   { color: #4b5563; font-size: 11pt; margin-top: 18px; }
    .qr-page .url    { color: #374151; font-size: 10pt; margin-top: 4px; word-break: break-all; }
    .footer {
        position: fixed;
        bottom: 10mm;
        left: 20mm;
        right: 20mm;
        text-align: center;
        font-size: 9pt;
        color: #9ca3af;
        border-top: 1px solid #e5e7eb;
        padding-top: 6px;
    }
</style>
</head>
<body>

<div class="accent-bar"></div>

<h1>{$safeName}</h1>
HTML;

    if ($safePos !== '')    { $html .= "<p class=\"position\">{$safePos}</p>"; }
    if ($safeCoName !== '') { $html .= "<p class=\"company\">{$safeCoName}</p>"; }

    $html .= '<hr class="divider">';

    if ($safeMobile !== '') {
        $html .= "<div class=\"row\"><span class=\"label\">Mobile</span>{$safeMobile}</div>";
    }
    if ($safePhone !== '' && $safePhone !== $safeMobile) {
        $html .= "<div class=\"row\"><span class=\"label\">Phone</span>{$safePhone}</div>";
    }
    if ($safeEmail !== '') {
        $html .= "<div class=\"row\"><span class=\"label\">Email</span><a href=\"mailto:{$safeEmail}\">{$safeEmail}</a></div>";
    }
    if ($safeWeb !== '') {
        $html .= "<div class=\"row\"><span class=\"label\">Web</span><a href=\"{$safeWeb}\">{$safeWeb}</a></div>";
    }
    if ($safeAddr !== '') {
        $html .= "<div class=\"row\"><span class=\"label\">Address</span>{$safeAddr}</div>";
    }

    $html .= '<div class="qr-page">';
    if ($qrSrc !== '') {
        $html .= '<img src="' . htmlspecialchars($qrSrc, ENT_QUOTES, 'UTF-8') . '" alt="QR code">';
    }
    $html .= <<<HTML
    <div class="hint">Scan to view live digital card</div>
    <div class="url">{$safeUrl}</div>
</div>

<div class="footer">
    Generated by Cardify &middot; cardify.om &middot; &copy; {$year} BHD Printing &amp; Designing
</div>

</body>
</html>
HTML;

    // -------- Render via wkhtmltopdf --------------------------------------
    // Small filesystem cache to keep repeat downloads fast.
    $cacheDir = BASE_DIR . '/tmp/pdf-cards';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }
    $cacheKey  = sha1($employee['id'] . '|' . ($employee['updated_at'] ?? '') . '|' . $cardUrl);
    $cachePath = $cacheDir . '/' . $cacheKey . '.pdf';
    $cacheTtl  = 3600;

    $needsRender = !is_file($cachePath)
        || (time() - filemtime($cachePath)) > $cacheTtl
        || filesize($cachePath) < 1024;

    if ($needsRender) {
        // `command -v` returns the path even when open_basedir hides it from PHP stat.
        // We trust exec() (which bypasses open_basedir) rather than is_file() here.
        $bin = trim((string)@shell_exec('command -v wkhtmltopdf 2>/dev/null'));
        if ($bin === '') {
            // Fallback to TCPDF if composer is available locally.
            $tcpdf = BASE_DIR . '/vendor/tecnickcom/tcpdf/tcpdf.php';
            if (!is_file($tcpdf)) {
                throw new Exception('wkhtmltopdf not available on server');
            }
            require_once $tcpdf;
            $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
            $pdf->SetCreator(SITE_NAME);
            $pdf->SetAuthor(SITE_NAME);
            $pdf->SetTitle($name . ' — Business Card');
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->SetMargins(20, 25, 20);
            $pdf->SetAutoPageBreak(true, 20);
            $pdf->AddPage();
            $pdf->writeHTML($html, true, false, true, false, '');
            $pdf->Output($cachePath, 'F');
        } else {
            $tmpHtml = tempnam(sys_get_temp_dir(), 'card-pdf-') . '.html';
            file_put_contents($tmpHtml, $html);

            $cmd = escapeshellcmd($bin)
                 . ' --quiet --encoding utf-8 --enable-local-file-access'
                 . ' --load-error-handling ignore --load-media-error-handling ignore'
                 . ' --page-size A4 --margin-top 25mm --margin-bottom 25mm'
                 . ' --margin-left 20mm --margin-right 20mm'
                 . ' --disable-smart-shrinking'
                 . ' ' . escapeshellarg($tmpHtml)
                 . ' ' . escapeshellarg($cachePath)
                 . ' 2>&1';

            // 15s timeout guardrail via `timeout` wrapper when available.
            if (trim((string)@shell_exec('command -v timeout 2>/dev/null')) !== '') {
                $cmd = 'timeout 15 ' . $cmd;
            }

            $rc = 0;
            $out = [];
            exec($cmd, $out, $rc);
            @unlink($tmpHtml);
            if ($qrTmp !== '') { @unlink($qrTmp); }

            if ($rc !== 0 || !is_file($cachePath) || filesize($cachePath) < 1024) {
                error_log('card-pdf wkhtmltopdf failed (rc=' . $rc . '): ' . implode("\n", $out));
                throw new Exception('PDF render failed');
            }
        }
    } else {
        if ($qrTmp !== '' && is_file($qrTmp)) { @unlink($qrTmp); }
    }

    // Track this download as a card scan event (non-fatal).
    try {
        QRTracker::logScan($employee['id'], $company['id']);
    } catch (Throwable $e) {
        error_log('card-pdf QRTracker: ' . $e->getMessage());
    }

    // Stream the PDF.
    while (ob_get_level()) { ob_end_clean(); }

    $downloadName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) . '.pdf';
    if ($downloadName === '.pdf') { $downloadName = 'business-card.pdf'; }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . filesize($cachePath));
    header('Cache-Control: private, max-age=300');
    header('X-Content-Type-Options: nosniff');
    readfile($cachePath);
    exit;

} catch (Throwable $e) {
    while (ob_get_level()) { ob_end_clean(); }
    error_log('card-pdf error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Unable to generate PDF. Please try again later.';
    exit;
}
