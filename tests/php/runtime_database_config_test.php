<?php
require_once dirname(__DIR__, 2) . '/includes/RuntimeDatabaseConfig.php';
$path = tempnam(sys_get_temp_dir(), 'cardify-config-test-');
$fails = 0;
function configCheck(string $name, bool $ok): void {
    global $fails;
    if (!$ok) $fails++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n";
}
try {
    $fixture = ['HOST' => '127.0.0.1', 'NAME' => 'synthetic_db', 'USER' => 'synthetic_user', 'PASS' => "synthetic'quote\\slash\"double"];
    $content = "<?php\n";
    foreach ($fixture as $key => $value) $content .= "define('DB_" . $key . "', " . var_export($value, true) . ");\n";
    $content .= "throw new RuntimeException('Configuration must never execute');\n";
    file_put_contents($path, $content);
    configCheck('literal values survive quote and slash escaping without execution', RuntimeDatabaseConfig::read($path) === $fixture);
    $options = RuntimeDatabaseConfig::clientOptions($fixture);
    configCheck('client options quote the synthetic value', strpos($options, 'password="synthetic') !== false && strpos($options, '\\"double') !== false);
    putenv('CARDIFY_DB_PASS=synthetic-runtime-override');
    configCheck('protected environment takes precedence', RuntimeDatabaseConfig::read($path)['PASS'] === 'synthetic-runtime-override');
    putenv('CARDIFY_DB_PASS');
    file_put_contents($path, '<?php define("DB_HOST", getenv("EXTERNAL_SETTING"));');
    $rejected = false;
    try { RuntimeDatabaseConfig::read($path); } catch (RuntimeException $e) { $rejected = true; }
    configCheck('missing or computed configuration fails closed', $rejected);
} finally {
    putenv('CARDIFY_DB_PASS');
    unlink($path);
}
exit($fails ? 1 : 0);
