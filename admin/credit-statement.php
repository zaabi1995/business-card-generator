<?php
/**
 * Company admin credit statement (Cat S action 449).
 *
 * Printable / save-as-PDF statement of a single credit account's
 * ledger activity between two dates. Includes opening balance, every
 * charge / payment / refund / adjustment in the window, and closing
 * balance. Bilingual EN/AR via ?lang=.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Currency.php';
require_once INCLUDES_DIR . '/admin-layout.php';

requireAdmin();
$companyId = getCurrentCompanyId();
if (!$companyId) { header('Location: ' . getBasePath() . 'login.php'); exit; }

// Locale
if (isset($_GET['lang']) && in_array($_GET['lang'], ['en','ar'], true)) {
    I18n::setLocale($_GET['lang']);
}
$locale = I18n::getLocale();
$isAr   = $locale === 'ar';
$altLang = $isAr ? 'en' : 'ar';
$altUrl  = $_SERVER['REQUEST_URI'];
$altUrl  = preg_replace('/([?&])lang=[a-z]+/', '$1lang=' . $altLang, $altUrl);
if (strpos($altUrl, 'lang=') === false) {
    $altUrl .= (strpos($altUrl, '?') === false ? '?' : '&') . 'lang=' . $altLang;
}

$db = Database::getInstance();

// Pick which credit account
$accountId = $_GET['account'] ?? null;
$accounts = $db->fetchAll(
    "SELECT ca.id, ca.status, ca.credit_limit, ca.balance_used,
            ca.payment_terms, ps.name AS print_shop_name
       FROM credit_accounts ca
  LEFT JOIN print_shops ps ON ps.id = ca.print_shop_id
      WHERE ca.company_id = :cid AND ca.deleted_at IS NULL
   ORDER BY ps.name ASC",
    ['cid' => $companyId]
);
if (!$accountId && !empty($accounts)) $accountId = $accounts[0]['id'];

$account = null;
if ($accountId) {
    $account = $db->fetchOne(
        "SELECT ca.*, ps.name AS print_shop_name, ps.email AS print_shop_email,
                ps.phone AS print_shop_phone, ps.address AS print_shop_address,
                ps.city AS print_shop_city, ps.country AS print_shop_country
           FROM credit_accounts ca
      LEFT JOIN print_shops ps ON ps.id = ca.print_shop_id
          WHERE ca.id = :id AND ca.company_id = :cid AND ca.deleted_at IS NULL",
        ['id' => $accountId, 'cid' => $companyId]
    );
}

// Date range (default: current + prior month window)
$from = $_GET['from'] ?? date('Y-m-01', strtotime('first day of -1 month'));
$to   = $_GET['to']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01', strtotime('-1 month'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');

// Opening balance = balance_after of the most recent tx strictly before $from.
// If no prior activity, opening = 0.
$opening = 0.0;
$txns    = [];
if ($account) {
    $last = $db->fetchOne(
        "SELECT balance_after FROM credit_transactions
          WHERE credit_account_id = :aid AND created_at < :from
       ORDER BY created_at DESC, id DESC
          LIMIT 1",
        ['aid' => $account['id'], 'from' => $from . ' 00:00:00']
    );
    $opening = $last ? (float) $last['balance_after'] : 0.0;

    $txns = $db->fetchAll(
        "SELECT ct.*, po.order_number
           FROM credit_transactions ct
      LEFT JOIN print_orders po ON po.id = ct.order_id
          WHERE ct.credit_account_id = :aid
            AND ct.created_at >= :from AND ct.created_at <= :toend
       ORDER BY ct.created_at ASC, ct.id ASC",
        ['aid' => $account['id'], 'from' => $from . ' 00:00:00', 'toend' => $to . ' 23:59:59']
    );
}

// Totals
$totalCharges = 0.0;
$totalPayments = 0.0;
$totalRefunds = 0.0;
$totalAdjustments = 0.0;
foreach ($txns as $t) {
    $amt = (float) $t['amount'];
    if ($t['type'] === 'charge')     $totalCharges    += $amt;
    elseif ($t['type'] === 'payment')$totalPayments   += $amt;
    elseif ($t['type'] === 'refund') $totalRefunds    += $amt;
    else                             $totalAdjustments+= $amt;
}
$closing = $txns ? (float) end($txns)['balance_after'] : $opening;

// Print view toggle
$printView = !empty($_GET['print']);

$pageTitle = t('credit_statement.page_title');
if (!$printView) { adminHeader($pageTitle, 'billing'); }

$basePath = getAdminBasePath();
$isCompanyAdmin = defined('COMPANY_ADMIN_BASE') || !empty($_SESSION['company_slug']);
$ext = $isCompanyAdmin ? '' : '.php';
$selfHref = $basePath . 'credit-statement' . $ext;
?>
<?php
// Tenant brand (mirrors includes/admin-layout.php). Print views bypass the
// shared admin header so we look up logo/favicon directly here. Falls back
// to Cardify default when no theme row exists.
$_tBrandLogo = null; $_tBrandFavicon = null;
try {
    $_cid = $_SESSION['company_id'] ?? null;
    if ($_cid && class_exists('Database') && class_exists('DatabaseAdapter') && DatabaseAdapter::useDatabase()) {
        $_t = Database::getInstance()->fetchOne(
            'SELECT logo_path, favicon_path FROM company_themes WHERE company_id = :id LIMIT 1',
            ['id' => $_cid]
        );
        $_norm = function ($p) { if (!$p) return null; $p = trim((string)$p); if ($p === '' || preg_match('#^https?://#i', $p)) return $p; return $p[0] === '/' ? $p : '/uploads/' . ltrim($p, '/'); };
        $_tBrandLogo    = $_norm($_t['logo_path']    ?? null);
        $_tBrandFavicon = $_norm($_t['favicon_path'] ?? null) ?: $_tBrandLogo;
    }
} catch (Throwable $_) { /* legacy installs */ }
?>
<?php if ($printView): ?>
<!DOCTYPE html>
<html lang="<?= $locale ?>" dir="<?= $isAr ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($pageTitle) ?></title>
<?php if (!empty($_tBrandFavicon)):
    $__favType = preg_match('/\.svg(\?|$)/i', $_tBrandFavicon) ? 'image/svg+xml'
                : (preg_match('/\.png(\?|$)/i', $_tBrandFavicon) ? 'image/png' : 'image/png');
