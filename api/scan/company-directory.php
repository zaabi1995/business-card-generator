<?php
/**
 * GET /api/scan/company-directory.php -> every ACTIVE colleague in the signed-in
 * user's Cardify company as a read-only contact card (name, title, phones,
 * email, website, socials, photo, public card URL + company branding). This is
 * the in-app "Team" directory: no schema change, the data already lives on
 * employees + company_themes. Bearer-auth.
 *
 * Query: ?limit (default 200, max 500), ?offset, ?q, ?since=<datetime> for delta
 *   (returns only colleagues changed at/after `since`, for cheap re-sync).
 * Response: {success, company:{id,name,slug}, contacts:[...], total,
 *   offset, limit, has_more}
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';
require_once INCLUDES_DIR . '/CardifyConvention.php';

header('Content-Type: application/json');
$ctx = ScanAuth::requireEmployee();
require_once __DIR__ . '/_ratelimit.php';
scanRateLimit($ctx, 'directory', 600);

$db = Database::getInstance();
$companyId = $ctx['company_id'];
$self = $ctx['employee_id'];

$limit  = min(500, max(1, (int) ($_GET['limit'] ?? 200)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));

$company = $db->fetchOne("SELECT id, name, slug FROM companies WHERE id = :id", ['id' => $companyId]);
if (!$company) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'company_not_found']);
    exit;
}

// Company branding (shared): color + logo, same store my-card reads.
$theme = $db->fetchOne(
    "SELECT primary_color, logo_path FROM company_themes WHERE company_id = :cid",
    ['cid' => $companyId]
);
$apex = cardifyApexHost();
$logoUrl = null;
if ($theme && !empty($theme['logo_path'])) {
    $lp = ltrim((string) $theme['logo_path'], '/');
    if (strpos($lp, 'uploads/') !== 0) {
        $lp = 'uploads/' . $lp;
    }
    $logoUrl = 'https://' . $apex . '/' . $lp;
}

$where = "company_id = :cid AND status = 'active' AND deleted_at IS NULL AND id <> :self";
$params = ['cid' => $companyId, 'self' => $self];
$since = trim($_GET['since'] ?? '');
if ($since !== '') {
    $where .= " AND updated_at >= :since";
    $params['since'] = date('Y-m-d H:i:s', strtotime($since) ?: 0);
}
$query = trim((string) ($_GET['q'] ?? ''));
if ($query !== '') {
    $where .= " AND (
        name_en LIKE :query_name_en
        OR name_ar LIKE :query_name_ar
        OR position_en LIKE :query_position_en
        OR position_ar LIKE :query_position_ar
        OR email LIKE :query_email
    )";
    $queryPattern = '%' . mb_substr($query, 0, 120) . '%';
    $params['query_name_en'] = $queryPattern;
    $params['query_name_ar'] = $queryPattern;
    $params['query_position_en'] = $queryPattern;
    $params['query_position_ar'] = $queryPattern;
    $params['query_email'] = $queryPattern;
}

try {
    // limit/offset are (int)-cast + clamped above, safe to interpolate (PDO
    // cannot bind them on every driver); all user text is bound.
    $rows = $db->fetchAll(
        "SELECT * FROM employees WHERE $where ORDER BY name_en ASC, id ASC LIMIT $limit OFFSET $offset",
        $params
    );
    $count = $db->fetchOne("SELECT COUNT(*) c FROM employees WHERE $where", $params);
} catch (\Throwable $e) {
    error_log('[scan/company-directory] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
    exit;
}

$slug = (string) ($company['slug'] ?? '');

$contacts = array_map(function ($emp) use ($company, $slug, $apex, $theme, $logoUrl) {
    // Canonical parsed shape (shared with scans), then enrich past its lossy
    // base map so the directory card carries socials + photo + the public URL.
    $card = CardifyConvention::employeeToScanCard($emp, $company);
    $card['id']       = (string) $emp['id'];
    $card['linkedin'] = trim((string) ($emp['linkedin'] ?? ''));
    $card['twitter']  = trim((string) ($emp['twitter'] ?? ''));

    $photo = trim((string) ($emp['photo'] ?? ''));
    if ($photo === '') {
        $card['photo_url'] = null;
    } elseif (strpos($photo, 'http') === 0) {
        $card['photo_url'] = $photo;
    } else {
        $pp = ltrim($photo, '/');
        if (strpos($pp, 'uploads/') !== 0) {
            $pp = 'uploads/' . $pp;
        }
        $card['photo_url'] = 'https://' . $apex . '/' . $pp;
    }

    $card['public_url'] = CardifyConvention::employeeShareUrl($slug, $emp);
    $card['updated_at'] = $emp['updated_at'] ?? null;
    $card['design'] = [
        'primary_color' => $theme['primary_color'] ?? null,
        'logo_url'      => $logoUrl,
    ];
    return $card;
}, $rows);

echo json_encode([
    'success'  => true,
    'company'  => ['id' => (string) $company['id'], 'name' => (string) $company['name'], 'slug' => $slug],
    'contacts' => $contacts,
    'total'    => (int) $count['c'],
    'offset'   => $offset,
    'limit'    => $limit,
    'has_more' => $offset + count($contacts) < (int) $count['c'],
], JSON_UNESCAPED_UNICODE);
