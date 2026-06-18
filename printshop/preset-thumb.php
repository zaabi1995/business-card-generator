<?php
/**
 * Operator-side branded preset thumbnail for a given client company.
 * Gated to internal-provider operators (BHD). Renders + caches one preset
 * front so printshop/client-templates.php loads fast.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/PrintShopAuth.php';
require_once INCLUDES_DIR . '/CardPresets.php';

PrintShopAuth::requireInternalProvider();

$companyId = trim($_GET['company'] ?? '');
$preset = preg_replace('/[^a-z_]/', '', (string)($_GET['preset'] ?? ''));
if ($companyId === '' || !CardPresets::exists($preset)) { http_response_code(404); exit; }

$db = Database::getInstance();
$company = $db->fetchOne("SELECT * FROM companies WHERE id = :id", ['id' => $companyId]);
if (!$company) { http_response_code(404); exit; }
$theme = function_exists('loadCompanyTheme') ? loadCompanyTheme($companyId)
       : $db->fetchOne("SELECT * FROM company_themes WHERE company_id = :id", ['id' => $companyId]);
$sample = $db->fetchOne(
    "SELECT name_en, name_ar, position_en, position_ar, phone, mobile, email, website
     FROM employees WHERE company_id = :c AND deleted_at IS NULL ORDER BY created_at ASC LIMIT 1",
    ['c' => $companyId]);

$file = CardPresets::thumbFile($company, $theme, $preset, $sample ?: null);
if (!$file || !is_file($file)) { http_response_code(500); exit; }

header('Content-Type: image/png');
header('Cache-Control: private, max-age=600');
header('Content-Length: ' . filesize($file));
readfile($file);
