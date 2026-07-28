<?php
/**
 * POST /api/scan/resolve-card.php, resolve a scanned QR URL to a Cardify card
 *
 * Body: {url: "..."} -> {success, card: {...}, card_url}
 *
 * The Cardify Scan mobile app calls this the moment a scanned QR decodes to
 * text that LOOKS like a URL and the host is cardify.om. When the card was
 * made with Cardify, this returns the employee's data in the same canonical
 * shape ScanParser/ScanVcf use, a perfect fill instead of a photo-OCR guess.
 *
 * Host is checked strictly against cardify.om / www.cardify.om (parse_url,
 * no substring match). That is deliberate, not an oversight: every
 * programmatically-generated Cardify QR/URL (the printed-card QR via
 * qr.php, the vcf.php Save Contact link, the /{slug}/card/{id} and
 * /{slug}/{token} share forms) is built on the apex host, see VCF.php,
 * qr.php, router.php and CardifyConvention::employeeShareUrl(). A tenant
 * subdomain URL (otech.cardify.om/...) is never what gets baked into a
 * physical card's own QR code, so it is intentionally out of scope here.
 *
 * URL shapes resolved (all on host cardify.om or www.cardify.om):
 *   /qr.php?i={employeeId}                    printed-card QR, short form
 *   /qr.php?c={slug}&e={email}                printed-card QR, legacy form
 *   /vcf.php?company={slug}&email={email}     Save Contact link
 *   /{slug}/card/{id}                         share link (pre-redirect)
 *   /{slug}/{token}                           share link (pre-redirect)
 * Everything else (unmatched path, unmatched host, or a scanned payload
 * that is not a URL at all, e.g. the vcard-qr-generator tool at
 * /tools/vcard-qr-generator embeds a raw BEGIN:VCARD...END:VCARD text
 * blob, never a URL) returns not_a_cardify_card. The app is expected to
 * fall back to its own OCR/vCard parsing in that case.
 *
 * Only resolves employees whose card the public card page (digital_card.php)
 * would itself resolve, see CardifyConvention::resolveEmployeeToken(). That
 * function has no status gate on an exact id/email match (mirrors
 * digital_card.php's current behaviour) and gates the fuzzy localpart
 * fallback to status=active + not deleted.
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';
require_once INCLUDES_DIR . '/CardifyConvention.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST only']);
    exit;
}

// Auth is OPTIONAL here, deliberately.
//
// This used to require a bearer token, described as keeping the endpoint from
// being an open cardify.om scraper. It never did that: every field it returns
// is already served, unauthenticated, as HTML at the very URL being resolved,
// so a scraper simply fetches the page. What the gate actually blocked was the
// product's most important moment, a person who was sent a colleague's card,
// tapped it, and landed in the app with no account. They were shown a sign-in
// wall for a page anyone can read in a browser.
//
// So: a signed-in app gets the generous per-account budget as before, and an
// anonymous caller gets a much tighter IP-keyed one. The rate limiter already
// keys on (action, account_id or 'anon', client IP), and getClientIp is
// CF-gated, so a spoofed IP cannot multiply the anonymous budget.
require_once __DIR__ . '/_ratelimit.php';
$ctx = [];
if (ScanAuth::presentedBearerTokenHash() !== null) {
    $ctx = ScanAuth::requireEmployeeMutation();
    scanRateLimit($ctx, 'resolve', 240);
} else {
    scanRateLimit($ctx, 'resolve_anon', 40);
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$rawUrl = trim((string) ($body['url'] ?? ''));
if ($rawUrl === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'missing_url']);
    exit;
}

try {
    $scheme = strtolower((string) (parse_url($rawUrl, PHP_URL_SCHEME) ?? ''));
    $host = strtolower((string) (parse_url($rawUrl, PHP_URL_HOST) ?? ''));
    // Strip a trailing dot (DNS root) and any port suffix parse_url may have
    // left attached to a malformed host string.
    $host = rtrim($host, '.');

    $tenantSlug = null;
    if ($scheme !== 'https') {
        echo json_encode(['success' => false, 'error' => 'not_a_cardify_card']);
        exit;
    }
    if (!in_array($host, ['cardify.om', 'www.cardify.om'], true)) {
        if (!preg_match('/^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)\\.cardify\\.om$/', $host, $hostMatch)) {
            echo json_encode(['success' => false, 'error' => 'not_a_cardify_card']);
            exit;
        }
        $tenantSlug = strtolower((string) $hostMatch[1]);
        if (in_array($tenantSlug, CardifyConvention::reservedSlugs(), true)) {
            echo json_encode(['success' => false, 'error' => 'not_a_cardify_card']);
            exit;
        }
    }

    $path = (string) (parse_url($rawUrl, PHP_URL_PATH) ?? '');
    $path = '/' . trim($path, '/'); // normalize: no trailing slash, leading slash
    $queryString = (string) (parse_url($rawUrl, PHP_URL_QUERY) ?? '');
    $q = [];
    parse_str($queryString, $q);

    $db = Database::getInstance();
    $company = null;
    $employee = null;

    if ($tenantSlug !== null) {
        $segments = array_values(array_filter(explode('/', $path), function ($s) {
            return $s !== '';
        }));
        $company = findCompanyBySlug($tenantSlug);
        if ($company && count($segments) === 1) {
            $employee = CardifyConvention::resolveEmployeeToken(
                rawurldecode($segments[0]),
                $company['id'],
                $db
            );
        } elseif ($company && count($segments) === 2 && strcasecmp($segments[0], 'card') === 0) {
            $employee = CardifyConvention::resolveEmployeeToken(
                rawurldecode($segments[1]),
                $company['id'],
                $db
            );
        }
    } elseif (strcasecmp($path, '/qr.php') === 0) {
        // Printed-card QR. Mirrors qr.php's own two lookup shapes exactly.
        $i = trim((string) ($q['i'] ?? ''));
        $c = trim((string) ($q['c'] ?? $q['company'] ?? ''));
        $e = trim((string) ($q['e'] ?? $q['email'] ?? ''));
        if ($i !== '') {
            $employee = $db->fetchOne(
                "SELECT * FROM employees WHERE id = :id AND status = 'active' LIMIT 1",
                ['id' => $i]
            ) ?: null;
            if ($employee) {
                $company = $db->fetchOne(
                    "SELECT * FROM companies WHERE id = :cid",
                    ['cid' => $employee['company_id']]
                ) ?: null;
            }
        } elseif ($c !== '' && $e !== '') {
            $company = findCompanyBySlug($c);
            if ($company) {
                $employee = findEmployeeByEmail($e, $company['id']);
            }
        }
    } elseif (strcasecmp($path, '/vcf.php') === 0) {
        // Save Contact link.
        $slug = trim((string) ($q['company'] ?? ''));
        $email = trim((string) ($q['email'] ?? ''));
        $email = preg_replace('/\.vcf$/i', '', $email);
        if ($slug !== '' && $email !== '') {
            $company = findCompanyBySlug($slug);
            if ($company) {
                $employee = CardifyConvention::resolveEmployeeToken($email, $company['id'], $db);
            }
        }
    } else {
        // /{slug}/card/{token} or /{slug}/{token} share-link forms
        // (the apex form; router.php 301s these to the tenant subdomain,
        // but the QR/link payload itself is always this apex path).
        $segments = array_values(array_filter(explode('/', $path), function ($s) {
            return $s !== '';
        }));
        if (count($segments) === 2 || count($segments) === 3) {
            $slug = rawurldecode($segments[0]);
            if (!in_array($slug, CardifyConvention::reservedSlugs(), true) && strpos($slug, '.') === false) {
                $company = findCompanyBySlug($slug);
                if ($company) {
                    if (count($segments) === 3 && strcasecmp($segments[1], 'card') === 0) {
                        $token = rawurldecode($segments[2]);
                        $employee = CardifyConvention::resolveEmployeeToken($token, $company['id'], $db);
                    } elseif (count($segments) === 2) {
                        $token = rawurldecode($segments[1]);
                        $employee = CardifyConvention::resolveEmployeeToken($token, $company['id'], $db);
                    }
                }
            }
        }
    }

    if (!$company || !$employee) {
        // Host matched but nothing on this path resolves, distinct from a
        // host that never looked like a Cardify card URL at all.
        $recognizedShape = ($tenantSlug !== null
                && isset($segments)
                && (count($segments) === 1
                    || (count($segments) === 2 && strcasecmp($segments[0], 'card') === 0)))
            || strcasecmp($path, '/qr.php') === 0
            || strcasecmp($path, '/vcf.php') === 0
            || (isset($segments) && (count($segments) === 2 || count($segments) === 3));
        echo json_encode(['success' => false, 'error' => $recognizedShape ? 'not_found' : 'not_a_cardify_card']);
        exit;
    }

    $card = CardifyConvention::employeeToScanCard($employee, $company);
    $cardUrl = CardifyConvention::employeeShareUrl((string) $company['slug'], $employee);

    echo json_encode([
        'success' => true,
        'card' => $card,
        'card_url' => $cardUrl,
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('[scan/resolve-card] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
    exit;
}
