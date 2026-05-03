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
    // Sample the WITH-TEXT bg (pre-redaction) because the redacted bg
    // can have the QR modules wiped out where they overlapped inflated
    // text bboxes. parse_card_pdf.py emits both copies as
    // bg-page-N.png (redacted) + bg-page-N-with-text.png (original).
    $withTextPath = preg_replace('/(bg-page-\d+)\.png$/i', '$1-with-text.png', $bgPath);
    if ($withTextPath && $withTextPath !== $bgPath && is_file($withTextPath)) {
        $bgPath = $withTextPath;
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

    // Expand the qr_code field to include the detected outer panel
    // (the rounded container surrounding the QR). The bg PNG can lose
    // the panel during redaction, so we ask the dynamic QR canvas to
    // paint it. Need to grow size + recenter so the QR modules stay
    // pinned to their original position.
    //
    // IDEMPOTENT: derive the canonical original size + position from
    // settings.qr_area + the importer's 90% factor, NOT from the
    // existing field.size (which may already be panel-grown from a
    // prior backfill run, and growing it again would compound).
    //
    // panel_padding_px is sampled at 1200 DPI (BG_DPI). Editor coords
    // are 300 DPI. Scale factor = 300/1200 = 0.25.
    if (!empty($newStyle['panel_padding_px']) && $newStyle['qr_px_width'] > 0) {
        $padEditorPx = (int)round($newStyle['panel_padding_px'] * 300.0 / 1200.0);
        if ($padEditorPx > 0) {
            $editorScale = 300.0 / 72.0;  // PDF points -> editor px
            $qWeditor = (float)$qa['w_pt'] * $editorScale;
            $qHeditor = (float)$qa['h_pt'] * $editorScale;
            $qXeditor = (float)$qa['x_pt'] * $editorScale;
            $qYeditor = (float)$qa['y_pt'] * $editorScale;
            // Original module-only size = 90% of QR area, centred (matches
            // CardifyTemplateImporter::translatePage).
            $origSize = (int)round(min($qWeditor, $qHeditor) * 0.90);
            $origX = (int)round($qXeditor + ($qWeditor - $origSize) / 2);
            $origY = (int)round($qYeditor + ($qHeditor - $origSize) / 2);
            // Now grow by panel padding from the canonical original.
            $newSize = $origSize + 2 * $padEditorPx;
            $newX = max(0, $origX - $padEditorPx);
            $newY = max(0, $origY - $padEditorPx);
            $oldSize = (int)($fields['qr_code']['size'] ?? 0);
            $fields['qr_code']['x'] = $newX;
            $fields['qr_code']['y'] = $newY;
            $fields['qr_code']['size'] = $newSize;
            echo "$tag   panel: pad=" . $newStyle['panel_padding_px'] . "px(bg) -> " . $padEditorPx . "px(editor); orig=$origSize  size $oldSize -> $newSize\n";
        }
    }

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
