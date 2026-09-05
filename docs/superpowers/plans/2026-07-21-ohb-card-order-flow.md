# OHB One-Tap Card Order Flow — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement task-by-task. Steps use `- [ ]` checkboxes.

**Goal:** Turn the OHB (Oman Housing Bank) card-ordering path into a single spine: employee submits (200 default, design locked) → admin gets an email showing the design with a one-tap magic-link Approve → one action generates the card, places a 200-pcs BHD print order, emails `info@bhdoman.com` with the print-ready sheet attached, and opens an ERP quote against Oman Housing Bank → attaching a PO or paying auto-upgrades to invoice + sales order + delivery, all tracked in one view.

**Architecture:** Additive on top of the existing `card_requests` → approve → `print_orders` → `ERPSync` stack. New pieces: a scoped admin-approval token (`admin_approval_tokens`), an approval landing page + one-tap approve endpoint, an upgraded admin-notification email carrying the design + quantity, an `ERPSync::createQuote()` path plus a new BHD-ERP endpoint `POST /api/admin/cardify/create-quote`, and PO/payment hooks that upgrade the quote to invoice+SO+delivery.

**Tech Stack:** Cardify = PHP 7.4 (no framework) + MySQL (`bc` db) + Fabric.js; BHD-ERP = Node/Express + MongoDB + Mongoose. Deploy Cardify via `/usr/local/bin/deploy-cardify.sh` (never raw git pull). Deploy BHD-ERP via push to `main` (PR-gated for this endpoint).

## Global Constraints (verbatim)

- **No em dashes** in any output, code comment, commit, email copy, or doc. Commas/colons/periods only. En dashes fine for ranges.
- OMR to **3 decimals**; `DECIMAL(10,3)`; `number_format($x, 3)`.
- New MySQL tables: `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`.
- File uploads: real MIME via `finfo(FILEINFO_MIME_TYPE)`, derive ext from verified MIME (CLAUDE.md rule 7).
- Company + employee ids are **VARCHAR strings** (UUID or slug). Never `(int)`-cast an id.
- Fonts: `fonts.bhd.om` only. WhatsApp links: `api.whatsapp.com/send?phone=...`, never `wa.me`.
- Email buttons: solid `background-color` first, gradient via `background-image` (rule 48). Brand cyan `#009bc1`.
- Any new admin page → register in `company_admin.php` `$pageMap`. Links use `getAdminBasePath()` + `$ext`.
- New public/token endpoint → cross-tenant isolation guard (rule 31) + CSRF on POST + rate limit.
- Cardify PDO native prepares are ON: never reuse a `:name` placeholder twice in one SQL string (rule 12).
- Work in the worktree `.worktrees/ux-employee-tabs/` (= real `main`).
- BHD-ERP JWT token in `erp_settings.erp_api_token` must exist in Mongo `adminpasswords.loggedSessions` or the API 401s.

**Key file map (verified this session):**
- Submit: `portal.php:280-524` → `card_requests` insert `:420`; qty `:295,401`.
- Admin email: `includes/Mailer.php:1048-1074` (`admin_new_request`); notify send `portal.php:487-515`.
- Approve: `admin/requests.php:47-149`; redirect to `batch_generate` `:136-138`.
- Print order create: `includes/PrintShopIntegration.php:242`; shop email notify `:300-313`, `:426-480` (`print_order_received`, to `print@bhd.om`, no attachment).
- ERP sync: `includes/ERPSync.php` — `recordPayment()` `:157-290` (pay-time), `createProductionOrder()` `:299-357`, `createConsolidatedInvoice()` `:366-405`. `erp_settings` live (url+token+client, sync=1).
- PO upload: `includes/PrintShopBilling.php:215` (`print_orders.po_*`); company PO pool `admin/print-tracking.php` + `company_print_pos`.
- `print_orders` already has: `quotation_*`, `po_*`, `invoice_*`, `delivery_note_*`, `erp_quote_id/erp_invoice_id/erp_payment_id/erp_invoice_number`, `status` ENUM(pending,confirmed,processing,printing,shipped,delivered,cancelled).
- OHB: company_id `a0b10000-0000-0000-0000-00000000b001`, slug `ohb`, admin `Adnan.r@ohb.co.om`, `erp_client_name` NULL. BHD printshop `id=2`, `is_internal_provider=1`, email `print@bhd.om`.

