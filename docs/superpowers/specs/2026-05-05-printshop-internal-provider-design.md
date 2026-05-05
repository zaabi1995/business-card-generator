# Print Shop Internal-Provider Mode + Multi-Operator Phone Login

**Status:** Approved by Ali, ready for implementation plan
**Date:** 2026-05-05
**Scope:** Cardify, print-shop side
**Builds on:** per-client pricing overrides shipped 2026-05-05 (commits 160fb58 + 8644b48)

## Problem

BHD is the print shop on Cardify but ALSO acts as an in-house provider that orders cards on behalf of any client (Otech, Hosn, etc.). Today the print-shop side can only see orders that arrived through the customer's own admin flow. Three gaps to close:

1. BHD operators (Ali, Arshad, Hussain) can't all log in. The print shop has one `user_id` slot.
2. There's no UI for a print shop to browse all client companies, pick an employee, fill the print form, and place an order.
3. The A4 imposition engine exists (`scripts/imposition-vector.py`, 5x2 = 10 cards/sheet) but the print-shop side has no trigger to download the production sheet.

## Goals

- Multiple operators log into one print shop account by phone number, OTP via WhatsApp or email (matching the channel pattern used elsewhere in Cardify).
- Audit log records which operator did what, so any of Ali / Arshad / Hussain can act with full BHD permissions but actions stay attributable.
- "Internal provider" print shops (just BHD for now, flag-based) can browse every Cardify company, view their employees, generate previews, place print orders, and download A4 print sheets.
- The existing per-client pricing overrides + min-quantity gate continue to work end-to-end.
- Other print shops (non-internal) see no change.

## Non-Goals (v1)

- Tiered operator roles. Ali confirmed flat: any logged-in operator has full shop powers, differentiated only via audit log.
- Operator self-registration. Operators are added by an existing operator from the in-app admin page.
- Multi-employee gang sheets (10 different cards on one A4). v1 stays at 10 copies of one card per A4, matching the current `imposition-vector.py` output.
- Delegating ordering to non-internal print shops via the same UI. The flag is the gate.

## High-Level Design

```
                    +---------------------------+
  Phone or email -->|  printshop/login.php      |
                    |  - email+password tab     |
                    |  - phone/email OTP tab    |
                    +-------------+-------------+
                                  |
                                  v
        OtpService::send() -- WhatsApp or email channel
                                  |
                                  v
              +--------------------------------------+
              |  printshop/verify-otp.php            |
              |  -> session: print_shop_id +         |
              |     operator_id + operator_name      |
              +-------------------+------------------+
                                  |
                                  v
                +-----------------+-----------------+
                |  printshop/dashboard.php          |
                |  + new "Clients" tab when         |
                |    is_internal_provider = 1       |
                +-----------------+-----------------+
                                  |
                                  v
   printshop/clients.php  -->  printshop/client.php?company=<id>
                                  |
                                  v
                printshop/order-on-behalf.php?employee=<id>
                                  |
                                  v
              PrintShopIntegration::createOrder($orderData)
                  (already accepts company_id; per-client
                   pricing override + min_quantity gate live)
                                  |
                                  v
        printshop/orders.php row -> "Download print sheet"
                                  |
                                  v
                    api/print-ready.php (rows=5 cols=2)
                                  |
                                  v
                  scripts/imposition-vector.py (existing)
                                  |
                                  v
                    Single PDF: page 1 fronts, page 2 backs
```

## Components

### 1. `print_shop_operators` table (migration 106)

