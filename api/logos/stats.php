<?php
/**
 * GET /api/logos/stats — aggregated library stats (no PII).
 */
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('X-Attribution: cardify.om/logos');

$db = Database::getInstance();

$stats = $db->fetchOne(
    "SELECT
        COUNT(*)                      AS total,
        SUM(logo_status = 'verified') AS verified,
        SUM(logo_status = 'indexed')  AS indexed,
        MAX(logo_updated_at)          AS last_updated
     FROM om_companies
     WHERE logo_status IN ('indexed','verified')"
);
$downloads    = (int) ($db->fetchOne("SELECT COUNT(*) c FROM logo_downloads")['c'] ?? 0);
$claimsApproved = (int) ($db->fetchOne("SELECT COUNT(*) c FROM logo_claims WHERE status = 'approved'")['c'] ?? 0);

echo json_encode([
    'total'            => (int) ($stats['total'] ?? 0),
    'verified'         => (int) ($stats['verified'] ?? 0),
    'indexed'          => (int) ($stats['indexed'] ?? 0),
    'last_updated'     => $stats['last_updated'] ?? null,
    'downloads_total'  => $downloads,
    'claims_approved'  => $claimsApproved,
    'attribution'      => 'https://cardify.om/logos',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
