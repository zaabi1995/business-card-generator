# Cardify Full System Interconnection, Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix all broken interconnections in Cardify.om and add a rich digital card page so the full flow works end-to-end: company setup → card design → generation → notifications → QR scan → digital card → save contact.

**Architecture:** PHP (no framework), MySQL, Nginx rewrites for clean URLs. Existing patterns: `DatabaseAdapter` for DB queries, `functions.php` wrappers, Alpine.js + Tailwind for frontend. Deploy via git push → SSH to VPS at 147.93.20.54.

**Tech Stack:** PHP 8, MySQL/MariaDB, Nginx, Tailwind CSS, Alpine.js, GD library (image processing), Dardasha REST API (WhatsApp)

**Spec:** `docs/superpowers/specs/2026-03-12-cardify-full-interconnection-design.md`

---

## Chunk 1: Bug Fixes & Database Migration

### Task 1: Add `findDepartmentById()` to DatabaseAdapter and functions.php

**Files:**
- Modify: `includes/DatabaseAdapter.php` (add method after line ~301, after `findEmployeeById`)
- Modify: `includes/functions.php` (add wrapper after line ~610, after `findEmployeeById`)

- [ ] **Step 1: Add method to DatabaseAdapter.php**

Add after the `findEmployeeById()` method (around line 301):

```php
public static function findDepartmentById($id, $companyId = null) {
    if (!self::useDatabase()) {
        return null;
    }

    if ($companyId) {
        return self::$db->fetchOne(
            "SELECT * FROM departments WHERE id = :id AND company_id = :cid",
            ['id' => $id, 'cid' => $companyId]
        );
    }

    return self::$db->fetchOne("SELECT * FROM departments WHERE id = :id", ['id' => $id]);
}
```

- [ ] **Step 2: Add wrapper to functions.php**

Add after `findEmployeeById()` (around line 610):

```php
function findDepartmentById($id, $companyId = null) {
    if (!class_exists('DatabaseAdapter') || !DatabaseAdapter::useDatabase()) {
        return null;
    }

    return DatabaseAdapter::findDepartmentById($id, $companyId);
}
```

- [ ] **Step 3: Commit**

```bash
git add includes/DatabaseAdapter.php includes/functions.php
git commit -m "feat: add findDepartmentById() to DatabaseAdapter and functions.php"
```

---

### Task 2: Fix send_card_email.php null safety

**Files:**
- Modify: `admin/send_card_email.php` (lines ~97-101 and ~142-151)

- [ ] **Step 1: Check current code around lines 97-101 and 142-151**

Read the file to confirm the exact context. The calls to `findDepartmentById()` already exist but crash because the function didn't exist. Now that Task 1 adds the function, verify the existing null-check pattern. The code at line 99 already has:
```php
if ($employeeData && !empty($employeeData['department_id'])) {
    $department = findDepartmentById($employeeData['department_id'], $companyId);
```

This is already null-safe (checks `department_id` before calling). Verify line 144 also has the same guard. If either call lacks the `!empty($employeeData['department_id'])` check, add it.

- [ ] **Step 2: Verify both call sites have null safety**

Ensure both locations follow this pattern:
```php
$department = null;
if ($employeeData && !empty($employeeData['department_id'])) {
    $department = findDepartmentById($employeeData['department_id'], $companyId);
}
```

- [ ] **Step 3: Commit if changes were needed**

```bash
git add admin/send_card_email.php
git commit -m "fix: add null safety for department lookup in send_card_email.php"
```

---

### Task 3: Fix ORDER BY created_at references

**Files:**
- Modify: `admin/employees.php` (line ~70, known `ORDER BY created_at` on card_requests query)
- Search: other PHP files for any remaining `created_at` references in card_requests context

- [ ] **Step 1: Fix known bug in admin/employees.php**

`admin/employees.php` line ~70 has `ORDER BY created_at DESC` in a card_requests query. Change to `ORDER BY submitted_at DESC`.

- [ ] **Step 2: Search for any other occurrences**

```bash
grep -rn "card_requests" --include="*.php" . | grep "created_at"
```

Fix any additional files found. Note: `admin/requests.php` line 213 already uses `submitted_at` correctly.