---

## Phase 0: Config (reversible, no deploy)

### Task 0: OHB + BHD config so downstream books correctly

**Files:** direct SQL on the `bc` db.

- [ ] **Step 1: Back up the two rows first**

```bash
ssh root@147.93.20.54 "mysql -u bc -ppWewN3fwFmEHh32J bc -e \"SELECT id, erp_client_name FROM companies WHERE id='a0b10000-0000-0000-0000-00000000b001'; SELECT id, name, email FROM print_shops WHERE id=2;\""
```

- [ ] **Step 2: Set OHB ERP customer name** (so an auto-quote books against Oman Housing Bank, not BHD)

```bash
ssh root@147.93.20.54 "mysql -u bc -ppWewN3fwFmEHh32J bc -e \"UPDATE companies SET erp_client_name='Oman Housing Bank S.A.O.G.' WHERE id='a0b10000-0000-0000-0000-00000000b001';\""
```

- [ ] **Step 3: Verify** `erp_client_name` now non-null. Expected: `Oman Housing Bank S.A.O.G.`

> Note: BHD printer routing to `info@bhdoman.com` is done in code (Phase 3, Task 6) via a CC/production-routing constant, NOT by changing `print_shops.email` (that address is used elsewhere).

---

## Phase 1: Portal — 200 default + design locked into the request

### Task 1: Default the OHB request to 200 pcs and capture the design preview

**Files:**
- Modify: `portal.php:295` (quantity default), `portal.php:1141-1151` (quantity UI), `lang/en/portal.php:31-34` + `lang/ar/portal.php` (copy)
- Verify: mocked-session render is not needed; this is public. Use curl.

**Interfaces:**
- Produces: `card_requests.quantity_requested` defaults to 200 for OHB; `preview_front_path`/`preview_back_path` populated on submit.

- [ ] **Step 1:** In `portal.php`, change the quantity read so an empty/absent value defaults to the company's standard order (200), not 1:

```php
// portal.php ~:295 — was: max(1, (int)($_POST['quantity_requested'] ?? 1))
$defaultQty = (int)($company['default_order_qty'] ?? 200);
$quantityRequested = max(1, (int)($_POST['quantity_requested'] ?? $defaultQty));
```

- [ ] **Step 2:** Add `default_order_qty INT DEFAULT 200` to `companies` (migration) so it is per-tenant, and backfill OHB to 200:

```sql
ALTER TABLE companies ADD COLUMN default_order_qty INT NOT NULL DEFAULT 200;
```

- [ ] **Step 3:** Change the quantity UI from "sets" to explicit pcs with 200 preselected. Options: 100 / 200 (standard) / 500 / 1000 pcs. Update `lang/{en,ar}/portal.php` keys: `quantity_label` = "Quantity", `quantity_200` = "200 pcs (standard)", drop the "each set = 100" hint or restate in pcs. No em dashes.

- [ ] **Step 4:** Confirm `preview_front`/`preview_back` are always captured. The MHD department path already renders a print PDF; for the standard path store the client preview URLs into `preview_front_path`/`preview_back_path` on insert (they already flow at `portal.php:372-373`, confirm they persist to the `_path` columns, add if only the non-path columns are set).

- [ ] **Step 5: Deploy + verify**

```bash
cd /Users/ali/claude/projects/cardify.om/.worktrees/ux-employee-tabs/ && git add -A && git commit -m "portal: default OHB card requests to 200 pcs, capture design preview" && git push origin main
ssh root@147.93.20.54 "/usr/local/bin/deploy-cardify.sh"
curl -s "https://ohb.cardify.om/?cb=$RANDOM" | grep -iE '200 pcs|Quantity' | head
```

