<?php
/**
 * ScanParser: refines a business card photo (plus optional on-device draft)
 * into structured contact JSON via the Anthropic Messages API.
 */
require_once __DIR__ . '/Database.php';

class ScanParser {

    const MODEL = 'claude-haiku-4-5-20251001';
    const API_URL = 'https://api.anthropic.com/v1/messages';

    public static function emptyParsed(): array {
        return [
            'name_en' => '', 'name_ar' => '', 'title_en' => '', 'title_ar' => '',
            'company_en' => '', 'company_ar' => '',
            'phones' => [], 'emails' => [], 'website' => '',
            'address_en' => '', 'address_ar' => '', 'confidence' => [],
        ];
    }

    // Sanitizes an untrusted device-side draft parse to the canonical shape:
    // unknown keys dropped, strings capped, phones/emails bounded and forced
    // to their expected structures. Used by the upload endpoint when refine()
    // fails and the draft becomes the stored parse; reusable by later tasks.
    public static function sanitizeDraft(array $draft): array {
        $clean = array_merge(self::emptyParsed(), array_intersect_key($draft, self::emptyParsed()));
        foreach (['name_en', 'name_ar', 'title_en', 'title_ar', 'company_en', 'company_ar',
                  'website', 'address_en', 'address_ar'] as $k) {
            $clean[$k] = self::capString($clean[$k]);
        }
        $phones = [];
        if (is_array($clean['phones'])) {
            foreach (array_slice($clean['phones'], 0, 10) as $p) {
                if (!is_array($p)) continue;
                $phones[] = [
                    'number' => self::capString($p['number'] ?? ''),
                    'type'   => self::capString($p['type'] ?? ''),
                ];
            }
        }
        $clean['phones'] = $phones;
        $emails = [];
        if (is_array($clean['emails'])) {
            foreach (array_slice($clean['emails'], 0, 10) as $e) {
                if (is_scalar($e)) $emails[] = self::capString($e);
            }
        }
        $clean['emails'] = $emails;
        $clean['confidence'] = is_array($clean['confidence']) ? $clean['confidence'] : [];
        return $clean;
    }

    private static function capString($v): string {
        return mb_substr(is_scalar($v) ? (string)$v : '', 0, 500);
    }

    public static function extractJson(string $modelText): ?array {
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $modelText, $m)) {
            $modelText = $m[1];
        }
        $start = strpos($modelText, '{');
        $end = strrpos($modelText, '}');
        if ($start === false || $end === false || $end <= $start) return null;
        $decoded = json_decode(substr($modelText, $start, $end - $start + 1), true);
        return is_array($decoded) ? $decoded : null;
    }

    public static function refine(string $imagePath, ?array $deviceDraft = null): array {
        $apiKey = self::getApiKey();
        if (!$apiKey) return ['success' => false, 'parsed' => null, 'error' => 'no_api_key'];
        if (!is_readable($imagePath)) return ['success' => false, 'parsed' => null, 'error' => 'image_unreadable'];

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($imagePath);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return ['success' => false, 'parsed' => null, 'error' => 'bad_mime'];
        }

        $prompt = "Extract the contact from this business card photo. Cards here are often bilingual Arabic/English; pair the two sides of the same person. Return ONLY a JSON object with exactly these keys: name_en, name_ar, title_en, title_ar, company_en, company_ar, phones (array of {number, type} where type is mobile|work|fax and numbers are E.164 with country code, default +968), emails (array), website, address_en, address_ar, confidence (object mapping any uncertain field name to 0..1). Empty string or empty array when absent.";
        if ($deviceDraft) {
            $prompt .= " A device-side draft parse follows; correct and complete it: " . json_encode($deviceDraft, JSON_UNESCAPED_UNICODE);
        }

        $payload = json_encode([
            'model' => self::MODEL,
            'max_tokens' => 1024,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'image', 'source' => [
                        'type' => 'base64', 'media_type' => $mime,
                        'data' => base64_encode(file_get_contents($imagePath)),
                    ]],
                    ['type' => 'text', 'text' => $prompt],
                ],
            ]],
        ]);

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $code !== 200) {
            error_log('[ScanParser] API error http=' . $code . ' body=' . substr((string)$resp, 0, 300));
            return ['success' => false, 'parsed' => null, 'error' => 'api_error_' . $code];
        }
        $data = json_decode($resp, true);
        $text = $data['content'][0]['text'] ?? '';
        $json = self::extractJson($text);
        if (!$json) {
            error_log('[ScanParser] unparseable model output: ' . substr($text, 0, 300));
            return ['success' => false, 'parsed' => null, 'error' => 'unparseable'];
        }
        return ['success' => true, 'parsed' => self::sanitizeDraft($json), 'error' => null];
    }

    private static function getApiKey(): ?string {
        if (defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY !== '') {
            return ANTHROPIC_API_KEY;
        }
        return null;
    }
}
