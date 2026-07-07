<?php
/**
 * Headless import of a pre-merged 2-page card PDF (page1=front, page2=back) into
 * a Cardify tenant. Mirrors admin/create_design_from_pdf.php but as a CLI (no
 * auth/CSRF/upload). Used to import the MHD group-card master into the `mhd`
 * tenant.
 *
 * Run: /www/server/php/83/bin/php scripts/mhd/import-card.php <pdf> <company_id> <name>
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/CardifyTemplateImporter.php';
require_once INCLUDES_DIR . '/AIBindingClassifier.php';
require_once INCLUDES_DIR . '/BindingValidator.php';
require_once INCLUDES_DIR . '/CardRenderer.php';

[$self, $pdf, $companyId, $name] = array_pad($argv, 4, null);
if (!$pdf || !$companyId) { fwrite(STDERR, "usage: import-card.php <pdf> <company_id> <name>\n"); exit(2); }
if (!is_file($pdf)) { fwrite(STDERR, "no such pdf: $pdf\n"); exit(2); }
$name = $name ?: 'MHD Business Card';

$db = Database::getInstance();

$token  = bin2hex(random_bytes(8));
$outRel = '/uploads/templates/imports/' . $token;
$outAbs = realpath(__DIR__ . '/..') . '/../' . ltrim($outRel, '/');
$outAbs = realpath(dirname(__DIR__, 2)) . $outRel;
@mkdir($outAbs, 0755, true);
$srcPdf = $outAbs . '/source.pdf';
copy($pdf, $srcPdf);

$installedFontsFile = dirname(__DIR__, 2) . '/uploads/fonts/installed.txt';

$stderrLog = $outAbs . '/parser-stderr.log';
$cmd = sprintf('python3 %s %s %s %s 2>%s',
    escapeshellarg(dirname(__DIR__, 2) . '/scripts/parse_card_pdf.py'),
    escapeshellarg($srcPdf), escapeshellarg($outAbs), escapeshellarg($installedFontsFile),
    escapeshellarg($stderrLog));
$parserOut = shell_exec($cmd);
if (!$parserOut) { fwrite(STDERR, "parser_no_output: " . substr((string)@file_get_contents($stderrLog), 0, 800) . "\n"); exit(1); }
$parsed = json_decode($parserOut, true);
if ($parsed === null || empty($parsed['pages'])) { fwrite(STDERR, "parser_failed: " . substr((string)$parserOut, 0, 800) . "\n"); exit(1); }

foreach ($parsed['pages'] as &$page) {
    if (!empty($page['background_path']))           $page['background_url']           = $outRel . '/' . $page['background_path'];
    if (!empty($page['background_with_text_path']))  $page['background_with_text_url']  = $outRel . '/' . $page['background_with_text_path'];
    if (!empty($page['background_svg_path']))        $page['background_svg_url']        = $outRel . '/' . $page['background_svg_path'];
}
unset($page);

$fontsDirRel = !empty($parsed['fonts_dir_rel']) ? ($outRel . '/fonts') : null;
file_put_contents($outAbs . '/parse.json', json_encode([
    'token' => $token, 'company_id' => $companyId, 'original_filename' => $name . '.pdf',
    'created_at' => date('c'), 'pages' => $parsed['pages'],
    'fonts_used' => $parsed['fonts_used'] ?? [], 'missing_fonts' => $parsed['missing_fonts'] ?? [],
    'fonts_dir_rel' => $fontsDirRel,
], JSON_UNESCAPED_UNICODE));

// Build review structure + script/AI-classify each block.
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
            'color'          => $f['color'] ?? null,
            'suggested_binding' => CardifyTemplateImporter::suggestBinding($f),
        ];
    }
    $reviewPages[] = ['page_number' => $page['page_number'], 'side' => $page['side'], 'blocks' => $blocks];
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
    try { $aiResult = AIBindingClassifier::classify($reviewPages); }
    catch (Throwable $e) { fwrite(STDERR, "AI classify skipped: " . $e->getMessage() . "\n"); }
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

$result = CardifyTemplateImporter::persist($db, $companyId, $token, $outRel, $name . '.pdf',
    $parsed['pages'], $bindingsByPage, $fontsDirRel);

if (!empty($result['template_ids'])) {
    foreach ($result['template_ids'] as $tplId) {
        try { $db->update('templates', ['name' => $name], 'id = :id', ['id' => $tplId]); } catch (Throwable $e) {}
    }
}
try { CardRenderer::invalidateForCompany((string)$companyId, 'mhd-import-card'); } catch (Throwable $e) {}

echo json_encode([
    'ok' => true, 'pair_id' => $result['pair_id'] ?? null, 'template_ids' => $result['template_ids'] ?? [],
    'token' => $token, 'ai_used' => !empty($aiResult['used_ai']),
    'missing_fonts' => $parsed['missing_fonts'] ?? [], 'fonts_used' => $parsed['fonts_used'] ?? [],
    'bindings' => $bindingsByPage,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
