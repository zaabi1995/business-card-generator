<?php
/**
 * Backfill templates.fields_json.qr_code.qr_style for every template that
 * has a QR area, by re-running the v2 detector (sample_qr_style) against
 * the template's bg PNG.
 *
 * Run after the detector logic in scripts/parse_card_pdf.py changes so
 * old templates pick up the new mode classifier + eye_color + tighter
 * bg/border sampling without re-importing each PDF (which would lose
 * user-confirmed bindings).
 *
 * Usage:
 *   php scripts/backfill-qr-styles.php                  # all templates with a QR
 *   php scripts/backfill-qr-styles.php --slug=hosn      # single company
 *   php scripts/backfill-qr-styles.php --slug=hosn --side=back
 *   php scripts/backfill-qr-styles.php --dry-run        # show diffs, do not write
 *
 * Bumps current_version on every updated row so card-pdf.php cache + CF
 * cache + Fabric editor all refetch.
 */

$opts = getopt('', ['slug::', 'side::', 'dry-run', 'help']);
if (isset($opts['help'])) {
    fwrite(STDERR, "Usage: php scripts/backfill-qr-styles.php [--slug=hosn] [--side=back] [--dry-run]\n");
    exit(0);
}
$dryRun = isset($opts['dry-run']);
$filterSlug = $opts['slug'] ?? null;
$filterSide = $opts['side'] ?? null;

require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Database.php';

$db = Database::getInstance();

$where = ["t.is_active = 1"];
$params = [];
if ($filterSlug) {
    $where[] = "c.slug = :slug";
    $params['slug'] = $filterSlug;
}
if ($filterSide) {
    $where[] = "t.side = :side";
    $params['side'] = $filterSide;
}
$whereSql = implode(' AND ', $where);

$rows = $db->fetchAll(
    "SELECT t.id, t.side, t.fields_json, t.settings_json, t.background_image_path, t.current_version, c.slug
     FROM templates t
     JOIN companies c ON c.id = t.company_id
     WHERE $whereSql",
    $params
);

if (!$rows) {
    fwrite(STDERR, "No templates matched.\n");
    exit(0);
}

$cli = escapeshellarg(__DIR__ . '/sample_qr_style_cli.py');
$python = '/usr/bin/env python3';
$updated = 0;
$skipped = 0;

foreach ($rows as $row) {
    $tplId = $row['id'];
    $slug = $row['slug'];
    $side = $row['side'];
    $tag = "[$slug/$side $tplId]";

    $fields = json_decode($row['fields_json'] ?: '{}', true) ?: [];
    $settings = json_decode($row['settings_json'] ?: '{}', true) ?: [];
    $qa = $settings['qr_area'] ?? null;
    if (!$qa || !isset($qa['x_pt'], $qa['y_pt'], $qa['w_pt'], $qa['h_pt'])) {
        echo "$tag skip: no qr_area in settings\n";
        $skipped++;
        continue;
    }

    $bgRel = $row['background_image_path'];
    if (!$bgRel) {
        echo "$tag skip: no background_image_path\n";
        $skipped++;
        continue;
    }
    $bgPath = (strpos($bgRel, '/') === 0) ? __DIR__ . '/..' . $bgRel : __DIR__ . '/../' . $bgRel;
    $bgPath = realpath($bgPath);
    if (!$bgPath || !is_file($bgPath)) {
        echo "$tag skip: bg file not found ($bgRel)\n";
        $skipped++;
        continue;
    }

    $cmd = sprintf(
        '%s %s --bg %s --x-pt %s --y-pt %s --w-pt %s --h-pt %s --bg-dpi 1200 --real-qr 2>&1',
        $python,
        $cli,
        escapeshellarg($bgPath),
        escapeshellarg((string)$qa['x_pt']),
        escapeshellarg((string)$qa['y_pt']),
        escapeshellarg((string)$qa['w_pt']),
        escapeshellarg((string)$qa['h_pt'])
    );
    $output = shell_exec($cmd);
    if (!$output) {
        echo "$tag fail: empty output from sampler\n";
        $skipped++;
        continue;
    }
    $jsonStart = strpos($output, '{');
    if ($jsonStart === false) {
        echo "$tag fail: no JSON in sampler output:\n$output\n";
        $skipped++;
        continue;
    }
    $newStyle = json_decode(substr($output, $jsonStart), true);
    if (!is_array($newStyle)) {
        echo "$tag fail: could not parse style JSON:\n$output\n";
        $skipped++;
        continue;
    }

    $oldStyle = $fields['qr_code']['qr_style'] ?? null;
    if ($oldStyle == $newStyle) {
        echo "$tag unchanged (mode={$newStyle['mode']})\n";
        $skipped++;
        continue;
    }
    echo "$tag update: mode={$newStyle['mode']} fg={$newStyle['color']} bg={$newStyle['bg_color']}";
    if ($newStyle['eye_color']) echo " eye={$newStyle['eye_color']}";
    if ($newStyle['has_border']) echo " border={$newStyle['border_color']}/{$newStyle['border_width_px']}px";
    echo "\n";

    if ($dryRun) continue;

    if (!isset($fields['qr_code']) || !is_array($fields['qr_code'])) {
        $fields['qr_code'] = [];
    }
    $fields['qr_code']['qr_style'] = $newStyle;
    $newJson = json_encode($fields, JSON_UNESCAPED_UNICODE);
    $newVersion = (int)$row['current_version'] + 1;
    $db->update(
        'templates',
        ['fields_json' => $newJson, 'current_version' => $newVersion],
        'id = :id',
        ['id' => $tplId]
    );
    $updated++;
}

echo "\nDone. updated=$updated skipped=$skipped " . ($dryRun ? '(dry-run)' : '') . "\n";
