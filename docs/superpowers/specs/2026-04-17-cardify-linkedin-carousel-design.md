# Cardify LinkedIn Carousel Autoposter, Design

**Date**: 2026-04-17
**Status**: Design / pre-implementation
**Replaces**: `cron/linkedin-autoposter.php` (text + link, retained as fallback)

## Goal

Replace the plain text + link daily LinkedIn post with a bold, bilingual carousel PDF, generated from each scheduled blog post. Post from Ali's personal profile today; cross-post from the Cardify company page the moment LinkedIn approves the Community Management API.

## Context

- Current cron `0 9 * * *` posts `{title}\n\n{excerpt}\n\nRead more: {url}\n\n#hashtags` as a LinkedIn UGC Share with the blog URL as an article link.
- 49 draft blog posts already scheduled through Dec 2026 (`blog_posts` table, status = draft, `published_at` set).
- Personal OAuth token valid until **2026-05-13** on app **Cardify** (`77j4ugwwoa5wsm`).
- **Cardify - App** (`775l8mpkveo3ro`) has Community Management API in review (10, 14 business days). Once approved, production config switches to this app for both personal + org posting (single-app consolidation, one OAuth round required).
- Cardify company page ID: `111727648`.

## Visual Style

Bold / viral LinkedIn carousel aesthetic + subtle Oman-native motifs + Cardify brand restraint (per Ali: "I want Cardify to be subtle").

### Palette
- Background: `#0a0a0a` (near-black)
- Primary text: `#fafaf7` (cream)
- Accent: `#2563eb` (Cardify blue), used sparingly, CTA slide only
- AR text: cream at 85% opacity

### Typography
- EN display: **Space Grotesk Bold** (self-hosted WOFF2)
- EN body: **Inter Regular**
- AR display + body: **Noto Sans Arabic** (Bold + Regular)
- Tight tracking on display sizes (120, 180pt on hook)

### Branding (subtle)
- Cardify wordmark bottom-left on every slide, 14pt, 60% opacity
- Single Omani mashrabiya-inspired geometric motif as 5% opacity background texture, cover (slide 1) and CTA (slide 7) only

### Slide counter
- "01/07" top-right on every slide, the B-style cue that signals "keep swiping"

### Slide dimensions
- 1080 × 1350 px (4:5 aspect ratio, LinkedIn feed-optimal)

## Slide Structure (7 slides per post)

| # | Purpose | Layout | Language |
|---|---------|--------|----------|
| 01 | Cover / hook (question or stat pulled from blog) | Centered giant display text | **EN + AR** |
| 02 | Tension / problem setup | Left-aligned, 60pt | EN |
| 03 | Key point 1 | Big numeral "01" + statement | EN |
| 04 | Key point 2 | Big numeral "02" + statement | EN |
| 05 | Key point 3 | Big numeral "03" + statement | EN |
| 06 | Takeaway / aha moment | Centered, quote-style treatment | **EN + AR** |
| 07 | CTA (logo + `cardify.om` + QR code) | Cardify blue background | **EN + AR** |

AR appears only on slides 1, 6, 7 (entrance, aha, exit), respects bilingual audience without diluting scroll speed. AR rendered right-to-left with `dir="rtl"` in template.

## Generation Pipeline

```
Cron 9 AM GST
  → Fetch next due post (status='draft' AND published_at <= today AND linkedin_posted IS NULL)
  → If status = draft: UPDATE status = 'published'
  → Call Claude Sonnet 4.6: blog body → structured 7-slide JSON (validated)
  → Render HTML template with slide JSON → headless Chromium (Playwright) → 7-page PDF
  → Upload PDF to LinkedIn as document UGC post (personal profile)
  → If linkedin_org_access_token exists: cross-post from Cardify page (organization URN)
  → Save PDF to uploads/linkedin-carousels/YYYY-MM-DD-slug.pdf
  → UPDATE blog_posts SET linkedin_posted=NOW(), linkedin_post_id, linkedin_carousel_pdf, linkedin_company_post_id
```

## Claude API Contract

**Model**: `claude-sonnet-4-6` (fast, cheap enough at ~1 call/day, plus manual preview calls).

**Input**:
```json
{
  "title": "Print vs Digital Business Cards: Which is Right for Your Omani Company?",
  "excerpt": "...",
  "body": "...(full blog HTML stripped to text)..."
}
```

**Output** (strict JSON schema, rejected + retried once if invalid):
```json
{
  "hook_en": "Your business card is costing you more than you think.",
  "hook_ar": "بطاقتك تكلّفك أكثر مما تتخيّل.",
  "tension": "Most Omani companies still hand out a card that ends up in a drawer.",
  "points": [
    {"number": "01", "text": "Print cards average 0.300 OMR per handover and land in a drawer within 48 hours."},
    {"number": "02", "text": "A digital card updates when your title changes, no reprint, no waste."},
    {"number": "03", "text": "NFC + QR means one tap shares full contact details, straight to their phone."}
  ],
  "takeaway_en": "Print for ceremony. Digital for everything else.",
  "takeaway_ar": "الطباعة للمناسبات. والرقمي لكل شيء آخر.",
  "cta_en": "Build yours in 2 minutes at cardify.om",
  "cta_ar": "أنشئ بطاقتك في دقيقتين"
}
```

