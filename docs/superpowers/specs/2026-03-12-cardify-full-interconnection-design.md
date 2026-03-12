# Cardify.om — Full System Interconnection & Digital Card Page

**Date:** 2026-03-12
**Status:** Approved
**Scope:** Bug fixes, new digital card page, Dardasha WhatsApp integration, email fixes, system wiring

---

## Problem Statement

Cardify.om is 95% built but has several gaps preventing full end-to-end operation:

1. **3 bugs** block core functionality (missing PHP function, ORDER BY wrong column, broken email sending)
2. **No digital card page** — QR codes on printed cards have nowhere useful to land
3. **Share links viewer** is a stub — doesn't show actual employee cards
4. **WhatsApp notifications** use a placeholder API — not wired to Dardasha
5. **Email system** is fully coded but unverified on VPS — may not be sending
6. **Minor wiring gaps** in notification chains and navigation

## Success Criteria

- Scanning a QR code from a printed business card loads a branded digital card page with tap-to-flip animation, contact actions, and vCard download
- Company admins can place print orders and print shops receive WhatsApp notifications via Dardasha
- All email notifications (card proofs, order updates, request approvals) actually deliver
- Share links resolve to the digital card page or a proper template preview
- The full flow works: company setup → template design → employee add → card generate → email/WhatsApp notify → QR scan → digital card → save contact

---

## Section 1: Bug Fixes

### 1a. Missing `findDepartmentById()` function

**File:** `includes/functions.php`

Follow existing pattern (uses `DatabaseAdapter` with fallback check):

```php
function findDepartmentById($departmentId, $companyId = null) {
    if (!class_exists('DatabaseAdapter') || !DatabaseAdapter::useDatabase()) {
        return null;
    }

    return DatabaseAdapter::findDepartmentById($departmentId, $companyId);
}
```

Also add `findDepartmentById()` to `includes/DatabaseAdapter.php` following the existing `findEmployeeById()` pattern:

```php
public static function findDepartmentById($id, $companyId = null) {
    $db = Database::getInstance();
    if ($companyId) {
        return $db->fetchOne('SELECT * FROM departments WHERE id = ? AND company_id = ?', [$id, $companyId]);
    }
    return $db->fetchOne('SELECT * FROM departments WHERE id = ?', [$id]);
}
```

**Impact:** Fixes fatal error in `admin/send_card_email.php` at lines 99 and 144.

### 1b. Fix `card_requests` ORDER BY column

The `card_requests` table has `submitted_at` (not `created_at`). Fix the ORDER BY clause in the code that references `created_at` to use `submitted_at` instead. Do NOT add a duplicate `created_at` column — `submitted_at` is the canonical timestamp.

**Impact:** Fixes ORDER BY error when loading card requests dashboard.

### 1c. `send_card_email.php` null safety

Wrap `findDepartmentById()` calls in null checks — employee may not have a department assigned:

```php
$department = null;
if (!empty($employeeData['department_id'])) {
    $department = findDepartmentById($employeeData['department_id'], $companyId);
}
// Only CC department admin if department exists and has admin_email
if ($department && !empty($department['admin_email'])) {
    // add CC
}
```

---

## Section 2: Digital Card Page

### Route

**URL:** `/{company_slug}/card/{employee_id}`
**Nginx rewrite:** `^/([a-z0-9-]+)/card/([^/]+)/?$` → `digital_card.php?company_slug=$1&employee_id=$2`

The nginx rewrite uses `([^/]+)` for employee_id (not hex-only regex) since employee IDs are `VARCHAR(36)` and may contain mixed case. PHP-side validates the UUID format.

This rewrite rule must appear BEFORE the company slug catchall in the nginx config so it doesn't fall through to `router.php`.

**File:** `digital_card.php` (new, in project root)

### Data Loading

1. Look up company by slug via `findCompanyBySlug()` → branded 404 if not found ("This card is no longer available")
2. Look up employee by ID + company_id via `findEmployeeById()` → branded 404 if not found
3. Load company theme (logo, colors) from `company_themes`
4. Load latest generated card from `generated_cards` (ORDER BY `generated_at DESC LIMIT 1` for this employee)
5. Log QR scan to `qr_scans` table via existing `QRTracker` class (deduplicates by `visitor_id` cookie)
6. Build vCard download URL: `/{company_slug}/{employee_email}.vcf` (existing `vcf.php` endpoint uses email, not ID)

