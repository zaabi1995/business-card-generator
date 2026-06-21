<?php
/**
 * cron/wc_score.php - score predictions for finished matches.
 * 1 point for the correct result (home/draw/away), +2 bonus for the exact
 * score. Recomputes points_cache (prediction points + share bonus).
 * Idempotent: only unscored predictions of finished matches are touched.
 */
require_once __DIR__ . '/../config.php';
$db = Database::getInstance();

$finished = $db->fetchAll(
    "SELECT espn_id, home_score, away_score FROM wc_matches
     WHERE state='post' AND home_score IS NOT NULL AND away_score IS NOT NULL");

$scoredPreds = 0; $affected = [];
foreach ($finished as $m) {
    $hs = (int)$m['home_score']; $as = (int)$m['away_score'];
    $result = $hs === $as ? 'draw' : ($hs > $as ? 'home' : 'away');
    $preds = $db->fetchAll(
        "SELECT id, user_id, pick, pred_home, pred_away FROM wc_predictions
         WHERE match_id=:m AND scored=0", ['m'=>$m['espn_id']]);
    foreach ($preds as $p) {
        $pts = ($p['pick'] === $result) ? 1 : 0;
        if ($p['pred_home'] !== null && $p['pred_away'] !== null
            && (int)$p['pred_home'] === $hs && (int)$p['pred_away'] === $as) {
            $pts += 2; // exact-score bonus
        }
        $db->update('wc_predictions', ['points'=>$pts,'scored'=>1], 'id=:id', ['id'=>$p['id']]);
        $affected[(int)$p['user_id']] = true;
        $scoredPreds++;
    }
}

// Recompute points_cache for affected users (predictions + share bonus).
foreach (array_keys($affected) as $uid) {
    $db->query(
        "UPDATE wc_users SET points_cache =
            (SELECT COALESCE(SUM(points),0) FROM wc_predictions WHERE user_id=:u1)
            + bonus_points
         WHERE id=:u2", ['u1'=>$uid, 'u2'=>$uid]);
}
echo "[wc_score] scored {$scoredPreds} predictions across ".count($affected)." users\n";
