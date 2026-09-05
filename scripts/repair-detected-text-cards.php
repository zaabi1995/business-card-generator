<?php
/**
 * Re-bake the cards that printed the person whose design was imported.
 *
 * `detected_text` is the sample lifted out of the source PDF at import. The PNG
 * renderer used it whenever an employee's own value was empty, per-person
 * fields included, so a card could carry another person's name, position or
 * mobile number. Measured on production 5 Sep 2026: 163 leaked fields across
 * five tenants, including 59 cards printing one person's mobile.
 *
 * scripts/render-card-images.py is fixed. This re-bakes the cards that were
 * generated before the fix, through the canonical path
 * (CardRenderer::regenerateForEmployee), which stages and promotes atomically.
 *
 * It only touches employees whose card actually leaks: the field is empty on
 * the employee, the active template holds a non-static detected_text for it,
 * and the field is a per-person one. Nothing else is regenerated, and no
 * identifier, QR destination or URL changes.
 *
 * Usage:
 *   php scripts/repair-detected-text-cards.php            # dry run, prints the list
 *   php scripts/repair-detected-text-cards.php --apply    # re-bake
 */
require_once dirname(__DIR__) . '/config.php';
require_once INCLUDES_DIR . '/CardRenderer.php';

$apply = in_array('--apply', $argv, true);
$db = Database::getInstance();

/** Per-person fields. Anything else on a card is the same for the whole tenant. */
$perPerson = ['name', 'position', 'job_title', 'phone', 'mobile', 'email'];

$templates = $db->fetchAll(
    "SELECT company_id, fields_json FROM templates
      WHERE deleted_at IS NULL AND is_active = 1 AND fields_json LIKE :q",
    ['q' => '%detected_text%']
);

$leaks = [];
foreach ($templates as $t) {
    $fields = json_decode((string) $t['fields_json'], true);
    if (!is_array($fields)) continue;
    foreach ($fields as $key => $def) {
        if (!is_array($def) || !empty($def['is_static'])) continue;
        if (trim((string) ($def['detected_text'] ?? '')) === '') continue;
        $base = preg_replace('/_(en|ar)$/', '', $key);
        if (!in_array($base, $perPerson, true)) continue;
        $leaks[$t['company_id']][$key] = trim((string) $def['detected_text']);
    }
}

$employeeColumns = array_column(
    $db->fetchAll("SHOW COLUMNS FROM employees"), 'Field'
);

$targets = [];
foreach ($leaks as $companyId => $keys) {
    foreach (array_keys($keys) as $key) {
        if (!in_array($key, $employeeColumns, true)) continue;
        $rows = $db->fetchAll(
            "SELECT e.id, e.name_en FROM employees e
               JOIN generated_cards g ON g.employee_id = e.id
              WHERE e.company_id = :c AND (e.`{$key}` IS NULL OR e.`{$key}` = '')",
            ['c' => $companyId]
        );
        foreach ($rows as $r) {
            $targets[$r['id']] = ['name' => $r['name_en'], 'company' => $companyId, 'field' => $key];
        }
    }
}

echo count($targets), " employee card(s) currently print a value that is not theirs\n";
foreach (array_slice($targets, 0, 8, true) as $id => $t) {
    echo "  " . substr($id, 0, 8) . "  " . $t['name'] . "  (" . $t['field'] . ")\n";
}
if (!$apply) {
    echo "\nDry run. Pass --apply to re-bake.\n";
    exit(0);
}

$ok = $fail = 0;
foreach ($targets as $employeeId => $t) {
    try {
        CardRenderer::regenerateForEmployee((string) $employeeId, 'detected_text_leak_repair');
        $ok++;
    } catch (Throwable $e) {
        $fail++;
        fwrite(STDERR, "FAILED {$employeeId}: " . $e->getMessage() . "\n");
    }
    usleep(150000);
}
echo "re-baked {$ok}, failed {$fail}\n";
exit($fail === 0 ? 0 : 1);
