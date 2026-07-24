<?php
declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

$root = dirname(__DIR__, 2);
$failures = 0;
$server = null;
$serverPipes = [];
$adminPdo = null;
$databaseName = null;
$temporaryRoot = null;

function deletionDbCheck(string $label, bool $condition): void
{
    global $failures;
    echo ($condition ? 'PASS' : 'FAIL') . " $label\n";
    if (!$condition) {
        $failures++;
    }
}

function deletionDbScalar(PDO $pdo, string $sql, array $params = []): mixed
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetchColumn();
}

function deletionDbPost(
    int $port,
    string $token,
    array $payload
): array {
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) {
        throw new RuntimeException('Unable to encode test request');
    }
    $request = curl_init(
        'http://127.0.0.1:' . $port . '/api/scan/delete-account.php'
    );
    if ($request === false) {
        throw new RuntimeException('Unable to create integration request');
    }
    curl_setopt_array($request, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            'Connection: close',
        ],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => 10,
    ]);
    $raw = curl_exec($request);
    if (!is_string($raw)) {
        $message = curl_error($request);
        curl_close($request);
        throw new RuntimeException(
            'Integration HTTP request failed: ' . $message
        );
    }
    $status = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE);
    curl_close($request);
    $decoded = json_decode($raw, true);
    return [
        'status' => $status,
        'body' => is_array($decoded) ? $decoded : [],
        'raw' => $raw,
    ];
}

function deletionDbFreePort(): int
{
    $socket = @stream_socket_server('tcp://127.0.0.1:0', $error, $message);
    if ($socket === false) {
        throw new RuntimeException(
            'Unable to reserve test HTTP port: ' . $message
        );
    }
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    if (
        !is_string($name)
        || preg_match('/:(\d+)$/D', $name, $matches) !== 1
    ) {
        throw new RuntimeException('Unable to determine test HTTP port');
    }
    return (int) $matches[1];
}

