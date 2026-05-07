<?php
/**
 * audit-card-surfaces.php
 *
 * Verifies every card surface for a company resolves to the same canonical
 * front/back PNG. Use after any template/theme change, or when investigating
 * "the design looks different on X but not Y".
 *
 * Usage:
 *   php scripts/audit-card-surfaces.php <company-slug> [employee-slug]
 *
 * Examples:
 *   php scripts/audit-card-surfaces.php otech
 *   php scripts/audit-card-surfaces.php otech muhammed.ali
 *
 * Exit codes:
 *   0  all employees resolved a fresh canonical front + back PNG
 *   1  any employee was stale (missing PNG or version drift)
 *   2  CLI argument or company lookup error
 */

require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/CardRenderer.php';

// config.php turns on output buffering for security headers, but in CLI we
// want every echo to hit stdout immediately so progress is visible.
while (ob_get_level()) { ob_end_clean(); }

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(2);
}

$companySlug  = $argv[1] ?? '';
$employeeSlug = $argv[2] ?? '';

if ($companySlug === '') {
    fwrite(STDERR, "Usage: php scripts/audit-card-surfaces.php <company-slug> [employee-slug]\n");
    exit(2);
}

$company = findCompanyBySlug($companySlug);
if (!$company) {
    fwrite(STDERR, "Company not found: {$companySlug}\n");
    exit(2);
}

$db = Database::getInstance();

if ($employeeSlug !== '') {
    // employees.id IS the URL-facing slug (e.g. "muhammed.ali"); no separate slug column.
    $employees = $db->fetchAll(
        "SELECT * FROM employees
          WHERE company_id = :cid AND id = :id AND status = 'active'
          LIMIT 1",
        ['cid' => $company['id'], 'id' => $employeeSlug]
    );
} else {
    $employees = $db->fetchAll(
        "SELECT * FROM employees
          WHERE company_id = :cid AND status = 'active'
          ORDER BY created_at DESC",
        ['cid' => $company['id']]
    );
}

if (empty($employees)) {
    fwrite(STDERR, "No active employees matched.\n");
    exit(2);
}

$ok    = 0;
$bad   = 0;
$rows  = [];

// --- vector audit setup ---------------------------------------------------
// Determine whether any of this company's active templates have
// has_vector_source=1. We look up once here so each employee row can use the
// pre-fetched flag without a per-row query.
$companyTemplates = $db->fetchAll(
    'SELECT id, side, has_vector_source FROM templates
      WHERE company_id = :cid AND is_active = 1 AND deleted_at IS NULL',
    ['cid' => $company['id']]
);

// Both front and back templates must have has_vector_source=1 for the vector
// path to be active. Count by side.
$vectorBySide = ['front' => false, 'back' => false];
foreach ($companyTemplates as $tpl) {
    if (!empty($tpl['has_vector_source'])) {
        $vectorBySide[$tpl['side']] = true;
    }
}
$companyHasVectorSource = $vectorBySide['front'] && $vectorBySide['back'];

// Counters for summary line.
$vectorFresh    = 0;
$vectorFallback = 0;
$rasterOnly     = 0;

foreach ($employees as $emp) {
    $ctx = CardRenderer::forEmployee((string)$emp['id']);
    if (!$ctx) {
        $bad++;
        $rows[] = [
            'slug'          => $emp['id'],
            'name'          => $emp['name_en'] ?? '?',
            'fresh'         => false,
            'front_present' => false,
            'back_present'  => false,
            'signature'     => '',
            'note'          => 'CardRenderer returned null',
            'vector_status' => 'error',
        ];
        continue;
    }

    $hasFront = !empty($ctx['front_fs']) && is_file($ctx['front_fs']);
    $hasBack  = !empty($ctx['back_fs'])  && is_file($ctx['back_fs']);
    $fresh    = (bool)$ctx['is_fresh'];

    if ($fresh) $ok++; else $bad++;

    // --- vector status for this employee -----------------------------------
    $vectorStatus = vectorStatusForEmployee((string)$emp['id'], $companyHasVectorSource);
    switch ($vectorStatus) {
        case 'vector':          $vectorFresh++;    break;
        case 'raster-fallback': $vectorFallback++; break;
        case 'n/a':             $rasterOnly++;     break;
        default:                                   break;
    }

    $rows[] = [
        'slug'          => $emp['id'],
        'name'          => $emp['name_en'] ?? '?',
        'fresh'         => $fresh,
        'front_present' => $hasFront,
        'back_present'  => $hasBack,
        'signature'     => substr($ctx['signature'], 0, 10),
        'note'          => $fresh ? 'OK' : self_describeStaleness($ctx),
        'vector_status' => $vectorStatus,
    ];
}

