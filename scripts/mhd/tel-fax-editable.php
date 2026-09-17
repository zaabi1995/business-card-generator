<?php
/**
 * Make the office tel / fax on MHD's division cards editable, prefilled per division.
 *
 * The division templates carried tel1 / tel2 / fax (and their Arabic twins) as
 * STATIC text. This turns them into dynamic fields bound to the employee's own
 * phone / phone_2 / fax (+ _ar), and records the numbers they used to bake as the
 * division default in departments.office_tel1 / office_tel2 / office_fax, which the
 * portal prefills and falls back to.
 *
 * Geometry is unchanged. Print draws a left-aligned dynamic field from the same x
 * as the static did; the preview keeps the static block's baseline nudge and
 * full-size digits through baselineFactor / autoShrink on the field.
 *
 * Idempotent. The previous fields_json of every template it touches is written to
 * private/backups/ first.
 *
 *   php scripts/mhd/tel-fax-editable.php            # dry run
 *   php scripts/mhd/tel-fax-editable.php --apply
 */
$root = is_file(dirname(__DIR__, 2) . '/config.php') ? dirname(__DIR__, 2) : '/www/wwwroot/cardify.om';
chdir($root);
require_once $root . '/config.php';

const MHD = 'a9ba4c5e-7b8e-4ccc-a3bd-08ab9af7b1d5';
const RENAME = [
    'tel1' => 'phone',   'tel2' => 'phone_2',   'fax' => 'fax',
    'tel1_ar' => 'phone_ar', 'tel2_ar' => 'phone_2_ar', 'fax_ar' => 'fax_ar',
    // Already converted: kept, so a re-run can still correct their geometry.
    'phone' => 'phone', 'phone_2' => 'phone_2', 'phone_ar' => 'phone_ar', 'phone_2_ar' => 'phone_2_ar',
];

$apply = in_array('--apply', $argv, true);
$db = Database::getInstance();
$backupDir = $root . '/private/backups';
if ($apply && !is_dir($backupDir)) { mkdir($backupDir, 0750, true); }

$western = fn(string $s) => strtr($s, ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);

