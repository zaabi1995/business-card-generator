# MHD Combined Card Portal — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A single self-service portal at mhd.cardify.om where an MHD employee picks their division, previews their bilingual card (with a QR on/off tickbox), and on Send gets the print-ready PDF emailed to themselves + CC to the division's responsible mailbox + BHD sales.

**Architecture:** Fold the 9 MHD divisions into the parent `mhd` Cardify tenant as `departments` rows, each linked to a division-branded template pair and a new `responsible_email`. Extend the existing `portal.php` division flow (dropdown + preview already exist) with a QR toggle and an email-on-send step that renders `CardPDFRenderer::render(..., 'print')` and mails it via the M365 smarthost from sales@bhdoman.com. Designs are imported headless from BHD's mail archive (proofs now, clean masters swapped later).

**Tech Stack:** PHP 7.4/8.3 (no framework), MySQL (`bc` db, host 127.0.0.1), Fabric.js, PyMuPDF/Poppler import pipeline, Mailer (Postfix→M365 smarthost), Playwright for E2E.

## Global Constraints (verbatim from spec + CLAUDE.md)
- **Deploy only via `/usr/local/bin/deploy-cardify.sh`** after `git push origin main`. Never raw `git pull`. Main lives in `.worktrees/ux-employee-tabs/`.
- **No em-dashes** anywhere (files, commits, Arabic + English). Use comma/colon/period.
- **Every new user-facing string in `lang/en/*.php` AND `lang/ar/*.php`** same commit. Run `php scripts/i18n-audit.php`.
- OMR 3 decimals. New tables/columns `utf8mb4_unicode_ci`. Migrations `database/migrations/NNN_*.php`, run with `/www/server/php/83/bin/php`.
- CSRF on every POST (`csrfField()` / `validateCSRFToken()`); rate-limit state-changing POST endpoints. Detect upload MIME with `finfo`. Company/employee ids are VARCHAR strings, never `(int)`-cast.
- Render parity: any card render must satisfy CLAUDE.md rule 24 (skip `render_in_bg`, PNG bg, qr_style passthrough, font registry, `fonts.load` preload). The QR toggle must be honored in ALL 5 Fabric paths + `render-card-pdf.py`.
- Tenant subdomains are no-cache; changes show on next load. Parent `mhd` tenant id = `a9ba4c5e-7b8e-4ccc-a3bd-08ab9af7b1d5`, slug `mhd`.
- **Live email routing to real MHD mailboxes is wired LAST and only after the sweep passes with a test recipient.** Never blast real MHD inboxes during development.

## Verified routing map (docs/mhd/mhd-division-routing-map.md — do not fabricate)
ITICS→iticsceooffice@mhd.co.om · Tech&Comm→tech.comm@mhd.co.om · Office Products→opd@mhd.co.om · Infrastructure→ibs@mhd.co.om · Healthcare→healthcare@mhd.co.om · Building Materials→bmdsales@mhd.co.om · EEP→eep@mhd.co.om · IPD→ipd@mhd.co.om · Automotive/Consumer/Logistics/parent→sales@bhdoman.com (fallback). Every Send also CCs sales@bhdoman.com.

## File Structure
| File | Responsibility |
|---|---|
| `database/migrations/NNN_department_responsible_email.php` | Add `responsible_email`, `cc_emails`, `include_qr_default` to `departments` |
| `scripts/mhd/import-division-card.php` (new) | Headless: extract a card PDF from a maildir file, split front/back, run import pipeline into `mhd` tenant, return template ids |
| `scripts/mhd/seed-departments.php` (new) | Create/update one `departments` row per division under `mhd`, link template pair + responsible_email |
| `admin/departments.php` (modify or create) | Admin CRUD for departments incl. `responsible_email` field |
| `company_admin.php` | Register `departments` in `$pageMap` if new |
| `portal.php` | QR toggle in preview; email-on-send handler; store include_qr on request |
| `assets/js/card-editor.js` | Honor `includeQr=false` (skip qr field on render/export) |
| `includes/CardPDFRenderer.php` | Thread `include_qr` into cache key + render args |
| `scripts/render-card-pdf.py` | Skip QR field when `--no-qr` |
| `includes/MhdMailer.php` (new) | Build + send the "MHD Business Card" email with PDF attachment + CC routing |
| `lang/{en,ar}/portal.php` | New strings: qr toggle label, send-success, email copy |
| `tests/e2e/mhd-portal.spec.ts` (new) | A-to-Z sweep per division |

