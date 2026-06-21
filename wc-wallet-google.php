<?php
/**
 * wc.cardify.om/wc-wallet-google
 * Builds a Google Wallet generic pass for the signed-in World Cup player
 * (points, knockout rank context, streak, CTA link) and 302s to the
 * pay.google.com/gp/v/save/<jwt> save URL.
 *
 * Reuses the live Cardify Google Wallet config (issuer + service account +
 * the existing generic class GOOGLE_WALLET_CLASS_ID). The pass reflects the
 * player's state AT SAVE TIME. Live daily auto-refresh of the saved pass
 * needs a server push integration (see docs/wc-campaign-status.md) and is
 * tracked there; this save path is the low-risk piece that works today.
 *
 * Falls back to /wc-wallet (the friendly placeholder) if Wallet is not
 * configured or building the pass fails, so the button never dead-ends.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/WcHub.php';
require_once INCLUDES_DIR . '/GoogleWalletPass.php';

$user = WcHub::currentUser();
if (!$user) { header('Location: https://wc.cardify.om/'); exit; }

if (!GoogleWalletPass::isEnabled()) { header('Location: /wc-wallet'); exit; }

try {
    $db   = Database::getInstance();
    $uid  = (int)$user['id'];
    $name = trim((string)($user['name'] ?? 'Player'));
    $pts  = (int)($user['points_cache'] ?? 0);
    $streak = (int)($user['streak_count'] ?? 0);

    $rank = (int)($db->fetchOne(
        "SELECT COUNT(*)+1 AS r FROM wc_users WHERE status='active' AND points_cache > :p",
        ['p'=>$pts]
    )['r'] ?? 1);

    // Unique-per-user object under the existing generic class.
    $objectId = GoogleWalletPass::objectResourceId('wc_' . $uid);
    $classId  = GoogleWalletPass::classResourceId();

    $object = [
        'id'        => $objectId,
        'classId'   => $classId,
        'state'     => 'ACTIVE',
        'hexBackgroundColor' => '#2563eb',
        'logo' => [
            'sourceUri' => ['uri' => 'https://wc.cardify.om/assets/wc/wc-logo.png'],
            'contentDescription' => ['defaultValue' => ['language'=>'en','value'=>'World Cup 2026']],
        ],
        'cardTitle'   => ['defaultValue' => ['language'=>'en','value'=>'World Cup 2026 · Cardify']],
        'subheader'   => ['defaultValue' => ['language'=>'en','value'=>'Player']],
        'header'      => ['defaultValue' => ['language'=>'en','value'=>($name !== '' ? $name : 'Player')]],
        'textModulesData' => [
            ['id'=>'points', 'header'=>'Points', 'body'=>(string)$pts],
            ['id'=>'rank',   'header'=>'Rank',   'body'=>'#'.$rank],
            ['id'=>'streak', 'header'=>'Day streak', 'body'=>(string)$streak],
        ],
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
    error_log('wc-wallet-google failed: ' . $e->getMessage());
    header('Location: /wc-wallet');
    exit;
}
