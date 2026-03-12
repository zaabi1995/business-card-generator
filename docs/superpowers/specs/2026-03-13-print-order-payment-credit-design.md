# Print Order Payment & Credit System — Design Spec

## Goal

Add payment collection for print orders and a credit account system to Cardify.om. Companies can pay instantly via Paymob or charge to a credit account managed by their print shop. Purchase Orders can be attached to any order.

## Context

- Cardify is a multi-tenant SaaS platform — print shops run their own business
- All payments flow through Cardify's single Paymob merchant account (future: split payments)
- Paymob endpoint: `oman.paymob.com` (Oman regional)
- Integration IDs: 48380 (Card), 48381 (OmanNet), 48389 (Apple Pay)
- Currency: OMR (3 decimal places, ×1000 for smallest unit)
- Existing `print_orders` table already has PO, quotation, invoice, and delivery note columns (unused)
- Existing `Billing.php` handles subscriptions only
- Existing `payment_transactions` table tracks subscription payments only

## Architecture

### Three New Classes

| Class | File | Responsibility |
|-------|------|----------------|
| `Payment` | `includes/Payment.php` | All Paymob transactions — subscriptions AND print orders. Single entry point for creating payment intents, handling callbacks, tracking status. |
| `CreditManager` | `includes/CreditManager.php` | Credit accounts per company-per-printshop. Print shop sets limits, terms, approves requests. Tracks balance, generates ledger. |
| `PrintShopBilling` | `includes/PrintShopBilling.php` | Bridges print orders with payments. Checkout flow: pay now (Paymob) or charge to credit. PO upload. |

### Existing Classes — Changes

| Class | Change |
|-------|--------|
| `Billing.php` | Remove Paymob intent/callback code. Delegate to `Payment.php`. Keep plan management, usage tracking, feature gates. |
| `PrintShopIntegration.php` | After `createOrder()`, call `PrintShopBilling` for payment flow instead of proceeding directly. |
| `paymob/callback.php` | Route callbacks through `Payment::handleCallback()` which dispatches to subscription or order handler. |

### Flow Diagram

```
Company places print order
  → Order created in print_orders (status: pending, payment_status: pending)
  → PrintShopBilling::checkout($orderId)
    → Has approved credit account with sufficient balance?
      → YES: Show options — "Pay Now" or "Charge to Credit"
      → NO: "Pay Now" only (+ option to "Request Credit" for future orders)

Pay Now:
  → Payment::createIntent('print_order', $orderId, $amount, ...)
  → Redirect to Paymob checkout
  → Callback → Payment::handleCallback()
    → Updates payment record + print_orders.payment_status = 'paid'
    → Order status → 'confirmed' (must add 'confirmed' to PrintShopIntegration::updateOrderStatus() $validStatuses)
    → Print shop notified

Charge to Credit:
  → CreditManager::charge($creditAccountId, $orderId, $amount)
  → Deducts from available balance
  → Creates credit_transaction record
  → Order proceeds (payment_status = 'paid', payment_method = 'credit')
  → Print shop notified

Request Credit:
  → CreditManager::requestCredit($companyId, $printShopId, $requestedLimit)
  → Creates credit_accounts record (status: pending)
  → Print shop gets notification
  → Print shop approves/rejects with limit and terms
```

## Database Schema

### New Tables

#### `payments`

Unified payment record for ALL Paymob transactions (subscriptions + print orders).

```sql
CREATE TABLE payments (
    id VARCHAR(36) PRIMARY KEY,
    company_id VARCHAR(36) NOT NULL,
    type ENUM('subscription', 'print_order') NOT NULL,
    reference_id VARCHAR(36) NOT NULL COMMENT 'plan_id for subscriptions, order_id for print orders',
    amount DECIMAL(10,3) NOT NULL,
    currency VARCHAR(3) DEFAULT 'OMR',

    -- Paymob fields
    paymob_intention_id VARCHAR(255) NULL,
    paymob_order_id VARCHAR(255) NULL,
    paymob_transaction_id VARCHAR(255) NULL,
    special_reference VARCHAR(255) NULL,
    payment_method VARCHAR(50) NULL COMMENT 'card, omannet, apple_pay',

    status ENUM('pending', 'paid', 'failed', 'refunded') DEFAULT 'pending',
    callback_data JSON NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_company (company_id),
    INDEX idx_type_ref (type, reference_id),
    INDEX idx_status (status),
    INDEX idx_special_ref (special_reference),
    INDEX idx_paymob_order (paymob_order_id)
);
```