function deletionDbWaitForServer($server, int $port): void
{
    $deadline = microtime(true) + 5;
    do {
        $status = proc_get_status($server);
        if (empty($status['running'])) {
            throw new RuntimeException('PHP integration server stopped early');
        }
        $socket = @fsockopen('127.0.0.1', $port, $error, $message, 0.1);
        if (is_resource($socket)) {
            fclose($socket);
            return;
        }
        usleep(25000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('PHP integration server did not start');
}

function deletionDbRemoveTree(string $path): void
{
    if ($path === '' || !file_exists($path)) {
        return;
    }
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    $entries = scandir($path);
    if (is_array($entries)) {
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            deletionDbRemoveTree($path . DIRECTORY_SEPARATOR . $entry);
        }
    }
    @rmdir($path);
}

function deletionDbExecSchema(PDO $pdo): void
{
    $statements = [
        "CREATE TABLE companies (
            id VARCHAR(36) NOT NULL PRIMARY KEY,
            status VARCHAR(20) NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE users (
            id VARCHAR(36) NOT NULL PRIMARY KEY,
            role VARCHAR(30) NOT NULL,
            status VARCHAR(20) NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE employees (
            id VARCHAR(36) NOT NULL PRIMARY KEY,
            company_id VARCHAR(36) NOT NULL,
            status VARCHAR(20) NOT NULL,
            deleted_at DATETIME NULL,
            scan_pro_until DATETIME NULL,
            scan_pro_source VARCHAR(50) NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE scan_accounts (
            id CHAR(36) NOT NULL PRIMARY KEY,
            user_id VARCHAR(36) NULL,
            password_hash VARCHAR(255) NULL,
            status VARCHAR(20) NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE scan_account_memberships (
            account_id CHAR(36) NOT NULL,
            employee_id VARCHAR(36) NOT NULL,
            company_id VARCHAR(36) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (account_id, employee_id)
        ) ENGINE=InnoDB",
        "CREATE TABLE scan_api_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            employee_id VARCHAR(36) NOT NULL,
            account_id CHAR(36) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            label VARCHAR(50) NOT NULL,
            revoked TINYINT(1) NOT NULL DEFAULT 0,
            last_used_at DATETIME NULL,
            UNIQUE KEY uniq_scan_test_token_hash (token_hash)
        ) ENGINE=InnoDB",
        "CREATE TABLE scans (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            employee_id VARCHAR(36) NOT NULL,
            image_path VARCHAR(255) NULL,
            image_path_back VARCHAR(255) NULL,
            shadow_profile_id BIGINT UNSIGNED NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE scan_passes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            employee_id VARCHAR(36) NOT NULL,
            serial_number VARCHAR(100) NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE scan_pass_registrations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            serial_number VARCHAR(100) NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE scan_pass_changes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            serial_number VARCHAR(100) NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE scan_claim_tickets (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            claimed_employee_id VARCHAR(36) NULL,
            shadow_profile_id BIGINT UNSIGNED NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE push_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            employee_id VARCHAR(36) NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE card_designs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            employee_id VARCHAR(36) NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE generated_cards (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            employee_id VARCHAR(36) NOT NULL,
            front_file_path VARCHAR(255) NULL,
            back_file_path VARCHAR(255) NULL,
            front_web_path VARCHAR(255) NULL,
            back_web_path VARCHAR(255) NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE shadow_profiles (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            phone_primary VARCHAR(50) NULL,
            email_primary VARCHAR(255) NULL,
            best_parsed LONGTEXT NULL,
            claim_token VARCHAR(255) NULL,
            claimed_at DATETIME NULL,
            claimed_company_id VARCHAR(36) NULL,
            invite_sent_at DATETIME NULL,
            invited_by_employee_id VARCHAR(36) NULL,
            opted_out TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB",
        "CREATE TABLE scan_pro_receipts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            account_id CHAR(36) NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE scan_company_create_operations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            account_id CHAR(36) NULL,
            company_name VARCHAR(255) NULL,
            requested_slug VARCHAR(255) NULL,
            company_id VARCHAR(36) NULL,
            employee_id VARCHAR(36) NULL,
            status VARCHAR(30) NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE scan_account_entitlements (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            account_id CHAR(36) NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE scan_account_identifiers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            account_id CHAR(36) NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE scan_identity_user_link_audit (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            account_id CHAR(36) NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE scan_identity_migration_audit (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            canonical_account_id CHAR(36) NULL,
            merged_account_id CHAR(36) NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE rate_limits (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            action VARCHAR(64) NOT NULL,
            ip VARCHAR(64) NOT NULL,
            bucket BIGINT NOT NULL,
            count INT UNSIGNED NOT NULL,
            window_sec INT UNSIGNED NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_rate_limit_bucket (action, ip, bucket)
        ) ENGINE=InnoDB",
        "CREATE TABLE scan_account_delete_operations (
            operation_id CHAR(36)
                CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            account_id CHAR(36) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (operation_id),
            KEY idx_scan_account_delete_operation_account
                (account_id, status, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }
}

function deletionDbSeed(
    PDO $pdo,
    array $accounts,
    string $tokenA,
    string $tokenB,
    string $sharedPath,
    string $ownedPathB
): void {
    $company = $pdo->prepare(
        'INSERT INTO companies (id, status) VALUES (?, ?)'
    );
    $employee = $pdo->prepare(
        'INSERT INTO employees
            (id, company_id, status, deleted_at, scan_pro_until, scan_pro_source)
         VALUES (?, ?, ?, NULL, NOW(), ?)'
    );
    $account = $pdo->prepare(
        'INSERT INTO scan_accounts
            (id, user_id, password_hash, status)
         VALUES (?, NULL, ?, ?)'
    );
    $membership = $pdo->prepare(
        'INSERT INTO scan_account_memberships
            (account_id, employee_id, company_id)
         VALUES (?, ?, ?)'
    );
    $apiToken = $pdo->prepare(
        'INSERT INTO scan_api_tokens
            (employee_id, account_id, token_hash, label, revoked)
         VALUES (?, ?, ?, ?, 0)'
    );
    foreach ($accounts as $fixture) {
        $company->execute([$fixture['company_id'], 'active']);
        $employee->execute([
            $fixture['employee_id'],
            $fixture['company_id'],
            'active',
            'subscription',
        ]);
        $account->execute([
            $fixture['account_id'],
            password_hash('integration-only', PASSWORD_DEFAULT),
            'active',
        ]);
        $membership->execute([
            $fixture['account_id'],
            $fixture['employee_id'],
            $fixture['company_id'],
        ]);
    }
    $apiToken->execute([
        $accounts['a']['employee_id'],
        $accounts['a']['account_id'],
        hash('sha256', $tokenA),
        'integration-a',
    ]);
    $apiToken->execute([
        $accounts['b']['employee_id'],
        $accounts['b']['account_id'],
        hash('sha256', $tokenB),
        'integration-b',
    ]);
    $scan = $pdo->prepare(
        'INSERT INTO scans
            (employee_id, image_path, image_path_back, shadow_profile_id)
         VALUES (?, ?, ?, NULL)'
    );
    $scan->execute([
        $accounts['a']['employee_id'],
        $sharedPath,
        null,
    ]);
    $scan->execute([
        $accounts['b']['employee_id'],
        $sharedPath,
        $ownedPathB,
    ]);
    $design = $pdo->prepare(
        'INSERT INTO card_designs (employee_id) VALUES (?)'
    );
    $generated = $pdo->prepare(
        'INSERT INTO generated_cards
            (
                employee_id,
                front_file_path,
                back_file_path,
                front_web_path,
                back_web_path
            )
         VALUES (?, ?, ?, ?, ?)'
    );
    foreach ($accounts as $fixture) {
        $design->execute([$fixture['employee_id']]);
        $generated->execute([
            $fixture['employee_id'],
            '/private/front.png',
            '/private/back.png',
            '/uploads/front.png',
            '/uploads/back.png',
        ]);
    }
}

$databaseHost = getenv('CARDIFY_TEST_MYSQL_HOST') ?: '127.0.0.1';
$databasePort = (int) (getenv('CARDIFY_TEST_MYSQL_PORT') ?: '3306');
$databaseUser = getenv('CARDIFY_TEST_MYSQL_USER') ?: 'root';
$databasePassword = getenv('CARDIFY_TEST_MYSQL_PASSWORD');
$databasePassword = is_string($databasePassword) ? $databasePassword : '';
$adminDsn = sprintf(
    'mysql:host=%s;port=%d;charset=utf8mb4',
    $databaseHost,
    $databasePort
);

try {
    $adminPdo = new PDO(
        $adminDsn,
        $databaseUser,
        $databasePassword,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (Throwable $error) {
    echo 'SKIP isolated MySQL integration unavailable: '
        . $error->getMessage()
        . "\n";
    exit(0);
}

try {
    $databaseName = 'cardify_delete_it_'
        . getmypid()
        . '_'
        . bin2hex(random_bytes(4));
    if (
        preg_match(
            '/^cardify_delete_it_[0-9]+_[0-9a-f]{8}$/D',
            $databaseName
        ) !== 1
    ) {
        throw new RuntimeException('Unsafe integration database name');
    }
    try {
        $adminPdo->exec(
            'CREATE DATABASE `' . $databaseName
                . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
    } catch (Throwable $error) {
        echo 'SKIP isolated MySQL schema privilege unavailable: '
            . $error->getMessage()
            . "\n";
        exit(0);
    }
    $pdo = new PDO(
        $adminDsn . ';dbname=' . $databaseName,
        $databaseUser,
        $databasePassword,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    deletionDbExecSchema($pdo);
    require_once $root
        . '/database/migrations/145_scan_account_deletion_cleanup.php';
    $migrationResult = migration_145_scan_account_deletion_cleanup($pdo);
    deletionDbCheck(
        'migration 145 creates the durable deletion schema',
        !empty($migrationResult['success'])
    );

    $temporaryRoot = sys_get_temp_dir()
        . '/cardify-delete-db-it-'
        . getmypid()
        . '-'
        . bin2hex(random_bytes(4));
    if (!mkdir($temporaryRoot . '/api/scan', 0700, true)) {
        throw new RuntimeException('Unable to create test document root');
    }
    if (!mkdir($temporaryRoot . '/uploads/scans', 0700, true)) {
        throw new RuntimeException('Unable to create test scan root');
    }
    if (!mkdir($temporaryRoot . '/tmp/pdf-vector', 0700, true)) {
        throw new RuntimeException('Unable to create test renderer cache');
    }
    foreach (
        ['delete-account.php', '_request.php', '_ratelimit.php'] as $source
    ) {
        if (
            !copy(
                $root . '/api/scan/' . $source,
                $temporaryRoot . '/api/scan/' . $source
            )
        ) {
            throw new RuntimeException('Unable to copy endpoint fixture');
        }
    }
    $config = "<?php\n"
        . "define('BASE_DIR', __DIR__);\n"
        . "define('INCLUDES_DIR', "
        . var_export($root . '/includes', true)
        . ");\n"
        . "define('UPLOADS_DIR', BASE_DIR . '/uploads');\n"
        . "date_default_timezone_set('UTC');\n"
        . "require_once INCLUDES_DIR . '/Database.php';\n"
        . "\$ok = Database::getInstance()->connect(\n"
        . "    (string) getenv('CARDIFY_IT_DB_HOST'),\n"
        . "    (string) getenv('CARDIFY_IT_DB_NAME'),\n"
        . "    (string) getenv('CARDIFY_IT_DB_USER'),\n"
        . "    (string) getenv('CARDIFY_IT_DB_PASSWORD'),\n"
        . "    (int) getenv('CARDIFY_IT_DB_PORT'),\n"
        . "    'mysql'\n"
        . ");\n"
        . "if (!\$ok) {\n"
        . "    throw new RuntimeException('Integration database unavailable');\n"
        . "}\n";
    if (file_put_contents($temporaryRoot . '/config.php', $config) === false) {
        throw new RuntimeException('Unable to create test configuration');
    }

    $accounts = [
        'a' => [
            'account_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'employee_id' => '11111111-1111-4111-8111-111111111111',
            'company_id' => 'company-a',
        ],
        'b' => [
            'account_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'employee_id' => '22222222-2222-4222-8222-222222222222',
            'company_id' => 'company-b',
        ],
    ];
    $tokenA = rtrim(
        strtr(base64_encode(random_bytes(32)), '+/', '-_'),
        '='
    );
    $tokenB = rtrim(
        strtr(base64_encode(random_bytes(32)), '+/', '-_'),
        '='
    );
    $sharedPath = 'uploads/scans/'
        . $accounts['a']['employee_id']
        . '/aaaaaaaaaaaaaaaaaaaaaaaa.jpg';
    $ownedPathB = 'uploads/scans/'
        . $accounts['b']['employee_id']
        . '/bbbbbbbbbbbbbbbbbbbbbbbb.jpg';
    deletionDbSeed(
        $pdo,
        $accounts,
        $tokenA,
        $tokenB,
        $sharedPath,
        $ownedPathB
    );
    $sharedAbsolute = $temporaryRoot . '/' . $sharedPath;
    $ownedAbsoluteB = $temporaryRoot . '/' . $ownedPathB;
    if (
        !mkdir(dirname($sharedAbsolute), 0700, true)
        || !mkdir(dirname($ownedAbsoluteB), 0700, true)
    ) {
        throw new RuntimeException('Unable to create owned scan directories');
    }
    file_put_contents($sharedAbsolute, 'shared private scan');
    file_put_contents($ownedAbsoluteB, 'private scan b');
    foreach ($accounts as $key => $fixture) {
        $base = $temporaryRoot . '/tmp/pdf-vector/' . $key;
        file_put_contents($base . '.pdf', 'private rendered card');
        file_put_contents(
            $base . '.meta',
            json_encode(
                ['employee_id' => $fixture['employee_id']],
                JSON_UNESCAPED_SLASHES
            )
        );
    }

    $port = deletionDbFreePort();
    $serverLog = $temporaryRoot . '/server.log';
    $environment = array_merge($_ENV, [
        'CARDIFY_IT_DB_HOST' => $databaseHost,
        'CARDIFY_IT_DB_PORT' => (string) $databasePort,
        'CARDIFY_IT_DB_NAME' => $databaseName,
        'CARDIFY_IT_DB_USER' => $databaseUser,
        'CARDIFY_IT_DB_PASSWORD' => $databasePassword,
    ]);
    $server = proc_open(
        [
            PHP_BINARY,
            '-d',
            'display_errors=0',
            '-d',
            'error_reporting=24575',
            '-S',
            '127.0.0.1:' . $port,
            '-t',
            $temporaryRoot,
        ],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $serverLog, 'a'],
            2 => ['file', $serverLog, 'a'],
        ],
        $serverPipes,
        $temporaryRoot,
        $environment
    );
    if (!is_resource($server)) {
        throw new RuntimeException('Unable to start PHP integration server');
    }
    deletionDbWaitForServer($server, $port);

    $operationA = 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa';
    $pdo->exec(
        "CREATE TRIGGER scan_delete_rollback_guard
         BEFORE DELETE ON scans
         FOR EACH ROW
         BEGIN
             IF OLD.employee_id = '"
                . $accounts['a']['employee_id']
                . "' THEN
                 SIGNAL SQLSTATE '45000'
                     SET MESSAGE_TEXT = 'forced integration rollback';
             END IF;
         END"
    );
    $rollbackResponse = deletionDbPost(
        $port,
        $tokenA,
        ['confirm' => true, 'operation_id' => $operationA]
    );
    deletionDbCheck(
        'a mid-delete database error returns a server failure',
        $rollbackResponse['status'] === 500
            && ($rollbackResponse['body']['error'] ?? null) === 'server_error'
    );
    deletionDbCheck(
        'a failed deletion rolls back account data and operation queues',
        (int) deletionDbScalar(
            $pdo,
            'SELECT COUNT(*) FROM scan_accounts WHERE id = ?',
            [$accounts['a']['account_id']]
        ) === 1
            && (int) deletionDbScalar(
                $pdo,
                'SELECT COUNT(*) FROM scans WHERE employee_id = ?',
                [$accounts['a']['employee_id']]
            ) === 1
            && (int) deletionDbScalar(
                $pdo,
                'SELECT COUNT(*) FROM card_designs WHERE employee_id = ?',
                [$accounts['a']['employee_id']]
            ) === 1
            && (int) deletionDbScalar(
                $pdo,
                'SELECT COUNT(*) FROM scan_account_delete_operations
                 WHERE operation_id = ?',
                [$operationA]
            ) === 0
            && (int) deletionDbScalar(
                $pdo,
                'SELECT COUNT(*) FROM scan_account_delete_files'
            ) === 0
            && (int) deletionDbScalar(
                $pdo,
                'SELECT COUNT(*)
                 FROM scan_account_delete_render_invalidations'
            ) === 0
            && file_exists($sharedAbsolute)
    );
    $pdo->exec('DROP TRIGGER scan_delete_rollback_guard');

    $deleteA = deletionDbPost(
        $port,
        $tokenA,
        ['confirm' => true, 'operation_id' => $operationA]
    );
    deletionDbCheck(
        'Build 51 deletion commits with its supplied operation identifier',
        $deleteA['status'] === 200
            && !empty($deleteA['body']['deletion_confirmed'])
            && ($deleteA['body']['operation_id'] ?? null) === $operationA
            && ($deleteA['body']['account_id'] ?? null)
                === $accounts['a']['account_id']
    );
    deletionDbCheck(
        'deleting account A preserves account B and shared company records',
        (int) deletionDbScalar(
            $pdo,
            'SELECT COUNT(*) FROM scan_accounts WHERE id = ?',
            [$accounts['a']['account_id']]
        ) === 0
            && (int) deletionDbScalar(
                $pdo,
                'SELECT COUNT(*) FROM employees WHERE id = ?',
                [$accounts['a']['employee_id']]
            ) === 1
            && (int) deletionDbScalar(
                $pdo,
                'SELECT COUNT(*) FROM companies WHERE id = ?',
                [$accounts['a']['company_id']]
            ) === 1
            && (int) deletionDbScalar(
                $pdo,
                'SELECT COUNT(*) FROM scan_accounts WHERE id = ?',
                [$accounts['b']['account_id']]
            ) === 1
            && (int) deletionDbScalar(
                $pdo,
                'SELECT COUNT(*) FROM scan_api_tokens WHERE account_id = ?',
                [$accounts['b']['account_id']]
            ) === 1
            && (int) deletionDbScalar(
                $pdo,
                'SELECT COUNT(*) FROM scans WHERE employee_id = ?',
                [$accounts['b']['employee_id']]
            ) === 1
    );
    deletionDbCheck(
        'a path referenced by account B remains pending and on disk',
        deletionDbScalar(
            $pdo,
            'SELECT status FROM scan_account_delete_files
             WHERE operation_id = ?',
            [$operationA]
        ) === 'waiting_reference'
            && file_exists($sharedAbsolute)
    );
    deletionDbCheck(
        'account A renderer state and private cache are invalidated',
        deletionDbScalar(
            $pdo,
            'SELECT front_file_path FROM generated_cards
             WHERE employee_id = ?',
            [$accounts['a']['employee_id']]
        ) === null
            && !file_exists($temporaryRoot . '/tmp/pdf-vector/a.meta')
            && !file_exists($temporaryRoot . '/tmp/pdf-vector/a.pdf')
            && file_exists($temporaryRoot . '/tmp/pdf-vector/b.meta')
    );

    $wrongTokenReplay = deletionDbPost(
        $port,
        $tokenB,
        ['confirm' => true, 'operation_id' => $operationA]
    );
    deletionDbCheck(
        'another account token cannot replay or claim a completed operation',
        $wrongTokenReplay['status'] === 409
            && ($wrongTokenReplay['body']['error'] ?? null)
                === 'operation_owner_conflict'
            && !array_key_exists('account_id', $wrongTokenReplay['body'])
            && !array_key_exists(
                'deleted_employee_ids',
                $wrongTokenReplay['body']
            )
            && (int) deletionDbScalar(
                $pdo,
                'SELECT COUNT(*) FROM scan_accounts WHERE id = ?',
                [$accounts['b']['account_id']]
            ) === 1
    );

    $build51Replay = deletionDbPost(
        $port,
        $tokenA,
        ['confirm' => true, 'operation_id' => $operationA]
    );
    deletionDbCheck(
        'Build 51 can confirm the committed operation after token deletion',
        $build51Replay['status'] === 200
            && ($build51Replay['body']['operation_id'] ?? null)
                === $operationA
            && ($build51Replay['body']['account_id'] ?? null)
                === $accounts['a']['account_id']
    );

    chmod(dirname($ownedAbsoluteB), 0500);
    $deleteBPending = deletionDbPost(
        $port,
        $tokenB,
        ['confirm' => true]
    );
    $operationB = (string) (
        $deleteBPending['body']['operation_id'] ?? ''
    );
    deletionDbCheck(
        'a transient unlink failure returns retryable cleanup status',
        $deleteBPending['status'] === 503
            && ($deleteBPending['body']['error'] ?? null)
                === 'deletion_cleanup_pending'
            && preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-'
                    . '[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
                $operationB
            ) === 1
            && file_exists($ownedAbsoluteB)
            && deletionDbScalar(
                $pdo,
                'SELECT status FROM scan_account_delete_files
                 WHERE operation_id = ? AND path_hash = ?',
                [$operationB, hash('sha256', $ownedPathB)]
            ) === 'failed'
    );
    deletionDbCheck(
        'the account deletion remains committed while file cleanup retries',
        (int) deletionDbScalar(
            $pdo,
            'SELECT COUNT(*) FROM scan_accounts WHERE id = ?',
            [$accounts['b']['account_id']]
        ) === 0
            && deletionDbScalar(
                $pdo,
                'SELECT status FROM scan_account_delete_operations
                 WHERE operation_id = ?',
                [$operationB]
            ) === 'completed'
            && deletionDbScalar(
                $pdo,
                'SELECT front_file_path FROM generated_cards
                 WHERE employee_id = ?',
                [$accounts['b']['employee_id']]
            ) === null
            && !file_exists($temporaryRoot . '/tmp/pdf-vector/b.meta')
            && !file_exists($temporaryRoot . '/tmp/pdf-vector/b.pdf')
    );

    chmod(dirname($ownedAbsoluteB), 0700);
    $build50Retry = deletionDbPost(
        $port,
        $tokenB,
        ['confirm' => true]
    );
    deletionDbCheck(
        'Build 50 retries without an operation identifier after token deletion',
        $build50Retry['status'] === 200
            && ($build50Retry['body']['operation_id'] ?? null) === $operationB
            && ($build50Retry['body']['account_id'] ?? null)
                === $accounts['b']['account_id']
            && !file_exists($ownedAbsoluteB)
            && deletionDbScalar(
                $pdo,
                'SELECT status FROM scan_account_delete_files
                 WHERE operation_id = ? AND path_hash = ?',
                [$operationB, hash('sha256', $ownedPathB)]
            ) === 'completed'
    );

    if (!defined('BASE_DIR')) {
        define('BASE_DIR', $temporaryRoot);
    }
    require_once $root . '/includes/Database.php';
    require_once $root . '/includes/ScanAccountDeletionCleanup.php';
    $database = Database::getInstance();
    $database->connect(
        $databaseHost,
        $databaseName,
        $databaseUser,
        $databasePassword,
        $databasePort,
        'mysql'
    );
    $backlog = ScanAccountDeletionCleanup::processBacklog($database, 10);
    deletionDbCheck(
        'the shared file is removed after its final account reference is gone',
        ($backlog['waiting_reference'] ?? -1) === 0
            && deletionDbScalar(
                $pdo,
                'SELECT status FROM scan_account_delete_files
                 WHERE operation_id = ? AND path_hash = ?',
                [$operationA, hash('sha256', $sharedPath)]
            ) === 'completed'
            && !file_exists($sharedAbsolute)
    );
    deletionDbCheck(
        'a cross-owner legacy path is retained only as hash evidence',
        deletionDbScalar(
            $pdo,
            'SELECT status FROM scan_account_delete_files
             WHERE operation_id = ? AND path_hash = ?',
            [$operationB, hash('sha256', $sharedPath)]
        ) === 'quarantined'
            && deletionDbScalar(
                $pdo,
                'SELECT relative_path FROM scan_account_delete_files
                 WHERE operation_id = ? AND path_hash = ?',
                [$operationB, hash('sha256', $sharedPath)]
            ) === ''
    );

    $pdo->exec(
        "UPDATE scan_account_delete_operations
         SET updated_at = DATE_SUB(NOW(), INTERVAL 31 DAY)"
    );
    $purge = ScanAccountDeletionCleanup::purgeExpiredTombstones(
        $database,
        30,
        100
    );
    deletionDbCheck(
        'expired replay tombstones are purged after cleanup completion',
        ($purge['selected'] ?? -1) === 2
            && ($purge['deleted'] ?? -1) === 2
            && (int) deletionDbScalar(
                $pdo,
                'SELECT COUNT(*) FROM scan_account_delete_operations'
            ) === 0
            && (int) deletionDbScalar(
                $pdo,
                'SELECT COUNT(*) FROM scan_account_delete_operations
                 WHERE confirmation_token_hash IS NOT NULL'
            ) === 0
    );
} catch (Throwable $error) {
    $failures++;
    echo 'FAIL integration test exception: ' . $error->getMessage() . "\n";
    if (is_string($temporaryRoot)) {
        $logPath = $temporaryRoot . '/server.log';
        if (is_file($logPath)) {
            $serverLog = file_get_contents($logPath);
            if (is_string($serverLog) && $serverLog !== '') {
                echo "SERVER LOG\n" . $serverLog;
            }
        }
    }
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if (
        $adminPdo instanceof PDO
        && is_string($databaseName)
        && preg_match(
            '/^cardify_delete_it_[0-9]+_[0-9a-f]{8}$/D',
            $databaseName
        ) === 1
    ) {
        try {
            $adminPdo->exec('DROP DATABASE IF EXISTS `' . $databaseName . '`');
        } catch (Throwable $ignored) {
        }
    }
    if (is_string($temporaryRoot)) {
        @chmod($temporaryRoot . '/uploads/scans', 0700);
        deletionDbRemoveTree($temporaryRoot);
    }
}

echo $failures === 0 ? "ALL PASS\n" : $failures . " FAILED\n";
exit($failures === 0 ? 0 : 1);
