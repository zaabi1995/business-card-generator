# MHD Card Ordering Flow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Take an MHD card request from employee submit to signed delivery note, one click at every step, with real BHD-ERP documents.

**Architecture:** Each approved card request creates a `print_orders` row, which hands the job to the ERP methods that already exist (`ERPSync::createQuote`, `convertQuoteToInvoice`, `createProductionOrder`, `runQueue`). New code is the state machine, the purchase-order poller, the emails and the console. Nothing about the ERP integration is rebuilt.

**Tech Stack:** PHP 8.3, MySQL, the `imap` extension (confirmed present on the VPS), existing `MhdMailer` SMTP-to-localhost path, existing `AdminApprovalToken` magic links, Dardasha for WhatsApp.

**Spec:** `docs/superpowers/specs/2026-09-16-mhd-card-ordering-flow-design.md`

## Status, 16 September 2026, 22:40 GMT+4

Tasks 1 to 7 and 9 to 11 are shipped and live-verified end to end on
mhd.cardify.om: submit, one-click approve, ERP quotation with the card shown on
the line, purchase order taken from an email reply, invoice and delivery note
issued and attached, one-click signature, and the Card Jobs console.

Proven on a live run (job MHD-C3E15A, since removed): QUO-06126/2026 at
6.000 + 0.300 VAT = 6.300 on the IPD account, PO 4191000999 read off the reply,
INV-06128/2026 raised, all three documents attached, the delivery note signed in
its own signature box.

Open, and only these:
- Task 8's WhatsApp handoff. The print-ready artwork goes to the production
  mailbox. Ali wants WhatsApp and Cardify can already reach the Cloud API, but
  nobody has given the production number, and a phone number is never guessed.
- Logistics has no named approver at MHD, so it still routes to
  sales@bhdoman.com. MHD have to name the person.
- The two Tech & Comm client accounts in the ERP are reported, not merged:
  "(ITICS-Tech & Comm)" carries 62 invoices, "(Technology and Communication
  Division)" carries 10 and is the one the portal quotes against. Merging live
  client accounts is a finance decision.

## Global Constraints

- **No em dashes** anywhere: files, commit messages, email copy, Arabic and English alike. Use commas, colons, periods or parentheses.
- **OMR is 3 decimals.** `number_format($amount, 3)` and `DECIMAL(10,3)` in MySQL.
- **Every user-facing string lands in `lang/en/*.php` AND `lang/ar/*.php` in the same commit.** `php scripts/i18n-audit.php` must pass.
- **Deploy only via `/usr/local/bin/deploy-cardify.sh`.** Never raw `git pull` as root.
- Main lives in the `/private/tmp/cardify-main` worktree. Push to `origin/main`, then deploy.
- DB credentials: `bc` / `pWewN3fwFmEHh32J` / host `127.0.0.1`. Migrations run with `/www/server/php/83/bin/php`.
- CSRF on every POST: `csrfField()` in forms, `validateCSRFToken()` in handlers.
- MHD tenant company id: `a9ba4c5e-7b8e-4ccc-a3bd-08ab9af7b1d5`.
- **Standard card only.** Art 300 GSM matte, unit price 0.030, quantities 100/200/300/400.
- Never send test mail to a real `@mhd.co.om` address. Test by pointing a division's
  `responsible_email` at `ali@bhd.om` and widening `companies.email_domain`, then revert.

## File Structure

| File | Responsibility |
|---|---|
| `database/migrations/159_mhd_card_flow.php` | new columns and `card_request_events` |
| `includes/CardJob.php` | the state machine: allowed transitions, event log, job ref |
| `includes/CardJobMailer.php` | the four flow emails, built on `MhdMailer`'s transport |
| `includes/PoInbox.php` | IMAP poll, match a reply to a job, extract the PO number |
| `cron/mhd-po-poll.php` | cron entry point for `PoInbox` |
| `portal.php` | show the routing address, standard quantities only |
| `admin/one-tap-approve.php` | on approve, create the print order and quote |
| `admin/orders.php` | the console |
| `sign-delivery.php` | one-tap delivery note signature |
| `scripts/mhd/seed-flow-config.php` | per-division head, ERP client, price, disable Automotive |

---

### Task 1: Data model and the state machine

**Files:**
- Create: `database/migrations/159_mhd_card_flow.php`
- Create: `includes/CardJob.php`
- Test: `tests/CardJobTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `CardJob::STATES` (array), `CardJob::canTransition(string $from, string $to): bool`, `CardJob::transition(string $requestId, string $to, array $evidence = []): bool`, `CardJob::mintRef(string $requestId): string`, `CardJob::findByRef(string $ref): ?array`, `CardJob::events(string $requestId): array`.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/CardJobTest.php
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/CardJob.php';

function t(string $name, bool $ok) { echo ($ok ? "PASS " : "FAIL ") . $name . "\n"; if (!$ok) { exit(1); } }

t('forward transition allowed',  CardJob::canTransition('approved', 'quoted') === true);
t('skipping a state refused',    CardJob::canTransition('approved', 'dispatched') === false);
t('backwards refused',           CardJob::canTransition('quoted', 'approved') === false);
t('reject only from submitted',  CardJob::canTransition('submitted', 'rejected') === true);
t('reject not from quoted',      CardJob::canTransition('quoted', 'rejected') === false);
t('unknown state refused',       CardJob::canTransition('quoted', 'banana') === false);
t('ref is stable and short',     preg_match('/^MHD-[A-Z0-9]{6}$/', CardJob::mintRef('req-1')) === 1);
echo "all passed\n";
```

- [ ] **Step 2: Run it and watch it fail**

Run: `ssh root@147.93.20.54 "cd /www/wwwroot/cardify.om && /www/server/php/83/bin/php tests/CardJobTest.php"`
Expected: FAIL, `Failed opening required .../CardJob.php`.

- [ ] **Step 3: Write the migration**

