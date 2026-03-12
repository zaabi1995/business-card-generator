# Cardify.om — Full System Interconnection & Digital Card Page

**Date:** 2026-03-12
**Status:** Approved
**Scope:** Bug fixes, new digital card page, Dardasha WhatsApp integration, email fixes, system wiring

---

## Problem Statement

Cardify.om is 95% built but has several gaps preventing full end-to-end operation:

1. **3 bugs** block core functionality (missing PHP function, missing DB column, broken email sending)
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

Add function:
```php
function findDepartmentById($departmentId, $companyId) {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare('SELECT * FROM departments WHERE id = ? AND company_id = ?');
    $stmt->execute([$departmentId, $companyId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
```

**Impact:** Fixes fatal error in `admin/send_card_email.php` at lines 99 and 144.

### 1b. Missing `created_at` column on `card_requests`

**Migration:** Add `created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP` to `card_requests` table if not present.

Run on VPS:
```sql
ALTER TABLE card_requests ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;
```

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
**Nginx rewrite:** `^/([a-z0-9-]+)/card/([a-f0-9-]+)/?$` → `digital_card.php?company_slug=$1&employee_id=$2`
**File:** `digital_card.php` (new, in project root)

### Data Loading

1. Look up company by slug → 404 if not found
2. Look up employee by ID + company_id → 404 if not found
3. Load company theme (logo, colors) from `company_themes`
4. Load latest generated card from `generated_cards` (front + back web-optimized images)
5. Log QR scan to `qr_scans` table (IP, user agent, device type, geolocation)

### Adaptive Theme Detection

PHP determines page theme based on card background brightness:

1. Read the front template's `settings_json` or `background_image_path`
2. If solid background color → calculate luminance: `(0.299*R + 0.587*G + 0.114*B)`
3. If background image → sample average brightness of center region using GD
4. **Luminance < 128** → dark card → **light page** (white/gray background)
5. **Luminance >= 128** → light card → **dark page** (navy/charcoal background)
6. **Fallback:** If no template/card exists, use company theme's `secondary_color` to decide
7. Company `primary_color` used for accent elements (buttons, links) in both modes

### Page Layout (top → bottom, mobile-first)

1. **Company logo** — from `company_themes.logo_path`, centered, ~120px wide
2. **Flippable card** — CSS 3D perspective transform, tap to toggle front/back
   - Front face: `<img>` of web-optimized card front PNG
   - Back face: `<img>` of web-optimized card back PNG
   - "Tap card to flip" hint, fades after first interaction
3. **Employee name + title** — centered below card
4. **Action buttons row** — 3 equal buttons:
   - **Call** → `tel:+968XXXXXXXX` (uses mobile number, falls back to phone)
   - **WhatsApp** → `https://api.whatsapp.com/send?phone=968XXXXXXXX` (NOT wa.me — blocked in Oman)
   - **Email** → `mailto:employee@company.com`
   - Only show buttons where data exists (hide WhatsApp if no mobile, etc.)
5. **Contact details list** — rounded card with tappable rows:
   - Phone, Mobile, Email, Website, Address — each with icon, tappable action
   - Only show rows where data exists
6. **Save Contact + Share** — two buttons:
   - Save Contact → downloads `.vcf` file (existing `vcf.php` endpoint)
   - Share → native Web Share API (`navigator.share`) with fallback to copy-link
7. **Footer** — "Powered by Cardify" with link

### Card Image Optimization

During batch card generation (`admin/batch_generate.php`), generate an additional web-optimized version:

- **Full resolution (existing):** 1050×600px PNG — used for print and admin download
- **Web optimized (new):** 788×450px PNG (~150 DPI equivalent) — used for digital card page
- Filename pattern: `card_front_web_{timestamp}_{hash}.png` / `card_back_web_{timestamp}_{hash}.png`
- Stored alongside full-res in `/uploads/companies/{id}/cards/`
- If web version doesn't exist (legacy cards), fall back to full-res with CSS `max-width`

### CSS Flip Animation

```css
.card-flip-container {
    perspective: 1000px;
    cursor: pointer;
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
    border-radius: 10px;
    overflow: hidden;
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

---

## Section 3: Share Links Fix

### Current State

`share/index.php` is a stub that shows a placeholder template preview.

### Fix

Rewrite `share/index.php` as a thin gateway:

1. Validate share token from `share_links` or `design_links` table
2. Check password protection (if set) — show password form
3. Check expiration — show expired message
4. Increment `access_count` / `view_count`
5. **Route based on link type:**
   - **Employee share link** (`share_links` with `employee_id`) → redirect to `/{company_slug}/card/{employee_id}`
   - **Design/template link** (`design_links` with `template_id`) → render template preview with sample data using adaptive theme
6. Password-protected links: validate password, then redirect/render

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
| Print order status: shipped | Company admin (from `companies.notification_email` or look up phone) | "Your print order #{order_number} has been shipped. Tracking: {tracking_number}" |
| Print order status: delivered | Company admin | "Your print order #{order_number} has been delivered." |
| Card request approved | Employee (mobile number) | "Your business card has been approved and generated! View: {digital_card_url}" |
| Card generated (batch) | Employee (mobile number) | "Your new business card is ready! View: {digital_card_url}" |

Only send if:
- WhatsApp is enabled (`whatsapp_enabled = true`)
- Recipient has a valid phone number
- Dardasha session is active

### Admin Config UI

Update `admin/whatsapp_settings.php`:
- Field: API URL (default: `https://dardasha.om/api/send-message`)
- Field: API Token
- Field: Session ID (e.g., "anna")
- Test button: sends a test message to verify connectivity
- Status indicator: shows if Dardasha session is connected

---

## Section 5: Email — Verify & Fix

### Current State

`includes/Mailer.php` is a complete raw-socket SMTP implementation with TLS support, email logging, and branded HTML templates. Configuration via `config.php` constants or `system_settings` table override.

### Tasks

1. **Check VPS SMTP config:** Verify `config.php` on VPS has correct `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION` values. If not, set via super admin Email Settings panel.

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

The digital card page logs the scan (same analytics) and has a Save Contact button for VCF download. Better UX than auto-downloading a file.

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

## Files Changed

| File | Change Type |
|------|------------|
| `includes/functions.php` | Add `findDepartmentById()` |
| `admin/send_card_email.php` | Fix null safety around department lookup |
| `digital_card.php` (new) | Digital card page with adaptive theme + flip animation |
| `share/index.php` | Rewrite as gateway → redirect to digital card or render template |
| `includes/WhatsApp.php` | Replace placeholder API with Dardasha integration |
| `admin/whatsapp_settings.php` | Update UI for Dardasha config (API URL, session ID) |
| `admin/batch_generate.php` | Generate web-optimized card images + update QR code URL |
| `company_admin.php` | Add route for `card` page (if needed) |
| Nginx rewrite conf | Add `/{slug}/card/{uuid}` route |
| DB migration | Add `created_at` to `card_requests` if missing |
| Sidebar template | Hide super-admin links for company admin role |

## Out of Scope

- Print shop ERP integration (Odoo sync) — already working, no changes
- Billing/subscription system — already working
- Template editor (Fabric.js) — already working
- Bulk import — already working
- Blog/careers/marketing pages — not related
