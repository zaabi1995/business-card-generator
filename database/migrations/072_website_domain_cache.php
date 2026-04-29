<?php
/**
 * Migration 072: backfill website_domain_cache for all om_companies rows.
 * Parses website → host → strip www. → lowercase.
 * Idempotent (re-runs fine).
 */
require_once __DIR__ . '/../../config.php';

function deriveDomainFromUrl(?string $url): ?string {
    if (!$url) return null;
    $url = trim($url);
    if ($url === '') return null;
    if (!preg_match('~^https?://~i', $url)) $url = 'http://' . $url;
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) return null;
    $host = strtolower($host);
    if (strpos($host, 'www.') === 0) $host = substr($host, 4);
    return $host ?: null;
}

try {
    $db  = Database::getInstance();
    $pdo = $db->getConnection();

    $rows = $pdo->query("SELECT id, website, website_domain_cache FROM om_companies")->fetchAll(PDO::FETCH_ASSOC);
    $updated = 0; $skipped = 0;
    $stmt = $pdo->prepare("UPDATE om_companies SET website_domain_cache = :d WHERE id = :id");
    foreach ($rows as $r) {
        $d = deriveDomainFromUrl($r['website']);
        if ($d === ($r['website_domain_cache'] ?? null)) { $skipped++; continue; }
        $stmt->execute([':d' => $d, ':id' => $r['id']]);
        $updated++;
    }
    echo "[072] updated=$updated skipped=$skipped total=" . count($rows) . "\n";
    return ['success' => true];
} catch (Throwable $e) {
    echo "[072] ERROR: " . $e->getMessage() . "\n";
    return ['success' => false, 'errors' => [$e->getMessage()]];
}
