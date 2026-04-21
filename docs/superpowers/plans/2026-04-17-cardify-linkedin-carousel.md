# Cardify LinkedIn Carousel Autoposter Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace Cardify's text + link daily LinkedIn post with bold, bilingual 7-slide carousel PDFs generated from each scheduled blog post, posted to Ali's personal profile today and cross-posted from the Cardify company page once LinkedIn approves Community Management API.

**Architecture:** A PHP cron on VPS invokes a Claude Sonnet 4.6 API call that turns blog body into structured slide JSON, renders the JSON through an HTML/CSS template into a 7-page PDF via Node + Playwright (both already installed on VPS), uploads the PDF to LinkedIn as a Document UGC post on Ali's personal profile, and, if an organization access token exists, cross-posts from the Cardify company page. Old text-link poster is kept as a safe fallback.

**Tech Stack:** PHP 7.4 (core), Claude Anthropic Messages API (`claude-sonnet-4-6`), Node 22 + Playwright (already on VPS), LinkedIn UGC + Assets API, MySQL (existing `bc` database), Space Grotesk + Inter + Noto Sans Arabic fonts.

---

## File Structure

```
cardify.om/
├── cron/linkedin-carousel.php                           # NEW entry point (cron)
├── includes/
│   ├── LinkedInCarousel.php                             # NEW orchestrator
│   ├── CarouselSlideGenerator.php                       # NEW Claude API → slide JSON
│   ├── CarouselPDFRenderer.php                          # NEW PHP wrapper for Node renderer
│   └── LinkedInPoster.php                               # NEW thin wrapper over LinkedIn API
├── tools/carousel-render/                               # NEW Node renderer workspace
│   ├── render.js                                        # Playwright script
│   ├── template.html                                    # HTML template
│   ├── template.css                                     # styles
│   ├── package.json                                     # playwright dep
│   ├── fonts/                                           # WOFF2 files
│   │   ├── SpaceGrotesk-Bold.woff2
│   │   ├── Inter-Regular.woff2
│   │   ├── NotoSansArabic-Bold.woff2
│   │   └── NotoSansArabic-Regular.woff2
│   └── mashrabiya.svg                                   # Omani geometric motif
├── api/linkedin/connect-company.php                     # NEW OAuth endpoint (org scopes)
├── admin/blog-carousel-preview.php                      # NEW preview endpoint
├── admin/super/blog.php                                 # MODIFY: add preview button
├── database/migrations/065_blog_posts_carousel_columns.php  # NEW
├── uploads/linkedin-carousels/                          # NEW (at runtime)
└── logs/linkedin-carousel.log                           # NEW (at runtime)
```

---

## Phase 1, Database + Infrastructure

### Task 1: Create carousel migration

**Files:**
- Create: `database/migrations/065_blog_posts_carousel_columns.php`

- [ ] **Step 1: Write migration**

```php
<?php
require_once __DIR__ . '/../../config.php';
try {
    $db = Database::getInstance();
    $db->exec("ALTER TABLE blog_posts
        ADD COLUMN linkedin_carousel_pdf VARCHAR(500) NULL AFTER linkedin_post_id,
        ADD COLUMN linkedin_company_post_id VARCHAR(255) NULL AFTER linkedin_carousel_pdf");
    echo "Migration 065: blog_posts carousel columns added\n";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "Migration 065: columns already exist, skipping\n";
        exit(0);
    }
    echo "Migration 065 failed: " . $e->getMessage() . "\n";
    exit(1);
}
```

- [ ] **Step 2: Run locally (or skip, run on VPS in deploy phase)**

Run: `php database/migrations/065_blog_posts_carousel_columns.php`
Expected: `Migration 065: blog_posts carousel columns added` (or "already exist, skipping").

- [ ] **Step 3: Commit**

```bash
git add database/migrations/065_blog_posts_carousel_columns.php
git commit -m "migration: add linkedin_carousel_pdf + linkedin_company_post_id to blog_posts"
```

### Task 2: Add Anthropic API key config

**Files:**
- Modify: `config.example.php` (add `ANTHROPIC_API_KEY` constant placeholder)
- The live `config.php` on VPS will be edited during deploy phase (not in git)

- [ ] **Step 1: Add placeholder in config.example.php**

Find the existing API-key definitions section (after Paymob constants) and append:

```php
// Anthropic Claude API, used by LinkedIn carousel generator
define('ANTHROPIC_API_KEY', 'sk-ant-api03-YOUR-KEY-HERE');
```

- [ ] **Step 2: Commit**

```bash
git add config.example.php
git commit -m "config: add ANTHROPIC_API_KEY placeholder for carousel generator"
```

---

## Phase 2, Claude Slide Generator

### Task 3: Build `CarouselSlideGenerator`

**Files:**
- Create: `includes/CarouselSlideGenerator.php`

- [ ] **Step 1: Write the class**

```php
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
        while ($attempt <= self::MAX_RETRIES) {
            $attempt++;
            $raw = self::callClaude($apiKey, $systemPrompt, $userPrompt);
            $slides = self::parseAndValidate($raw);
            if ($slides !== null) return $slides;
        }
        throw new RuntimeException('CarouselSlideGenerator: Claude returned invalid JSON after retries');
    }

    private static function systemPrompt(): string {
        return "You are a senior LinkedIn copywriter for Cardify, a bilingual (EN/AR) business card platform serving Oman.

Your job: turn a blog post into a 7-slide carousel payload.

STYLE RULES:
- Hook (slide 1) must be contrarian, curiosity-inducing, or stat-led. Never generic. Under 12 words EN.
- AR must be natural Gulf Arabic (Omani phrasing preferred), never machine-literal. Short, punchy.
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
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
                'content-type: application/json',
            ],
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err) throw new RuntimeException("Claude cURL error: $err");
        if ($code !== 200) throw new RuntimeException("Claude HTTP $code: $res");
        $data = json_decode($res, true);
        $text = $data['content'][0]['text'] ?? '';
        if (!$text) throw new RuntimeException("Claude empty response: $res");
        return $text;
    }

    private static function parseAndValidate(string $raw): ?array {
        $raw = trim($raw);
        // Strip markdown fences if Claude wrapped them despite instructions
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
```