- [ ] **Step 3: Commit**

```bash
git add admin/employees.php
git commit -m "fix: use submitted_at instead of created_at for card_requests ordering"
```

---

### Task 4: Run database migration on VPS

**Files:**
- Create: `database/migrations/029_digital_card_columns.php`

- [ ] **Step 1: Create migration file**

```php
<?php
/**
 * Migration 029: Add web-optimized card paths and theme mode to generated_cards
 */

return [
    'up' => "
        ALTER TABLE generated_cards
            ADD COLUMN IF NOT EXISTS front_web_path VARCHAR(500) DEFAULT NULL AFTER back_file_path,
            ADD COLUMN IF NOT EXISTS back_web_path VARCHAR(500) DEFAULT NULL AFTER front_web_path,
            ADD COLUMN IF NOT EXISTS theme_mode ENUM('light','dark') DEFAULT NULL AFTER back_web_path;
    ",
    'down' => "
        ALTER TABLE generated_cards
            DROP COLUMN IF EXISTS front_web_path,
            DROP COLUMN IF EXISTS back_web_path,
            DROP COLUMN IF EXISTS theme_mode;
    "
];
```

- [ ] **Step 2: Run migration on VPS**

```bash
ssh root@147.93.20.54 "mysql -u bc -ppWewN3fwFmEHh32J bc -e \"
ALTER TABLE generated_cards
    ADD COLUMN IF NOT EXISTS front_web_path VARCHAR(500) DEFAULT NULL AFTER back_file_path,
    ADD COLUMN IF NOT EXISTS back_web_path VARCHAR(500) DEFAULT NULL AFTER front_web_path,
    ADD COLUMN IF NOT EXISTS theme_mode ENUM('light','dark') DEFAULT NULL AFTER back_web_path;
SELECT 'Migration 029 complete' as status;
\""
```

- [ ] **Step 3: Commit migration file**

```bash
git add database/migrations/029_digital_card_columns.php
git commit -m "feat: add migration 029 for web card paths and theme mode"
```

---

### Task 5: Deploy bug fixes to VPS

- [ ] **Step 1: Push to GitHub**

```bash
git push origin main
```

- [ ] **Step 2: Deploy to VPS**

```bash
ssh root@147.93.20.54 "cd /www/wwwroot/cardify.om && git pull origin main"
```

- [ ] **Step 3: Verify send_card_email.php no longer crashes**

Test by navigating to batch generate page in browser and triggering an email send for an employee.

---

## Chunk 2: Digital Card Page

### Task 6: Add nginx rewrite rule for digital card

**Files:**
- Modify: Nginx config on VPS at `/www/server/panel/vhost/rewrite/cardify.om.conf`

- [ ] **Step 1: Add rewrite rule BEFORE the company slug catchall**

SSH to VPS and add this rule. It must go BEFORE the `# Company-specific routing (catch-all)` comment:

```nginx
# Digital card page
rewrite ^/([a-z0-9-]+)/card/([^/]+)/?$ /digital_card.php?company_slug=$1&employee_id=$2 last;
```

Place it right after the `# Share link routing` block and before `# Print shop clean URLs`.

- [ ] **Step 2: Reload nginx**

```bash
ssh root@147.93.20.54 "nginx -t && nginx -s reload"
```

- [ ] **Step 3: Verify route responds (will 404 until digital_card.php exists, but should not hit router.php)**

---

### Task 7: Build digital_card.php, data loading and theme detection

**Files:**
- Create: `digital_card.php` (project root)

- [ ] **Step 1: Create the PHP file with data loading**

The file should:
1. `require_once __DIR__ . '/config.php'` and required includes
2. Get `$companySlug` from `$_GET['company_slug']` and `$employeeId` from `$_GET['employee_id']`
3. Look up company via `findCompanyBySlug($companySlug)` → show branded 404 if not found
4. Look up employee via `findEmployeeById($employeeId, $company['id'])` → branded 404
5. Load theme: `SELECT * FROM company_themes WHERE company_id = ?`
6. Load latest generated card: `SELECT * FROM generated_cards WHERE employee_id = ? AND company_id = ? ORDER BY generated_at DESC LIMIT 1`
7. Log QR scan via `QRTracker::logScan()` if the class exists
8. Determine theme mode:
   - Use `$card['theme_mode']` if set
   - Else use company theme `secondary_color` luminance
   - Else default to `'dark'` (dark page)