---

### Task 0: Feature branch in the main worktree

**Files:** none (git only)

- [ ] **Step 1: Branch off main in the worktree**

```bash
cd /Users/ali/claude/projects/cardify.om/.worktrees/ux-employee-tabs
git fetch origin && git checkout main && git pull --ff-only origin main
git checkout -b feature/mhd-combined-portal
git status
```
Expected: on `feature/mhd-combined-portal`, clean tree.

- [ ] **Step 2: Confirm parent tenant + current departments**

```bash
ssh root@147.93.20.54 "mysql -u bc -ppWewN3fwFmEHh32J bc -e \"
SELECT id,slug FROM companies WHERE slug='mhd';
SELECT company_id,name,slug FROM departments WHERE company_id='a9ba4c5e-7b8e-4ccc-a3bd-08ab9af7b1d5' AND deleted_at IS NULL;\""
```
Expected: parent row present; 0 departments today.

---

### Task 1: Migration — department routing columns

**Files:**
- Create: `database/migrations/NNN_department_responsible_email.php` (use next free NNN from `ls database/migrations/ | tail -3`)

**Interfaces:**
- Produces: `departments.responsible_email VARCHAR(255) NULL`, `departments.cc_emails TEXT NULL`, `departments.include_qr_default TINYINT(1) DEFAULT 1`.

- [ ] **Step 1: Write the migration**

```php
<?php
// database/migrations/NNN_department_responsible_email.php
require_once __DIR__ . '/../../config.php';
try {
    $db = Database::getInstance();
    foreach ([
        "ALTER TABLE departments ADD COLUMN responsible_email VARCHAR(255) NULL",
        "ALTER TABLE departments ADD COLUMN cc_emails TEXT NULL",
        "ALTER TABLE departments ADD COLUMN include_qr_default TINYINT(1) NOT NULL DEFAULT 1",
    ] as $sql) {
        try { $db->exec($sql); echo "ok: $sql\n"; }
        catch (Exception $e) {
            if (stripos($e->getMessage(), 'Duplicate column') !== false) { echo "skip (exists): $sql\n"; }
            else { throw $e; }
        }
    }
    echo "Migration NNN: department routing columns done\n";
} catch (Exception $e) { echo "Migration NNN failed: " . $e->getMessage() . "\n"; exit(1); }
```

- [ ] **Step 2: Lint**

Run: `php -l database/migrations/NNN_department_responsible_email.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Apply on VPS with the 8.3 binary**

```bash
git add database/migrations/NNN_department_responsible_email.php && git commit -m "feat: department routing columns for MHD portal"
git push origin feature/mhd-combined-portal
# run migration directly (production suppresses migration output otherwise)
ssh root@147.93.20.54 "cd /www/wwwroot/cardify.om && git fetch origin feature/mhd-combined-portal && git checkout feature/mhd-combined-portal -- database/migrations/ 2>/dev/null; /www/server/php/83/bin/php database/migrations/NNN_department_responsible_email.php"
```
Expected: `ok:` lines then `done`.

- [ ] **Step 4: Verify columns exist**

Run: `ssh root@147.93.20.54 "mysql -u bc -ppWewN3fwFmEHh32J bc -e 'SHOW COLUMNS FROM departments LIKE \"responsible_email\"; SHOW COLUMNS FROM departments LIKE \"include_qr_default\";'"`
Expected: both columns listed.

---

### Task 2: Headless division-card importer + import one design (ITICS proof)

Proven recipe: CLAUDE.md rule 63 (headless import into a tenant) + PDF Import Wizard section. This task builds the mechanism and validates it on the ITICS card so later divisions are a loop.

**Files:**
- Create: `scripts/mhd/import-division-card.php`

**Interfaces:**
- Consumes: `CardifyTemplateImporter::persist()`, `parse_card_pdf.py`, `CardRenderer::invalidateForCompany()`.
- Produces: on success prints JSON `{"pair_id":..., "front_template_id":..., "back_template_id":...}` for the `mhd` company.

- [ ] **Step 1: Extract the source card PDFs per division to a staging dir on the VPS**

The proof already located: ITICS = `sales/cur/1771708274.M514656P1235455...` (`KKDURAI Business Card draft.final.pdf`). Extract it + rasterize to confirm which page is front/back:

```bash
ssh bhd-vps "mkdir -p /tmp/mhd-src && python3 - <<'PY'
import email
f='/www/vmail/bhdoman.com/sales/cur/1771708274.M514656P1235455.mail.dardasha.om,S=302991,W=306980:2,S'
m=email.message_from_file(open(f,errors='replace'))
for p in m.walk():
    fn=(p.get_filename() or '')
    if fn.lower().endswith('.pdf') and 'card' in fn.lower():
        open('/tmp/mhd-src/itics.pdf','wb').write(p.get_payload(decode=True)); print('saved itics.pdf')