- [ ] **Step 2: Smoke test**

Create a throwaway `/tmp/test-generator.php`:

```php
<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/CarouselSlideGenerator.php';
$db = Database::getInstance();
$post = $db->fetchOne("SELECT title, excerpt, body FROM blog_posts WHERE status='published' LIMIT 1");
$slides = CarouselSlideGenerator::generate($post);
echo json_encode($slides, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
```

Run: `php /tmp/test-generator.php`
Expected: valid JSON with `hook_en`, `hook_ar`, `tension`, three `points`, `takeaway_en/ar`, `cta_en/ar`.

Delete the smoke test after verifying.

- [ ] **Step 3: Commit**

```bash
git add includes/CarouselSlideGenerator.php
git commit -m "feat: CarouselSlideGenerator, Claude Sonnet 4.6 blog → slide JSON"
```

---

## Phase 3, HTML Template + Playwright Renderer

### Task 4: Create template workspace + install fonts

**Files:**
- Create: `tools/carousel-render/package.json`
- Create: `tools/carousel-render/fonts/` (empty dir, populated by commands)
- Create: `tools/carousel-render/mashrabiya.svg`

- [ ] **Step 1: Create package.json**

```json
{
  "name": "carousel-render",
  "version": "1.0.0",
  "private": true,
  "scripts": {
    "render": "node render.js"
  },
  "dependencies": {
    "playwright": "^1.47.0"
  }
}
```

Note: do NOT run `npm install` yet, VPS already has `/usr/local/bin/playwright`. Local install is only for testing; deploy step runs `npm install --omit=dev` on VPS.

- [ ] **Step 2: Download font WOFF2 files (commit them, small, self-hosted)**

```bash
mkdir -p tools/carousel-render/fonts
cd tools/carousel-render/fonts

curl -sL -o SpaceGrotesk-Bold.woff2 \
  "https://cdn.jsdelivr.net/fontsource/fonts/space-grotesk@latest/latin-700-normal.woff2"
curl -sL -o Inter-Regular.woff2 \
  "https://cdn.jsdelivr.net/fontsource/fonts/inter@latest/latin-400-normal.woff2"
curl -sL -o NotoSansArabic-Bold.woff2 \
  "https://cdn.jsdelivr.net/fontsource/fonts/noto-sans-arabic@latest/arabic-700-normal.woff2"
curl -sL -o NotoSansArabic-Regular.woff2 \
  "https://cdn.jsdelivr.net/fontsource/fonts/noto-sans-arabic@latest/arabic-400-normal.woff2"
```

Verify each file >5KB: `ls -la tools/carousel-render/fonts/`

- [ ] **Step 3: Create mashrabiya.svg (simple Omani-inspired geometric pattern)**

File: `tools/carousel-render/mashrabiya.svg`

```xml
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120" width="120" height="120">
  <defs>
    <pattern id="m" patternUnits="userSpaceOnUse" width="60" height="60">
      <g stroke="#fafaf7" stroke-width="0.8" fill="none">
        <polygon points="30,4 52,17 52,43 30,56 8,43 8,17"/>
        <polygon points="30,14 42,21 42,35 30,42 18,35 18,21"/>
        <line x1="30" y1="4" x2="30" y2="14"/>
        <line x1="30" y1="42" x2="30" y2="56"/>
        <line x1="8" y1="17" x2="18" y2="21"/>
        <line x1="52" y1="17" x2="42" y2="21"/>
        <line x1="8" y1="43" x2="18" y2="35"/>
        <line x1="52" y1="43" x2="42" y2="35"/>
      </g>
    </pattern>
  </defs>
  <rect width="120" height="120" fill="url(#m)"/>
</svg>
```

- [ ] **Step 4: Commit**

```bash
git add tools/carousel-render/package.json tools/carousel-render/fonts/ tools/carousel-render/mashrabiya.svg
git commit -m "feat: carousel renderer scaffold, fonts + motif + package.json"
```

### Task 5: Build HTML template

**Files:**
- Create: `tools/carousel-render/template.html`
- Create: `tools/carousel-render/template.css`

- [ ] **Step 1: Create template.html (7-slide structure with placeholders)**

```html
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Cardify Carousel</title>
<link rel="stylesheet" href="template.css">
</head>
<body>

<!-- Slide 01, Hook -->
<section class="slide hook">
  <div class="motif"></div>
  <span class="counter">01/07</span>
  <div class="content">
    <h1 class="hook-en">{{HOOK_EN}}</h1>
    <h2 class="hook-ar" dir="rtl" lang="ar">{{HOOK_AR}}</h2>
  </div>
  <span class="wordmark">Cardify</span>
</section>

<!-- Slide 02, Tension -->
<section class="slide tension">
  <span class="counter">02/07</span>
  <div class="content">
    <p class="tension-text">{{TENSION}}</p>
  </div>
  <span class="wordmark">Cardify</span>
</section>

<!-- Slide 03, Point 1 -->
<section class="slide point">
  <span class="counter">03/07</span>
  <div class="content">
    <span class="big-num">{{POINT_1_NUM}}</span>
    <p class="point-text">{{POINT_1_TEXT}}</p>
  </div>
  <span class="wordmark">Cardify</span>
</section>

<!-- Slide 04, Point 2 -->
<section class="slide point">
  <span class="counter">04/07</span>
  <div class="content">
    <span class="big-num">{{POINT_2_NUM}}</span>
    <p class="point-text">{{POINT_2_TEXT}}</p>
  </div>
  <span class="wordmark">Cardify</span>
</section>

<!-- Slide 05, Point 3 -->
<section class="slide point">
  <span class="counter">05/07</span>
  <div class="content">
    <span class="big-num">{{POINT_3_NUM}}</span>
    <p class="point-text">{{POINT_3_TEXT}}</p>
  </div>
  <span class="wordmark">Cardify</span>
</section>

<!-- Slide 06, Takeaway -->
<section class="slide takeaway">
  <span class="counter">06/07</span>
  <div class="content">
    <p class="takeaway-en">"{{TAKEAWAY_EN}}"</p>
    <p class="takeaway-ar" dir="rtl" lang="ar">"{{TAKEAWAY_AR}}"</p>
  </div>
  <span class="wordmark">Cardify</span>
</section>

<!-- Slide 07, CTA -->
<section class="slide cta">
  <div class="motif"></div>
  <span class="counter cta-counter">07/07</span>
  <div class="content">
    <p class="cta-en">{{CTA_EN}}</p>
    <p class="cta-ar" dir="rtl" lang="ar">{{CTA_AR}}</p>
    <div class="cta-url">cardify.om</div>
    <img class="qr" src="{{QR_DATA_URL}}" alt="cardify.om QR">
  </div>
</section>

</body>
</html>
```

