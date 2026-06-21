<?php
/**
 * WcWalletMatches - builds the signed "Matches" World Cup .pkpass for a user.
 *
 * Unlike the player pass (points/rank/streak), this pass shows TODAY's World
 * Cup fixtures in the user's timezone (kickoff times, LIVE / FT states) plus
 * yesterday's results, and refreshes daily via the same PassKit web service +
 * APNs cron. One definition so the download endpoint and the web-service push
 * always emit an identical pass.
 *
 * Apple's generic style only shows a handful of front fields, so the front
 * carries a compact summary (matchday + first few kickoffs) and the FULL list
 * lives in backFields (which render many rows on the pass back).
 */

require_once INCLUDES_DIR . '/WcHub.php';
require_once INCLUDES_DIR . '/WcMatches.php';
require_once INCLUDES_DIR . '/AppleWalletPass.php';

class WcWalletMatches
{
    /** Build and sign the matches .pkpass bytes for $user. Refreshes the content tag. */
    public static function build(array $user, ?DateTime $now = null): string
    {
        $uid  = (int)$user['id'];
        $name = trim((string)($user['name'] ?? 'Player')) ?: 'Player';
        $view = WcMatches::dayView($user, $now);
        $tag  = WcMatches::updateTag($view);

        $pass   = WcHub::walletPassFor($uid, 'matches');
        $serial = $pass['serial'];
        $token  = $pass['auth_token'];

        Database::getInstance()->update('wc_wallet_passes', ['updated_tag'=>$tag], 'serial=:s', ['s'=>$serial]);

        $cardUrl = 'https://wc.cardify.om/predictions';
        $bg = 'rgb(37, 99, 235)';     // #2563eb
        $fg = 'rgb(255, 255, 255)';
        $lb = 'rgb(242, 193, 78)';    // #F2C14E gold labels

        $qr = [
            'format'          => 'PKBarcodeFormatQR',
            'message'         => $cardUrl,
            'messageEncoding' => 'iso-8859-1',
            'altText'         => 'wc.cardify.om',
        ];

        // Day heading in the user's tz.
        $tz = WcMatches::tzOf($user['tz'] ?? null);
        $dayHeading = (new DateTime($view['matchday'] . ' 00:00:00', $tz))->format('D, d M');

        // Front summary.
        $count = count($view['today']);
        if ($view['over']) {
            $primaryValue = 'Tournament concluded';
            $headerValue  = 'WC 2026';
        } elseif ($count === 0) {
            $primaryValue = 'No matches';
            $headerValue  = $dayHeading;
        } else {
            $label = $view['is_today'] ? 'TODAY' : 'NEXT';
            $primaryValue = $count . ($count === 1 ? ' match' : ' matches');
            $headerValue  = $label;
        }

        // Up to two next-kickoff secondary fields (front of card).
        $secondary = [];
        foreach (array_slice($view['today'], 0, 2) as $i => $m) {
            $secondary[] = [
                'key'   => 'm' . $i,
                'label' => self::stateLabel($m['state']),
                'value' => self::frontFixtureValue($m),
            ];
        }
        if (!$secondary) {
            $secondary[] = ['key'=>'none', 'label'=>'STATUS',
                'value'=> $view['over'] ? 'Season over' : 'Check back soon'];
        }

        // Back: the full day list + yesterday's results.
        $backFields = [];
        $backFields[] = ['key'=>'day', 'label'=>'Matchday', 'value'=>$dayHeading
            . ' (' . $tz->getName() . ')'];

        $todayLines = [];
        foreach ($view['today'] as $m) { $todayLines[] = '• ' . WcMatches::fixtureLine($m); }
        $backFields[] = [
            'key'   => 'fixtures',
            'label' => $view['is_today'] ? "Today's fixtures" : 'Next matchday',
            'value' => $todayLines ? implode("\n", $todayLines)
                                   : ($view['over'] ? 'The World Cup has concluded.' : 'No fixtures scheduled.'),
        ];

        if ($view['yesterday']) {
            $ydayLines = [];
            foreach ($view['yesterday'] as $m) { $ydayLines[] = '• ' . WcMatches::fixtureLine($m); }
            $backFields[] = [
                'key'   => 'results',
                'label' => "Yesterday's results",
                'value' => implode("\n", $ydayLines),
            ];
        }

        $backFields[] = ['key'=>'predict', 'label'=>'Predict & win',
            'value'=>$cardUrl, 'attributedValue'=>'<a href="'.$cardUrl.'">Open predictions</a>'];
        $backFields[] = ['key'=>'lb', 'label'=>'Leaderboard',
            'value'=>'https://wc.cardify.om/wc-leaderboard',
            'attributedValue'=>'<a href="https://wc.cardify.om/wc-leaderboard">Open leaderboard</a>'];
        $backFields[] = ['key'=>'about', 'label'=>'About',
            'value'=>'Today\'s World Cup fixtures in your timezone, updated daily. Top 3 predictors win $10,000 / $5,000 / $1,000. Powered by Cardify.'];

        $passData = [
            'formatVersion'      => 1,
            'passTypeIdentifier' => APPLE_WALLET_PASS_TYPE_ID,
            'serialNumber'       => $serial,
            'teamIdentifier'     => APPLE_WALLET_TEAM_ID,
            'organizationName'   => defined('APPLE_WALLET_ORG_NAME') ? APPLE_WALLET_ORG_NAME : 'Cardify',
            'description'        => 'World Cup 2026 Matches - ' . $name,
            'logoText'           => 'World Cup 2026 Matches',
            'foregroundColor'    => $fg,
            'backgroundColor'    => $bg,
            'labelColor'         => $lb,
            'barcodes'           => [$qr],
            'barcode'            => $qr,
            'webServiceURL'        => 'https://wc.cardify.om/wc-wallet/v1',
            'authenticationToken'  => $token,
            'generic' => [
                'headerFields' => [
                    ['key'=>'when', 'label'=>'MATCHDAY', 'value'=>$headerValue, 'textAlignment'=>'PKTextAlignmentRight'],
                ],
                'primaryFields' => [
                    ['key'=>'count', 'label'=>'FIXTURES', 'value'=>$primaryValue],
                ],
                'secondaryFields' => $secondary,
                'auxiliaryFields' => [
                    ['key'=>'tz', 'label'=>'YOUR TIMEZONE', 'value'=>$tz->getName()],
                ],
                'backFields' => $backFields,
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

    private static function stateLabel(string $state): string
    {
        if ($state === 'in')   return 'LIVE';
        if ($state === 'post') return 'FT';
        return 'KICKOFF';
    }

    /** Compact front value for one fixture. */
    private static function frontFixtureValue(array $m): string
    {
        $home = self::shorten($m['home']);
        $away = self::shorten($m['away']);
        if ($m['state'] === 'post' || $m['state'] === 'in') {
            return $home . ' ' . $m['hs'] . '-' . $m['as'] . ' ' . $away;
        }
        /** @var DateTime $ko */
        $ko = $m['ko'];
        return $ko->format('H:i') . '  ' . $home . ' v ' . $away;
    }

    /** Trim long placeholder names ("Round of 32 7 Winner") so the front fits. */
    private static function shorten(string $name): string
    {
        $name = trim($name);
        if (function_exists('mb_strlen') ? mb_strlen($name) > 16 : strlen($name) > 16) {
            return (function_exists('mb_substr') ? mb_substr($name, 0, 15) : substr($name, 0, 15)) . '…';
        }
        return $name;
    }
}
