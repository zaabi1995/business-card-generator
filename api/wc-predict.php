<?php
/**
 * POST /api/wc-predict.php  {match_id, pick, pred_home?, pred_away?}
 * Saves/updates the signed-in user's prediction. Rejects after kickoff.
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
$pick    = in_array(($in['pick'] ?? ''), ['home','draw','away'], true) ? $in['pick'] : null;
$ph = isset($in['pred_home']) && $in['pred_home']!=='' ? max(0,min(50,(int)$in['pred_home'])) : null;
$pa = isset($in['pred_away']) && $in['pred_away']!=='' ? max(0,min(50,(int)$in['pred_away'])) : null;
if ($matchId==='' || !$pick) out(['ok'=>false,'error'=>'bad_input']);

$db = Database::getInstance();
$m = $db->fetchOne("SELECT espn_id, kickoff_utc FROM wc_matches WHERE espn_id=:i", ['i'=>$matchId]);
if (!$m) out(['ok'=>false,'error'=>'no_match']);

// Lock at kickoff (compare in UTC).
$now = new DateTime('now', new DateTimeZone('UTC'));
$ko  = new DateTime($m['kickoff_utc'], new DateTimeZone('UTC'));
if ($now >= $ko) out(['ok'=>false,'error'=>'locked']);

// If an exact score is given, derive/override the winner pick for consistency.
if ($ph !== null && $pa !== null) {
    $pick = $ph === $pa ? 'draw' : ($ph > $pa ? 'home' : 'away');
}

$existing = $db->fetchOne("SELECT id FROM wc_predictions WHERE user_id=:u AND match_id=:m",
    ['u'=>$user['id'],'m'=>$matchId]);
$data = ['pick'=>$pick,'pred_home'=>$ph,'pred_away'=>$pa,'scored'=>0,'points'=>0];
if ($existing) {
    $db->update('wc_predictions', $data, 'id=:id', ['id'=>$existing['id']]);
} else {
    $data['user_id']=$user['id']; $data['match_id']=$matchId;
    $db->insert('wc_predictions', $data);
}
out(['ok'=>true,'pick'=>$pick,'pred_home'=>$ph,'pred_away'=>$pa]);
