<?php
/**
 * Company Theme Management
 * Allows companies to customize their branding
 */
require_once __DIR__ . '/../config.php';
requireAdmin();
require_once INCLUDES_DIR . '/Auth.php';

$db = Database::getInstance();
$companyId = getCurrentCompanyId();

if (!$companyId) {
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

$message = null;
$messageType = 'success';

// Get or create company theme
$theme = $db->fetchOne(
    "SELECT * FROM company_themes WHERE company_id = :id",
    ['id' => $companyId]
);

if (!$theme) {
    // Create default theme
    $themeId = generateUUID();
    $db->insert('company_themes', [
        'id' => $themeId,
        'company_id' => $companyId,
        'primary_color' => '#d4af37',
        'secondary_color' => '#0f3460'
    ]);
    $theme = $db->fetchOne(
        "SELECT * FROM company_themes WHERE id = :id",
        ['id' => $themeId]
    );
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $primaryColor = $_POST['primary_color'] ?? '#d4af37';
    $secondaryColor = $_POST['secondary_color'] ?? '#0f3460';
    $headerText = $_POST['header_text'] ?? '';
    $footerText = $_POST['footer_text'] ?? '';
    $customCss = $_POST['custom_css'] ?? '';
    
    // Handle logo upload
    $logoPath = $theme['logo_path'] ?? null;
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = getCompanyUploadsPath($companyId) . '/theme/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'svg'];
        
        if (in_array($ext, $allowed)) {
            $filename = 'logo_' . time() . '.' . $ext;
            $filepath = $uploadDir . $filename;
            
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $filepath)) {
                $logoPath = 'companies/' . $companyId . '/theme/' . $filename;
            }
        }
    }
    
    // Handle favicon upload
    $faviconPath = $theme['favicon_path'] ?? null;
    if (isset($_FILES['favicon']) && $_FILES['favicon']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = getCompanyUploadsPath($companyId) . '/theme/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $ext = strtolower(pathinfo($_FILES['favicon']['name'], PATHINFO_EXTENSION));
        $allowed = ['ico', 'png', 'jpg', 'jpeg'];
        
        if (in_array($ext, $allowed)) {
            $filename = 'favicon_' . time() . '.' . $ext;
            $filepath = $uploadDir . $filename;
            
            if (move_uploaded_file($_FILES['favicon']['tmp_name'], $filepath)) {
                $faviconPath = 'companies/' . $companyId . '/theme/' . $filename;
            }
        }
    }
    
    // Update theme
    $updateData = [
        'primary_color' => $primaryColor,
        'secondary_color' => $secondaryColor,
        'header_text' => $headerText,
        'footer_text' => $footerText,
        'custom_css' => $customCss
    ];
    
    if ($logoPath) {
        $updateData['logo_path'] = $logoPath;
    }
    if ($faviconPath) {
        $updateData['favicon_path'] = $faviconPath;
    }
    
    $db->update('company_themes', $updateData, 'company_id = :id', ['id' => $companyId]);
    
    $message = 'Theme updated successfully!';
    $theme = $db->fetchOne(
        "SELECT * FROM company_themes WHERE company_id = :id",
        ['id' => $companyId]
    );
}

$company = $db->fetchOne("SELECT * FROM companies WHERE id = :id", ['id' => $companyId]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Theme | <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo assetUrl('css/tailwind.css'); ?>">
    <style>
        .glass-card { background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.1); }
        .color-preview { width: 60px; height: 60px; border-radius: 8px; border: 2px solid rgba(255, 255, 255, 0.2); }
    </style>
</head>
<body class="bg-alzayani-dark text-white font-sans min-h-screen">
    <div class="min-h-screen">
        <header class="glass-card border-b border-white/10">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-white">Company Theme</h1>
                        <p class="text-gray-400 text-sm">Customize your company branding</p>
                    </div>
                    <a href="index.php" class="px-4 py-2 rounded-lg bg-white/5 hover:bg-white/10 transition-colors">
                        ← Back
                    </a>
                </div>
            </div>
        </header>
        
        <main class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-xl bg-green-500/10 border border-green-500/30 text-green-400">
                <?php echo sanitize($message); ?>
            </div>
            <?php endif; ?>
            
            <form method="post" enctype="multipart/form-data" class="glass-card rounded-xl p-8 space-y-6">
                <div class="grid md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Primary Color</label>
                        <div class="flex items-center space-x-3">
                            <input type="color" name="primary_color" value="<?php echo sanitize($theme['primary_color'] ?? '#d4af37'); ?>" class="color-preview">
                            <input type="text" name="primary_color" value="<?php echo sanitize($theme['primary_color'] ?? '#d4af37'); ?>" class="flex-1 px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-white">
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Secondary Color</label>
                        <div class="flex items-center space-x-3">
                            <input type="color" name="secondary_color" value="<?php echo sanitize($theme['secondary_color'] ?? '#0f3460'); ?>" class="color-preview">
                            <input type="text" name="secondary_color" value="<?php echo sanitize($theme['secondary_color'] ?? '#0f3460'); ?>" class="flex-1 px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-white">
                        </div>
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">Logo</label>
                    <?php if ($theme['logo_path']): ?>
                    <div class="mb-2">
                        <img src="<?php echo imageUrl($theme['logo_path']); ?>" alt="Logo" class="h-16">
                    </div>
                    <?php endif; ?>
                    <input type="file" name="logo" accept="image/*" class="w-full px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-white">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">Favicon</label>
                    <?php if ($theme['favicon_path']): ?>
                    <div class="mb-2">
                        <img src="<?php echo imageUrl($theme['favicon_path']); ?>" alt="Favicon" class="h-8">
                    </div>
                    <?php endif; ?>
                    <input type="file" name="favicon" accept="image/x-icon,image/png" class="w-full px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-white">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">Header Text</label>
                    <input type="text" name="header_text" value="<?php echo sanitize($theme['header_text'] ?? ''); ?>" class="w-full px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-white" placeholder="Your Company Name">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">Footer Text</label>
                    <input type="text" name="footer_text" value="<?php echo sanitize($theme['footer_text'] ?? ''); ?>" class="w-full px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-white" placeholder="Copyright text">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">Custom CSS</label>
                    <textarea name="custom_css" rows="10" class="w-full px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-white font-mono text-sm" placeholder="/* Your custom CSS */"><?php echo htmlspecialchars($theme['custom_css'] ?? ''); ?></textarea>
                </div>
                
                <button type="submit" class="w-full py-3 rounded-xl bg-gradient-to-r from-amber-500 to-amber-600 text-white font-bold hover:from-amber-600 hover:to-amber-700 transition-colors">
                    Save Theme
                </button>
            </form>
        </main>
    </div>
</body>
</html>
