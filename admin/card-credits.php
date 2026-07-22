<?php
/**
 * Company admin card-credit top-up (Cat S action 451).
 *
 * Shows current card_credits balance, four bundle options with a
 * volume discount, and the last 20 card_credit_ledger entries. POST
 * with a bundle choice creates a Paymob card_order intent and
 * redirects to checkout; on success Payment::confirmCardOrder() adds
 * the credits and inserts a ledger row (done from Payment.php today;
 * extended here to also log to the new card_credit_ledger table so
 * the statement stays in sync).
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/CardCredits.php';
require_once INCLUDES_DIR . '/admin-layout.php';

requireAdmin();
$companyId = getCurrentCompanyId();
if (!$companyId) { header('Location: ' . getBasePath() . 'login.php'); exit; }

$db = Database::getInstance();
$message = null;
$messageType = 'success';

// Bundle catalogue. Per-card price discounts with volume; all OMR.
// BASE_PRICE is the Starter rate; savings per bundle = (BASE - tier) * count.
// Keep this ladder in sync with CardCredits::priceFor() on the server side
// so custom counts land on the same tier the UI advertises.
$BUNDLES = [
    ['count' => 10,  'price_per' => 0.500, 'label_key' => 'bundle_starter',    'recommended' => false],
    ['count' => 50,  'price_per' => 0.400, 'label_key' => 'bundle_team',       'recommended' => true],
    ['count' => 100, 'price_per' => 0.350, 'label_key' => 'bundle_department', 'recommended' => false],
    ['count' => 500, 'price_per' => 0.280, 'label_key' => 'bundle_enterprise', 'recommended' => false],
];
define('CC_BASE_PRICE', 0.500);

/** Pick the best per-card price for an arbitrary count based on the ladder. */
function ccTierPrice(int $count, array $bundles): float {
    $price = $bundles[0]['price_per'];
    foreach ($bundles as $b) {
        if ($count >= $b['count']) $price = $b['price_per'];
    }
    return $price;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'buy') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { die('Invalid request'); }
    require_once INCLUDES_DIR . '/Payment.php';
    $chosenRaw = $_POST['bundle'] ?? '';
    $count = 0; $price = 0.0;

    if ($chosenRaw === 'custom') {
        $count = (int) ($_POST['custom_count'] ?? 0);
        // Custom quantity is clamped to [10, 5000] and uses the same
        // volume-tier ladder so bulk orders still get the discount.
        $count = max(10, min(5000, $count));
        $price = ccTierPrice($count, $BUNDLES);
    } elseif (ctype_digit((string) $chosenRaw) && isset($BUNDLES[(int) $chosenRaw])) {
        $b     = $BUNDLES[(int) $chosenRaw];
        $count = (int) $b['count'];
        $price = (float) $b['price_per'];
    }

    if ($count <= 0) {
        $message = t('card_credits.err_bundle');
        $messageType = 'error';
    } else {
        $cur = defined('APP_CURRENCY') ? APP_CURRENCY : 'OMR';
        $result = Payment::createCardOrderIntent($companyId, $count, $price, $cur);
        if (!empty($result['checkout_url'])) {
            header('Location: ' . $result['checkout_url']);
            exit;
        }
        $message = $result['error'] ?? t('card_credits.err_generic');
        $messageType = 'error';
    }
}

$balance = CardCredits::getBalance($companyId);

// Recent ledger (table 091). Fallback to empty array if table absent.
$ledger = [];
try {
    $ledger = $db->fetchAll(
        "SELECT created_at, delta, balance_after, reason, notes
           FROM card_credit_ledger WHERE company_id = :c
       ORDER BY created_at DESC LIMIT 20",
        ['c' => $companyId]
    );
} catch (Throwable $_) { /* table may not exist yet on legacy installs */ }

