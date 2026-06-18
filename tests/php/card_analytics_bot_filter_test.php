<?php
/**
 * Regression test: CardAnalytics::log() must drop bot / self-traffic at insert
 * time, exactly like QRTracker::logScan() does, so the card_events table and the
 * live admin dashboard are not inflated by curl/cron/Playwright/self hits.
 *
 * Pure-logic test: no DB needed. The guard runs before any DB access, so a bot
 * UA makes CardAnalytics::log() short-circuit to false without connecting.
 *
 * Run: php tests/php/card_analytics_bot_filter_test.php
 */

require_once __DIR__ . '/../../includes/QRTracker.php';
require_once __DIR__ . '/../../includes/CardAnalytics.php';

$fails = 0;
function check($label, $got, $want) {
    global $fails;
    $ok = ($got === $want);
    if (!$ok) { $fails++; }
    printf("[%s] %s  (got=%s want=%s)\n", $ok ? 'PASS' : 'FAIL', $label,
        var_export($got, true), var_export($want, true));
}

// 1) The shared detector classifies traffic correctly.
check('curl is bot',            QRTracker::isBotOrSelfTraffic('curl/8.5.0', '1.2.3.4'), true);
check('empty UA is bot',        QRTracker::isBotOrSelfTraffic('', '1.2.3.4'), true);
check('playwright is bot',      QRTracker::isBotOrSelfTraffic('Mozilla/5.0 HeadlessChrome', '1.2.3.4'), true);
check('VPS self-IP filtered',   QRTracker::isBotOrSelfTraffic('Mozilla/5.0 (iPhone)', '147.93.20.54'), true);
check('localhost filtered',     QRTracker::isBotOrSelfTraffic('Mozilla/5.0 (iPhone)', '127.0.0.1'), true);
check('real iPhone NOT bot',    QRTracker::isBotOrSelfTraffic(
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
    '94.200.1.1'), false);

// 2) CardAnalytics::log() must reject a bot BEFORE touching the DB (returns false).
$_SERVER['HTTP_USER_AGENT'] = 'curl/8.5.0';
$_SERVER['REMOTE_ADDR'] = '1.2.3.4';
check('CardAnalytics::log drops curl view', CardAnalytics::log('emp1', 'co1', 'view'), false);

// 3) Self-traffic from the VPS is dropped too.
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone)';
$_SERVER['REMOTE_ADDR'] = '147.93.20.54';
check('CardAnalytics::log drops self-IP view', CardAnalytics::log('emp1', 'co1', 'view'), false);

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