PY"
```
Note: the proof is an A4 sheet with the card mocked up + pen annotations. Flag in output that this needs a clean master; import proceeds for structure.

- [ ] **Step 2: Write the importer script**

```php
<?php
// scripts/mhd/import-division-card.php  <pdf_path> <mhd_company_id> <department_name>
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/CardifyTemplateImporter.php';
require_once INCLUDES_DIR . '/CardRenderer.php';

[$self, $pdf, $cid, $deptName] = array_pad($argv, 4, null);
if (!$pdf || !$cid) { fwrite(STDERR, "usage: import-division-card.php <pdf> <company_id> <dept>\n"); exit(2); }
if (!is_file($pdf)) { fwrite(STDERR, "no such pdf: $pdf\n"); exit(2); }

$token = 'mhd-' . preg_replace('/[^a-z0-9]+/','-', strtolower($deptName ?: 'div')) . '-' . substr(md5($pdf . microtime()), 0, 8);
$outDir = dirname(__DIR__, 2) . '/uploads/templates/imports/' . $token;
@mkdir($outDir, 0755, true);

$installed = dirname(__DIR__, 2) . '/uploads/fonts/installed.txt';
$stderrFile = $outDir . '/parser-stderr.log';
$cmd = 'python3 ' . escapeshellarg(dirname(__DIR__, 2) . '/scripts/parse_card_pdf.py')
     . ' ' . escapeshellarg($pdf) . ' ' . escapeshellarg($outDir) . ' ' . escapeshellarg($installed)
     . ' 2>' . escapeshellarg($stderrFile);
$json = shell_exec($cmd);            // NEVER 2>&1 (rule: stderr to sidecar)
$parsed = json_decode($json, true);
if (!$parsed) { fwrite(STDERR, "parser_failed; see $stderrFile\n"); fwrite(STDERR, substr((string)$json,0,400)); exit(1); }