adminHeader(t('card_credits.page_title'), 'billing');
?>
<div class="max-w-6xl mx-auto px-4 py-6">
    <header class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars(t('card_credits.page_title')) ?></h1>
        <p class="text-gray-600 mt-1 text-sm"><?= htmlspecialchars(t('card_credits.page_sub')) ?></p>
    </header>

    <?php if ($message): ?>
    <div class="mb-6 p-4 rounded-lg text-sm <?= $messageType === 'success' ? 'bg-green-50 text-green-800 border border-green-200' : 'bg-red-50 text-red-800 border border-red-200' ?>">
        <?= htmlspecialchars($message) ?>
    </div>
    <?php endif; ?>

    <!-- Balance card -->
    <section class="bg-gradient-to-br from-blue-600 to-indigo-700 rounded-2xl p-8 text-white mb-8 flex items-center justify-between flex-wrap gap-4">
        <div>
            <p class="text-blue-200 text-xs uppercase tracking-wide mb-2"><?= htmlspecialchars(t('card_credits.balance_label')) ?></p>
            <p class="text-5xl font-extrabold"><?= number_format($balance) ?></p>
            <p class="text-blue-100 text-sm mt-2"><?= htmlspecialchars(t('card_credits.balance_hint')) ?></p>
        </div>
        <div class="text-right rtl:text-left">
            <i class="fa-solid fa-credit-card text-6xl opacity-20"></i>
        </div>
    </section>

    <!-- Bundle grid -->
    <form method="POST" class="mb-8">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="buy">
        <h2 class="text-lg font-bold text-gray-900 mb-4"><?= htmlspecialchars(t('card_credits.pick_bundle')) ?></h2>
        <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-4">
            <?php foreach ($BUNDLES as $i => $b):
                $total    = $b['count'] * $b['price_per'];
                $savings  = ($b['count'] * CC_BASE_PRICE) - $total;
                $savePct  = CC_BASE_PRICE > 0 ? (int) round(((CC_BASE_PRICE - $b['price_per']) / CC_BASE_PRICE) * 100) : 0;
            ?>
            <label class="relative bg-white rounded-2xl border-2 border-gray-200 hover:border-blue-400 p-6 cursor-pointer transition-all <?= $b['recommended'] ? 'ring-2 ring-blue-500 border-blue-500' : '' ?>">
                <?php if ($b['recommended']): ?>
                <span class="absolute top-2 right-2 rtl:left-2 rtl:right-auto bg-blue-600 text-white text-xs px-2 py-0.5 rounded-full"><?= htmlspecialchars(t('card_credits.most_popular')) ?></span>
                <?php elseif ($savePct > 0): ?>
                <span class="absolute top-2 right-2 rtl:left-2 rtl:right-auto bg-green-50 text-green-700 border border-green-200 text-xs px-2 py-0.5 rounded-full"><?= htmlspecialchars(t('card_credits.save_pct', ['pct' => $savePct])) ?></span>
                <?php endif; ?>
                <input type="radio" name="bundle" value="<?= $i ?>" required class="sr-only peer"<?= $b['recommended'] ? ' checked' : '' ?>>
                <div class="peer-checked:border-blue-500">
                    <p class="text-xs uppercase tracking-wide text-gray-500 mb-1"><?= htmlspecialchars(t('card_credits.' . $b['label_key'])) ?></p>
                    <p class="text-3xl font-extrabold text-gray-900"><?= number_format($b['count']) ?></p>
                    <p class="text-sm text-gray-500 mb-4"><?= htmlspecialchars(t('card_credits.cards_word')) ?></p>
                    <div class="border-t border-gray-100 pt-3">
                        <p class="text-xs text-gray-500"><?= number_format($b['price_per'], 3) ?> <?= htmlspecialchars(t('card_credits.per_card')) ?></p>
                        <p class="text-xl font-bold text-gray-900 mt-1"><?= number_format($total, 3) ?> <span class="text-sm font-medium text-gray-500">OMR</span></p>
                        <?php if ($savings > 0): ?>
                        <p class="text-xs text-green-700 mt-1 font-medium"><?= htmlspecialchars(t('card_credits.save_omr', ['amt' => number_format($savings, 3)])) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="peer-checked:ring-4 peer-checked:ring-blue-200 rounded-xl absolute inset-0 pointer-events-none"></div>
            </label>
            <?php endforeach; ?>
        </div>

        <!-- Custom amount (uses the same volume-tier ladder) -->
        <div x-data="ccCustom()" class="mt-6 bg-white rounded-2xl border-2 border-gray-200 p-5">
            <label class="flex items-center gap-3 cursor-pointer">
                <input type="radio" name="bundle" value="custom" class="h-4 w-4 text-blue-600" @change="active = true">
                <span class="font-semibold text-gray-900"><?= htmlspecialchars(t('card_credits.custom_h')) ?></span>
                <span class="text-xs text-gray-500"><?= htmlspecialchars(t('card_credits.custom_hint')) ?></span>
            </label>
            <div class="mt-4 grid sm:grid-cols-3 gap-4 items-end" :class="active ? '' : 'opacity-60 pointer-events-none'">
                <div>
                    <label class="block text-xs text-gray-500 mb-1 uppercase"><?= htmlspecialchars(t('card_credits.custom_count')) ?></label>
                    <input type="number" name="custom_count" min="10" max="5000" step="10"
                           x-model.number="count"
                           @input="active = true"
                           class="w-full px-3 py-2 rounded-lg border border-gray-300 font-mono">
                </div>
                <div>
                    <p class="text-xs text-gray-500 uppercase mb-1"><?= htmlspecialchars(t('card_credits.per_card')) ?></p>
                    <p class="font-mono text-lg font-semibold text-gray-900" x-text="formatRate(pricePer())">,</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500 uppercase mb-1"><?= htmlspecialchars(t('card_credits.custom_total')) ?></p>
                    <p class="font-mono text-xl font-bold text-gray-900" x-text="formatTotal(count * pricePer())">,</p>
                    <p x-show="count * (0.500 - pricePer()) > 0" class="text-xs text-green-700 font-medium mt-1"
                       x-text="'<?= htmlspecialchars(t('card_credits.save_prefix')) ?> ' + formatTotal(count * (0.500 - pricePer()))"></p>
                </div>
            </div>
        </div>
        <script>
          function ccCustom() {
            const tiers = <?= json_encode(array_map(static fn($b) => ['count' => $b['count'], 'price' => $b['price_per']], $BUNDLES)) ?>;
            return {
              active: false,
              count: 25,
              pricePer() {
                let p = tiers[0].price;
                for (const t of tiers) if (this.count >= t.count) p = t.price;
                return p;
              },
              formatRate(v) { return v.toFixed(3) + ' OMR'; },
              formatTotal(v) { return v.toFixed(3) + ' OMR'; },
            };
          }
        </script>
        <!-- Inline quick pay: Apple Pay (native; Chrome via QR handoff) + Pixel card.
             Bound to the currently selected bundle; the amount tracks the radio. -->
        <div id="inlinePay" class="mt-8 max-w-md mx-auto">
            <div data-ap="wrap" hidden class="mb-3">
                <apple-pay-button data-ap="button" role="button" aria-label="<?= htmlspecialchars(t('order.pay_with_apple_pay')) ?>"
                        buttonstyle="black" type="plain"
                        style="--apple-pay-button-width:100%;--apple-pay-button-height:48px;--apple-pay-button-border-radius:12px;display:block;width:100%;height:48px;"></apple-pay-button>
                <p data-ap="status" hidden role="status" aria-live="polite" class="mt-2 text-sm text-center text-gray-500 data-[tone=error]:text-red-600"></p>
                <div class="flex items-center gap-3 my-4" aria-hidden="true">
                    <span class="h-px flex-1 bg-gray-200"></span>
                    <span class="text-xs text-gray-400 uppercase"><?= htmlspecialchars(t('order.or')) ?></span>
                    <span class="h-px flex-1 bg-gray-200"></span>
                </div>
            </div>
            <button type="button" data-checkout="trigger"
                    class="w-full text-white py-3 px-6 rounded-xl font-medium transition flex items-center justify-center gap-2 hover:brightness-95"
                    style="background:#009bc1;">
                <i class="fa-solid fa-credit-card"></i>
                <span><?= htmlspecialchars(t('order.pay_by_card')) ?></span>
                <i class="fa-solid fa-chevron-down text-xs opacity-80"></i>
            </button>
            <div data-checkout="skeleton" hidden class="mt-4 animate-pulse" aria-hidden="true">
                <div class="h-3 w-24 bg-gray-200 rounded mb-3"></div>
                <div class="h-12 bg-gray-100 rounded-xl mb-3"></div>
                <div class="grid grid-cols-2 gap-3 mb-3"><div class="h-12 bg-gray-100 rounded-xl"></div><div class="h-12 bg-gray-100 rounded-xl"></div></div>
                <div class="h-12 rounded-xl mt-4" style="background:#bde8f2;"></div>
            </div>
            <div id="paymob-elements" data-checkout="mount" hidden class="mt-4"></div>
            <p data-checkout="status" hidden role="status" aria-live="polite" class="mt-3 text-sm text-center text-gray-600 data-[tone=error]:text-red-600"></p>
            <div data-checkout="retry" hidden class="text-center mt-3">
                <a href="#ccCheckout" class="text-sm text-gray-500 hover:text-gray-700 underline underline-offset-4"><?= htmlspecialchars(t('order.pay_secure_page')) ?></a>
            </div>
            <div data-checkout="fallback" hidden class="text-center mt-3">
                <a href="#ccCheckout" class="text-sm text-gray-500 hover:text-gray-700 underline underline-offset-4"><?= htmlspecialchars(t('order.pay_secure_page')) ?></a>
            </div>
        </div>

        <!-- Divider before the always-available hosted (secure page) option -->
        <div class="flex items-center gap-3 my-6 max-w-md mx-auto" aria-hidden="true">
            <span class="h-px flex-1 bg-gray-200"></span>
            <span class="text-xs text-gray-400 uppercase"><?= htmlspecialchars(t('order.pay_secure_page')) ?></span>
            <span class="h-px flex-1 bg-gray-200"></span>
        </div>

        <div class="mt-6 flex justify-center">
            <button type="submit" id="ccCheckout" class="inline-flex items-center gap-2 px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl shadow transition">
                <i class="fa-solid fa-building-columns"></i>
                <?= htmlspecialchars(t('card_credits.checkout_btn')) ?>
            </button>
        </div>
    </form>

    <!-- Recent activity -->
    <section class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <header class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
            <h2 class="font-semibold text-gray-900"><?= htmlspecialchars(t('card_credits.recent_h')) ?></h2>
            <?php
                $basePath = getAdminBasePath();
                $isCompanyAdmin = defined('COMPANY_ADMIN_BASE') || !empty($_SESSION['company_slug']);
                $ext = $isCompanyAdmin ? '' : '.php';
            ?>
            <a href="<?= htmlspecialchars($basePath . 'payments-history' . $ext) ?>?type=card_order" class="text-xs text-blue-600 hover:text-blue-700">
                <?= htmlspecialchars(t('card_credits.all_activity')) ?> <i class="fa-solid fa-arrow-right rtl:hidden"></i><i class="fa-solid fa-arrow-left hidden rtl:inline"></i>
            </a>
        </header>
        <?php if (empty($ledger)): ?>
            <div class="p-10 text-center text-sm text-gray-500">
                <i class="fa-regular fa-file-lines text-3xl text-gray-300 mb-2"></i>
                <p><?= htmlspecialchars(t('card_credits.empty')) ?></p>
            </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                    <tr>
                        <th class="px-4 py-3 text-left rtl:text-right"><?= htmlspecialchars(t('card_credits.col_date')) ?></th>
                        <th class="px-4 py-3 text-left rtl:text-right"><?= htmlspecialchars(t('card_credits.col_reason')) ?></th>
                        <th class="px-4 py-3 text-right rtl:text-left"><?= htmlspecialchars(t('card_credits.col_delta')) ?></th>
                        <th class="px-4 py-3 text-right rtl:text-left"><?= htmlspecialchars(t('card_credits.col_balance')) ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                <?php foreach ($ledger as $l): $delta = (int) $l['delta']; ?>
                    <tr>
                        <td class="px-4 py-2.5 text-gray-700"><?= htmlspecialchars(I18n::formatDate(strtotime($l['created_at']))) ?></td>
                        <td class="px-4 py-2.5 text-gray-900 capitalize"><?= htmlspecialchars($l['reason']) ?></td>
                        <td class="px-4 py-2.5 text-right rtl:text-left font-mono <?= $delta < 0 ? 'text-red-600' : 'text-green-700' ?>">
                            <?= $delta > 0 ? '+' : '' ?><?= $delta ?>
                        </td>
                        <td class="px-4 py-2.5 text-right rtl:text-left font-mono text-gray-900"><?= (int) $l['balance_after'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>
</div>
<?php
// Inline checkout config (Apple Pay native + Paymob Pixel card) for card credits.
// Additive: the hosted "Checkout" button above is unchanged and always works.
$__ccToken = generateCSRFToken();
$__ccBase  = getBasePath() . 'admin/paymob-intent.php?purpose=card_order&csrf=' . urlencode($__ccToken);
// Default = the pre-checked recommended bundle, so the widgets have a valid amount on load.
$__ccDefault = $BUNDLES[1];
foreach ($BUNDLES as $b) { if (!empty($b['recommended'])) { $__ccDefault = $b; break; } }
$__ccDefaultCount = (int) $__ccDefault['count'];
$__ccDefaultAmt   = number_format($__ccDefault['count'] * $__ccDefault['price_per'], 3, '.', '');
$__ccDefaultUrl   = $__ccBase . '&count=' . $__ccDefaultCount;
$__ccSuccess      = getBasePath() . 'admin/card-credits.php';
?>
<script>
  window.__cardifyNativeAP = {
    intentUrl: <?= json_encode($__ccDefaultUrl) ?>,
    apiBase: 'https://oman.paymob.com',
    appleMerchantId: 'merchant.om.bhd',
    appleIntegrationId: 48389,
    merchantName: 'Cardify',
    amount: <?= json_encode($__ccDefaultAmt) ?>,
    currency: 'OMR',
    countryCode: 'OM',
    successUrl: <?= json_encode($__ccSuccess) ?>,
    hostedFallbackUrl: '#ccCheckout'
  };
  window.__cardifyCheckout = {
    intentUrl: <?= json_encode($__ccDefaultUrl) ?>,
    successUrl: <?= json_encode($__ccSuccess) ?>,
    hostedFallbackUrl: '#ccCheckout',
    paymentMethods: ['card'],
    autoStart: false,
    sdkUrl: 'https://cdn.jsdelivr.net/npm/paymob-pixel@latest/main.js'
  };
</script>
<script src="<?= getBasePath() ?>assets/js/paymob-apple-pay.js?v=<?= @filemtime(__DIR__ . '/../assets/js/paymob-apple-pay.js') ?: time() ?>"></script>
<script src="<?= getBasePath() ?>assets/js/paymob-pixel.js?v=<?= @filemtime(__DIR__ . '/../assets/js/paymob-pixel.js') ?: time() ?>"></script>
<script>
  // Keep the inline widgets' amount in sync with the selected bundle.
  (function () {
    var base = <?= json_encode($__ccBase) ?>;
    var counts = <?= json_encode(array_map(static fn($b) => (int)$b['count'], $BUNDLES)) ?>;
    var tiers = <?= json_encode(array_map(static fn($b) => ['count' => (int)$b['count'], 'price' => (float)$b['price_per']], $BUNDLES)) ?>;
    var lastUrl = <?= json_encode($__ccDefaultUrl) ?>; // matches the default config, so no double-bootstrap on load
    function priceFor(c) { var p = tiers[0].price; tiers.forEach(function (t) { if (c >= t.count) p = t.price; }); return p; }
    function currentCount() {
      var sel = document.querySelector('input[name="bundle"]:checked');
      if (!sel) return 0;
      if (sel.value === 'custom') {
        var raw = document.querySelector('input[name="custom_count"]');
        var n = raw ? parseInt(raw.value, 10) : 0;
        if (!n) n = 0;
        return Math.max(10, Math.min(5000, n));
      }
      var idx = parseInt(sel.value, 10);
      return counts[idx] || 0;
    }
    function apply() {
      var c = currentCount();
      if (!c) return;
      var url = base + '&count=' + c;
      if (url === lastUrl) return;
      lastUrl = url;
      var amount = (c * priceFor(c)).toFixed(3);
      if (window.__cardifyAP) window.__cardifyAP.reconfigure({ intentUrl: url, amount: amount });
      if (window.__cardifyPixel) window.__cardifyPixel.reconfigure({ intentUrl: url, amountLabel: 'Pay ' + amount + ' OMR' });
    }
    document.addEventListener('change', function (e) { if (e.target && e.target.name === 'bundle') apply(); });
    var cc = document.querySelector('input[name="custom_count"]');
    if (cc) cc.addEventListener('input', apply);
  })();
</script>

<?php adminFooter(); ?>
