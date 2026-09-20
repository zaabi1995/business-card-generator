<?php
/** Read CLI database configuration without booting the application or migrations. */
final class RuntimeDatabaseConfig
{
    public static function read(?string $path = null): array
    {
        $source = null;
        $result = [];
        foreach (['HOST', 'NAME', 'USER', 'PASS'] as $field) {
            $value = getenv('CARDIFY_DB_' . $field);
            if ($field === 'NAME' && ($value === false || $value === '')) {
                $value = getenv('CARDIFY_DB');
            }
            if ($value === false || $value === '') {
                if ($source === null) {
                    $source = @file_get_contents($path ?? dirname(__DIR__) . '/config.php');
                    if ($source === false) throw new RuntimeException('Runtime database configuration is unavailable');
                }
                $name = 'DB_' . $field;
                // Only literal definitions are supported. Never evaluate config.php.
                $pattern = '/define\s*\(\s*[\'"]' . $name . '[\'"]\s*,\s*(\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*")\s*\)/';
                if (!preg_match($pattern, $source, $match)) {
                    throw new RuntimeException('Runtime database setting is unavailable: ' . $name);
                }
                $literal = $match[1];
                $raw = substr($literal, 1, -1);
                if ($literal[0] === "'") {
                    $value = preg_replace_callback('/\\\\([\\\\\'])/', static function ($m) { return $m[1]; }, $raw);
                } else {
                    if (strpos($raw, '$') !== false) throw new RuntimeException('Use literal runtime database settings');
                    $value = stripcslashes($raw);
                }
            }
            if (strpos($value, "\0") !== false) throw new RuntimeException('Invalid runtime database setting');
            $result[$field] = $value;
        }
        return $result;
    }

    public static function connect(): PDO
    {
        $c = self::read();
        return new PDO('mysql:host=' . $c['HOST'] . ';dbname=' . $c['NAME'] . ';charset=utf8mb4', $c['USER'], $c['PASS'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    public static function clientOptions(array $config): string
    {
        $text = "[client]\n";
        foreach (['host' => 'HOST', 'user' => 'USER', 'password' => 'PASS'] as $key => $field) {
            $value = str_replace(["\\", '"', "\n", "\r"], ["\\\\", '\\"', '\\n', '\\r'], $config[$field]);
            $text .= $key . '="' . $value . "\"\n";
        }
        return $text;
    }
}
