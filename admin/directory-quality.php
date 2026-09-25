<?php
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';
require_once INCLUDES_DIR . '/CompanyDirectory.php';

Auth::requireRole('super_admin');
$db = Database::getInstance();
if (!CompanyDirectory::ensureReady($db)) {
    http_response_code(503);
    echo 'Directory migration 170 is not applied.';
    exit;
}

$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['match_id'] ?? 0);
    $decision = (string) ($_POST['decision'] ?? '');
    if ($id > 0 && in_array($decision, ['accepted', 'rejected'], true)) {
        CompanyDirectory::decideMatch($db, $id, $decision, 'super_admin');
        $notice = 'Match ' . $id . ' marked ' . $decision . '. No rows were merged.';
    }
}

$metrics = CompanyDirectory::metrics($db);
$queue = $db->fetchAll(
    "SELECT m.id, m.score, m.reason, a.name_en a_en, a.slug a_slug, b.name_en b_en, b.slug b_slug
       FROM om_company_matches m
       JOIN om_companies a ON a.id = m.company_a
       JOIN om_companies b ON b.id = m.company_b
      WHERE m.status = 'proposed'
      ORDER BY m.score DESC, m.id ASC
      LIMIT 40"
);
$sources = $db->fetchAll("SELECT name, source_type, usable, reason FROM om_directory_sources ORDER BY rank_order");

adminHeader('Oman directory quality', 'directory-quality');
?>
<div class="p-4 max-w-5xl">
    <?php if ($notice): ?><p class="mb-4 text-sm text-green-800"><?= htmlspecialchars($notice) ?></p><?php endif; ?>
    <h1 class="text-xl font-semibold mb-4">Oman directory quality</h1>
    <table class="w-full text-sm mb-8">
        <?php foreach ($metrics as $k => $v): ?>
            <tr class="border-b border-gray-200"><td class="py-1 pr-4 text-gray-600"><?= htmlspecialchars($k) ?></td><td class="py-1 font-mono"><?= (int) $v ?></td></tr>
        <?php endforeach; ?>
    </table>
    <h2 class="font-semibold mb-2">Sources</h2>
    <ul class="text-sm mb-8 space-y-2">
        <?php foreach ($sources as $s): ?>
            <li><strong><?= htmlspecialchars($s['name']) ?></strong> (<?= $s['usable'] ? 'usable' : 'not usable' ?>): <?= htmlspecialchars($s['reason']) ?></li>
        <?php endforeach; ?>
    </ul>
    <h2 class="font-semibold mb-2">Review queue</h2>
    <p class="text-sm text-gray-600 mb-3">Accepting a pair records a human decision. It does not merge or delete a company.</p>
    <?php if (!$queue): ?><p class="text-sm">No proposed pairs.</p><?php endif; ?>
    <?php foreach ($queue as $m): ?>
        <form method="post" class="border border-gray-200 rounded p-3 mb-2 text-sm flex flex-wrap items-center gap-3">
            <input type="hidden" name="match_id" value="<?= (int) $m['id'] ?>">
            <span><?= htmlspecialchars($m['a_en']) ?></span>
            <span class="text-gray-400">/</span>
            <span><?= htmlspecialchars($m['b_en']) ?></span>
            <span class="text-gray-500"><?= htmlspecialchars($m['reason']) ?> <?= htmlspecialchars($m['score']) ?></span>
            <button name="decision" value="accepted" class="px-2 py-1 border rounded">Same company</button>
            <button name="decision" value="rejected" class="px-2 py-1 border rounded">Keep separate</button>
        </form>
    <?php endforeach; ?>
</div>
<?php adminFooter(); ?>
