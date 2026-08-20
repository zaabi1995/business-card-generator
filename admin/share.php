<?php
/**
 * Shareable Design Links - Cardify
 */
require_once __DIR__ . '/../config.php';
requireAdmin();
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';

$db = Database::getInstance();
$companyId = getCurrentCompanyId();

if (!$companyId) {
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

$message = null;
$messageType = 'success';

$templates = $db->fetchAll("SELECT * FROM templates WHERE company_id = :id ORDER BY name", ['id' => $companyId]);

$links = $db->fetchAll(
    "SELECT dl.*, t.name as template_name FROM design_links dl
     LEFT JOIN templates t ON dl.template_id = t.id
     WHERE dl.company_id = :id ORDER BY dl.created_at DESC",
    ['id' => $companyId]
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid request. Please try again.';
        $messageType = 'error';
    }
    $action = $_POST['action'] ?? '';

    if ($messageType === 'error') {
        // CSRF failed, skip processing
    } elseif ($action === 'create') {
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
                // Admin-typed date, so parse it in their local zone with
                // strtotime, then store UTC with dbNow. dbTs is for reading a
                // value back out of the database, not for form input.
                'expires_at' => $expiresAt ? dbNow(strtotime($expiresAt)) : null,
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
    
    $links = $db->fetchAll(
        "SELECT dl.*, t.name as template_name FROM design_links dl
         LEFT JOIN templates t ON dl.template_id = t.id
         WHERE dl.company_id = :id ORDER BY dl.created_at DESC",
        ['id' => $companyId]
    );
}

adminHeader('Share Links', 'share');
?>

<div x-data="{ showModal: false }">
    <!-- Page Header Actions -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <p class="text-gray-600"><?php echo count($links); ?> shareable links</p>
        </div>
        <button @click="showModal = true" 
                class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors text-sm font-medium flex items-center gap-2">
            <i class="fa-solid fa-plus"></i>
            <span>Create Link</span>
        </button>
    </div>

    <!-- Alert Message -->
    <?php if ($message): ?>
    <div class="mb-6 p-4 rounded-xl flex items-center gap-3 bg-green-50 border border-green-200 text-green-700">
        <i class="fa-solid fa-circle-check"></i>
        <?php echo sanitize($message); ?>
    </div>
    <?php endif; ?>

    <!-- Links Table -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-6 py-4 text-gray-600 font-semibold text-sm">Template</th>
                        <th class="text-left px-6 py-4 text-gray-600 font-semibold text-sm">Link</th>
                        <th class="text-left px-6 py-4 text-gray-600 font-semibold text-sm">Protection</th>
                        <th class="text-left px-6 py-4 text-gray-600 font-semibold text-sm">Usage</th>
                        <th class="text-right px-6 py-4 text-gray-600 font-semibold text-sm">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($links as $link): ?>
                    <?php 
                    $shareUrl = rtrim(BASE_URL ?? '', '/') . '/share/' . $link['share_token'];
                    $isExpired = $link['expires_at'] && dbTs($link['expires_at']) < time();
                    ?>
                    <tr class="hover:bg-gray-50 transition-colors <?php echo $isExpired ? 'opacity-50' : ''; ?>">
                        <td class="px-6 py-4">
                            <p class="font-medium text-gray-900"><?php echo sanitize($link['template_name'] ?? 'Unknown'); ?></p>
                            <p class="text-gray-500 text-sm">Created <?php echo date('M j, Y', dbTs($link['created_at'])); ?></p>
                        </td>
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-2">
                                <input type="text" value="<?php echo $shareUrl; ?>" readonly 
                                       class="w-64 px-3 py-1.5 bg-gray-50 border border-gray-200 rounded text-sm text-gray-600 font-mono truncate"
                                       onclick="this.select()">
                                <button onclick="navigator.clipboard.writeText('<?php echo $shareUrl; ?>'); this.innerHTML='<i class=\'fa-solid fa-check\'></i>'; setTimeout(() => this.innerHTML='<i class=\'fa-solid fa-copy\'></i>', 2000)"
                                        class="p-2 text-gray-500 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors">
                                    <i class="fa-solid fa-copy"></i>
                                </button>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-2">
                                <?php if ($link['password_hash']): ?>
                                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-amber-100 text-amber-700">
                                    <i class="fa-solid fa-lock"></i> Password
                                </span>
                                <?php endif; ?>
                                <?php if ($link['expires_at']): ?>
                                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium <?php echo $isExpired ? 'bg-red-100 text-red-700' : 'bg-blue-100 text-blue-700'; ?>">
                                    <i class="fa-solid fa-clock"></i> <?php echo $isExpired ? 'Expired' : date('M j', dbTs($link['expires_at'])); ?>
                                </span>
                                <?php endif; ?>
                                <?php if (!$link['password_hash'] && !$link['expires_at']): ?>
                                <span class="text-gray-400 text-sm">Public</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <p class="text-gray-900 font-medium">
                                <?php echo $link['access_count'] ?? 0; ?>
                                <?php if ($link['max_access']): ?>
                                <span class="text-gray-500 font-normal">/ <?php echo $link['max_access']; ?></span>
                                <?php endif; ?>
                            </p>
                            <p class="text-gray-500 text-sm">views</p>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <a href="<?php echo $shareUrl; ?>" target="_blank" 
                                   class="p-2 text-gray-500 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors">
                                    <i class="fa-solid fa-external-link"></i>
                                </a>
                                <form method="post" class="inline" onsubmit="return confirm('Delete this link?')">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="link_id" value="<?php echo $link['id']; ?>">
                                    <button type="submit" class="p-2 text-gray-500 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($links)): ?>
                    <tr>
                        <td colspan="5" class="px-6 py-16 text-center">
                            <div class="text-gray-400">
                                <i class="fa-solid fa-share-nodes text-4xl mb-4 opacity-50"></i>
                                <p class="text-gray-600 font-medium"><?= htmlspecialchars(t('emptystates.no_share_links_h')) ?></p>
                                <p class="text-sm mt-1"><?= htmlspecialchars(t('emptystates.no_share_links_sub')) ?></p>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Create Modal -->
    <div x-show="showModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" @keydown.escape.window="showModal = false">
        <div class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" @click="showModal = false"></div>
        <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-md">
            <div class="p-6 border-b border-gray-100">
                <h3 class="text-xl font-bold text-gray-900">Create Shareable Link</h3>
            </div>
            
            <form method="post" class="p-6">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="create">
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Template <span class="text-red-500">*</span></label>
                        <select name="template_id" required 
                                class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                            <option value="">Select a template</option>
                            <?php foreach ($templates as $template): ?>
                            <option value="<?php echo $template['id']; ?>"><?php echo sanitize($template['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Password Protection</label>
                        <input type="password" name="password" 
                               class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                               placeholder="Leave empty for public access">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Expiration Date</label>
                        <input type="datetime-local" name="expires_at" 
                               class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Max Views</label>
                        <input type="number" name="max_access" min="1" 
                               class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                               placeholder="Unlimited">
                    </div>
                </div>
                
                <div class="flex items-center justify-end gap-3 mt-6 pt-6 border-t border-gray-100">
                    <button type="button" @click="showModal = false" class="px-4 py-2 text-gray-600 hover:text-gray-900 font-medium transition-colors">
                        Cancel
                    </button>
                    <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors">
                        Create Link
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php adminFooter(); ?>
