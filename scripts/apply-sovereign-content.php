<?php
/**
 * Apply curated summary_en + summary_ar to sovereign / ministerial rows.
 *
 * Source: data/logo_library/sovereign_content.php (keyed by name_en).
 * Target: om_companies rows whose name_en matches a key (case-insensitive,
 * "the ministry of" normalized).
 *
 * Safe to re-run. Only updates rows where summary_en is empty/null.
 *
 * Run: /www/server/php/83/bin/php scripts/apply-sovereign-content.php [--force]
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require '/www/wwwroot/cardify.om/config.php';

$force = in_array('--force', $argv, true);
$map = require '/www/wwwroot/cardify.om/data/logo_library/sovereign_content.php';

function normalize(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = preg_replace('/^the\s+/', '', $s);
    // Strip corporate suffixes and & / commas so variants collapse:
    //   "Asyad Group SAOC" ≡ "ASYAD Group"
    //   "Oman Chamber of Commerce & Industry" ≡ "Oman Chamber of Commerce Industry"
    //   "Ministry of Commerce, Industry and Investment Promotion" ≡ same without comma
    $s = preg_replace('/\b(saoc|saog|s\.a\.o\.c\.|llc|sa?oc|s\.p\.c\.|spc|co\.?|limited|ltd)\b/', '', $s);
    $s = str_replace(['&', ','], [' and ', ' '], $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}

$lookup = [];
foreach ($map as $key => $row) {
    $lookup[normalize($key)] = $row;
}

$db  = Database::getInstance();
$pdo = $db->getConnection();

$rows = $pdo->query("SELECT id, name_en, summary_en FROM om_companies")->fetchAll(PDO::FETCH_ASSOC);
$updated = 0; $skipped_nomatch = 0; $skipped_has_summary = 0;

foreach ($rows as $r) {
    $key = normalize($r['name_en']);
    if (!isset($lookup[$key])) { $skipped_nomatch++; continue; }
    if (!$force && trim((string) $r['summary_en']) !== '') {
        $skipped_has_summary++;
        continue;
    }
    $data = $lookup[$key];
    $pdo->prepare("UPDATE om_companies SET summary_en = :en, summary_ar = :ar, updated_at = NOW() WHERE id = :id")
        ->execute([
            ':en' => $data['en'],
            ':ar' => $data['ar'],
            ':id' => $r['id'],
        ]);
    printf("  ✓ %5d  %s\n", $r['id'], $r['name_en']);
    $updated++;
}

echo "\n[sovereign] updated=$updated skipped_has_summary=$skipped_has_summary no_match=$skipped_nomatch\n";
