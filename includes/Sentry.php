<?php
require_once __DIR__ . '/JsonLd.php';
/**
 * Lightweight Sentry client (Cat T action 456).
 *
 * Opt-in: activates only when SENTRY_DSN is defined in config.php.
 * When absent every method is a no-op so the class is safe to call
 * from hot paths without a runtime cost.
 *
 * We avoid Composer / sentry/sentry-php here to keep the Cardify
 * install drop-in (no autoloader change, no Packagist fetch, no SDK
 * version drift). The Store endpoint accepts plain HTTP + JSON, which
 * is all we need for a modest error-reporting use case.
 *
 * Usage:
 *   Sentry::init();                               // from bootstrap
 *   Sentry::captureException($e);                 // in catch blocks
 *   Sentry::captureMessage('thing', 'warning');   // manual events
 */
class Sentry
{
    private static bool $initialized = false;
    private static ?array $dsn = null;

    public static function init(): void
    {
        if (self::$initialized) return;
        self::$initialized = true;

        if (!defined('SENTRY_DSN') || !SENTRY_DSN) return;
        self::$dsn = self::parseDsn(SENTRY_DSN);
        if (!self::$dsn) return;

        // Catch anything that bubbles out of the PHP request so we get a
        // single event per fatal. set_exception_handler wins over the
        // default "uncaught exception" trace dump.
        set_exception_handler(static function (Throwable $e) {
            self::captureException($e);
            // Re-throw so PHP still logs to stderr for aaPanel's tail.
            error_log('Uncaught: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
        });

        // Fatals (E_ERROR / E_PARSE / E_COMPILE_ERROR) bypass the
        // exception handler but surface in the shutdown function.
        register_shutdown_function(static function () {
            $err = error_get_last();
            if (!$err) return;
            if (!in_array($err['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) return;
            self::captureMessage(
                sprintf('Fatal: %s at %s:%d', $err['message'], $err['file'], $err['line']),
                'fatal'
            );
        });
    }

    public static function captureException(Throwable $e, array $extra = []): void
    {
        if (!self::$dsn) return;
        $event = self::baseEvent('error');
        $event['exception'] = ['values' => [[
            'type'  => get_class($e),
            'value' => $e->getMessage(),
            'stacktrace' => ['frames' => self::frames($e->getTrace())],
        ]]];
        if ($extra) $event['extra'] = $extra;
        self::send($event);
    }

    public static function captureMessage(string $message, string $level = 'info', array $extra = []): void
    {
        if (!self::$dsn) return;
        $event = self::baseEvent($level);
        $event['message'] = ['formatted' => $message];
        if ($extra) $event['extra'] = $extra;
        self::send($event);
    }

    /** Emit a minimal loader <script> for the browser SDK if configured. */
    public static function browserBootstrap(): string
    {
        if (!defined('SENTRY_DSN_PUBLIC') || !SENTRY_DSN_PUBLIC) return '';
        $dsn = htmlspecialchars(SENTRY_DSN_PUBLIC, ENT_QUOTES);
        // The nonce, or the block does not run under the policy. json_encode
        // with the script-safe flags, or a DSN holding "</script>" would end
        // the element early, which is exactly how the public digital card
        // executed a name.
        return '<script' . cspNonceAttr() . '>window.__SENTRY_DSN__='
            . json_encode(SENTRY_DSN_PUBLIC, JsonLd::SAFE | JSON_UNESCAPED_SLASHES)
            . ';</script>';
    }

    // --- internals ---

    private static function baseEvent(string $level): array
    {
        $host = defined('APP_HOST') ? APP_HOST : ($_SERVER['HTTP_HOST'] ?? 'cardify.om');
        return [
            'event_id'    => str_replace('-', '', self::uuid4()),
            'timestamp'   => gmdate('Y-m-d\TH:i:s\Z'),
            'platform'    => 'php',
            'level'       => $level,
            'server_name' => $host,
            'release'     => defined('CARDIFY_VERSION') ? CARDIFY_VERSION : 'main',
            'environment' => defined('APP_ENV') ? APP_ENV : 'production',
            'request'     => [
                'url'     => ($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . $host . ($_SERVER['REQUEST_URI'] ?? ''),
                'method'  => $_SERVER['REQUEST_METHOD'] ?? 'GET',
                'headers' => ['User-Agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''],
            ],
        ];
    }

    private static function frames(array $trace): array
    {
        $out = [];
        foreach (array_reverse($trace) as $f) {
            $out[] = [
                'filename' => $f['file'] ?? '(unknown)',
                'lineno'   => $f['line'] ?? 0,
                'function' => ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '<closure>'),
            ];
        }
        return $out;
    }

    private static function send(array $event): void
    {
        if (!self::$dsn) return;
        $url = sprintf(
            '%s://%s/api/%s/store/',
            self::$dsn['scheme'],
            self::$dsn['host'],
            self::$dsn['project_id']
        );
        $auth = 'Sentry sentry_version=7,sentry_key=' . self::$dsn['public_key']
              . ',sentry_client=cardify-php/1.0';
        $payload = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Sentry-Auth: ' . $auth,
            ],
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        @curl_exec($ch);
        curl_close($ch);
    }

    private static function parseDsn(string $dsn): ?array
    {
        // https://PUBLIC@o123.ingest.sentry.io/PROJECT_ID
        if (!preg_match('~^(https?)://([^@]+)@([^/]+)/(\d+)$~', $dsn, $m)) return null;
        return [
            'scheme'     => $m[1],
            'public_key' => $m[2],
            'host'       => $m[3],
            'project_id' => $m[4],
        ];
    }

    private static function uuid4(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
