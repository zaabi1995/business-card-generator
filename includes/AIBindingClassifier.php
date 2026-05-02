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
    // MUST stay in lockstep with bindingOptions in admin/onboarding.php.
    // Anything not on this list gets silently dropped, which then falls
    // back to 'static' on the frontend (the user sees "Decoration" with
    // no idea why). Keep the spelling identical to what the dropdown ships.
    private const VALID = [
        'name_en','name_ar',
        'position_en','position_ar',
        'company_en','company_ar',
        'mobile','mobile_ar',
        'phone','phone_ar','fax',
        'email',
        'website','website_ar',
        'address','address_en','address_2_en','address_ar','address_2_ar',
        'social',
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
You are a precise classifier that labels text on a business card with EXACTLY ONE of these field labels (no others, no synonyms):

  name_en        Person's name in Latin/English script (e.g., "Mohammed Al Adawi", "Ali Al-Zaabi")
  name_ar        Person's name in Arabic script         (e.g., "محمد العدوي", "علي الزعابي")
  position_en    Job title in Latin/English script      (e.g., "Founding Partner", "CEO", "Senior Engineer", "Head of Marketing")
  position_ar    Job title in Arabic script             (e.g., "الشريك المؤسس", "الرئيس التنفيذي", "مهندس أول")
  company_en     Company / org name in Latin/English    (e.g., "Hosn AI Services LLC", "Omantel", "BHD Group", "HOSN ARTIFICIAL INTELLIGENCE SERVICES LLC")
  company_ar     Company / org name in Arabic           (e.g., "حصن لخدمات الذكاء الاصطناعي ش م م", "شركة عُمانتل")
  mobile         Mobile/phone number in Latin digits    (e.g., "+968 9214 4404", "+968 7161 6161", "+971 50 123 4567", "9214 4404")
  mobile_ar      Mobile/phone number in Arabic digits   (e.g., "٩٢١٤ ٤٤٠٤ ٩٦٨+")
  phone          Landline phone in Latin digits         (e.g., "+968 2456 7890", "T: +968 24...")
  phone_ar       Landline phone in Arabic digits
  fax            Fax number                              (e.g., "F: +968 ...")
  email          Email address                           (e.g., "mohammed@hosn.om", "ali@hosn.om", "info@cardify.om")
  website        URL in Latin                            (e.g., "hosn.om", "HOSN.OM", "https://cardify.om", "www.bhd.om")
  website_ar     URL in Arabic                           (e.g., "حصن.عُمان")
  address_en     Postal address in Latin                 (e.g., "Bousher, Muscat, Sultanate of Oman", "BOUSHER, MUSCAT, SULTANATE OF OMAN", "PO Box 1234, Muscat 121")
  address_ar     Postal address in Arabic                (e.g., "بوشر، مسقط، سلطنة عُمان")
  social         Social-media handle                     (e.g., "@hosn.om", "@cardify_om")
  static         Anything that should NEVER be replaced per-employee: field-name labels ("PHONE", "EMAIL", "P H O N E", "هاتف", "بريد"), brand taglines/slogans ("Sovereign AI, hosted inside your organisation", "ذكاء اصطناعي سيادي يعمل داخل مؤسستكم", "Design that delivers", "An Omantel Company"), follow-us prompts, established-since lines.
  skip           Pure visual noise with no text meaning (rare).

ABSOLUTE RULES:

1. SCRIPT WINS, NOT POSITION. If the text contains Arabic letters (ا ب ت ث ج ...), use the *_ar variant. If the text is in Latin/Roman letters (a b c ...), use the *_en variant. NEVER use *_ar for Latin text or *_en for Arabic text. Even on the "back" page that is mostly Arabic, if a specific block contains Latin text (like an email "ali@hosn.om" appearing on both sides), it is NOT _ar.

2. EMAIL vs MOBILE - DO NOT SWAP. An email ALWAYS contains "@" (e.g., "ali@hosn.om", "info@cardify.om"). A mobile/phone is digits, possibly with +, spaces, dashes, parentheses, leading "T:" or "M:" (e.g., "+968 7161 6161"). If you see "@" → email. If you see digits → mobile (or phone if explicitly a landline). NEVER label a phone number as email or an email address as mobile/phone.

3. FIELD LABELS ARE STATIC, NOT TYPED. The strings "PHONE", "EMAIL", "MOBILE", "ADDRESS", "WEBSITE", "FAX", "P H O N E", "E M A I L", "هاتف", "بريد", "موقع", "عنوان", "فاكس" are field NAMES, not values. They get 'static' EVERY time. Only the actual phone number, email, address, URL gets the typed binding.

4. TAGLINES & SLOGANS ARE STATIC. A tagline is a sentence that describes the company's mission/value, not a discrete data field. Examples: "Sovereign AI, hosted inside your organisation", "ذكاء اصطناعي سيادي يعمل داخل مؤسستكم", "Design that delivers", "Trusted since 1995". These get 'static' (NOT a typed binding).

5. COMPANY NAME (company_en/company_ar). A company name is short, often ends with LLC, Ltd, Group, Co, SAOC, ش م م, شركة, مجموعة. Examples: "Hosn AI Services", "HOSN ARTIFICIAL INTELLIGENCE SERVICES LLC", "حصن لخدمات الذكاء الاصطناعي ش م م". Use company_en for Latin, company_ar for Arabic.

6. NAMES vs POSITIONS. A name is a personal name (2-4 capitalised words, no occupation words). A position contains words like CEO, Director, Manager, Officer, Engineer, Founder, Partner, President, Lead, Head, Chief, Principal, Specialist, Consultant, Architect, Analyst, Owner, مدير, رئيس, مؤسس, مهندس, شريك, مستشار.

7. ADDRESS. Multi-part location with city, country, district, or PO Box. Example Latin: "BOUSHER, MUSCAT, SULTANATE OF OMAN" → address_en. Example Arabic: "بوشر، مسقط، سلطنة عُمان" → address_ar.

8. WEBSITE. Anything that looks like a domain (contains a dot and ends in .om/.com/.co/.io/.ai/.org/.net), with or without protocol or www. Examples: "hosn.om", "HOSN.OM", "cardify.om", "https://bhd.om". An email (contains @) is NEVER a website.

9. WHEN UNSURE → 'static'. A wrong typed binding silently corrupts every employee card the template generates. 'static' just keeps the original text on the card. Prefer 'static' over a guess.

OUTPUT FORMAT: a single JSON object, NO prose, NO markdown fences, NO explanation. Use ONLY the labels listed above. Example output:
{"block_0":"name_en","block_1":"position_en","block_2":"static","block_3":"static","block_4":"static","block_5":"mobile","block_6":"email","block_7":"address_en","block_8":"website","block_9":"name_ar","block_10":"position_ar","block_11":"static","block_12":"static","block_13":"static","block_14":"email","block_15":"mobile","block_16":"company_ar","block_17":"address_ar"}
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
