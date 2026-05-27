<?php
/**
 * GET /api/logos/random, returns one random indexed/verified brand
 * for the "Surprise me" chip on /logos. Cache-Control: no-store so
 * Cloudflare doesn't pin a single slug for an hour.
 */
require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');

$db = Database::getInstance();
$row = $db->fetchOne(
    "SELECT slug, name_en, name_ar
       FROM om_companies
      WHERE logo_status IN ('indexed','verified')
      ORDER BY RAND() LIMIT 1"
);

if (!$row) {
    echo json_encode(['error' => 'no_brands']);
    exit;
}

echo json_encode([
    'slug'        => $row['slug'],
    'name_en'     => $row['name_en'],
    'name_ar'     => $row['name_ar'],
    'profile_url' => 'https://cardify.om/companies/' . $row['slug'],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
