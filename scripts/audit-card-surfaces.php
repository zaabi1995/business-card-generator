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
    $employees = $db->fetchAll(
        "SELECT * FROM employees
          WHERE company_id = :cid AND slug = :slug AND status = 'active'
          LIMIT 1",
        ['cid' => $company['id'], 'slug' => $employeeSlug]
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

foreach ($employees as $emp) {
    $ctx = CardRenderer::forEmployee((string)$emp['id']);
    if (!$ctx) {
        $bad++;
        $rows[] = [
            'slug'          => $emp['slug'] ?? '?',
            'name'          => $emp['name_en'] ?? $emp['name'] ?? '?',
            'fresh'         => false,
            'front_present' => false,
            'back_present'  => false,
            'signature'     => '',
            'note'          => 'CardRenderer returned null',
        ];
        continue;
    }

    $hasFront = !empty($ctx['front_fs']) && is_file($ctx['front_fs']);
    $hasBack  = !empty($ctx['back_fs'])  && is_file($ctx['back_fs']);
    $fresh    = (bool)$ctx['is_fresh'];

    if ($fresh) $ok++; else $bad++;

    $rows[] = [
        'slug'          => $emp['slug'] ?? '?',
        'name'          => $emp['name_en'] ?? $emp['name'] ?? '?',
        'fresh'         => $fresh,
        'front_present' => $hasFront,
        'back_present'  => $hasBack,
        'signature'     => substr($ctx['signature'], 0, 10),
        'note'          => $fresh ? 'OK' : self_describeStaleness($ctx),
    ];
}

// Pretty CLI table
$col = function ($s, $w) {
    $s = (string)$s;
    if (mb_strlen($s) > $w) { $s = mb_substr($s, 0, $w - 1) . '…'; }
    return str_pad($s, $w);
};

echo "\n";
echo $col('SLUG', 22) . $col('NAME', 28) . $col('FRESH', 7) . $col('FRONT', 7) . $col('BACK', 6) . $col('SIG', 12) . "NOTE\n";
echo str_repeat('-', 110) . "\n";
foreach ($rows as $r) {
    echo $col($r['slug'], 22)
       . $col($r['name'], 28)
       . $col($r['fresh'] ? 'yes' : 'NO', 7)
       . $col($r['front_present'] ? 'yes' : 'NO', 7)
       . $col($r['back_present']  ? 'yes' : 'NO', 6)
       . $col($r['signature'], 12)
       . $r['note'] . "\n";
}

echo "\nCompany: {$company['name']} ({$company['slug']})\n";
echo "Surfaces aligned via canonical PNG: digital_card.php, card-pdf.php, wallet_apple.php, wallet_google.php, og:image, print-shop preview.\n";
echo "Result: {$ok} fresh / {$bad} stale\n";

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
