# Cardify.om SEO Phase 1: Technical SEO Foundation

> **Goal**: Make Cardify.om fully discoverable by search engines, optimized for social sharing, and instrumented with analytics, targeting 1000+ customers through organic search in Oman.

## Context

Cardify.om is a SaaS business card generator (PHP, MySQL, Tailwind). It has a solid landing page, blog system, careers page, and clean company URLs. But it's invisible to search engines due to missing fundamentals: no robots.txt, no sitemap, no OG tags, no structured data, no analytics.

## Scope

Phase 1 covers technical SEO infrastructure only. Content marketing (Phase 2) and paid channels (Phase 3) are separate projects.

---

## Section 1: Core SEO Infrastructure

### 1.1 robots.txt
Create `/robots.txt` at project root:
- Allow all crawlers on public pages
- Disallow: `/admin/`, `/install/`, `/api/`, `/data/`, `/logs/`, `/uploads/`, `/printshop/`, `/paymob/`, `/webhooks/`, `/amwalpay/`
- Disallow company admin paths: `*/admin/*`
- Reference sitemap: `https://cardify.om/sitemap.xml`

### 1.2 Dynamic Sitemap (sitemap.xml)
Create `/sitemap.php` that outputs XML sitemap:
- **Static pages**: `/`, `/about`, `/blog`, `/careers`, `/contact`, `/intro`, `/terms`, `/privacy`, `/security`, `/cookies`
- **Blog posts**: Query `blog_posts` where `status = 'published'`, use `updated_at` as lastmod
- **Career listings**: Query `career_listings` where `status = 'open'`
- **Company pages**: Query `companies` where `status = 'active'`, URL `/{slug}/`
- **Digital cards**: Query `generated_cards` JOIN `employees` JOIN `companies`, URL `/{slug}/card/{employee_id}`
- Add `.htaccess` rewrite: `/sitemap.xml` → `sitemap.php`
- Priority: homepage 1.0, about/blog 0.8, posts 0.6, cards 0.4

### 1.3 Google Analytics
- Add `GA_MEASUREMENT_ID` to `config.example.php` (and actual config on VPS)
- Add gtag.js script to `includes/ui-header.php`, only when GA_MEASUREMENT_ID is configured
- Fire on all pages (public and admin, admin traffic is negligible)

### 1.4 Google Search Console
- Add `GOOGLE_SITE_VERIFICATION` to config
- Add `<meta name="google-site-verification">` to ui-header.php when configured

---

## Section 2: Meta Tags & Social Sharing

### 2.1 Open Graph Tags
Add to `includes/ui-header.php` after existing meta tags:
```html
<meta property="og:site_name" content="Cardify">
<meta property="og:type" content="website">
<meta property="og:title" content="{$pageTitle}">
<meta property="og:description" content="{$pageDescription}">
<meta property="og:url" content="{$canonicalUrl}">
<meta property="og:image" content="{baseUrl}assets/images/cardify-og.png">
<meta property="og:locale" content="en_US">
```

### 2.2 Twitter Card Tags
```html
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{$pageTitle}">
<meta name="twitter:description" content="{$pageDescription}">
<meta name="twitter:image" content="{baseUrl}assets/images/cardify-og.png">
```

### 2.3 Canonical URL
```html
<link rel="canonical" href="{$canonicalUrl}">
```

### 2.4 Per-Page Variables
Every public page must set before including ui-header.php:
- `$pageTitle`, Already exists, keep using
- `$pageDescription`, Unique, keyword-rich, 120-160 chars
- `$canonicalUrl`, Full URL for that page
- `$ogImage`, Optional override (default: cardify-og.png)
- `$ogType`, Optional override (default: "website", blog posts use "article")

### 2.5 OG Image
Create a 1200x630 branded PNG at `assets/images/cardify-og.png`:
- Cardify logo
- Tagline: "Business Cards Made Simple"
- Brand colors
- Professional, clean design

---

## Section 3: JSON-LD Structured Data

### 3.1 Organization Schema (homepage only)
```json
{
  "@context": "https://schema.org",
  "@type": "Organization",
  "name": "Cardify",
  "url": "https://cardify.om",
  "logo": "https://cardify.om/assets/images/logo.svg",
  "description": "SaaS platform for creating and managing professional business cards in Oman",
  "address": { "@type": "PostalAddress", "addressCountry": "OM" },
  "sameAs": ["https://instagram.com/cardifyom"]
}
```

### 3.2 SoftwareApplication Schema (homepage)
```json
{
  "@context": "https://schema.org",
  "@type": "SoftwareApplication",
  "name": "Cardify",
  "applicationCategory": "BusinessApplication",
  "operatingSystem": "Web",
  "offers": {
    "@type": "Offer",
    "price": "0",
    "priceCurrency": "OMR",
    "description": "Free plan with 10 employees"
  }
}
```