?>
<link rel="icon" href="<?= htmlspecialchars($_tBrandFavicon, ENT_QUOTES) ?>" type="<?= $__favType ?>">
<link rel="apple-touch-icon" href="<?= htmlspecialchars($_tBrandFavicon, ENT_QUOTES) ?>">
<?php else: ?>
<link rel="icon" href="<?= getBasePath() ?>favicon.svg" type="image/svg+xml">
<?php endif; ?>
<script src="https://cdn.tailwindcss.com"></script>
<style>
@import url('https://fonts.bhd.om/css2?family=Inter:wght@400;500;600;700&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap');
* { font-family: <?= $isAr ? "'IBM Plex Sans Arabic', 'Inter', sans-serif" : "'Inter', sans-serif" ?>; }
@media print { .no-print { display: none !important; } body { print-color-adjust: exact; -webkit-print-color-adjust: exact; } }
</style>
</head>
<body class="bg-gray-100 min-h-screen">
<div class="no-print bg-white border-b border-gray-200 px-6 py-3 flex items-center justify-between sticky top-0 z-10">
    <a href="<?= htmlspecialchars($selfHref) ?>?account=<?= urlencode($accountId ?? '') ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>" class="text-sm text-gray-500 hover:text-gray-700">
        <i class="fa-solid fa-arrow-<?= $isAr ? 'right' : 'left' ?> mr-1"></i> <?= htmlspecialchars(t('credit_statement.back_to_view')) ?>
    </a>
    <div class="flex items-center gap-2">
        <a href="<?= htmlspecialchars($altUrl) ?>" class="px-3 py-2 text-sm bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg font-medium transition-colors"><?= $isAr ? 'EN' : 'AR' ?></a>
        <button onclick="window.print()" class="px-4 py-2 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium"><i class="fa-solid fa-print mr-1"></i> <?= htmlspecialchars(t('credit_statement.save_pdf')) ?></button>
    </div>
