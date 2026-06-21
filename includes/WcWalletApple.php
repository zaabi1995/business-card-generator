<?php
/**
 * WcWalletApple - builds the signed World Cup .pkpass for a given wc_users row.
 *
 * One place that defines the pass content so the download endpoint
 * (wc_wallet_apple.php) and the daily-update web service (wc_wallet_service.php)
 * always emit an identical pass. Carries points/rank/level/streak/next-match,
 * a QR to predictions, and the webServiceURL + per-pass authenticationToken
 * for daily APNs updates.
 */

require_once INCLUDES_DIR . '/WcHub.php';
require_once INCLUDES_DIR . '/AppleWalletPass.php';

class WcWalletApple
{
    /** Build and sign the .pkpass bytes for $user. Also refreshes the content tag. */
    public static function build(array $user): string
    {
        $uid   = (int)$user['id'];
        $name  = trim((string)($user['name'] ?? 'Player')) ?: 'Player';
        $state = WcHub::walletState($user);
        $tag   = WcHub::walletUpdateTag($state);

        $pass   = WcHub::walletPassFor($uid);
        $serial = $pass['serial'];
        $token  = $pass['auth_token'];

        // Persist the content tag (used by the web service for change detection).
        Database::getInstance()->update('wc_wallet_passes', ['updated_tag'=>$tag], 'serial=:s', ['s'=>$serial]);

        $cardUrl = 'https://wc.cardify.om/predictions';
        $bg = 'rgb(37, 99, 235)';   // #2563eb
        $fg = 'rgb(255, 255, 255)';
        $lb = 'rgb(219, 234, 254)';

        $qr = [
            'format'          => 'PKBarcodeFormatQR',
            'message'         => $cardUrl,
            'messageEncoding' => 'iso-8859-1',
            'altText'         => 'wc.cardify.om',
        ];

        $passData = [
            'formatVersion'      => 1,
            'passTypeIdentifier' => APPLE_WALLET_PASS_TYPE_ID,
            'serialNumber'       => $serial,
            'teamIdentifier'     => APPLE_WALLET_TEAM_ID,
            'organizationName'   => defined('APPLE_WALLET_ORG_NAME') ? APPLE_WALLET_ORG_NAME : 'Cardify',
            'description'        => 'World Cup 2026 - ' . $name,
            'logoText'           => 'World Cup 2026',
            'foregroundColor'    => $fg,
            'backgroundColor'    => $bg,
            'labelColor'         => $lb,
            'barcodes'           => [$qr],
            'barcode'            => $qr,
            'webServiceURL'        => 'https://wc.cardify.om/wc-wallet/v1',
            'authenticationToken'  => $token,
            'generic' => [
                'headerFields' => [
                    ['key'=>'level', 'label'=>'LEVEL', 'value'=>(string)$state['level'], 'textAlignment'=>'PKTextAlignmentRight'],
                ],
                'primaryFields' => [
                    ['key'=>'points', 'label'=>'POINTS', 'value'=>(string)$state['points']],
                ],
                'secondaryFields' => [
                    ['key'=>'rank',   'label'=>'RANK',   'value'=>'#'.$state['rank']],
                    ['key'=>'streak', 'label'=>'STREAK', 'value'=>$state['streak'].'d'],
                    ['key'=>'title',  'label'=>'TIER',   'value'=>$state['level_title'], 'textAlignment'=>'PKTextAlignmentRight'],
                ],
                'auxiliaryFields' => [
                    ['key'=>'next', 'label'=>'NEXT MATCH', 'value'=>$state['next_match']],
                ],
                'backFields' => [
                    ['key'=>'player', 'label'=>'Player', 'value'=>$name],
                    ['key'=>'predict', 'label'=>'Predictions', 'value'=>$cardUrl,
                     'attributedValue'=>'<a href="'.$cardUrl.'">Open predictions</a>'],
                    ['key'=>'lb', 'label'=>'Leaderboard', 'value'=>'https://wc.cardify.om/wc-leaderboard',
                     'attributedValue'=>'<a href="https://wc.cardify.om/wc-leaderboard">Open leaderboard</a>'],
                    ['key'=>'about', 'label'=>'About',
                     'value'=>'Updated daily. Top 3 predictors win $10,000 / $5,000 / $1,000. Powered by Cardify.'],
                ],
            ],
        ];

        $passObj = new AppleWalletPass($passData);

        $transparentPng = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
        );
        $iconBytes = null;
        foreach ([BASE_DIR.'/assets/wc/icon.png', BASE_DIR.'/assets/wc/wc-logo.png', BASE_DIR.'/assets/images/icon-512.png'] as $c) {
            if (is_readable($c)) { $iconBytes = @file_get_contents($c); break; }
        }
        foreach (['icon.png','icon@2x.png','icon@3x.png'] as $f) {
            $passObj->addAsset($f, $iconBytes ?: $transparentPng);
        }
        foreach ([BASE_DIR.'/assets/wc/wc-logo.png', BASE_DIR.'/assets/images/logo.png'] as $c) {
            if (is_readable($c)) {
                $b = @file_get_contents($c);
                if ($b) { foreach (['logo.png','logo@2x.png','logo@3x.png'] as $f) $passObj->addAsset($f, $b); }
                break;
            }
        }

        return $passObj->build();
    }
}