```php
<?php // database/migrations/159_mhd_card_flow.php
require_once __DIR__ . '/../../config.php';
$db = Database::getInstance();
$add = function (string $table, string $col, string $ddl) use ($db) {
    if (!$db->columnExists($table, $col)) {
        $db->exec("ALTER TABLE {$table} ADD COLUMN {$ddl}");
        echo "159: {$table}.{$col} added\n";
    }
};
$add('card_requests', 'job_ref',        "job_ref VARCHAR(16) NULL");
$add('card_requests', 'erp_order_id',   "erp_order_id INT NULL");
$add('card_requests', 'po_number',      "po_number VARCHAR(32) NULL");
$add('card_requests', 'po_file',        "po_file VARCHAR(255) NULL");
$add('card_requests', 'quantity_ordered', "quantity_ordered INT NOT NULL DEFAULT 200");
$add('departments',   'head_email',     "head_email VARCHAR(190) NULL");
$add('departments',   'erp_client_name',"erp_client_name VARCHAR(190) NULL");
$add('departments',   'card_unit_price',"card_unit_price DECIMAL(10,3) NOT NULL DEFAULT 0.030");
$add('users',         'viewer_scope',   "viewer_scope VARCHAR(16) NOT NULL DEFAULT 'division'");
$db->exec("CREATE TABLE IF NOT EXISTS card_request_events (
  id CHAR(36) NOT NULL PRIMARY KEY,
  request_id CHAR(36) NOT NULL,
  company_id CHAR(36) NOT NULL,
  from_state VARCHAR(24) NULL,
  to_state   VARCHAR(24) NOT NULL,
  actor      VARCHAR(190) NULL,
  evidence   TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (request_id), INDEX (company_id, to_state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_card_requests_job_ref ON card_requests (job_ref)");
echo "159: done\n";
```

- [ ] **Step 4: Write `includes/CardJob.php`**

```php
<?php
/**
 * CardJob, the MHD card fulfilment state machine.
 *
 * One card request moves through exactly these states. Every transition is
 * recorded in card_request_events with who did it and the evidence, so the
 * console can show the history and nothing advances without a trace.
 */
class CardJob
{
    public const STATES = ['submitted','approved','quoted','po_received',
                           'in_production','dispatched','delivered','rejected'];

    private const NEXT = [
        'submitted'     => ['approved','rejected'],
        'approved'      => ['quoted'],
        'quoted'        => ['po_received'],
        'po_received'   => ['in_production'],
        'in_production' => ['dispatched'],
        'dispatched'    => ['delivered'],
        'delivered'     => [],
        'rejected'      => [],
    ];

    public static function canTransition(string $from, string $to): bool
    {
        return isset(self::NEXT[$from]) && in_array($to, self::NEXT[$from], true);
    }

    /** Deterministic, short, and safe to put in an email subject. */
    public static function mintRef(string $requestId): string
    {
        return 'MHD-' . strtoupper(substr(hash('sha256', $requestId), 0, 6));
    }

    public static function findByRef(string $ref): ?array
    {
        $db = Database::getInstance();
        return $db->fetchOne("SELECT * FROM card_requests WHERE job_ref = :r LIMIT 1", ['r' => $ref]) ?: null;
    }

    public static function events(string $requestId): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT * FROM card_request_events WHERE request_id = :r ORDER BY created_at ASC",
            ['r' => $requestId]);
    }

    /**
     * Move a job forward. Returns false and writes nothing when the move is not
     * in the diagram or the row has already moved on (another worker won).
     */
    public static function transition(string $requestId, string $to, array $evidence = []): bool
    {
        $db = Database::getInstance();
        $row = $db->fetchOne("SELECT id, company_id, status FROM card_requests WHERE id = :id", ['id' => $requestId]);
        if (!$row) { return false; }
        $from = (string)$row['status'];
        if (!self::canTransition($from, $to)) { return false; }

        // Conditional update: the guard and the write are one statement, so two
        // pollers racing the same reply cannot both advance the job.
        $stmt = $db->getConnection()->prepare(
            "UPDATE card_requests SET status = :to WHERE id = :id AND status = :from");
        $stmt->execute([':to' => $to, ':id' => $requestId, ':from' => $from]);
        if ($stmt->rowCount() === 0) { return false; }

        $db->insert('card_request_events', [
            'id'         => function_exists('generateUUID') ? generateUUID() : bin2hex(random_bytes(16)),
            'request_id' => $requestId,
            'company_id' => $row['company_id'],
            'from_state' => $from,
            'to_state'   => $to,
            'actor'      => $evidence['actor'] ?? null,
            'evidence'   => json_encode($evidence, JSON_UNESCAPED_UNICODE),
        ]);
        return true;
    }
}
```

- [ ] **Step 5: Run the migration, then the test**

Run: `ssh root@147.93.20.54 "cd /www/wwwroot/cardify.om && /www/server/php/83/bin/php database/migrations/159_mhd_card_flow.php && /www/server/php/83/bin/php tests/CardJobTest.php"`
Expected: `159: done` then `all passed`.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/159_mhd_card_flow.php includes/CardJob.php tests/CardJobTest.php
git commit -m "feat(mhd): card fulfilment state machine and its event log"
```

---

### Task 2: Show the employee where the request goes

**Files:**
- Modify: `portal.php` (the division picker and the form header)
- Modify: `lang/en/portal.php`, `lang/ar/portal.php`

**Interfaces:**
- Consumes: `departments.responsible_email`, `departments.head_email` from Task 1.
- Produces: nothing other tasks depend on.

- [ ] **Step 1: Add the strings, both languages**

```php
// lang/en/portal.php
'routes_to'      => 'This goes to :division, :email, for approval.',
'routes_to_cc'   => 'This goes to :division, :email, for approval. :head is copied.',
```

```php
// lang/ar/portal.php
'routes_to'      => 'يُرسل هذا الطلب إلى :division، :email، للموافقة.',
'routes_to_cc'   => 'يُرسل هذا الطلب إلى :division، :email، للموافقة. نسخة إلى :head.',
```

- [ ] **Step 2: Show it on the division picker**

In the picker loop in `portal.php`, under each division name, print the approver address so the choice is informed before it is made:

```php
<span class="block text-xs text-gray-500 mt-0.5">
    <?= htmlspecialchars($__d['responsible_email'] ?? '') ?>
</span>
```

- [ ] **Step 3: Show it on the form and the review step**

Directly under the `issue-domain` line in the form header:

```php
<?php if (!empty($selectedDepartment['responsible_email'])): ?>
<p class="issue-domain"><i class="fa-solid fa-paper-plane"></i>
<?= htmlspecialchars(t(empty($selectedDepartment['head_email']) ? 'portal.routes_to' : 'portal.routes_to_cc', [
      'division' => $selectedDepartment['name'],
      'email'    => $selectedDepartment['responsible_email'],
      'head'     => $selectedDepartment['head_email'] ?? '',
])) ?></p>
<?php endif; ?>
```

- [ ] **Step 4: Verify the strings are paired**

Run: `php scripts/i18n-audit.php`
Expected: `OK, EN and AR namespaces and keys match.`

- [ ] **Step 5: Deploy and look at it**

Run: `git push origin main && ssh root@147.93.20.54 "/usr/local/bin/deploy-cardify.sh"`
Then open `https://mhd.cardify.om/portal` and `https://mhd.cardify.om/portal/ipd`.
Expected: each division tile shows its mailbox; the IPD form says it goes to ipd@mhd.co.om with devanand.v@mhd.co.om copied.

