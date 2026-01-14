<?php
/**
 * Shareable Design Links
 * Create and manage shareable links for templates
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

// Get templates
$templates = $db->fetchAll(
    "SELECT * FROM templates WHERE company_id = :id ORDER BY name",
    ['id' => $companyId]
);

// Get shareable links
$links = $db->fetchAll(
    "SELECT dl.*, t.name as template_name FROM design_links dl
     LEFT JOIN templates t ON dl.template_id = t.id
     WHERE dl.company_id = :id ORDER BY dl.created_at DESC",
    ['id' => $companyId]
);

// Handle create/update/delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $templateId = $_POST['template_id'] ?? '';
        $password = $_POST['password'] ?? '';
        $expiresAt = $_POST['expires_at'] ?? null;
        $maxAccess = $_POST['max_access'] ?? null;
        
        if (!empty($templateId)) {
            $linkId = generateUUID();
            $shareToken = bin2hex(random_bytes(32));
            
            $db->insert('design_links', [
                'id' => $linkId,
                'company_id' => $companyId,
                'template_id' => $templateId,
                'share_token' => $shareToken,
                'password_hash' => !empty($password) ? password_hash($password, PASSWORD_BCRYPT) : null,
                'expires_at' => $expiresAt ? date('Y-m-d H:i:s', strtotime($expiresAt)) : null,
                'max_access' => $maxAccess ? (int)$maxAccess : null,
                'created_by' => $_SESSION['user_id'] ?? null
            ]);
            
            $message = 'Shareable link created!';
        }
    } elseif ($action === 'delete') {
        $linkId = $_POST['link_id'] ?? '';
        if (!empty($linkId)) {
            $db->delete('design_links', 'id = :id AND company_id = :company_id', [
                'id' => $linkId,
                'company_id' => $companyId
            ]);
            $message = 'Link deleted successfully!';
        }
    }
    
    // Reload links
    $links = $db->fetchAll(
        "SELECT dl.*, t.name as template_name FROM design_links dl
         LEFT JOIN templates t ON dl.template_id = t.id
         WHERE dl.company_id = :id ORDER BY dl.created_at DESC",
        ['id' => $companyId]
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shareable Links | <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo assetUrl('css/tailwind.css'); ?>">
    <style>
        .glass-card { background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.1); }
    </style>
</head>
<body class="bg-alzayani-dark text-white font-sans min-h-screen">
    <div class="min-h-screen">
        <header class="glass-card border-b border-white/10">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-white">Shareable Design Links</h1>
                        <p class="text-gray-400 text-sm">Create links to share your designs</p>
                    </div>
                    <a href="index.php" class="px-4 py-2 rounded-lg bg-white/5 hover:bg-white/10 transition-colors">
                        ← Back
                    </a>
                </div>
            </div>
        </header>
        
        <main class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-xl bg-green-500/10 border border-green-500/30 text-green-400">
                <?php echo sanitize($message); ?>
            </div>
            <?php endif; ?>
            
            <!-- Create Link Form -->
            <div class="glass-card rounded-xl p-6 mb-8">
                <h2 class="text-xl font-bold mb-4">Create Shareable Link</h2>
                <form method="post" class="space-y-4">
                    <input type="hidden" name="action" value="create">
                    <div class="grid md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Template</label>
                            <select name="template_id" required class="w-full px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-white">
                                <option value="">Select Template</option>
                                <?php foreach ($templates as $template): ?>
                                <option value="<?php echo sanitize($template['id']); ?>"><?php echo sanitize($template['name']); ?> (<?php echo sanitize($template['side']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Password (Optional)</label>
                            <input type="password" name="password" class="w-full px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-white" placeholder="Leave empty for public">
                        </div>
                    </div>
                    <div class="grid md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Expires At (Optional)</label>
                            <input type="datetime-local" name="expires_at" class="w-full px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-white">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Max Access (Optional)</label>
                            <input type="number" name="max_access" min="1" class="w-full px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-white" placeholder="Unlimited">
                        </div>
                    </div>
                    <button type="submit" class="px-6 py-2 rounded-lg bg-gradient-to-r from-amber-500 to-amber-600 text-white font-bold hover:from-amber-600 hover:to-amber-700 transition-colors">
                        Create Link
                    </button>
                </form>
            </div>
            
            <!-- Links List -->
            <div class="glass-card rounded-xl p-6">
                <h2 class="text-xl font-bold mb-4">Active Links</h2>
                <div class="space-y-4">
                    <?php if (empty($links)): ?>
                    <p class="text-gray-400 text-center py-8">No shareable links yet. Create one above.</p>
                    <?php else: ?>
                    <?php foreach ($links as $link): ?>
                    <div class="p-4 rounded-lg bg-white/5">
                        <div class="flex items-start justify-between">
                            <div class="flex-1">
                                <div class="font-semibold mb-2"><?php echo sanitize($link['template_name']); ?></div>
                                <div class="text-sm text-gray-400 space-y-1">
                                    <div>Link: <code class="bg-black/30 px-2 py-1 rounded"><?php echo getBaseUrl(); ?>share/<?php echo sanitize($link['share_token']); ?></code></div>
                                    <div>Access Count: <?php echo $link['access_count']; ?>
                                        <?php if ($link['max_access']): ?>
                                        / <?php echo $link['max_access']; ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($link['expires_at']): ?>
                                    <div>Expires: <?php echo date('M d, Y H:i', strtotime($link['expires_at'])); ?></div>
                                    <?php endif; ?>
                                    <?php if ($link['password_hash']): ?>
                                    <div class="text-amber-400">🔒 Password Protected</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="flex items-center space-x-2 ml-4">
                                <button onclick="copyLink('<?php echo getBaseUrl(); ?>share/<?php echo $link['share_token']; ?>')" class="px-4 py-2 rounded-lg bg-blue-500/20 text-blue-400 hover:bg-blue-500/30 transition-colors">
                                    Copy
                                </button>
                                <form method="post" class="inline" onsubmit="return confirm('Delete this link?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="link_id" value="<?php echo sanitize($link['id']); ?>">
                                    <button type="submit" class="px-4 py-2 rounded-lg bg-red-500/20 text-red-400 hover:bg-red-500/30 transition-colors">
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
    
    <script>
        function copyLink(url) {
            navigator.clipboard.writeText(url).then(() => {
                alert('Link copied to clipboard!');
            });
        }
    </script>
</body>
</html>