Expected: the submit form shows "200 pcs (standard)" preselected.

---

## Phase 2: Admin approval magic link (Both: one-tap + review page)

### Task 2: `admin_approval_tokens` table + `AdminApprovalToken` class

**Files:**
- Create: `database/migrations/NNN_admin_approval_tokens.php`
- Create: `includes/AdminApprovalToken.php`

**Interfaces:**
- Produces:
  - `AdminApprovalToken::mint(string $companyId, string $requestId, string $adminEmail): string` (returns 40-char token)
  - `AdminApprovalToken::verify(string $token): ?array` (returns `['company_id','request_id','admin_email','expires_at','used_at']` or null; null if expired/unknown)
  - `AdminApprovalToken::consumeApprove(string $token): bool` (single-use for the one-tap approve; marks `used_at`, returns false if already used)
  - `AdminApprovalToken::startAdminSession(array $row): void` (sets `$_SESSION['user_id']`, `company_id`, `user_role='admin'` scoped to that company)

- [ ] **Step 1:** Migration. Table columns: `id VARCHAR(36) PK`, `company_id VARCHAR(36)`, `request_id VARCHAR(36)`, `admin_email VARCHAR(255)`, `token CHAR(64) UNIQUE`, `expires_at TIMESTAMP`, `used_at TIMESTAMP NULL`, `created_at`. Indexes on `token`, `request_id`. `utf8mb4_unicode_ci`.

- [ ] **Step 2:** `AdminApprovalToken` class. `mint()` = `bin2hex(random_bytes(20))`, 7-day expiry. `verify()` filters `expires_at > NOW()`. `consumeApprove()` uses `UPDATE ... SET used_at=NOW() WHERE token=:t AND used_at IS NULL` and checks affected rows (single-use race-safe). `startAdminSession()` mirrors the mocked-session keys the app uses for admins (rule 34).

- [ ] **Step 3: Test (mocked)** — write `/tmp/tok.php` that mints, verifies, double-consumes; assert second consume returns false. Run with `/www/server/php/83/bin/php`.

- [ ] **Step 4: Commit.**

### Task 3: Approval landing page + one-tap approve endpoint

**Files:**
- Create: `admin/approve-request.php` (token → auto-login → render request with design + Approve / Approve & Send-to-Print / Reject)
- Create: `admin/one-tap-approve.php` (GET token → consume → run the full approve+print chain → confirmation page)
- Modify: `company_admin.php` `$pageMap` (register `approve-request`)

**Interfaces:**
- Consumes: `AdminApprovalToken::verify/consumeApprove/startAdminSession`; the approve chain from Task 5.
- Produces: URLs `getTenantUrl($slug, '/admin/approve-request?t=<token>')` and `.../one-tap-approve?t=<token>`.

- [ ] **Step 1:** `approve-request.php`: `verify()` the token; if valid call `startAdminSession()` and render the request (design preview front+back via `preview_*_path`, employee data, quantity) with three buttons posting to the existing `admin/requests.php` actions (reuse, do not fork approve logic). If token invalid/expired show a clear "link expired, please log in" page. Cross-tenant guard: the session is scoped to `company_id` from the token only.
- [ ] **Step 2:** `one-tap-approve.php`: `consumeApprove()`; on success run the shared approve chain (Task 5) for that request and render a branded "Approved. Sent to print. Quote raised." confirmation with links. On already-used token, render "already approved" with a link to the order.
- [ ] **Step 3:** Register `approve-request` in `$pageMap`.
- [ ] **Step 4: Test** — mint a token for OHB's pending request (Ali Al-Zaabi, id `8056a607-cd98-4a9a-9304-6a6900c46580`), curl the landing URL, assert 200 + design `<img>` present + Approve button. Curl the one-tap URL in a disposable token, assert the chain ran (order row created).
- [ ] **Step 5: Commit + deploy.**

