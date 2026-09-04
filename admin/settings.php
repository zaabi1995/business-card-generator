<?php
/**
 * Settings: admin users, integrations, account preferences.
 *
 * Commit 1 (sidebar IA consolidation) ships this as a minimal hub.
 * Future commits add the admin-user list, integrations (Odoo/WhatsApp),
 * and account preferences inline.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';

requireAdmin();
$companyId = getCurrentCompanyId();
if (!$companyId) { header('Location: ' . getBasePath() . 'login.php'); exit; }

$basePath = getAdminBasePath();
$isCompanyAdmin = defined('COMPANY_ADMIN_BASE') || !empty($_SESSION['company_slug']);
$ext = $isCompanyAdmin ? '' : '.php';

// Admin users on this tenant (for the simple display).
$db = Database::getInstance();
$admins = $db->fetchAll(
    "SELECT email, name, role, status, last_login_at FROM users WHERE company_id = :cid ORDER BY created_at",
    ['cid' => $companyId]
);

adminHeader('Settings', 'settings');
?>

<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <header class="mb-8">
        <h1 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars(t('settingshub.title')) ?></h1>
        <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars(t('settingshub.lead')) ?></p>
    </header>

    <section class="mb-8 bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <div class="p-5 border-b border-gray-100">
            <h2 class="font-semibold text-gray-900"><?= htmlspecialchars(t('settingshub.admin_users')) ?></h2>
            <p class="text-sm text-gray-500"><?= htmlspecialchars(t('settingshub.admin_users_sub')) ?></p>
        </div>
        <div class="divide-y divide-gray-100">
            <?php foreach ($admins as $u): ?>
            <div class="px-5 py-3 flex items-center justify-between">
                <div>
                    <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($u['name'] ?: $u['email']) ?></div>
                    <div class="text-xs text-gray-500"><?= htmlspecialchars($u['email']) ?>
                        <?php if (!empty($u['last_login_at'])): ?>
                            · last login <?= htmlspecialchars(date('j M Y', dbTs($u['last_login_at']))) ?>
                        <?php else: ?>
                            · never logged in
                        <?php endif; ?>
                    </div>
                </div>
                <span class="inline-flex items-center px-2 py-0.5 text-xs font-semibold rounded-full <?= $u['status'] === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-600' ?>">
                    <?= htmlspecialchars(ucfirst($u['status'])) ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="bg-white rounded-2xl border border-gray-200 p-5">
        <h2 class="font-semibold text-gray-900 mb-2"><?= htmlspecialchars(t('settingshub.integrations')) ?></h2>
        <p class="text-sm text-gray-500"><?= htmlspecialchars(t('settingshub.integrations_sub')) ?></p>
    </section>
</div>

<?php adminFooter(); ?>
