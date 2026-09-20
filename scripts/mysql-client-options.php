<?php
// The caller supplies a private temporary file. Never print credential values.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
require_once dirname(__DIR__) . '/includes/RuntimeDatabaseConfig.php';
try {
    $target = $argv[1] ?? '';
    if ($target === '' || is_link($target) || !is_file($target)) {
        throw new RuntimeException('A private temporary file is required');
    }
    if (!chmod($target, 0600)) throw new RuntimeException('Cannot restrict the temporary file');
    $config = RuntimeDatabaseConfig::read();
    if (file_put_contents($target, RuntimeDatabaseConfig::clientOptions($config), LOCK_EX) === false) {
        throw new RuntimeException('Cannot write the temporary file');
    }
} catch (Throwable $error) {
    fwrite(STDERR, "Unable to prepare protected MySQL options\n");
    exit(1);
}