### Task 4: Upgrade the admin-notification email (design + quantity + one-tap button + review link)

**Files:**
- Modify: `includes/Mailer.php:1048-1074` (`admin_new_request` template)
- Modify: `portal.php:487-515` (payload: add `quantity`, `approve_url`, `review_url`, `design_front_url`)

**Interfaces:**
- Consumes: `AdminApprovalToken::mint()` (called in `portal.php` right after the request insert).

- [ ] **Step 1:** In `portal.php` after insert, `mint()` a token and build `approve_url` (one-tap) + `review_url` (landing). Pass `quantity_requested`, the absolute `preview_front_path` URL, and both URLs into the template payload.
- [ ] **Step 2:** Rewrite `admin_new_request` body: show the **card design image** (front preview, absolute URL), the employee summary table **including quantity (200 pcs)**, a primary solid-cyan **"Approve & Send to Print"** button (→ `approve_url`), and a secondary **"Review design first"** link (→ `review_url`). Solid `background-color` first (rule 48). No em dashes. WhatsApp/link rules honored.
- [ ] **Step 3:** Also send the same notification to the admin over WhatsApp (optional, gated) via `WhatsApp::sendMessage` with the two links (Anna line). Skip if number absent.
- [ ] **Step 4: Test** — render `admin_new_request` via reflection on the VPS (rule 48 recipe), assert: design `<img>`, "200 pcs", `background-color: #009bc1`, `approve_url` present, no ` -- `, no `wa.me`.
- [ ] **Step 5: Commit + deploy.**

---

## Phase 3: Approve → auto 200-pcs BHD print order + info@bhdoman.com with design attached

### Task 5: Shared "approve → generate card → place print order" chain

**Files:**
- Modify: `admin/requests.php:47-149` (approve action) — after employee create + card generate, place the print order
- Reuse: `includes/PrintShopIntegration.php::createOrder`

**Interfaces:**
- Produces: `approveRequestChain(string $requestId, string $adminId): array` returning `['employee_id','order_id','order_number','erp_quote_number'?]`. Called by `requests.php`, `one-tap-approve.php`.

- [ ] **Step 1:** Extract the current approve body into a shared function `approveRequestChain()` (put it in `includes/` so both callers use it). Keep the existing employee-create + digital-card-generate behaviour.
- [ ] **Step 2:** After the card is generated, place a print order: `PrintShopIntegration::createOrder()` with `print_shop_id=2` (BHD), `quantity = card_requests.quantity_requested` (200), `paper_type` = OHB default, `card_front_url`/`card_back_url` = the generated card URLs, `payment_status='pending'`. Store `order_id` on the request (`admin_notes` or a new `print_order_id` column on `card_requests`).
- [ ] **Step 3:** Set `card_requests.status='approved'` and stamp reviewer as today. Do not double-place if an order already exists for the request (idempotency guard on `card_requests.print_order_id`).
- [ ] **Step 4: Test** — run `approveRequestChain()` for a disposable OHB request via `/tmp` PHP; assert an employee + a `print_orders` row (qty 200, shop 2) exist.
- [ ] **Step 5: Commit + deploy.**

### Task 6: Route BHD print-order notification to info@bhdoman.com with the print-ready sheet attached

**Files:**
- Modify: `includes/PrintShopIntegration.php:426-480` (`sendOrderNotification`)
- Reuse: `CardPDFRenderer::render($employeeId, 'print')` for the attachment; `Mailer::sendTemplate($to, $tpl, $data, $attachments)` (4th arg)

**Interfaces:**
- Consumes: the order row + employee id from Task 5.

