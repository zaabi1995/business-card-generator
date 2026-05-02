<?php
/**
 * AI-assisted binding classifier for the PDF import wizard.
 *
 * After the parser detects text blocks on a card, this class asks Qwen 3.6
 * (via OpenRouter) to label every block as one of the typed Cardify field
 * keys (name_en, name_ar, position_en, position_ar, mobile, phone, email,
 * website, address_en, address_ar, company_name, tagline) or 'static' for
 * decorative copy that should never be substituted per-employee.
 *
 * The model receives every block at once with neighbour context (lines on
 * the same card) and a fixed example bank, so it can disambiguate cases the
 * regex heuristic misses, e.g.:
 *   - "Bousher, Muscat, Sultanate of Oman"  -> address_en
 *   - "An Omantel Company"                  -> static (brand tagline)
 *   - "Founder & CEO"                       -> position_en
 *   - Uppercase tracking-spaced "P H O N E" -> static (label, not value)
 *
 * Falls back to the parser's own suggestion when:
 *   - OPENROUTER_API_KEY is not configured
 *   - the request times out / errors
 *   - the model returns malformed JSON or an unknown label for a block
 *
 * The fallback is silent so a missing API key never breaks import. We log
 * to error_log for observability but always return a usable suggestion map.
 */
class AIBindingClassifier
{
    // qwen3.6-flash classifies in ~3-6s vs qwen3.6-plus 15-30s. Classification
    // doesn't need -plus depth, and the wizard upload UX shouldn't make
    // the user stare at a spinner for half a minute.
    private const MODEL_DEFAULT = 'qwen/qwen3.6-flash';
    private const TIMEOUT_SEC   = 45;
    // Cardify-side typed keys + the universal escape hatches.
    private const VALID = [
        'name_en','name_ar',
        'position_en','position_ar',
        'company_name','tagline',
        'mobile','phone','fax',
        'email','website',
        'address_en','address_ar',
        'static','skip',
    ];

    /**
     * Classify every block on every page using Qwen 3.6.
     *
     * @param array $pages parser-output pages with 'page_number','side','blocks'
     *                     where each block has 'id','detected_text','x','y','width','height'
     * @return array  ['by_block_id' => ['block_0' => 'name_en', ...], 'used_ai' => bool, 'error' => ?string]
     */
    public static function classify(array $pages): array
    {
        if (!self::isConfigured()) {
            return ['by_block_id' => [], 'used_ai' => false, 'error' => 'no_api_key'];
        }

        // Build a compact prompt: one block per line, prefixed with id and
        // page side, so the model can use spatial neighbours as context.
        $lines = [];
        foreach ($pages as $page) {
            $sideTag = ($page['side'] ?? 'front') === 'back' ? 'BACK' : 'FRONT';
            foreach (($page['blocks'] ?? []) as $b) {
                $text = trim((string)($b['detected_text'] ?? ''));
                if ($text === '') continue;
                // Keep lines short to fit within the model's context window.
                $clip = mb_substr($text, 0, 140);
                $lines[] = sprintf('%s | %s | %s', $b['id'], $sideTag, $clip);
            }
        }
        if (empty($lines)) {
            return ['by_block_id' => [], 'used_ai' => false, 'error' => 'no_blocks'];
        }

        $userPrompt = "Classify each text block on this business card. Return ONLY a JSON object mapping block_id -> field_label. No prose, no markdown.\n\nBlocks (block_id | side | text):\n" . implode("\n", $lines);

        $resp = self::callQwen(self::systemPrompt(), $userPrompt);
        if (!$resp['ok']) {
            error_log('[AIBindingClassifier] qwen call failed: ' . $resp['error']);
            return ['by_block_id' => [], 'used_ai' => false, 'error' => $resp['error']];
        }

        $parsed = self::parseModelJson($resp['content']);
        if ($parsed === null) {
            error_log('[AIBindingClassifier] could not parse model response: ' . substr($resp['content'], 0, 300));
            return ['by_block_id' => [], 'used_ai' => false, 'error' => 'bad_json'];
        }

        // Filter to known labels only so we never silently introduce a binding
        // the rest of the system doesn't recognise.
        $clean = [];
        foreach ($parsed as $blockId => $label) {
            if (!is_string($blockId) || !is_string($label)) continue;
            $label = strtolower(trim($label));
            if (in_array($label, self::VALID, true)) {
                $clean[$blockId] = $label;
            }
        }
        return ['by_block_id' => $clean, 'used_ai' => true, 'error' => null];
    }

    public static function isConfigured(): bool
    {
        return defined('OPENROUTER_API_KEY') && OPENROUTER_API_KEY !== '';
    }

