# Cardify Site-Wide Standards

This document defines all standard patterns for card generation, UI components, and template fields to ensure consistency across the entire site.

---

## Card Generation Standards

### Canvas Dimensions

| Setting | Value |
|---------|-------|
| Canvas Width | 1050px |
| Canvas Height | 600px |
| Background Color | #ffffff |
| Aspect Ratio | 3.5:2 (standard business card) |
| DPI Reference | 300 DPI |

### Background Image Scaling

Use "cover" mode - scale to fill canvas while maintaining aspect ratio:

```javascript
const scaleX = canvas.width / img.width;
const scaleY = canvas.height / img.height;
const scale = Math.max(scaleX, scaleY);  // Cover mode

img.set({
    scaleX: scale,
    scaleY: scale,
    originX: 'left',
    originY: 'top',
    left: 0,
    top: 0,
    selectable: false,
    evented: false
});
```

### Export Quality Settings

| Export Type | Multiplier | Effective DPI | Use Case |
|-------------|------------|---------------|----------|
| PNG Standard | 3x | ~300 DPI | Web display, general use |
| PNG High Quality | 4x | ~400 DPI | High-quality prints |
| PDF Print | 6x | ~600 DPI | Professional printing |

```javascript
// Standard export
cardEditor.exportPNG(3);

// High quality export
cardEditor.exportPNGBlob(4);

// PDF export (uses 6x internally)
cardEditor.exportPDF('card.pdf');
```

---

## QR Code Standards

### Default Settings

| Setting | Value |
|---------|-------|
| Default Size | 140-150px |
| Front Card Position | x: 880, y: 420 |
| Back Card Position | x: 850, y: 250 |
| Error Correction | 'M' (medium) |
| Library | qrcode-generator |

### QR Code URL Format (Tracking)

Always use the tracking URL format to enable scan analytics:

```
/qr.php?c={company_slug}&e={employee_email}
```

**PHP Generation:**
```php
$vcfUrl = VCF::getUrl($employee, $company);
// Returns: https://cardify.om/qr.php?c=acme&e=user@email.com
```

**JavaScript Generation:**
```javascript
function getVcfUrl(email) {
    return window.location.origin + basePath + 'qr.php?c=' + 
           encodeURIComponent(companySlug) + '&e=' + encodeURIComponent(email);
}
```

### QR Code Implementation

```javascript
await cardEditor.addQRCode(vcfUrl, {
    x: fields.qr_code.x,
    y: fields.qr_code.y,
    size: fields.qr_code.size || 140
});
```

---

## Font Standards

### Font Families

| Language | Primary Font | Fallback |
|----------|-------------|----------|
| English | Inter | sans-serif |
| Arabic | Cairo | sans-serif |

### Available Fonts (Pre-loaded)

- **English:** Inter, Plus Jakarta Sans, Montserrat
- **Arabic:** Cairo, Tajawal, Almarai

### Font Sizes by Field Type

| Field | Font Size | Font Weight |
|-------|-----------|-------------|
| Name | 28px | bold |
| Position/Title | 16px | normal |
| Company | 14px | 500 |
| Phone/Mobile/Email | 13-14px | normal |
| Website | 12-13px | normal |
| Address | 11-12px | normal |

### Font Loading

```html
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&family=Tajawal:wght@400;500;700&family=Almarai:wght@400;700&family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
```

---

## Text Alignment Standards

### Alignment by Language

| Field Type | textAlign | originX | originY |
|------------|-----------|---------|---------|
| English fields (_en) | left | left | top |
| Arabic fields (_ar) | right | right | top |
| Centered fields | center | center | top |

### Implementation

```javascript
// Automatic alignment based on field name
const textAlign = field.textAlign || (key.endsWith('_ar') ? 'right' : 'left');
const originX = field.originX || (textAlign === 'center' ? 'center' : textAlign);

cardEditor.addTextField(key, {
    text: value,
    x: field.x,
    y: field.y,
    fontSize: field.fontSize,
    fontFamily: field.fontFamily,
    fontWeight: field.fontWeight,
    fill: field.fill || field.color,
    textAlign: textAlign,
    originX: originX,
    originY: 'top'
});
```

---

## Template Field Standards

### Field Keys

