<?php
/**
 * Public "Download Card as PDF" endpoint.
 *
 * URL:    /card-pdf.php?i=<employee_id>
 * Output: A4 PDF, page 1 = canonical front of card, page 2 = canonical back.
 *         Falls back to a text/QR layout only when the canonical PNGs are
 *         missing (audit-card-surfaces.php will flag those employees).
 *
 * Source of truth: generated_cards.{front,back}_file_path, populated by the
 * admin Fabric.js editor. Same PNG that digital_card.php, wallet passes,
 * og:image, and the print-shop preview render. See includes/CardRenderer.php.
 */

ob_start();

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    require_once __DIR__ . '/config.php';
    require_once INCLUDES_DIR . '/CardRenderer.php';
    require_once INCLUDES_DIR . '/QRTracker.php';

    $employeeId = trim($_GET['i'] ?? '');
    if ($employeeId === '') {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Missing employee id (use ?i=<employee_id>)';
        exit;
    }

    $ctx = CardRenderer::forEmployee($employeeId);
    if (!$ctx || ($ctx['employee']['status'] ?? 'active') !== 'active') {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Card not found';
        exit;
    }

    $employee = $ctx['employee'];
    $company  = $ctx['company'];

    // Tenant isolation: when served from a tenant subdomain
    // (<slug>.cardify.om), refuse to leak another tenant's employee. Caught
    // 7 May 2026 in the verify-everything loop (iter 21): hosn.cardify.om
    // was serving Otech employee PDFs because no host check existed.
    require_once INCLUDES_DIR . '/TenantHost.php';
    if (TenantHost::isTenantHost()) {
        $tenantSlug = (string) TenantHost::slug();
        if ($tenantSlug !== '' && $tenantSlug !== ($company['slug'] ?? '')) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Card not found';
            exit;
        }
    }

    // Prefer the vector PDF when the template was imported with a
    // vector source. Falls back to the existing PNG-in-PDF path
    // when the renderer is unavailable.
    //
    // Profile selection by role:
    //   print_shop / super_admin            → clean (no watermark)
    //   tenant admin downloading their OWN  → clean press file when ?print=1
    //     staff's card (admin/employees.php)   (full font embed, no watermark)
    //   anyone else (employee, anonymous)   → 'sample' (watermarked)
    // The clean download is admin-gated and tenant-scoped: a company admin
    // can only pull the press-ready file for an employee of their own company.
    require_once INCLUDES_DIR . '/CardPDFRenderer.php';
    require_once INCLUDES_DIR . '/Auth.php';
    $callerRole  = Auth::isLoggedIn() ? (string)Auth::getCurrentRole() : '';
    $wantsPrint  = (($_GET['print'] ?? '') === '1');
    $adminRoles  = ['admin', 'company', 'company_admin'];
    $callerCompany = function_exists('getCurrentCompanyId') ? (string)getCurrentCompanyId() : '';
    $ownsEmployee  = $callerCompany !== '' && $callerCompany === (string)($company['id'] ?? '');

    if (in_array($callerRole, ['print_shop', 'super_admin'], true)) {
        // Print shop / super admin: the all-vector CMYK print file (original
        // vector artwork, fonts match the design, exact brand CMYK, no
        // watermark). Falls back to the raster 'print' if vector is unavailable.
        $profile = $wantsPrint ? 'vector' : 'web';
    } elseif ($wantsPrint && in_array($callerRole, $adminRoles, true) && $ownsEmployee) {
        // Tenant admin pulling the print-ready file for their own staff.
        $profile = 'vector';
    } else {
        $profile = 'sample';
    }
    $vector = CardPDFRenderer::render((string)$employee['id'], $profile);
    if (empty($vector['success']) && $profile === 'vector') {
        $profile = 'print';
        $vector = CardPDFRenderer::render((string)$employee['id'], $profile);
    }
    if (!empty($vector['success']) && is_file($vector['path'])) {
        try { QRTracker::logScan($employee['id'], $company['id']); } catch (Throwable $e) {}
        while (ob_get_level()) { ob_end_clean(); }
        $name = trim((string)($employee['name_en'] ?? $employee['name'] ?? '')) ?: 'Employee';
        $suffix = in_array($profile, ['print', 'press', 'vector'], true) ? '-print-ready' : '';
        $downloadName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) . $suffix . '.pdf';
        if ($downloadName === $suffix . '.pdf') $downloadName = 'business-card' . $suffix . '.pdf';
        $mtime = filemtime($vector['path']);
        $lastModified = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
        // 304 Not Modified shortcut for conditional GET
        $ifMod = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
        if ($ifMod !== '') {
            $reqTime = strtotime($ifMod);
            if ($reqTime !== false && $reqTime >= $mtime) {
                http_response_code(304);
                header('Last-Modified: ' . $lastModified);
                header('Cache-Control: private, max-age=86400');
                header('X-Cardify-Pdf-Mode: vector-304');
                exit;
            }
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . filesize($vector['path']));
        header('Last-Modified: ' . $lastModified);
        header('Cache-Control: private, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        header('X-Cardify-Pdf-Mode: vector');
        readfile($vector['path']);
        exit;
    }
    // Otherwise fall through to the existing canvas-PNG-embed fallback.

    $theme    = $ctx['theme'];
    $frontFs  = $ctx['front_fs'];
    $backFs   = $ctx['back_fs'];

    $accent = ($theme && !empty($theme['primary_color'])) ? $theme['primary_color'] : '#d4af37';
    if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $accent)) {
        $accent = '#d4af37';
    }

    // The share URL, not a hand-built one. Building '/' . id here printed a
    // dead link on the PDF and encoded it into the QR: nginx routes a bare
    // token only when it is a single no-dot word or first.last, so an id like
    // "abdalah.ah.tm" 404'd while /card/abdalah.ah.tm answered 200.
    // CardifyConvention::employeeShareUrl() already knows all of that.
    require_once INCLUDES_DIR . '/CardifyConvention.php';
    $cardUrl = CardifyConvention::employeeShareUrl((string) $company['slug'], $employee);

    $name      = trim((string)($employee['name_en'] ?? $employee['name'] ?? '')) ?: 'Employee';
    $companyNm = trim((string)$company['name']);
    $year      = date('Y');

    $hasCanonical = $frontFs !== null && $backFs !== null;

    if ($hasCanonical) {
        $html = card_pdf_render_canonical($frontFs, $backFs, $cardUrl, $name, $companyNm, $accent, $year);
    } else {
        error_log(sprintf(
            'card-pdf: canonical PNG missing for employee=%s company=%s, falling back to text layout',
            $employee['id'], $company['id']
        ));
        $html = card_pdf_render_fallback($employee, $company, $cardUrl, $accent);
    }

    $cacheDir = BASE_DIR . '/tmp/pdf-cards';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }

    // Cache key includes CardRenderer signature so a template/theme/employee
    // change invalidates the cached PDF too. Mode is keyed separately so
    // flipping back to a real card busts the cache.
    $cacheKey = sha1(
        $employee['id']
        . '|sig=' . $ctx['signature']
        . '|mode=' . ($hasCanonical ? 'canonical' : 'fallback')
    );
    $cachePath = $cacheDir . '/' . $cacheKey . '.pdf';
    $cacheTtl  = 3600;

    $needsRender = !is_file($cachePath)
        || (time() - filemtime($cachePath)) > $cacheTtl
        || filesize($cachePath) < 1024;

    if ($needsRender) {
        $bin = trim((string)@shell_exec('command -v wkhtmltopdf 2>/dev/null'));
        if ($bin === '') {
            $tcpdf = BASE_DIR . '/vendor/tecnickcom/tcpdf/tcpdf.php';
            if (!is_file($tcpdf)) {
                throw new Exception('wkhtmltopdf not available on server');
            }
            require_once $tcpdf;
            $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
            $pdf->SetCreator(SITE_NAME);
            $pdf->SetAuthor(SITE_NAME);
            $pdf->SetTitle($name . ', Business Card');
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->SetMargins(15, 15, 15);
            $pdf->SetAutoPageBreak(true, 15);
            $pdf->AddPage();
            $pdf->writeHTML($html, true, false, true, false, '');
            $pdf->Output($cachePath, 'F');
        } else {
            $tmpHtml = tempnam(sys_get_temp_dir(), 'card-pdf-') . '.html';
            file_put_contents($tmpHtml, $html);

            $cmd = escapeshellcmd($bin)
                 . ' --quiet --encoding utf-8 --enable-local-file-access'
                 . ' --load-error-handling ignore --load-media-error-handling ignore'
                 . ' --page-size A4 --margin-top 15mm --margin-bottom 15mm'
                 . ' --margin-left 15mm --margin-right 15mm'
                 . ' --disable-smart-shrinking'
                 . ' ' . escapeshellarg($tmpHtml)
                 . ' ' . escapeshellarg($cachePath)
                 . ' 2>&1';

            if (trim((string)@shell_exec('command -v timeout 2>/dev/null')) !== '') {
                $cmd = 'timeout 15 ' . $cmd;
            }

            $rc = 0; $out = [];
            exec($cmd, $out, $rc);
            @unlink($tmpHtml);

            if ($rc !== 0 || !is_file($cachePath) || filesize($cachePath) < 1024) {
                error_log('card-pdf wkhtmltopdf failed (rc=' . $rc . '): ' . implode("\n", $out));
                throw new Exception('PDF render failed');
            }
        }
    }

    try { QRTracker::logScan($employee['id'], $company['id']); } catch (Throwable $e) {
        error_log('card-pdf QRTracker: ' . $e->getMessage());
    }

    while (ob_get_level()) { ob_end_clean(); }

    $downloadName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) . '.pdf';
    if ($downloadName === '.pdf') { $downloadName = 'business-card.pdf'; }

    $mtime = filemtime($cachePath);
    $lastModified = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
    // 304 Not Modified shortcut for conditional GET
    $ifMod = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
    if ($ifMod !== '') {
        $reqTime = strtotime($ifMod);
        if ($reqTime !== false && $reqTime >= $mtime) {
            http_response_code(304);
            header('Last-Modified: ' . $lastModified);
            header('Cache-Control: private, max-age=86400');
            header('X-Cardify-Pdf-Mode: raster-fallback-304');
            exit;
        }
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . filesize($cachePath));
    header('Last-Modified: ' . $lastModified);
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    header('X-Cardify-Pdf-Mode: raster-fallback');
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