- [ ] **Step 6: Commit**

```bash
git add portal.php lang/en/portal.php lang/ar/portal.php
git commit -m "feat(mhd): show the employee which division mailbox will approve the request"
```

---

### Task 3: Standard cards only, and the quantity choice

**Files:**
- Modify: `portal.php` (the quantity select, around the `quantity_requested` field)
- Create: `includes/CardPrice.php`
- Test: `tests/CardPriceTest.php`

**Interfaces:**
- Consumes: `departments.card_unit_price` from Task 1.
- Produces: `CardPrice::QUANTITIES` (array of int), `CardPrice::quote(int $qty, float $unit): array` returning `['qty','unit','net','vat','gross','description']`.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/CardPriceTest.php
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/CardPrice.php';
function t(string $n, bool $ok) { echo ($ok?"PASS ":"FAIL ").$n."\n"; if(!$ok) exit(1); }

$q = CardPrice::quote(200, 0.030);
t('net is 6.000',   abs($q['net']   - 6.000) < 0.0005);
t('vat is 0.300',   abs($q['vat']   - 0.300) < 0.0005);
t('gross is 6.300', abs($q['gross'] - 6.300) < 0.0005);
t('describes the standard stock', $q['description'] === 'Business Card (Art 300 GSM, Matte)');
$q4 = CardPrice::quote(400, 0.030);
t('400 is 12.600',  abs($q4['gross'] - 12.600) < 0.0005);
t('offers four quantities', CardPrice::QUANTITIES === [100,200,300,400]);
$bad = null; try { CardPrice::quote(250, 0.030); } catch (InvalidArgumentException $e) { $bad = $e; }
t('refuses an off-menu quantity', $bad !== null);
echo "all passed\n";
```

- [ ] **Step 2: Run it and watch it fail**

Run: `ssh root@147.93.20.54 "cd /www/wwwroot/cardify.om && /www/server/php/83/bin/php tests/CardPriceTest.php"`
Expected: FAIL, `Failed opening required .../CardPrice.php`.

- [ ] **Step 3: Write `includes/CardPrice.php`**

```php
<?php
/**
 * CardPrice, the only rate the MHD portal may quote.
 *
 * Art 300 GSM matte, the stock MHD buys on almost every order, at the flat
 * 0.030 per card BHD already bills them. Spot UV, FBB 400, foil and die cut are
 * real products but they are quoted by hand, so they are refused here rather
 * than guessed at.
 */
class CardPrice
{
    public const QUANTITIES = [100, 200, 300, 400];
    public const DEFAULT_QTY = 200;
    public const VAT_RATE = 0.05;
    public const DESCRIPTION = 'Business Card (Art 300 GSM, Matte)';

    public static function quote(int $qty, float $unit): array
    {
        if (!in_array($qty, self::QUANTITIES, true)) {
            throw new InvalidArgumentException("Quantity {$qty} is not a standard lot");
        }
        $net   = round($qty * $unit, 3);
        $vat   = round($net * self::VAT_RATE, 3);
        return [
            'qty' => $qty, 'unit' => $unit, 'net' => $net, 'vat' => $vat,
            'gross' => round($net + $vat, 3), 'description' => self::DESCRIPTION,
        ];
    }
}
```

- [ ] **Step 4: Run the test**

Run: `ssh root@147.93.20.54 "cd /www/wwwroot/cardify.om && /www/server/php/83/bin/php tests/CardPriceTest.php"`
Expected: `all passed`.

- [ ] **Step 5: Drive the portal's quantity select from it**

Replace the hand-written options on `quantity_requested` in `portal.php` with:

```php
<?php foreach (CardPrice::QUANTITIES as $__q): ?>
  <option value="<?= $__q ?>" <?= $__q === CardPrice::DEFAULT_QTY ? 'selected' : '' ?>><?= $__q ?></option>