**English Fields:**
- `name_en` - Full name
- `position_en` - Job title
- `company_en` - Company name
- `phone` - Phone number
- `mobile` - Mobile number
- `email` - Email address
- `website` - Website URL
- `address_en` - Address

**Arabic Fields:**
- `name_ar`, `position_ar`, `company_ar`
- `phone_ar`, `mobile_ar`, `website_ar`, `address_ar`

**Special Fields:**
- `qr_code` - QR code (has `size` instead of font properties)

### Default Field Positions (1050x600 canvas)

| Field | X | Y | Font Size | Color |
|-------|---|---|-----------|-------|
| name_en | 50 | 60 | 28 | #1f2937 |
| name_ar | 1000 | 60 | 28 | #1f2937 |
| position_en | 50 | 100 | 16 | #6b7280 |
| position_ar | 1000 | 100 | 16 | #6b7280 |
| company_en | 50 | 130 | 14 | #374151 |
| phone | 50 | 450 | 14 | #374151 |
| mobile | 50 | 480 | 14 | #374151 |
| email | 50 | 510 | 14 | #374151 |
| website | 50 | 540 | 12 | #6b7280 |
| address_en | 50 | 560 | 11 | #6b7280 |
| qr_code | 880 | 420 | size: 140 | N/A |

### Field Structure

```php
'field_name' => [
    'enabled' => true,          // boolean
    'x' => 50,                  // pixels from left
    'y' => 60,                  // pixels from top
    'fontSize' => 28,           // pixels
    'fontFamily' => 'Inter',    // font name
    'fontWeight' => 'bold',     // normal, bold, 500, etc.
    'fill' => '#1f2937',        // hex color (Fabric.js)
    'color' => '#1f2937',       // hex color (backward compat)
    'textAlign' => 'left',      // left, center, right
    'originX' => 'left',        // left, center, right
    'originY' => 'top'          // top, center, bottom
]
```

---

## UI Component Standards

### Button Classes

**Primary Button (Blue) - Main Actions:**
```html
<button class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg shadow-md transition-colors">
    Action
</button>
```

**Success Button (Green) - Confirmations:**
```html
<button class="px-6 py-3 bg-green-600 hover:bg-green-700 text-white font-semibold rounded-lg shadow-md transition-colors">
    Submit
</button>
```

**Secondary Button (Gray) - Cancel/Back:**
```html
<button class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg font-medium transition-colors">
    Cancel
</button>
```

**Danger Button (Red) - Destructive Actions:**
```html
<button class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg font-medium transition-colors">
    Delete
</button>
```

**Text Button - Minimal Actions:**
```html
<button class="px-4 py-2 text-gray-600 hover:text-gray-900 font-medium transition-colors">
    Edit
</button>
```

**With Icon:**
```html
<button class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg shadow-md transition-colors flex items-center justify-center gap-2">
    <i class="fa-solid fa-icon-name"></i>
    Action
</button>
```

**Full Width:**
```html
<button class="w-full px-6 py-3 ...">
```

> **IMPORTANT:** Avoid gradient buttons (`bg-gradient-to-r from-purple-600...`) as they may not render consistently across all browsers.

### Form Input Classes

**Standard Input:**
```html
<input class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 placeholder-gray-400 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 transition-all">
```

**Select Dropdown:**
```html
<select class="px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-900 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
```

**Textarea:**
```html
<textarea class="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
```

### Card/Container Classes

**Standard Card:**
```html
<div class="bg-white rounded-xl border border-gray-200 shadow-sm">
```

**Card with Padding:**
```html
<div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
```

**Info Card (colored):**
```html
<div class="bg-blue-50 border border-blue-200 rounded-lg p-4">  <!-- Info -->
<div class="bg-green-50 border border-green-200 rounded-lg p-3"> <!-- Success -->
<div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3"> <!-- Warning -->
<div class="bg-red-50 border border-red-200 rounded-lg p-4">   <!-- Error -->
```

### Alert/Notification Classes

**Toast Notifications:**
```html
<div class="fixed bottom-4 right-4 bg-green-500 text-white px-4 py-2 rounded-lg shadow-lg z-50">
    Success message
</div>
```

**Toast Colors:**
- Success: `bg-green-500`
- Error: `bg-red-500`
- Warning: `bg-yellow-500`
- Info: `bg-blue-500`

### Loading States

**Spinner Icon:**
```html
<i class="fa-solid fa-spinner fa-spin"></i>
```