- [ ] **Step 2: Create template.css**

```css
@font-face {
  font-family: 'Space Grotesk';
  src: url('fonts/SpaceGrotesk-Bold.woff2') format('woff2');
  font-weight: 700;
  font-display: block;
}
@font-face {
  font-family: 'Inter';
  src: url('fonts/Inter-Regular.woff2') format('woff2');
  font-weight: 400;
  font-display: block;
}
@font-face {
  font-family: 'Noto Sans Arabic';
  src: url('fonts/NotoSansArabic-Bold.woff2') format('woff2');
  font-weight: 700;
  font-display: block;
}
@font-face {
  font-family: 'Noto Sans Arabic';
  src: url('fonts/NotoSansArabic-Regular.woff2') format('woff2');
  font-weight: 400;
  font-display: block;
}

:root {
  --bg: #0a0a0a;
  --fg: #fafaf7;
  --accent: #2563eb;
}

* { box-sizing: border-box; margin: 0; padding: 0; }

html, body { background: var(--bg); color: var(--fg); }

.slide {
  width: 1080px;
  height: 1350px;
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 96px;
  page-break-after: always;
  overflow: hidden;
  background: var(--bg);
}
.slide:last-child { page-break-after: auto; }

.content { position: relative; z-index: 2; width: 100%; }

.counter {
  position: absolute;
  top: 64px;
  right: 72px;
  font-family: 'Inter', sans-serif;
  font-size: 22px;
  letter-spacing: 0.2em;
  color: var(--fg);
  opacity: 0.7;
  z-index: 3;
}
.cta-counter { color: var(--fg); opacity: 0.9; }

.wordmark {
  position: absolute;
  bottom: 64px;
  left: 72px;
  font-family: 'Space Grotesk', sans-serif;
  font-weight: 700;
  font-size: 28px;
  letter-spacing: -0.01em;
  color: var(--fg);
  opacity: 0.55;
  z-index: 3;
}

.motif {
  position: absolute;
  inset: 0;
  background-image: url('mashrabiya.svg');
  background-size: 240px 240px;
  opacity: 0.05;
  z-index: 1;
}

/* Slide 01, Hook */
.hook .content { text-align: center; }
.hook .hook-en {
  font-family: 'Space Grotesk', sans-serif;
  font-weight: 700;
  font-size: 132px;
  line-height: 1.02;
  letter-spacing: -0.03em;
  color: var(--fg);
}
.hook .hook-ar {
  margin-top: 56px;
  font-family: 'Noto Sans Arabic', sans-serif;
  font-weight: 700;
  font-size: 68px;
  line-height: 1.35;
  color: var(--fg);
  opacity: 0.85;
}

/* Slide 02, Tension */
.tension .content { text-align: left; }
.tension .tension-text {
  font-family: 'Space Grotesk', sans-serif;
  font-weight: 700;
  font-size: 82px;
  line-height: 1.1;
  letter-spacing: -0.025em;
  color: var(--fg);
}

/* Slides 03-05, Points */
.point .content { text-align: left; }
.point .big-num {
  display: block;
  font-family: 'Space Grotesk', sans-serif;
  font-weight: 700;
  font-size: 320px;
  line-height: 0.9;
  color: var(--accent);
  letter-spacing: -0.05em;
  margin-bottom: 24px;
}
.point .point-text {
  font-family: 'Space Grotesk', sans-serif;
  font-weight: 700;
  font-size: 64px;
  line-height: 1.15;
  letter-spacing: -0.02em;
  color: var(--fg);
  max-width: 820px;
}

/* Slide 06, Takeaway */
.takeaway .content { text-align: center; }
.takeaway .takeaway-en {
  font-family: 'Space Grotesk', sans-serif;
  font-weight: 700;
  font-size: 96px;
  line-height: 1.1;
  letter-spacing: -0.025em;
  color: var(--fg);
}
.takeaway .takeaway-ar {
  margin-top: 56px;
  font-family: 'Noto Sans Arabic', sans-serif;
  font-weight: 700;
  font-size: 56px;
  line-height: 1.4;
  color: var(--fg);
  opacity: 0.85;
}

/* Slide 07, CTA */
.cta { background: var(--accent); }
.cta .motif { opacity: 0.08; }
.cta .content { text-align: center; }
.cta .cta-en {
  font-family: 'Space Grotesk', sans-serif;
  font-weight: 700;
  font-size: 88px;
  line-height: 1.08;
  letter-spacing: -0.02em;
  color: var(--fg);
}
.cta .cta-ar {
  margin-top: 40px;
  font-family: 'Noto Sans Arabic', sans-serif;
  font-weight: 700;
  font-size: 52px;
  color: var(--fg);
  opacity: 0.92;
}
.cta .cta-url {
  margin-top: 72px;
  font-family: 'Space Grotesk', sans-serif;
  font-weight: 700;
  font-size: 48px;
  letter-spacing: 0.02em;
  color: var(--fg);
}
.cta .qr {
  display: block;
  margin: 56px auto 0;
  width: 280px;
  height: 280px;
  border-radius: 20px;
  background: var(--fg);
  padding: 16px;
}
.cta .wordmark { display: none; } /* CTA slide uses its own logo zone */
```

