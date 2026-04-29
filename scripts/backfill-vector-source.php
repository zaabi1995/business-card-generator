<?php
/**
 * Walk every template, run extract_template_fonts.py against its
 * import dir, set has_vector_source + fonts_dir on the row.
 * Idempotent, safe to re-run.
 */
require_once __DIR__ . '/../config.php';

$db = Database::getInstance();
$rows = $db->fetchAll("SELECT id, company_id, background_image_path FROM templates");
$updated = 0;
foreach ($rows as $r) {
    $bg = (string)($r['background_image_path'] ?? '');
    if ($bg === '') continue;
    $absDir = dirname(BASE_DIR . '/' . ltrim($bg, '/'));
    if (!is_file($absDir . '/source.pdf')) continue;
    $cmd = 'python3 ' . escapeshellarg(BASE_DIR . '/scripts/extract_template_fonts.py')
         . ' ' . escapeshellarg($absDir) . ' 2>&1';
    $rc = 0;
    $out = [];
    exec($cmd, $out, $rc);
    if ($rc !== 0) {
        echo "skip {$r['id']}: " . implode("\n", $out) . "\n";
        continue;
    }
    $rel = '/' . trim(str_replace(BASE_DIR, '', $absDir . '/fonts'), '/');
    $db->query(
        "UPDATE templates SET has_vector_source = 1, fonts_dir = :fd WHERE id = :id",
        ['fd' => $rel, 'id' => $r['id']]
    );
    $updated++;
    echo "ok   {$r['id']}: $rel\n";
}
echo "Done, $updated rows updated\n";
