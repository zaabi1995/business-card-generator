<?php
/**
 * LinkedInPoster
 * Document (carousel) posts via LinkedIn's REST API.
 * Flow: initializeUpload → PUT PDF → create post referencing document URN.
 */
class LinkedInPoster {
    private const VERSION = '202404';

    public static function postDocument(
        string $accessToken,
        string $authorUrn,
        string $pdfPath,
        string $commentary,
        string $title
    ): string {
        // 1) Initialize upload
        $init = self::rest(
            $accessToken,
            'POST',
            'https://api.linkedin.com/rest/documents?action=initializeUpload',
            ['initializeUploadRequest' => ['owner' => $authorUrn]]
        );
        $uploadUrl = $init['value']['uploadUrl'] ?? null;
        $documentUrn = $init['value']['document'] ?? null;
        if (!$uploadUrl || !$documentUrn) {
            throw new RuntimeException('LinkedIn initializeUpload failed: ' . json_encode($init));
        }

        // 2) PUT PDF bytes
        $ch = curl_init($uploadUrl);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => file_get_contents($pdfPath),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/pdf',
                'LinkedIn-Version: ' . self::VERSION,
            ],
        ]);
        $resp = curl_exec($ch);
        $putCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($putCode < 200 || $putCode >= 300) {
            throw new RuntimeException("LinkedIn PUT upload HTTP $putCode: " . substr($resp, 0, 300));
        }

        // 3) Create post
        $post = self::rest(
            $accessToken,
            'POST',
            'https://api.linkedin.com/rest/posts',
            [
                'author' => $authorUrn,
                'commentary' => $commentary,
                'visibility' => 'PUBLIC',
                'distribution' => [
                    'feedDistribution' => 'MAIN_FEED',
                    'targetEntities' => [],
                    'thirdPartyDistributionChannels' => [],
                ],
                'content' => [
                    'media' => [
                        'title' => mb_substr($title, 0, 100),
                        'id' => $documentUrn,
                    ],
                ],
                'lifecycleState' => 'PUBLISHED',
                'isReshareDisabledByAuthor' => false,
            ],
            true // return headers to read x-restli-id
        );
        // The post URN is in the `x-restli-id` response header or the response body's `id`
        $postUrn = $post['id'] ?? $post['_header_id'] ?? null;
        if (!$postUrn) throw new RuntimeException('LinkedIn posts create: no URN in response: ' . json_encode($post));
        return $postUrn;
    }

    private static function rest(string $token, string $method, string $url, ?array $payload = null, bool $wantHeaders = false): array {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'LinkedIn-Version: ' . self::VERSION,
                'X-Restli-Protocol-Version: 2.0.0',
            ],
        ];
        if ($payload !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
        }
        if ($wantHeaders) {
            $opts[CURLOPT_HEADER] = true;
        }
        curl_setopt_array($ch, $opts);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        if ($err) throw new RuntimeException("LinkedIn cURL: $err");

        $headers = [];
        $body = $res;
        if ($wantHeaders) {
            $hsize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $rawHeaders = substr($res, 0, $hsize);
            $body = substr($res, $hsize);
            foreach (explode("\r\n", $rawHeaders) as $line) {
                if (strpos($line, ':') !== false) {
                    [$k, $v] = explode(':', $line, 2);
                    $headers[strtolower(trim($k))] = trim($v);
                }
            }
        }
        if ($code >= 400) throw new RuntimeException("LinkedIn HTTP $code ($method $url): " . substr($body, 0, 500));
        $data = json_decode($body, true);
        if (!is_array($data)) $data = [];
        if ($wantHeaders && isset($headers['x-restli-id'])) {
            $data['_header_id'] = $headers['x-restli-id'];
        }
        return $data;
    }
}