### 3.3 Article Schema (blog posts)
On individual blog post pages, add Article schema with:
- headline, datePublished, dateModified, author, image, publisher

### 3.4 JobPosting Schema (career listings)
On individual job pages, add JobPosting schema with:
- title, description, datePosted, employmentType, jobLocation, hiringOrganization

### 3.5 BreadcrumbList Schema (all pages)
Navigation breadcrumbs for Google search results.

---

## Section 4: Clean URLs

### 4.1 Blog Clean URLs
Add to `.htaccess`:
```
RewriteRule ^blog/?$ blog.php [L,QSA]
RewriteRule ^blog/([a-z0-9-]+)/?$ blog.php?post=$1 [L,QSA]
```
Update all internal blog links to use `/blog/{slug}` format.

### 4.2 Career Clean URLs
```
RewriteRule ^careers/?$ careers.php [L,QSA]
RewriteRule ^careers/([a-z0-9-]+)/?$ careers.php?job=$1 [L,QSA]
```
Update all internal career links.

### 4.3 Static Page Clean URLs
```
RewriteRule ^about/?$ about.php [L,QSA]
RewriteRule ^contact/?$ contact.php [L,QSA]
RewriteRule ^terms/?$ terms.php [L,QSA]
RewriteRule ^privacy/?$ privacy.php [L,QSA]
RewriteRule ^security/?$ security.php [L,QSA]
RewriteRule ^cookies/?$ cookies.php [L,QSA]
```

---

## Section 5: Viral Growth Mechanics

### 5.1 "Powered by Cardify" on Digital Cards
Add to `digital_card.php` footer:
```html
<a href="https://cardify.om?ref=card" style="subtle styling">
  Powered by Cardify
</a>
```
- Small, non-intrusive, at bottom of card page
- Includes `?ref=card` for tracking referral source
- Links to Cardify homepage

### 5.2 Email Card Deliveries
When cards are emailed (`admin/send_card_email.php`), include footer:
```
Create your team's digital business cards at cardify.om
```

---

## Section 6: Performance Fixes

### 6.1 Image Lazy Loading
Add `loading="lazy"` to all `<img>` tags in public pages.

### 6.2 Page Loader
Remove or reduce the 800ms artificial delay in the page loader animation.

### 6.3 HSTS Header
Uncomment/enable HSTS in `.htaccess`:
```
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
```

---

## Section 7: Page-Specific SEO Content

### Target Keywords (Oman market)
Primary: "business cards Oman", "digital business cards", "order business cards Muscat"
Secondary: "print business cards Oman", "company business cards", "business card design Oman"
Long-tail: "free business card maker Oman", "digital NFC business cards Muscat", "bulk business cards Oman"

### Page Descriptions
| Page | Title | Description |
|------|-------|-------------|
| index | Cardify - Business Cards Made Simple | Create, manage, and print professional business cards for your team in Oman. Digital & printed cards with QR codes. Free to start. |
| about | About Cardify - Oman's Business Card Platform | Cardify helps Omani businesses create stunning digital and printed business cards. Serving 500+ companies across the Sultanate. |
| blog | Cardify Blog - Business Card Tips & Trends | Expert tips on business card design, networking, and professional branding for Omani businesses. |
| careers | Careers at Cardify | Join Oman's leading business card platform. View open positions in development, design, and sales. |
| contact | Contact Cardify - Get in Touch | Questions about business cards? Contact Cardify for support, partnerships, or print shop inquiries in Oman. |
| intro | How Cardify Works - Business Cards in Minutes | See how easy it is to design, generate, and share professional business cards with Cardify. Free for Omani businesses. |
| terms | Terms of Service - Cardify | Terms and conditions for using Cardify's business card platform in Oman. |
| privacy | Privacy Policy - Cardify | How Cardify protects your data. Privacy policy for our business card platform. |

---

## Files to Create
- `robots.txt`, Crawler directives
- `sitemap.php`, Dynamic XML sitemap
- `assets/images/cardify-og.png`, Social sharing image

## Files to Modify
- `includes/ui-header.php`, OG tags, Twitter cards, canonical, GA, JSON-LD, Search Console
- `.htaccess`, Blog/career/static clean URL rewrites, sitemap rewrite, HSTS
- `index.php`, Organization + SoftwareApplication JSON-LD, updated meta
- `about.php`, Updated meta description
- `blog.php`, Article JSON-LD, updated meta, clean URL links
- `careers.php`, JobPosting JSON-LD, updated meta, clean URL links
- `contact.php`, Updated meta description
- `intro.php`, Updated meta description
- `terms.php`, `privacy.php`, `security.php`, `cookies.php`, Updated meta
- `digital_card.php`, "Powered by Cardify" footer
- `admin/send_card_email.php`, Cardify footer in emails
- `config.example.php`, GA_MEASUREMENT_ID, GOOGLE_SITE_VERIFICATION constants