#### `credit_accounts`

One per company-per-printshop. Print shop manages these.

```sql
CREATE TABLE credit_accounts (
    id VARCHAR(36) PRIMARY KEY,
    company_id VARCHAR(36) NOT NULL,
    print_shop_id INT NOT NULL,

    credit_limit DECIMAL(10,3) DEFAULT 0.000,
    balance_used DECIMAL(10,3) DEFAULT 0.000,
    payment_terms ENUM('net15', 'net30', 'net60', 'net90') DEFAULT 'net30',

    status ENUM('pending', 'approved', 'suspended', 'rejected') DEFAULT 'pending',
    requested_limit DECIMAL(10,3) NULL COMMENT 'What company asked for',
    request_notes TEXT NULL,

    approved_by VARCHAR(36) NULL,
    approved_at TIMESTAMP NULL,
    rejected_reason TEXT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_company_shop (company_id, print_shop_id),
    INDEX idx_print_shop (print_shop_id),
    INDEX idx_status (status)
);
```

#### `credit_transactions`

Ledger for all credit account activity.

```sql
CREATE TABLE credit_transactions (
    id VARCHAR(36) PRIMARY KEY,
    credit_account_id VARCHAR(36) NOT NULL,
    order_id INT NULL COMMENT 'print_orders.id if charge/refund',

    type ENUM('charge', 'payment', 'adjustment', 'refund') NOT NULL,
    amount DECIMAL(10,3) NOT NULL COMMENT 'Positive = increases balance_used',
    balance_after DECIMAL(10,3) NOT NULL,

    notes TEXT NULL,
    recorded_by VARCHAR(36) NULL COMMENT 'User who recorded (for manual payments)',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_account (credit_account_id),
    INDEX idx_order (order_id),
    INDEX idx_type (type)
);
```

### Modified Tables

#### `print_orders` — Add columns

```sql
ALTER TABLE print_orders
    ADD COLUMN payment_method ENUM('online', 'credit') NULL AFTER payment_status,
    ADD COLUMN payment_id VARCHAR(36) NULL AFTER payment_method;
```

The existing PO columns (`po_number`, `po_file_path`, `po_required`, `po_approved`, etc.) are already in the schema and will be activated.

**Legacy orders:** Existing print_orders rows will have `NULL` for both `payment_method` and `payment_id`. This is acceptable — `NULL` means "created before payment system" and all queries should handle this with `IS NULL` or `COALESCE`.

### Migration from `payment_transactions`

The existing `payment_transactions` table stays for backward compatibility. New subscription payments go to `payments` table. No data migration needed — old records remain in `payment_transactions`.

**Field mapping (old → new):**
- `payment_transactions.plan_id` → `payments.reference_id` (type='subscription')
- `payment_transactions.payment_gateway` → not needed (Payment is Paymob-only)
- `payment_transactions.gateway_response` → `payments.callback_data`
- `payment_transactions.transaction_id` → `payments.paymob_transaction_id`
- `payment_transactions.status = 'completed'` → `payments.status = 'paid'`

### Existing Codebase Notes

- `PrintShopIntegration::updateOrderStatus()` has a `$validStatuses` array — must add `'confirmed'` to it
- The `/printshop/` directory already exists with `dashboard.php`, `orders.php`, `settings.php`. New pages follow the same auth pattern: print shop user authenticated via `Auth::requireRole('printshop')` or session-based shop ownership check. Layout uses the same `printshop-layout.php` includes.
- `PrintShopIntegration::ensureHighQualityForOrder()` references a `require_hq` column not in migration 027 — pre-existing issue, not addressed in this spec

## Class Design

### Payment.php

**Config access:** `Payment` reads Paymob credentials from PHP constants defined in `config.php` (`PAYMOB_SECRET_KEY`, `PAYMOB_PUBLIC_KEY`, `PAYMOB_HMAC_SECRET`, `PAYMOB_INTEGRATION_IDS`). This matches how other classes like `DatabaseAdapter` access config — via constants, not constructor injection. The existing `Billing` class uses constructor injection, but that pattern is only needed because `Billing` supports multiple gateways. `Payment` is Paymob-only.

