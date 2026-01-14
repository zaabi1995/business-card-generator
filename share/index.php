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
            <link rel="stylesheet" href="<?php echo assetUrl('css/tailwind.css'); ?>">
        </head>
        <body class="bg-alzayani-dark text-white font-sans min-h-screen flex items-center justify-center">
            <div class="glass-card rounded-xl p-8 max-w-md w-full mx-4">
                <h2 class="text-2xl font-bold mb-4">Password Required</h2>
                <?php if (isset($error)): ?>
                <div class="mb-4 p-3 rounded-lg bg-red-500/10 text-red-400 text-sm"><?php echo sanitize($error); ?></div>
                <?php endif; ?>
                <form method="post">
                    <input type="password" name="password" required autofocus class="w-full px-4 py-3 rounded-lg bg-white/5 border border-white/10 text-white mb-4" placeholder="Enter password">
                    <button type="submit" class="w-full py-3 rounded-lg bg-gradient-to-r from-amber-500 to-amber-600 text-white font-bold">Access Design</button>
                </form>
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
    <link rel="stylesheet" href="<?php echo assetUrl('css/tailwind.css'); ?>">
    <style>
        :root {
            --primary-color: <?php echo $companyTheme['primary_color'] ?? '#d4af37'; ?>;
            --secondary-color: <?php echo $companyTheme['secondary_color'] ?? '#0f3460'; ?>;
        }
        <?php if ($companyTheme['custom_css']): ?>
        <?php echo $companyTheme['custom_css']; ?>
        <?php endif; ?>
    </style>
</head>
<body class="bg-alzayani-dark text-white">
    <div class="min-h-screen py-8">
        <div class="max-w-4xl mx-auto px-4">
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold mb-2">Shared Design Preview</h1>
                <p class="text-gray-400"><?php echo sanitize($template['name']); ?></p>
            </div>
            
            <div class="glass-card rounded-xl p-8">
                <div class="bg-white rounded-lg p-8 shadow-2xl" style="max-width: 1050px; margin: 0 auto;">
                    <?php
                    // Render template preview (simplified - you can enhance this)
                    $fields = json_decode($template['fields_json'] ?? '{}', true);
                    $bgImage = $template['background_image_path'] ? imageUrl($template['background_image_path']) : '';
                    ?>
                    <div style="width: 1050px; height: 600px; background-image: url('<?php echo $bgImage; ?>'); background-size: cover; background-position: center; position: relative;">
                        <!-- Template preview content -->
                        <div class="p-8 text-white">
                            <h2 class="text-2xl font-bold mb-4">Template Preview</h2>
                            <p class="text-gray-300">This is a preview of the shared design template.</p>
                        </div>
                    </div>
                </div>
                
                <div class="mt-6 text-center">
                    <a href="<?php echo getBaseUrl(); ?>" class="inline-block px-6 py-3 rounded-lg bg-gradient-to-r from-amber-500 to-amber-600 text-white font-bold hover:from-amber-600 hover:to-amber-700 transition-colors">
                        Create Your Card
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