$importer = new CardifyTemplateImporter();
$res = $importer->persist($cid, $parsed, ['source' => 'mhd-archive', 'name' => $deptName]); // returns pair_id + template ids
try { CardRenderer::invalidateForCompany((string)$cid, 'mhd-division-import'); } catch (Throwable $e) {}
echo json_encode($res) . "\n";
```
(Confirm `CardifyTemplateImporter::persist` signature/return in `includes/CardifyTemplateImporter.php` before running; adapt arg shape to the real method — the PDF Import Wizard section documents `persist` writes `templates` rows and sets `pair_id`.)

- [ ] **Step 3: Lint + run for ITICS**

```bash
php -l scripts/mhd/import-division-card.php
git add scripts/mhd/import-division-card.php && git commit -m "feat: headless MHD division card importer"
git push origin feature/mhd-combined-portal
ssh root@147.93.20.54 "cd /www/wwwroot/cardify.om && git checkout feature/mhd-combined-portal -- scripts/mhd/ && chown -R www:www scripts/mhd && /www/server/php/83/bin/php scripts/mhd/import-division-card.php /tmp/mhd-src/itics.pdf a9ba4c5e-7b8e-4ccc-a3bd-08ab9af7b1d5 'ITICS'"
```
Expected: JSON with `pair_id` + template ids.

- [ ] **Step 4: Verify the template renders (mocked-session admin render, rule 34)**

```bash
ssh root@147.93.20.54 "mysql -u bc -ppWewN3fwFmEHh32J bc -e \"SELECT id,name,pair_id,has_vector_source,side FROM templates WHERE company_id='a9ba4c5e-7b8e-4ccc-a3bd-08ab9af7b1d5' AND deleted_at IS NULL;\""
```
Expected: front + back rows sharing a pair_id, `has_vector_source=1`.

---

### Task 3: Seed departments under the parent tenant

**Files:**
- Create: `scripts/mhd/seed-departments.php`

**Interfaces:**
- Consumes: template pair ids from Task 2 (per division).
- Produces: one `departments` row per division under `mhd`, with `slug`, `template_pair_id`, `responsible_email`, `portal_enabled=1`.

- [ ] **Step 1: Write the seeder (idempotent upsert by company_id+slug)**

```php
<?php
// scripts/mhd/seed-departments.php
require_once __DIR__ . '/../../config.php';
$db = Database::getInstance();
$CID = 'a9ba4c5e-7b8e-4ccc-a3bd-08ab9af7b1d5'; // mhd parent

// name, slug, responsible_email, pair_id (fill pair_id from Task 2 imports; null until imported)
$divisions = [
  ['ITICS',                'itics',            'iticsceooffice@mhd.co.om', null],
  ['Technology & Communications','tech-comm',  'tech.comm@mhd.co.om',      null],
  ['Office Products',      'office-products',  'opd@mhd.co.om',            null],
  ['Infrastructure & Building Systems','infrastructure','ibs@mhd.co.om',  null],
  ['Healthcare',           'healthcare',       'healthcare@mhd.co.om',     null],
  ['Building Materials',   'building-materials','bmdsales@mhd.co.om',      null],
  ['EEP',                  'eep',              'eep@mhd.co.om',            null],
  ['IPD',                  'ipd',              'ipd@mhd.co.om',            null],
  ['Automotive',           'automotive',       'sales@bhdoman.com',        null],
  ['Consumer',             'consumer',         'sales@bhdoman.com',        null],
  ['Logistics',            'logistics',        'sales@bhdoman.com',        null],
];
foreach ($divisions as [$name,$slug,$email,$pair]) {
    $row = $db->fetchOne("SELECT id FROM departments WHERE company_id=:c AND slug=:s", ['c'=>$CID,'s'=>$slug]);
    if ($row) {
        $db->update('departments',
          ['name'=>$name,'responsible_email'=>$email,'portal_enabled'=>1] + ($pair?['template_pair_id'=>$pair]:[]),
          'id=:id', ['id'=>$row['id']]);
        echo "updated $slug\n";
    } else {
        $db->insert('departments', [
            'id'=>generateUUID(),'company_id'=>$CID,'name'=>$name,'slug'=>$slug,
            'responsible_email'=>$email,'template_pair_id'=>$pair,'portal_enabled'=>1,
        ]);
        echo "inserted $slug\n";
    }
}
```
CC extras for ITICS (`corpcom-itics@`, `cio-office@`) go in `cc_emails` once confirmed with Ali; leave null for now.

- [ ] **Step 2: Lint + run**

```bash
php -l scripts/mhd/seed-departments.php
git add scripts/mhd/seed-departments.php && git commit -m "feat: seed MHD divisions as parent-tenant departments"
git push origin feature/mhd-combined-portal
ssh root@147.93.20.54 "cd /www/wwwroot/cardify.om && git checkout feature/mhd-combined-portal -- scripts/mhd/ && /www/server/php/83/bin/php scripts/mhd/seed-departments.php"
```
Expected: inserted/updated lines for all 11.

- [ ] **Step 3: Verify + confirm portal lists them**

```bash
ssh root@147.93.20.54 "mysql -u bc -ppWewN3fwFmEHh32J bc -e \"SELECT name,slug,responsible_email,portal_enabled,template_pair_id FROM departments WHERE company_id='a9ba4c5e-7b8e-4ccc-a3bd-08ab9af7b1d5' AND deleted_at IS NULL ORDER BY name;\""
curl -s "https://mhd.cardify.om/portal?cb=$RANDOM" | grep -ciE "itics|healthcare|automotive"
```
Expected: 11 rows; portal HTML mentions division names.

---

### Task 4: Loop the importer over remaining divisions, backfill pair_id

**Files:** none new (uses Task 2 + Task 3 scripts).

- [ ] **Step 1: Extract each division's best card PDF to /tmp/mhd-src/<slug>.pdf**

Use the archive locator (already proven) per division. Candidate sources found in sweep: tech.comm, opd, ibs, healthcare threads with `*card*.pdf` / `draft.final.pdf`. For divisions with only proofs, extract the most recent approved one. Where no card artwork exists (Automotive/Consumer/Logistics), reuse the ITICS pair as a temporary group card and mark `template_pair_id` = ITICS pair (Ali chose ITICS-as-group fallback is acceptable only where no division art exists).

- [ ] **Step 2: Run importer per division, capture pair_id**

```bash
for slug in tech-comm office-products infrastructure healthcare building-materials eep ipd; do
  ssh root@147.93.20.54 "cd /www/wwwroot/cardify.om && /www/server/php/83/bin/php scripts/mhd/import-division-card.php /tmp/mhd-src/$slug.pdf a9ba4c5e-7b8e-4ccc-a3bd-08ab9af7b1d5 '$slug'"
