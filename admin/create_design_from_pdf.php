<?php
/**
 * Create a card-design pair from an uploaded front/back PDF, dashboard path.
 *
 * Mirrors the onboarding wizard's PDF upload flow but skips the interactive
 * binding-review UI: front + back PDFs are merged, parsed by parse_card_pdf.py,
 * classified by BindingValidator + AIBindingClassifier, and persisted via
 * CardifyTemplateImporter::persist using each block's auto-suggested binding.
 *
 * Body (multipart/form-data):
 *   csrf_token, name, front_pdf (required), back_pdf (optional)
 *
 * Returns: { ok, pair_id, template_ids:{front,back}, ai_used, missing_fonts }
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/CardifyTemplateImporter.php';
require_once INCLUDES_DIR . '/AIBindingClassifier.php';
require_once INCLUDES_DIR . '/BindingValidator.php';
requireAdmin();

header('Content-Type: application/json');

try {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        throw new Exception('Invalid request token. Please refresh and try again.');
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new Exception('method_not_allowed');
    }

    $companyId = function_exists('getCurrentCompanyId') ? getCurrentCompanyId() : null;
    if (!$companyId) {
        throw new Exception('No company context, please log in again');
    }

    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        throw new Exception('Design name is required');
    }

    $hasFront = isset($_FILES['front_pdf']) && $_FILES['front_pdf']['error'] === UPLOAD_ERR_OK;
    $hasBack  = isset($_FILES['back_pdf'])  && $_FILES['back_pdf']['error']  === UPLOAD_ERR_OK;
    if (!$hasFront && !$hasBack) {
        throw new Exception('At least one PDF (front or back) is required');
    }

    $MAX_BYTES = 25 * 1024 * 1024;
    foreach (['front_pdf', 'back_pdf'] as $k) {
        if (isset($_FILES[$k]) && $_FILES[$k]['error'] === UPLOAD_ERR_OK
            && (int)$_FILES[$k]['size'] > $MAX_BYTES) {
            throw new Exception("$k too large (max 25 MB)");
        }
    }

    foreach (['front_pdf', 'back_pdf'] as $k) {
        if (isset($_FILES[$k]) && $_FILES[$k]['error'] === UPLOAD_ERR_OK) {
            $fh = @fopen($_FILES[$k]['tmp_name'], 'rb');
            if (!$fh) throw new Exception("Cannot read $k");
            $magic = fread($fh, 5);
            fclose($fh);
            if (substr($magic, 0, 4) !== '%PDF') {
                throw new Exception("$k is not a valid PDF");
            }
        }
    }

    $token = bin2hex(random_bytes(8));
    $outRel = '/uploads/templates/imports/' . $token;
    $outAbs = realpath(__DIR__ . '/..') . $outRel;
    if (!@mkdir($outAbs, 0755, true) && !is_dir($outAbs)) {
        throw new Exception('Cannot create import dir');
    }

    $frontTmp = $outAbs . '/_front.pdf';
    $backTmp  = $outAbs . '/_back.pdf';
    if ($hasFront && !move_uploaded_file($_FILES['front_pdf']['tmp_name'], $frontTmp)) {
        throw new Exception('Cannot save front PDF');
    }
    if ($hasBack && !move_uploaded_file($_FILES['back_pdf']['tmp_name'], $backTmp)) {
        throw new Exception('Cannot save back PDF');
    }

    // Combine into a single source.pdf so parse_card_pdf.py emits both pages
    // as one envelope. pdfunite preserves vector content, fonts, MediaBox.
    $srcPdf = $outAbs . '/source.pdf';
    if ($hasFront && $hasBack) {
        $cmd = sprintf(
            '/usr/bin/pdfunite %s %s %s 2>&1',
            escapeshellarg($frontTmp),
            escapeshellarg($backTmp),
            escapeshellarg($srcPdf)
        );
        $merged = shell_exec($cmd);
        if (!is_file($srcPdf) || filesize($srcPdf) < 100) {
            throw new Exception('pdfunite failed: ' . substr((string)$merged, 0, 500));
        }
    } elseif ($hasFront) {
        rename($frontTmp, $srcPdf);
    } else {
        // Back-only: still page 1 in the parser. The persisted template will
        // be a "front" template at page_number=1, but we relabel before
        // persist by post-processing the parsed envelope below.
        rename($backTmp, $srcPdf);
    }
    @unlink($frontTmp);
    @unlink($backTmp);

    // Run the same parser the wizard uses
    $installedFontsFile = __DIR__ . '/../uploads/fonts/installed.txt';
    if (!file_exists($installedFontsFile)) {
        @mkdir(dirname($installedFontsFile), 0755, true);
        @file_put_contents($installedFontsFile, "inter\nlato\nsora\nroboto\nopen sans\ncairo\ntajawal\n");
    }

    $stderrLog = $outAbs . '/parser-stderr.log';
    $cmd = sprintf(
        'python3 %s %s %s %s 2>%s',
        escapeshellarg(__DIR__ . '/../scripts/parse_card_pdf.py'),
        escapeshellarg($srcPdf),
        escapeshellarg($outAbs),
        escapeshellarg($installedFontsFile),
        escapeshellarg($stderrLog)
    );
    $parserOut = shell_exec($cmd);
    if (!$parserOut) {
        throw new Exception('parser_no_output: ' . substr((string)@file_get_contents($stderrLog), 0, 500));
    }
    $parsed = json_decode($parserOut, true);
    if ($parsed === null || empty($parsed['pages'])) {
        throw new Exception('parser_failed: ' . substr((string)$parserOut, 0, 500));
    }

    foreach ($parsed['pages'] as &$page) {
        if (!empty($page['background_path'])) {
            $page['background_url'] = $outRel . '/' . $page['background_path'];
        }
        if (!empty($page['background_with_text_path'])) {
            $page['background_with_text_url'] = $outRel . '/' . $page['background_with_text_path'];
        }
        if (!empty($page['background_svg_path'])) {
            $page['background_svg_url'] = $outRel . '/' . $page['background_svg_path'];
        }
    }
    unset($page);

    // Persist the parser envelope so debug + cache invalidation can find it
    $fontsDirRel = !empty($parsed['fonts_dir_rel']) ? ($outRel . '/fonts') : null;
    $envelope = [
        'token'             => $token,
        'company_id'        => $companyId,
        'user_id'           => Auth::getCurrentUser()['id'] ?? null,
        'original_filename' => $name . '.pdf',
        'created_at'        => date('c'),
        'pages'             => $parsed['pages'],
        'fonts_used'        => $parsed['fonts_used']    ?? [],
        'missing_fonts'     => $parsed['missing_fonts'] ?? [],
        'fonts_dir_rel'     => $fontsDirRel,
    ];
    file_put_contents($outAbs . '/parse.json', json_encode($envelope, JSON_UNESCAPED_UNICODE));

    // Build review structure + script/AI-classify each block, same pipeline
    // as printshop/import_pdf.php, then auto-confirm every suggestion as the
    // final binding.
    $reviewPages = [];
    foreach ($parsed['pages'] as $page) {
        $blocks = [];
        foreach (($page['fields'] ?? []) as $idx => $f) {
            $blocks[] = [
                'id'             => 'block_' . $idx,
                'detected_text'  => $f['detected_text'] ?? '',
                'is_static_hint' => !empty($f['is_static']),
                'x'              => (int)($f['x_px'] ?? 0),
                'y'              => (int)($f['y_px'] ?? 0),
                'width'          => (int)($f['w_px'] ?? 0),
                'height'         => (int)($f['h_px'] ?? 0),
                'font_family'    => $f['font_family'] ?? null,
                'font_size_pt'   => $f['font_size_pt'] ?? null,
                'color'          => $f['color'] ?? null,
                'suggested_binding' => CardifyTemplateImporter::suggestBinding($f),
            ];
        }
        $reviewPages[] = [
            'page_number' => $page['page_number'],
            'side'        => $page['side'],
            'blocks'      => $blocks,
        ];
    }

    $ambiguousIds = [];
    foreach ($reviewPages as &$rp) {
        foreach ($rp['blocks'] as &$blk) {
            $script = BindingValidator::scriptClassify((string)($blk['detected_text'] ?? ''));
            if ($script['confident'] && $script['binding'] !== null) {
                $blk['suggested_binding'] = $script['binding'];
                $blk['source'] = 'script';
            } else {
                $ambiguousIds[$blk['id']] = true;
            }
        }
        unset($blk);
    }
    unset($rp);

    $aiResult = ['by_block_id' => [], 'used_ai' => false];
    if (!empty($ambiguousIds)) {
        $aiResult = AIBindingClassifier::classify($reviewPages);
    }

    $bindingsByPage = [];
    foreach ($reviewPages as $rp) {
        $pn = (int)$rp['page_number'];
        $bindingsByPage[$pn] = [];
        foreach ($rp['blocks'] as $blk) {
            $text = (string)($blk['detected_text'] ?? '');
            $candidate = $aiResult['by_block_id'][$blk['id']] ?? ($blk['suggested_binding'] ?? 'static');
            $bindingsByPage[$pn][$blk['id']] = BindingValidator::sanitize($text, $candidate);
        }
    }

    // Persist the rows
    $db = Database::getInstance();
    $result = CardifyTemplateImporter::persist(
        $db,
        $companyId,
        $token,
        $outRel,
        $name . '.pdf',
        $parsed['pages'],
        $bindingsByPage,
        $fontsDirRel
    );

    // Rename the persisted templates to the user-chosen design name
    if (!empty($result['template_ids'])) {
        foreach ($result['template_ids'] as $sideKey => $tplId) {
            try {
                $db->update('templates', ['name' => $name], 'id = :id', ['id' => $tplId]);
            } catch (Throwable $e) {
                error_log('[create_design_from_pdf] rename failed: ' . $e->getMessage());
            }
        }
    }

    require_once INCLUDES_DIR . '/CardRenderer.php';
    try { CardRenderer::invalidateForCompany((string)$companyId, 'create-design-from-pdf'); }
    catch (Throwable $e) { error_log('[create_design_from_pdf] invalidate: ' . $e->getMessage()); }

    echo json_encode([
        'ok'           => true,
        'pair_id'      => $result['pair_id'],
        'template_ids' => $result['template_ids'],
        'import_token' => $token,
        'ai_used'      => !empty($aiResult['used_ai']),
        'missing_fonts'=> $parsed['missing_fonts'] ?? [],
    ], JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    error_log('[create_design_from_pdf] ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[create_design_from_pdf] FATAL ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