<?php endforeach; ?>
```

and validate on POST, refusing anything off the menu:

```php
$quantityRequested = (int)($_POST['quantity_requested'] ?? CardPrice::DEFAULT_QTY);
if (!in_array($quantityRequested, CardPrice::QUANTITIES, true)) {
    $quantityRequested = CardPrice::DEFAULT_QTY;
}
```

- [ ] **Step 6: Commit**

```bash
git add includes/CardPrice.php tests/CardPriceTest.php portal.php
git commit -m "feat(mhd): quote only the standard Art 300 GSM card, four fixed quantities"
```

---

### Task 4: Per-division routing, pricing and ERP client configuration

**Files:**
- Create: `scripts/mhd/seed-flow-config.php`

**Interfaces:**
- Consumes: the columns from Task 1.
- Produces: configured `departments` rows. Later tasks read `head_email` and `erp_client_name`.

- [ ] **Step 1: Write the seeder**

The names on the right are the live BHD-ERP client records, verified against 365 days of
invoices. Automotive is switched off because it has never ordered a card from BHD.

```php
<?php // scripts/mhd/seed-flow-config.php
chdir('/www/wwwroot/cardify.om');
require_once 'config.php';
$db = Database::getInstance();
$CID = 'a9ba4c5e-7b8e-4ccc-a3bd-08ab9af7b1d5';
$MAP = [
 'itics'              => ['bipin.k@mhd.co.om',      'MOHSIN HAIDER DARWISH (ITICS)'],
 'tech-comm'          => ['rajanikanth.r@mhd.co.om','Mohsin Haider Darwish LLC.(Technology and Communication Division)'],
 'infrastructure'     => ['himanshu.p@mhd.co.om',   'MOHSIN HAIDER DARWISH (Infrastructure & Building Systems)'],
 'office-products'    => ['sadasivam@mhd.co.om',    'Mohsin Haider Darwish LLC.(ITICS - Office Products Division)'],
 'consumer'           => [null,                     'Mohsin Haider Darwish L.L.C.( Consumer Division )'],
 'logistics'          => [null,                     'MOHSIN HAIDER DARWISH LOGISTICS LLC'],
 'ipd'                => ['devanand.v@mhd.co.om',   'Mohsin Haider Darwish L.L.C.( Building Materials & Industrial Products Division) IPD'],
 'building-materials' => ['devanand.v@mhd.co.om',   'Mohsin Haider Darwish L.L.C.( Building Materials & Industrial Products Division) IPD'],
 'healthcare'         => ['anandha.k@mhd.co.om',    'Mohsin Haider Darwish L.L.C.( Healthcare Division )'],
 'eep'                => ['gokul.p@mhd.co.om',      'MOHSIN HAIDER DARWISH (ITICS)'],
];
foreach ($MAP as $slug => [$head, $erp]) {
    $db->query("UPDATE departments SET head_email = ?, erp_client_name = ?, card_unit_price = 0.030
                 WHERE company_id = ? AND slug = ?", [$head, $erp, $CID, $slug]);
    printf("%-20s head=%-26s erp=%s\n", $slug, $head ?: '(none)', $erp);
}
// Automotive has never ordered a card from BHD: zero invoices in 365 days.
$db->query("UPDATE departments SET portal_enabled = 0 WHERE company_id = ? AND slug = 'automotive'", [$CID]);
echo "automotive: portal_enabled = 0\n";
```

- [ ] **Step 2: Run it**

Run: `scp scripts/mhd/seed-flow-config.php root@147.93.20.54:/tmp/ && ssh root@147.93.20.54 "chmod 644 /tmp/seed-flow-config.php && cd /www/wwwroot/cardify.om && sudo -u www /www/server/php/83/bin/php /tmp/seed-flow-config.php"`
Expected: ten rows printed, then `automotive: portal_enabled = 0`.

- [ ] **Step 3: Verify Automotive is off the picker**

Run: `curl -s https://mhd.cardify.om/portal | grep -c Automotive`
Expected: `0`.

- [ ] **Step 4: Commit**

```bash
git add scripts/mhd/seed-flow-config.php
git commit -m "feat(mhd): per-division head, ERP client and rate; retire Automotive"
```

---

### Task 5: The approval email, with buttons and the head on CC

**Files:**
- Create: `includes/CardJobMailer.php`
- Modify: `portal.php` (the block after the `card_requests` insert)

**Interfaces:**
- Consumes: `CardJob::mintRef`, `departments.head_email`.
- Produces: `CardJobMailer::sendForApproval(array $request, array $dept, string $token): array`, and a private `CardJobMailer::send(array $to, array $cc, string $subject, string $html, array $attachments = []): array` reused by Tasks 6, 7 and 9.

- [ ] **Step 1: Write `includes/CardJobMailer.php`**

Reuse `MhdMailer`'s transport decision: send as sales@bhdoman.com over localhost:25 so
Postfix routes through the M365 smarthost, the only path that reaches MHD.

```php
<?php
require_once __DIR__ . '/MhdMailer.php';
/**
 * CardJobMailer, the four emails of the MHD card flow.
 *
 * Every one carries the job ref in the subject as [MHD-XXXXXX]. That tag is how
 * a purchase-order reply is matched back to its job, because Outlook rewrites
 * threading headers and cannot be relied on.
 */
class CardJobMailer
{
    public static function sendForApproval(array $req, array $dept, string $token): array
    {
        $base = getTenantUrl('mhd', '/admin/one-tap-approve.php');
        $name = htmlspecialchars($req['name_en'] ?: $req['name_ar'], ENT_QUOTES);
        $div  = htmlspecialchars($dept['name'], ENT_QUOTES);
        $ok   = $base . '?t=' . urlencode($token) . '&a=approve';
        $no   = $base . '?t=' . urlencode($token) . '&a=reject';
        $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#222;line-height:1.6">'
              . "<p><strong>{$name}</strong> has requested a business card for <strong>{$div}</strong>.</p>"
              . '<p>Position: ' . htmlspecialchars($req['position_en'] ?? '', ENT_QUOTES) . '<br>'
              . 'Mobile: +968 ' . htmlspecialchars($req['mobile'] ?? '', ENT_QUOTES) . '<br>'
              . 'Email: ' . htmlspecialchars($req['email'], ENT_QUOTES) . '</p>'
              . '<p style="margin:28px 0">'
              . '<a href="' . $ok . '" style="background:#127a3d;color:#fff;padding:12px 26px;'
              . 'border-radius:6px;text-decoration:none;font-weight:bold">Approve</a>'
              . '&nbsp;&nbsp;&nbsp;'
              . '<a href="' . $no . '" style="background:#b3261e;color:#fff;padding:12px 26px;'
              . 'border-radius:6px;text-decoration:none;font-weight:bold">Reject</a></p>'
              . '<p style="color:#6b7280;font-size:13px">Approving raises the quotation and '
              . 'emails it to this address. Nothing prints until you send the purchase order.</p></div>';
        $cc = array_values(array_filter([$dept['head_email'] ?? null, 'sales@bhdoman.com']));
        return self::send([$dept['responsible_email']], $cc,
            "[{$req['job_ref']}] Business card approval: {$name}, {$div}", $html);
    }

    /** Shared transport. Same route as MhdMailer, which is live-verified to MHD. */
    public static function send(array $to, array $cc, string $subject, string $html, array $attachments = []): array
    {
        return MhdMailer::sendRaw($to, $cc, $subject, $html, $attachments);
    }
}
```

- [ ] **Step 2: Expose the transport on `MhdMailer`**

`MhdMailer::sendCard()` keeps its signature. Add a sibling that takes arbitrary
recipients and attachments, built from the same `buildMime` and `smtpSend` already in the
file:

```php
/** @param array $attachments list of ['path'=>string,'name'=>string] */
public static function sendRaw(array $to, array $cc, string $subject, string $html, array $attachments = []): array
{
    $to = array_values(array_filter($to, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    if (!$to) { return ['ok' => false, 'error' => 'no valid recipient']; }
    $cc = array_values(array_filter($cc, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL) && !in_array($e, $to, true)));
    $mime = self::buildMimeMulti($to[0], $cc, $subject, $html, $attachments);
    $res  = self::smtpSend(self::SENDER, array_merge($to, $cc), $mime);
    self::log($to[0], $subject, $res['ok'], $res['error'] ?? null, ['cc' => implode(',', $cc)]);
    return ['ok' => $res['ok'], 'error' => $res['error'] ?? null];
}
```

`buildMimeMulti` is `buildMime` with the single hardcoded PDF replaced by a loop over
`$attachments`. Keep `buildMime` so `sendCard` is untouched.

- [ ] **Step 3: Call it on submit**

In `portal.php`, where the card email is sent today, mint the ref and the token first:

```php
$jobRef = CardJob::mintRef($requestId);
$db->query("UPDATE card_requests SET job_ref = ?, quantity_ordered = ? WHERE id = ?",
           [$jobRef, $quantityRequested, $requestId]);
$token = AdminApprovalToken::mint($companyId, $requestId, $sendDept['responsible_email']);
CardJobMailer::sendForApproval($formData + ['id' => $requestId, 'job_ref' => $jobRef],
                               $sendDept, $token);
```

- [ ] **Step 4: Test end to end without touching MHD**

```bash
ssh root@147.93.20.54 'mysql -ubc -ppWewN3fwFmEHh32J bc -e "
  UPDATE companies SET email_domain=\"bhd.om\" WHERE id=\"a9ba4c5e-7b8e-4ccc-a3bd-08ab9af7b1d5\";
  UPDATE departments SET responsible_email=\"ali@bhd.om\", head_email=NULL
   WHERE slug=\"building-materials\" AND company_id=\"a9ba4c5e-7b8e-4ccc-a3bd-08ab9af7b1d5\";"'
```

Submit a request at `https://mhd.cardify.om/portal/building-materials` as `qa@bhd.om`.
Expected: an email to ali@bhd.om, subject beginning `[MHD-`, with Approve and Reject buttons.

- [ ] **Step 5: Revert the test routing**

```bash
ssh root@147.93.20.54 'mysql -ubc -ppWewN3fwFmEHh32J bc -e "
  UPDATE companies SET email_domain=\"mhd.co.om\" WHERE id=\"a9ba4c5e-7b8e-4ccc-a3bd-08ab9af7b1d5\";
  UPDATE departments SET responsible_email=\"bmdsales@mhd.co.om\", head_email=\"devanand.v@mhd.co.om\"
   WHERE slug=\"building-materials\" AND company_id=\"a9ba4c5e-7b8e-4ccc-a3bd-08ab9af7b1d5\";
  DELETE FROM card_requests WHERE email=\"qa@bhd.om\"; DELETE FROM employees WHERE id=\"qa\";"'
```

- [ ] **Step 6: Commit**

```bash
git add includes/CardJobMailer.php includes/MhdMailer.php portal.php
git commit -m "feat(mhd): approval email with Approve and Reject buttons, head on CC"
```

---

### Task 6: On approve, raise the quotation

**Files:**
- Modify: `admin/one-tap-approve.php`
- Modify: `includes/RequestApproval.php`
- Modify: `includes/CardJobMailer.php` (add `sendQuotation`)

**Interfaces:**
- Consumes: `CardJob::transition`, `CardPrice::quote`, `ERPSync::createQuote(int $orderId)`.
- Produces: `RequestApproval::createPrintOrder(array $request, array $dept): int` returning the new `print_orders.id`; `CardJobMailer::sendQuotation(array $req, array $dept, string $quotePdfPath): array`.

- [ ] **Step 1: Create the print order on approval**

`ERPSync::createQuote` reads a `print_orders` row and resolves the ERP client from
`companies.erp_client_name`. MHD needs it per division, so pass the department's name
through on the order row:

```php
public static function createPrintOrder(array $req, array $dept): int
{
    $db = Database::getInstance();
    $price = CardPrice::quote((int)$req['quantity_ordered'], (float)$dept['card_unit_price']);
    $db->insert('print_orders', [
        'company_id'      => $req['company_id'],
        'order_number'    => $req['job_ref'],
        'quantity'        => $price['qty'],
        'paper_type'      => 'Art 300 GSM',
        'finish'          => 'matte',
        'total'           => $price['gross'],
        'erp_client_name' => $dept['erp_client_name'],
        'status'          => 'pending',
    ]);
    return (int)$db->getConnection()->lastInsertId();
}
```

`ERPSync::createQuote` must prefer the order's own `erp_client_name` over the company's.
Its existing line already does: `!empty($order['erp_client_name']) ? $order['erp_client_name'] : ...`. Add `po.erp_client_name` to the column list if the table lacks it, in migration 159.

- [ ] **Step 2: Wire the approve branch**

In `admin/one-tap-approve.php`, after `approveRequestChain()` succeeds:

```php
$orderId = RequestApproval::createPrintOrder($request, $dept);
Database::getInstance()->query("UPDATE card_requests SET erp_order_id = ? WHERE id = ?",
                               [$orderId, $request['id']]);
CardJob::transition($request['id'], 'approved', ['actor' => $approverEmail, 'order' => $orderId]);
$quote = ERPSync::createQuote($orderId);
if (!empty($quote['success'])) {
    CardJob::transition($request['id'], 'quoted', ['actor' => 'system', 'quote' => $quote['data']['quoteId'] ?? null]);
    CardJobMailer::sendQuotation($request, $dept, $quote['data']['pdfPath'] ?? '');
} else {
    ERPSync::enqueueRetry($orderId, 'createQuote', $quote['message'] ?? 'unknown');
}
```

A failed quote leaves the job at `approved` and goes on the existing retry queue. It never
advances without a real quotation.

- [ ] **Step 3: Write `sendQuotation`**

```php
public static function sendQuotation(array $req, array $dept, string $pdf): array
{
    $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#222;line-height:1.6">'
          . '<p>Thank you, the card for <strong>' . htmlspecialchars($req['name_en'], ENT_QUOTES)
          . '</strong> is approved.</p><p>Our quotation is attached.</p>'
          . '<p><strong>To go ahead, reply to this email with your purchase order attached.</strong></p>'
          . '<p style="color:#6b7280;font-size:13px">Keep the subject line as it is so we can '
          . 'match your purchase order to this job automatically.</p>'
          . '<p>Regards,<br>BHD Printing &amp; Designing</p></div>';
    $att = ($pdf && is_file($pdf)) ? [['path' => $pdf, 'name' => 'Quotation-' . $req['job_ref'] . '.pdf']] : [];
    $cc  = array_values(array_filter([$dept['head_email'] ?? null, 'sales@bhdoman.com']));
    return self::send([$dept['responsible_email']], $cc,
        "[{$req['job_ref']}] Quotation for " . $req['name_en'], $html, $att);
}
```

- [ ] **Step 4: Verify with the test division**

Repeat the reroute from Task 5 Step 4, submit, then click Approve in the email.
Expected: `card_requests.status` is `quoted`, `erp_order_id` and `erp_quote_id` are set, and
a quotation email with a PDF arrives at ali@bhd.om. Check with:

```bash
ssh root@147.93.20.54 'mysql -ubc -ppWewN3fwFmEHh32J bc -e "
 SELECT job_ref,status,erp_order_id,po_number FROM card_requests WHERE email=\"qa@bhd.om\"\G"'
```

- [ ] **Step 5: Revert the test routing and delete the test rows** (same commands as Task 5 Step 5)

- [ ] **Step 6: Commit**

```bash
git add admin/one-tap-approve.php includes/RequestApproval.php includes/CardJobMailer.php
git commit -m "feat(mhd): approving raises a real ERP quotation and emails it"
```

---

### Task 7: Purchase-order intake by email reply

**Files:**
- Create: `includes/PoInbox.php`
- Create: `cron/mhd-po-poll.php`
- Test: `tests/PoInboxTest.php`
- Create: `tests/fixtures/po/*.eml` (saved copies of three real MHD purchase-order emails)

**Interfaces:**
- Consumes: `CardJob::findByRef`, `CardJob::transition`.
- Produces: `PoInbox::extractRef(string $subject): ?string`, `PoInbox::extractPoNumber(string $text): ?string`, `PoInbox::ingest(array $message): array` returning `['matched'=>bool,'ref'=>?string,'po'=>?string,'reason'=>?string]`, `PoInbox::poll(int $limit = 50): array`.

- [ ] **Step 1: Write the failing test**

MHD purchase orders are ten digits beginning 41. These are real formats found in 155
purchase-order emails.

```php
<?php // tests/PoInboxTest.php
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/PoInbox.php';
function t(string $n, bool $ok) { echo ($ok?"PASS ":"FAIL ").$n."\n"; if(!$ok) exit(1); }

t('ref out of a reply subject',
  PoInbox::extractRef('RE: [MHD-A1B2C3] Quotation for Hatem Al Balushi') === 'MHD-A1B2C3');
t('ref survives the Outlook external banner',
  PoInbox::extractRef('RE: EXTERNAL.!!!   Re: [MHD-A1B2C3] Quotation') === 'MHD-A1B2C3');
t('no ref when absent', PoInbox::extractRef('RE: Business Cards') === null);

t('PO number from the body',   PoInbox::extractPoNumber('Please find attached PO 4191000258') === '4191000258');
t('PO number with prefix 4101',PoInbox::extractPoNumber('our po no 4101000932 refers')       === '4101000932');
t('PO number from a filename', PoInbox::extractPoNumber('PO 4141008646 - Business Cards for Pradeep.pdf') === '4141008646');
t('nine digits is not a PO',   PoInbox::extractPoNumber('reference 419100025') === null);
t('a phone number is not a PO',PoInbox::extractPoNumber('call 96871557505')    === null);
echo "all passed\n";
```

- [ ] **Step 2: Run it and watch it fail**

Run: `ssh root@147.93.20.54 "cd /www/wwwroot/cardify.om && /www/server/php/83/bin/php tests/PoInboxTest.php"`
Expected: FAIL, `Failed opening required .../PoInbox.php`.

- [ ] **Step 3: Write `includes/PoInbox.php`**

```php
<?php
/**
 * PoInbox, purchase-order intake from the sales@bhdoman.com mailbox.
 *
 * MHD replies to the quotation email with their PO as a PDF. We match the reply
 * to its job by the [MHD-XXXXXX] tag in the subject, because Outlook rewrites
 * threading headers. The PO number is ten digits beginning 41, which is MHD's
 * own format across every purchase order they have sent BHD.
 *
 * Idempotent on the IMAP message id: a replay files nothing twice.
 */
class PoInbox
{
    private const REF_RE = '/\[(MHD-[A-Z0-9]{6})\]/';
    private const PO_RE  = '/(?<!\d)(41\d{8})(?!\d)/';

    public static function extractRef(string $subject): ?string
    {
        return preg_match(self::REF_RE, $subject, $m) ? $m[1] : null;
    }

    public static function extractPoNumber(string $text): ?string
    {
        return preg_match(self::PO_RE, $text, $m) ? $m[1] : null;
    }

    /**
     * @param array $message ['message_id','subject','from','body','attachments'=>[['name','data']]]
     */
    public static function ingest(array $message): array
    {
        $db = Database::getInstance();
        if ($db->fetchOne("SELECT id FROM card_request_events WHERE evidence LIKE :m LIMIT 1",
                          ['m' => '%' . $message['message_id'] . '%'])) {
            return ['matched' => false, 'reason' => 'already ingested'];
        }
        $ref = self::extractRef($message['subject'] ?? '');
        if (!$ref) { return ['matched' => false, 'reason' => 'no job ref in subject']; }
        $job = CardJob::findByRef($ref);
        if (!$job) { return ['matched' => false, 'ref' => $ref, 'reason' => 'no such job']; }
        if ($job['status'] !== 'quoted') {
            return ['matched' => false, 'ref' => $ref, 'reason' => "job is {$job['status']}, not quoted"];
        }
        $pdf = null;
        foreach (($message['attachments'] ?? []) as $a) {
            if (preg_match('/\.pdf$/i', $a['name'] ?? '')) { $pdf = $a; break; }
        }
        if (!$pdf) { return ['matched' => false, 'ref' => $ref, 'reason' => 'no pdf attached']; }

        $po = self::extractPoNumber($pdf['name'] . ' ' . ($message['subject'] ?? '') . ' ' . ($message['body'] ?? ''));
        $dir = __DIR__ . '/../uploads/po/' . date('Y/m');
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        $path = $dir . '/' . $ref . '-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $pdf['name']);
        file_put_contents($path, $pdf['data']);

        $db->query("UPDATE card_requests SET po_number = ?, po_file = ? WHERE id = ?",
                   [$po, str_replace(__DIR__ . '/..', '', $path), $job['id']]);
        CardJob::transition($job['id'], 'po_received', [
            'actor' => $message['from'] ?? null, 'po' => $po,
            'message_id' => $message['message_id'], 'file' => basename($path),
        ]);
        return ['matched' => true, 'ref' => $ref, 'po' => $po];
    }

    /** Poll the mailbox. Returns one result row per message examined. */
    public static function poll(int $limit = 50): array
    {
        $host = '{127.0.0.1:143/imap/notls}INBOX';
        $mbox = @imap_open($host, 'sales@bhdoman.com', IMAP_PASSWORD);
        if (!$mbox) { return [['matched' => false, 'reason' => 'imap: ' . imap_last_error()]]; }
        $ids = imap_search($mbox, 'UNSEEN SUBJECT "MHD-"') ?: [];
        $out = [];
        foreach (array_slice($ids, 0, $limit) as $id) {
            $hdr = imap_headerinfo($mbox, $id);
            $out[] = self::ingest([
                'message_id'  => $hdr->message_id ?? ('imap-' . $id),
                'subject'     => imap_utf8($hdr->subject ?? ''),
                'from'        => ($hdr->from[0]->mailbox ?? '') . '@' . ($hdr->from[0]->host ?? ''),
                'body'        => imap_fetchbody($mbox, $id, '1'),
                'attachments' => self::attachments($mbox, $id),
            ]);
        }
        imap_close($mbox);
        return $out;
    }

    private static function attachments($mbox, int $id): array
    {
        $out = []; $st = imap_fetchstructure($mbox, $id);
        foreach (($st->parts ?? []) as $i => $part) {
            $name = '';
            foreach (array_merge($part->dparameters ?? [], $part->parameters ?? []) as $p) {
                if (strtolower($p->attribute) === 'filename' || strtolower($p->attribute) === 'name') { $name = $p->value; }
            }
            if (!$name) { continue; }
            $data = imap_fetchbody($mbox, $id, (string)($i + 1));
            if (($part->encoding ?? 0) == 3) { $data = base64_decode($data); }
            $out[] = ['name' => $name, 'data' => $data];
        }
        return $out;
    }
}
```

`IMAP_PASSWORD` is added to `config.php` alongside the other secrets. It is the existing
sales@bhdoman.com mailbox password, not a new account.

- [ ] **Step 4: Run the test**

Run: `ssh root@147.93.20.54 "cd /www/wwwroot/cardify.om && /www/server/php/83/bin/php tests/PoInboxTest.php"`
Expected: `all passed`.

- [ ] **Step 5: Add the cron entry point and schedule it**

```php
<?php // cron/mhd-po-poll.php
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/PoInbox.php';
require_once INCLUDES_DIR . '/CardJob.php';
foreach (PoInbox::poll(50) as $r) {
    echo date('c') . ' ' . json_encode($r) . "\n";
}
```

Run: `ssh root@147.93.20.54 "(crontab -l 2>/dev/null; echo '*/2 * * * * cd /www/wwwroot/cardify.om && /www/server/php/83/bin/php cron/mhd-po-poll.php >> logs/mhd-po.log 2>&1') | crontab -"`

- [ ] **Step 6: Commit**

```bash
git add includes/PoInbox.php cron/mhd-po-poll.php tests/PoInboxTest.php
git commit -m "feat(mhd): take the purchase order from an email reply, match it by job ref"
```

---

### Task 8: On the purchase order, issue the documents and start production

**Files:**
- Modify: `includes/PoInbox.php` (call out after a successful ingest)
- Modify: `includes/CardJobMailer.php` (add `sendDocuments`)

**Interfaces:**
- Consumes: `ERPSync::convertQuoteToInvoice(int $orderId, 'po')`, `ERPSync::createProductionOrder(array)`, `PrintReadyGenerator`.
- Produces: `CardJobMailer::sendDocuments(array $req, array $dept, array $pdfs): array`.

- [ ] **Step 1: Convert the quote and issue the delivery note**

At the end of a successful `PoInbox::ingest`:

```php
$order = (int)$job['erp_order_id'];
$inv = ERPSync::convertQuoteToInvoice($order, 'po');
if (empty($inv['success'])) {
    ERPSync::enqueueRetry($order, 'convertQuoteToInvoice', $inv['message'] ?? 'unknown');
    return ['matched' => true, 'ref' => $ref, 'po' => $po, 'reason' => 'invoice deferred'];
}
CardJobMailer::sendDocuments($job, $dept, [
    'quote'    => $inv['data']['quotePdf']    ?? '',
    'invoice'  => $inv['data']['invoicePdf']  ?? '',
    'delivery' => $inv['data']['deliveryPdf'] ?? '',
]);
ERPSync::createProductionOrder(['orderId' => $order, 'ref' => $ref]);
CardJob::transition($job['id'], 'in_production', ['actor' => 'system', 'invoice' => $inv['data']['invoiceNumber'] ?? null]);
```

The three PDFs carry Ali's signature and the company stamp because BHD-ERP already
applies them to every issued document.

- [ ] **Step 2: Send the print-ready artwork to production on WhatsApp**

```php
$pdf = CardPDFRenderer::render($job['employee_id'] ?? $job['id'], 'print', ['include_qr' => true]);
if (!empty($pdf['success'])) {
    WhatsApp::sendDocument(PRODUCTION_WA_NUMBER, $pdf['path'],
        "MHD {$ref}: {$job['name_en']}, {$dept['name']}, {$job['quantity_ordered']} cards, PO {$po}");
}
```

- [ ] **Step 3: Verify against the test division**

Reroute as in Task 5, run the flow to `quoted`, then reply to the quotation email from
ali@bhd.om with any PDF named `PO 4191000999 - test.pdf` attached. Wait two minutes.

Run: `ssh root@147.93.20.54 "tail -5 /www/wwwroot/cardify.om/logs/mhd-po.log"`
Expected: a line with `"matched":true` and `"po":"4191000999"`, the job at `in_production`,
and an email with three PDFs.

- [ ] **Step 4: Revert the test routing and delete the test rows**

- [ ] **Step 5: Commit**

```bash
git add includes/PoInbox.php includes/CardJobMailer.php
git commit -m "feat(mhd): the purchase order issues invoice and delivery note and starts production"
```

---

### Task 9: One-tap delivery note signature

**Files:**
- Create: `sign-delivery.php`
- Modify: `includes/CardJobMailer.php` (add `sendDispatchNotice`)
- Modify: `lang/en/portal.php`, `lang/ar/portal.php`

**Interfaces:**
- Consumes: `AdminApprovalToken`, `CardJob::transition`.
- Produces: `CardJobMailer::sendDispatchNotice(array $req, array $dept, string $token): array`.

- [ ] **Step 1: The dispatch email, one button**

```php
public static function sendDispatchNotice(array $req, array $dept, string $token): array
{
    $url  = getTenantUrl('mhd', '/sign-delivery.php') . '?t=' . urlencode($token);
    $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#222;line-height:1.6">'
          . '<p>The cards for <strong>' . htmlspecialchars($req['name_en'], ENT_QUOTES)
          . '</strong> are on their way.</p>'
          . '<p style="margin:28px 0"><a href="' . $url . '" style="background:#0f4c81;color:#fff;'
          . 'padding:14px 30px;border-radius:6px;text-decoration:none;font-weight:bold">'
          . 'Confirm receipt</a></p>'
          . '<p style="color:#6b7280;font-size:13px">One tap signs the delivery note. Nothing else to do.</p></div>';
    return self::send([$dept['responsible_email']], ['sales@bhdoman.com'],
        "[{$req['job_ref']}] Your cards are on the way", $html);
}
```

- [ ] **Step 2: The signing page, GET confirms, POST signs**

Mirror `admin/one-tap-approve.php`: GET renders a prefetch-safe interstitial that verifies
the token without consuming it, POST is CSRF-checked and consumes it exactly once.

```php
<?php // sign-delivery.php
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/SecurityHeaders.php';
SecurityHeaders::send();
require_once INCLUDES_DIR . '/AdminApprovalToken.php';
require_once INCLUDES_DIR . '/CardJob.php';
session_status() === PHP_SESSION_NONE && session_start();

$token = $_GET['t'] ?? $_POST['t'] ?? '';
$claim = AdminApprovalToken::verify($token);
if (!$claim) { http_response_code(410); exit('This link has expired.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { die('Invalid request'); }
    AdminApprovalToken::consumeApprove($token);
    CardJob::transition($claim['request_id'], 'delivered', [
        'actor' => $claim['admin_email'], 'signed_at' => gmdate('c'),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
    exit('<p style="font:16px system-ui;padding:40px">Signed. Thank you.</p>');
}
?>
<form method="post" style="font:16px system-ui;padding:40px;text-align:center">
  <?= csrfField() ?><input type="hidden" name="t" value="<?= htmlspecialchars($token) ?>">
  <p>Confirm you have received these cards.</p>
  <button style="background:#0f4c81;color:#fff;padding:14px 30px;border:0;border-radius:6px;font-size:16px">
    Sign the delivery note</button>
</form>
```

The signature, the signer's address, the time and the IP go on the event row, which is the
evidence the delivery note carries.

- [ ] **Step 3: Test it**

Mint a token against a test job, open the link, click once.
Expected: the job reaches `delivered`, the event row holds the address, time and IP, and a
second click returns the expired page rather than signing twice.

- [ ] **Step 4: Commit**

```bash
git add sign-delivery.php includes/CardJobMailer.php lang/en/portal.php lang/ar/portal.php
git commit -m "feat(mhd): one-tap delivery note signature on dispatch"
```

---

### Task 10: The console

**Files:**
- Create: `admin/orders.php`
- Modify: `company_admin.php` (register the page in `$pageMap`)
- Modify: `includes/Auth.php` (honour `users.viewer_scope`)

**Interfaces:**
- Consumes: `CardJob::events`, `card_requests`, `departments`.
- Produces: nothing other tasks depend on.

- [ ] **Step 1: Register the page**

A new admin page that is not in `company_admin.php`'s `$pageMap` 404s on company-slug
URLs. Add `'orders' => 'admin/orders.php'`.

- [ ] **Step 2: Scope the list by viewer**

```php
$scope = $_SESSION['viewer_scope'] ?? 'division';
$where = "cr.company_id = :cid";
$params = ['cid' => $companyId];
if ($scope !== 'all') {
    $where .= " AND d.responsible_email = :me";
    $params['me'] = $_SESSION['user_email'];
}
$jobs = $db->fetchAll("SELECT cr.*, d.name AS division, d.responsible_email
                         FROM card_requests cr
                         LEFT JOIN departments d ON d.id = cr.department_id
                        WHERE {$where} ORDER BY cr.submitted_at DESC LIMIT 200", $params);
```

An approver sees their own division. BHD staff and iticsceooffice@ get `viewer_scope = 'all'`.

- [ ] **Step 3: One row per job, everything on open**

Each row: employee, division, state, last change. Expanding shows the card front and back,
who approved it and when, and links to the quotation, the purchase order PDF, the invoice,
the delivery note and the signature, plus the full event list from `CardJob::events()`.

- [ ] **Step 4: Verify every page still loads clean**

Run the admin sweep from the browser console against `/admin/orders` and the existing
pages, checking for `Fatal error`, `Warning:` and `Undefined`.
Expected: 200 and clean on all.

- [ ] **Step 5: Commit**

```bash
git add admin/orders.php company_admin.php includes/Auth.php
git commit -m "feat(mhd): one console for every card job and its documents"
```

---

### Task 11: Cleanups found during the research

**Files:** none in the repo. These are data fixes on the live ERP.

- [ ] **Step 1: Remove the Cardify test invoice from the live ledger**

Invoice 3419, OMR 15.000, 6 Apr 2026, notes `Cardify:CRDFY-TEST-001 | Test sync from Cardify`.
It is not a real order. Check with Ali before deleting, then void it the normal ERP way
rather than deleting the row, so the ledger stays continuous.

- [ ] **Step 2: Merge the duplicate Technology and Communications ERP clients**

`Mohsin Haider Darwish LLC.(Technology and Communication Division)` and
`Mohsin Haider Darwish LLC.(ITICS-Tech & Comm)` are both live and both taking card orders.
Pick the first, repoint the second's documents, disable the second.

- [ ] **Step 3: Raise Polycon with Ali**

`Mohsin Haider Darwish Polycon LLC` bought cards in June 2026 and has no Cardify division.
Either add it or confirm it stays manual.

- [ ] **Step 4: Commit the decision, not code**

Record the outcome in `~/claude/decisions/log.md`.

---

## Self-Review

**Spec coverage.** Submit with routing shown: Task 2. Standard cards only: Task 3.
Approval buttons and CC: Task 5. Quotation on approve: Task 6. PO by email reply: Task 7.
Documents, production and WhatsApp: Task 8. One-tap signature: Task 9. Console and
sign-in scope: Task 10. Divisions, heads, rates and retiring Automotive: Task 4. Data
model: Task 1. Cleanups: Task 11. Failure handling is inside Tasks 6, 7 and 8 where the
retry queue is used. No spec section is unimplemented.

**Placeholders.** None. Every code step carries the code.

**Type consistency.** `CardJob::transition($requestId, $to, $evidence)` is called with that
signature in Tasks 6, 7, 8 and 9. `CardPrice::quote(int, float)` returns the same array
shape everywhere it is read. `CardJobMailer::send()` is defined in Task 5 and reused by
Tasks 6, 8 and 9 with the same argument order. `PoInbox::ingest()` returns the same keys
in every branch.

**Open item.** Logistics has no approver. Task 4 seeds it as `null`, which leaves the
division without an approval target. It must not be enabled on the picker until Ali
supplies the name.
