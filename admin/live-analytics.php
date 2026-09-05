<?php
/**
 * Live Analytics Dashboard
 *
 * Reactive analytics view powered by self-hosted Convex. PHP mints a short-lived
 * HS256 JWT, the React island opens a WebSocket subscription to Convex, and
 * KPIs/activity update without a page reload.
 *
 * URL: /admin/live-analytics.php?employee=<id>&days=<1|7|30|90>
 *
 * Feature-flagged via FEATURE_LIVE_ANALYTICS in config.php. When the flag is
 * off or Convex is not configured, this page redirects to the static
 * card-analytics.php so the navigation never dead-ends.
 */

require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';

requireAdmin();
$companyId = getCurrentCompanyId();
if (!$companyId) {
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

$liveEnabled = defined('FEATURE_LIVE_ANALYTICS') && FEATURE_LIVE_ANALYTICS;
$convexBrowserUrl = defined('CONVEX_BROWSER_URL') ? CONVEX_BROWSER_URL : '';
$convexAuthSecret = defined('CONVEX_AUTH_SECRET') ? CONVEX_AUTH_SECRET : '';

if (!$liveEnabled || $convexBrowserUrl === '' || $convexAuthSecret === '') {
    // Live analytics not provisioned, fall back to static dashboard.
    header('Location: ' . getBasePath() . 'admin/card-analytics.php');
    exit;
}

$days = isset($_GET['days']) ? (int) $_GET['days'] : 7;
$days = in_array($days, [1, 7, 30, 90], true) ? $days : 7;

$employeeId = trim($_GET['employee'] ?? '');
$employees  = function_exists('loadEmployees') ? loadEmployees($companyId) : [];

$company = function_exists('findCompanyById') ? findCompanyById($companyId) : null;
$companySlug   = $company['slug']    ?? (string) $companyId;
$companyNameEn = $company['name_en'] ?? $company['name'] ?? $companySlug;

$user = Auth::getCurrentUser();
$role = Auth::getCurrentRole() ?: 'company_admin';
$adminId = (string) ($user['id'] ?? 'unknown');
$adminName = (string) ($user['name'] ?? $user['email'] ?? 'Admin');

/**
 * Mint a short-lived HS256 JWT. Convex `auth.config.ts` validates with the
 * same shared secret; `lib/identity.ts` reads claims.
 *
 * Tokens are valid for 10 minutes. The React island reloads its identity on
 * the next page navigation; long-lived sessions don't strictly need a refresh
 * loop because reactive queries reconnect with whatever token they hold.
 */
function mintConvexToken(string $secret, array $claims): string
{
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $now = time();
    $payload = array_merge([
        'iss' => 'cardify-admin',
        'aud' => 'convex-cardify',
        'iat' => $now,
        'nbf' => $now - 5,
        'exp' => $now + 600, // 10 min
    ], $claims);

    $b64 = function ($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    };
    $h = $b64(json_encode($header, JSON_UNESCAPED_SLASHES));
    $p = $b64(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $sig = $b64(hash_hmac('sha256', $h . '.' . $p, $secret, true));
    return $h . '.' . $p . '.' . $sig;
}

$token = mintConvexToken($convexAuthSecret, [
    'sub'         => $adminId,
    'tenantSlug'  => $companySlug,
    'tenantId'    => $companyId,    // PHP companyId == Convex companies.mysqlId; resolved at query time
    'role'        => $role,
    'nameEn'      => $adminName,
]);

/**
 * Locate the Vite-built bundle entry. Vite emits a manifest at
 * `/assets/live-analytics/manifest.json` with the hashed entry name.
 * In dev or before the first build, fall back to the unhashed entry.
 */
function liveAnalyticsBundle(): array
{
    $manifestPath = __DIR__ . '/../assets/live-analytics/manifest.json';
    if (is_readable($manifestPath)) {
        $manifest = json_decode(file_get_contents($manifestPath), true);
        if (is_array($manifest)) {
            foreach ($manifest as $entry) {
                if (!empty($entry['isEntry']) && !empty($entry['file'])) {
                    return [
                        'js'  => 'assets/live-analytics/' . $entry['file'],
                        'css' => isset($entry['css'][0])
                            ? 'assets/live-analytics/' . $entry['css'][0]
                            : null,
                    ];
                }
            }
        }
    }
    return ['js' => 'assets/live-analytics/main.js', 'css' => null];
}

$bundle = liveAnalyticsBundle();

adminHeader('Live Analytics', 'analytics');
?>

<div>
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <p class="text-gray-600">Real-time card events. Updates without refresh.</p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <select data-cardify-change-fn="laFilterEmployee" data-arg="value" class="px-3 py-2 bg-white border border-gray-200 rounded-lg text-sm">
                <option value="">All employees</option>
                <?php foreach ($employees as $emp): ?>
                    <option value="<?php echo sanitize($emp['id']); ?>" <?php echo $employeeId === $emp['id'] ? 'selected' : ''; ?>>
                        <?php echo sanitize($emp['name_en'] ?? $emp['email']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <a class="px-3 py-2 bg-white border border-gray-200 rounded-lg text-sm hover:bg-gray-50" href="<?php echo getBasePath(); ?>admin/card-analytics.php<?php echo $employeeId ? '?employee=' . urlencode($employeeId) : ''; ?>">
                Static (last 30 days)
            </a>
        </div>
    </div>

    <?php if ($bundle['css']): ?>
        <link rel="stylesheet" href="<?php echo getBasePath() . sanitize($bundle['css']); ?>" />
    <?php endif; ?>

    <div
        id="live-analytics-root"
        data-convex-url="<?php echo sanitize($convexBrowserUrl); ?>"
        data-token="<?php echo sanitize($token); ?>"
        data-employee-id="<?php echo sanitize($employeeId); ?>"
        data-days="<?php echo (int) $days; ?>"
    ></div>

    <script type="module" src="<?php echo getBasePath() . sanitize($bundle['js']); ?>"></script>

    <script<?= cspNonceAttr() ?>>
        function laFilterEmployee(empId) {
            var url = new URL(window.location.href);
            if (empId) url.searchParams.set('employee', empId);
            else url.searchParams.delete('employee');
            window.location.href = url.toString();
        }
    </script>
</div>

<?php adminFooter(); ?>
