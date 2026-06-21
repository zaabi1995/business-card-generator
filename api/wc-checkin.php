<?php
/**
 * POST /api/wc-checkin.php   (no body)
 * Daily streak check-in for the signed-in WC user.
 *
 * Idempotent by design: the wc_checkins UNIQUE(user_id, checkin_date) index
 * is the single atomic winner. We RESERVE today's row first; only the request
 * that actually inserts it awards points. A second tap the same day hits the
 * duplicate-key path and returns {already:true} with no double-award.
 *
 * Award rules (server-validated):
 *   +1 point every check-in day.
 *   +5 one-time milestone the first time the streak reaches 7.
 * Points land in bonus_points AND points_cache so they survive
 * cron/wc_score.php (which recomputes points_cache = SUM(pred points)+bonus).
 *
 * "Today" is the user's own timezone so the day boundary matches the UI.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/WcHub.php';

header('Content-Type: application/json; charset=utf-8');
function out($a){ echo json_encode($a); exit; }

$user = WcHub::currentUser();
if (!$user) out(['ok'=>false,'error'=>'auth']);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(['ok'=>false,'error'=>'method']);

$db  = Database::getInstance();
$uid = (int)$user['id'];

// Day boundary in the user's timezone.
$tzName = $user['tz'] ?: 'Asia/Muscat';
try { $tz = new DateTimeZone($tzName); } catch (Throwable $e) { $tz = new DateTimeZone('Asia/Muscat'); }
$today     = (new DateTime('now', $tz))->format('Y-m-d');
$yesterday = (new DateTime('now', $tz))->modify('-1 day')->format('Y-m-d');

// 1) RESERVE today's check-in row. The unique index decides the single winner.
try {
    $db->insert('wc_checkins', ['user_id'=>$uid, 'checkin_date'=>$today]);
} catch (Throwable $e) {
    // Duplicate key (already checked in today) or any insert failure: no award.
    if (stripos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), '1062') !== false) {
        out([
            'ok'=>true, 'already'=>true,
            'streak'=>(int)$user['streak_count'],
            'best'=>(int)$user['streak_best'],
            'points'=>(int)$user['points_cache'],
        ]);
    }
    error_log('wc-checkin reserve failed: ' . $e->getMessage());
    out(['ok'=>false, 'error'=>'reserve']);
}

// 2) We won the day. Compute the new streak from last_checkin.
$last       = $user['last_checkin'] ?? null;
$prevStreak = (int)$user['streak_count'];
if ($last === $yesterday)      { $streak = $prevStreak + 1; }  // continued
else                           { $streak = 1; }               // first day or broken streak
$best  = max((int)$user['streak_best'], $streak);

// 3) Award. +1 always; +5 once when the streak first hits 7.
$award = 1;
$milestone = false;
if ($streak === 7) { $award += 5; $milestone = true; }

$db->update('wc_users', [
    'streak_count' => $streak,
    'streak_best'  => $best,
    'last_checkin' => $today,
], 'id=:id', ['id'=>$uid]);

$db->query(
    "UPDATE wc_users SET bonus_points = bonus_points + :a, points_cache = points_cache + :b WHERE id=:id",
    ['a'=>$award, 'b'=>$award, 'id'=>$uid]
);

$points = (int)$user['points_cache'] + $award;
out([
    'ok'=>true, 'already'=>false,
    'streak'=>$streak, 'best'=>$best,
    'award'=>$award, 'milestone'=>$milestone,
    'points'=>$points,
]);
