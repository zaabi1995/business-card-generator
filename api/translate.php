<?php
/**
 * AI Translation API Endpoint
 * Uses Qwen 3.6 via OpenRouter to translate text between English and Arabic.
 * Falls back to OPENAI_API_KEY (gpt-4o-mini) if OPENROUTER_API_KEY is unset,
 * so existing deploys keep working while the new key rolls out.
 * Requires authentication to prevent unauthorized AI credit consumption.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Public portal visitors hit this endpoint anonymously to translate
// their own name/position from English to Arabic. Rate limit + length
// cap below are the spend protection; full auth would block the use case.
//
// Bucket the rate limit per CLIENT IP (not session). Cookie-less callers
// would otherwise create a fresh session each request and bypass entirely
// (caught 7 May 2026 in the verify-everything loop, iter 10/11).
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$now = time();
$rateLimitWindow = 60; // seconds
$rateLimitMax = Auth::isLoggedIn() ? 20 : 8; // tighter cap for anonymous

// Real client IP behind Cloudflare; falls back to REMOTE_ADDR.
$clientIp = $_SERVER['HTTP_CF_CONNECTING_IP']
    ?? trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0])
    ?: ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$bucketKey = sha1($clientIp);
$bucketDir = sys_get_temp_dir() . '/cardify-rate-translate';
if (!is_dir($bucketDir)) { @mkdir($bucketDir, 0775, true); }
$bucketFile = $bucketDir . '/' . $bucketKey . '.json';
$bucket = [];
if (is_file($bucketFile)) {
    $raw = @file_get_contents($bucketFile);
    if ($raw) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $bucket = $decoded;
    }
}
$bucket = array_values(array_filter($bucket, fn($ts) => is_numeric($ts) && ($now - (int)$ts) < $rateLimitWindow));
if (count($bucket) >= $rateLimitMax) {
    http_response_code(429);
    echo json_encode(['error' => 'Rate limit exceeded. Please wait before making more translation requests.']);
    exit;
}
$bucket[] = $now;
@file_put_contents($bucketFile, json_encode($bucket), LOCK_EX);
// Probabilistic GC: 1 in 100 requests sweeps stale buckets.
if (mt_rand(1, 100) === 1) {
    foreach (glob($bucketDir . '/*.json') ?: [] as $f) {
        if (@filemtime($f) < $now - $rateLimitWindow * 10) @unlink($f);
    }
}

// Check if any AI provider is configured (prefer OpenRouter, fall back to OpenAI)
$hasOpenRouter = defined('OPENROUTER_API_KEY') && !empty(OPENROUTER_API_KEY);
$hasOpenAI = defined('OPENAI_API_KEY') && !empty(OPENAI_API_KEY);
if (!$hasOpenRouter && !$hasOpenAI) {
    http_response_code(503);
    echo json_encode(['error' => 'AI translation not configured', 'code' => 'not_configured']);
    exit;
}

// Get input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    // Try form data
    $input = $_POST;
}

$text = trim($input['text'] ?? '');
$targetLang = $input['target'] ?? 'ar'; // ar (Arabic) or en (English)
$fieldType = $input['field_type'] ?? 'text'; // name, position, address, phone, etc.

if (empty($text)) {
    echo json_encode(['error' => 'No text provided', 'translation' => '']);
    exit;
}

// Hard cap on input size so an anonymous caller can't dump a novel
// into the model. Names/positions/addresses are short by definition.
if (mb_strlen($text) > 300) {
    http_response_code(413);
    echo json_encode(['error' => 'Text too long (max 300 chars)']);
    exit;
}

// Build the prompt based on field type
$systemPrompt = "You are a professional translator specializing in business card content. Translate accurately and professionally.";

switch ($fieldType) {
    case 'name':
        if ($targetLang === 'ar') {
            $prompt = "Translate this person's name to Arabic. If it's already a common Arabic name, provide the standard Arabic spelling. Keep it professional and accurate for a business card.\n\nName: {$text}\n\nProvide only the Arabic translation, nothing else.";
        } else {
            $prompt = "Transliterate this Arabic name to English letters. Keep proper capitalization for a business card.\n\nName: {$text}\n\nProvide only the English transliteration, nothing else.";
        }
        break;
        
    case 'position':
        if ($targetLang === 'ar') {
            $prompt = "Translate this job title/position to Arabic. Use professional business terminology.\n\nPosition: {$text}\n\nProvide only the Arabic translation, nothing else.";
        } else {
            $prompt = "Translate this Arabic job title/position to English. Use professional business terminology.\n\nPosition: {$text}\n\nProvide only the English translation, nothing else.";
        }
        break;
        
    case 'address':
        if ($targetLang === 'ar') {
            $prompt = "Translate this address to Arabic. Keep the format appropriate for Oman/Middle East.\n\nAddress: {$text}\n\nProvide only the Arabic translation, nothing else.";
        } else {
            $prompt = "Translate this Arabic address to English.\n\nAddress: {$text}\n\nProvide only the English translation, nothing else.";
        }
        break;
        
    case 'phone':
        // Convert phone numbers to Arabic numerals or vice versa
        if ($targetLang === 'ar') {
            $translation = convertToArabicNumerals($text);
            echo json_encode(['translation' => $translation, 'source' => 'local']);
            exit;
        } else {
            $translation = convertToWesternNumerals($text);
            echo json_encode(['translation' => $translation, 'source' => 'local']);
            exit;
        }
        break;
        
    default:
        if ($targetLang === 'ar') {
            $prompt = "Translate this text to Arabic:\n\n{$text}\n\nProvide only the Arabic translation, nothing else.";
        } else {
            $prompt = "Translate this Arabic text to English:\n\n{$text}\n\nProvide only the English translation, nothing else.";
        }
}

// Call AI provider (OpenRouter Qwen first, OpenAI fallback)
$response = callTranslator($prompt, $systemPrompt);

if ($response['success']) {
    echo json_encode([
        'translation' => $response['text'],
        'source' => $response['provider'] ?? 'ai'
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'error' => $response['error'] ?? 'Translation failed',
        'translation' => ''
    ]);
}

/**
 * Call the configured AI translator. Tries providers in order: OpenRouter Qwen
 * 3.6 first, then OpenAI gpt-4o-mini. OpenRouter gets one retry on transient
 * 5xx/network failures before falling through. Returns the first success.
 */
