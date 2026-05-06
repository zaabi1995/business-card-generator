<?php
/**
 * Internal-provider mode: paginated list of every Cardify company,
 * with employee count and last-order date. Each row links to
 * client.php for the per-company employee picker.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/PrintShopAuth.php';

$ctx = PrintShopAuth::requireInternalProvider();
$shop = $ctx['shop'];
$shopId = (int) $shop['id'];

$db = Database::getInstance();
$pdo = $db->getConnection();

$q = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = "WHERE 1=1";
$params = [];
if ($q !== '') {
    $where .= " AND (c.name LIKE :q_name OR c.slug LIKE :q_slug)";
    $params['q_name'] = '%' . $q . '%';
    $params['q_slug'] = '%' . $q . '%';
}

$stmt = $pdo->prepare(
    "SELECT
        c.id, c.name, c.slug, c.country, c.created_at,
        ct.logo_path,
        (SELECT COUNT(*) FROM employees e WHERE e.company_id = c.id) AS employee_count,
        (SELECT MAX(po.created_at) FROM print_orders po
            WHERE po.company_id = c.id AND po.print_shop_id = :shop_id) AS last_order_at,
        (SELECT COUNT(*) FROM print_orders po
            WHERE po.company_id = c.id AND po.print_shop_id = :shop_id_count) AS shop_order_count
     FROM companies c
     LEFT JOIN company_themes ct ON ct.company_id = c.id
     $where
     ORDER BY c.name ASC
     LIMIT :lim OFFSET :off"
);
foreach ($params as $k => $v) $stmt->bindValue(':' . $k, $v);
$stmt->bindValue(':shop_id',       $shopId, PDO::PARAM_INT);
$stmt->bindValue(':shop_id_count', $shopId, PDO::PARAM_INT);
$stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Total for pagination
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM companies c $where");
foreach ($params as $k => $v) $countStmt->bindValue(':' . $k, $v);
$countStmt->execute();
$total = (int) $countStmt->fetchColumn();
$pageCount = max(1, (int) ceil($total / $perPage));

require_once INCLUDES_DIR . '/printshop-layout.php';
printshopHeader(t('printshopinternal.clients_title', ['shop' => $shop['name']]), 'clients');
?>
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars(t('printshopinternal.clients_heading')) ?></h1>
            <p class="text-gray-600 mt-1 max-w-2xl"><?= htmlspecialchars(t('printshopinternal.clients_subheading')) ?></p>
        </div>
        <form method="GET" class="flex items-center gap-2">
            <input type="text" name="q" value="<?= sanitize($q) ?>"
                   placeholder="<?= htmlspecialchars(t('printshopinternal.search_placeholder')) ?>"
                   class="px-3 py-2 border border-gray-300 rounded-lg w-64">
            <button class="px-4 py-2 bg-blue-600 text-white rounded-lg font-medium"><i class="fa-solid fa-magnifying-glass"></i></button>
        </form>
    </div>

    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
        <?php if (empty($rows)): ?>
        <div class="p-12 text-center">
            <i class="fa-solid fa-building text-4xl text-gray-300 mb-3"></i>
            <p class="text-gray-700 font-medium"><?= htmlspecialchars(t('printshopinternal.no_clients')) ?></p>
        </div>
        <?php else: ?>
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-600 text-xs uppercase">
                <tr>
                    <th class="px-4 py-3 text-start"><?= htmlspecialchars(t('printshopinternal.col_company')) ?></th>
                    <th class="px-4 py-3 text-start"><?= htmlspecialchars(t('printshopinternal.col_employees')) ?></th>
                    <th class="px-4 py-3 text-start"><?= htmlspecialchars(t('printshopinternal.col_orders_with_us')) ?></th>
                    <th class="px-4 py-3 text-start"><?= htmlspecialchars(t('printshopinternal.col_last_order')) ?></th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($rows as $r):
                    $logo = $r['logo_path'] ? getBasePath() . ltrim($r['logo_path'], '/') : '';
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <?php if ($logo): ?>
                            <img src="<?= sanitize($logo) ?>" alt="" class="w-8 h-8 rounded object-contain bg-gray-50 border border-gray-100">
                            <?php else: ?>
                            <div class="w-8 h-8 rounded bg-gray-100 flex items-center justify-center text-xs font-bold text-gray-500"><?= strtoupper(substr($r['name'] ?? '?', 0, 2)) ?></div>
                            <?php endif; ?>
                            <div>
                                <p class="font-medium text-gray-900"><?= sanitize($r['name']) ?></p>
                                <p class="text-xs text-gray-400">/<?= sanitize($r['slug']) ?></p>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-gray-700"><?= (int)$r['employee_count'] ?></td>
                    <td class="px-4 py-3 text-gray-700"><?= (int)$r['shop_order_count'] ?></td>
                    <td class="px-4 py-3 text-xs text-gray-500"><?= $r['last_order_at'] ? sanitize(date('Y-m-d', strtotime($r['last_order_at']))) : '<span class="text-gray-400">,</span>' ?></td>
                    <td class="px-4 py-3 text-end">
                        <a href="client.php?company=<?= urlencode($r['id']) ?>" class="inline-flex items-center gap-1 px-3 py-1.5 bg-blue-50 hover:bg-blue-100 text-blue-700 rounded-md text-sm font-medium">
                            <i class="fa-solid fa-arrow-right"></i> <?= htmlspecialchars(t('printshopinternal.open_btn')) ?>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php if ($pageCount > 1): ?>
        <div class="px-4 py-3 border-t border-gray-100 flex items-center justify-between text-sm text-gray-600">
            <span><?= htmlspecialchars(t('printshopinternal.pagination', ['page' => $page, 'total' => $pageCount, 'count' => $total])) ?></span>
            <div class="flex items-center gap-2">
                <?php if ($page > 1): ?><a href="?q=<?= urlencode($q) ?>&page=<?= $page - 1 ?>" class="px-3 py-1 border rounded">,</a><?php endif; ?>
                <?php if ($page < $pageCount): ?><a href="?q=<?= urlencode($q) ?>&page=<?= $page + 1 ?>" class="px-3 py-1 border rounded">,</a><?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
<?php printshopFooter(); ?>