done
```
Record each printed `pair_id`.

- [ ] **Step 3: Backfill pair_id into the seeder + re-run**

Edit `scripts/mhd/seed-departments.php` filling the `$pair` column per division from Step 2, commit, push, re-run seeder (idempotent update).

- [ ] **Step 4: Verify every enabled department has a template_pair_id**

Run: `ssh root@147.93.20.54 "mysql -u bc -ppWewN3fwFmEHh32J bc -e \"SELECT name, template_pair_id IS NOT NULL AS has_design FROM departments WHERE company_id='a9ba4c5e-7b8e-4ccc-a3bd-08ab9af7b1d5' AND deleted_at IS NULL AND portal_enabled=1;\""`
Expected: `has_design=1` for all enabled rows.

---

### Task 5: QR on/off toggle end-to-end

**Files:**
- Modify: `portal.php` (preview UI + pass `include_qr` to render + submit)
- Modify: `assets/js/card-editor.js` (skip qr field when `includeQr=false`)
- Modify: `includes/CardPDFRenderer.php` (cache key + arg)
- Modify: `scripts/render-card-pdf.py` (`--no-qr` skips qr field)
- Modify: `lang/{en,ar}/portal.php` (label)

**Interfaces:**
- Consumes: existing render paths.
- Produces: JS `cardEditor` honors `window.__mhdIncludeQr`/option `includeQr`; `CardPDFRenderer::render($id,$profile,$opts=['include_qr'=>bool])`; python flag `--no-qr`.

- [ ] **Step 1: Add the tickbox to the portal preview**

In `portal.php` preview block, add before the Generate/Send buttons:

```html
<label class="flex items-center gap-2 text-sm mt-3">
  <input type="checkbox" id="mhd-include-qr" checked
         class="rounded border-gray-300 text-cyan-600 focus:ring-cyan-500">
  <span><?= sanitize(t('portal.include_qr')) ?></span>
