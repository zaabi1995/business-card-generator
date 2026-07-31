<?php

require_once __DIR__ . '/../config.php';
requireAdmin();
require_once INCLUDES_DIR . '/WalletThemePolicy.php';

$companyId = getCurrentCompanyId();
$themeId = trim((string)($_GET['id'] ?? ''));
if (!$companyId || $themeId === '') {
    http_response_code(404);
    exit;
}

$theme = Database::getInstance()->fetchOne(
    'SELECT * FROM wallet_themes
      WHERE id = :id AND company_id = :company_id
      LIMIT 1',
    ['id' => $themeId, 'company_id' => $companyId]
);
if (!is_array($theme)) {
    http_response_code(404);
    exit;
}

try {
    $theme = WalletThemePolicy::validateTheme($theme);
} catch (InvalidArgumentException $error) {
    http_response_code(422);
    exit;
}

$xml = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
};
$background = $xml($theme['background_color']);
$foreground = $xml($theme['foreground_color']);
$label = $xml($theme['label_color']);
$name = $xml($theme['name_en']);
$style = $xml($theme['style']);

header('Content-Type: image/svg+xml; charset=UTF-8');
header('Cache-Control: private, max-age=60');
echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<svg xmlns="http://www.w3.org/2000/svg" width="632" height="400" viewBox="0 0 632 400" role="img" aria-label="<?= $name ?>">
    <rect width="632" height="400" rx="34" fill="<?= $background ?>"/>
    <circle cx="542" cy="72" r="38" fill="<?= $foreground ?>" fill-opacity=".12"/>
    <text x="44" y="72" fill="<?= $label ?>" font-family="Arial, sans-serif" font-size="19" font-weight="700">CARDIFY</text>
    <text x="44" y="180" fill="<?= $label ?>" font-family="Arial, sans-serif" font-size="15">COMPANY PASS</text>
    <text x="44" y="222" fill="<?= $foreground ?>" font-family="Arial, sans-serif" font-size="30" font-weight="700"><?= $name ?></text>
    <text x="44" y="336" fill="<?= $label ?>" font-family="Arial, sans-serif" font-size="14"><?= $style ?></text>
</svg>