// ---- Helpers (file-scoped, prefixed to avoid global collisions) ------------

function card_pdf_render_canonical(string $frontFs, string $backFs, string $cardUrl, string $name, string $companyNm, string $accent, string $year): string
{
    $sn = htmlspecialchars($name,      ENT_QUOTES, 'UTF-8');
    $sc = htmlspecialchars($companyNm, ENT_QUOTES, 'UTF-8');
    $su = htmlspecialchars($cardUrl,   ENT_QUOTES, 'UTF-8');
    $sa = htmlspecialchars($accent,    ENT_QUOTES, 'UTF-8');
    $front = 'file://' . $frontFs;
    $back  = 'file://' . $backFs;

    return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{$sn}, Business Card</title>
<style>
@page { size: A4; margin: 15mm; }
*{box-sizing:border-box}
html,body{margin:0;padding:0;font-family:"Helvetica","Arial",sans-serif;color:#1f2937}
.page{width:100%;page-break-after:always;display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:90vh}
.page:last-of-type{page-break-after:auto}
.label{align-self:flex-start;font-size:9pt;letter-spacing:.12em;text-transform:uppercase;color:#6b7280;margin-bottom:8mm}
.label .accent{color:{$sa};font-weight:600}
.card-wrap{width:100%;max-width:170mm;display:flex;align-items:center;justify-content:center}
.card-wrap img{width:100%;height:auto;display:block;border-radius:4mm;box-shadow:0 1mm 3mm rgba(0,0,0,.08)}
.meta{margin-top:10mm;text-align:center;font-size:10pt;color:#4b5563}
.meta strong{color:#111827;font-size:12pt}
.meta .url{color:#374151;word-break:break-all;margin-top:2mm}
.footer{position:fixed;bottom:6mm;left:15mm;right:15mm;text-align:center;font-size:8.5pt;color:#9ca3af;border-top:1px solid #e5e7eb;padding-top:3mm}
</style>
</head>
<body>

<div class="page">
    <div class="label">Front <span class="accent">/ {$sc}</span></div>
    <div class="card-wrap"><img src="{$front}" alt="Front of card"></div>
    <div class="meta">
        <strong>{$sn}</strong><br>
        <span class="url">{$su}</span>
    </div>
</div>

<div class="page">
    <div class="label">Back <span class="accent">/ {$sc}</span></div>
    <div class="card-wrap"><img src="{$back}" alt="Back of card"></div>
</div>

<div class="footer">
    Generated by Cardify &middot; cardify.om &middot; &copy; {$year}
</div>

</body>
</html>
HTML;
}

function card_pdf_render_fallback(array $employee, array $company, string $cardUrl, string $accent): string
{
    $name      = trim((string)($employee['name_en'] ?? $employee['name'] ?? '')) ?: 'Employee';
    $position  = trim((string)($employee['position'] ?? $employee['job_title'] ?? ''));
    $companyNm = trim((string)$company['name']);
    $phone     = trim((string)($employee['phone']  ?? ''));
    $mobile    = trim((string)($employee['mobile'] ?? ''));
    $email     = trim((string)($employee['email']  ?? ''));
    $website   = trim((string)($employee['website']?? ''));
    $address   = trim((string)($company['address'] ?? ''));
    $year      = date('Y');

    $sn = htmlspecialchars($name,      ENT_QUOTES, 'UTF-8');
    $sp = htmlspecialchars($position,  ENT_QUOTES, 'UTF-8');
    $sc = htmlspecialchars($companyNm, ENT_QUOTES, 'UTF-8');
    $sa = htmlspecialchars($accent,    ENT_QUOTES, 'UTF-8');
    $su = htmlspecialchars($cardUrl,   ENT_QUOTES, 'UTF-8');
    $sm = htmlspecialchars($mobile,    ENT_QUOTES, 'UTF-8');
    $sph= htmlspecialchars($phone,     ENT_QUOTES, 'UTF-8');
    $se = htmlspecialchars($email,     ENT_QUOTES, 'UTF-8');
    $sw = htmlspecialchars($website,   ENT_QUOTES, 'UTF-8');
    $sad= htmlspecialchars($address,   ENT_QUOTES, 'UTF-8');

    $qrSrc = '';
    try {
        require_once INCLUDES_DIR . '/phpqrcode.php';
        $qrTmp = tempnam(sys_get_temp_dir(), 'card-qr-') . '.png';
        QRcode::png($cardUrl, $qrTmp, 'M', 8, 2);
        if (is_file($qrTmp) && filesize($qrTmp) > 0) {
            $qrSrc = 'file://' . $qrTmp;
        }
    } catch (Throwable $e) { /* QR optional */ }

    $rows = '';
    if ($sm  !== '') $rows .= "<div class=\"row\"><span class=\"label\">Mobile</span>{$sm}</div>";
    if ($sph !== '' && $sph !== $sm) $rows .= "<div class=\"row\"><span class=\"label\">Phone</span>{$sph}</div>";
    if ($se  !== '') $rows .= "<div class=\"row\"><span class=\"label\">Email</span>{$se}</div>";
    if ($sw  !== '') $rows .= "<div class=\"row\"><span class=\"label\">Web</span>{$sw}</div>";
    if ($sad !== '') $rows .= "<div class=\"row\"><span class=\"label\">Address</span>{$sad}</div>";

    $qrTag = $qrSrc !== '' ? '<img src="' . htmlspecialchars($qrSrc, ENT_QUOTES, 'UTF-8') . '" alt="QR">' : '';

    $head = <<<HTML
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>{$sn}, Business Card</title>
<style>
@page { size: A4; margin: 25mm 20mm; }
*{box-sizing:border-box}body{font-family:"Helvetica","Arial",sans-serif;color:#1f2937;margin:0;font-size:12pt;line-height:1.55}
.accent-bar{height:6px;background:{$sa};margin-bottom:28px;border-radius:3px}
h1{font-size:28pt;margin:0 0 6px 0;letter-spacing:-0.5px;color:#111827}
.position{font-size:13pt;color:#4b5563;margin:0 0 4px 0}
.company{font-size:13pt;color:{$sa};font-weight:600;margin:0 0 24px 0}
.divider{border:0;border-top:1px solid #e5e7eb;margin:24px 0}
.label{display:inline-block;width:85px;color:#6b7280;font-size:10pt;text-transform:uppercase;letter-spacing:.06em}
.row{margin:8px 0;font-size:12pt}
.qr-page{page-break-before:always;text-align:center;padding-top:40mm}
.qr-page img{width:70mm;height:70mm}
.qr-page .url{color:#374151;font-size:10pt;margin-top:4px;word-break:break-all}
.footer{position:fixed;bottom:10mm;left:20mm;right:20mm;text-align:center;font-size:9pt;color:#9ca3af;border-top:1px solid #e5e7eb;padding-top:6px}
</style></head><body>
<div class="accent-bar"></div>
<h1>{$sn}</h1>
HTML;

    return $head
        . ($sp !== '' ? "<p class=\"position\">{$sp}</p>" : '')
        . ($sc !== '' ? "<p class=\"company\">{$sc}</p>" : '')
        . '<hr class="divider">' . $rows
        . '<div class="qr-page">' . $qrTag
        . '<div class="url">' . $su . '</div></div>'
        . '<div class="footer">Generated by Cardify &middot; cardify.om &middot; &copy; ' . $year . '</div>'
        . '</body></html>';
}
