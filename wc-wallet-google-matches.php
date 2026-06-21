<?php
/**
 * wc.cardify.om/wc-wallet-google-matches
 *
 * Builds a Google Wallet generic pass listing TODAY's World Cup fixtures in
 * the player's timezone (+ yesterday's results) and 302s to the
 * pay.google.com/gp/v/save/<jwt> save URL. Distinct object id (wcm_<uid>) from
 * the player pass (wc_<uid>) so a user can hold both.
 *
 * Reflects the day's matches AT SAVE TIME. Daily auto-refresh of the saved
 * Google object needs the server-push integration tracked in
 * docs/wc-campaign-status.md; this save path is the piece that works today.
 *
 * Falls back to /wc-wallet if Wallet is not configured or building fails.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/WcHub.php';
require_once INCLUDES_DIR . '/WcMatches.php';
require_once INCLUDES_DIR . '/GoogleWalletPass.php';

$user = WcHub::currentUser();
if (!$user) { header('Location: https://wc.cardify.om/'); exit; }

if (!GoogleWalletPass::isEnabled()) { header('Location: /wc-wallet'); exit; }

try {
    $uid  = (int)$user['id'];
    $name = trim((string)($user['name'] ?? 'Player'));
    $view = WcMatches::dayView($user);
    $tz   = WcMatches::tzOf($user['tz'] ?? null);
    $dayHeading = (new DateTime($view['matchday'] . ' 00:00:00', $tz))->format('D, d M');

    // Unique-per-user MATCHES object under the existing generic class.
    $objectId = GoogleWalletPass::objectResourceId('wcm_' . $uid);
    $classId  = GoogleWalletPass::classResourceId();

    $todayLines = [];
    foreach ($view['today'] as $m) { $todayLines[] = WcMatches::fixtureLine($m); }
    $todayBody = $todayLines ? implode("\n", $todayLines)
                             : ($view['over'] ? 'The World Cup has concluded.' : 'No fixtures scheduled.');

    $textModules = [
        ['id'=>'day',     'header'=>($view['is_today'] ? 'Today' : 'Next matchday'), 'body'=>$dayHeading . ' (' . $tz->getName() . ')'],
        ['id'=>'fixtures','header'=>'Fixtures', 'body'=>$todayBody],
    ];
    if ($view['yesterday']) {
        $ydayLines = [];
        foreach ($view['yesterday'] as $m) { $ydayLines[] = WcMatches::fixtureLine($m); }
        $textModules[] = ['id'=>'results', 'header'=>"Yesterday's results", 'body'=>implode("\n", $ydayLines)];
    }

    $object = [
        'id'        => $objectId,
        'classId'   => $classId,
        'state'     => 'ACTIVE',
        'hexBackgroundColor' => '#2563eb',
        'logo' => [
            'sourceUri' => ['uri' => 'https://wc.cardify.om/assets/wc/wc-logo.png'],
            'contentDescription' => ['defaultValue' => ['language'=>'en','value'=>'World Cup 2026']],
        ],
        'cardTitle'   => ['defaultValue' => ['language'=>'en','value'=>'World Cup 2026 Matches']],
        'subheader'   => ['defaultValue' => ['language'=>'en','value'=>'Today\'s fixtures']],
        'header'      => ['defaultValue' => ['language'=>'en','value'=>$dayHeading]],
        'textModulesData' => $textModules,
        'linksModuleData' => [
            'uris' => [
                ['uri'=>'https://wc.cardify.om/predictions', 'description'=>'Make predictions', 'id'=>'predict'],
                ['uri'=>'https://wc.cardify.om/wc-leaderboard', 'description'=>'Leaderboard', 'id'=>'lb'],
            ],
        ],
        'barcode' => [
            'type'  => 'QR_CODE',
            'value' => 'https://wc.cardify.om/predictions',
            'alternateText' => 'wc.cardify.om',
        ],
    ];

    $url = GoogleWalletPass::buildSaveUrl($object);
    header('Location: ' . $url, true, 302);
    exit;
} catch (Throwable $e) {
    error_log('wc-wallet-google-matches failed: ' . $e->getMessage());
    header('Location: /wc-wallet');
    exit;
}