### Adaptive Theme Detection

Compute once during card generation, cache in `generated_cards` table:

**Migration:** Add `theme_mode ENUM('light','dark') DEFAULT NULL` to `generated_cards` table.

During batch generation:
1. Read front template's background color from `settings_json` (e.g., `{"backgroundColor": "#1a1a2e"}`)
2. If solid color → calculate luminance: `(0.299*R + 0.587*G + 0.114*B)`
3. If background image → sample average brightness of center 100x100px region using GD `imagecreatefrompng()`
4. **Luminance < 128** → `theme_mode = 'dark'` (dark card → light page)
5. **Luminance >= 128** → `theme_mode = 'light'` (light card → dark page)
6. Store in `generated_cards.theme_mode`

At render time, `digital_card.php` reads `theme_mode` from the DB — no image processing on page load.

**Fallback:** If `theme_mode` is NULL (legacy cards), use company theme's `secondary_color` to decide. If no theme exists, default to dark page.

Company `primary_color` used for accent elements (buttons, links) in both modes.

### Page Layout (top → bottom, mobile-first)

1. **Company logo** — from `company_themes.logo_path`, centered, ~120px wide
2. **Flippable card** — CSS 3D perspective transform, tap to toggle front/back
   - Front face: `<img>` of web-optimized card front PNG
   - Back face: `<img>` of web-optimized card back PNG
   - "Tap card to flip" hint, fades after first interaction
3. **Employee name + title** — centered below card
4. **Action buttons row** — up to 3 equal buttons:
   - **Call** → `tel:+968XXXXXXXX` (uses mobile number, falls back to phone) — only if phone/mobile exists
   - **WhatsApp** → `https://api.whatsapp.com/send?phone=968XXXXXXXX` (NOT wa.me — blocked in Oman) — only if mobile exists
   - **Email** → `mailto:employee@company.com` — only if email exists
5. **Contact details list** — rounded card with tappable rows:
   - Phone, Mobile, Email, Website, Address — each with icon, tappable action
   - Only show rows where data exists
6. **Save Contact + Share** — two buttons:
   - Save Contact → downloads `.vcf` via `/{company_slug}/{employee_email}.vcf`
   - Share → native Web Share API (`navigator.share`) with fallback to copy-link modal
7. **Footer** — "Powered by Cardify" with link
8. **Branded 404** — If company/employee not found, show styled page: "This card is no longer available" with company logo if possible

### Card Image Optimization

During batch card generation (`admin/batch_generate.php`), generate an additional web-optimized version:

- **Full resolution (existing):** 1050×600px PNG — used for print and admin download
- **Web optimized (new):** 788×450px PNG (~150 DPI equivalent) — used for digital card page
- Filename pattern: `card_front_web_{timestamp}_{hash}.png` / `card_back_web_{timestamp}_{hash}.png`
- Stored alongside full-res in `/uploads/companies/{id}/cards/`

**Migration:** Add `front_web_path VARCHAR(500) DEFAULT NULL` and `back_web_path VARCHAR(500) DEFAULT NULL` to `generated_cards` table to store web-optimized file paths.

If web version doesn't exist (legacy cards), fall back to full-res with CSS `max-width` and `loading="lazy"`.

### CSS Flip Animation

```css
.card-flip-container {
    perspective: 1000px;
    cursor: pointer;
    max-width: 400px;
    margin: 0 auto;
}
.card-flip-inner {
    position: relative;
    width: 100%;
    aspect-ratio: 1050/600;
    transition: transform 0.7s cubic-bezier(0.4, 0, 0.2, 1);
    transform-style: preserve-3d;
}
.card-flip-inner.flipped {
    transform: rotateY(180deg);
}
.card-face {
    position: absolute;
    width: 100%;
    height: 100%;
    backface-visibility: hidden;
    -webkit-backface-visibility: hidden;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0,0,0,0.15);
}
.card-face img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.card-back-face {
    transform: rotateY(180deg);
}
```

