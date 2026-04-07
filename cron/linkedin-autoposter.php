<?php
/**
 * LinkedIn Auto-Poster
 * Publishes blog posts to LinkedIn when they become due (based on publish_date)
 * Run via cron: 0 9 * * * php /www/wwwroot/cardify.om/cron/linkedin-autoposter.php
 */

$logFile = __DIR__ . '/../logs/linkedin-poster.log';

function logMsg($msg) {
    global $logFile;
    $dir = dirname($logFile);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($logFile, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
}

try {
    $pdo = new PDO('mysql:host=localhost;dbname=bc;charset=utf8mb4', 'bc', 'pWewN3fwFmEHh32J');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    logMsg("DB ERROR: " . $e->getMessage());
    exit(1);
}

// Get LinkedIn credentials
$token = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='linkedin_access_token'")->fetchColumn();
$personUrn = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='linkedin_person_urn'")->fetchColumn();
$orgToken = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='linkedin_org_access_token'")->fetchColumn();
$companyId = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='linkedin_company_id'")->fetchColumn();

if (!$token && !$orgToken) {
    logMsg("ERROR: No LinkedIn access token found");
    exit(1);
}

// Find draft posts that should be published today
$today = date('Y-m-d');
$stmt = $pdo->prepare("SELECT id, title, slug, excerpt, featured_image
                        FROM blog_posts
                        WHERE status = 'draft'
                        AND DATE(publish_date) <= ?
                        AND linkedin_posted IS NULL
                        ORDER BY publish_date ASC
                        LIMIT 1");
$stmt->execute([$today]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    logMsg("No posts to publish today");
    exit(0);
}

// Publish the post (change status from draft to published)
$pdo->prepare("UPDATE blog_posts SET status = 'published' WHERE id = ?")->execute([$post['id']]);
logMsg("Published blog post: " . $post['title']);

// Build LinkedIn post
$blogUrl = "https://cardify.om/blog/" . $post['slug'];
$commentary = $post['title'] . "\n\n" . substr(strip_tags($post['excerpt']), 0, 250) . "\n\nRead more: " . $blogUrl . "\n\n#BusinessCards #Oman #Cardify #Branding";

// Determine which token/author to use (prefer org if available)
if ($orgToken && $companyId) {
    $author = "urn:li:organization:" . $companyId;
    $postToken = $orgToken;
    $useOrg = true;
} else {
    $author = "urn:li:person:" . $personUrn;
    $postToken = $token;
    $useOrg = false;
}

$payload = [
    'author' => $author,
    'lifecycleState' => 'PUBLISHED',
    'specificContent' => [
        'com.linkedin.ugc.ShareContent' => [
            'shareCommentary' => ['text' => $commentary],
            'shareMediaCategory' => 'ARTICLE',
            'media' => [[
                'status' => 'READY',
                'originalUrl' => $blogUrl,
                'title' => ['text' => $post['title']],
                'description' => ['text' => substr(strip_tags($post['excerpt']), 0, 200)]
            ]]
        ]
    ],
    'visibility' => [
        'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC'
    ]
];

$ch = curl_init('https://api.linkedin.com/v2/ugcPosts');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $postToken,
        'Content-Type: application/json',
        'X-Restli-Protocol-Version: 2.0.0'
    ],
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 201) {
    $data = json_decode($response, true);
    $shareId = $data['id'] ?? 'unknown';
    $pdo->prepare("UPDATE blog_posts SET linkedin_posted = NOW(), linkedin_post_id = ? WHERE id = ?")
        ->execute([$shareId, $post['id']]);
    logMsg("SUCCESS: Posted to LinkedIn (" . ($useOrg ? "company" : "personal") . "): " . $post['title'] . " | ID: " . $shareId);
} else {
    logMsg("FAILED: HTTP $httpCode | " . $response);
}