</label>
```
Wire it so the preview re-renders on change and its value posts with the form (`<input type="hidden" name="include_qr" ...>` synced from the checkbox in the submit handler JS).

- [ ] **Step 2: Add strings (both langs)**

`lang/en/portal.php`: `'include_qr' => 'Include QR code on the card',`
`lang/ar/portal.php`: `'include_qr' => 'إضافة رمز QR على البطاقة',`
Run: `php scripts/i18n-audit.php` → expect parity pass.

- [ ] **Step 3: Honor includeQr in the Fabric render (card-editor.js)**

In the field-draw loop, skip the QR field when disabled:

```js
// where fields are iterated for render/export
if (field.type === 'qr_code' || field.qr_code) {
  if (this.options && this.options.includeQr === false) continue;
}
```
Ensure `exportPNGBlob` / preview both read the same `this.options.includeQr`. Default true.

- [ ] **Step 4: Thread include_qr through CardPDFRenderer + python**

`includes/CardPDFRenderer.php`: add `$opts['include_qr']` to the sha1 cache key and pass `--no-qr` to the python invocation when false. `scripts/render-card-pdf.py`: add `--no-qr` arg; in the field loop `if args.no_qr and field.get('type')=='qr_code': continue`. Bump `RENDERER_VERSION`.

- [ ] **Step 5: Lint everything**

```bash
php -l portal.php includes/CardPDFRenderer.php
python3 -c "import ast; ast.parse(open('scripts/render-card-pdf.py').read())"
node -e "require('fs').readFileSync('assets/js/card-editor.js','utf8')"  # smoke
```
Expected: all clean.

- [ ] **Step 6: Deploy + visual verify both states (Playwright, cold profile)**

```bash
git add -A && git commit -m "feat: QR on/off toggle in MHD portal preview + print PDF"
git push origin feature/mhd-combined-portal
# merge to main to deploy (or deploy the feature branch checkout), then:
ssh root@147.93.20.54 "/usr/local/bin/deploy-cardify.sh"
```
Then drive `https://mhd.cardify.om/portal`: pick ITICS, fill sample data, toggle QR off → preview shows no QR; toggle on → QR returns. Screenshot both. Also `curl` the print PDF with and without qr and confirm `pdftoppm` renders show/omit the QR.
Expected: QR present only when ticked, in preview AND print PDF.

---

### Task 6: Email-on-Send with PDF attachment + CC routing

**Files:**
- Create: `includes/MhdMailer.php`
- Modify: `portal.php` (submit handler: render print PDF, call MhdMailer, store request)
- Modify: `lang/{en,ar}/portal.php` (send-success + email subject/body)

**Interfaces:**
- Consumes: `CardPDFRenderer::render($id,'print',['include_qr'=>bool])`, department `responsible_email`/`cc_emails`, `Mailer`.
- Produces: `MhdMailer::sendCard(array $ctx): array` where `$ctx = [employee_email,employee_name,division_name,responsible_email,cc_emails[],pdf_path,include_qr]`; returns `['ok'=>bool,'error'=>?string]`.

- [ ] **Step 1: Write MhdMailer**

```php
<?php
// includes/MhdMailer.php
require_once __DIR__ . '/Mailer.php';
class MhdMailer {
    public static function sendCard(array $c): array {
        $to  = $c['employee_email'];
        $cc  = array_values(array_unique(array_filter(array_merge(
                 [$c['responsible_email'] ?? null], $c['cc_emails'] ?? [], ['sales@bhdoman.com']))));
        $subject = 'MHD Business Card: ' . $c['employee_name'] . ', ' . $c['division_name'];
        $html = '<p>Dear ' . htmlspecialchars($c['employee_name']) . ',</p>'
              . '<p>Your MHD business card for the ' . htmlspecialchars($c['division_name'])
              . ' division is attached as a print-ready PDF.</p>'
              . '<p>This copy has also gone to your division contact and BHD Printing for processing.</p>'
              . '<p>Regards,<br>BHD Printing &amp; Designing</p>';
        return Mailer::sendWithAttachment(
            'sales@bhdoman.com', $to, $cc, $subject, $html,
            [['path'=>$c['pdf_path'], 'name'=>self::fileName($c), 'mime'=>'application/pdf']]
        );
    }
    private static function fileName(array $c): string {
        return 'MHD-Card-' . preg_replace('/[^A-Za-z0-9]+/','-', $c['employee_name']) . '.pdf';
    }
}
```
(Confirm `Mailer` has an attachment-capable send; if not, add `Mailer::sendWithAttachment($from,$to,array $cc,$subject,$html,array $files)` building a MIME multipart/mixed and posting through the existing SMTP path. From must be `sales@bhdoman.com` so the M365 smarthost accepts it.)

