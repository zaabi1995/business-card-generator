<?php
/**
 * Admin lookup against the public Oman Business Platform establishment search.
 * A person solves the portal CAPTCHA. Cardify does not.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';
require_once INCLUDES_DIR . '/BusinessGovOm.php';
require_once INCLUDES_DIR . '/CompanyDirectory.php';
require_once INCLUDES_DIR . '/CompanyNormalizer.php';

Auth::requireRole('super_admin');
$db = Database::getInstance();

$view = ['step' => 'start', 'error' => '', 'session' => '', 'inputs' => [], 'results' => []];
$notice = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'verify') {
            $view = BusinessGovOm::verify((string) $_POST['session'], (string) $_POST['captchaCode']);
        } elseif ($action === 'search') {
            $posted = $_POST['field'] ?? [];
            if (!is_array($posted)) {
                $posted = [];
            }
            $posted['_event'] = (string) ($_POST['event'] ?? 'search');
            $view = BusinessGovOm::submit((string) $_POST['session'], $posted);
        } elseif ($action === 'save' && CompanyDirectory::ensureReady($db)) {
            $companyId = (int) ($_POST['company_id'] ?? 0);
            $cr = CompanyNormalizer::crNumber((string) ($_POST['cr_number'] ?? ''));
            $row = $companyId > 0 ? $db->fetchOne('SELECT id, name_en FROM om_companies WHERE id = ?', [$companyId]) : null;
            if (!$row || $cr === '') {
                throw new RuntimeException('Pick a Cardify company and a CR number taken from the portal result.');
            }
            $db->query(
                'UPDATE om_companies SET cr_number = ?, registry_status = NULLIF(?, \'\'), source_checked_at = NOW() WHERE id = ? AND (cr_number IS NULL OR cr_number = ?)',
                [$cr, trim((string) ($_POST['registry_status'] ?? '')), $companyId, $cr]
            );
            CompanyDirectory::putFact($db, $companyId, 'cr_number', $cr, BusinessGovOm::SOURCE, 'official_registry', BusinessGovOm::BASE . '/portal/searchEstablishments', 1.0);
            $status = trim((string) ($_POST['registry_status'] ?? ''));
            if ($status !== '') {
                CompanyDirectory::putFact($db, $companyId, 'registry_status', $status, BusinessGovOm::SOURCE, 'official_registry', BusinessGovOm::BASE . '/portal/searchEstablishments', 1.0);
            }
            $notice = 'Saved CR ' . $cr . ' on ' . $row['name_en'] . ' from the Oman Business Platform.';
            $view['step'] = 'start';
        }
    }
    if ($view['step'] === 'start' && $_SERVER['REQUEST_METHOD'] !== 'POST' || (($_POST['action'] ?? '') === 'start')) {
        $view = BusinessGovOm::start();
    }
} catch (Throwable $e) {
    $view['error'] = $e->getMessage();
    $view['step'] = $view['step'] ?? 'start';
}

adminHeader('Oman Business Platform lookup', 'business-gov');
?>
<div class="p-4 max-w-3xl text-sm">
    <h1 class="text-xl font-semibold mb-2">Oman Business Platform</h1>
    <p class="text-gray-600 mb-4">Public establishment search. The portal shows a CAPTCHA first. Type the characters from the image. Cardify does not solve it, and it does not run a bulk crawl. The <code>execution=e1s1</code> link is only a live session key.</p>
    <?php if ($notice): ?><p class="mb-3 text-green-800"><?= htmlspecialchars($notice) ?></p><?php endif; ?>
    <?php if (!empty($view['error'])): ?><p class="mb-3 text-red-700"><?= htmlspecialchars($view['error']) ?></p><?php endif; ?>

    <?php if (($view['step'] ?? '') === 'captcha' && !empty($view['image'])): ?>
        <form method="post" class="space-y-3">
            <input type="hidden" name="action" value="verify">
            <input type="hidden" name="session" value="<?= htmlspecialchars($view['session']) ?>">
            <img alt="Portal CAPTCHA" src="data:<?= htmlspecialchars($view['image_type']) ?>;base64,<?= htmlspecialchars($view['image']) ?>">
            <div>
                <label class="block mb-1">Code from the image</label>
                <input name="captchaCode" class="border rounded px-2 py-1" autocomplete="off" required>
            </div>
            <button class="px-3 py-1 border rounded" type="submit">Continue</button>
        </form>
    <?php elseif (($view['step'] ?? '') === 'search'): ?>
        <form method="post" class="space-y-3 mb-6">
            <input type="hidden" name="action" value="search">
            <input type="hidden" name="session" value="<?= htmlspecialchars($view['session']) ?>">
            <?php foreach ($view['inputs'] as $input): ?>
                <div>
                    <label class="block mb-1"><?= htmlspecialchars($input['name']) ?></label>
                    <input class="border rounded px-2 py-1 w-full" name="field[<?= htmlspecialchars($input['name']) ?>]" value="<?= htmlspecialchars($input['value']) ?>">
                </div>
            <?php endforeach; ?>
            <?php if (!$view['inputs']): ?>
                <p class="text-gray-600">The portal returned no text fields. Start again if this session died.</p>
            <?php endif; ?>
            <button class="px-3 py-1 border rounded" name="event" value="search" type="submit">Search</button>
        </form>
        <?php foreach ($view['results'] as $table): ?>
            <table class="w-full mb-4 border-collapse">
                <?php foreach ($table as $i => $row): ?>
                    <tr class="<?= $i === 0 ? 'font-semibold' : '' ?>">
                        <?php foreach ($row as $cell): ?><td class="border px-2 py-1"><?= htmlspecialchars($cell) ?></td><?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endforeach; ?>
        <?php if (CompanyDirectory::ensureReady($db)): ?>
            <form method="post" class="space-y-2 border-t pt-4">
                <input type="hidden" name="action" value="save">
                <p class="font-semibold">Save a CR onto a Cardify company</p>
                <input class="border rounded px-2 py-1 w-full" name="company_id" placeholder="Cardify company id" required>
                <input class="border rounded px-2 py-1 w-full" name="cr_number" placeholder="CR number copied from the portal result" required>
                <input class="border rounded px-2 py-1 w-full" name="registry_status" placeholder="Status, if the portal showed one">
                <button class="px-3 py-1 border rounded" type="submit">Save official CR</button>
            </form>
        <?php endif; ?>
    <?php else: ?>
        <form method="post"><input type="hidden" name="action" value="start"><button class="px-3 py-1 border rounded" type="submit">Open the portal search</button></form>
    <?php endif; ?>
</div>
<?php adminFooter(); ?>