```sql
CREATE TABLE print_shop_operators (
    id            VARCHAR(36) PRIMARY KEY,
    print_shop_id INT NOT NULL,
    name          VARCHAR(120) NOT NULL,
    phone         VARCHAR(32)  NULL,
    email         VARCHAR(190) NULL,
    status        ENUM('active','disabled') NOT NULL DEFAULT 'active',
    last_login_at TIMESTAMP NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_phone_per_shop (print_shop_id, phone),
    UNIQUE KEY uniq_email_per_shop (print_shop_id, email),
    KEY idx_phone (phone),
    KEY idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Either `phone` or `email` must be set (not both required). Login by phone uses WhatsApp OTP; login by email uses email OTP. Both via `OtpService::send($identifier, $channel, 'printshop_login')`.

Seed at deploy:
- `(print_shop_id=2, name='Ali Al-Zaabi', phone='+96871616161')`

Arshad + Hussain added later through the operators admin page.

### 2. `print_shops.is_internal_provider` flag (migration 107)

```sql
ALTER TABLE print_shops ADD COLUMN is_internal_provider TINYINT(1) NOT NULL DEFAULT 0;
UPDATE print_shops SET is_internal_provider = 1 WHERE id = 2; -- BHD
```

When `1`, the operator's session unlocks the "Browse Clients" pages. When `0`, those pages return 403.

### 3. New / modified PHP files

| File | Role |
|---|---|
| `printshop/login.php` | Add a second tab "Sign in with phone/email" alongside the existing email+password form. |
| `printshop/request-otp.php` | POST handler. Looks up `print_shop_operators` by phone OR email, calls `OtpService::send`, returns JSON `{ok, channel, masked_destination}`. |
| `printshop/verify-otp.php` | POST handler. `OtpService::verify`, on success sets `$_SESSION['print_shop_id'] / operator_id / operator_name / auth_via='otp'`, redirects to dashboard. Sets a 30-day signed `pso_remember` cookie when "Remember me" checked. |
| `printshop/operators.php` | Admin page (any active operator can access). List, add, disable, edit operators. |
| `printshop/save-operator.php` | POST handler for the operators page. CSRF protected. |
| `printshop/clients.php` | Paginated list of all `companies` rows. Search by name + slug. Shows employee count + last order date. Gated by `is_internal_provider`. |
| `printshop/client.php` | One company. Employee grid with cardFront thumbnail. "Order print" + "Generate sheet" buttons per employee. Gated. |
| `printshop/order-on-behalf.php` | Mirror of `admin/order_print.php`, but the company_id comes from query string and the operator is a print-shop user, not a company admin. Uses the same Alpine + price calculator. Server side calls `PrintShopIntegration::createOrder($orderData)` with the explicit company_id. Gated. |
| `printshop/print-sheet.php` | GET handler. Wraps `api/print-ready.php` output (rows=5 cols=2) for a given employee_id, streams the resulting PDF inline. Gated. Available from both order-on-behalf flow AND the orders list as a per-row button. |
| `includes/PrintShopOperator.php` | New class, mirrors `PrintShop` static method pattern: `getById`, `getByPhone`, `getByEmail`, `listForShop`, `create`, `update`, `disable`. |
| `includes/Auth.php` | Add `Auth::loginAsPrintShopOperator($operatorRow, $printShopRow, $authVia)` that sets the session keys consistently. Existing email+password login keeps working unchanged. |

### 4. Auth + permission helper

`includes/PrintShopAuth.php` (new):

```php
PrintShopAuth::requireLogin();          // redirects to printshop/login.php on miss
PrintShopAuth::requireInternalProvider(); // 403 when shop.is_internal_provider != 1
PrintShopAuth::currentOperator();         // ['id', 'name', 'shop_id', ...]
```

Every new printshop page calls `requireLogin` at top; the three "Browse Clients" pages additionally call `requireInternalProvider`.

### 5. Audit logging + order attribution (migration 108)

```sql
ALTER TABLE audit_log    ADD COLUMN actor_print_shop_operator_id VARCHAR(36) NULL;
ALTER TABLE print_orders ADD COLUMN placed_by_operator_id        VARCHAR(36) NULL;
CREATE INDEX idx_pord_placed_by ON print_orders (placed_by_operator_id);
```

`AuditLog::log` gets the optional `actor_print_shop_operator_id` arg so existing call sites stay backward compatible. `PrintShopIntegration::createOrder` writes `placed_by_operator_id` from `$_SESSION['operator_id']` when present. The orders list grows a "Placed by" column when viewed by an internal-provider shop.

### 6. Nav

`printshop/dashboard.php` and the chrome on every printshop page get a "Browse Clients" link, conditionally rendered based on `is_internal_provider`. Lang strings added for `printshopinternal.nav_clients`, `printshopinternal.heading`, etc., in `lang/en/printshopinternal.php` + `lang/ar/printshopinternal.php`.

### 7. A4 sheet

No new logic. `api/print-ready.php` already produces the imposition. New thin wrapper `printshop/print-sheet.php`:

1. Validates operator session + shop access to the requested employee (always allowed when `is_internal_provider=1`).
2. POST internally to the existing print-ready endpoint with `rows=5 cols=2 employee_id=X` (or call its function directly to skip the HTTP hop).
3. Streams the resulting PDF with a sensible filename: `BHD-print-sheet-<employee-slug>-A4-10up.pdf`.

The button on each `printshop/orders.php` row + the `order-on-behalf` confirmation page links to this URL.

## Data Flow

### Login (phone path)
1. Operator visits `printshop/login.php`, picks "Sign in with phone".
2. Enters `+968 7161 6161` (or `71616161`, normalised server-side).
3. POST `printshop/request-otp.php` -> looks up active operator -> `OtpService::send($phone, 'whatsapp', 'printshop_login')` -> 6-digit code via Dardasha to that number -> response `{ok:true, channel:'whatsapp', masked:'+968 *** *** 6161'}`.
4. Enters code -> POST `printshop/verify-otp.php` -> `OtpService::verify` -> `Auth::loginAsPrintShopOperator(...)` -> redirect to dashboard.
5. `last_login_at` updated; AuditLog records `printshop_operator.login`.

### Order on behalf
1. Operator clicks "Browse Clients" -> picks Otech.
2. `printshop/client.php?company=<id>` shows Otech employees. Operator clicks "Order print" on Muhammed Ali.
3. `printshop/order-on-behalf.php?employee=<emp_id>` renders the same form as the customer-side, prefilled with the employee + Otech as company. Per-client override (matte 0.030 OMR/card etc.) + min-qty 200 are applied automatically because the price calculator already accepts company_id.
4. Operator hits Place Order -> `PrintShopIntegration::createOrder` with `company_id`, `employee_id`, `print_shop_id`, `quantity`, `paper_type`, `finish`, `placed_by_operator_id`.
5. Same Quote -> Invoice -> Payment chain triggers in BHD-ERP via existing `ERPSync`.
6. Confirmation page offers "Download A4 print sheet" -> `printshop/print-sheet.php?employee=<id>`.

## Error Handling

- Phone not registered -> `printshop/request-otp.php` returns `{ok:false, error:'unknown_operator'}`. UI shows "This number is not registered as a BHD operator. Ask an admin to add you."
- OTP rate limit (3/hour per identifier, 10/day per IP) is already enforced by `OtpService`. UI surfaces `rate_limited_identifier` / `rate_limited_ip` translated.
- Disabled operator -> request-otp rejects with `operator_disabled`.
- Missing `is_internal_provider` -> 403 with friendly "This area is only available to internal-provider print shops."
- Order failure (min-quantity) -> existing flash banner (already shipped in the previous feature).
- Imposition generation timeout -> `print-sheet.php` returns 503 with retry hint; mirrors the timeout handling already in `api/print-ready.php`.

## Security

- All POST handlers protect with `validateCSRFToken()`.
- New `includes/Phone.php` helper with `Phone::normalize($input, $defaultCountry='OM')`. Strips spaces, dashes, parens; replaces leading `00` with `+`; if no `+`, prepends `+968` for 8-digit Omani numbers. Stored as E.164 (`+96871616161`).
- Session fixation: rotate session id on successful OTP verify.
- The `pso_remember` cookie is HMAC-signed with `APP_SECRET`, scoped to the `Path=/printshop/`, `HttpOnly`, `Secure`, `SameSite=Lax`. On reuse, server validates HMAC + ttl + operator status + matches a per-operator token salt; cookie is rotated each use.
- Browse-clients pages validate `print_shop_id` from session against the operator row on each request to prevent cross-shop privilege escalation if someone tampers with the session.
- Audit log entries are append-only; deletes are blocked at the model.

## Testing

- Unit tests for `PrintShopOperator` (CRUD, phone uniqueness scoped to shop).
- Unit tests for `PrintShopAuth::requireLogin / requireInternalProvider`.
- Integration test for the OTP flow happy path + rate-limit path, using mocked WhatsApp.
- Playwright e2e: `tests/e2e/printshop-phone-login.spec.ts` (request OTP, verify, dashboard loads).
- Playwright e2e: `tests/e2e/printshop-order-on-behalf.spec.ts` (login as Ali, browse to Otech, order on behalf with min-qty 200, download print sheet).
- Manual QA: phone normalisation handles `71616161`, `+968 7161 6161`, `0096871616161`, `968-7161-6161`. All hash to the same `+96871616161`.

## Migration / Rollout

1. Migrations 106 + 107 + 108 (operators table, internal-provider flag, audit-log column).
2. Seed Ali as the first operator for shop_id=2.
3. Set `is_internal_provider=1` on shop_id=2.
4. Deploy via `/usr/local/bin/deploy-cardify.sh` per project SOP.
5. Smoke test: Ali receives OTP on his number, signs in, browses clients, places a test order on Otech, downloads the print sheet.
6. Add Arshad + Hussain via the new operators page once their numbers are in.

Rollback: drop the two new tables; the flag column is harmless when 0; the existing email+password login remains untouched throughout.

## Open Questions / Decided

- **Tiered roles?** No. Flat. (decided)
- **OTP channel?** Phone or email, operator picks at login. Both already supported by `OtpService`. (decided)
- **A4 layout?** 5x2, 10 copies of one card. Existing engine output. v2 may add a multi-employee gang sheet. (decided)
- **Seed phones?** Ali only at deploy. Arshad / Hussain added through the operators admin page when their numbers are available. (decided)