- [ ] **Step 1:** Add a constant `BHD_PRODUCTION_EMAIL = 'info@bhdoman.com'`. When `print_shop_id == 2` (BHD internal provider), set the notification `$to` (or add as CC) to `info@bhdoman.com`.
- [ ] **Step 2:** Render the print-ready front+back PDF (`CardPDFRenderer::render($employeeId, 'print')`) and pass it as an attachment to `Mailer::sendTemplate(...)` (currently called with 3 args at `:457`, add the 4th). Fall back to no-attachment if render fails (non-fatal).
- [ ] **Step 3:** Update `print_order_received` template to name the file and state "print-ready sheet attached". No em dashes.
- [ ] **Step 4: Test** — place a test BHD order for OHB's real employee `sami.alismaili`; assert `email_logs` shows a send to `info@bhdoman.com` with a non-empty attachment; verify the attached PDF via `pdftoppm` + Read.
- [ ] **Step 5: Commit + deploy.**

---

## Phase 4: ERP auto-quote on approve (cross-repo, PR-gated)

### Task 7: BHD-ERP endpoint `POST /api/admin/cardify/create-quote`

**Files (BHD-ERP repo, feature branch → PR into main):**
- Modify: the cardify admin API controller that already serves `record-payment` / `create-production-order` (find via `grep -r "cardify/record-payment" backend/src`)
- Add route `create-quote`

**Interfaces:**
- Produces: `POST /api/admin/cardify/create-quote` body `{ clientName, orderNumber, amount, description, currency:'OMR', items:[{itemName, quantity, price}] }` → returns `{ quoteId, quoteNumber }`. Idempotent on `orderNumber` (409 if a quote already exists, same as record-payment).

- [ ] **Step 1:** Branch `feature/cardify-create-quote`. Implement the handler: look up the client by `clientName` (create if missing per existing helper), create a **Quote only** (no invoice, no JE) with the line items, return quote id/number. Reuse the Quote model's `itemName` requirement (skill note: Quote needs `itemName`, not `description`).
- [ ] **Step 2:** Idempotency: detect existing quote via a marker in `notes` (`Cardify:{orderNumber}`), return 409 with the existing quote on repeat.
- [ ] **Step 3:** Add a jest test in the ERP repo mirroring the record-payment test. Run `npm test` (maxWorkers=1).
- [ ] **Step 4:** Open PR into `main`; CI (`ci.yml`) green is the gate. Merge → deploy fires. Verify `gh run list --workflow=deploy.yml --branch=main --limit=1`.

### Task 8: Cardify `ERPSync::createQuote()` called at approve/print-order time

**Files:**
- Modify: `includes/ERPSync.php` (add `createQuote(array $order, array $company): array`)
- Modify: the approve chain (Task 5) to call it after the print order is placed

**Interfaces:**
- Consumes: BHD-ERP `create-quote` endpoint; `erp_settings` (url/token); `companies.erp_client_name` (OHB = "Oman Housing Bank S.A.O.G.").
- Produces: writes `print_orders.erp_quote_id`, `erp_quote_number`, `erp_sync_status='quoted'`.

- [ ] **Step 1:** `createQuote()` mirrors `recordPayment()`'s auth/envelope (JWT header + the loggedSessions requirement) but hits `create-quote`. `clientName = company['erp_client_name'] ?: settings['erp_client_name']` — for OHB this resolves to Oman Housing Bank, NOT BHD. `amount` = order subtotal (200-pcs price), 3-dp. Non-fatal on failure (log, do not block approval).
- [ ] **Step 2:** Call it from `approveRequestChain()` right after `createOrder()`. Store the returned quote id/number on the order.
- [ ] **Step 3: Test** — approve a disposable OHB request; assert `print_orders.erp_quote_number` populated and the ERP shows a quote against Oman Housing Bank (query via mongo MCP or the ERP admin API).
- [ ] **Step 4: Commit + deploy (Cardify).**

---

## Phase 5: PO attached OR paid → invoice + sales order + delivery

### Task 9: Upgrade the ERP quote to invoice + SO on PO-attach or payment

