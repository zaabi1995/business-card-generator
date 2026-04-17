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
        return "You are a senior LinkedIn copywriter for Cardify, a bilingual (EN/AR) business card platform serving Oman.

Your job: turn a blog post into a 7-slide carousel payload.

STYLE RULES:
- Hook (slide 1) must be contrarian, curiosity-inducing, or stat-led. Never generic. Under 12 words EN.
- AR must be natural Gulf Arabic (Omani phrasing preferred) — never machine-literal. Short, punchy.
- Tension (slide 2) sets up the problem the blog solves. One line.
- 3 key points: each is a concrete statement with a number, fact, or specific detail pulled from the blog. 12-20 words each. No filler.
- Takeaway: the 'aha'. Pithy. Quotable. Under 15 words EN.
- CTA: action-oriented, soft pitch for cardify.om. Under 10 words EN.

Return ONLY the JSON object, no prose, no markdown fences.";
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

        $required = ['hook_en', 'hook_ar', 'tension', 'points', 'takeaway_en', 'takeaway_ar', 'cta_en', 'cta_ar'];
        foreach ($required as $key) {
            if (!isset($data[$key])) return null;
        }
        if (!is_array($data['points']) || count($data['points']) !== 3) return null;
        foreach ($data['points'] as $p) {
            if (!isset($p['number'], $p['text'])) return null;
        }
        return $data;
    }
}
