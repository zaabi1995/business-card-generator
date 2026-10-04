<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config.php';
$pdo = Database::getInstance()->getConnection();
$pdo->exec('DELETE FROM logo_download_tickets WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 1 DAY');
$pdo->exec('DELETE FROM logo_member_sessions WHERE expires_at < UTC_TIMESTAMP()');
$pdo->exec("DELETE FROM logo_access_grants WHERE period <> '1970-01-01' AND period < UTC_DATE() - INTERVAL 90 DAY");
$pdo->exec("DELETE FROM logo_access_days WHERE period <> '1970-01-01' AND period < UTC_DATE() - INTERVAL 90 DAY");
echo "Expired logo access records pruned\n";