</div>
<div class="max-w-3xl mx-auto my-6 bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
    <div class="bg-blue-600 px-8 py-6 text-white">
        <div class="flex items-center justify-between mb-2">
            <?php if (!empty($_tBrandLogo)): ?>
            <img src="<?= htmlspecialchars($_tBrandLogo, ENT_QUOTES) ?>" alt="" class="h-8 w-auto brightness-0 invert">
            <?php else: ?>
            <img src="<?= getBasePath() ?>assets/images/logo.svg" alt="Cardify" class="h-8 w-auto brightness-0 invert">
            <?php endif; ?>
            <div class="<?= $isAr ? 'text-left' : 'text-right' ?>">
                <p class="text-blue-200 text-xs uppercase tracking-wide"><?= htmlspecialchars(t('credit_statement.doc_title')) ?></p>
                <p class="font-bold text-lg"><?= htmlspecialchars($account['print_shop_name'] ?? ',') ?></p>
            </div>
        </div>
        <div class="flex items-end justify-between text-sm">
            <div>
                <p class="text-blue-200 text-xs"><?= htmlspecialchars(t('credit_statement.period')) ?></p>
                <p class="font-medium"><?= htmlspecialchars(I18n::formatDate(strtotime($from))) ?> &ndash; <?= htmlspecialchars(I18n::formatDate(strtotime($to))) ?></p>
            </div>
            <?php if ($account): ?>
            <div class="<?= $isAr ? 'text-left' : 'text-right' ?>">
                <p class="text-blue-200 text-xs"><?= htmlspecialchars(t('credit_statement.payment_terms')) ?></p>
                <p class="font-medium uppercase"><?= htmlspecialchars($account['payment_terms']) ?></p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="px-8 py-5 grid grid-cols-2 gap-6 border-b border-gray-100">
        <div>
            <p class="text-xs text-gray-400 uppercase tracking-wide mb-1"><?= htmlspecialchars(t('credit_statement.opening_balance')) ?></p>
            <p class="font-mono text-lg font-semibold text-gray-900"><?= number_format($opening, 3) ?> OMR</p>
        </div>
        <div class="<?= $isAr ? 'text-left' : 'text-right' ?>">
            <p class="text-xs text-gray-400 uppercase tracking-wide mb-1"><?= htmlspecialchars(t('credit_statement.closing_balance')) ?></p>
            <p class="font-mono text-lg font-bold text-gray-900"><?= number_format($closing, 3) ?> OMR</p>
        </div>
    </div>

    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-xs uppercase text-gray-500">
            <tr>
                <th class="px-4 py-3 text-<?= $isAr ? 'right' : 'left' ?>"><?= htmlspecialchars(t('credit_statement.col_date')) ?></th>
                <th class="px-4 py-3 text-<?= $isAr ? 'right' : 'left' ?>"><?= htmlspecialchars(t('credit_statement.col_desc')) ?></th>
                <th class="px-4 py-3 text-<?= $isAr ? 'left' : 'right' ?>"><?= htmlspecialchars(t('credit_statement.col_charge')) ?></th>
                <th class="px-4 py-3 text-<?= $isAr ? 'left' : 'right' ?>"><?= htmlspecialchars(t('credit_statement.col_payment')) ?></th>
                <th class="px-4 py-3 text-<?= $isAr ? 'left' : 'right' ?>"><?= htmlspecialchars(t('credit_statement.col_balance')) ?></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            <?php if (empty($txns)): ?>
            <tr><td colspan="5" class="px-4 py-8 text-center text-gray-500"><?= htmlspecialchars(t('credit_statement.empty')) ?></td></tr>
            <?php endif; ?>
            <?php foreach ($txns as $t):
                $desc = $t['order_number'] ? 'Order ' . $t['order_number'] : ucfirst($t['type']);
                if ($t['notes']) $desc .= ' · ' . mb_substr($t['notes'], 0, 80);
                $isCharge = $t['type'] === 'charge';
                $isPay    = in_array($t['type'], ['payment', 'refund'], true);
            ?>
                <tr>
                    <td class="px-4 py-2.5 text-gray-700"><?= htmlspecialchars(I18n::formatDate(strtotime($t['created_at']))) ?></td>
                    <td class="px-4 py-2.5 text-gray-900 text-xs"><?= htmlspecialchars($desc) ?></td>
                    <td class="px-4 py-2.5 text-<?= $isAr ? 'left' : 'right' ?> font-mono <?= $isCharge ? 'text-red-600' : 'text-gray-300' ?>"><?= $isCharge ? number_format((float) $t['amount'], 3) : ',' ?></td>
                    <td class="px-4 py-2.5 text-<?= $isAr ? 'left' : 'right' ?> font-mono <?= $isPay ? 'text-green-700' : 'text-gray-300' ?>"><?= $isPay ? number_format((float) $t['amount'], 3) : ',' ?></td>
                    <td class="px-4 py-2.5 text-<?= $isAr ? 'left' : 'right' ?> font-mono text-gray-900"><?= number_format((float) $t['balance_after'], 3) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="px-8 py-4 grid grid-cols-3 gap-4 text-sm border-t border-gray-100 bg-gray-50">
        <div>
            <p class="text-xs text-gray-400 uppercase mb-0.5"><?= htmlspecialchars(t('credit_statement.total_charges')) ?></p>
            <p class="font-mono text-red-700"><?= number_format($totalCharges, 3) ?></p>
        </div>
        <div>
            <p class="text-xs text-gray-400 uppercase mb-0.5"><?= htmlspecialchars(t('credit_statement.total_payments')) ?></p>
            <p class="font-mono text-green-700"><?= number_format($totalPayments + $totalRefunds, 3) ?></p>
        </div>
        <div>
            <p class="text-xs text-gray-400 uppercase mb-0.5"><?= htmlspecialchars(t('credit_statement.total_adjustments')) ?></p>
            <p class="font-mono text-gray-900"><?= number_format($totalAdjustments, 3) ?></p>
        </div>
    </div>

    <div class="px-8 py-5 text-center text-xs text-gray-500 border-t border-gray-100">
        <p><?= htmlspecialchars(t('credit_statement.footer_note')) ?></p>
        <p class="mt-2">
            <strong><?= $isAr ? 'مجموعة BHD' : 'BHD Group' ?></strong>
            · <?= $isAr ? 'بن حيدر درويش ش م م' : 'Bin Haider Darwish LLC' ?>
            · <?= $isAr ? 'س.ت 1334733' : 'C.R. 1334733' ?>
        </p>
    </div>