    private static function systemPrompt(): string
    {
        // The example bank is intentionally large + bilingual so the model
        // sees Omani-real cards (Arabic + English mixed, gov+corporate style)
        // and learns to distinguish labels ("PHONE") from values ("+968...").
        return <<<'SYS'
You are a precise classifier that labels text on a business card with one of these field types:

  name_en        Person's name in English (e.g., "Mohammed Al Adawi", "Ali Al-Zaabi")
  name_ar        Person's name in Arabic  (e.g., "محمد العدوي", "علي الزعابي")
  position_en    Job title in English     (e.g., "Founding Partner", "CEO", "Senior Engineer", "Head of Marketing")
  position_ar    Job title in Arabic      (e.g., "الشريك المؤسس", "الرئيس التنفيذي", "مهندس أول")
  company_name   Company / org name       (e.g., "Hosn AI Services", "Omantel", "حصن لخدمات الذكاء الاصطناعي")
  tagline        Brand tagline / slogan   (e.g., "Sovereign AI, hosted inside your organisation", "ذكاء اصطناعي سيادي")
  mobile         Mobile phone number      (e.g., "+968 9214 4404", "+968 7161 6161", "+971 50 123 4567")
  phone          Landline phone           (e.g., "+968 2456 7890", "T: +968 24...")
  fax            Fax number               (e.g., "F: +968 ...")
  email          Email address            (e.g., "mohammed@hosn.om", "info@cardify.om")
  website        URL                      (e.g., "hosn.om", "https://cardify.om", "www.bhd.om")
  address_en     Postal address (EN)      (e.g., "Bousher, Muscat, Sultanate of Oman", "PO Box 1234, Muscat 121")
  address_ar     Postal address (AR)      (e.g., "بوشر، مسقط، سلطنة عُمان")
  static         A label, decorative tagline, brand line, or social handle that should NEVER be replaced per-employee. Examples: "PHONE", "EMAIL", "MOBILE", "P H O N E" (label, not value), "An Omantel Company", "@hosn.om", "بريد", "هاتف", "Follow us", "Trusted by leaders since 1995"
  skip           Pure decoration with no text meaning (rare; only if the text is meaningless symbols)

CLASSIFICATION RULES:

1. FIELD LABELS vs VALUES: Words like "PHONE", "EMAIL", "MOBILE", "ADDRESS", "WEBSITE", "FAX" — and their tracking-spaced variants ("P H O N E", "E M A I L") — and Arabic equivalents ("هاتف", "بريد", "موقع", "عنوان", "فاكس") are LABELS. Always classify as 'static'. Only the actual phone number / email / URL gets the typed binding.

2. NAMES vs POSITIONS: A name is usually 2-4 words, capitalised, no digits, no occupation words. A position contains words like CEO, Director, Manager, Officer, Engineer, Founder, Partner, President, Lead, Head, Chief, Principal, Specialist, Consultant, Architect, Analyst, Owner, Founder, مدير, رئيس, مؤسس, مهندس, شريك, مستشار.

3. COMPANY NAME vs TAGLINE: A company name is short (1-5 words), often ends with Ltd/LLC/Group/Co/SAOC/شركة/مجموعة/ش م م. A tagline is a longer descriptive phrase ("hosted inside your organisation", "sovereign AI", "design that delivers", "ذكاء اصطناعي سيادي").

4. BILINGUAL CARDS: Use the script of the text itself. Arabic script -> *_ar variant. Latin script -> *_en variant. Don't infer language from the side of the card.

5. ADDRESS: Multi-part location with city, country, district, or PO Box. "Bousher, Muscat, Sultanate of Oman" is address_en. "بوشر، مسقط، سلطنة عُمان" is address_ar. Just "Muscat" alone with nothing else is too thin — treat as 'static' unless clearly intended as the address.

6. WEBSITE: Anything that looks like a domain (contains a dot and ends in .om/.com/.co/.io/.ai/.org/.net) — even without protocol. "hosn.om" is website. "info@hosn.om" is email (the @ wins).

7. WHEN UNSURE: prefer 'static' over wrong typed binding. A wrong binding silently corrupts every employee card; 'static' just keeps the original text.

OUTPUT FORMAT: a single JSON object, no prose, no markdown fences. Example:
{"block_0":"name_en","block_1":"position_en","block_2":"static","block_3":"phone","block_4":"email","block_5":"static","block_6":"address_en","block_7":"website"}
SYS;
    }

    private static function parseModelJson(string $raw): ?array
    {
        // Models sometimes wrap JSON in ```json ... ``` fences; strip them.
        $s = trim($raw);
        $s = preg_replace('/^```(?:json)?\s*/i', '', $s);
        $s = preg_replace('/```\s*$/i', '', $s);
        // Find the first { and last } in case there's stray prose.
        $first = strpos($s, '{');
        $last  = strrpos($s, '}');
        if ($first === false || $last === false || $last <= $first) return null;
        $jsonText = substr($s, $first, $last - $first + 1);
        $decoded = json_decode($jsonText, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function callQwen(string $systemPrompt, string $userPrompt): array
    {
        $apiKey = OPENROUTER_API_KEY;
        // Always use the classification-tuned (fast) model. We deliberately
        // ignore AI_MODEL env, that one is set for translate.php where
        // -plus quality matters more than latency.
        $model = defined('AI_CLASSIFY_MODEL') ? AI_CLASSIFY_MODEL : self::MODEL_DEFAULT;
        $url = 'https://openrouter.ai/api/v1/chat/completions';

        $payload = [
            'model'       => $model,
            'messages'    => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userPrompt],
            ],
            'max_tokens'  => 800,
            'temperature' => 0.1, // we want deterministic classification
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
                'HTTP-Referer: https://cardify.om',
                'X-Title: Cardify Wizard',
            ],
            CURLOPT_TIMEOUT        => self::TIMEOUT_SEC,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) return ['ok' => false, 'error' => 'curl: ' . $err];
        if ($code !== 200) return ['ok' => false, 'error' => 'http ' . $code . ': ' . substr((string)$body, 0, 300)];

        $resp = json_decode((string)$body, true);
        $content = $resp['choices'][0]['message']['content'] ?? null;
        if (!is_string($content)) return ['ok' => false, 'error' => 'no content in response'];
        return ['ok' => true, 'content' => $content];
    }
}
