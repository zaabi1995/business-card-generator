<?php
/**
 * Lazy branded preset thumbnail. Renders (and caches) one preset's front
 * for the current company so the designer gallery loads instantly and the
 * 10 thumbnails stream in parallel. Admin-only, company-scoped.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/CardPresets.php';

requireAdmin();
$companyId = getCurrentCompanyId();
if (!$companyId) { http_response_code(403); exit; }

$preset = preg_replace('/[^a-z_]/', '', (string)($_GET['preset'] ?? ''));
if (!CardPresets::exists($preset)) { http_response_code(404); exit; }

$db = Database::getInstance();
$company = $db->fetchOne("SELECT * FROM companies WHERE id = :id", ['id' => $companyId]);
if (!$company) { http_response_code(404); exit; }
$theme = function_exists('loadCompanyTheme') ? loadCompanyTheme($companyId)
       : $db->fetchOne("SELECT * FROM company_themes WHERE company_id = :id", ['id' => $companyId]);
// Use the company's first employee as the sample so the preview feels real.
$sample = $db->fetchOne(
    "SELECT name_en, name_ar, position_en, position_ar, phone, mobile, email, website
     FROM employees WHERE company_id = :c AND deleted_at IS NULL ORDER BY created_at ASC LIMIT 1",
    ['c' => $companyId]
);

$file = CardPresets::thumbFile($company, $theme, $preset, $sample ?: null);
if (!$file || !is_file($file)) { http_response_code(500); exit; }

header('Content-Type: image/png');
header('Cache-Control: private, max-age=600');
header('Content-Length: ' . filesize($file));
readfile($file);