- [ ] **Step 3: Commit**

```bash
git add tools/carousel-render/template.html tools/carousel-render/template.css
git commit -m "feat: carousel HTML + CSS template (7 slides, bilingual)"
```

### Task 6: Build Node renderer (`render.js`)

**Files:**
- Create: `tools/carousel-render/render.js`

- [ ] **Step 1: Write the renderer**

```javascript
#!/usr/bin/env node
/**
 * Cardify LinkedIn Carousel Renderer
 * Usage: node render.js <input.json> <output.pdf>
 * Input JSON: { hook_en, hook_ar, tension, points:[{number,text}x3],
 *               takeaway_en, takeaway_ar, cta_en, cta_ar, qr_data_url }
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

async function main() {
  const [inputPath, outputPath] = process.argv.slice(2);
  if (!inputPath || !outputPath) {
    console.error('Usage: node render.js <input.json> <output.pdf>');
    process.exit(1);
  }

  const data = JSON.parse(fs.readFileSync(inputPath, 'utf8'));
  const template = fs.readFileSync(path.join(__dirname, 'template.html'), 'utf8');

  const rendered = template
    .replace('{{HOOK_EN}}', escapeHtml(data.hook_en))
    .replace('{{HOOK_AR}}', escapeHtml(data.hook_ar))
    .replace('{{TENSION}}', escapeHtml(data.tension))
    .replace('{{POINT_1_NUM}}', escapeHtml(data.points[0].number))
    .replace('{{POINT_1_TEXT}}', escapeHtml(data.points[0].text))
    .replace('{{POINT_2_NUM}}', escapeHtml(data.points[1].number))
    .replace('{{POINT_2_TEXT}}', escapeHtml(data.points[1].text))
    .replace('{{POINT_3_NUM}}', escapeHtml(data.points[2].number))
    .replace('{{POINT_3_TEXT}}', escapeHtml(data.points[2].text))
    .replace('{{TAKEAWAY_EN}}', escapeHtml(data.takeaway_en))
    .replace('{{TAKEAWAY_AR}}', escapeHtml(data.takeaway_ar))
    .replace('{{CTA_EN}}', escapeHtml(data.cta_en))
    .replace('{{CTA_AR}}', escapeHtml(data.cta_ar))
    .replace('{{QR_DATA_URL}}', data.qr_data_url || '');

  const tmpHtml = path.join(__dirname, '.tmp-render.html');
  fs.writeFileSync(tmpHtml, rendered);

  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1080, height: 1350 } });
  await page.goto('file://' + tmpHtml, { waitUntil: 'networkidle' });
  await page.evaluateHandle('document.fonts.ready');

  await page.pdf({
    path: outputPath,
    width: '1080px',
    height: '1350px',
    printBackground: true,
    pageRanges: '1-7',
    margin: { top: 0, right: 0, bottom: 0, left: 0 },
  });

  await browser.close();
  fs.unlinkSync(tmpHtml);
  console.log('OK: ' + outputPath);
}

function escapeHtml(s) {
  return String(s || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

main().catch(err => {
  console.error('RENDER ERROR:', err.message);
  process.exit(1);
});
```

- [ ] **Step 2: Smoke test locally (if Playwright available) or skip**

If local machine has Playwright/Chromium:

```bash
cd tools/carousel-render
echo '{
  "hook_en": "Your business card is costing you more than you think.",
  "hook_ar": "بطاقتك تكلّفك أكثر مما تتخيّل",
  "tension": "Most Omani companies still hand out a card that lands in a drawer.",
  "points": [
    {"number":"01","text":"Print cards average 0.300 OMR per handover."},
    {"number":"02","text":"A digital card updates when your title changes, no reprint."},
    {"number":"03","text":"NFC + QR means one tap shares full contact details."}
  ],
  "takeaway_en": "Print for ceremony. Digital for everything else.",
  "takeaway_ar": "الطباعة للمناسبات. والرقمي لكل شيء آخر.",
  "cta_en": "Build yours in 2 minutes",
  "cta_ar": "أنشئ بطاقتك في دقيقتين",
  "qr_data_url": ""
}' > /tmp/carousel-test.json
node render.js /tmp/carousel-test.json /tmp/carousel-test.pdf
ls -la /tmp/carousel-test.pdf
```

Expected: `/tmp/carousel-test.pdf` exists, size >50KB, 7 pages (open to verify).

If Playwright missing locally, defer this test to the VPS deploy phase.

- [ ] **Step 3: Commit**

```bash
git add tools/carousel-render/render.js
git commit -m "feat: Node+Playwright carousel renderer (HTML template → 7-page PDF)"
```

### Task 7: Build `CarouselPDFRenderer` PHP wrapper

**Files:**
- Create: `includes/CarouselPDFRenderer.php`

- [ ] **Step 1: Write the class**

```php
<?php
/**
 * CarouselPDFRenderer
 * PHP wrapper that feeds slide JSON to the Node + Playwright renderer.
 */
class CarouselPDFRenderer {
    public static function render(array $slides, string $outputPdfPath, string $blogUrl): void {
        $toolDir = dirname(__DIR__) . '/tools/carousel-render';
        $renderScript = $toolDir . '/render.js';
        if (!file_exists($renderScript)) {
            throw new RuntimeException("render.js missing at $renderScript");
        }

        $slides['qr_data_url'] = self::qrDataUrl($blogUrl);

        $inputPath = tempnam(sys_get_temp_dir(), 'carousel_in_') . '.json';
        file_put_contents($inputPath, json_encode($slides, JSON_UNESCAPED_UNICODE));

        $cmd = sprintf(
            'cd %s && node render.js %s %s 2>&1',
            escapeshellarg($toolDir),
            escapeshellarg($inputPath),
            escapeshellarg($outputPdfPath)
        );
        exec($cmd, $out, $code);
        @unlink($inputPath);

        if ($code !== 0) {
            throw new RuntimeException("Renderer failed (code=$code): " . implode("\n", $out));
        }
        if (!file_exists($outputPdfPath) || filesize($outputPdfPath) < 5000) {
            throw new RuntimeException("Renderer produced no/empty PDF: $outputPdfPath");
        }
    }

    private static function qrDataUrl(string $url): string {
        // Use Google Chart API fallback, returns PNG bytes we base64-encode
        $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=' . urlencode($url);
        $ctx = stream_context_create(['http' => ['timeout' => 8]]);
        $png = @file_get_contents($qrUrl, false, $ctx);
        if (!$png) return '';
        return 'data:image/png;base64,' . base64_encode($png);
    }
}
```