- [ ] **Step 2: Wire the portal submit handler**

In `portal.php` POST handler, after the existing request-save + preview-confirm branch:

```php
// resolve department + its routing
$dept = null;
foreach ($departments as $d) { if ($d['id'] === ($formData['department_id'] ?? '')) { $dept = $d; break; } }
$includeQr = !empty($_POST['include_qr']);
$pdf = CardPDFRenderer::render($requestOrEmployeeId, 'print', ['include_qr' => $includeQr]);
if (!empty($pdf['success']) && is_file($pdf['path'])) {
    $sent = MhdMailer::sendCard([
        'employee_email' => $formData['email'],
        'employee_name'  => trim(($formData['name_en'] ?? '') ?: ($formData['name'] ?? '')),
        'division_name'  => $dept['name'] ?? 'MHD',
        'responsible_email' => $dept['responsible_email'] ?? 'sales@bhdoman.com',
        'cc_emails'      => array_filter(array_map('trim', explode(',', (string)($dept['cc_emails'] ?? '')))),
        'pdf_path'       => $pdf['path'],
        'include_qr'     => $includeQr,
    ]);
    // store send status on the card_requests row + show success/failure banner
}
```
Keep CSRF + the existing rate-limit guard. Persist `include_qr` + `sent` on the request row.

- [ ] **Step 3: Strings (both langs)**

`portal.php` en: `'send_success' => 'Your card has been emailed to you and your division contact.',`
ar: `'send_success' => 'تم إرسال بطاقتك إليك وإلى جهة الاتصال في قسمك عبر البريد الإلكتروني.',`
Run `php scripts/i18n-audit.php`.

- [ ] **Step 4: Lint + deploy to staging path with a TEST recipient**

Temporarily set the parent-tenant departments' `responsible_email` to a test address (e.g. ali@bhd.om) via SQL so the sweep does NOT hit real MHD inboxes:

```bash
ssh root@147.93.20.54 "mysql -u bc -ppWewN3fwFmEHh32J bc -e \"UPDATE departments SET responsible_email='ali@bhd.om' WHERE company_id='a9ba4c5e-7b8e-4ccc-a3bd-08ab9af7b1d5';\""
php -l includes/MhdMailer.php portal.php
git add -A && git commit -m "feat: email MHD card on send with CC routing"
git push origin feature/mhd-combined-portal && ssh root@147.93.20.54 "/usr/local/bin/deploy-cardify.sh"
```

- [ ] **Step 5: Live send test (to ali@bhd.om), verify delivery + attachment + CC**

Drive the portal end-to-end for ITICS with a real email = ali@bhd.om, Send. Then:

```bash
ssh bhd-vps "grep -iE 'MHD Business Card|ali@bhd.om' /var/log/mail.log | tail -20"
ssh root@147.93.20.54 "mysql -u bc -ppWewN3fwFmEHh32J bc -e \"SELECT status,COUNT(*) FROM email_logs WHERE created_at>NOW()-INTERVAL 15 MINUTE GROUP BY status;\""
```
Expected: `status=sent`, email in ali@bhd.om Maildir with a PDF attachment, CC = ali@bhd.om + sales@bhdoman.com. Confirm the M365 smarthost accepted (`sender_relay` for @bhdoman.com).

---

### Task 7: Responsible-email admin field

**Files:**
- Modify or create: `admin/departments.php` (verify it exists: `ls admin/departments.php`)
- Modify: `company_admin.php` `$pageMap` if the page is new
- Modify: `lang/{en,ar}/*.php` for the field label

- [ ] **Step 1: Add the field to the department edit form**

Add a `responsible_email` (+ optional `cc_emails`, `include_qr_default` checkbox) input to the department create/edit form and persist via the existing update path (whitelist the new keys). Use `getAdminBasePath()` + `$ext` for links; register page in `$pageMap` if new.

- [ ] **Step 2: Lint + deploy + verify edit round-trips**

```bash
php -l admin/departments.php company_admin.php
php scripts/i18n-audit.php
git add -A && git commit -m "feat: responsible-email admin field for departments"
git push origin feature/mhd-combined-portal && ssh root@147.93.20.54 "/usr/local/bin/deploy-cardify.sh"
```
Verify via mocked-session render (rule 34) of `admin/departments.php` for the `mhd` tenant that the field shows and saves.