```php
class Payment {
    // Create Paymob payment intent (subscriptions or print orders)
    // Reads PAYMOB_SECRET_KEY, PAYMOB_PUBLIC_KEY, PAYMOB_INTEGRATION_IDS from config.php constants
    public static function createIntent(
        string $type,           // 'subscription' or 'print_order'
        string $referenceId,    // plan_id or order_id
        float $amount,
        string $companyId,
        array $billingData,
        string $currency = 'OMR'
    ): array
    // Returns: ['payment_id' => ..., 'checkout_url' => ...]

    // Handle Paymob callback (GET redirect + POST webhook)
    // Reads PAYMOB_HMAC_SECRET from config.php
    public static function handleCallback(array $data, string $hmac): array
    // Returns: ['success' => bool, 'payment_id' => ..., 'type' => ...]
    // On success: updates payment record, dispatches to type-specific handler

    // Get payment by ID
    public static function getById(string $paymentId): ?array

    // Get payments for a company
    public static function getByCompany(string $companyId, ?string $type = null): array

    // Get payment by special reference (for callback matching)
    public static function getBySpecialReference(string $ref): ?array

    // HMAC verification (moved from Billing.php)
    public static function verifyHmac(array $data, string $secret): bool

    // Amount conversion (moved from Billing.php)
    public static function toSmallestUnit(float $amount, string $currency): int
}
```

**Special reference format:**
- Subscriptions: `SUB_{companyId}_{timestamp}`
- Print orders: `PO_{orderId}_{timestamp}`

**Callback dispatch:**
- Type = `subscription` → update company plan + subscription_expires_at (same logic as current Billing.php)
- Type = `print_order` → update print_orders.payment_status = 'paid', set payment_method = 'online', trigger order confirmation

**Status mapping:** The new `payments` table uses `'paid'` (not `'completed'` as in the old `payment_transactions` table). All subscription activation logic moved to `Payment::handleCallback()` must check for `'paid'`, not `'completed'`. The old `payment_transactions` table and its `'completed'` status are untouched.

### CreditManager.php

```php
class CreditManager {
    // Company requests credit from a print shop
    public static function requestCredit(
        string $companyId,
        int $printShopId,
        float $requestedLimit,
        ?string $notes = null
    ): array
    // Returns: ['credit_account_id' => ..., 'status' => 'pending']

    // Print shop approves credit request
    public static function approve(
        string $creditAccountId,
        float $approvedLimit,
        string $paymentTerms,
        string $approvedBy
    ): bool

    // Print shop rejects credit request
    public static function reject(
        string $creditAccountId,
        string $reason,
        string $rejectedBy
    ): bool

    // Print shop suspends credit account
    public static function suspend(string $creditAccountId): bool

    // Charge an order to credit
    // MUST wrap in DB transaction: beginTransaction() → SELECT ... FOR UPDATE on credit_accounts → check balance → UPDATE → INSERT credit_transaction → commit()
    public static function charge(
        string $creditAccountId,
        int $orderId,
        float $amount
    ): array
    // Returns: ['transaction_id' => ..., 'balance_after' => ...]
    // Fails if insufficient available credit

    // Record payment received (print shop marks company paid)
    public static function recordPayment(
        string $creditAccountId,
        float $amount,
        ?string $notes = null,
        ?string $recordedBy = null
    ): array

    // Refund a charge (order cancelled)
    public static function refund(
        string $creditAccountId,
        int $orderId,
        float $amount
    ): array

    // Get credit account for company+shop
    public static function getAccount(string $companyId, int $printShopId): ?array

    // Get all credit accounts for a print shop
    public static function getShopAccounts(int $printShopId, ?string $status = null): array

    // Get transaction ledger for an account
    public static function getLedger(string $creditAccountId, int $limit = 50): array

    // Get available credit (limit - used)
    public static function getAvailable(string $creditAccountId): float

    // Get outstanding balance summary for print shop
    public static function getOutstandingSummary(int $printShopId): array
}
```

### PrintShopBilling.php

```php
class PrintShopBilling {
    // Get checkout options for an order
    public static function getCheckoutOptions(int $orderId): array
    // Returns: [
    //   'order' => [...],
    //   'can_pay_online' => true,
    //   'can_use_credit' => bool,
    //   'credit_account' => [...] or null,
    //   'available_credit' => float,
    //   'can_request_credit' => bool
    // ]

    // Process online payment for order
    public static function payOnline(int $orderId, array $billingData): array
    // Returns: ['checkout_url' => ...]

    // Charge order to credit account
    public static function chargeToCredit(int $orderId): array
    // Returns: ['success' => bool, 'transaction_id' => ...]

    // Upload Purchase Order for an order
    public static function uploadPO(int $orderId, array $file, ?string $poNumber = null): array
    // Returns: ['po_file_path' => ..., 'po_number' => ...]
    // Saves to uploads/purchase_orders/{company_id}/

    // Get payment summary for an order
    public static function getOrderPaymentInfo(int $orderId): array
}
```

