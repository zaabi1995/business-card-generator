<?php
/**
 * Share Link Gateway
 * Routes token to digital card page (share_links) or template preview (design_links)
 */
require_once __DIR__ . '/../config.php';

$token    = $_GET['token'] ?? '';
$password = $_POST['password'] ?? '';

/* ─── helpers ─────────────────────────────────────────────────────────────── */

function renderErrorPage(string $title, string $message, int $httpCode = 404): void {
    http_response_code($httpCode);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title); ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', ui-sans-serif, system-ui, sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            color: #1e293b;
        }
        .card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 1.25rem;
            box-shadow: 0 10px 40px rgba(0,0,0,.08);
            padding: 2.5rem 2rem;
            max-width: 420px;
            width: 100%;
            text-align: center;
        }
        .icon {
            width: 64px; height: 64px;
            border-radius: .875rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            margin-bottom: 1.25rem;
        }
        .icon-red   { background: #fee2e2; color: #dc2626; }
        .icon-amber { background: #fef3c7; color: #d97706; }
        h1 { font-size: 1.375rem; font-weight: 700; margin-bottom: .5rem; }
        p  { color: #64748b; font-size: .9375rem; line-height: 1.6; }
        a {
            display: inline-flex;
            align-items: center;
            gap: .375rem;
            margin-top: 1.5rem;
            padding: .625rem 1.25rem;
            background: #2563eb;
            color: #fff;
            border-radius: .625rem;
            text-decoration: none;
            font-size: .875rem;
            font-weight: 600;
        }
        a:hover { background: #1d4ed8; }
    </style>
</head>
<body>
    <div class="card">
        <?php if ($httpCode === 410): ?>
        <div class="icon icon-amber">&#9203;</div>
        <?php else: ?>
        <div class="icon icon-red">&#10060;</div>
        <?php endif; ?>
        <h1><?php echo htmlspecialchars($title); ?></h1>
        <p><?php echo htmlspecialchars($message); ?></p>
        <a href="/">&#8592; Go Home</a>
    </div>
</body>
</html>
    <?php
    exit;
}

/* ─── validate token ───────────────────────────────────────────────────────── */

if (empty($token)) {
    renderErrorPage('Invalid Link', 'No share token was provided.', 400);
}

$db = Database::getInstance();

/* ═══════════════════════════════════════════════════════════════════════════
   1. Try share_links first (employee digital card)
   ═══════════════════════════════════════════════════════════════════════════ */

$shareRow = $db->fetchOne(
    "SELECT sl.*, c.slug AS company_slug
       FROM share_links sl
       JOIN companies c ON c.id = sl.company_id
      WHERE sl.token = :token",
    ['token' => $token]
);

if ($shareRow) {
    // Check expiration
    if (!empty($shareRow['expires_at']) && strtotime($shareRow['expires_at']) < time()) {
        renderErrorPage('Link Expired', 'This share link has expired and is no longer valid.', 410);
    }

    // Increment view count
    $db->query(
        "UPDATE share_links SET view_count = view_count + 1 WHERE id = :id",
        ['id' => $shareRow['id']]
    );

    // Redirect to digital card. Defensive: validate slug + id shape so a
    // tampered share_links row can't inject a header (CR/LF) or send us to
    // a different host.
    $slug = preg_match('~^[a-z0-9-]{1,64}$~i', $shareRow['company_slug'] ?? '')
        ? $shareRow['company_slug'] : null;
    $empId = preg_match('~^[a-z0-9_-]{1,64}$~i', $shareRow['employee_id'] ?? '')
        ? $shareRow['employee_id'] : null;
    if ($slug && $empId) {
        header('Location: ' . getTenantCardUrl($slug, 'card/' . $empId), true, 302);
        exit;
    }
    renderErrorPage('Link Invalid', 'This share link points to a record we could not resolve.', 410);
}

/* ═══════════════════════════════════════════════════════════════════════════
   2. Try design_links (template preview)
   ═══════════════════════════════════════════════════════════════════════════ */

$designRow = $db->fetchOne(
    "SELECT dl.*, c.slug AS company_slug
       FROM design_links dl
       JOIN companies c ON c.id = dl.company_id
      WHERE dl.share_token = :token
        AND dl.is_active = 1",
    ['token' => $token]
);

if (!$designRow) {
    renderErrorPage('Link Not Found', 'This share link does not exist or has been removed.', 404);
}

// Check expiration
if (!empty($designRow['expires_at']) && strtotime($designRow['expires_at']) < time()) {
    renderErrorPage('Link Expired', 'This design link has expired and is no longer valid.', 410);
}

// Check max access
if (!empty($designRow['max_access']) && $designRow['access_count'] >= $designRow['max_access']) {
    renderErrorPage('Access Limit Reached', 'This design link has reached its maximum number of views.', 410);
}

/* ─── password protection ──────────────────────────────────────────────────── */

$passwordRequired = !empty($designRow['password_hash']);
$passwordCorrect  = false;
$passwordError    = '';

if ($passwordRequired) {
    if (!empty($password)) {
        if (password_verify($password, $designRow['password_hash'])) {
            $passwordCorrect = true;
        } else {
            $passwordError = 'Incorrect password. Please try again.';
        }
    }

    if (!$passwordCorrect) {
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Required</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', ui-sans-serif, system-ui, sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            color: #1e293b;
        }
        .card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 1.25rem;
            box-shadow: 0 10px 40px rgba(0,0,0,.08);
            padding: 2.5rem 2rem;
            max-width: 420px;
            width: 100%;
        }
        .header {
            display: flex;
            align-items: center;
            gap: .875rem;
            margin-bottom: 1.5rem;
        }
        .icon {
            width: 48px; height: 48px;
            background: #fef3c7;
            color: #d97706;
            border-radius: .75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }
        .header h2 { font-size: 1.125rem; font-weight: 700; }
        .header p  { color: #64748b; font-size: .8125rem; margin-top: .2rem; }
        .error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
            border-radius: .5rem;
            padding: .625rem .875rem;
            font-size: .875rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: .5rem;
        }
        label {
            display: block;
            font-size: .875rem;
            font-weight: 500;
            color: #374151;
            margin-bottom: .375rem;
        }
        input[type="password"] {
            width: 100%;
            border: 1px solid #d1d5db;
            border-radius: .5rem;
            background: #f9fafb;
            padding: .625rem .875rem;
            font-size: .9375rem;
            color: #111827;
            outline: none;
            transition: border-color .15s;
        }
        input[type="password"]:focus { border-color: #2563eb; background: #fff; }
        button {
            margin-top: 1rem;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: .5rem;
            padding: .75rem 1rem;
            background: #2563eb;
            color: #fff;
            border: none;
            border-radius: .625rem;
            font-size: .9375rem;
            font-weight: 600;
            cursor: pointer;
            transition: background .15s;
        }
        button:hover { background: #1d4ed8; }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            <div class="icon">&#128274;</div>
            <div>
                <h2>Password Required</h2>
                <p>This shared design is protected.</p>
            </div>
        </div>
        <?php if ($passwordError): ?>
        <div class="error">
            <span>&#9888;</span>
            <?php echo htmlspecialchars($passwordError); ?>
        </div>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
            <div>
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required autofocus
                       placeholder="Enter password">
            </div>
            <button type="submit">
                Access Design &#8594;
            </button>
        </form>
    </div>
</body>
</html>
        <?php
        exit;
    }
}

/* ─── increment access count ───────────────────────────────────────────────── */

$db->query(
    "UPDATE design_links SET access_count = access_count + 1 WHERE id = :id",
    ['id' => $designRow['id']]
);

/* ─── load template ─────────────────────────────────────────────────────────── */

$template = $db->fetchOne(
    "SELECT * FROM templates WHERE id = :id",
    ['id' => $designRow['template_id']]
);

if (!$template) {
    renderErrorPage('Template Not Found', 'The design template for this link no longer exists.', 404);
}

// Get company theme for colour vars
$companyTheme = $db->fetchOne(
    "SELECT * FROM company_themes WHERE company_id = :id",
    ['id' => $designRow['company_id']]
);

$primaryColor   = $companyTheme['primary_color']   ?? '#2563eb';
$secondaryColor = $companyTheme['secondary_color'] ?? '#0f3460';
$customCss      = !empty($companyTheme['custom_css'])
    ? preg_replace('/<\s*\/?\s*(style|script)[^>]*>/i', '', $companyTheme['custom_css'])
    : '';

$bgImage = !empty($template['background_image_path']) ? imageUrl($template['background_image_path']) : '';

/* ─── render template preview ───────────────────────────────────────────────── */

$pageTitle  = 'Shared Design Preview';
$extraHead  = '<style>
    :root {
        --primary-color: ' . htmlspecialchars($primaryColor) . ';
        --secondary-color: ' . htmlspecialchars($secondaryColor) . ';
    }
    .btn-primary {
        background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
    }
    ' . $customCss . '
</style>';

require_once INCLUDES_DIR . '/ui-header.php';
?>
<div class="min-h-screen py-10">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="bg-white border border-gray-200 rounded-2xl shadow-lg p-6 sm:p-8">

            <!-- Header -->
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6 mb-8">
                <div>
                    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Shared Design Preview</h1>
                    <p class="text-gray-500 mt-1"><?php echo sanitize($template['name']); ?></p>
                </div>
                <div class="flex items-center gap-3">
                    <a href="<?php echo getBaseUrl(); ?>"
                       class="inline-flex items-center gap-2 rounded-lg bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-200">
                        &#8592; Back to Home
                    </a>
                    <a href="<?php echo getBaseUrl(); ?>"
                       class="inline-flex items-center gap-2 rounded-lg btn-primary px-4 py-2 text-sm font-semibold text-white hover:opacity-90">
                        Create Your Card &#8594;
                    </a>
                </div>
            </div>

            <!-- Template Preview -->
            <div class="rounded-xl border border-gray-200 bg-gray-50 p-2 sm:p-4">
                <div class="w-full">
                    <div class="bg-white rounded-lg shadow-sm border border-gray-100 w-full max-w-[1050px] mx-auto">
                        <div class="w-full"
                             style="aspect-ratio: 1050/600;
                                    background-image: url('<?php echo htmlspecialchars($bgImage); ?>');
                                    background-size: cover;
                                    background-position: center;
                                    position: relative;">
                            <div class="p-4 sm:p-8 text-white">
                                <h2 class="text-lg sm:text-2xl font-bold mb-2">Template Preview</h2>
                                <p class="text-white/80 text-sm sm:text-base">
                                    This is a preview of the shared design template.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>
<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
