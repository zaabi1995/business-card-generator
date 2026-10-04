<?php
/** Import a reviewed government identity manifest. Dry run unless --apply. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/LogoLibrary.php';
$manifestFile = $argv[1] ?? '';
$apply = in_array('--apply', $argv, true);
$manifest = json_decode((string) @file_get_contents($manifestFile), true, 512, JSON_THROW_ON_ERROR);
if (count($manifest['entities'] ?? []) !== 50 || !preg_match('/^[a-f0-9]{64}$/', $manifest['source_sha256'] ?? '')) {
    throw new RuntimeException('Expected the reviewed 50-entity guide manifest');
}
$pdo = Database::getInstance()->getConnection();
$root = dirname(__DIR__);
$prefix = '/storage/logos/indexed/government-2025/';
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--asset-prefix=')) {
        $prefix = substr($argument, strlen('--asset-prefix='));
    }
}
if (!preg_match('~^/storage/logos/indexed/government-2025(?:-[a-z0-9-]+)?/$~', $prefix)) {
    throw new RuntimeException('Invalid government asset directory');
}
$all = $pdo->query('SELECT * FROM om_companies')->fetchAll(PDO::FETCH_ASSOC);
$normalize = static function ($s) {
    return preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower((string) $s));
};
$plan = []; $backup = [];
foreach ($manifest['entities'] as $entity) {
    $matches = array_values(array_filter($all, static function ($c) use ($entity, $normalize) {
        return $normalize($c['name_en']) === $normalize($entity['name_en'])
            || $normalize($c['name_ar']) === $normalize($entity['name_ar']);
    }));
    if (count($matches) > 1) throw new RuntimeException('Ambiguous entity: ' . $entity['name_en']);
    $existing = $matches[0] ?? null;
    if ($existing && in_array($existing['logo_status'], ['takedown','disputed','pending','verified'], true)) {
        throw new RuntimeException('Protected logo requires individual review: ' . $entity['name_en']);
    }
    if (!empty($entity['company_id']) && (int) ($existing['id'] ?? 0) !== (int) $entity['company_id']) {
        throw new RuntimeException('Reviewed company ID no longer matches: ' . $entity['name_en']);
    }
    $layouts = $entity['layouts'];
    foreach ($layouts as &$layout) {
        foreach ($layout['assets'] as &$assets) {
            foreach ($assets as &$path) {
                if (!preg_match('~^[a-z0-9-]+/[a-z0-9-]+\.(svg|pdf|png|webp)$~', $path)) throw new RuntimeException('Invalid asset path');
                $path = $prefix . $path;
                if (!is_file($root . $path) || filesize($root . $path) === 0) throw new RuntimeException('Missing asset: ' . $path);
            }
            unset($path);
        }
        unset($assets);
    }
    unset($layout);
    $entity['layouts'] = $layouts;
    $entity['existing_id'] = $existing['id'] ?? null;
    if ($existing) $backup[] = $existing;
    $plan[] = $entity;
}
if (!$apply) {
    echo json_encode(array_map(static fn($e) => ['name'=>$e['name_en'],'id'=>$e['existing_id'],'action'=>$e['existing_id']?'update':'create'], $plan), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit;
}
$backupDir = $root . '/private/government-logo-imports';
if (!is_dir($backupDir)) mkdir($backupDir, 0700, true);
file_put_contents($backupDir . '/before-' . date('Ymd-His') . '.json', json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$results = [];
$pdo->beginTransaction();
try {
    foreach ($plan as $e) {
        $id = $e['existing_id'];
        if (!$id) {
            $stmt = $pdo->prepare("INSERT INTO om_companies (name_en,name_ar,slug,sector,wilayat,size_bucket,curated) VALUES (:en,:ar,:slug,'government-defense',:wilayat, 'large',0)");
            $stmt->execute([':en'=>$e['name_en'], ':ar'=>$e['name_ar'], ':slug'=>$e['key'], ':wilayat'=>str_ends_with($e['key'], '-governorate') ? str_replace(['-governorate','al-sharqiyah'], ['', 'al-sharqiyah'], $e['key']) : '']);
            $id = (int) $pdo->lastInsertId();
        }
        $normal = $e['layouts']['bilingual']['assets']['normal'];
        $black = $e['layouts']['bilingual']['assets']['black'];
        $white = $e['layouts']['bilingual']['assets']['white'];
        [$w,$h] = getimagesize($root . $normal['png_1024']);
        $paths = ['logo_svg_path'=>$normal['svg'],'logo_png_path'=>$normal['png_1024'], 'logo_png_512_path'=>$normal['png_512'],'logo_png_2048_path'=>$normal['png_2048'],'logo_webp_path'=>$normal['webp'],
            'logo_svg_dark_path'=>$black['svg'],'logo_png_dark_path'=>$black['png_2048'],'logo_webp_dark_path'=>$black['webp'],
            'logo_svg_white_path'=>$white['svg'],'logo_png_white_path'=>$white['png_2048'],'logo_webp_white_path'=>$white['webp']];
        $palette = LogoLibrary::palette($root . $normal['png_1024']);
        $values = $paths + ['logo_identity_assets'=>json_encode(['source_file'=>$manifest['source_file'],'source_sha256'=>$manifest['source_sha256'],'edition'=>2025,'pdf_page'=>$e['pdf_page'],'printed_pages'=>$e['printed_pages'],'layouts'=>$e['layouts']], JSON_UNESCAPED_UNICODE),
            'logo_source'=>'government_guide','logo_source_url'=>$manifest['source_file'] . ' (2025), PDF page ' . $e['pdf_page'],
            'logo_width'=>$w,'logo_height'=>$h,'logo_palette'=>json_encode($palette), 'logo_dominant_color'=>$palette[0] ?? '#CD2027'];
        $sets = []; $params = [':id'=>$id];
        foreach ($values as $column=>$value) { $sets[] = "$column = :$column"; $params[":$column"] = $value; }
        $pdo->prepare("UPDATE om_companies SET " . implode(',', $sets) . ", logo_status='indexed', logo_updated_at=NOW(),logo_variants_at=NOW() WHERE id=:id")->execute($params);
        $slug = $pdo->query('SELECT slug FROM om_companies WHERE id=' . (int) $id)->fetchColumn();
        $results[] = ['id'=>$id,'name_en'=>$e['name_en'],'slug'=>$slug,'action'=>$e['existing_id']?'updated':'created'];
    }
    $pdo->commit();
} catch (Throwable $e) { $pdo->rollBack(); throw $e; }
echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
