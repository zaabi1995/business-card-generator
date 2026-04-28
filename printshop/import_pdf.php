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
// Allow print shops, super admins, and any company admin onboarding
// their tenant. Each upload is namespaced by the random token below
// so company A can't read company B's import directory.
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

// Persist the parsed pages as `templates` rows so the import shows up in
// the company's Card Designs panel and the portal can render the design.
// Only do this when the upload has a company context (i.e. company admins
// onboarding their tenant; print-shop uploads stay separate).
$companyId = function_exists('getCurrentCompanyId') ? getCurrentCompanyId() : null;

/**
 * Translate parser output (array of fields, px at 300 DPI, raw font names)
 * into the dict-shaped fields_json the editor + portal expect:
 *   { "name_en": { enabled, x, y, width, fontSize, fontFamily, fill, ... },
 *     "email":   { ... }, "qr_code": { x, y, size }, "static_1": { is_static, text, ... } }
 *
 * Disambiguates duplicate typed keys (two M-phones, two custom blocks) by
 * suffixing _2, _3, ... so nothing is silently dropped.
 *
 * Static spans (decorative captions like "QR Code, to save the contact",
 * "Follow us") become static_N so the portal renders their detected_text
 * verbatim instead of looking up employee data.
 */
function cardify_translate_fields(array $page): array {
    $out = [];
    $usedKeys = [];

    $deriveKey = function ($base, $detected) {
        $t = ltrim((string)$detected);
        if ($base === 'mobile' && strlen($t) >= 2) {
            $first = strtoupper(substr($t, 0, 1));
            if ($first === 'T') return 'phone';
            if ($first === 'F') return 'fax';
            return 'mobile';
        }
        return $base;
    };

    // Field keys that on a single brand's card almost always represent
    // shared brand decoration rather than per-employee data: tagline
    // ornaments, the company social handle ("@otech"), unclassified spans
    // ("An Omantel Company"). Treat them as static so the editor and
    // portal render the detected text verbatim (designer can flip them
    // back to a typed field manually if a per-employee value is wanted).
    $staticBaseKeys = ['custom', 'social', 'company_tagline'];

    $staticIdx = 0;
    foreach (($page['fields'] ?? []) as $f) {
        $base = $f['field_key'] ?? 'custom';
        $isStatic = !empty($f['is_static']) || in_array($base, $staticBaseKeys, true);
        if ($isStatic) {
            $staticIdx++;
            $key = 'static_' . $staticIdx;
        } else {
            $key = $deriveKey($base, $f['detected_text'] ?? '');
        }

        if (isset($usedKeys[$key])) {
            $usedKeys[$key]++;
            $key = $key . '_' . $usedKeys[$key];
        } else {
            $usedKeys[$key] = 1;
        }

        // Friendly editor label. Static fields show their detected text
        // so the designer can tell which decoration is which without
        // having to read static_1 / static_2.
        $label = null;
        if ($isStatic) {
            $sample = trim((string)($f['detected_text'] ?? ''));
            if ($sample !== '') {
                $short = mb_strimwidth($sample, 0, 22, '…');
                $label = 'Decoration: ' . $short;
            }
        }

        $out[$key] = [
            'enabled'       => true,
            'is_static'     => $isStatic,
            'label'         => $label,
            'detected_text' => $f['detected_text'] ?? '',
            'x'             => (int)($f['x_px'] ?? 0),
            'y'             => (int)($f['y_px'] ?? 0),
            'width'         => (int)($f['w_px'] ?? 0),
            'height'        => (int)($f['h_px'] ?? 0),
            'fontSize'      => (int)($f['font_size_px'] ?? 14),
            'fontFamily'    => $f['font_family'] ?? 'Inter',
            'fontWeight'    => isset($f['font_weight']) && (int)$f['font_weight'] >= 600 ? 'bold' : 'normal',
            'italic'        => !empty($f['italic']),
            'fill'          => $f['color'] ?? '#222222',
            'color'         => $f['color'] ?? '#222222',
            'textAlign'     => $f['align'] ?? 'left',
            'originX'       => 'left',
            'originY'       => 'top',
        ];
    }

    // QR code field: always inject one per page so the user can place it
    // on either side. When the parser detected a white placeholder square,
    // centre the QR inside it at 90% of the square's smaller dimension
    // (5% margin on each side, the user's preferred breathing room).
    // When no square was detected, drop a sensibly-sized QR in the bottom-
    // right corner so the user has something to drag.
    $scale = 300.0 / 72.0;
    if (!empty($page['qr_area'])) {
        $qa = $page['qr_area'];
        $qWpx = (float)$qa['w_pt'] * $scale;
        $qHpx = (float)$qa['h_pt'] * $scale;
        $qXpx = (float)$qa['x_pt'] * $scale;
        $qYpx = (float)$qa['y_pt'] * $scale;
        $qrSize = (int)round(min($qWpx, $qHpx) * 0.90);
        $out['qr_code'] = [
            'enabled' => true,
            'x'       => (int)round($qXpx + ($qWpx - $qrSize) / 2),
            'y'       => (int)round($qYpx + ($qHpx - $qrSize) / 2),
            'size'    => $qrSize > 0 ? $qrSize : 140,
        ];
    } else {
        // Default: bottom-right corner, ~18mm square.
        $defaultMm = 18;
        $defaultPx = (int)round($defaultMm / 25.4 * 300);
        $pageWpx = (int)round((float)($page['width_pt'] ?? 255) * $scale);
        $pageHpx = (int)round((float)($page['height_pt'] ?? 165) * $scale);
        $marginPx = (int)round(6 / 25.4 * 300); // 6mm margin
        $out['qr_code'] = [
            'enabled' => false, // off by default on sides where the designer
                                // didn't carve out a placeholder square
            'x'       => max(0, $pageWpx - $defaultPx - $marginPx),
            'y'       => max(0, $pageHpx - $defaultPx - $marginPx),
            'size'    => $defaultPx,
        ];
    }

    return $out;
}