Toggle via: `element.classList.toggle('flipped')` on click/tap.

Note: `-webkit-backface-visibility` required for Safari/iOS support.

---

## Section 3: Share Links Fix

### Current State

`share/index.php` is a stub that shows a placeholder template preview.

### Schema Reference

- `share_links` table: uses `token` column (VARCHAR 100), has `company_id` and `employee_id`
- `design_links` table: uses `share_token` column (VARCHAR 64), has `company_id` and `template_id`, supports `password_hash`, `max_access`, `is_active`

### Fix

Rewrite `share/index.php` as a thin gateway:

1. Take token from `$_GET['token']`
2. **Query both tables** (different column names):
   ```sql
   -- Try share_links first
   SELECT sl.*, c.slug as company_slug FROM share_links sl
   JOIN companies c ON c.id = sl.company_id
   WHERE sl.token = ?

   -- If not found, try design_links
   SELECT dl.*, c.slug as company_slug FROM design_links dl
   JOIN companies c ON c.id = dl.company_id
   WHERE dl.share_token = ? AND dl.is_active = 1
   ```
3. Check expiration (`expires_at`) — show styled "Link expired" message
4. Check password protection (`password_hash` on design_links) — show password form if set
5. Check max access (`max_access` on design_links vs `access_count`) — show limit message if exceeded
6. Increment counter: `view_count` for share_links, `access_count` for design_links
7. **Route based on link type:**
   - **Employee share link** (from `share_links`, has `employee_id`) → redirect to `/{company_slug}/card/{employee_id}` (slug from JOIN)
   - **Design/template link** (from `design_links`, has `template_id`) → render template preview with sample data using adaptive theme

This avoids duplicating card rendering logic — the digital card page handles all employee card display.

---

## Section 4: Dardasha WhatsApp Integration

### Current State

`includes/WhatsApp.php` uses a placeholder API URL (`api.whatsapp.com/v1/messages`) with generic Bearer token auth.

### Changes to `includes/WhatsApp.php`

Replace the placeholder with Dardasha REST API:

- **API endpoint:** Loaded from `system_settings` key `whatsapp_api_url` (default: `https://dardasha.om/api/send-message`)
- **Auth:** Dardasha API token from `system_settings` key `whatsapp_api_token`
- **Session:** Dardasha session ID from `system_settings` key `whatsapp_session_id`

**Note:** `system_settings` table has `id VARCHAR(36)` as PK and `setting_key VARCHAR(100) UNIQUE`. All inserts must include `id` via `generateUUID()`, matching the existing pattern in `WhatsApp.php`.

- **Phone number format:** Dardasha expects numbers WITHOUT `+` prefix (e.g., `968XXXXXXXX`). Strip any leading `+` from phone numbers before sending.
- **Payload format:**
  ```json
  {
      "phone": "968XXXXXXXX",
      "message": "Your card is ready!",
      "sessionId": "anna"
  }
  ```
- **Response handling:** Dardasha returns `{ success: true/false, messageId: "..." }`

### Notification Points

| Event | Recipient | Message |
|-------|-----------|---------|
| Print order placed | Print shop (phone from `print_shops` table) | "New print order #{order_number} from {company}. {quantity} cards. View at cardify.om" |
| Print order status: shipped | Company admin (notification_email → look up phone) | "Your print order #{order_number} has been shipped. Tracking: {tracking_number}" |
| Print order status: delivered | Company admin | "Your print order #{order_number} has been delivered." |
| Card request approved | Employee (mobile number) | "Your business card has been approved and generated! View: {digital_card_url}" |
| Card generated (batch) | Employee (mobile number) | "Your new business card is ready! View: {digital_card_url}" |

Only send if:
- WhatsApp is enabled (`whatsapp_enabled = true` in system_settings)
- Recipient has a valid phone number (not empty, not null)
- Dardasha session is active

### Admin Config UI

Update `admin/whatsapp_settings.php`:
- Field: API URL (default: `https://dardasha.om/api/send-message`)
- Field: API Token
- Field: Session ID (e.g., "anna")
- Test button: sends a test message to the super admin's phone number to verify connectivity. Shows success/failure result inline.
- Status indicator: shows if Dardasha session is connected