- [ ] **Step 2: Smoke test (only if Node+Playwright local)**

```bash
php -r "
require 'config.php';
require 'includes/CarouselPDFRenderer.php';
\$slides = json_decode(file_get_contents('/tmp/carousel-test.json'), true);
CarouselPDFRenderer::render(\$slides, '/tmp/via-php.pdf', 'https://cardify.om/blog/test');
echo file_exists('/tmp/via-php.pdf') ? 'OK' : 'FAIL';
"
```

Expected: `OK` printed, `/tmp/via-php.pdf` ~100KB with QR code visible on slide 7.

If deferring to VPS, run equivalent after deploy.

- [ ] **Step 3: Commit**

```bash
git add includes/CarouselPDFRenderer.php
git commit -m "feat: CarouselPDFRenderer PHP wrapper + QR data-URL injection"
```

---

## Phase 4, LinkedIn Document Upload

### Task 8: Build `LinkedInPoster` class

**Files:**
- Create: `includes/LinkedInPoster.php`

- [ ] **Step 1: Write the class**

```php
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
        curl_close($ch);
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
        curl_close($ch);
        if ($err) throw new RuntimeException("LinkedIn cURL: $err");
        $data = json_decode($res, true);
        if ($code >= 400) throw new RuntimeException("LinkedIn HTTP $code: $res");
        return is_array($data) ? $data : [];
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add includes/LinkedInPoster.php
git commit -m "feat: LinkedInPoster, v2 Assets + UGC document (carousel) upload"
```

---

## Phase 5, Orchestrator + Cron Entry

### Task 9: Build `LinkedInCarousel` orchestrator

**Files:**
- Create: `includes/LinkedInCarousel.php`

- [ ] **Step 1: Write the orchestrator**

```php
<?php
require_once __DIR__ . '/CarouselSlideGenerator.php';
require_once __DIR__ . '/CarouselPDFRenderer.php';
require_once __DIR__ . '/LinkedInPoster.php';

/**
 * LinkedInCarousel
 * End-to-end: blog post → slide JSON → PDF → LinkedIn doc post + optional org cross-post.
 * Throws on fatal failure so cron can decide to fall back.
 */
class LinkedInCarousel {
    public static function postForBlog(array $blog, PDO $pdo, string $logFile): array {
        self::log($logFile, "Generating carousel for: {$blog['title']}");

        // 1) Slide JSON via Claude
        $slides = CarouselSlideGenerator::generate($blog);
        self::log($logFile, "Slide JSON generated");

        // 2) Render PDF
        $pdfDir = dirname(__DIR__) . '/uploads/linkedin-carousels';
        if (!is_dir($pdfDir)) mkdir($pdfDir, 0755, true);
        $pdfPath = $pdfDir . '/' . date('Y-m-d') . '-' . self::slugify($blog['slug']) . '.pdf';
        $blogUrl = 'https://cardify.om/blog/' . $blog['slug'];
        CarouselPDFRenderer::render($slides, $pdfPath, $blogUrl);
        self::log($logFile, "PDF rendered: $pdfPath (" . filesize($pdfPath) . " bytes)");

        // 3) Build commentary (short, carousel carries the narrative)
        $commentary = $slides['hook_en']
            . "\n\n" . $slides['hook_ar']
            . "\n\nSwipe through, or read the full post: " . $blogUrl
            . "\n\n#Cardify #Oman #DigitalBusinessCards #Branding";

        // 4) Fetch tokens
        $personalToken = self::setting($pdo, 'linkedin_access_token');
        $personUrn = self::setting($pdo, 'linkedin_person_urn');
        if (!$personalToken || !$personUrn) {
            throw new RuntimeException('Personal LinkedIn token or person URN missing');
        }

        // 5) Personal post
        $personalAuthor = 'urn:li:person:' . $personUrn;
        $personalPostId = LinkedInPoster::postDocument(
            $personalToken,
            $personalAuthor,
            $pdfPath,
            $commentary,
            $blog['title']
        );
        self::log($logFile, "Personal post OK: $personalPostId");

        // 6) Org cross-post (if configured)
        $orgToken = self::setting($pdo, 'linkedin_org_access_token');
        $orgId = self::setting($pdo, 'linkedin_company_id');
        $orgPostId = null;
        if ($orgToken && $orgId) {
            try {
                $orgAuthor = 'urn:li:organization:' . $orgId;
                $orgPostId = LinkedInPoster::postDocument(
                    $orgToken,
                    $orgAuthor,
                    $pdfPath,
                    $commentary,
                    $blog['title']
                );
                self::log($logFile, "Company page post OK: $orgPostId");
            } catch (Throwable $e) {
                self::log($logFile, "Company page post FAILED (personal still OK): " . $e->getMessage());
            }
        } else {
            self::log($logFile, "Skip company post, org token not configured");
        }

        return [
            'pdf_path' => $pdfPath,
            'personal_post_id' => $personalPostId,
            'company_post_id' => $orgPostId,
        ];
    }

    private static function setting(PDO $pdo, string $key): ?string {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $v = $stmt->fetchColumn();
        return $v !== false ? $v : null;
    }

    private static function slugify(string $s): string {
        $s = preg_replace('/[^a-z0-9-]+/i', '-', $s);
        return trim(strtolower($s), '-');
    }

    private static function log(string $file, string $msg): void {
        $dir = dirname($file);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($file, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add includes/LinkedInCarousel.php
git commit -m "feat: LinkedInCarousel orchestrator (blog → slides → PDF → personal + org post)"
```

