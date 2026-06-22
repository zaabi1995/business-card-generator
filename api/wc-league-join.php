<?php
/**
 * POST /api/wc-league-join.php  {code}
 * Adds the signed-in WC user to a mini-league by its share code. Idempotent:
 * re-joining returns {already:true} with no duplicate row.
 *
 * Auth = the signed wc_auth cookie (WcHub::currentUser). POST-only.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/WcHub.php';

header('Content-Type: application/json; charset=utf-8');
function out($a){ echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

$user = WcHub::currentUser();
if (!$user) out(['ok'=>false,'error'=>'l_err_auth']);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(['ok'=>false,'error'=>'l_err_generic']);

$in   = json_decode(file_get_contents('php://input'), true) ?: [];
$code = (string)($in['code'] ?? '');

$res = WcHub::joinLeague((int)$user['id'], $code);
if (!$res['ok']) out(['ok'=>false,'error'=>$res['error'] ?: 'l_err_generic']);

$l = $res['league'];
out([
    'ok'      => true,
    'already' => (bool)$res['already'],
    'code'    => $l['code'],
    'name'    => $l['name'],
    'board'   => '/wc-league?l=' . rawurlencode($l['code']),
]);
