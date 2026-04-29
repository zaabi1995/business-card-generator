<?php
/**
 * Super-admin feedback inbox (Cat U action 507).
 *
 * Lists every /contact form submission persisted into contact_messages.
 * Filter by subject, locale, replied-state. POST mark-replied action
 * is CSRF-guarded; logs the admin user id.
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';

Auth::requireRole('super_admin');
$db = Database::getInstance();
$message = null; $messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_replied') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { die('Invalid request'); }
    $id = (int) ($_POST['id'] ?? 0);
    $adminId = $_SESSION['user_id'] ?? null;
    if ($id && $adminId) {
        $db->exec(
            "UPDATE contact_messages SET replied_at = NOW(), replied_by = :by WHERE id = :id AND replied_at IS NULL",
            ['by' => $adminId, 'id' => $id]
        );
        $message = 'Marked as replied.';
    }
}

$filter    = $_GET['filter']  ?? 'open';
$subject   = $_GET['subject'] ?? 'all';
$locale    = $_GET['locale']  ?? 'all';

$where  = ['1=1']; $params = [];
if ($filter === 'open')    { $where[] = 'replied_at IS NULL'; }
if ($filter === 'replied') { $where[] = 'replied_at IS NOT NULL'; }
if (in_array($subject, ['general','support','sales','partner','feedback'], true)) {
    $where[] = 'subject = :s'; $params['s'] = $subject;
}
if (in_array($locale, ['en','ar'], true)) {
    $where[] = 'locale = :l'; $params['l'] = $locale;
}
$sql = "SELECT * FROM contact_messages WHERE " . implode(' AND ', $where)
     . " ORDER BY created_at DESC LIMIT 200";
$rows = [];
try { $rows = $db->fetchAll($sql, $params); } catch (Throwable $_) {}

// Summary counts
$counts = [
    'open'     => (int) ($db->fetchOne("SELECT COUNT(*) c FROM contact_messages WHERE replied_at IS NULL")['c'] ?? 0),
    'replied'  => (int) ($db->fetchOne("SELECT COUNT(*) c FROM contact_messages WHERE replied_at IS NOT NULL")['c'] ?? 0),
    'total'    => (int) ($db->fetchOne("SELECT COUNT(*) c FROM contact_messages")['c'] ?? 0),
];

adminHeader('Customer feedback inbox', 'super');
?>
<div class="max-w-6xl mx-auto px-4 py-6">
    <header class="mb-6 flex items-end justify-between flex-wrap gap-3">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Customer feedback</h1>
            <p class="text-sm text-gray-600 mt-1">Every <code>/contact</code> submission is persisted here so nothing falls through a mailer hiccup.</p>
        </div>
        <form method="GET" class="flex items-center gap-2 flex-wrap">
            <select name="filter" class="px-3 py-2 rounded-lg border border-gray-300 text-sm">
                <option value="open"    <?= $filter==='open'?'selected':'' ?>>Open</option>
                <option value="replied" <?= $filter==='replied'?'selected':'' ?>>Replied</option>
                <option value="all"     <?= $filter==='all'?'selected':'' ?>>All</option>
            </select>
            <select name="subject" class="px-3 py-2 rounded-lg border border-gray-300 text-sm">
                <option value="all">All subjects</option>
                <?php foreach (['general','support','sales','partner','feedback'] as $s): ?>
                    <option value="<?= $s ?>" <?= $subject===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="locale" class="px-3 py-2 rounded-lg border border-gray-300 text-sm">
                <option value="all">All locales</option>
                <option value="en" <?= $locale==='en'?'selected':'' ?>>EN</option>
                <option value="ar" <?= $locale==='ar'?'selected':'' ?>>AR</option>
            </select>
            <button class="px-3 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold">Filter</button>
        </form>
    </header>

    <?php if ($message): ?>
        <div class="mb-4 p-3 rounded-lg bg-green-50 text-green-800 text-sm"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="grid sm:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <div class="text-xs uppercase text-gray-500 mb-1">Open</div>
            <div class="text-2xl font-bold text-amber-700"><?= $counts['open'] ?></div>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <div class="text-xs uppercase text-gray-500 mb-1">Replied</div>
            <div class="text-2xl font-bold text-green-700"><?= $counts['replied'] ?></div>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <div class="text-xs uppercase text-gray-500 mb-1">Total</div>
            <div class="text-2xl font-bold text-gray-900"><?= $counts['total'] ?></div>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <?php if (empty($rows)): ?>
            <div class="p-10 text-center text-gray-500 text-sm">No messages match this filter.</div>
        <?php else: ?>
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-3 text-left">When</th>
                    <th class="px-4 py-3 text-left">From</th>
                    <th class="px-4 py-3 text-left">Subject</th>
                    <th class="px-4 py-3 text-left">Locale</th>
                    <th class="px-4 py-3 text-left">Message</th>
                    <th class="px-4 py-3 text-right">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
            <?php foreach ($rows as $r): ?>
                <tr class="hover:bg-gray-50 <?= $r['replied_at']?'opacity-60':'' ?>">
                    <td class="px-4 py-3 text-gray-700 whitespace-nowrap"><?= htmlspecialchars(I18n::formatDate(strtotime($r['created_at']))) ?></td>
                    <td class="px-4 py-3">
                        <div class="font-medium text-gray-900"><?= htmlspecialchars($r['name']) ?></div>
                        <a href="mailto:<?= htmlspecialchars($r['email']) ?>" class="text-xs text-blue-600 hover:underline"><?= htmlspecialchars($r['email']) ?></a>
                    </td>
                    <td class="px-4 py-3"><span class="inline-block px-2 py-0.5 rounded-full bg-gray-100 text-gray-700 text-xs"><?= htmlspecialchars($r['subject']) ?></span></td>
                    <td class="px-4 py-3 text-xs uppercase"><?= htmlspecialchars($r['locale']) ?></td>
                    <td class="px-4 py-3 text-gray-700 max-w-md truncate" title="<?= htmlspecialchars($r['message']) ?>"><?= htmlspecialchars(mb_substr($r['message'], 0, 120)) ?></td>
                    <td class="px-4 py-3 text-right">
                        <?php if (empty($r['replied_at'])): ?>
                        <form method="POST" class="inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="mark_replied">
                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <button class="px-3 py-1.5 rounded-lg bg-green-600 hover:bg-green-700 text-white text-xs font-semibold">Mark replied</button>
                        </form>
                        <?php else: ?>
                        <span class="text-xs text-green-700">✓ <?= htmlspecialchars(I18n::formatDate(strtotime($r['replied_at']))) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
<?php adminFooter(); ?>