---

### Task 8: A-to-Z verification sweep (all divisions)

**Files:**
- Create: `tests/e2e/mhd-portal.spec.ts`

- [ ] **Step 1: Write the E2E sweep (test recipient still wired)**

For each enabled division: open `https://mhd.cardify.om/portal`, select division, fill sample employee (email=ali@bhd.om), assert preview front+back render with the division name/branding, toggle QR off then on and assert the QR element visibility flips, click Send, assert the success banner.

```ts
// tests/e2e/mhd-portal.spec.ts
import { test, expect } from '@playwright/test';
const DIVS = ['ITICS','Technology & Communications','Office Products','Infrastructure & Building Systems','Healthcare','Building Materials','EEP','IPD','Automotive','Consumer','Logistics'];
for (const div of DIVS) {
  test(`MHD portal: ${div}`, async ({ page }) => {
    await page.goto('https://mhd.cardify.om/portal');
    await page.selectOption('#department_id', { label: div });
    await page.fill('[name="name_en"]', 'Test User');
    await page.fill('[name="email"]', 'ali@bhd.om');
    await page.getByRole('button', { name: /generate|preview/i }).click();
    await expect(page.locator('#frontCardImage, canvas')).toBeVisible();
    const qr = page.locator('#mhd-include-qr');
    await qr.uncheck(); await expect(page.locator('.qr-preview, [data-qr]')).toHaveCount(0);
    await qr.check();
    await page.getByRole('button', { name: /send/i }).click();
    await expect(page.getByText(/emailed|sent|تم/i)).toBeVisible();
  });
}
```
Adjust selectors to the real portal DOM after Task 5.

- [ ] **Step 2: Run the sweep**

Run: `npx playwright test tests/e2e/mhd-portal.spec.ts`
Expected: all divisions pass. Investigate any render/route failure (missing pair_id, wrong branding, email not sent).

- [ ] **Step 3: Server-side email assertion per division**

```bash
ssh root@147.93.20.54 "mysql -u bc -ppWewN3fwFmEHh32J bc -e \"SELECT status,COUNT(*) FROM email_logs WHERE created_at>NOW()-INTERVAL 1 HOUR GROUP BY status;\""
```
Expected: one `sent` per division test, zero failures.

- [ ] **Step 4: Go-live — wire the real MHD mailboxes**

Only after all above pass, re-run the seeder (restores real `responsible_email` per the verified map) and do ONE live confirmation send per division to the real mailbox with Ali watching, or send to sales@bhdoman.com first for a final human check. Never bulk-send.

```bash
ssh root@147.93.20.54 "cd /www/wwwroot/cardify.om && /www/server/php/83/bin/php scripts/mhd/seed-departments.php"
```

- [ ] **Step 5: Merge to main + deploy + final smoke**

```bash
cd /Users/ali/claude/projects/cardify.om/.worktrees/ux-employee-tabs
git checkout main && git merge --no-ff feature/mhd-combined-portal -m "feat: MHD combined card portal (division routing, QR toggle, email-on-send)"
git push origin main && ssh root@147.93.20.54 "/usr/local/bin/deploy-cardify.sh"
curl -sI "https://mhd.cardify.om/portal" | head -1   # expect 200
```

---

## Self-Review notes
- **Spec coverage:** combined portal (T3), designs imported (T2/T4), responsible_email + admin (T1/T7), QR toggle (T5), email-on-send + CC routing (T6), A-to-Z sweep (T8). All covered.
- **Safety:** real MHD inboxes wired LAST (T8.4); test recipient (ali@bhd.om) used throughout (T6.4).
- **Open real-world dependency:** clean per-division master artwork (import proofs now, swap later) — does not block the mechanism.
- **Verify-before-code checkpoints:** `CardifyTemplateImporter::persist` signature (T2), `Mailer` attachment support (T6), `admin/departments.php` existence (T7) — confirm each against the live file before writing, adapt to real signatures.
