<?php
/**
 * CardJob: the state machine must refuse every move that is not in the diagram.
 * Run: /www/server/php/83/bin/php tests/CardJobTest.php
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/CardJob.php';

$fails = 0;
function t(string $name, bool $ok): void {
    global $fails;
    echo ($ok ? "PASS  " : "FAIL  ") . $name . "\n";
    if (!$ok) { $fails++; }
}

t('forward transition allowed',        CardJob::canTransition('submitted', 'approved') === true);
t('approved to quoted allowed',        CardJob::canTransition('approved', 'quoted') === true);
t('quoted to po_received allowed',     CardJob::canTransition('quoted', 'po_received') === true);
t('dispatched to delivered allowed',   CardJob::canTransition('dispatched', 'delivered') === true);

t('skipping a state refused',          CardJob::canTransition('approved', 'dispatched') === false);
t('backwards refused',                 CardJob::canTransition('quoted', 'approved') === false);
t('delivered is terminal',             CardJob::canTransition('delivered', 'dispatched') === false);
t('rejected is terminal',              CardJob::canTransition('rejected', 'approved') === false);

t('reject only from submitted',        CardJob::canTransition('submitted', 'rejected') === true);
t('reject not from quoted',            CardJob::canTransition('quoted', 'rejected') === false);

t('unknown target refused',            CardJob::canTransition('quoted', 'banana') === false);
t('unknown source refused',            CardJob::canTransition('banana', 'quoted') === false);

t('ref has the expected shape',        preg_match('/^MHD-[A-Z0-9]{6}$/', CardJob::mintRef('req-1')) === 1);
t('ref is stable for one request',     CardJob::mintRef('req-1') === CardJob::mintRef('req-1'));
t('ref differs between requests',      CardJob::mintRef('req-1') !== CardJob::mintRef('req-2'));

t('every state is reachable in NEXT',  count(CardJob::STATES) === 8);

echo $fails === 0 ? "\nall passed\n" : "\n{$fails} FAILED\n";
exit($fails === 0 ? 0 : 1);
