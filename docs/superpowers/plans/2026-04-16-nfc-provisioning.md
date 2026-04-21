# NFC Provisioning for Cardify (Migration 051)

**Branch:** `infy-nfc-provisioning`
**Goal:** Print shops/admins program physical NFC tags with each employee's Cardify card URL using a phone's Web NFC API.

## Schema, `nfc_cards`
```sql
CREATE TABLE nfc_cards (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id VARCHAR(36) NOT NULL,
  company_id VARCHAR(36) NOT NULL,
  tag_uid VARCHAR(64) NULL,
  programmed TINYINT(1) NOT NULL DEFAULT 0,
  programmed_at DATETIME NULL,
  programmed_by_user_id INT NULL,
  batch_id VARCHAR(32) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_employee (employee_id),
  INDEX idx_batch (batch_id),
  INDEX idx_company (company_id)
);
```

## Files
- `database/migrations/051_nfc_cards.php`, idempotent table create
- `admin/nfc/batch.php`, bulk QR sheet generator (CSV upload or all employees) + progress
- `admin/nfc/write.php?eid=X`, Web NFC writer page (mobile, Chrome Android)
- `admin/nfc/mark-programmed.php`, POST endpoint to record write
- `admin/nfc/sheet.php?batch=X`, print-friendly QR sheet for batch
- `includes/admin-layout.php`, add "NFC Tags" sidebar entry

## Flow
1. Admin opens `/admin/nfc/batch.php`, uploads CSV of employee emails OR selects all employees → batch row created, QR sheet rendered.
2. Print tech scans QR (camera) → opens `/admin/nfc/write.php?eid=X` on Chrome Android.
3. Page checks `'NDEFReader' in window`; if missing → fallback message.
4. Tech taps "Tap to program" → `new NDEFReader().write({records:[{recordType:'url',data:cardUrl}]})`.
5. On success → POST to `mark-programmed.php` with eid+batch → row inserted, beep + flash.
6. Undo button (5s) calls DELETE/mark endpoint to roll back row.

## Auth
- All `/admin/nfc/*` use `requireAdmin()` (super_admin/admin/company/printshop allowed).
- Tenant scope: company_id from session enforced server-side on read+write.

## QR
- Reuse existing `qrcode-generator` CDN library already loaded in card editor.

## Compat fallback
- `!('NDEFReader' in window)` shows: "Web NFC requires Chrome on Android. Use desktop to print the QR sheet, then scan from Android."

## Verification
- `php -l` every file
- Apply migration via mysql CLI on VPS
- curl smoke: batch.php → 302 (no auth), write.php?eid=test → 302
- Manual: open Chrome Android, point at NFC tag, verify card URL written.

## Deploy
1. Commit, push, PR
2. Merge → `/usr/local/bin/deploy-cardify.sh`
3. Run migration on VPS
