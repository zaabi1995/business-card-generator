<?php
/**
 * Shared FAQ accordion partial for /tools/* pages.
 * Expects $faq = [['q' => '...', 'a' => '...'], ...] in scope.
 * Emits a visible accordion matching site design language
 * (matches /logos hub + /gcc-business-index FAQ styling).
 */
if (empty($faq) || !is_array($faq)) return;
$_faq_esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<section class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
    <span class="text-blue-700 font-semibold text-xs uppercase tracking-wider">FAQ</span>
    <h2 class="text-2xl sm:text-3xl font-extrabold text-gray-900 mt-2 mb-2">Frequently asked</h2>
    <p class="text-gray-600 mb-7 max-w-2xl">Everything people ask about this tool, privacy, compatibility, what works, what doesn't.</p>
    <div class="space-y-3">
        <?php foreach ($faq as $i => $q): ?>
            <details class="group bg-white border border-gray-200 rounded-2xl shadow-sm hover:shadow-md hover:border-blue-300 transition overflow-hidden"<?= $i === 0 ? ' open' : '' ?>>
                <summary class="flex items-center justify-between gap-4 px-5 py-4 cursor-pointer list-none">
                    <span class="font-semibold text-gray-900"><?= $_faq_esc($q['q']) ?></span>
                    <i class="fa-solid fa-chevron-down text-gray-400 text-sm transition group-open:rotate-180"></i>
                </summary>
                <div class="px-5 pb-5 text-gray-600 leading-relaxed"><?= $_faq_esc($q['a']) ?></div>
            </details>
        <?php endforeach; ?>
    </div>
</section>
