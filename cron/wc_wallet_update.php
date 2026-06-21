<?php
/**
 * cron/wc_wallet_update.php - daily World Cup Apple Wallet refresh.
 *
 * For every issued pass: recompute the live content tag (points/rank/level/
 * streak/next match). If it changed, bump wc_wallet_passes.updated_at so the
 * web service serves a fresh pass, then APNs-push every device registered for
 * that serial (empty payload -> the device re-fetches the .pkpass). Prunes
 * push tokens APNs reports as gone (410/BadDeviceToken/Unregistered).
 *
 * Install (do NOT auto-install): run once daily, e.g. 06:00 Asia/Muscat
 *   0 6 * * *  /www/server/php/83/bin/php /www/wwwroot/cardify.om/cron/wc_wallet_update.php >> /www/wwwroot/cardify.om/logs/wc_wallet_update.log 2>&1
 *
 * Safe to run more often; only changed passes trigger a push.
 */

require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/WcHub.php';
require_once INCLUDES_DIR . '/WcMatches.php';
require_once INCLUDES_DIR . '/AppleWalletPushNotifier.php';

$db = Database::getInstance();
$log = fn($m) => fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $m . "\n");

if (!AppleWalletPushNotifier::isConfigured()) {
    $log('APNs not configured, nothing to do.');
    exit(0);
}

// Older schemas may lack pass_type; treat its absence as 'player'.
$hasType = (bool)$db->fetchOne(
    "SELECT 1 AS x FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='wc_wallet_passes' AND COLUMN_NAME='pass_type'"
);
$cols = 'id, user_id, serial, updated_tag' . ($hasType ? ', pass_type' : '');
$passes = $db->fetchAll("SELECT $cols FROM wc_wallet_passes");
$log('passes=' . count($passes));

$changed = 0; $pushedDevices = 0; $pruned = 0;
foreach ($passes as $p) {
    $user = $db->fetchOne("SELECT * FROM wc_users WHERE id=:id LIMIT 1", ['id'=>(int)$p['user_id']]);
    if (!$user) continue;

    // Matches passes track the day's fixtures; player passes track points/streak.
    $type = ($p['pass_type'] ?? null) === 'matches' || strpos((string)$p['serial'], 'wcm') === 0
        ? 'matches' : 'player';
    if ($type === 'matches') {
        $newTag = WcMatches::updateTag(WcMatches::dayView($user));
    } else {
        $newTag = WcHub::walletUpdateTag(WcHub::walletState($user));
    }
    if ($newTag === (string)$p['updated_tag']) {
        continue; // nothing moved for this user
    }

    // Touch the pass row (bumps updated_at via ON UPDATE) so the web service
    // returns the new pass and the device's If-Modified-Since misses.
    $db->update('wc_wallet_passes', ['updated_tag'=>$newTag], 'id=:id', ['id'=>(int)$p['id']]);
    $changed++;

    $regs = $db->fetchAll("SELECT push_token FROM wallet_device_registrations WHERE pass_serial=:s", ['s'=>$p['serial']]);
    if (!$regs) continue;
    $tokens = array_map(fn($r)=>$r['push_token'], $regs);
    $res = AppleWalletPushNotifier::pushMany($tokens);
    $pushedDevices += $res['sent'];

    foreach ($res['bad_tokens'] as $bad) {
        $db->query("DELETE FROM wallet_device_registrations WHERE pass_serial=:s AND push_token=:t", ['s'=>$p['serial'], 't'=>$bad]);
        $pruned++;
    }
    foreach ($res['errors'] as $e) { $log('push err serial=' . $p['serial'] . ': ' . $e); }
}

$log("done changed=$changed pushed_devices=$pushedDevices pruned_tokens=$pruned");
exit(0);
