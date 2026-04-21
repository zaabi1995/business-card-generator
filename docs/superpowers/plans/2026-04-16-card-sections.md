# Public Card Sections, Bio, Services, Gallery, Testimonials, Lead Form

**Branch:** `infy-card-sections-v2`
**Inspiration (clean-room):** Infy vCard `vcard_sections`, one row per vcard with a flag column per section type.

## Goal
Enhance `/{slug}/card/{employee_id}` (rendered by `digital_card.php`) with richer below-the-fold sections so scan recipients see a full profile. Card owner edits via the employee portal (`/{slug}/employee` → `company/views/employee.php`).

## Scope
- Untouched: Fabric.js canvas editor (printed cards), Paymob, public URL structure.
- Mobile-first single-column render below existing contact block.

## Schema (migration `044_card_sections.php`)
Two-level model, master flags row + child rows for lists:

1. `employee_card_sections`, 1:1 with employee (toggles + bio_text + section_order CSV + lead_form_email)
2. `employee_card_services`, id, employee_id, icon, title, description, position
3. `employee_card_gallery`, id, employee_id, file_path, caption, position
4. `employee_card_testimonials`, id, employee_id, name, photo_path, quote, position
5. `employee_card_leads`, id, employee_id, company_id, name, email, phone, message, ip, created_at

All rows cascade on employee delete.

## Admin UI (`company/views/employee.php`)
New "Public Card Sections" panel below existing profile form with:
- Five toggles (Bio / Services / Gallery / Testimonials / Lead Form)
- Bio textarea (markdown-lite: newlines + `**bold**`)
- Services repeatable list (icon + title + description + delete)
- Gallery multi-upload (JPG/PNG/WebP, 5MB each, 12 max)
- Testimonials repeatable list (name + optional photo + quote)
- Lead form override email field

All mutations CSRF-guarded, dispatch via distinct `action` values.

## Public Render (`digital_card.php`)
After "Save & Share" buttons, iterate `section_order` CSV and render each enabled section using the existing accent color + dark/light theme. Pure inline CSS in existing `<style>` block, zero new JS libs.

Lead form posts to `api/lead.php` via fetch; success inline replaces form.

## Uploads
- Dir: `uploads/cards/{employee_id}/{gallery|testimonials}/`
- Validate MIME via `finfo_file` (image/jpeg, image/png, image/webp)
- Reject >5MB
- Rename to `bin2hex(random_bytes(8)).ext`
- Store relative web path in DB

## Lead form mailer
- Sends via existing `Mailer::send()` to `lead_form_email` (or employee email)
- Honeypot field + 5/hour IP rate limit
- Non-fatal: lead row is saved even if mail fails

## Files Touched
- NEW `database/migrations/044_card_sections.php`
- NEW `includes/CardSections.php`
- NEW `api/lead.php`
- MOD `company/views/employee.php`
- MOD `digital_card.php`

## Verification
- `php -l` every touched file
- Commit, push, open PR against main. No deploy.
