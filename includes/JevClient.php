<?php
/**
 * TypeSafe Jev client. Jev answers typed questions about a text (choice,
 * score, noul = probability of yes). It never writes text.
 *
 * Contract: ask() returns null on ANY failure (no key, timeout, HTTP error,
 * bad shape). Callers then behave exactly as they did before Jev existed.
 *
 * Key: TYPESAFE_API_KEY constant or env, else private/jev.env inside the
 * site (PHP-FPM is confined to the site directory by open_basedir, so the
 * shared /etc/jev file is out of reach). /usr/local/bin/jev-set-key on the
 * VPS rewrites that file when the key changes. JEV_ENABLED=0 switches it off.
 */
class JevClient
{
    private const URL = 'https://api.typesafe.ai/v1/systemone';
    private const MODEL = 'jev-1.13.0';
    private const TIMEOUT_SEC = 8;

    public static function key(): string
    {
        if (defined('TYPESAFE_API_KEY') && TYPESAFE_API_KEY !== '') return (string) TYPESAFE_API_KEY;
        $env = getenv('TYPESAFE_API_KEY');
        if (is_string($env) && $env !== '') return $env;
        $file = dirname(__DIR__) . '/private/jev.env';
        if (is_readable($file)) {
            foreach ((array) @file($file, FILE_IGNORE_NEW_LINES) as $line) {
                if (strpos($line, 'TYPESAFE_API_KEY=') === 0) {
                    return trim(substr($line, 17), " \t\"'");
                }
            }
        }
        return '';
    }

    public static function enabled(): bool
    {
        if (getenv('JEV_ENABLED') === '0' || (defined('JEV_ENABLED') && !JEV_ENABLED)) return false;
        return self::key() !== '';
    }

    /**
     * @param mixed $state     string, or array (sent as JSON object)
     * @param array $questions name => ['type' => 'choice'|'score'|'noul', ...]
     * @return array|null      answers keyed by question name
     */
    public static function ask($state, array $questions, string $label = 'jev'): ?array
    {
        if (!self::enabled() || empty($questions)) return null;
        $payload = json_encode([
            'state' => $state,
            'model' => self::MODEL,
            'questions' => $questions,
        ], JSON_UNESCAPED_UNICODE);
        if ($payload === false) return null;

        $ch = curl_init(self::URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . self::key(),
            ],
            CURLOPT_TIMEOUT => self::TIMEOUT_SEC,
            CURLOPT_CONNECTTIMEOUT => 4,
        ]);
        $t0 = microtime(true);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);

        if ($err || $code !== 200) {
            error_log("[$label] jev failed: " . ($err ?: "http $code"));
            return null;
        }
        $data = json_decode((string) $body, true);
        $answers = $data['answers'] ?? $data;
        if (!is_array($answers)) return null;
        foreach (array_keys($questions) as $k) {
            if (!isset($answers[$k]) || !is_array($answers[$k])) {
                error_log("[$label] jev missing answer $k");
                return null;
            }
        }
        error_log(sprintf('[%s] jev ok %dms %d in', $label, (int) ((microtime(true) - $t0) * 1000), (int) ($data['usage']['input_tokens'] ?? 0)));
        return $answers;
    }
}
