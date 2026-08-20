<?php
/**
 * The verification footer every comparison page carries.
 *
 * r328. A comparison page is a legal surface as much as a marketing one, so
 * the rule this partial exists to enforce is: every competitor claim on this
 * estate names the page it was read from and the date it was read, and links
 * there so a reader (or the competitor) can check it.
 *
 * $checkedDate and $sources (list of [label, url]) come from the caller.
 *
 * r329: this file answered 200 on its own URL. The nginx slug class excludes
 * underscore, so /compare/_verify-note was already blocked, but nothing
 * blocked the raw /compare/_verify-note.php path, and with display_errors on
 * in the production pool it served an indexable fragment whose body was a
 * PHP warning naming the absolute docroot. A comment asserting a file "must
 * not be reachable" is not a gate. This is the gate: a partial with no
 * caller has no page, so it 404s instead of rendering half of one.
 */
if (!isset($checkedDate) || !isset($sources) || !is_array($sources)) {
    http_response_code(404);
    if (defined('BASE_DIR') && is_file(BASE_DIR . '/404.php')) {
        require BASE_DIR . '/404.php';
    }
    exit;
}
?>
<div class="not-prose my-10 rounded-xl border border-gray-200 bg-gray-50 px-5 py-5 text-sm text-gray-600">
    <p class="font-semibold text-gray-900 mb-2">
        <i class="fa-solid fa-circle-info mr-1.5" aria-hidden="true"></i>
        How we checked this
    </p>
    <p class="mb-3">
        Every competitor fact on this page was read off the competitor's own live pages on <?= htmlspecialchars($checkedDate) ?>, and each is linked below so you can verify it yourself. Pricing and features change: if you find something here that is out of date or wrong, tell us at <a href="<?= getBasePath() ?>contact" class="text-blue-600 font-medium">our contact page</a> and we will correct it.
    </p>
    <p class="mb-2">Where we say a competitor "does not advertise" something, we mean the claim was absent from the pages listed below on that date. It is not a statement that their product lacks the feature.</p>
    <ul class="list-disc pl-5 space-y-1">
        <?php foreach ($sources as [$label, $url]): ?>
            <li><a href="<?= htmlspecialchars($url) ?>" rel="nofollow noopener" target="_blank" class="text-blue-600 hover:underline"><?= htmlspecialchars($label) ?></a></li>
        <?php endforeach; ?>
    </ul>
    <p class="mt-3">Cardify is a product of BHD Group (Bin Haider Darwish L.L.C.), Muscat. All other product names are the trademarks of their respective owners, used here for identification and comparison only.</p>
</div>