### Task 10: Build cron entry point

**Files:**
- Create: `cron/linkedin-carousel.php`

- [ ] **Step 1: Write the cron**

```php
<?php
/**
 * LinkedIn Carousel Autoposter
 * Publishes the next-due blog post as a LinkedIn document carousel.
 * On any fatal failure, falls back to legacy text+link poster (linkedin-autoposter.php).
 *
 * Cron: 0 9 * * * php /www/wwwroot/cardify.om/cron/linkedin-carousel.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/LinkedInCarousel.php';

$logFile = __DIR__ . '/../logs/linkedin-carousel.log';
function carouselLog(string $m) {
    global $logFile;
    $d = dirname($logFile); if (!is_dir($d)) mkdir($d, 0755, true);
    file_put_contents($logFile, date('[Y-m-d H:i:s] ') . $m . "\n", FILE_APPEND);
}

try {
    $pdo = Database::getInstance()->getConnection();
    if (!$pdo) throw new RuntimeException('DB not connected');
} catch (Throwable $e) {
    carouselLog('DB ERROR: ' . $e->getMessage());
    fallback('DB init failed');
    exit(1);
}

$today = date('Y-m-d');
$stmt = $pdo->prepare(
    "SELECT id, title, slug, excerpt, body, status
     FROM blog_posts
     WHERE status IN ('draft','published')
       AND DATE(published_at) <= ?
       AND linkedin_posted IS NULL
     ORDER BY published_at ASC
     LIMIT 1"
);
$stmt->execute([$today]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    carouselLog('No posts due today');
    exit(0);
}

if ($post['status'] === 'draft') {
    $pdo->prepare("UPDATE blog_posts SET status='published' WHERE id = ?")->execute([$post['id']]);
    carouselLog("Auto-published draft: {$post['title']}");
}

try {
    $result = LinkedInCarousel::postForBlog($post, $pdo, $logFile);

    $upd = $pdo->prepare(
        "UPDATE blog_posts
         SET linkedin_posted = NOW(),
             linkedin_post_id = ?,
             linkedin_carousel_pdf = ?,
             linkedin_company_post_id = ?
         WHERE id = ?"
    );
    $upd->execute([
        $result['personal_post_id'],
        str_replace(dirname(__DIR__) . '/', '', $result['pdf_path']),
        $result['company_post_id'],
        $post['id'],
    ]);
    carouselLog("SUCCESS: {$post['title']} | personal={$result['personal_post_id']} | company=" . ($result['company_post_id'] ?? 'n/a'));
} catch (Throwable $e) {
    carouselLog("CAROUSEL FAILED for {$post['title']}: " . $e->getMessage());
    fallback("Post {$post['id']}: " . $e->getMessage());
    exit(1);
}

function fallback(string $reason) {
    carouselLog("Invoking legacy poster as fallback, reason: $reason");
    $legacy = __DIR__ . '/linkedin-autoposter.php';
    if (file_exists($legacy)) {
        passthru('php ' . escapeshellarg($legacy));
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add cron/linkedin-carousel.php
git commit -m "feat: cron entry, linkedin-carousel.php with legacy fallback"
```

---

## Phase 6, Org OAuth + Admin Preview

### Task 11: Build `/api/linkedin/connect-company.php`

**Files:**
- Create: `api/linkedin/connect-company.php`

- [ ] **Step 1: Write the endpoint**

```php
<?php
/**
 * LinkedIn OAuth, captures org (Cardify company page) access token.
 * Saves to system_settings.linkedin_org_access_token.
 * Scopes requested: openid profile email w_member_social w_organization_social r_organization_social
 */
require_once __DIR__ . '/../../config.php';

$client_id = defined('LINKEDIN_CLIENT_ID') ? LINKEDIN_CLIENT_ID : '';
$client_secret = defined('LINKEDIN_CLIENT_SECRET') ? LINKEDIN_CLIENT_SECRET : '';
$host = defined('APP_HOST') ? APP_HOST : ($_SERVER['HTTP_HOST'] ?? 'cardify.om');
$redirect_uri = 'https://' . $host . '/api/linkedin/connect-company';

if (isset($_GET['code'])) {
    $ch = curl_init('https://www.linkedin.com/oauth/v2/accessToken');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $_GET['code'],
            'redirect_uri' => $redirect_uri,
            'client_id' => $client_id,
            'client_secret' => $client_secret,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $res = curl_exec($ch); curl_close($ch);
    $data = json_decode($res, true);
    if (empty($data['access_token'])) {
        echo '<h1 style="color:red">OAuth failed</h1><pre>' . htmlspecialchars($res) . '</pre>';
        exit;
    }
    $token = $data['access_token'];
    $expires = isset($data['expires_in']) ? date('Y-m-d H:i:s', time() + $data['expires_in']) : 'unknown';

    // Verify the token can post as Cardify org
    $ch = curl_init('https://api.linkedin.com/v2/organizationAcls?q=roleAssignee&role=ADMINISTRATOR&state=APPROVED');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'X-Restli-Protocol-Version: 2.0.0',
        ],
    ]);
    $aclRes = curl_exec($ch); curl_close($ch);
    $acl = json_decode($aclRes, true);
    $orgs = [];
    foreach (($acl['elements'] ?? []) as $el) {
        if (isset($el['organization'])) $orgs[] = $el['organization'];
    }

    // Save
    $conn = Database::getInstance()->getConnection();
    $stmt = $conn->prepare(
        "INSERT INTO system_settings (id, setting_key, setting_value, updated_at)
         VALUES (UUID(), ?, ?, NOW())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()"
    );
    $stmt->execute(['linkedin_org_access_token', $token]);
    $stmt->execute(['linkedin_org_token_expires', $expires]);

    echo '<html><body style="font-family:system-ui;max-width:640px;margin:60px auto;text-align:center">';
    echo '<h1 style="color:#0a66c2">&#10003; Cardify Company Page Connected</h1>';
    echo '<p>Org access token saved. Expires: ' . htmlspecialchars($expires) . '</p>';
    echo '<p><strong>Organizations this token can post as:</strong></p>';
    echo '<ul style="text-align:left;display:inline-block">';
    foreach ($orgs as $o) echo '<li>' . htmlspecialchars($o) . '</li>';
    if (!$orgs) echo '<li style="color:#c00">(none, you may not be a Cardify page admin, or Community Management API scope is still pending)</li>';
    echo '</ul>';
    echo '<p><a href="/admin">Back to Admin</a></p></body></html>';
    exit;
}

if (isset($_GET['error'])) {
    echo 'OAuth error: ' . htmlspecialchars($_GET['error_description'] ?? $_GET['error']);
    exit;
}

$scopes = 'openid profile email w_member_social w_organization_social r_organization_social';
$state = bin2hex(random_bytes(16));
$authUrl = 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query([
    'response_type' => 'code',
    'client_id' => $client_id,
    'redirect_uri' => $redirect_uri,
    'state' => $state,
    'scope' => $scopes,
]);
header('Location: ' . $authUrl);
exit;
```