**Button Loading State:**
```html
<button disabled class="... opacity-50 cursor-not-allowed">
    <i class="fa-solid fa-spinner fa-spin mr-2"></i>
    Loading...
</button>
```

**Page Loader:**
```css
.page-loader {
    position: fixed;
    inset: 0;
    background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    z-index: 99999;
}
```

---

## Color Palette

### Primary Colors

| Usage | Color Name | Tailwind Class | Hex |
|-------|------------|----------------|-----|
| Primary | Blue | `blue-600` | #2563eb |
| Primary Hover | Blue Dark | `blue-700` | #1d4ed8 |
| Success | Green | `green-600` | #16a34a |
| Danger | Red | `red-600` | #dc2626 |
| Warning | Yellow | `yellow-500` | #eab308 |

### Text Colors

| Usage | Tailwind Class | Hex |
|-------|----------------|-----|
| Primary Text | `text-gray-900` | #111827 |
| Secondary Text | `text-gray-600` | #4b5563 |
| Muted Text | `text-gray-500` | #6b7280 |
| Disabled Text | `text-gray-400` | #9ca3af |

### Background Colors

| Usage | Tailwind Class | Hex |
|-------|----------------|-----|
| Page Background | `bg-gray-50` | #f9fafb |
| Card Background | `bg-white` | #ffffff |
| Input Background | `bg-gray-50` | #f9fafb |
| Hover Background | `bg-gray-100` | #f3f4f6 |

### Border Colors

| Usage | Tailwind Class | Hex |
|-------|----------------|-----|
| Default Border | `border-gray-200` | #e5e7eb |
| Focus Border | `border-blue-500` | #3b82f6 |
| Error Border | `border-red-500` | #ef4444 |

---

## Header/Footer Standards

### Header (Sticky)

```html
<header class="bg-white/80 backdrop-blur-md border-b border-gray-100 sticky top-0 z-50">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
        <div class="flex items-center justify-between">
            <!-- Logo and title -->
            <div class="flex items-center gap-3">
                <img src="logo.png" class="h-10 w-auto rounded-xl">
                <div>
                    <h1 class="text-lg font-bold text-gray-900">Title</h1>
                    <p class="text-xs text-gray-500">Subtitle</p>
                </div>
            </div>
            <!-- Actions -->
            <div class="flex items-center gap-2">
                <!-- buttons/links -->
            </div>
        </div>
    </div>
</header>
```

### Footer

```html
<footer class="border-t border-gray-200 bg-white mt-8">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="flex flex-col md:flex-row items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <img src="logo.png" class="h-8 opacity-60">
                <p class="text-sm text-gray-500">&copy; 2026 Company Name</p>
            </div>
            <div class="flex items-center gap-6 text-sm text-gray-500">
                <a href="#" class="hover:text-gray-700">Link 1</a>
                <a href="#" class="hover:text-gray-700">Link 2</a>
                <span class="text-gray-300">|</span>
                <span>Powered by <a href="#" class="text-blue-600 hover:underline">Cardify</a></span>
            </div>
        </div>
    </div>
</footer>
```

---

## Canvas Display Scaling

When displaying a 1050x600 canvas in a smaller container, use CSS scaling:

```css
.preview-card canvas {
    width: 100% !important;
    height: auto !important;
    max-width: 100%;
}

.preview-card {
    aspect-ratio: 3.5 / 2;
}
```

The canvas internally renders at 1050x600, but CSS scales the display to fit the container while maintaining aspect ratio.

---

## URL Routing Structure

### Authentication URLs

| URL | Handler | Purpose |
|-----|---------|---------|
| `/login.php` | login.php | Unified login for all users (super admin, company admin, print shop, employee) |
| `/logout.php` | logout.php | Session logout |
| `/forgot-password.php` | forgot-password.php | Password reset request |
| `/reset-password.php` | reset-password.php | Password reset handler |

> **Note:** All role-specific login pages redirect to the unified `/login.php`

### Public URLs

| URL Pattern | Handler | Purpose |
|-------------|---------|---------|
| `/` | index.php | Main landing page |
| `/intro` | intro.php | Interactive onboarding |
| `/about` | about.php | About page |
| `/contact` | contact.php | Contact form |
| `/print-shops` | print-shops.php | Public print shop directory |
| `/privacy`, `/terms`, `/cookies` | Static pages | Legal pages |

