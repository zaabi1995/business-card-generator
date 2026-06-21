<?php
/**
 * cron/wc_sync_matches.php - sync WC2026 fixtures + results from ESPN into
 * wc_matches. Syncs a rolling window (so predictions show upcoming games and
 * recent results stay fresh). Run every ~15-30 min via cron.
 */
require_once __DIR__ . '/../config.php';

$ESPN = 'https://site.api.espn.com/apis/site/v2/sports/soccer/fifa.world/scoreboard?dates=';
$db = Database::getInstance();

// Rolling window: 3 days back to 14 days ahead (covers near-term predictions).
$start = new DateTime('now', new DateTimeZone('UTC'));
$start->modify('-3 days');
$days = 18;
$synced = 0;

for ($i = 0; $i < $days; $i++) {
    $d = (clone $start)->modify("+{$i} days")->format('Ymd');
    $ch = curl_init($ESPN . $d);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>20,
        CURLOPT_USERAGENT=>'wc.cardify.om/1.0', CURLOPT_SSL_VERIFYPEER=>true]);
    $body = curl_exec($ch); curl_close($ch);
    if (!$body) continue;
    $data = json_decode($body, true);
    foreach (($data['events'] ?? []) as $e) {
        try {
            $comp = $e['competitions'][0] ?? null; if (!$comp) continue;
            $cs = $comp['competitors'] ?? [];
            $home = null; $away = null;
            foreach ($cs as $c) { if (($c['homeAway'] ?? '')==='home') $home=$c; else $away=$c; }
            if (!$home || !$away) continue;
            $espnId = (string)($e['id'] ?? '');
            if ($espnId==='') continue;
            $ko = new DateTime($e['date'] ?? 'now'); $ko->setTimezone(new DateTimeZone('UTC'));
            $state = $e['status']['type']['state'] ?? 'pre';
            $hs = isset($home['score']) && $home['score']!=='' ? (int)$home['score'] : null;
            $as = isset($away['score']) && $away['score']!=='' ? (int)$away['score'] : null;
            $row = [
                'espn_id'    => $espnId,
                'stage'      => substr((string)($e['season']['type']['name'] ?? ($data['leagues'][0]['season']['type']['name'] ?? '')), 0, 32),
                'home'       => substr((string)($home['team']['displayName'] ?? '?'), 0, 80),
                'away'       => substr((string)($away['team']['displayName'] ?? '?'), 0, 80),
                'kickoff_utc'=> $ko->format('Y-m-d H:i:s'),
                'home_score' => $hs,
                'away_score' => $as,
                'state'      => substr($state, 0, 12),
            ];
            // upsert
            $exists = $db->fetchOne("SELECT espn_id FROM wc_matches WHERE espn_id=:i", ['i'=>$espnId]);
            if ($exists) {
                $db->update('wc_matches', $row, 'espn_id=:i', ['i'=>$espnId]);
            } else {
                $db->insert('wc_matches', $row);
            }
            $synced++;
        } catch (Throwable $ex) { error_log('wc_sync event: '.$ex->getMessage()); }
    }
}
echo "[wc_sync] upserted {$synced} matches\n";
