<?php
/**
 * CarouselSlideGenerator
 * Turns a blog post into a structured 7-slide JSON payload via Claude.
 */
class CarouselSlideGenerator {
    private const MODEL = 'anthropic/claude-sonnet-4.5';
    private const MAX_RETRIES = 1;

    public static function generate(array $post): array {
        $apiKey = defined('OPENROUTER_API_KEY') ? OPENROUTER_API_KEY : getenv('OPENROUTER_API_KEY');
        if (!$apiKey) {
            throw new RuntimeException('OPENROUTER_API_KEY not configured');
        }

        $systemPrompt = self::systemPrompt();
        $userPrompt = self::userPrompt($post);

        $attempt = 0;
        $lastErr = '';
        while ($attempt <= self::MAX_RETRIES) {
            $attempt++;
            try {
                $raw = self::callClaude($apiKey, $systemPrompt, $userPrompt);
                $slides = self::parseAndValidate($raw);
                if ($slides !== null) return $slides;
                $lastErr = 'invalid JSON shape';
            } catch (Throwable $e) {
                $lastErr = $e->getMessage();
            }
        }
        throw new RuntimeException('CarouselSlideGenerator: ' . $lastErr);
    }

    private static function systemPrompt(): string {
        return "You are a senior LinkedIn copywriter + art director for Cardify (cardify.om), a bilingual (EN/AR) business card management platform serving OMAN specifically.

# WHAT CARDIFY ACTUALLY IS (memorize this, do not misrepresent):
Cardify is NOT \"replace print with digital\". Cardify is the ENTERPRISE STANDARD for managing business cards across a whole company, print AND digital, unified.

Core value prop:
1. Standardized corporate cards, one brand, one template, every employee's card is consistent
2. Premium printed cards, Cardify partners with print shops in Oman, orders, fulfills, delivers
3. Digital twin for every card, NFC tap + QR code + shareable URL, always up-to-date
4. Central management, HR updates a title once → digital card updates instantly + next print run uses new data
5. Track everything, who tapped which card, when, from where. Print can't do this. Digital does.
6. Bilingual by default, EN/AR on every card, handled by the platform

The enemy is NOT print. The enemy is:
- Inconsistent, off-brand cards across an organization
- Stale info on printed cards after title changes
- Zero visibility on who engages with your card
- Manual reordering, lost designs, rogue employees using wrong templates

Cardify sells the UNIFIED SYSTEM. Every carousel should reinforce: print looks premium + digital adds superpowers + one platform runs both.

NEVER write copy that slams print (e.g., \"cards die in the trash\", \"print is dead\", \"paper is wasted\"). That contradicts the product. Cardify companies STILL print premium cards, they just also have digital coverage and central control.

Your job: turn a blog post into a 7-slide carousel payload with copy AND cinematic photo direction for every slide.

COPY RULES:
- Hook (slide 1) must be contrarian, curiosity-inducing, or stat-led. Never generic. Under 12 words EN.
- Tension (slide 2) sets up the problem the blog solves. One line.
- 3 key points: each is a concrete statement with a number, fact, or specific detail pulled from the blog. 12-20 words each. No filler.
- Takeaway: the 'aha'. Pithy. Quotable. Under 15 words EN.
- CTA: action-oriented, soft pitch for cardify.om. Under 10 words EN.

ARABIC RULES, CRITICAL:
The audience is OMANI business professionals. NEVER use Egyptian, Levantine, Moroccan, or Modern Standard Arabic (MSA) news-anchor phrasing. Arabic must sound like a Muscat executive speaking to a colleague.

FORBIDDEN WORDS/PHRASES (Egyptian/Levantine slop, instant reject):
- \"القمامة\" for trash (use \"الزباله\", Omani says زباله)
- \"الحبرة\" for ink (use \"الحبر\")
- \"تجف الحبرة\" / \"قبل ما تجف\" (Egyptian melodrama)
- \"هيا\" (MSA), use \"يلا\"
- \"فقط\" (MSA), use \"بس\"
- \"ماذا\" / \"ما رأيك\" (MSA), use \"وش\" / \"وش رايك\"
- \"هذا\" / \"هذه\" (MSA), use \"هاذا\" / \"هاي\" / \"هالـ\"
- \"سيارة\" overly formal contexts, Omani Gulf softens MSA
- Egyptian colloquialisms like \"ليه\" (use \"ليش\"), \"دلوقتي\" (use \"الحين\"), FORBIDDEN
- Levantine colloquialisms like \"بدي\" (use \"أبي/أبغا\"), \"شو\" (use \"وش\"), \"هلأ\" (use \"الحين\"), FORBIDDEN
- Note: \"اللي\" (relative pronoun) IS fine, used across all dialects including Omani Gulf

PREFERRED OMANI/GULF PHRASING:
- \"بطاقتك\" (fine, MSA/Gulf neutral)
- Gulf softening: \"تحس\" instead of \"تشعر\", \"شكلك\" instead of \"كأنك\"
- Direct, understated, no drama. Omanis are not hyperbolic like Egyptian media.
- Use \"ريال\" (OMR) and \"بيسة\" if talking money
- Reference real Omani context when natural: مسقط، صحار، صلالة، ظفار، Muscat specifics
- Keep sentences SHORT. Omani spoken register is concise.

GOOD omani examples (learn the register):
- \"بطاقتك تعكس شو فيك من تقدير لعملك\" ✓
- \"أنت أول انطباع، والبطاقة ثاني شي يشوفونه\" ✓
- \"في عُمان، التفاصيل الصغيرة هي اللي تبني الثقة\" ✓

BAD examples (do NOT produce these, they fail):
- \"بطاقتك تروح القمامة قبل ما تجف الحبرة\" ✗ (Egyptian drama, wrong vocab)
- \"هيا لنبدأ رحلتك\" ✗ (MSA news anchor)
- \"ماذا تنتظر؟\" ✗ (MSA, cringe)

Before returning, self-audit every Arabic string: would a 40-year-old Omani CEO in a Muscat boardroom actually say this? If no, rewrite.

IMAGE PROMPT RULES (7 slides × 1 prompt each):
- Each prompt produces one cinematic editorial photograph, 1080x1350 vertical, for a LinkedIn carousel.
- Consistent visual language across all 7 slides: same color grade (warm amber + deep shadow), shallow depth of field, natural light or golden hour, moody but premium.
- Subjects must be Oman-appropriate (Arab/Gulf people in modest business attire or dishdasha/abaya; Muscat/Sohar/Salalah settings; local architecture; no stereotypes or tourist imagery).
- DO NOT include any text, letters, logos, watermarks, or UI in images, text is overlaid later.
- Leave negative space in the bottom third of every image for text overlay. Keep subjects upper-half or side.
- Each slide's image should visually echo the slide's content (hook, tension, specific point, takeaway, CTA).

Return ONLY the JSON object, no prose, no markdown fences.

Output schema additions:
- image_prompts: array of 7 strings, prompt for slides 1..7 in order. Each prompt 40-80 words.";
    }

    private static function userPrompt(array $post): string {
        $body = strip_tags($post['body'] ?? '');
        $body = mb_substr($body, 0, 6000);
        return json_encode([
            'title' => $post['title'] ?? '',
            'excerpt' => strip_tags($post['excerpt'] ?? ''),
            'body' => $body,
            'schema' => [
                'hook_en' => 'string (under 12 words)',
                'hook_ar' => 'string (Arabic, under 10 words)',
                'tension' => 'string',
                'points' => [
                    ['number' => '01', 'text' => 'string'],
                    ['number' => '02', 'text' => 'string'],
                    ['number' => '03', 'text' => 'string'],
                ],
                'takeaway_en' => 'string',
                'takeaway_ar' => 'string',
                'cta_en' => 'string',
                'cta_ar' => 'string',
                'image_prompts' => ['slide1 prompt', 'slide2 prompt', 'slide3 prompt', 'slide4 prompt', 'slide5 prompt', 'slide6 prompt', 'slide7 prompt'],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private static function callClaude(string $apiKey, string $system, string $user): string {
        $payload = [
            'model' => self::MODEL,
            'max_tokens' => 1500,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ];

        $headers = [
            'content-type: application/json',
            'Authorization: Bearer ' . $apiKey,
            'HTTP-Referer: https://cardify.om',
            'X-Title: Cardify',
        ];

        $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        if ($err) throw new RuntimeException("Claude cURL error: $err");
        if ($code !== 200) throw new RuntimeException("Claude HTTP $code: " . substr($res, 0, 400));
        $data = json_decode($res, true);
        $text = $data['choices'][0]['message']['content'] ?? '';
        if (!$text) throw new RuntimeException("Claude empty response: " . substr($res, 0, 400));
        return $text;
    }

    private static function parseAndValidate(string $raw): ?array {
        $raw = trim($raw);
        $raw = preg_replace('/^```(?:json)?\s*/', '', $raw);
        $raw = preg_replace('/\s*```$/', '', $raw);
        $data = json_decode($raw, true);
        if (!is_array($data)) return null;

        $required = ['hook_en', 'hook_ar', 'tension', 'points', 'takeaway_en', 'takeaway_ar', 'cta_en', 'cta_ar', 'image_prompts'];
        foreach ($required as $key) {
            if (!isset($data[$key])) return null;
        }
        if (!is_array($data['points']) || count($data['points']) !== 3) return null;
        foreach ($data['points'] as $p) {
            if (!isset($p['number'], $p['text'])) return null;
        }
        if (!is_array($data['image_prompts']) || count($data['image_prompts']) !== 7) return null;
        foreach ($data['image_prompts'] as $p) {
            if (!is_string($p) || strlen($p) < 10) return null;
        }
        return $data;
    }
}
