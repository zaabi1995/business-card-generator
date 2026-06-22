<?php
/** POST /api/wc-settings-save.php {name, language, tz} - update own prefs. */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/WcHub.php';
header('Content-Type: application/json; charset=utf-8');
function out($a){ echo json_encode($a); exit; }

$user = WcHub::currentUser();
if (!$user) out(['ok'=>false,'error'=>'auth']);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(['ok'=>false,'error'=>'method']);

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$name = trim((string)($in['name'] ?? ''));
$lang = WcHub::lang((string)($in['language'] ?? 'en'));
$tz   = (string)($in['tz'] ?? 'Asia/Muscat');
if ($name === '' || mb_strlen($name) > 120) out(['ok'=>false,'error'=>'name']);
if (!in_array($tz, timezone_identifiers_list(), true)) $tz = 'Asia/Muscat';
$nhour = max(0, min(23, (int)($in['notify_hour'] ?? ($user['notify_hour'] ?? 10))));
$nresults = !empty($in['notify_results']) ? 1 : 0;

Database::getInstance()->update('wc_users',
    ['name'=>$name,'language'=>$lang,'tz'=>$tz,'notify_hour'=>$nhour,'notify_results'=>$nresults], 'id=:id', ['id'=>$user['id']]);
out(['ok'=>true]);