---

## Section 5: Email — Verify & Fix

### Current State

`includes/Mailer.php` is a complete raw-socket SMTP implementation with TLS support, email logging, and branded HTML templates. Configuration via `config.php` constants or `system_settings` table override.

### Tasks

1. **Check VPS SMTP config:** Verify `config.php` on VPS has correct `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION` values. If not, set via super admin Email Settings panel at `/admin/super/email_settings.php`.

2. **Test email delivery:** Use the built-in test email function at `/admin/super/email_settings.php` to verify emails actually send.

3. **Fix `send_card_email.php`:** Already covered in Section 1 (missing function + null checks).

4. **Verify all notification emails fire:**

| Event | Method | Recipient |
|-------|--------|-----------|
| Card generated | `Mailer::sendCardProof()` | Employee email |
| Card request submitted | `Mailer::send()` | Company admin email |
| Card request approved | `Mailer::send()` | Employee email |
| Print order quotation issued | `sendDocumentEmail()` in printshop | Company admin email |
| Print order shipped | `sendDocumentEmail()` in printshop | Company admin email |

5. **Email logging:** All sends already log to `email_logs` table — verify logs appear after test sends.

---

## Section 6: Minor Wiring Fixes

### 6a. QR Code Destination Update

Currently QR codes on generated cards encode a URL pointing to `qr.php` (direct VCF download). Update batch_generate to encode the new digital card page URL instead:

- **Old:** `https://cardify.om/qr.php?c={slug}&e={email}`
- **New:** `https://cardify.om/{slug}/card/{employee_id}`

The digital card page logs the scan (same `QRTracker` class, deduplicates by visitor cookie) and has a Save Contact button for VCF download. Better UX than auto-downloading a file.

### 6b. Navigation Consistency

Company admin sidebar currently shows super-admin-only links (Companies, All Employees, Print Shops, Subscriptions, Email Logs). These should be hidden for company admin users — only show for super admins.

Check the role/permission logic in the sidebar template and conditionally render these menu items.

### 6c. Print Shop Notification Chain

Verify end-to-end that when `PrintShopIntegration::createOrder()` is called:
1. Order record is created in `print_orders`
2. WhatsApp notification fires to print shop (via Dardasha)
3. Email notification fires to print shop (via Mailer)
4. Print shop sees the order in their dashboard

---

## Database Migrations

| Table | Change |
|-------|--------|
| `generated_cards` | Add `front_web_path VARCHAR(500) DEFAULT NULL` |
| `generated_cards` | Add `back_web_path VARCHAR(500) DEFAULT NULL` |
| `generated_cards` | Add `theme_mode ENUM('light','dark') DEFAULT NULL` |

No changes to `card_requests` — fix the PHP code to use `submitted_at` instead of `created_at`.

---

## Files Changed

| File | Change Type |
|------|------------|
| `includes/functions.php` | Add `findDepartmentById()` |
| `includes/DatabaseAdapter.php` | Add `findDepartmentById()` static method |
| `admin/send_card_email.php` | Fix null safety around department lookup |
| `digital_card.php` (new) | Digital card page with adaptive theme + flip animation |
| `share/index.php` | Rewrite as gateway → redirect to digital card or render template |
| `includes/WhatsApp.php` | Replace placeholder API with Dardasha integration |
| `admin/whatsapp_settings.php` | Update UI for Dardasha config (API URL, session ID) |
| `admin/batch_generate.php` | Generate web-optimized card images + update QR code URL + compute theme_mode |
| Nginx rewrite conf | Add `/{slug}/card/{id}` route before catchall |
| DB migration (new) | Add `front_web_path`, `back_web_path`, `theme_mode` to `generated_cards` |
| Sidebar template | Hide super-admin links for company admin role |
| `admin/requests.php` (or similar) | Change `ORDER BY created_at` → `ORDER BY submitted_at` |

## Out of Scope

- Print shop ERP integration (Odoo sync) — already working, no changes
- Billing/subscription system — already working
- Template editor (Fabric.js) — already working
- Bulk import — already working
- Blog/careers/marketing pages — not related
