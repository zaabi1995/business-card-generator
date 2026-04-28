<?php
/**
 * Print Shop: Import a Business Card PDF
 *
 * Accepts a multipart upload of a 1-2 page PDF business card, runs the Python
 * parser (parse_card_pdf.py), and returns a JSON template definition that the
 * template editor uses to auto-populate the Fabric.js canvas.
 *
 * Detection covers: text positions, font family + weight, font size, color,
 * QR placeholder area, and a redacted background image with text removed.
 * Missing fonts (not in Cardify's installed font list) are flagged so the
 * user can upload them.
 *
 * Route: POST /printshop/import_pdf.php  (multipart/form-data, field: pdf)
 * Auth:  Login required, role print_shop or super_admin.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';

header('Content-Type: application/json');

Auth::requireLogin();
$user = Auth::getCurrentUser();
if ($user['role'] !== 'print_shop' && $user['role'] !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

if (empty($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'no_pdf_uploaded']);
    exit;
}

// Hard limit: 25 MB. Stops abuse and matches typical card-art ceiling.
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

// Validate it's a real PDF (magic bytes)
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

// Output dir under uploads/templates/imports/<token>/
$token = bin2hex(random_bytes(8));
$outRel = '/uploads/templates/imports/' . $token;
$outAbs = realpath(__DIR__ . '/..') . $outRel;
if (!@mkdir($outAbs, 0755, true) && !is_dir($outAbs)) {
    http_response_code(500);
    echo json_encode(['error' => 'cannot_create_output_dir']);
    exit;
}

// Save the source PDF for reference / debugging
$srcPdf = $outAbs . '/source.pdf';
if (!move_uploaded_file($tmp, $srcPdf)) {
    http_response_code(500);
    echo json_encode(['error' => 'cannot_save_pdf']);
    exit;
}

// Build the installed-fonts list. Cardify ships with a curated set of web-safe
// + Google fonts. Anything outside this list is flagged for upload.
$installedFontsFile = __DIR__ . '/../uploads/fonts/installed.txt';
if (!file_exists($installedFontsFile)) {
    $defaultFonts = [
        // Web-safe
        'arial', 'helvetica', 'helvetica neue', 'georgia', 'times', 'times new roman',
        'courier', 'courier new', 'verdana', 'tahoma', 'trebuchet ms',
        // Cardify defaults (loaded via Google Fonts in the editor)
        'inter', 'roboto', 'open sans', 'lato', 'montserrat', 'poppins', 'raleway',
        'oswald', 'merriweather', 'playfair display', 'sora', 'work sans',
        'noto sans', 'noto serif', 'noto sans arabic', 'noto kufi arabic',
        'cairo', 'tajawal', 'amiri', 'reem kufi', 'changa',
    ];
    @mkdir(dirname($installedFontsFile), 0755, true);
    file_put_contents($installedFontsFile, implode("\n", $defaultFonts) . "\n");
}

// Run the Python parser
$cmd = sprintf(
    'python3 %s %s %s %s 2>&1',
    escapeshellarg(__DIR__ . '/../scripts/parse_card_pdf.py'),
    escapeshellarg($srcPdf),
    escapeshellarg($outAbs),
    escapeshellarg($installedFontsFile)
);
$out = shell_exec($cmd);
if (!$out) {
    http_response_code(500);
    echo json_encode(['error' => 'parser_no_output', 'cmd' => $cmd]);
    exit;
}

$result = json_decode($out, true);
if ($result === null) {
    // Parser printed something other than JSON (likely a traceback)
    http_response_code(500);
    echo json_encode([
        'error' => 'parser_failed',
        'parser_output' => substr($out, 0, 4000),
    ]);
    exit;
}

// Rewrite background paths to absolute web URLs
foreach ($result['pages'] as &$page) {
    if (!empty($page['background_path'])) {
        $page['background_url'] = $outRel . '/' . $page['background_path'];
    }
    if (!empty($page['background_with_text_path'])) {
        $page['background_with_text_url'] = $outRel . '/' . $page['background_with_text_path'];
    }
}
unset($page);

$result['import_token'] = $token;
$result['import_path']  = $outRel;
$result['source_pdf']   = $outRel . '/source.pdf';
$result['original_filename'] = $origName;

echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