- [ ] **Step 2: Remember to add redirect URI to LinkedIn app (deploy task)**

The new URI `https://cardify.om/api/linkedin/connect-company` must be added under **Authorized redirect URLs** in the LinkedIn developer portal for whichever app carries the `w_organization_social` approval. (Deploy task reminder, see Phase 7.)

- [ ] **Step 3: Commit**

```bash
git add api/linkedin/connect-company.php
git commit -m "feat: /api/linkedin/connect-company, OAuth capture for org token"
```

### Task 12: Build admin preview endpoint

**Files:**
- Create: `admin/blog-carousel-preview.php`

- [ ] **Step 1: Write the endpoint**

```php
<?php
/**
 * Admin: generate + return LinkedIn carousel PDF for a blog post on demand.
 * Usage: GET /admin/blog-carousel-preview.php?id=<blog_post_id>
 * Returns PDF inline.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/CarouselSlideGenerator.php';
require_once INCLUDES_DIR . '/CarouselPDFRenderer.php';

requireAdmin();

$id = $_GET['id'] ?? '';
if (!$id) { http_response_code(400); die('missing id'); }

$db = Database::getInstance();
$post = $db->fetchOne("SELECT id, title, slug, excerpt, body FROM blog_posts WHERE id = :id", ['id' => $id]);
if (!$post) { http_response_code(404); die('not found'); }

try {
    $slides = CarouselSlideGenerator::generate($post);
    $pdfPath = sys_get_temp_dir() . '/carousel-preview-' . $id . '.pdf';
    $blogUrl = 'https://cardify.om/blog/' . $post['slug'];
    CarouselPDFRenderer::render($slides, $pdfPath, $blogUrl);

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="carousel-' . $post['slug'] . '.pdf"');
    header('Content-Length: ' . filesize($pdfPath));
    readfile($pdfPath);
    @unlink($pdfPath);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<pre>Preview failed: ' . htmlspecialchars($e->getMessage()) . '</pre>';
}
```

- [ ] **Step 2: Add preview button to blog edit page**

Modify `admin/super/blog.php`, find the blog post edit form's action buttons section and add:

```php
<?php if (!empty($post['id'])): ?>
<a href="/admin/blog-carousel-preview.php?id=<?= htmlspecialchars($post['id']) ?>"
   target="_blank"
   class="btn-secondary"
   style="display:inline-flex;align-items:center;gap:6px;margin-left:8px">
  <i class="fa-brands fa-linkedin"></i> Preview LinkedIn carousel
</a>
<?php endif; ?>
```

(Match existing button styling, may need to adjust class names to match project conventions after inspecting the file.)

- [ ] **Step 3: Commit**

```bash
git add admin/blog-carousel-preview.php admin/super/blog.php
git commit -m "feat: admin preview endpoint + button for LinkedIn carousel"
```

---

## Phase 7, Deploy + Verify

### Task 13: Deploy code to VPS

- [ ] **Step 1: Push branch**

```bash
cd /Users/ali/claude/projects/cardify.om
git push origin design-showcase
```

- [ ] **Step 2: Pull on VPS**

```bash
ssh root@147.93.20.54 "cd /www/wwwroot/cardify.om && git pull origin design-showcase"
```