**Files:**
- Modify: `includes/PrintShopBilling.php:215` (PO upload) — after saving the PO, if the order has an `erp_quote_id`, call an ERP upgrade
- Modify: `includes/Payment.php` (`confirmPrintOrder`) — already calls `recordPayment`; ensure it links to the existing quote instead of creating a fresh one
- Modify/extend: `includes/ERPSync.php` (`convertQuoteToInvoice(array $order, string $trigger): array`)
- BHD-ERP: extend `record-payment` (or add `convert-quote`) to accept an existing `quoteId`/`orderNumber` and raise invoice + sales order (+ delivery note) from it

**Interfaces:**
- Produces: `print_orders.erp_invoice_id/erp_invoice_number`, a sales-order id, and a delivery-note id; `erp_sync_status='invoiced'`.

- [ ] **Step 1 (ERP):** Extend the ERP side so that given an existing `Cardify:{orderNumber}` quote, it creates the invoice + sales order + delivery note in one call (idempotent). PR-gated, same as Task 7.
- [ ] **Step 2 (Cardify):** `convertQuoteToInvoice()` in ERPSync. Call it from (a) PO upload when `erp_quote_id` present, and (b) payment confirmation. Guard against double-invoicing (check `erp_invoice_id` already set).
- [ ] **Step 3:** Write `delivery_note_number`/`delivery_note_external_id` back to `print_orders` from the ERP response.
- [ ] **Step 4: Test** — for a quoted OHB order, (a) upload a PO → assert invoice+SO+delivery ids populate; (b) separately, pay a different quoted order → same. Verify no double invoice on repeat.
- [ ] **Step 5: Commit + deploy (both repos).**

---

## Phase 6: One tracking view (all stages in one place)

### Task 10: Unified order timeline on the request/order

**Files:**
- Modify: `admin/order_detail.php` (or create `admin/order-timeline.php`, register in `$pageMap`)

**Interfaces:**
- Consumes: `print_orders` (status, quotation_*, po_*, invoice_*, delivery_note_*, erp_* columns).

- [ ] **Step 1:** Render a single timeline for an order: Request submitted → Approved → Card generated → Print order placed (200 pcs) → Sent to BHD (info@bhdoman.com) → ERP quote #Q → PO attached / Paid → Invoice #INV → Sales order → Delivery note, each with its timestamp + document link where present. Mobile + desktop.
- [ ] **Step 2:** Link it from `admin/requests.php` and the approval confirmation page.
- [ ] **Step 3: Test** — mocked-session render `order-timeline` for the OHB order; assert all reached stages show with links, unreached stages show as pending.
- [ ] **Step 4: Commit + deploy.**

---

## Design track (parallel, separable): /award-site-process on ohb.cardify.om submit page

Run the award-site-process pipeline on the OHB-facing `portal.php` submit experience (the surface a bank's staff sees): forensic critique of the current portal, GCC/fintech benchmark + motion study, thesis cull, three divergent prototypes, generator-evaluator jury rounds to threshold, human gate with Ali. Feed the winning concept back into `portal.php` after Phase 1 lands (so the 200-default + design-lock data changes are already in place). Keep the admin approval page (`approve-request.php`) as a clean functional design from the impeccable/design-stack skills, not the full award pipeline.

---

## Self-review

- **Spec coverage:** employee submit+200 (Phase 1), email with design+magic link both-modes (Phase 2), approve→print (Task 5), info@bhdoman.com + attachment (Task 6), auto ERP quote booked to OHB (Phase 4), PO/pay→invoice+SO+delivery (Phase 5), tracking (Phase 6), award design (design track). All mapped.
- **Cross-repo risk:** Tasks 7 and 9-step-1 touch BHD-ERP production → feature branch + PR + green CI is the gate (never SSH deploy).
- **Idempotency:** every ERP call keyed on `Cardify:{orderNumber}`; approve guarded on `card_requests.print_order_id`; one-tap token single-use.
- **Booking correctness:** OHB `erp_client_name` set in Phase 0 so quotes/invoices book against Oman Housing Bank, not BHD.