</div>
</body></html>
<?php return; endif; ?>

<!-- Live admin view -->
<div class="max-w-6xl mx-auto px-4 py-6">
    <header class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars($pageTitle) ?></h1>
        <p class="text-gray-600 mt-1 text-sm"><?= htmlspecialchars(t('credit_statement.page_sub')) ?></p>
    </header>

    <?php if (empty($accounts)): ?>
        <div class="bg-white rounded-2xl border border-gray-200 p-12 text-center text-gray-500">
            <i class="fa-regular fa-building-columns text-4xl text-gray-300 mb-3"></i>
            <p><?= htmlspecialchars(t('credit_statement.no_accounts')) ?></p>
        </div>
    <?php else: ?>
        <form method="GET" class="bg-white rounded-2xl border border-gray-200 p-4 mb-6 flex items-end gap-3 flex-wrap">
            <?php if ($isCompanyAdmin): ?><input type="hidden" name="page" value="credit-statement"><?php endif; ?>
            <div class="flex-1 min-w-[200px]">
                <label class="text-xs uppercase text-gray-500 block mb-1"><?= htmlspecialchars(t('credit_statement.account')) ?></label>
                <select name="account" class="w-full px-3 py-2 rounded-lg border border-gray-300 text-sm">
                    <?php foreach ($accounts as $a): ?>
                        <option value="<?= htmlspecialchars($a['id']) ?>" <?= $a['id'] === $accountId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($a['print_shop_name']) ?> · <?= htmlspecialchars($a['status']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-xs uppercase text-gray-500 block mb-1"><?= htmlspecialchars(t('credit_statement.from')) ?></label>
                <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="px-3 py-2 rounded-lg border border-gray-300 text-sm">
            </div>
            <div>
                <label class="text-xs uppercase text-gray-500 block mb-1"><?= htmlspecialchars(t('credit_statement.to')) ?></label>
                <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="px-3 py-2 rounded-lg border border-gray-300 text-sm">
            </div>
            <button type="submit" class="px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold"><?= htmlspecialchars(t('credit_statement.apply')) ?></button>
            <a href="<?= htmlspecialchars($selfHref) ?>?account=<?= urlencode($accountId ?? '') ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&print=1" target="_blank" class="px-4 py-2 rounded-lg bg-gray-900 hover:bg-gray-800 text-white text-sm font-semibold inline-flex items-center gap-1.5">
                <i class="fa-solid fa-file-pdf"></i> <?= htmlspecialchars(t('credit_statement.download_btn')) ?>
            </a>
        </form>

        <?php if ($account): ?>
        <div class="grid md:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-2xl border border-gray-200 p-5">
                <div class="text-xs uppercase text-gray-500 mb-1"><?= htmlspecialchars(t('credit_statement.credit_limit')) ?></div>
                <div class="text-xl font-bold text-gray-900"><?= number_format((float) $account['credit_limit'], 3) ?> <span class="text-sm text-gray-500">OMR</span></div>
            </div>
            <div class="bg-white rounded-2xl border border-gray-200 p-5">
                <div class="text-xs uppercase text-gray-500 mb-1"><?= htmlspecialchars(t('credit_statement.balance_used')) ?></div>
                <div class="text-xl font-bold text-gray-900"><?= number_format((float) $account['balance_used'], 3) ?> <span class="text-sm text-gray-500">OMR</span></div>
            </div>
            <div class="bg-white rounded-2xl border border-gray-200 p-5">
                <div class="text-xs uppercase text-gray-500 mb-1"><?= htmlspecialchars(t('credit_statement.total_charges')) ?></div>
                <div class="text-xl font-bold text-red-700"><?= number_format($totalCharges, 3) ?></div>
            </div>
            <div class="bg-white rounded-2xl border border-gray-200 p-5">
                <div class="text-xs uppercase text-gray-500 mb-1"><?= htmlspecialchars(t('credit_statement.total_payments')) ?></div>
                <div class="text-xl font-bold text-green-700"><?= number_format($totalPayments + $totalRefunds, 3) ?></div>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
            <?php if (empty($txns)): ?>
                <div class="p-12 text-center text-gray-500">
                    <i class="fa-regular fa-file-lines text-4xl text-gray-300 mb-3"></i>
                    <p><?= htmlspecialchars(t('credit_statement.empty')) ?></p>
                </div>
            <?php else: ?>
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                    <tr>
                        <th class="px-4 py-3 text-left"><?= htmlspecialchars(t('credit_statement.col_date')) ?></th>
                        <th class="px-4 py-3 text-left"><?= htmlspecialchars(t('credit_statement.col_desc')) ?></th>
                        <th class="px-4 py-3 text-right"><?= htmlspecialchars(t('credit_statement.col_charge')) ?></th>
                        <th class="px-4 py-3 text-right"><?= htmlspecialchars(t('credit_statement.col_payment')) ?></th>
                        <th class="px-4 py-3 text-right"><?= htmlspecialchars(t('credit_statement.col_balance')) ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($txns as $t):
                        $desc = $t['order_number'] ? 'Order ' . $t['order_number'] : ucfirst($t['type']);
                        if ($t['notes']) $desc .= ' · ' . mb_substr($t['notes'], 0, 80);
                        $isCharge = $t['type'] === 'charge';
                        $isPay    = in_array($t['type'], ['payment', 'refund'], true);
                    ?>
                        <tr>
                            <td class="px-4 py-2.5 text-gray-700"><?= htmlspecialchars(I18n::formatDate(strtotime($t['created_at']))) ?></td>
                            <td class="px-4 py-2.5 text-gray-900"><?= htmlspecialchars($desc) ?></td>
                            <td class="px-4 py-2.5 text-right font-mono <?= $isCharge ? 'text-red-600' : 'text-gray-300' ?>"><?= $isCharge ? number_format((float) $t['amount'], 3) : ',' ?></td>
                            <td class="px-4 py-2.5 text-right font-mono <?= $isPay ? 'text-green-700' : 'text-gray-300' ?>"><?= $isPay ? number_format((float) $t['amount'], 3) : ',' ?></td>
                            <td class="px-4 py-2.5 text-right font-mono text-gray-900"><?= number_format((float) $t['balance_after'], 3) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php adminFooter(); ?>