### Company-Specific URLs

| URL Pattern | Handler | Purpose |
|-------------|---------|---------|
| `/{slug}/` | router.php | Company public page |
| `/{slug}/admin/` | company_admin.php | Company admin dashboard |
| `/{slug}/admin/{page}` | company_admin.php | Company admin pages |
| `/{slug}/portal` | portal.php | Employee card request portal |
| `/{slug}/portal/{dept}` | portal.php | Department-specific portal |
| `/{slug}/{email}.vcf` | vcf.php | VCF file download with tracking |

### Admin Panel URLs (Super Admin)

| URL Pattern | Handler | Purpose |
|-------------|---------|---------|
| `/admin/` | admin/index.php | Super admin dashboard |
| `/admin/companies` | admin/companies.php | Manage companies |
| `/admin/plans` | admin/plans.php | Subscription plans |
| `/admin/print_shops` | admin/print_shops.php | Manage print shops |
| `/admin/print_orders` | admin/print_orders.php | All print orders |
| `/admin/updates` | admin/updates.php | Database migrations |

### Print Shop Portal URLs

| URL Pattern | Handler | Purpose |
|-------------|---------|---------|
| `/printshop/` | printshop/dashboard.php | Print shop dashboard |
| `/printshop/register` | printshop/register.php | Print shop registration |
| `/printshop/orders` | printshop/orders.php | Order management |
| `/printshop/order/{id}` | printshop/order.php | Single order details |
| `/printshop/settings` | printshop/settings.php | Shop pricing/services |
| `/printshop/profile` | printshop/profile.php | Shop public profile |

### API/Utility URLs

| URL Pattern | Handler | Purpose |
|-------------|---------|---------|
| `/qr.php?c={slug}&e={email}` | qr.php | QR code tracking → VCF |
| `/api/check-slug.php` | api/check-slug.php | Slug availability check |
| `/api/translate.php` | api/translate.php | AI translation |
| `/save_card_image.php` | save_card_image.php | Save generated card |
| `/log_generation.php` | log_generation.php | Log card generation |

### .htaccess Rewrite Rules

The routing is managed by `.htaccess` with the following patterns:

```apache
# VCF files (for QR codes)
RewriteRule ^([a-z0-9-]+)/([^/]+)\.vcf$ vcf.php?company=$1&email=$2 [L,QSA]

# Company admin
RewriteRule ^([a-z0-9-]+)/admin/?$ company_admin.php?company_slug=$1&page=index [L,QSA]
RewriteRule ^([a-z0-9-]+)/admin/([a-z0-9_-]+)/?$ company_admin.php?company_slug=$1&page=$2 [L,QSA]

# Department-specific portal
RewriteRule ^([a-z0-9-]+)/portal/([a-z0-9-]+)/?$ portal.php?company_slug=$1&department_slug=$2 [L,QSA]

# Company portal
RewriteRule ^([a-z0-9-]+)/portal/?$ portal.php?company_slug=$1 [L,QSA]

# Print shop clean URLs
RewriteRule ^printshop/order/([0-9]+)/?$ printshop/order.php?id=$1 [L,QSA]

# Company public page (catch-all)
RewriteRule ^([a-z0-9-]+)/?$ router.php?company_slug=$1 [L,QSA]
```

### User Roles and Access

| Role | Primary Access | Redirect After Login |
|------|---------------|---------------------|
| `super_admin` | `/admin/` | `/admin/` |
| `company_admin` | `/{slug}/admin/` | `/{slug}/admin/` |
| `print_shop` | `/printshop/` | `/printshop/dashboard.php` |
| `employee` | `/{slug}/portal` | `/{slug}/` |

---

## Summary Checklist

Before implementing any card generation or UI feature, verify:

- [ ] Canvas is 1050x600 pixels
- [ ] Background uses cover scaling (`Math.max`)
- [ ] QR code uses tracking URL format (`/qr.php?c=&e=`)
- [ ] English text uses Inter font, Arabic uses Cairo
- [ ] Text alignment matches field language
- [ ] Buttons use solid colors (no gradients)
- [ ] Primary actions use `bg-blue-600`
- [ ] Success/submit actions use `bg-green-600`
- [ ] All form inputs have consistent styling
- [ ] Loading states use spinner icon
