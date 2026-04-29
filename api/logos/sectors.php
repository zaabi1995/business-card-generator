<?php
/**
 * GET /api/logos/sectors, sector index with counts.
 */
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('X-Attribution: cardify.om/logos');

$db   = Database::getInstance();
$rows = $db->fetchAll(
    "SELECT sector, COUNT(*) c FROM om_companies
     WHERE logo_status IN ('indexed','verified')
     GROUP BY sector ORDER BY c DESC"
);

echo json_encode([
    'sectors' => array_map(
        fn($r) => [
            'slug'  => $r['sector'],
            'count' => (int) $r['c'],
            'url'   => "https://cardify.om/logos/{$r['sector']}",
        ],
        $rows
    ),
    'attribution' => 'https://cardify.om/logos',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
