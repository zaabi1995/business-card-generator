<?php
/**
 * CarouselSlideGenerator
 * Turns a blog post into a structured 7-slide JSON payload via Claude.
 */
class CarouselSlideGenerator {
    private const MODEL = 'claude-sonnet-4-6';
    private const MAX_RETRIES = 1;

    public static function generate(array $post): array {
        $apiKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : getenv('ANTHROPIC_API_KEY');
        if (!$apiKey) {
            throw new RuntimeException('ANTHROPIC_API_KEY not configured');
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
        return "You are a senior LinkedIn copywriter + art director for Cardify, a bilingual (EN/AR) business card platform serving Oman.

Your job: turn a blog post into a 7-slide carousel payload with copy AND cinematic photo direction for every slide.

COPY RULES:
- Hook (slide 1) must be contrarian, curiosity-inducing, or stat-led. Never generic. Under 12 words EN.
- AR must be natural Gulf Arabic (Omani phrasing preferred) — never machine-literal. Short, punchy.
- Tension (slide 2) sets up the problem the blog solves. One line.
- 3 key points: each is a concrete statement with a number, fact, or specific detail pulled from the blog. 12-20 words each. No filler.
- Takeaway: the 'aha'. Pithy. Quotable. Under 15 words EN.
- CTA: action-oriented, soft pitch for cardify.om. Under 10 words EN.

IMAGE PROMPT RULES (7 slides × 1 prompt each):
- Each prompt produces one cinematic editorial photograph, 1080x1350 vertical, for a LinkedIn carousel.
- Consistent visual language across all 7 slides: same color grade (warm amber + deep shadow), shallow depth of field, natural light or golden hour, moody but premium.
- Subjects must be Oman-appropriate (Arab/Gulf people in modest business attire or dishdasha/abaya; Muscat/Sohar/Salalah settings; local architecture; no stereotypes or tourist imagery).
- DO NOT include any text, letters, logos, watermarks, or UI in images — text is overlaid later.
- Leave negative space in the bottom third of every image for text overlay. Keep subjects upper-half or side.
- Each slide's image should visually echo the slide's content (hook, tension, specific point, takeaway, CTA).

Return ONLY the JSON object, no prose, no markdown fences.

Output schema additions:
- image_prompts: array of 7 strings — prompt for slides 1..7 in order. Each prompt 40-80 words.";
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
            'system' => $system,
            'messages' => [
                ['role' => 'user', 'content' => $user],
            ],
        ];

        // Auto-detect auth mode: OAuth tokens use Bearer + oauth beta header;
        // Console API keys use x-api-key.
        $isOAuth = (strpos($apiKey, 'sk-ant-oat') === 0);
        $headers = [
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ];
        if ($isOAuth) {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
            $headers[] = 'anthropic-beta: oauth-2025-04-20';
        } else {
            $headers[] = 'x-api-key: ' . $apiKey;
        }

        $ch = curl_init('https://api.anthropic.com/v1/messages');
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
        $text = $data['content'][0]['text'] ?? '';
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
