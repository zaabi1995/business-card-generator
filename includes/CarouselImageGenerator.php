<?php
/**
 * CarouselImageGenerator
 * Generates 7 cinematic photos via Gemini 2.5 Flash Image.
 * Runs all 7 calls in parallel via curl_multi for speed.
 * Returns array of 7 data URLs (data:image/png;base64,...).
 */
class CarouselImageGenerator {
    private const MODEL = 'gemini-2.5-flash-image';
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public static function generate(array $prompts): array {
        if (count($prompts) !== 7) {
            throw new RuntimeException('Expected 7 image prompts, got ' . count($prompts));
        }
        $apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : getenv('GEMINI_API_KEY');
        if (!$apiKey) {
            throw new RuntimeException('GEMINI_API_KEY not configured');
        }

        $url = self::ENDPOINT . self::MODEL . ':generateContent?key=' . urlencode($apiKey);

        $mh = curl_multi_init();
        $handles = [];
        foreach ($prompts as $i => $prompt) {
            $payload = [
                'contents' => [[
                    'parts' => [['text' => self::wrapPrompt($prompt)]],
                ]],
                'generationConfig' => [
                    'responseModalities' => ['IMAGE'],
                ],
            ];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 90,
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$i] = $ch;
        }

        $active = null;
        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) curl_multi_select($mh, 30);
        } while ($active && $status === CURLM_OK);

        $results = array_fill(0, 7, null);
        $failures = [];
        foreach ($handles as $i => $ch) {
            $res = curl_multi_getcontent($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch);

            if ($code !== 200) {
                $failures[] = "Slide " . ($i + 1) . ": HTTP $code " . substr($res, 0, 200);
                continue;
            }
            $data = json_decode($res, true);
            $parts = $data['candidates'][0]['content']['parts'] ?? [];
            $b64 = null;
            foreach ($parts as $p) {
                if (isset($p['inlineData']['data'])) { $b64 = $p['inlineData']['data']; break; }
                if (isset($p['inline_data']['data'])) { $b64 = $p['inline_data']['data']; break; }
            }
            if (!$b64) {
                $failures[] = "Slide " . ($i + 1) . ": no image data in response";
                continue;
            }
            $results[$i] = 'data:image/png;base64,' . $b64;
        }
        curl_multi_close($mh);

        if ($failures) {
            throw new RuntimeException("Gemini failures: " . implode(' | ', $failures));
        }
        return $results;
    }

    private static function wrapPrompt(string $p): string {
        return "Generate a single cinematic editorial photograph for a LinkedIn carousel slide. 1080x1350 vertical. "
             . "Consistent carousel aesthetic: warm amber + deep shadow color grade, shallow depth of field, moody but premium. "
             . "NO text, NO letters, NO logos, NO watermarks, NO UI elements. "
             . "Leave negative space in the bottom third for text overlay. "
             . "Subject and scene: " . $p;
    }
}