$depts = $db->fetchAll(
    "SELECT id, slug, template_pair_id, office_tel1, office_tel2, office_fax
       FROM departments WHERE company_id = ? AND template_pair_id IS NOT NULL ORDER BY slug", [MHD]);

foreach ($depts as $d) {
    $tpls = $db->fetchAll("SELECT id, side, fields_json FROM templates WHERE pair_id = ? AND is_active = 1",
                          [$d['template_pair_id']]);
    $defaults = ['office_tel1' => null, 'office_tel2' => null, 'office_fax' => null];
    foreach ($tpls as $t) {
        $fields = json_decode((string)$t['fields_json'], true);
        if (!is_array($fields)) { continue; }
        $changed = false;
        $out = [];
        foreach ($fields as $key => $f) {
            if (!isset(RENAME[$key]) || !is_array($f)) { $out[$key] = $f; continue; }
            $newKey = RENAME[$key];
            $sample = trim($western((string)($f['detected_text'] ?? '')));
            if ($key === 'tel1') { $defaults['office_tel1'] = $sample; }
            if ($key === 'tel2') { $defaults['office_tel2'] = $sample; }
            if ($key === 'fax')  { $defaults['office_fax']  = $sample; }
            // Room to draw full size. A dynamic field is shrunk to fit its box in
            // print; the static text never was, and the tel boxes (121px) were a
            // hair narrower than eight digits. Left-aligned, so the extra width
            // sits to the right of the digits: 200px on the English front, 150px
            // on the Arabic back, which stops short of the Arabic label.
            $minW = str_ends_with($newKey, '_ar') ? 150 : 200;
            if ((float)($f['width'] ?? 0) < $minW) { $f['width'] = $minW; $changed = true; }
            if (empty($f['is_static']) && $newKey === $key) { $out[$key] = $f; continue; }
            $f['is_static']      = false;
            $f['render_in_bg']   = false;
            $f['enabled']        = true;
            // Preview only: the static block's nudge that sits the digits on the
            // baked "+968", and full-size digits, as print draws them.
            $f['baselineFactor'] = 0.27;
            $f['autoShrink']     = false;
            $out[$newKey] = $f;
            $changed = true;
        }
        printf("%-20s %-5s %s %s\n", $d['slug'], $t['side'], substr($t['id'], 0, 8),
               $changed ? 'convert: ' . implode(',', array_intersect(array_keys(RENAME), array_keys($fields))) : 'no change');
        if ($changed && $apply) {
            file_put_contents("$backupDir/template-{$t['id']}-" . date('Ymd-His') . '.json', $t['fields_json']);
            $db->query("UPDATE templates SET fields_json = ?, updated_at = NOW() WHERE id = ?",
                       [json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $t['id']]);
        }
    }
    // Record the division default once, from what the card baked. Never
    // overwrite a default a division has already set for itself.
    $set = [];
    foreach ($defaults as $col => $val) {
        if ($val !== null && $val !== '' && ($d[$col] === null || $d[$col] === '')) { $set[$col] = $val; }
    }
    if ($set) {
        printf("%-20s defaults %s\n", $d['slug'], json_encode($set));
        if ($apply) { $db->update('departments', $set, 'id = :id', ['id' => $d['id']]); }
    }
    // Employees already on this division carry no office numbers of their own:
    // until now the card supplied them. Give them the division default, or their
    // card would lose its tel lines.
    $def = array_merge($defaults, array_filter([
        'office_tel1' => $d['office_tel1'], 'office_tel2' => $d['office_tel2'], 'office_fax' => $d['office_fax']]));
    $ar = fn($v) => strtr((string)$v, ['0'=>'٠','1'=>'١','2'=>'٢','3'=>'٣','4'=>'٤','5'=>'٥','6'=>'٦','7'=>'٧','8'=>'٨','9'=>'٩']);
    foreach ([['phone', 'phone_ar', 'office_tel1'], ['phone_2', 'phone_2_ar', 'office_tel2'], ['fax', 'fax_ar', 'office_fax']] as [$col, $colAr, $src]) {
        if (empty($def[$src])) { continue; }
        // A number stored with its country code ("+968 2483 5500") would print
        // after the "+968" the card already carries. Keep eight digits.
        foreach ($db->fetchAll("SELECT id, `$col` v FROM employees WHERE department_id = ? AND `$col` <> ''", [$d['id']]) as $e) {
            $clean = preg_replace('/\D/', '', preg_replace('/^\s*(?:\+|00)?968[\s-]*/', '', (string)$e['v']));
            if ($clean === $e['v'] || !preg_match('/^\d{8}$/', $clean)) { continue; }
            printf("%-20s normalise %s.%s %s -> %s\n", $d['slug'], $e['id'], $col, $e['v'], $clean);
            if ($apply) {
                $db->query("UPDATE employees SET `$col` = ?, `$colAr` = ? WHERE id = ?", [$clean, $ar($clean), $e['id']]);
            }
        }
        $n = (int)($db->fetchOne("SELECT COUNT(*) n FROM employees WHERE department_id = ? AND (`$col` IS NULL OR `$col` = '')",
                                 [$d['id']])['n'] ?? 0);
        if (!$n) { continue; }
        printf("%-20s backfill %s=%s on %d employee(s)\n", $d['slug'], $col, $def[$src], $n);
        if ($apply) {
            $db->query("UPDATE employees SET `$col` = ?, `$colAr` = ? WHERE department_id = ? AND (`$col` IS NULL OR `$col` = '')",
                       [$def[$src], $ar($def[$src]), $d['id']]);
        }
    }
}
if ($apply) {
    // Cached PDFs are keyed on template version, not on this edit: clear them so
    // no card is served from the static-text layout.
    $n = 0;
    foreach (glob($root . '/tmp/pdf-vector/*') ?: [] as $f) { if (is_file($f) && @unlink($f)) { $n++; } }
    echo "cleared {$n} cached PDFs\napplied\n";
} else {
    echo "dry run, pass --apply\n";
}
