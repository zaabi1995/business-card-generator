<?php
/**
 * Server-side business-activity categorisation for scanned cards.
 *
 * The app already categorises ON DEVICE (lib/cardCategory.ts) so a card always
 * has a category, offline and instantly. This class REFINES that: it only asks a
 * model about cards the device could not place ('other') or placed weakly, which
 * keeps the token spend proportional to the actual uncertainty instead of
 * re-deciding cards that were already obvious.
 *
 * Rules that matter:
 *  - a category set by a HUMAN (category_source='user') is never overwritten,
 *  - the model must answer with one of our labels or the row is left alone,
 *  - a failure is logged and skipped, never written as a wrong category.
 */
class ScanCategorizer
{
    private const MODEL_DEFAULT = 'qwen/qwen-2.5-72b-instruct';
    private const TIMEOUT_SEC = 45;
    private const BATCH = 20;

    /** Must stay in lockstep with CardCategory in lib/cardCategory.ts. */
    public const CATEGORIES = [
        'construction', 'oil_gas', 'energy_utilities', 'logistics', 'automotive',
        'technology', 'telecom', 'finance', 'legal', 'consulting', 'healthcare',
        'education', 'government', 'hospitality', 'food', 'retail', 'real_estate',
        'manufacturing', 'media_marketing', 'travel', 'security', 'agriculture',
        'printing', 'other',
    ];

    /** Weak enough to be worth a second opinion. */
    private const REFINE_BELOW = 0.45;