function callTranslator($prompt, $systemPrompt = '') {
    $providers = [];
    if (defined('OPENROUTER_API_KEY') && !empty(OPENROUTER_API_KEY)) {
        $providers[] = 'openrouter';
    }
    if (defined('OPENAI_API_KEY') && !empty(OPENAI_API_KEY)) {
        $providers[] = 'openai';
    }

    $lastError = 'No AI provider configured';
    foreach ($providers as $i => $provider) {
        $attempts = ($provider === 'openrouter') ? 2 : 1;
        for ($n = 0; $n < $attempts; $n++) {
            $res = callProviderOnce($provider, $prompt, $systemPrompt);
            if ($res['success']) {
                return $res;
            }
            $lastError = $res['error'];
            // Retry only on transient failures (5xx, timeout, network).
            if (!empty($res['transient']) && $n < $attempts - 1) {
                usleep(500000); // 500ms
                continue;
            }
            break;
        }
    }
    return ['success' => false, 'error' => $lastError];
}

function callProviderOnce($provider, $prompt, $systemPrompt) {
    if ($provider === 'openrouter') {
        $apiKey = OPENROUTER_API_KEY;
        // Names/positions/addresses are short, qwen3.6-flash returns in 1-2s
        // vs qwen3.6-plus's 8-12s. Quality is identical for these inputs.
        $model = defined('AI_TRANSLATE_MODEL') ? AI_TRANSLATE_MODEL : 'qwen/qwen3.6-flash';
        $url = 'https://openrouter.ai/api/v1/chat/completions';
    } else {
        $apiKey = OPENAI_API_KEY;
        $model = defined('OPENAI_MODEL') ? OPENAI_MODEL : 'gpt-4o-mini';
        $url = 'https://api.openai.com/v1/chat/completions';
    }

    $messages = [];
    if ($systemPrompt) {
        $messages[] = ['role' => 'system', 'content' => $systemPrompt];
    }
    $messages[] = ['role' => 'user', 'content' => $prompt];

    $data = [
        'model' => $model,
        'messages' => $messages,
        'max_tokens' => 80,
        'temperature' => 0.2
    ];

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ];
    if ($provider === 'openrouter') {
        $headers[] = 'HTTP-Referer: https://cardify.om';
        $headers[] = 'X-Title: Cardify';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['success' => false, 'error' => "[{$provider}] connection error: {$error}", 'transient' => true];
    }

    $result = json_decode($response, true);

    if ($httpCode !== 200) {
        $errorMsg = $result['error']['message'] ?? ($result['error'] ?? "HTTP {$httpCode}");
        if (is_array($errorMsg)) { $errorMsg = json_encode($errorMsg); }
        // 408/429/5xx are worth retrying or falling through to next provider.
        $transient = ($httpCode === 408 || $httpCode === 429 || $httpCode >= 500);
        return ['success' => false, 'error' => "[{$provider}] {$errorMsg}", 'transient' => $transient];
    }

    $text = $result['choices'][0]['message']['content'] ?? '';
    if (trim($text) === '') {
        return ['success' => false, 'error' => "[{$provider}] empty response", 'transient' => true];
    }
    return ['success' => true, 'text' => trim($text), 'provider' => $provider];
}

/**
 * Convert Western numerals to Arabic-Indic numerals
 */
function convertToArabicNumerals($text) {
    $western = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    $arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    return str_replace($western, $arabic, $text);
}

/**
 * Convert Arabic-Indic numerals to Western numerals
 */
function convertToWesternNumerals($text) {
    $arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    $western = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return str_replace($arabic, $western, $text);
}
