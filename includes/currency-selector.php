<?php
/**
 * Currency selector dropdown partial.
 * Include from ui-header.php to render the header currency picker.
 * Uses plain text + SVG chevron, no emojis, no flag images.
 */
require_once __DIR__ . '/Currency.php';

$currentCurrency = Currency::getUserCurrency();
$all = Currency::supportedCurrencies();
?>
<?php $__isRtl = function_exists('currentDir') && currentDir() === 'rtl'; ?>
<div class="relative" x-data="{ open: false }" @click.away="open = false">
    <button type="button"
            @click="open = !open"
            class="cardify-currency-pill inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-blue-500"
            aria-label="Select currency">
        <span class="font-mono"><?= htmlspecialchars($currentCurrency) ?></span>
        <svg class="w-4 h-4 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
        </svg>
    </button>

    <div x-show="open"
         x-transition
         class="absolute <?= $__isRtl ? 'left-0' : 'right-0' ?> mt-2 w-56 bg-white rounded-xl shadow-lg ring-1 ring-black ring-opacity-5 z-50 py-1"
         style="display:none;">
        <?php foreach ($all as $code => $name): ?>
        <button type="button"
                data-currency="<?= htmlspecialchars($code) ?>"
                class="currency-option w-full <?= $__isRtl ? 'text-right' : 'text-left' ?> px-4 py-2 text-sm hover:bg-gray-50 flex items-center justify-between <?= $code === $currentCurrency ? 'bg-blue-50' : '' ?>"
                onclick="cardifySetCurrency('<?= htmlspecialchars($code) ?>')">
            <div>
                <span class="font-mono font-semibold text-gray-900"><?= htmlspecialchars($code) ?></span>
                <span class="text-gray-500 ml-2"><?= htmlspecialchars($name) ?></span>
            </div>
            <?php if ($code === $currentCurrency): ?>
            <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
            <?php endif; ?>
        </button>
        <?php endforeach; ?>
    </div>
</div>

<script>
function cardifySetCurrency(code) {
    fetch('<?= getBasePath() ?>api/set-currency.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ currency: code })
    }).then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.success) { location.reload(); }
        else { alert('Could not change currency: ' + (data.error || 'unknown')); }
      });
}
</script>
