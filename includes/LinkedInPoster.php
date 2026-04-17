<?php
/**
 * LinkedInPoster
 * Handles document (carousel) UGC posting to personal profile + optional org cross-post.
 * Uses the v2 Assets + UGC APIs.
 */
class LinkedInPoster {
    public static function postDocument(
        string $accessToken,
        string $authorUrn,
        string $pdfPath,
        string $commentary,
        string $title
    ): string {
        // 1) Register upload
        $register = self::api($accessToken, 'https://api.linkedin.com/v2/assets?action=registerUpload', [
            'registerUploadRequest' => [
                'recipes' => ['urn:li:digitalmediaRecipe:feedshare-document'],
                'owner' => $authorUrn,
                'serviceRelationships' => [[
                    'relationshipType' => 'OWNER',
                    'identifier' => 'urn:li:userGeneratedContent',
                ]],
            ],
        ]);
        $uploadUrl = $register['value']['uploadMechanism']['com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest']['uploadUrl'] ?? null;
        $assetUrn = $register['value']['asset'] ?? null;
        if (!$uploadUrl || !$assetUrn) {
            throw new RuntimeException('LinkedIn registerUpload failed: ' . json_encode($register));
        }

        // 2) PUT the PDF bytes
        $pdfBytes = file_get_contents($pdfPath);
        $ch = curl_init($uploadUrl);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $pdfBytes,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/pdf',
            ],
        ]);
        curl_exec($ch);
        $putCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($putCode < 200 || $putCode >= 300) {
            throw new RuntimeException("LinkedIn PUT upload HTTP $putCode");
        }

        // 3) Create UGC post referencing the asset
        $post = self::api($accessToken, 'https://api.linkedin.com/v2/ugcPosts', [
            'author' => $authorUrn,
            'lifecycleState' => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => [
                    'shareCommentary' => ['text' => $commentary],
                    'shareMediaCategory' => 'DOCUMENT',
                    'media' => [[
                        'status' => 'READY',
                        'media' => $assetUrn,
                        'title' => ['text' => mb_substr($title, 0, 100)],
                    ]],
                ],
            ],
            'visibility' => [
                'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
            ],
        ]);
        $postId = $post['id'] ?? null;
        if (!$postId) throw new RuntimeException('LinkedIn ugcPosts failed: ' . json_encode($post));
        return $postId;
    }

    private static function api(string $token, string $url, array $payload): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'X-Restli-Protocol-Version: 2.0.0',
            ],
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        if ($err) throw new RuntimeException("LinkedIn cURL: $err");
        $data = json_decode($res, true);
        if ($code >= 400) throw new RuntimeException("LinkedIn HTTP $code: " . substr($res, 0, 400));
        return is_array($data) ? $data : [];
    }
}