// Pretty CLI table
$col = function ($s, $w) {
    $s = (string)$s;
    if (mb_strlen($s) > $w) { $s = mb_substr($s, 0, $w - 1) . '…'; }
    return str_pad($s, $w);
};

echo "\n";
echo $col('SLUG', 22) . $col('NAME', 28) . $col('FRESH', 7) . $col('FRONT', 7) . $col('BACK', 6) . $col('SIG', 12) . $col('VECTOR', 18) . "NOTE\n";
echo str_repeat('-', 128) . "\n";
foreach ($rows as $r) {
    echo $col($r['slug'], 22)
       . $col($r['name'], 28)
       . $col($r['fresh'] ? 'yes' : 'NO', 7)
       . $col($r['front_present'] ? 'yes' : 'NO', 7)
       . $col($r['back_present']  ? 'yes' : 'NO', 6)
       . $col($r['signature'], 12)
       . $col($r['vector_status'], 18)
       . $r['note'] . "\n";
}

echo "\nCompany: {$company['name']} ({$company['slug']})\n";
echo "Surfaces aligned via canonical PNG: digital_card.php, card-pdf.php, wallet_apple.php, wallet_google.php, og:image, print-shop preview.\n";
echo "Result: {$ok} fresh / {$bad} stale\n";
echo "Vector:  {$vectorFresh} vector / {$vectorFallback} raster-fallback / {$rasterOnly} raster-only (no vector source)\n";

if ($bad > 0) {
    echo "\nNext step: open the admin Card Editor and click Save on each stale employee, OR run a batch regen, to refresh the cache. Re-render is browser-side (Fabric.js).\n";
    exit(1);
}
exit(0);

function self_describeStaleness(array $ctx): string
{
    $bits = [];
    if (empty($ctx['front_fs'])) $bits[] = 'no-front-png';
    if (empty($ctx['back_fs']))  $bits[] = 'no-back-png';
    $card = $ctx['card'] ?? null;
    if (!$card) return 'no-generated_cards-row';
    if (empty($card['front_template_version']) && empty($card['back_template_version'])) {
        $bits[] = 'no-template-version-recorded';
    } else {
        $bits[] = 'template-version-drift';
    }
    return implode(',', $bits) ?: 'stale';
}

/**
 * Determine the vector PDF status for one employee.
 *
 * Returns one of:
 *   'vector'          - company has has_vector_source=1 and card-pdf.php
 *                       responded with a compact vector PDF (50K-500K).
 *   'raster-fallback' - company has has_vector_source=1 but card-pdf.php
 *                       responded with a large raster-in-PDF (>1MB), meaning
 *                       the vector render failed for this employee.
 *   'n/a'             - company has has_vector_source=0 (raster-only tenant).
 *   'error'           - HTTP error or unexpected Content-Length range.
 */
function vectorStatusForEmployee(string $employeeId, bool $companyHasVectorSource): string
{
    if (!$companyHasVectorSource) {
        return 'n/a';
    }

    // HEAD the PDF endpoint and read the canonical X-Cardify-Pdf-Mode header
    // (vector | vector-304 | raster-fallback | raster-fallback-304). The
    // header is set by card-pdf.php itself so we don't have to guess from
    // Content-Length, which fails on vector PDFs above 500K (see iter 9
    // 7 May 2026 fix, real vectors are ~712K).
    $url = 'https://cardify.om/card-pdf.php?i=' . rawurlencode($employeeId);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 2,
        CURLOPT_USERAGENT      => 'CardifyAudit/1.0',
    ]);
    $resp = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !is_string($resp)) {
        return 'error';
    }

    // Parse the Pdf-Mode header. Folds 304 variants into their underlying mode.
    if (preg_match('/^X-Cardify-Pdf-Mode:\s*([^\r\n]+)/im', $resp, $m)) {
        $mode = strtolower(trim($m[1]));
        if (str_starts_with($mode, 'vector')) return 'vector';
        if (str_starts_with($mode, 'raster-fallback')) return 'raster-fallback';
    }

    return 'error';
}
