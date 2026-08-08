<?php
/**
 * ScanParser: refines a business card photo (plus optional on-device draft)
 * into structured contact JSON via OpenRouter (Claude Haiku 4.5).
 */
require_once __DIR__ . '/Database.php';

class ScanParser {

    const MODEL = 'anthropic/claude-haiku-4.5';
    const API_URL = 'https://openrouter.ai/api/v1/chat/completions';

    // Device-first parsing (product decision 13 Jul 2026): the server never
    // calls an AI API unless this kill-switch is explicitly turned on. No row
    // or any value other than 'on' means OFF.
    public static function serverRefineEnabled(): bool {
        try {
            $row = Database::getInstance()->fetchOne(
                "SELECT setting_value FROM system_settings WHERE setting_key = 'scan_server_refine'"
            );
            return $row && $row['setting_value'] === 'on';
        } catch (Throwable $e) {
            return false;
        }
    }

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
                $phone = [
                    'number' => self::capString($p['number'] ?? ''),
                    'type'   => self::capString($p['type'] ?? ''),
                ];
                // The device parses an extension off the number ("+968 24 556677 Ext. 214")
                // and sends it as its own field. Rebuilding the phone without it silently
                // dropped it here, and because sync overwrites the local parse from the
                // server, the extension vanished on the next pull. Digits only, matching
                // the client's own validation. The key is omitted when there is no
                // extension so a card without one serialises exactly as it always did.
                $ext = preg_replace('/\D/', '', (string)($p['ext'] ?? ''));
                if ($ext !== '') $phone['ext'] = mb_substr($ext, 0, 8);
                $phones[] = $phone;
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
        // The device routes a LinkedIn profile URL to its own field instead of letting it
        // land in `website` on a card that prints no website. `linkedin` is not in
        // emptyParsed(), so array_intersect_key above has already dropped it; read it from
        // the raw draft. Without this the value survives the scan and is then wiped on the
        // next sync pull, because sync overwrites the local parse from the server. The key
        // is omitted when empty so a card without a LinkedIn URL serialises exactly as it
        // always did.
        $linkedin = self::capString(is_scalar($draft['linkedin'] ?? null) ? $draft['linkedin'] : '');
        if ($linkedin !== '') $clean['linkedin'] = $linkedin;
        return $clean;
    }

    private static function capString($v): string {
        return mb_substr(is_scalar($v) ? (string)$v : '', 0, 500);
    }

    // Merge provenance: what a merge kept, what it replaced, and the losing value.
    // The app writes it as JSON and renders it in the user's own language, so the
    // server stores it verbatim and never interprets it.
    //
    // Returns the storable string, or null when the value must not be stored.
    //
    // Oversized input is REJECTED rather than truncated. Note what does and does
    // not protect that: cutting a valid JSON array at the column limit yields one
    // with no closing bracket, and the is_array(json_decode()) guard on the last
    // line WOULD catch that, because it runs on the final string whatever produced
    // it. So a truncating variant of this function does not write a broken value,
    // it writes null, which on UPDATE means "skip the column" and silently drops a
    // history the client believed it had saved. Rejecting is better because it is
    // honest about that, not because it is the only thing standing between us and
    // a corrupt row. (An earlier version of this comment claimed the latter; a
    // mutation that truncated instead of rejecting turned out to be an equivalent
    // mutant, which is what showed the claim was wrong.)
    //
    // The length check also keeps the refusal cheap, no decoding a 64KB+ string,
    // and states the client's side of the contract: it trims oldest entries to fit
    // before sending.
    //
    // Callers decide what null MEANS for them. On INSERT there is no prior value,
    // so null is simply "store nothing". On UPDATE the caller must skip the column
    // rather than write null, or a malformed write would erase a good history.
    public const MERGE_PROVENANCE_MAX = 65535;

    public static function mergeProvenanceOrNull($raw): ?string {
        if (!is_scalar($raw)) return null;
        $s = trim((string)$raw);
        if ($s === '' || strlen($s) > self::MERGE_PROVENANCE_MAX) return null;
        return is_array(json_decode($s, true)) ? $s : null;
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
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => [
                        'url' => 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($imagePath)),
                    ]],
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
                'Authorization: Bearer ' . $apiKey,
                'HTTP-Referer: https://cardify.om',
                'X-Title: Cardify Scan',
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
        $text = $data['choices'][0]['message']['content'] ?? '';
        $json = self::extractJson($text);
        if (!$json) {
            error_log('[ScanParser] unparseable model output: ' . substr($text, 0, 300));
            return ['success' => false, 'parsed' => null, 'error' => 'unparseable'];
        }
        return ['success' => true, 'parsed' => self::sanitizeDraft($json), 'error' => null];
    }

    private static function getApiKey(): ?string {
        if (defined('OPENROUTER_API_KEY') && OPENROUTER_API_KEY !== '') {
            return OPENROUTER_API_KEY;
        }
        return null;
    }
}