if ($companyId && in_array($user['role'] ?? '', ['admin', 'company_admin', 'company'], true)) {
    try {
        $db = Database::getInstance();
        $pairId = function_exists('generateUUID') ? generateUUID() : bin2hex(random_bytes(16));
        $createdTemplateIds = [];
        foreach ($result['pages'] as $page) {
            $tplId = function_exists('generateUUID') ? generateUUID() : bin2hex(random_bytes(16));
            $side = ($page['page_number'] ?? 1) === 1 ? 'front' : 'back';
            $bgPath = !empty($page['background_url']) ? $page['background_url'] : null;

            // Field schema translation: parser array -> portal dict, with
            // disambiguated keys, qr_code synthesized from qr_area, and
            // numeric coordinates stored at the parser's render DPI (300).
            $fieldsDict = cardify_translate_fields($page);

            // Collect unique font families so the portal can preload them
            // via Google Fonts rather than falling back to Arial.
            $fontFamilies = [];
            foreach ($fieldsDict as $f) {
                if (!empty($f['fontFamily'])) {
                    $fontFamilies[$f['fontFamily']] = true;
                }
            }

            // The editor only understands customUnit='mm' or 'in', and
            // its getCanvasDimensions falls back to inches when the unit
            // is unrecognised, so storing 'pt' makes the canvas blow up
            // to 262 inches and visually clip everything. Convert pt to
            // mm here so the editor renders at the real card size.
            $widthPt    = (float)($page['width_pt']  ?? 255);
            $heightPt   = (float)($page['height_pt'] ?? 165);
            $widthMm    = round($widthPt  * 25.4 / 72, 2);
            $heightMm   = round($heightPt * 25.4 / 72, 2);

            $settings = [
                'cardSize'      => 'custom',
                'customWidth'   => $widthMm,
                'customHeight'  => $heightMm,
                'customUnit'    => 'mm',
                'dpi'           => 300,
                'width_pt'      => $widthPt,
                'height_pt'     => $heightPt,
                'qr_area'       => $page['qr_area'] ?? null,
                'fonts_used'    => array_keys($fontFamilies),
                'imported_from' => 'pdf',
                'import_token'  => $token,
            ];

            $db->insert('templates', [
                'id'                    => $tplId,
                'company_id'            => $companyId,
                'pair_id'               => $pairId,
                'name'                  => trim('Imported ' . preg_replace('/\.pdf$/i', '', $origName)) ?: 'Imported design',
                'side'                  => $side,
                'background_image_path' => $bgPath,
                'original_pdf_path'     => $outRel . '/source.pdf',
                'original_pdf_page'     => (int)($page['page_number'] ?? 1),
                'fields_json'           => json_encode($fieldsDict, JSON_UNESCAPED_UNICODE),
                'settings_json'         => json_encode($settings, JSON_UNESCAPED_UNICODE),
                'is_active'             => 1,
                'description'           => 'Auto-imported from ' . $origName,
            ]);
            $createdTemplateIds[$side] = $tplId;
        }

        // Link front<->back via paired_template_id when both sides exist.
        if (isset($createdTemplateIds['front'], $createdTemplateIds['back'])) {
            $db->update('templates', ['paired_template_id' => $createdTemplateIds['back']], 'id = :id', ['id' => $createdTemplateIds['front']]);
            $db->update('templates', ['paired_template_id' => $createdTemplateIds['front']], 'id = :id', ['id' => $createdTemplateIds['back']]);
        }

        $result['template_pair_id'] = $pairId;
        $result['template_ids']     = $createdTemplateIds;
    } catch (Throwable $e) {
        error_log('[import_pdf] template persist failed: ' . $e->getMessage());
        $result['template_persist_error'] = $e->getMessage();
    }
}

echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
