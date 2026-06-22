<?php
/**
 * POST /api/wc-league-create.php  {name}
 * Creates a mini-league owned by the signed-in WC user and auto-joins them.
 *
 * Auth = the signed wc_auth cookie (WcHub::currentUser), the same standard the
 * other wc api/ endpoints use. POST-only (state-changing). Caps leagues-per-user.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/WcHub.php';

header('Content-Type: application/json; charset=utf-8');
function out($a){ echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

$user = WcHub::currentUser();
if (!$user) out(['ok'=>false,'error'=>'l_err_auth']);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(['ok'=>false,'error'=>'l_err_generic']);

$in   = json_decode(file_get_contents('php://input'), true) ?: [];
$name = trim((string)($in['name'] ?? ''));

$res = WcHub::createLeague((int)$user['id'], $name);
if (!$res['ok']) out(['ok'=>false,'error'=>$res['error'] ?: 'l_err_generic']);

$l = $res['league'];
out([
    'ok'    => true,
    'code'  => $l['code'],
    'name'  => $l['name'],
    'url'   => WcHub::leagueShareUrl($l['code']),
    'board' => '/wc-league?l=' . rawurlencode($l['code']),
]);
