<?php
/**
 * Shareable Design Link Viewer
 * Public access to shared templates
 */
require_once __DIR__ . '/../config.php';

$token = $_GET['token'] ?? '';
$password = $_POST['password'] ?? '';

if (empty($token)) {
    die('Invalid link');
}

$db = Database::getInstance();
$link = $db->fetchOne(
    "SELECT * FROM design_links WHERE share_token = :token AND is_active = 1",
    ['token' => $token]
);

if (!$link) {
    die('Link not found or expired');
}

// Check expiration
if ($link['expires_at'] && strtotime($link['expires_at']) < time()) {
    die('This link has expired');
}

// Check max access
if ($link['max_access'] && $link['access_count'] >= $link['max_access']) {
    die('Maximum access limit reached');
}

// Check password
$passwordRequired = !empty($link['password_hash']);
$passwordCorrect = false;

if ($passwordRequired) {
    if (!empty($password)) {
        $passwordCorrect = password_verify($password, $link['password_hash']);
        if (!$passwordCorrect) {
            $error = 'Incorrect password';
        }
    }
    
    if (!$passwordCorrect) {
        // Show password form
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Required | <?php echo SITE_NAME; ?></title>
    <link rel="icon" href="<?php echo getBasePath(); ?>favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?php echo getBasePath(); ?>assets/vendor/css/all.css">
    <link rel="stylesheet" href="<?php echo assetUrl('flowbite/app.css'); ?>">
</head>
<body class="bg-gray-50 text-gray-900 min-h-screen flex items-center justify-center">
    <div class="w-full max-w-md mx-auto px-4">
        <div class="bg-white border border-gray-200 shadow-lg rounded-2xl p-8">
            <div class="flex items-center gap-3 mb-6">
                <div class="w-12 h-12 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center">
                    <i class="fa-solid fa-lock"></i>
                </div>
                <div>
                    <h2 class="text-xl font-bold text-gray-900">Password Required</h2>
                    <p class="text-sm text-gray-500">This shared design is protected.</p>
                </div>
            </div>
            <?php if (isset($error)): ?>
            <div class="mb-4 p-3 rounded-lg bg-red-50 text-red-700 text-sm flex items-center gap-2">
                <i class="fa-solid fa-circle-exclamation"></i>
                <?php echo sanitize($error); ?>
            </div>
            <?php endif; ?>
            <form method="post" class="space-y-4">
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                    <input type="password" id="password" name="password" required autofocus
                           class="mt-2 block w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2.5 text-sm text-gray-900 focus:border-blue-600 focus:ring-blue-600">
                </div>
                <button type="submit" class="w-full inline-flex items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-3 text-sm font-semibold text-white hover:bg-blue-700">
                    Access Design
                    <i class="fa-solid fa-arrow-right"></i>
                </button>
            </form>
        </div>
    </div>
</body>
</html>
        <?php
        exit;
    }
}

// Increment access count
$db->update('design_links',
    ['access_count' => $link['access_count'] + 1],
    'id = :id',
    ['id' => $link['id']]
);

// Get template
$template = $db->fetchOne(
    "SELECT * FROM templates WHERE id = :id",
    ['id' => $link['template_id']]
);

if (!$template) {
    die('Template not found');
}

// Get company theme
$companyTheme = $db->fetchOne(
    "SELECT * FROM company_themes WHERE company_id = :id",
    ['id' => $link['company_id']]
);

// Render template preview
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shared Design | <?php echo SITE_NAME; ?></title>
    <link rel="icon" href="<?php echo getBasePath(); ?>favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?php echo getBasePath(); ?>assets/vendor/css/all.css">
    <link rel="stylesheet" href="<?php echo assetUrl('flowbite/app.css'); ?>">
    <style>
        :root {
            --primary-color: <?php echo $companyTheme['primary_color'] ?? '#2563eb'; ?>;
            --secondary-color: <?php echo $companyTheme['secondary_color'] ?? '#0f3460'; ?>;
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
        }
        <?php if ($companyTheme['custom_css']): ?>
        <?php echo $companyTheme['custom_css']; ?>
        <?php endif; ?>
    </style>
</head>
<body class="bg-gray-50 text-gray-900">
    <div class="min-h-screen py-10">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white border border-gray-200 rounded-2xl shadow-lg p-6 sm:p-8">
                <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6 mb-8">
                    <div>
                        <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Shared Design Preview</h1>
                        <p class="text-gray-500 mt-1"><?php echo sanitize($template['name']); ?></p>
                    </div>
                    <div class="flex items-center gap-3">
                        <a href="<?php echo getBaseUrl(); ?>" class="inline-flex items-center gap-2 rounded-lg bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-200">
                            <i class="fa-solid fa-house"></i>
                            Back to Home
                        </a>
                        <a href="<?php echo getBaseUrl(); ?>" class="inline-flex items-center gap-2 rounded-lg btn-primary px-4 py-2 text-sm font-semibold text-white hover:opacity-90">
                            Create Your Card
                            <i class="fa-solid fa-arrow-right"></i>
                        </a>
                    </div>
                </div>

                <?php
                // Render template preview
                $fields = json_decode($template['fields_json'] ?? '{}', true);
                $bgImage = $template['background_image_path'] ? imageUrl($template['background_image_path']) : '';
                ?>

                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4">
                    <div class="overflow-x-auto">
                        <div class="bg-white rounded-lg shadow-sm border border-gray-100" style="width: 1050px; margin: 0 auto;">
                            <div style="width: 1050px; height: 600px; background-image: url('<?php echo $bgImage; ?>'); background-size: cover; background-position: center; position: relative;">
                                <div class="p-8 text-white">
                                    <h2 class="text-2xl font-bold mb-2">Template Preview</h2>
                                    <p class="text-white/80">This is a preview of the shared design template.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <p class="mt-3 text-xs text-gray-500 text-center">Scroll horizontally to view the full design.</p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
