<?php
/**
 * Print Shop: Import a Business Card PDF (parse stage).
 *
 * Accepts a multipart upload of a 1-2 page PDF business card, runs the
 * Python parser (parse_card_pdf.py), and returns a review-friendly JSON
 * payload describing every detected text block plus a suggested binding.
 *
 * The actual templates rows are NOT created here. The wizard collects the
 * user's confirmed bindings (each block bound to a Cardify field, kept as
 * static decoration, or skipped) and POSTs them to /printshop/persist_template.php
 * which writes the rows. Splitting parse from persist lets the user
 * fix detection mistakes before they end up in the editor + portal.
 *
 * Route: POST /printshop/import_pdf.php  (multipart/form-data, field: pdf)
 * Auth:  Login required, role print_shop, super_admin, admin, company_admin, company.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/CardifyTemplateImporter.php';
require_once INCLUDES_DIR . '/AIBindingClassifier.php';
require_once INCLUDES_DIR . '/BindingValidator.php';

header('Content-Type: application/json');

Auth::requireLogin();
$user = Auth::getCurrentUser();
$allowedRoles = ['print_shop', 'super_admin', 'admin', 'company_admin', 'company'];
if (!in_array($user['role'], $allowedRoles, true)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

// Accept CSRF token from either FormData (csrf_token) or header (X-CSRF-Token)
// since callers built before this hardening pass send it as a header.
$csrf = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!validateCSRFToken($csrf)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_csrf']);
    exit;
}

if (empty($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'no_pdf_uploaded']);
    exit;
}

$MAX_BYTES = 25 * 1024 * 1024;
if ((int)$_FILES['pdf']['size'] > $MAX_BYTES) {
    http_response_code(413);
    echo json_encode([
        'error' => 'pdf_too_large',
        'max_mb' => $MAX_BYTES / (1024 * 1024),
        'received_mb' => round((int)$_FILES['pdf']['size'] / (1024 * 1024), 2),
    ]);
    exit;
}

$tmp = $_FILES['pdf']['tmp_name'];
$origName = $_FILES['pdf']['name'];

$fh = @fopen($tmp, 'rb');
if (!$fh) {
    http_response_code(400);
    echo json_encode(['error' => 'cannot_read_upload']);
    exit;
}
$magic = fread($fh, 5);
fclose($fh);
if (substr($magic, 0, 4) !== '%PDF') {
    http_response_code(400);
    echo json_encode(['error' => 'not_a_pdf']);
    exit;
}

// Defense in depth: magic-bytes + finfo (cardify rule 7).
$finfo = new finfo(FILEINFO_MIME_TYPE);
$realMime = $finfo->file($tmp);
if ($realMime !== 'application/pdf') {
    http_response_code(400);
    echo json_encode(['error' => 'not_a_pdf', 'detected_mime' => $realMime]);
    exit;
}

$token = bin2hex(random_bytes(8));
$outRel = '/uploads/templates/imports/' . $token;
$outAbs = realpath(__DIR__ . '/..') . $outRel;
if (!@mkdir($outAbs, 0755, true) && !is_dir($outAbs)) {
    http_response_code(500);
    echo json_encode(['error' => 'cannot_create_output_dir']);
    exit;
}

$srcPdf = $outAbs . '/source.pdf';
if (!move_uploaded_file($tmp, $srcPdf)) {
    http_response_code(500);
    echo json_encode(['error' => 'cannot_save_pdf']);
    exit;
}

$installedFontsFile = __DIR__ . '/../uploads/fonts/installed.txt';
if (!file_exists($installedFontsFile)) {
    $defaultFonts = [
        'arial', 'helvetica', 'helvetica neue', 'georgia', 'times', 'times new roman',
        'courier', 'courier new', 'verdana', 'tahoma', 'trebuchet ms',
        'inter', 'roboto', 'open sans', 'lato', 'montserrat', 'poppins', 'raleway',
        'oswald', 'merriweather', 'playfair display', 'sora', 'work sans',
        'noto sans', 'noto serif', 'noto sans arabic', 'noto kufi arabic',
        'cairo', 'tajawal', 'amiri', 'reem kufi', 'changa',
    ];
    @mkdir(dirname($installedFontsFile), 0755, true);
    file_put_contents($installedFontsFile, implode("\n", $defaultFonts) . "\n");
}

// Keep stderr OUT of stdout: the parser (and its sub-tools like
// extract_template_fonts.py) print progress messages to stderr, and
// merging them via 2>&1 silently corrupts the JSON we try to decode.
// Sidecar the stderr to a log file so we can still surface it on failure.
$stderrLog = $outAbs . '/parser-stderr.log';
$cmd = sprintf(
    'python3 %s %s %s %s 2>%s',
    escapeshellarg(__DIR__ . '/../scripts/parse_card_pdf.py'),
    escapeshellarg($srcPdf),
    escapeshellarg($outAbs),
    escapeshellarg($installedFontsFile),
    escapeshellarg($stderrLog)
);
$out = shell_exec($cmd);
if (!$out) {
    error_log('[import_pdf] parser_no_output stderr=' . substr((string)@file_get_contents($stderrLog), 0, 4000));
    http_response_code(500);
    // Do NOT echo parser_stderr to API consumers: it includes Python tracebacks
    // with absolute server paths and library internals. The token is enough
    // for an operator to find the stderr sidecar in the import dir.
    echo json_encode(['error' => 'parser_no_output', 'import_token' => $token]);
    exit;
}

$parsed = json_decode($out, true);
if ($parsed === null) {
    error_log('[import_pdf] parser_failed output=' . substr($out, 0, 1000) . ' stderr=' . substr((string)@file_get_contents($stderrLog), 0, 1000));
    http_response_code(500);
    echo json_encode(['error' => 'parser_failed', 'import_token' => $token]);
    exit;
}

// Stamp web-friendly URLs onto each page
foreach ($parsed['pages'] as &$page) {
    if (!empty($page['background_path'])) {
        $page['background_url'] = $outRel . '/' . $page['background_path'];
    }
    if (!empty($page['background_with_text_path'])) {
        $page['background_with_text_url'] = $outRel . '/' . $page['background_with_text_path'];
    }
    // True-vector SVG export (added with 1200 DPI bg bump). Surfaces that
    // need lossless output (PDF download, Wallet print strip, future
    // direct-print pipeline) can prefer this over the raster bg.
    if (!empty($page['background_svg_path'])) {
        $page['background_svg_url'] = $outRel . '/' . $page['background_svg_path'];
    }
}
unset($page);

// Persist the raw parser output so persist_template.php can re-read it
// later without rerunning the parser. We also remember which company
// owns this token so the persist endpoint can authorise the call.
//
// Two callers post here: tenant-admin onboarding (session has
// company_id) and the print-shop template editor (session has the
// operator + shop). Try the tenant-admin path first; fall back to the
// print-shop context, where the company arrives in POST['company_id'].
$companyId = function_exists('getCurrentCompanyId') ? getCurrentCompanyId() : null;
if (!$companyId) {
    $postedCompanyId = trim((string) ($_POST['company_id'] ?? ''));
    if ($postedCompanyId !== '') {
        $companyId = $postedCompanyId;
    }
}
// Python returns a truthy fonts_dir_rel when fonts were extracted; the PHP
// side knows the canonical web-root-relative path (outRel + /fonts).
$fontsDirRel = !empty($parsed['fonts_dir_rel']) ? ($outRel . '/fonts') : null;
$envelope = [
    'token'             => $token,
    'company_id'        => $companyId,
    'user_id'           => $user['id'] ?? null,
    'original_filename' => $origName,
    'created_at'        => date('c'),
    'pages'             => $parsed['pages'],
    'fonts_used'        => $parsed['fonts_used']    ?? [],
    'missing_fonts'     => $parsed['missing_fonts'] ?? [],
    'fonts_dir_rel'     => $fontsDirRel,
];
file_put_contents($outAbs . '/parse.json', json_encode($envelope, JSON_UNESCAPED_UNICODE));

// Build the review-friendly payload: each detected span becomes a "block"
// with display-friendly coordinates (300 DPI px, the same space the canvas
// uses), the parser's suggested binding, and the detected text. The wizard
// renders these as clickable overlays on top of the redacted background.
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
        'page_number'  => $page['page_number'],
        'side'         => $page['side'],
        'width_pt'     => $page['width_pt'],
        'height_pt'    => $page['height_pt'],
        'width_px'     => $page['width_px'],
        'height_px'    => $page['height_px'],
        'background_url'           => $page['background_url'] ?? null,
        'background_with_text_url' => $page['background_with_text_url'] ?? null,
        'background_svg_url'       => $page['background_svg_url'] ?? null,
        'background_dpi'           => $page['background_dpi'] ?? null,
        'qr_area'      => $page['qr_area'] ?? null,
        'blocks'       => $blocks,
    ];
}

// Pipeline: script-first classification, AI only fills the gaps, then every
// answer (script, AI, parser-heuristic) gets re-validated against script
// rules so we never trust an AI label that conflicts with the actual text.
$ambiguousIds = [];
foreach ($reviewPages as &$rp) {
    foreach ($rp['blocks'] as &$blk) {
        $script = BindingValidator::scriptClassify((string)($blk['detected_text'] ?? ''));
        $blk['script_binding'] = $script['binding'];
        $blk['script_reason']  = $script['reason'];
        if ($script['confident'] && $script['binding'] !== null) {
            // Hard-lock: script wins, do not waste an AI slot on this.
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
    // Build a slim copy with only the ambiguous blocks so the AI prompt
    // stays focused (and cheap). Fail-open: AI errors leave script answers.
    $aiResult = AIBindingClassifier::classify($reviewPages);
}

foreach ($reviewPages as &$rp) {
    foreach ($rp['blocks'] as &$blk) {
        $text = (string)($blk['detected_text'] ?? '');
        if (($blk['source'] ?? null) === 'script') {
            // Already locked. Still pass through sanitize as a safety net.
            $blk['suggested_binding'] = BindingValidator::sanitize($text, $blk['suggested_binding']);
            continue;
        }
        $aiLabel = $aiResult['by_block_id'][$blk['id']] ?? null;
        if ($aiLabel) {
            $blk['ai_classified'] = true;
        }
        $candidate = $aiLabel ?? ($blk['suggested_binding'] ?? 'static');
        $blk['suggested_binding'] = BindingValidator::sanitize($text, $candidate);
        $blk['source'] = $aiLabel ? 'ai_validated' : 'parser_validated';
    }
    unset($blk);
}
unset($rp);

echo json_encode([
    'import_token'      => $token,
    'import_path'       => $outRel,
    'source_pdf'        => $outRel . '/source.pdf',
    'original_filename' => $origName,
    'pages'             => $reviewPages,
    'fonts_used'        => $parsed['fonts_used']    ?? [],
    'missing_fonts'     => $parsed['missing_fonts'] ?? [],
    'ai_used'           => !empty($aiResult['used_ai']),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
