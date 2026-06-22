<?php
/**
 * POST /api/wc-boost.php  {match_id, on}
 * Toggle a 2x Boost on the signed-in user's prediction for a match.
 *
 * Rules (all server-validated):
 *   - Must already have a prediction on the match (boost rides a pick).
 *   - Locks at kickoff, exactly like predictions.
 *   - ONE active boost per matchday: turning a new one on clears any other
 *     boost the user has on a match kicking off the same local day.
 *   - cron/wc_score.php doubles the points of a boosted correct prediction.
 *
 * "Matchday" here = the user's own local calendar day (their tz), the same day
 * boundary the Daily Mission and streak use, so the UI and rule agree.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/WcHub.php';

header('Content-Type: application/json; charset=utf-8');
function out($a){ echo json_encode($a); exit; }

$user = WcHub::currentUser();
if (!$user) out(['ok'=>false,'error'=>'auth']);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(['ok'=>false,'error'=>'method']);

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$matchId = preg_replace('/[^0-9]/', '', (string)($in['match_id'] ?? ''));
$on = !empty($in['on']);
if ($matchId === '') out(['ok'=>false,'error'=>'bad_input']);

$db  = Database::getInstance();
$uid = (int)$user['id'];

$m = $db->fetchOne("SELECT espn_id, kickoff_utc FROM wc_matches WHERE espn_id=:i", ['i'=>$matchId]);
if (!$m) out(['ok'=>false,'error'=>'no_match']);

// Lock at kickoff (UTC compare).
$nowUtc = new DateTime('now', new DateTimeZone('UTC'));
$ko = new DateTime($m['kickoff_utc'], new DateTimeZone('UTC'));
if ($nowUtc >= $ko) out(['ok'=>false,'error'=>'locked']);

// The boost rides an existing prediction.
$pred = $db->fetchOne("SELECT id FROM wc_predictions WHERE user_id=:u AND match_id=:m", ['u'=>$uid, 'm'=>$matchId]);
if (!$pred) out(['ok'=>false,'error'=>'no_prediction']);

if (!$on) {
    // Simple un-boost.
    $db->update('wc_predictions', ['boosted'=>0], 'id=:id', ['id'=>$pred['id']]);
    out(['ok'=>true,'on'=>false,'cleared'=>[]]);
}

// Enforce one active boost per matchday (user-local day). Resolve the day
// window of THIS match in the user's tz, then clear any boost on another
// match kicking off the same day, then set this one.
$tzName = $user['tz'] ?: 'Asia/Muscat';
try { $tz = new DateTimeZone($tzName); } catch (Throwable $e) { $tz = new DateTimeZone('Asia/Muscat'); }
$koLocal = (clone $ko)->setTimezone($tz);
$dayStart = new DateTime($koLocal->format('Y-m-d') . ' 00:00:00', $tz);
$dayEnd   = (clone $dayStart)->modify('+1 day');
$startUtc = (clone $dayStart)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$endUtc   = (clone $dayEnd)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

// Currently-boosted matches for this user on the same local day (to report).
$others = $db->fetchAll(
    "SELECT p.match_id FROM wc_predictions p JOIN wc_matches m ON m.espn_id = p.match_id
     WHERE p.user_id=:u AND p.boosted=1 AND p.match_id <> :mid
       AND m.kickoff_utc >= :a AND m.kickoff_utc < :b",
    ['u'=>$uid, 'mid'=>$matchId, 'a'=>$startUtc, 'b'=>$endUtc]
);
$cleared = array_column($others, 'match_id');

// Clear other boosts on the same day, then boost this one.
$db->query(
    "UPDATE wc_predictions p JOIN wc_matches m ON m.espn_id = p.match_id
     SET p.boosted=0
     WHERE p.user_id=:u AND p.boosted=1 AND p.match_id <> :mid
       AND m.kickoff_utc >= :a AND m.kickoff_utc < :b",
    ['u'=>$uid, 'mid'=>$matchId, 'a'=>$startUtc, 'b'=>$endUtc]
);
$db->update('wc_predictions', ['boosted'=>1], 'id=:id', ['id'=>$pred['id']]);

out(['ok'=>true,'on'=>true,'cleared'=>$cleared]);
