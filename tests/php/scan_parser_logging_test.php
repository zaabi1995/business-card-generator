<?php
namespace AuditScanParser;

// Run the real parser with an in-memory provider transport. No outbound calls.
class finfo extends \finfo {}
function curl_init($url) { return new \stdClass(); }
function curl_setopt_array($ch, $opts) { $GLOBALS['fixture_payload'] = $opts[CURLOPT_POSTFIELDS]; return true; }
function curl_exec($ch) { return $GLOBALS['fixture_response']; }
function curl_getinfo($ch, $option) { return $GLOBALS['fixture_http']; }
function curl_close($ch) {}

define('OPENROUTER_API_KEY', 'synthetic-local-fixture');
$dir = sys_get_temp_dir() . '/cardify-log-fixture-' . bin2hex(random_bytes(8));
mkdir($dir, 0700);
$source = file_get_contents(dirname(__DIR__, 2) . '/includes/ScanParser.php');
file_put_contents($dir . '/ScanParser.php', '<?php namespace AuditScanParser;' . substr($source, 5));
file_put_contents($dir . '/Database.php', '<?php namespace AuditScanParser; class Database {}');
file_put_contents($dir . '/image.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jJ9sAAAAASUVORK5CYII='));
ini_set('error_log', $dir . '/diagnostics.log');
require $dir . '/ScanParser.php';

$fails = 0;
function check($name, $value) {
    global $fails;
    if (!$value) $fails++;
    echo ($value ? 'PASS ' : 'FAIL ') . $name . "\n";
}
try {
    $marker = 'SYNTHETIC_CONTACT_SHOULD_NOT_ENTER_LOGS';
    $GLOBALS['fixture_response'] = json_encode(['error' => $marker]);
    $GLOBALS['fixture_http'] = 503;
    $result = ScanParser::refine($dir . '/image.png');
    check('provider failure remains visible to caller', $result['error'] === 'api_error_503');
    $GLOBALS['fixture_response'] = json_encode(['choices' => [['message' => ['content' => $marker]]]]);
    $GLOBALS['fixture_http'] = 200;
    $result = ScanParser::refine($dir . '/image.png');
    check('malformed output remains an error', $result['error'] === 'unparseable');
    $logs = file_get_contents($dir . '/diagnostics.log');
    check('provider and model content never enters diagnostics', !str_contains($logs, $marker));
    check('diagnostics preserve useful failure categories', str_contains($logs, '503') && str_contains($logs, 'unparseable'));
    $GLOBALS['fixture_response'] = json_encode(['choices' => [['message' => ['content' => json_encode(['name_en' => 'Synthetic Person'])]]]]);
    $result = ScanParser::refine($dir . '/image.png');
    check('valid authorized parsing still returns the synthetic contact', $result['success'] && $result['parsed']['name_en'] === 'Synthetic Person');
} finally {
    foreach (glob($dir . '/*') as $file) unlink($file);
    rmdir($dir);
}
exit($fails ? 1 : 0);
