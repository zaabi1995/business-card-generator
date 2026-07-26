<?php
/**
 * The name still shrank below the job title, inverting the hierarchy: 29.7px
 * name under a 33px title. The name only needs to clear whatever is level with
 * IT, and on Group A nothing is: the address block starts at y=299 while the
 * name band is 254-299. So the name gets the full line to the design margin,
 * while the title and subtitle stay boxed by the address block they sit beside.
 */
require_once __DIR__ . '/config.php';
$db = Database::getInstance();
$PX = 4.16667;
$GUTTER = 24.0;
$DESIGN_MARGIN_PT = 9.6;   // same inset the Arabic column uses

// page width pt and bleed per side, per template family
$GEO = ['groupA'=>[258.009,1.417], 'automotive'=>[255.118,0.0],
        'logistics'=>[271.049,1.417], 'consumer'=>[362.154,1.417]];
$FAM = ['automotive'=>'automotive','logistics'=>'logistics','consumer'=>'consumer'];

$rows = $db->fetchAll(
    "SELECT d.slug, t.id, t.fields_json FROM templates t
     JOIN departments d ON d.template_pair_id = t.pair_id
     JOIN companies c ON c.id = d.company_id AND c.slug='mhd'
     WHERE t.side='front'");
foreach ($rows as $r) {
    $f = json_decode($r['fields_json'], true);
    if (!isset($f['name_en']['x'])) continue;
    $fam = $FAM[$r['slug']] ?? 'groupA';
    [$wpt, $bleed] = $GEO[$fam];
    $marginPx = ($wpt - $bleed) * $PX - ($DESIGN_MARGIN_PT * $PX);

    $nx = (float)$f['name_en']['x'];
    $ny = (float)$f['name_en']['y'];
    $nh = (float)($f['name_en']['height'] ?? 45);

    // What actually sits level with the NAME line?
    $colX = null;
    foreach ($f as $k => $v) {
        if (!is_array($v) || !isset($v['x'], $v['y']) || $k === 'qr_code' || $k === 'name_en') continue;
        $x = (float)$v['x']; $y = (float)$v['y']; $h = (float)($v['height'] ?? 30);
        if ($x <= $nx + 150) continue;
        if ($y + $h <= $ny + 4 || $y >= $ny + $nh - 4) continue;   // not level with the name
        $colX = ($colX === null) ? $x : min($colX, $x);
    }
    $limit = ($colX === null) ? $marginPx : ($colX - $GUTTER);
    $f['name_en']['width'] = round($limit - $nx, 2);
    $db->update('templates', ['fields_json' => json_encode($f)], 'id = :id', ['id' => $r['id']]);
    printf("%-20s name band %.0f-%.0f  level-with: %s  name width %.0f\n",
        $r['slug'], $ny, $ny+$nh, $colX === null ? 'nothing' : sprintf('x=%.0f', $colX),
        $f['name_en']['width']);
}