    public static function pending(PDO $pdo, int $limit = self::BATCH): array
    {
        $stmt = $pdo->prepare(
            "SELECT id, parsed
             FROM scans
             WHERE (category_source IS NULL OR category_source = 'device')
               AND (category IS NULL
                    OR category = 'other'
                    OR category_confidence IS NULL
                    OR category_confidence < :weak)
             ORDER BY id DESC
             LIMIT :lim"
        );
        $stmt->bindValue(':weak', self::REFINE_BELOW);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * How many candidates the model could ACTUALLY act on.
     *
     * count(pending()) over-reported: a scan with no company, title or website
     * is a permanent candidate (describe() returns null, refineBatch skips it,
     * nothing ever changes its category_source), so the "Awaiting AI" tile could
     * never reach zero and the button stayed lit with nothing to do. Capped so a
     * large table does not pay for an unbounded fetch on every page view;
     * $cappedAt tells the caller to render "500+" instead of a wrong exact number.
     */
    public static function pendingCount(PDO $pdo, int $cap = 500, ?bool &$cappedAt = null): int
    {
        $rows = self::pending($pdo, $cap);
        $cappedAt = count($rows) >= $cap;
        $n = 0;
        foreach ($rows as $row) {
            if (self::describe($row) !== null) $n++;
        }
        return $n;
    }

    /** Only the fields that describe the BUSINESS, never the person's contacts. */
    private static function describe(array $row): ?array
    {
        $parsed = json_decode((string)($row['parsed'] ?? ''), true);
        if (!is_array($parsed)) return null;
        $company = trim((string)($parsed['company_en'] ?? '')) ?: trim((string)($parsed['company_ar'] ?? ''));
        $title   = trim((string)($parsed['title_en'] ?? ''))   ?: trim((string)($parsed['title_ar'] ?? ''));
        $website = trim((string)($parsed['website'] ?? ''));
        if ($company === '' && $title === '' && $website === '') return null;
        return [
            'id' => (int)$row['id'],
            'company' => $company,
            'title' => $title,
            'website' => $website,
        ];
    }

    public static function refineBatch(PDO $pdo, int $limit = self::BATCH): array
    {
        $out = ['examined' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
        $rows = self::pending($pdo, $limit);
        $items = [];
        foreach ($rows as $row) {
            $described = self::describe($row);
            if ($described === null) { $out['skipped']++; continue; }
            $items[] = $described;
        }
        $out['examined'] = count($items);
        if (!$items) return $out;

        $answer = self::classify($items);
        if (!$answer['ok']) {
            $out['errors'][] = $answer['error'];
            return $out;
        }

        $update = $pdo->prepare(
            "UPDATE scans
             SET category = :cat,
                 category_source = 'server',
                 category_confidence = :conf,
                 category_at = NOW()
             WHERE id = :id
               AND (category_source IS NULL OR category_source = 'device')"
        );
        foreach ($answer['labels'] as $id => $label) {
            if (!in_array($label['category'], self::CATEGORIES, true)) { $out['skipped']++; continue; }
            $update->execute([
                ':cat' => $label['category'],
                ':conf' => $label['confidence'],
                ':id' => $id,
            ]);
            $out['updated'] += $update->rowCount();
        }
        return $out;
    }

    private static function classify(array $items): array
    {
        $lines = [];
        foreach ($items as $item) {
            $lines[] = json_encode([
                'id' => $item['id'],
                'company' => $item['company'],
                'title' => $item['title'],
                'website' => $item['website'],
            ], JSON_UNESCAPED_UNICODE);
        }
        $system =
            "You classify businesses from a business card into ONE category.\n" .
            "Allowed categories: " . implode(', ', self::CATEGORIES) . ".\n" .
            "The company name decides; the job title only supports it. An engineer at a bank is finance, not construction.\n" .
            "A government body (ministry, authority, municipality, diwan, embassy) is government whatever sector it covers.\n" .
            "Names may be Arabic or English. If you genuinely cannot tell, answer \"other\".\n" .
            "Reply with ONLY a JSON array: [{\"id\":123,\"category\":\"finance\",\"confidence\":0.9}]";
        $user = implode("\n", $lines);

        $resp = self::call($system, $user);
        if (!$resp['ok']) return $resp;

        $decoded = self::extractJsonArray($resp['content']);
        if ($decoded === null) {
            return ['ok' => false, 'error' => 'unparseable model response: ' . substr($resp['content'], 0, 200)];
        }
        $labels = [];
        foreach ($decoded as $entry) {
            if (!isset($entry['id'], $entry['category'])) continue;
            $conf = isset($entry['confidence']) ? (float)$entry['confidence'] : 0.6;
            $labels[(int)$entry['id']] = [
                'category' => (string)$entry['category'],
                'confidence' => max(0, min(1, $conf)),
            ];
        }
        return ['ok' => true, 'labels' => $labels];
    }

    private static function extractJsonArray(string $s): ?array
    {
        $first = strpos($s, '[');
        $last = strrpos($s, ']');
        if ($first === false || $last === false || $last <= $first) return null;
        $decoded = json_decode(substr($s, $first, $last - $first + 1), true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function call(string $systemPrompt, string $userPrompt): array
    {
        if (!defined('OPENROUTER_API_KEY') || !OPENROUTER_API_KEY) {
            return ['ok' => false, 'error' => 'OPENROUTER_API_KEY is not configured'];
        }
        $model = defined('AI_CLASSIFY_MODEL') ? AI_CLASSIFY_MODEL : self::MODEL_DEFAULT;
        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'max_tokens' => 1200,
            'temperature' => 0.1,
        ];
        $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . OPENROUTER_API_KEY,
                'HTTP-Referer: https://cardify.om',
                'X-Title: Cardify Scan Categorizer',
            ],
            CURLOPT_TIMEOUT => self::TIMEOUT_SEC,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err) return ['ok' => false, 'error' => 'curl: ' . $err];
        if ($code !== 200) return ['ok' => false, 'error' => 'http ' . $code . ': ' . substr((string)$body, 0, 200)];
        $resp = json_decode((string)$body, true);
        $content = $resp['choices'][0]['message']['content'] ?? null;
        if (!is_string($content)) return ['ok' => false, 'error' => 'no content in response'];
        return ['ok' => true, 'content' => $content];
    }
}