Prompt instructs model: hooks must be contrarian or curiosity-inducing; AR must be natural Gulf Arabic, not machine-literal.

## File Layout

```
cardify.om/
├── cron/
│   ├── linkedin-autoposter.php                 # existing; retained as fallback
│   └── linkedin-carousel.php                   # NEW, entry point
├── includes/
│   ├── LinkedInCarousel.php                    # NEW, orchestrator
│   ├── CarouselSlideGenerator.php              # NEW, Claude API caller + JSON validator
│   └── CarouselPDFRenderer.php                 # NEW, HTML → PDF via Playwright
├── templates/
│   └── carousel/
│       ├── slide.html                          # NEW, 7-slide HTML (one per page)
│       ├── slide.css                           # NEW, all slide styling
│       ├── mashrabiya.svg                      # NEW, Omani geometric motif
│       └── fonts/                              # NEW, Space Grotesk, Inter, Noto Sans Arabic WOFF2
├── api/
│   └── linkedin/
│       ├── callback.php                        # existing, saves linkedin_access_token
│       └── connect-company.php                 # NEW, OAuth w/ w_organization_social → saves linkedin_org_access_token
├── admin/
│   └── blog-carousel-preview.php               # NEW, generate + return PDF for any post
├── uploads/
│   └── linkedin-carousels/                     # NEW, PDF archive
└── logs/
    └── linkedin-carousel.log                   # NEW
```

## Database Changes

```sql
ALTER TABLE blog_posts
  ADD COLUMN linkedin_carousel_pdf VARCHAR(500) NULL AFTER linkedin_post_id,
  ADD COLUMN linkedin_company_post_id VARCHAR(255) NULL AFTER linkedin_carousel_pdf;
```

Existing columns used: `linkedin_posted`, `linkedin_post_id`.

`system_settings` table receives (via OAuth callback):
- `linkedin_org_access_token`
- `linkedin_org_token_expires`

## Cron Replacement

```
# replace existing
0 9 * * * php /www/wwwroot/cardify.om/cron/linkedin-carousel.php >> /www/wwwroot/cardify.om/logs/linkedin-carousel.log 2>&1
```

If `linkedin-carousel.php` exits with `fallback` signal, a subsequent command chains to the old poster, or the script itself invokes the fallback inline.

## Error Handling

| Failure | Behavior |
|---------|----------|
| Claude API timeout / invalid JSON (after 1 retry) | Fall back to legacy `linkedin-autoposter.php` for this post. Log reason. |
| Playwright render fails | Same fallback. |
| LinkedIn document upload fails | Log, exit 1, DB untouched, cron retries tomorrow same post (still `linkedin_posted IS NULL`). |
| Personal post OK but company cross-post fails | Log, continue, personal post already counted. Do not retry org post. |
| Org token expired / missing | Skip org post silently, log "org token missing, reconnect at /api/linkedin/connect-company". |
| No draft post due today | Log "nothing due", exit 0. |

## OAuth, `/api/linkedin/connect-company.php`

Requests scopes: `openid profile email w_member_social w_organization_social r_organization_social`.

Callback logic:
1. Exchange code → access token
2. Save as `linkedin_org_access_token` (separate from personal `linkedin_access_token`)
3. Confirm Cardify (`111727648`) is among the organizations the token can post as (`/v2/organizationAcls?q=roleAssignee`)
4. Display success + company verified

When `Cardify - App` approval arrives, config updates `LINKEDIN_CLIENT_ID` + `LINKEDIN_CLIENT_SECRET` to the new app's credentials; one visit to `/api/linkedin/connect-company` captures both personal and org scopes in a single OAuth round (they're all in the requested scope list).

## Admin UI, Carousel Preview

New button on `admin/super/blog.php` edit page: **"Preview LinkedIn carousel"**.

- `POST /admin/blog-carousel-preview.php?id={post_id}`, generates fresh PDF on demand, returns it inline (`Content-Type: application/pdf`).
- No approval gate for the cron. Preview is a pre-flight check; if Ali dislikes the copy, he tweaks the blog content and re-previews.

## Success Criteria

1. Cron runs 9 AM GST daily, posts carousel to LinkedIn personal profile.
2. PDF renders with correct bilingual layout, AR right-to-left on slides 1, 6, 7; EN left-to-right elsewhere.
3. Subtle Cardify branding (per Ali's guidance), wordmark not dominant; accent colour only on CTA slide.
4. Company page cross-post activates automatically when `linkedin_org_access_token` is populated.
5. Preview button in admin generates PDF for any blog post in <10 seconds.
6. Legacy text-link poster remains as fallback path and is triggered automatically on pipeline failure.

## Out of Scope (for this iteration)

- PDF carousel for existing Instagram / other social channels
- Multi-brand (BHD, CupsByAA, etc.) carousel generation
- A/B testing different templates
- Scheduling UI (existing `published_at` field is the schedule)
- Analytics fetching from LinkedIn API
