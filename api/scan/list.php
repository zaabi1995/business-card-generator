<?php
/**
 * GET /api/scan/list.php, employee's rolodex, search + tag filter + paginate
 *
 * Query: q, tag, limit (default 50, max 100), offset (default 0).
 * Response: {success, scans: [{id, parsed, tags, met_at, met_where, status,
 * image_url, created_at}], total}.
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';

header('Content-Type: application/json');
$ctx = ScanAuth::requireEmployee();
$db = Database::getInstance();

// LIMIT/OFFSET are interpolated directly (PDO can't bind them as query
// params on every driver); safe here only because both are (int) cast
// then clamped to a fixed range before they ever touch the SQL string, so
// no attacker-controlled text can reach the query.
$limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
$offset = max(0, (int)($_GET['offset'] ?? 0));

$where = "employee_id = :e AND status != 'deleted'";
$params = ['e' => $ctx['employee_id']];

$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    // Escape LIKE wildcards in the raw search term before wrapping it with
    // our own %, otherwise a % or _ typed by the user changes the match
    // shape instead of being searched for literally.
    $like = '%' . addcslashes($q, '%_\\') . '%';
    // Same value bound three times under three distinct placeholder names:
    // PDO with emulated prepares off (Database.php) rejects the same named
    // placeholder referenced twice in one query (SQLSTATE HY093).
    $where .= " AND (parsed LIKE :q1 OR tags LIKE :q2 OR met_where LIKE :q3)";
    $params['q1'] = $like;
    $params['q2'] = $like;
    $params['q3'] = $like;
}
$tag = trim($_GET['tag'] ?? '');
if ($tag !== '') {
    $where .= " AND tags LIKE :t";
    $params['t'] = '%' . addcslashes($tag, '%_\\') . '%';
}

try {
    $rows = $db->fetchAll(
        "SELECT id, parsed, tags, met_at, met_where, status, image_path, created_at
         FROM scans WHERE $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset", $params);
    $count = $db->fetchOne("SELECT COUNT(*) c FROM scans WHERE $where", $params);
} catch (\Throwable $e) {
    error_log('[scan/list] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
    exit;
}

$scans = array_map(function ($r) {
    return [
        'id' => (int)$r['id'],
        'parsed' => json_decode($r['parsed'], true),
        'tags' => $r['tags'],
        'met_at' => $r['met_at'],
        'met_where' => $r['met_where'],
        'status' => $r['status'],
        'image_url' => $r['image_path'] ? '/' . $r['image_path'] : null,
        'created_at' => $r['created_at'],
    ];
}, $rows);

echo json_encode(['success' => true, 'scans' => $scans, 'total' => (int)$count['c']], JSON_UNESCAPED_UNICODE);