9. Build variables: `$isDarkPage`, `$accentColor` (from theme primary_color), `$vcfUrl`, card image URLs

- [ ] **Step 2: Verify data loading works by adding a temporary JSON dump**

Add `echo json_encode(['company' => $company['name'], 'employee' => $employee['name_en']]); exit;` at the end temporarily, deploy, and test the URL.

- [ ] **Step 3: Remove the debug dump and continue to Step 4**

---

### Task 8: Build digital_card.php, HTML template with flip animation

**Files:**
- Modify: `digital_card.php` (continue building)

- [ ] **Step 1a: Write HTML skeleton with head, themed body, and company logo**

Create the HTML structure: DOCTYPE, head with meta viewport + Tailwind CDN + inline `<style>` for flip CSS, body with themed background gradient. Add company logo centered at top.

Theme variables from PHP: `$isDarkPage` controls background gradient and text colors. `$accentColor` from company theme primary_color. If `$isDarkPage` → dark bg (#141421 → #1a1a2e), light text. Else → light bg (#f5f6f8 → #ebedf0), dark text.

- [ ] **Step 1b: Add card flip container with front/back images**

Add the flip container HTML:
- `.card-flip-container` with `perspective: 1000px`, `max-width: 400px`, `cursor: pointer`
- `.card-flip-inner` with `transform-style: preserve-3d`, `transition: transform 0.7s cubic-bezier(0.4, 0, 0.2, 1)`, `aspect-ratio: 1050/600`
- Front `.card-face`: `<img>` using `$card['front_web_path'] ?: $card['front_file_path']` with `loading="lazy"`
- Back `.card-face.card-back-face` (`transform: rotateY(180deg)`): `<img>` using back path
- Both faces: `backface-visibility: hidden; -webkit-backface-visibility: hidden` (Safari fix)
- "Tap card to flip" hint text below

JS: `onclick` handler that toggles `.flipped` class and fades hint.

- [ ] **Step 1c: Add employee info, action buttons, and contact details**

Below the card:
- Employee name (h1) + position + company name, centered
- Action buttons row (flex, gap): Call (`tel:`), WhatsApp (`https://api.whatsapp.com/send?phone=` + digits only, no `+`), Email (`mailto:`), only show buttons where data exists
- Contact details list in a rounded card: phone, mobile, email, website, address rows with icons, only rows where data exists, each tappable

- [ ] **Step 1d: Add Save Contact + Share buttons and footer**

- Save Contact button → links to `/{company_slug}/{employee_email}.vcf`
- Share button → JS: `navigator.share({title, url})` with fallback to copy-to-clipboard
- Footer: "Powered by Cardify" with link to `/`

- [ ] **Step 2: Add branded 404 page**

At the top of the file where company/employee lookups happen, if not found, render a styled error page:
- Company logo if available (try loading theme even on 404)
- "This card is no longer available" message
- Link back to company portal if slug is valid
- HTTP 404 status code

- [ ] **Step 3: Commit**

```bash
git add digital_card.php
git commit -m "feat: add digital card page with adaptive theme and flip animation"
```

---

### Task 9: Web-optimized card export in batch_generate

**Files:**
- Modify: `admin/batch_generate.php` (JS section where card images are saved)
- Modify: `save_card_image.php` (server-side image save)

- [ ] **Step 1: Find where card PNG is exported in batch_generate.php**

Look for the canvas export logic (around lines 612-619). After the full-res PNG is saved, add a second export at reduced resolution (scale factor for 788×450 from 1050×600 = 0.75).

- [ ] **Step 2: Add web-optimized export**

After the full-res card is saved successfully, trigger a second save with `_web` suffix:
- Export canvas at 0.75x scale → `card_front_web_*.png`
- POST to `save_card_image.php` with an extra `web=1` parameter
- Server saves to same directory but with `_web_` in filename
- Server response includes `webPath` in addition to existing `path`

- [ ] **Step 3: Update save_card_image.php**

If `$_POST['web']` is set, add `_web` to the filename pattern. Return the web path in the response.

- [ ] **Step 4: Store web paths in generated_cards table**

After both images are saved, UPDATE the `generated_cards` record to set `front_web_path` and `back_web_path`.

- [ ] **Step 5: Compute and store theme_mode during generation**

After the front card is generated, before saving:
1. Read the front template's `settings_json` to get `backgroundColor`
2. If hex color: parse RGB, compute luminance `(0.299*R + 0.587*G + 0.114*B)`
3. If luminance < 128 → `theme_mode = 'dark'`, else `'light'`
4. If no background color (image background): use GD to sample center of the exported PNG
5. UPDATE `generated_cards SET theme_mode = ?` for this record

- [ ] **Step 6: Update QR code URL**

Find where the QR code URL is built (around line 484 in batch_generate.php JS). Change from:
```javascript
const vcfUrl = `${window.location.origin}/${this.companySlug}/${employee.email}.vcf`;
```
To:
```javascript
const cardUrl = `${window.location.origin}/${this.companySlug}/card/${employee.id}`;
```

**Important:** The variable `vcfUrl` is referenced at approximately 5 downstream locations (lines ~506, ~515, ~536, ~598, ~599) where it's used for QR code generation and other card features. Rename ALL of these references to `cardUrl`. Search for every occurrence of `vcfUrl` in the file and update them. The VCF download link is still available on the digital card page, this only changes what the QR code points to.

- [ ] **Step 7: Commit**

```bash
git add admin/batch_generate.php save_card_image.php
git commit -m "feat: add web-optimized card export, theme detection, and digital card QR URL"
```

---

## Chunk 3: Share Links, WhatsApp (Dardasha), Email

### Task 10: Rewrite share/index.php as gateway

**Files:**
- Modify: `share/index.php`

- [ ] **Step 1: Read current share/index.php to understand existing password logic**

Preserve the password protection flow but replace the template preview with routing.

- [ ] **Step 2: Rewrite the file**

New logic:
1. Get token from `$_GET['token']`
2. Query `share_links` table: `SELECT sl.*, c.slug as company_slug FROM share_links sl JOIN companies c ON c.id = sl.company_id WHERE sl.token = ?`
3. If found and has `employee_id`:
   - Check expiration (`expires_at`), show styled "Link expired" if past
   - Increment `view_count`: `UPDATE share_links SET view_count = view_count + 1 WHERE id = ?`
   - Redirect: `header('Location: /' . $row['company_slug'] . '/card/' . $row['employee_id'])`
4. If not found in share_links, query `design_links`: `SELECT dl.*, c.slug as company_slug FROM design_links dl JOIN companies c ON c.id = dl.company_id WHERE dl.share_token = ? AND dl.is_active = 1`
5. If found in design_links:
   - Check expiration, password, max_access (preserve existing logic)
   - Increment `access_count`
   - Render template preview (keep existing preview logic or simplify)
6. If not found in either table: show styled "Link not found" 404

- [ ] **Step 3: Commit**

```bash
git add share/index.php
git commit -m "feat: rewrite share links as gateway to digital card page"
```

---

### Task 11: Wire Dardasha into WhatsApp.php

**Files:**
- Modify: `includes/WhatsApp.php`

- [ ] **Step 1: Update loadSettings() to load Dardasha config**

Add loading of `whatsapp_api_url` and `whatsapp_session_id` from `system_settings` alongside existing `whatsapp_api_token` and `whatsapp_enabled`.

- [ ] **Step 2: Rewrite sendMessage() method**

Replace the placeholder API call (around line 74) with Dardasha:

```php
// Get API URL from settings, default to Dardasha
$apiUrl = self::$settings['whatsapp_api_url'] ?? 'https://dardasha.om/api/send-message';
$sessionId = self::$settings['whatsapp_session_id'] ?? 'anna';

// Strip + prefix from phone (Dardasha expects 968XXXXXXXX)
$phone = ltrim($phoneNumber, '+');
$phone = preg_replace('/[^0-9]/', '', $phone);

$payload = json_encode([
    'phone' => $phone,
    'message' => $message,
    'sessionId' => $sessionId
]);
```

Update cURL: POST to `$apiUrl` with `Content-Type: application/json`, body = `$payload`, auth via `Authorization: Bearer {token}`.

Response check: `$response['success'] === true`.

- [ ] **Step 3: Add notification methods for card events**

Add (or update existing) methods:
- `sendCardReadyNotification($employee, $company, $digitalCardUrl)`, "Your business card is ready! View: {url}"
- `sendCardRequestApproved($employee, $company, $digitalCardUrl)`, "Your card request has been approved! View: {url}"

These call `sendMessage()` with the employee's mobile number.

- [ ] **Step 4: Commit**

```bash
git add includes/WhatsApp.php
git commit -m "feat: replace WhatsApp placeholder with Dardasha API integration"
```

---

### Task 12: Update WhatsApp admin settings UI

**Files:**
- Modify: `admin/whatsapp_settings.php`

- [ ] **Step 1: Add new form fields**

Add fields for:
- API URL (text input, default: `https://dardasha.om/api/send-message`)
- Session ID (text input, default: `anna`)
- Keep existing: API Token, Enabled toggle

- [ ] **Step 2: Add save logic for new fields**

On form submit, save `whatsapp_api_url` and `whatsapp_session_id` to `system_settings` table using `generateUUID()` for the `id` field on INSERT (use INSERT ... ON DUPLICATE KEY UPDATE pattern on `setting_key`).

- [ ] **Step 3: Add test message button**

Add a "Send Test" button that POSTs to the same page with `action=test`. On the server side, call `WhatsApp::sendMessage($adminPhone, 'Cardify WhatsApp test - connection successful!')` and show the result.

- [ ] **Step 4: Commit**

```bash
git add admin/whatsapp_settings.php
git commit -m "feat: update WhatsApp settings UI for Dardasha config"
```

---

### Task 13: Verify and fix email system

**Files:**
- Check: VPS `config.php` for SMTP settings
- Check: `admin/super/email_settings.php` on live site

- [ ] **Step 1: Check SMTP config on VPS**

```bash
ssh root@147.93.20.54 "grep -A5 'MAIL_' /www/wwwroot/cardify.om/config.php"
```

If SMTP settings are empty/placeholder, configure them. Use the BHD mail server or the VPS's local mail service.

- [ ] **Step 2: Test email via admin panel**

Navigate to `https://cardify.om/admin/super/email_settings.php` and use the built-in test email button. Verify delivery.

- [ ] **Step 3: If SMTP is not configured, set it via system_settings**

```bash
ssh root@147.93.20.54 "mysql -u bc -ppWewN3fwFmEHh32J bc -e \"
INSERT INTO system_settings (id, setting_key, setting_value, setting_type) VALUES
(UUID(), 'mail_host', 'your-smtp-host', 'string'),
(UUID(), 'mail_port', '587', 'string'),
(UUID(), 'mail_username', 'noreply@cardify.om', 'string'),
(UUID(), 'mail_password', 'your-password', 'string'),
(UUID(), 'mail_encryption', 'tls', 'string'),
(UUID(), 'mail_from_email', 'noreply@cardify.om', 'string'),
(UUID(), 'mail_from_name', 'Cardify', 'string')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
\""
```

Adjust values based on what's available on the VPS.

- [ ] **Step 4: Verify email_logs table captures sends**

```bash
ssh root@147.93.20.54 "mysql -u bc -ppWewN3fwFmEHh32J bc -e 'SELECT id, to_email, subject, status, error_message FROM email_logs ORDER BY id DESC LIMIT 5'"
```

---

## Chunk 4: Navigation Fix, Deploy & End-to-End Test

### Task 14: Fix sidebar navigation for company admins

**Files:**
- Modify: `includes/admin-layout.php` (lines ~55-74)

- [ ] **Step 1: Read current sidebar code**

Check `includes/admin-layout.php` around lines 55-74 where super-admin menu items are added. The condition `if ($role === 'super_admin')` should already gate these items.

- [ ] **Step 2: Verify and fix if needed**

Ensure these links are ONLY shown when `$role === 'super_admin'`:
- Companies
- All Employees
- Print Shops
- Subscriptions
- Email Logs

If any appear outside the super_admin check, move them inside it.

- [ ] **Step 3: Commit if changes were needed**

```bash
git add includes/admin-layout.php
git commit -m "fix: hide super-admin sidebar links from company admin users"
```

---

### Task 15: Deploy everything to VPS

- [ ] **Step 1: Push all changes to GitHub**

```bash
git push origin main
```

- [ ] **Step 2: Pull on VPS**

```bash
ssh root@147.93.20.54 "cd /www/wwwroot/cardify.om && git pull origin main"
```

- [ ] **Step 3: Verify nginx config includes the digital card route**

The rewrite was added in Task 6. Verify it's still there:
```bash
ssh root@147.93.20.54 "grep 'digital_card' /www/server/panel/vhost/rewrite/cardify.om.conf"
```

---

### Task 15b: Verify PrintShop notification chain is wired

**Files:**
- Read: `includes/PrintShopIntegration.php` (check `createOrder()` method)

- [ ] **Step 1: Verify WhatsApp notification hook exists in createOrder()**

Read `includes/PrintShopIntegration.php` and find the `createOrder()` method. Check if it calls `WhatsApp::sendPrintOrderConfirmation()` or `WhatsApp::sendMessage()` after creating the order. If not, add the call:

```php
// After order is created successfully
if (class_exists('WhatsApp') && WhatsApp::isEnabled()) {
    WhatsApp::sendPrintOrderConfirmation($order, $company);
}
```

- [ ] **Step 2: Verify email notification hook exists**

Check if `createOrder()` calls `Mailer::send()` to notify the print shop. If not, add it.

- [ ] **Step 3: Commit if changes were needed**

```bash
git add includes/PrintShopIntegration.php
git commit -m "fix: wire WhatsApp and email notifications into print order creation"
```

---

### Task 16: End-to-end testing

- [ ] **Step 1: Create test company and generate cards**

Using Playwright browser automation or manual testing:
1. Log in as super admin at `https://cardify.om/admin/super/`
2. Create a test company (or use BHD Oman)
3. Generate cards for an employee with both front and back templates

- [ ] **Step 2: Test digital card page**

Navigate to `https://cardify.om/{slug}/card/{employee_id}`:
- Verify company logo shows
- Verify card front image loads
- Tap/click card → verify flip animation to back
- Verify Call/WhatsApp/Email buttons show correctly
- Verify WhatsApp uses `api.whatsapp.com` (NOT wa.me)
- Verify "Save Contact" downloads .vcf
- Verify adaptive theme (dark card → light page or vice versa)

- [ ] **Step 3: Test share links**

1. Create a share link from admin for an employee
2. Visit `https://cardify.om/share/{token}`
3. Verify it redirects to the digital card page

- [ ] **Step 4: Test WhatsApp notifications**

1. Go to WhatsApp settings → configure Dardasha API URL, token, session ID
2. Send test message → verify it arrives
3. Generate a card for an employee with a mobile number → verify WhatsApp notification sent

- [ ] **Step 5: Test email**

1. Go to Email Settings → send test email
2. Generate a card → verify email proof sent
3. Check email_logs table for delivery status

- [ ] **Step 6: Test print shop flow**

1. Place a print order from company admin
2. Verify print shop gets WhatsApp notification
3. Log in as print shop → verify order appears
4. Update order status → verify company admin gets notification

- [ ] **Step 7: Clean up test data if needed**

Delete any test companies/employees created during testing.

---

## Summary

| Chunk | Tasks | Focus |
|-------|-------|-------|
| 1 | Tasks 1-5 | Bug fixes + DB migration + deploy |
| 2 | Tasks 6-9 | Digital card page + web-optimized export |
| 3 | Tasks 10-13 | Share links + Dardasha WhatsApp + email |
| 4 | Tasks 14-16 | Navigation fix + deploy + E2E testing |

**Total tasks:** 16
**Estimated steps:** ~50
**Deploy frequency:** After Chunk 1, then after all chunks complete