(Or merge to main and push, depending on Ali's branch policy, confirm before pushing to main.)

- [ ] **Step 3: Run migration**

```bash
ssh root@147.93.20.54 "cd /www/wwwroot/cardify.om && php database/migrations/065_blog_posts_carousel_columns.php"
```

Expected: `Migration 065: blog_posts carousel columns added`.

- [ ] **Step 4: Install Node deps + verify Playwright**

```bash
ssh root@147.93.20.54 "cd /www/wwwroot/cardify.om/tools/carousel-render && npm install --omit=dev && node -e \"require('playwright')\" && echo OK"
```

Expected: `OK`. If Playwright needs its own Chromium, run `npx playwright install chromium` on the VPS (one-time, ~170MB download).

- [ ] **Step 5: Set ANTHROPIC_API_KEY in production config**

```bash
ssh root@147.93.20.54 "grep -q ANTHROPIC_API_KEY /www/wwwroot/cardify.om/config.php || echo \"define('ANTHROPIC_API_KEY', 'REPLACE_ME');\" >> /www/wwwroot/cardify.om/config.php"
```

Then edit `/www/wwwroot/cardify.om/config.php` via `nano` or ssh cat-append to paste the real key. Verify:

```bash
ssh root@147.93.20.54 "php -r \"require '/www/wwwroot/cardify.om/config.php'; echo defined('ANTHROPIC_API_KEY') && strlen(ANTHROPIC_API_KEY) > 20 ? 'OK' : 'MISSING';\""
```

Expected: `OK`.

- [ ] **Step 6: Create upload dir + log file with correct perms**

```bash
ssh root@147.93.20.54 "mkdir -p /www/wwwroot/cardify.om/uploads/linkedin-carousels /www/wwwroot/cardify.om/logs && chown -R www:www /www/wwwroot/cardify.om/uploads/linkedin-carousels /www/wwwroot/cardify.om/logs"
```

- [ ] **Step 7: Add LinkedIn app redirect URI**

Manual step (Ali): in LinkedIn Developer Portal for the active app, add `https://cardify.om/api/linkedin/connect-company` to Authorized redirect URLs. Not blocking for personal-profile carousel posts, only needed for company OAuth.

### Task 14: Live smoke test, preview endpoint

- [ ] **Step 1: Log into admin + hit preview endpoint**

```bash
ssh root@147.93.20.54 "/www/server/php/83/bin/php -r \"
require '/www/wwwroot/cardify.om/config.php';
require '/www/wwwroot/cardify.om/includes/CarouselSlideGenerator.php';
require '/www/wwwroot/cardify.om/includes/CarouselPDFRenderer.php';
\\\$db = Database::getInstance();
\\\$post = \\\$db->fetchOne('SELECT id, title, slug, excerpt, body FROM blog_posts WHERE status=\\'published\\' ORDER BY published_at DESC LIMIT 1');
\\\$slides = CarouselSlideGenerator::generate(\\\$post);
echo 'Slides OK\n';
CarouselPDFRenderer::render(\\\$slides, '/tmp/carousel-smoke.pdf', 'https://cardify.om/blog/'.\\\$post['slug']);
echo 'PDF: '.filesize('/tmp/carousel-smoke.pdf').' bytes\n';
\""</br>
ssh root@147.93.20.54 "ls -la /tmp/carousel-smoke.pdf"
```

Expected: `Slides OK`, PDF >50KB.

- [ ] **Step 2: Download PDF locally + open**

```bash
scp root@147.93.20.54:/tmp/carousel-smoke.pdf /tmp/carousel-smoke.pdf
open /tmp/carousel-smoke.pdf
```

Visual check: 7 pages, bilingual slides rendered, motif subtle, Cardify wordmark bottom-left, QR on last slide.

- [ ] **Step 3: If anything looks off, iterate on template.css and re-render. Only proceed when visually approved.**

### Task 15: Swap cron + first live post

- [ ] **Step 1: Update crontab**

```bash
ssh root@147.93.20.54 "crontab -l | sed 's|cron/linkedin-autoposter.php|cron/linkedin-carousel.php|; s|linkedin-poster.log|linkedin-carousel.log|' | crontab -"
ssh root@147.93.20.54 "crontab -l | grep linkedin"
```

Expected: crontab now points to `linkedin-carousel.php` + `linkedin-carousel.log`.

- [ ] **Step 2: Force a test run manually (DON'T wait 9 AM)**

Pick the next pending post and fire the cron:

```bash
ssh root@147.93.20.54 "/www/server/php/83/bin/php /www/wwwroot/cardify.om/cron/linkedin-carousel.php"
ssh root@147.93.20.54 "tail -40 /www/wwwroot/cardify.om/logs/linkedin-carousel.log"
```

Expected log pattern:
```
Generating carousel for: <title>
Slide JSON generated
PDF rendered: ...
Personal post OK: urn:li:share:...
Skip company post, org token not configured
SUCCESS: <title> | personal=urn:li:share:... | company=n/a
```

- [ ] **Step 3: Open LinkedIn personal profile + verify**

Visit `https://www.linkedin.com/in/<Ali>` and confirm carousel is live. Swipe through all 7 slides. Check bilingual slides render correctly.

If anything looks wrong visually: delete the LinkedIn post manually, null out `linkedin_posted` for that blog row (`UPDATE blog_posts SET linkedin_posted=NULL, linkedin_post_id=NULL WHERE id=...`), fix template/CSS, re-run cron.

- [ ] **Step 4: Commit final tuning (if any CSS tweaks made during smoke test)**

```bash
git add tools/carousel-render/
git commit -m "fix: carousel template tweaks post visual review"
git push origin design-showcase
ssh root@147.93.20.54 "cd /www/wwwroot/cardify.om && git pull origin design-showcase"
```

---

## Phase 8, Post-approval activation (whenever LinkedIn approves Community Management API)

These steps run only after LinkedIn emails approval.

### Task 16: Swap app credentials (if using Cardify - App)

- [ ] **Step 1: If approval is on `Cardify - App` (client 775l8mpkveo3ro), update config:**

```bash
ssh root@147.93.20.54 "sed -i \"s/LINKEDIN_CLIENT_ID', '77j4ugwwoa5wsm'/LINKEDIN_CLIENT_ID', '775l8mpkveo3ro'/\" /www/wwwroot/cardify.om/config.php"
# + paste new client secret manually
```

Add `https://cardify.om/api/linkedin/connect-company` and `https://cardify.om/api/linkedin/callback` to the new app's redirect URLs in LinkedIn portal.

### Task 17: Re-auth personal token + capture org token

- [ ] **Step 1: Visit `https://cardify.om/api/linkedin/callback` in browser (logged in as Ali)**

This refreshes `linkedin_access_token` on the new app.

- [ ] **Step 2: Visit `https://cardify.om/api/linkedin/connect-company`**

This captures `linkedin_org_access_token` with org scopes. Verify the success page shows `urn:li:organization:111727648` under "Organizations this token can post as".

### Task 18: Verify next cron cycle cross-posts to Cardify page

- [ ] **Step 1: Wait for 9 AM cron (or force-run manually)**

- [ ] **Step 2: Verify Cardify page + log**

```bash
ssh root@147.93.20.54 "grep 'Company page post OK' /www/wwwroot/cardify.om/logs/linkedin-carousel.log | tail -3"
```

Expected: "Company page post OK" lines. Open https://www.linkedin.com/company/cardify/ and visually confirm.

---

## Rollback

If something goes seriously wrong after deploy:

```bash
# Restore old cron
ssh root@147.93.20.54 "crontab -l | sed 's|cron/linkedin-carousel.php|cron/linkedin-autoposter.php|; s|linkedin-carousel.log|linkedin-poster.log|' | crontab -"
```

The old `cron/linkedin-autoposter.php` and its log file `logs/linkedin-poster.log` are untouched, so fallback is instant.