## UI Pages

### Company Side (under `/admin/`)

#### `admin/order-checkout.php` — Order Payment Page

After placing a print order, company lands here to pay.

- Shows order summary (quantity, paper, finish, pricing breakdown)
- **Pay Now** button → Paymob checkout redirect
- **Charge to Credit** button → only if approved credit account with sufficient balance
- **Request Credit** link → modal to request credit from this print shop
- **Upload PO** — file upload (PDF/image, max 5MB) + optional PO number field
- PO upload available regardless of payment method

#### `admin/credit-accounts.php` — Company's Credit Accounts

- List of all credit accounts across print shops
- Status (pending/approved/suspended)
- Available balance, limit, terms
- Transaction history per account
- Request new credit account

### Print Shop Side (under `/printshop/`)

#### `printshop/credit-accounts.php` — Manage Credit Accounts

- List all company credit accounts for this shop
- Pending requests with approve/reject actions
- Set credit limit and payment terms when approving
- Suspend/reactivate accounts
- Quick stats: total outstanding, overdue amounts

#### `printshop/credit-ledger.php` — Credit Ledger

- Transaction history per credit account
- Filter by date range, type (charge/payment/adjustment)
- Record manual payment received (bank transfer, cash, cheque)
- Outstanding balance per company
- Export (future)

### Shared

#### Updated `paymob/callback.php`

- Routes through `Payment::handleCallback()`
- Handles both subscription and print order callbacks
- Redirect URL includes payment type for correct return page

## Subscription Payment Migration

### Changes to `Billing.php`

1. Remove `createPaymobPaymentIntent()` — replaced by `Payment::createIntent('subscription', ...)`
2. Remove `handlePaymobCallback()` — replaced by `Payment::handleCallback()`
3. Remove `computePaymobHmac()` — moved to `Payment::verifyHmac()`
4. Remove `toSmallestUnit()` — moved to `Payment::toSmallestUnit()`
5. Keep: `createSubscription()` but have it call `Payment::createIntent()` internally
6. Keep: all plan management, limits, feature gates unchanged

### Changes to `admin/billing.php`

- Update `createSubscription` call to use new flow
- Payment success/error redirects stay the same

## PO Workflow

Purchase Orders are simple document attachments — no formal approval workflow beyond what already exists in the `print_orders` table:

1. Company uploads PO (PDF/image) during checkout or after
2. PO stored at `uploads/purchase_orders/{company_id}/{order_id}_{timestamp}.{ext}`
3. PO number saved to `print_orders.po_number`
4. PO file path saved to `print_orders.po_file_path`
5. Print shop can view/download PO from their order dashboard
6. Existing `po_approved` fields available if print shop wants to track acceptance

## Notifications

### Email (via existing Mailer)

- **Credit request submitted** → print shop email
- **Credit approved/rejected** → company admin email
- **Order paid (online)** → print shop + company confirmation
- **Order charged to credit** → print shop + company confirmation
- **Payment received on credit** → company confirmation

### WhatsApp (via existing Dardasha integration)

- Same notifications as email, sent via WhatsApp if print shop has WhatsApp enabled

## Security

- CSRF tokens on all forms
- File upload validation: PDF/JPG/PNG only, max 5MB, sanitized filenames
- Credit operations verify company owns the credit account
- Print shop operations verify shop ownership via `user_id`
- Payment amounts validated server-side (never trust client)
- HMAC verification on all Paymob callbacks
- SQL injection prevention via DatabaseAdapter named parameters

## Error Handling

- Insufficient credit → clear error message, offer "Pay Now" alternative
- Paymob failure → order stays pending, company can retry
- PO upload failure → order proceeds without PO, can upload later
- Credit request while one pending → show existing request status
- Concurrent charge race condition → use DB transaction with `SELECT ... FOR UPDATE` on credit_accounts

## Future Considerations (Not in this spec)

- **Split payments**: When Cardify adds Paymob split, only `Payment::createIntent()` changes
- **Auto-invoicing**: Generate PDF invoices from credit charges
- **Credit aging/overdue reports**: Report on overdue credit balances
- **Statement emails**: Monthly credit statements to companies
- **Multi-currency**: Print shops in different countries
