# MHD combined card portal — design spec (2026-07-07)

## Goal
A single self-service portal at **mhd.cardify.om** where any MHD employee picks
their **division**, fills their details, generates a **preview** (with a **QR
on/off tickbox**), and clicks **Send**. On Send, Cardify generates the
print-ready card PDF and emails it, **CC'ing the employee + the division's
responsible mailbox** (+ BHD sales). Then a full A-to-Z sweep verifies every
division renders and routes correctly.

## Verified ground truth (from prod DB + a year of BHD mail history)
- MHD already exists as **10 Cardify tenants**: parent `mhd` + 9 divisions.
- **0 of 10 have a real card design** — all are the auto-seeded BHD Classic
  placeholder. Real artwork lives only as PDFs in BHD's print/mail archive.
- `departments` table already supports per-department **template pairs** +
  passcodes; `portal.php` already renders a division dropdown. Missing pieces:
  responsible-email, email-on-send, QR toggle.
- **The MHD card is division-branded on a shared bilingual (AR/EN) layout**:
  per-division logo lockup (e.g. "MHD ITICS") + banner tagline + division line,
  same contact-block geometry. Sample proof: `docs/mhd/sample-card-render.png`.

## Division → responsible mailbox (verified, saved in docs/mhd/mhd-division-routing-map.md)
| Cardify tenant (slug) | Division | Responsible mailbox (CC) |
|---|---|---|
| mhd-itics | ITICS / CEO Office | iticsceooffice@mhd.co.om |
| mhd-tech-comm | Technology & Communications | tech.comm@mhd.co.om |
| mhd-office-products | Office Products Division | opd@mhd.co.om |
| mhd-infrastructure | Infrastructure & Building Systems | ibs@mhd.co.om |
| mhd-healthcare | Healthcare | healthcare@mhd.co.om |
| mhd-building-materials | Building Materials | bmdsales@mhd.co.om |
| (EEP) | EEP | eep@mhd.co.om |
| (IPD) | IPD | ipd@mhd.co.om |
| mhd-automotive | Automotive | **sales@bhdoman.com (fallback — no MHD mailbox in archive)** |
| mhd-consumer | Consumer | **sales@bhdoman.com (fallback)** |
| mhd-logistics | Logistics | **sales@bhdoman.com (fallback)** |
| mhd (parent) | Group | **sales@bhdoman.com (fallback)** |

Every Send also CCs `sales@bhdoman.com` (BHD owns MHD's account; Hamid Hussain).
Sender = `sales@bhdoman.com` via the M365 smarthost (MHD is Trend-Micro-protected;
direct-from-VPS is blocked).

## Architecture (decisions locked with Ali)
1. **Fold divisions into the parent `mhd` tenant as departments.** mhd.cardify.om
   is the single portal. Each division = one `departments` row under `mhd`, with
   its own template pair (division-branded card) + `responsible_email`.
2. **Extract designs from the BHD email archive** and import one division-branded
   template pair per division via the headless PDF-import pipeline
   (`parse_card_pdf.py` → `CardifyTemplateImporter::persist`). Clean masters
   preferred; annotated proofs are structure-only.

## Work items
### 1. Data model
- `ALTER TABLE departments ADD COLUMN responsible_email VARCHAR(255) NULL` (+ optional
  `cc_emails` TEXT for extra ITICS sub-mailboxes). Migration `NNN_department_responsible_email.php`.
- Seed the parent `mhd` tenant with one department per division (name, slug,
  template_pair_id, responsible_email from the map above).

### 2. Designs
- For each division, extract the best available approved card PDF from
  `sales@bhdoman.com` and import as a front+back template pair scoped to `mhd`,
  linked to that department. Where only a proof exists, flag for a clean master.

### 3. Portal (`portal.php` on the `mhd` tenant)
- Division dropdown already exists; ensure it lists the seeded departments and
  loads the division's template pair on select (existing behaviour).
- **QR tickbox** in the preview step: `include_qr` (default on). When off, the
  Fabric render + emailed PDF omit the QR field (skip the `qr_code` field in all
  render paths + `render-card-pdf.py`). Follows CLAUDE.md rule 24 render parity.

### 4. Email-on-Send
- On portal submit (after preview confirm), render the print-ready PDF
  (`CardPDFRenderer::render($empOrRequestId, 'print')`, respecting `include_qr`)
  and email it as an attachment.
- Recipients: **To** = employee; **CC** = department `responsible_email` +
  `sales@bhdoman.com`. Subject: `MHD Business Card — <Name>, <Division>`.
- Send via the existing `Mailer` / send-bhd-email path from `sales@bhdoman.com`
  (smarthost). Log to `email_logs`. Rate-limit + CSRF on the submit handler.
- Store the request (existing `card_requests`) with division + include_qr so the
  admin can re-send / track.

### 5. A-to-Z verification sweep
- For every division: load portal → pick division → generate preview (QR on and
  off) → confirm front+back render with correct division branding → Send → assert
  the email lands with the PDF attached and the correct CC. Use the one-shot
  health-probe pattern + Playwright + a test recipient before wiring live MHD
  mailboxes.

## Open item needing Ali / MHD
- **Clean per-division master artwork.** The archive has approved *proofs* (pen
  annotations). For production print quality we want the clean masters per
  division (MHD brand/design source). Proofs get us a working demo; masters get
  us print-ready. Decision: import proofs now and swap masters later, or wait for
  masters.

## Non-goals (YAGNI)
- No approval-workflow/status gate (Ali chose notification-copy).
- No payment in this flow (internal MHD cards; billing stays BHD-side).
- Not touching the 9 division tenants' existing rows beyond folding references.
