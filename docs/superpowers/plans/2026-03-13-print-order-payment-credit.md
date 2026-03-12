# Print Order Payment & Credit System — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add payment collection for print orders (via Paymob) and a credit account system (managed by print shops) to Cardify.om.

**Architecture:** Three new classes — `Payment.php` (unified Paymob handler), `CreditManager.php` (credit accounts & ledger), `PrintShopBilling.php` (order checkout bridge). Refactor `Billing.php` to delegate Paymob calls to `Payment.php`.

**Tech Stack:** PHP 7.4+ (no framework), MySQL, Paymob Unified Checkout (oman.paymob.com), Tailwind CSS

**Spec:** `docs/superpowers/specs/2026-03-13-print-order-payment-credit-design.md`

---

## CRITICAL: Database API & Include Patterns

**All code in this plan must use these patterns.** The code samples below show the conceptual structure — adapt all DB calls to match:

### Database Class API (`Database::getInstance()`)
```php
$db = Database::getInstance();

// Read queries
$rows = $db->fetchAll($sql, $params);    // Returns array of assoc arrays
$row = $db->fetchOne($sql, $params);     // Returns single assoc array or false
$stmt = $db->query($sql, $params);       // Returns PDOStatement (for writes needing rowCount)

// Write helpers
$id = $db->insert('table', ['col' => 'val']);  // Returns lastInsertId
$count = $db->update('table', $data, 'id = :id', [':id' => $val]);  // Returns rowCount
$count = $db->delete('table', 'id = :id', [':id' => $val]);  // Returns rowCount

// Transactions (methods on $db directly, NOT on PDO)
$db->beginTransaction();
$db->commit();
$db->rollback();

// Raw DDL
$db->exec("CREATE TABLE ...");
```

**DO NOT USE:** `DatabaseAdapter::getInstance()`, `->execute()`, `->getPdo()`, `->getConnection()`

### Include Pattern (admin pages)
```php
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';  // Provides layout helpers
// For printshop pages:
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/PrintShop.php';
```

**DO NOT USE:** `include __DIR__ . '/includes/admin-header.php'` (doesn't exist)

### Transaction Pattern (for CreditManager::charge)
```php
$db = Database::getInstance();
$db->beginTransaction();
try {
    $conn = $db->getConnection();
    $stmt = $conn->prepare("SELECT * FROM credit_accounts WHERE id = :id FOR UPDATE");
    $stmt->execute([':id' => $id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    // ... check balance, update ...
    $db->commit();
} catch (Exception $e) {
    $db->rollback();
    throw $e;
}
```

### Migration Pattern
```php
<?php
require_once __DIR__ . '/../../config.php';
$db = Database::getInstance();
$db->exec("CREATE TABLE IF NOT EXISTS ...");
echo "Migration done\n";
```

---

## File Structure

### New Files
| File | Responsibility |
|------|----------------|
| `includes/Payment.php` | Unified Paymob payment intent creation, callback handling, HMAC verification |
| `includes/CreditManager.php` | Credit account CRUD, charge/payment/refund ledger, balance tracking |
| `includes/PrintShopBilling.php` | Order checkout options, online pay, credit charge, PO upload |
| `database/migrations/030_payments_table.php` | Create `payments` table |
| `database/migrations/031_credit_tables.php` | Create `credit_accounts` + `credit_transactions` tables |
| `database/migrations/032_print_orders_payment_columns.php` | Add `payment_method`, `payment_id` to `print_orders` |
| `admin/order-checkout.php` | Company-side order payment page |
| `admin/credit-accounts.php` | Company-side credit account list |
| `printshop/credit-accounts.php` | Print shop credit management |
| `printshop/credit-ledger.php` | Print shop credit transaction ledger |

### Modified Files
| File | Change |
|------|--------|
| `includes/Billing.php` | Remove Paymob methods, delegate to `Payment.php` |
| `includes/PrintShopIntegration.php` | Add 'confirmed' to valid statuses (line 523) |
| `paymob/callback.php` | Route through `Payment::handleCallback()` |
| `admin/billing.php` | Update subscription flow to use `Payment.php` |

---

## Chunk 1: Database Migrations & Payment.php

### Task 1: Database Migrations

**Files:**
- Create: `database/migrations/030_payments_table.php`
- Create: `database/migrations/031_credit_tables.php`
- Create: `database/migrations/032_print_orders_payment_columns.php`

- [ ] **Step 1: Create migration 030 — payments table**

```php
<?php
// database/migrations/030_payments_table.php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Database.php';

try {
    $db = DatabaseAdapter::getInstance();

    $db->exec("CREATE TABLE IF NOT EXISTS payments (
        id VARCHAR(36) PRIMARY KEY,
        company_id VARCHAR(36) NOT NULL,
        type ENUM('subscription', 'print_order') NOT NULL,
        reference_id VARCHAR(36) NOT NULL COMMENT 'plan_id for subscriptions, order_id for print orders',
        amount DECIMAL(10,3) NOT NULL,
        currency VARCHAR(3) DEFAULT 'OMR',

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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    echo "Migration 030: payments table created successfully\n";
} catch (Exception $e) {
    echo "Migration 030 failed: " . $e->getMessage() . "\n";
    exit(1);
}
```

- [ ] **Step 2: Create migration 031 — credit tables**

```php
<?php
// database/migrations/031_credit_tables.php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Database.php';

try {
    $db = DatabaseAdapter::getInstance();

    $db->exec("CREATE TABLE IF NOT EXISTS credit_accounts (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS credit_transactions (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    echo "Migration 031: credit_accounts and credit_transactions tables created successfully\n";
} catch (Exception $e) {
    echo "Migration 031 failed: " . $e->getMessage() . "\n";
    exit(1);
}
```

- [ ] **Step 3: Create migration 032 — print_orders payment columns**

```php
<?php
// database/migrations/032_print_orders_payment_columns.php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Database.php';

try {
    $db = DatabaseAdapter::getInstance();

    // Check if columns already exist before adding
    $columns = $db->query("SHOW COLUMNS FROM print_orders LIKE 'payment_method'");
    if (empty($columns)) {
        $db->exec("ALTER TABLE print_orders
            ADD COLUMN payment_method ENUM('online', 'credit') NULL AFTER payment_status,
            ADD COLUMN payment_id VARCHAR(36) NULL AFTER payment_method");
    }

    echo "Migration 032: print_orders payment columns added successfully\n";
} catch (Exception $e) {
    echo "Migration 032 failed: " . $e->getMessage() . "\n";
    exit(1);
}
```

- [ ] **Step 4: Run migrations on VPS**

```bash
ssh root@147.93.20.54 "cd /www/wwwroot/cardify.om && php database/migrations/030_payments_table.php && php database/migrations/031_credit_tables.php && php database/migrations/032_print_orders_payment_columns.php"
```

Expected: All 3 migrations succeed.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/030_payments_table.php database/migrations/031_credit_tables.php database/migrations/032_print_orders_payment_columns.php
git commit -m "feat: add payment, credit_accounts, credit_transactions tables and print_orders columns"
```

### Task 2: Payment.php — Unified Paymob Handler

**Files:**
- Create: `includes/Payment.php`
- Reference: `includes/Billing.php` (lines 89-95 for toSmallestUnit, lines 101-200 for createPaymobPaymentIntent, lines 301-440 for handlePaymobCallback, lines 451-476 for computePaymobHmac)

- [ ] **Step 1: Create Payment.php with toSmallestUnit and verifyHmac**

Extract from `Billing.php` lines 89-95 and 451-476. These are pure utility methods with no dependencies.

```php
<?php
// includes/Payment.php
require_once __DIR__ . '/Database.php';

class Payment {
    /**
     * Convert amount to smallest currency unit
     * OMR/BHD/KWD = 3 decimals (×1000), others = 2 decimals (×100)
     */
    public static function toSmallestUnit(float $amount, string $currency = 'OMR'): int {
        $threeDecimalCurrencies = ['OMR', 'BHD', 'KWD'];
        if (in_array(strtoupper($currency), $threeDecimalCurrencies)) {
            return (int) round($amount * 1000);
        }
        return (int) round($amount * 100);
    }

    /**
     * Compute Paymob HMAC-SHA512 signature
     * Fields concatenated in Paymob's required order
     */
    public static function computeHmac(array $data, string $secret): string {
        $fields = [
            'amount_cents', 'created_at', 'currency', 'error_occured',
            'has_parent_transaction', 'id', 'integration_id',
            'is_3d_secure', 'is_auth', 'is_capture', 'is_refunded',
            'is_standalone_payment', 'is_voided', 'order',
            'owner', 'pending', 'source_data_pan', 'source_data_sub_type',
            'source_data_type', 'success'
        ];

        $concatenated = '';
        foreach ($fields as $field) {
            $value = $data[$field] ?? '';
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            $concatenated .= $value;
        }

        return hash_hmac('sha512', $concatenated, $secret);
    }

    /**
     * Verify HMAC signature from Paymob callback
     */
    public static function verifyHmac(array $data, string $hmac): bool {
        $computed = self::computeHmac($data, PAYMOB_HMAC_SECRET);
        return hash_equals($computed, $hmac);
    }
}
```

- [ ] **Step 2: Test toSmallestUnit manually**

```bash
ssh root@147.93.20.54 "cd /www/wwwroot/cardify.om && php -r \"
require 'config.php';
require 'includes/Payment.php';
echo Payment::toSmallestUnit(5.123, 'OMR') . '\n';  // expect 5123
echo Payment::toSmallestUnit(10.50, 'USD') . '\n';   // expect 1050
echo Payment::toSmallestUnit(0.500, 'OMR') . '\n';   // expect 500
\""
```

- [ ] **Step 3: Add createIntent method**

Extract and adapt from `Billing.php` lines 101-200. Key changes:
- Static method, reads config constants directly
- Accepts `$type` parameter for special_reference prefix (SUB_ or PO_)
- Stores payment record in new `payments` table (not `payment_transactions`)
- Returns `['payment_id' => ..., 'checkout_url' => ...]`

Add to `Payment.php`:

```php
    /**
     * Create Paymob payment intent
     * @param string $type 'subscription' or 'print_order'
     * @param string $referenceId plan_id or print order_id
     * @param float $amount in full currency units (e.g. 5.000 OMR)
     * @param string $companyId
     * @param array $billingData ['first_name', 'last_name', 'email', 'phone']
     * @param string $currency
     * @return array ['payment_id' => ..., 'checkout_url' => ...] or ['error' => ...]
     */
    public static function createIntent(
        string $type,
        string $referenceId,
        float $amount,
        string $companyId,
        array $billingData,
        string $currency = 'OMR'
    ): array {
        $secretKey = PAYMOB_SECRET_KEY;
        $publicKey = PAYMOB_PUBLIC_KEY;
        $integrationIds = array_map('intval', explode(',', PAYMOB_INTEGRATION_IDS));

        if (empty($secretKey) || empty($publicKey) || empty($integrationIds)) {
            return ['error' => 'Paymob configuration incomplete'];
        }

        $amountCents = self::toSmallestUnit($amount, $currency);
        $prefix = ($type === 'subscription') ? 'SUB' : 'PO';
        $specialReference = "{$prefix}_{$companyId}_{$referenceId}_" . time();

        // Create payment record
        $paymentId = generateUUID();
        $db = DatabaseAdapter::getInstance();
        $db->execute(
            "INSERT INTO payments (id, company_id, type, reference_id, amount, currency, special_reference, status, created_at)
             VALUES (:id, :company_id, :type, :reference_id, :amount, :currency, :special_reference, 'pending', NOW())",
            [
                ':id' => $paymentId,
                ':company_id' => $companyId,
                ':type' => $type,
                ':reference_id' => $referenceId,
                ':amount' => $amount,
                ':currency' => $currency,
                ':special_reference' => $specialReference
            ]
        );

        // Build Paymob intention payload
        $payload = [
            'amount' => $amountCents,
            'currency' => strtoupper($currency),
            'payment_methods' => $integrationIds,
            'billing_data' => [
                'first_name' => $billingData['first_name'] ?? 'Customer',
                'last_name' => $billingData['last_name'] ?? '',
                'email' => $billingData['email'] ?? 'customer@cardify.om',
                'phone_number' => $billingData['phone'] ?? '+96800000000',
                'apartment' => 'N/A',
                'floor' => 'N/A',
                'street' => 'N/A',
                'building' => 'N/A',
                'shipping_method' => 'N/A',
                'postal_code' => 'N/A',
                'city' => 'N/A',
                'country' => 'OM',
                'state' => 'N/A'
            ],
            'special_reference' => $specialReference,
            'notification_url' => rtrim(SITE_URL, '/') . '/paymob/callback.php',
            'redirection_url' => rtrim(SITE_URL, '/') . '/paymob/callback.php'
        ];

        // Call Paymob Intention API
        $ch = curl_init('https://oman.paymob.com/v1/intention/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Token ' . $secretKey,
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 30
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 && $httpCode !== 201) {
            // Mark payment as failed
            $db->execute("UPDATE payments SET status = 'failed', callback_data = :data WHERE id = :id",
                [':data' => $response, ':id' => $paymentId]);
            return ['error' => 'Paymob API error: HTTP ' . $httpCode];
        }

        $result = json_decode($response, true);
        $clientSecret = $result['client_secret'] ?? null;

        if (!$clientSecret) {
            $db->execute("UPDATE payments SET status = 'failed', callback_data = :data WHERE id = :id",
                [':data' => $response, ':id' => $paymentId]);
            return ['error' => 'No client_secret in Paymob response'];
        }

        // Update payment with Paymob intention ID
        $intentionId = $result['id'] ?? null;
        $db->execute("UPDATE payments SET paymob_intention_id = :iid WHERE id = :id",
            [':iid' => $intentionId, ':id' => $paymentId]);

        $checkoutUrl = 'https://oman.paymob.com/unifiedcheckout/?publicKey=' . urlencode($publicKey) . '&clientSecret=' . urlencode($clientSecret);

        // Store in session for callback matching
        $_SESSION["paymob_payment_{$specialReference}"] = [
            'payment_id' => $paymentId,
            'type' => $type,
            'reference_id' => $referenceId,
            'company_id' => $companyId,
            'amount' => $amountCents,
            'currency' => $currency
        ];

        return [
            'payment_id' => $paymentId,
            'checkout_url' => $checkoutUrl,
            'special_reference' => $specialReference
        ];
    }
```

- [ ] **Step 4: Add handleCallback method**

Extract and adapt from `Billing.php` lines 301-440. Key changes:
- Looks up payment in `payments` table by special_reference
- Updates `payments` table (not `payment_transactions`)
- Dispatches based on `type`: subscription → update company plan, print_order → update order status

Add to `Payment.php`:

```php
    /**
     * Handle Paymob callback (both GET redirect and POST webhook)
     * @return array ['success' => bool, 'payment_id' => ..., 'type' => ...]
     */
    public static function handleCallback(array $data, ?string $hmac = null): array {
        $db = DatabaseAdapter::getInstance();

        // Flatten nested Paymob response (POST webhook has 'obj' wrapper)
        if (isset($data['obj'])) {
            $txData = $data['obj'];
        } else {
            $txData = $data;
        }

        // Extract key fields
        $success = filter_var($txData['success'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $transactionId = $txData['id'] ?? null;
        $orderId = $txData['order'] ?? ($txData['order_id'] ?? null);
        $merchantOrderId = $txData['merchant_order_id'] ?? ($data['merchant_order_id'] ?? null);
        $amountCents = $txData['amount_cents'] ?? ($data['amount_cents'] ?? 0);
        $currency = $txData['currency'] ?? ($data['currency'] ?? 'OMR');

        // Also try special_reference from data
        $specialRef = $merchantOrderId ?? ($data['special_reference'] ?? null);

        // Verify HMAC if provided
        if ($hmac && !empty(PAYMOB_HMAC_SECRET)) {
            if (!self::verifyHmac($txData, $hmac)) {
                return ['success' => false, 'error' => 'HMAC verification failed'];
            }
        }

        // Look up payment record
        $payment = null;
        if ($specialRef) {
            $payment = self::getBySpecialReference($specialRef);
        }

        // Fallback to session
        if (!$payment && $specialRef && isset($_SESSION["paymob_payment_{$specialRef}"])) {
            $sessionData = $_SESSION["paymob_payment_{$specialRef}"];
            $payment = self::getById($sessionData['payment_id']);
        }

        if (!$payment) {
            return ['success' => false, 'error' => 'Payment record not found'];
        }

        // Verify amount matches
        $expectedAmount = self::toSmallestUnit($payment['amount'], $payment['currency']);
        if ((int)$amountCents !== $expectedAmount) {
            return ['success' => false, 'error' => 'Amount mismatch'];
        }

        // Determine status
        $status = $success ? 'paid' : 'failed';
        $paymentMethod = $txData['source_data_type'] ?? null;

        // Update payment record
        $db->execute(
            "UPDATE payments SET status = :status, paymob_order_id = :oid, paymob_transaction_id = :tid,
             payment_method = :pm, callback_data = :cd, updated_at = NOW()
             WHERE id = :id",
            [
                ':status' => $status,
                ':oid' => $orderId,
                ':tid' => $transactionId,
                ':pm' => $paymentMethod,
                ':cd' => json_encode($txData),
                ':id' => $payment['id']
            ]
        );

        // Clear session
        if ($specialRef) {
            unset($_SESSION["paymob_payment_{$specialRef}"]);
        }

        // Dispatch based on type
        if ($success) {
            if ($payment['type'] === 'subscription') {
                self::activateSubscription($payment);
            } elseif ($payment['type'] === 'print_order') {
                self::confirmPrintOrder($payment);
            }
        }

        return [
            'success' => $success,
            'payment_id' => $payment['id'],
            'type' => $payment['type'],
            'reference_id' => $payment['reference_id'],
            'status' => $status
        ];
    }

    /**
     * Activate subscription after successful payment
     * Extracted from Billing.php lines 422-438
     */
    private static function activateSubscription(array $payment): void {
        $db = DatabaseAdapter::getInstance();

        // reference_id is the plan_id
        $planId = $payment['reference_id'];
        $companyId = $payment['company_id'];

        // Determine expiry (default 1 month)
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 month'));

        $db->execute(
            "UPDATE companies SET plan = :plan, subscription_status = 'active',
             subscription_expires_at = :expires, subscription_id = :sub_id, updated_at = NOW()
             WHERE id = :id",
            [
                ':plan' => $planId,
                ':expires' => $expiresAt,
                ':sub_id' => $payment['id'],
                ':id' => $companyId
            ]
        );
    }

    /**
     * Confirm print order after successful payment
     */
    private static function confirmPrintOrder(array $payment): void {
        $db = DatabaseAdapter::getInstance();
        $orderId = $payment['reference_id'];

        $db->execute(
            "UPDATE print_orders SET payment_status = 'paid', payment_method = 'online',
             payment_id = :pid, status = 'confirmed'
             WHERE id = :id",
            [':pid' => $payment['id'], ':id' => $orderId]
        );

        // Send notifications (email + WhatsApp) via PrintShopIntegration
        try {
            require_once __DIR__ . '/PrintShopIntegration.php';
            PrintShopIntegration::sendStatusUpdateEmail($orderId, 'confirmed', null);
        } catch (Exception $e) {
            error_log("Payment notification failed for order {$orderId}: " . $e->getMessage());
        }
    }

    // --- Query methods ---

    public static function getById(string $paymentId): ?array {
        $db = DatabaseAdapter::getInstance();
        $rows = $db->query("SELECT * FROM payments WHERE id = :id", [':id' => $paymentId]);
        return $rows[0] ?? null;
    }

    public static function getByCompany(string $companyId, ?string $type = null): array {
        $db = DatabaseAdapter::getInstance();
        $sql = "SELECT * FROM payments WHERE company_id = :cid";
        $params = [':cid' => $companyId];
        if ($type) {
            $sql .= " AND type = :type";
            $params[':type'] = $type;
        }
        $sql .= " ORDER BY created_at DESC";
        return $db->query($sql, $params);
    }

    public static function getBySpecialReference(string $ref): ?array {
        $db = DatabaseAdapter::getInstance();
        $rows = $db->query("SELECT * FROM payments WHERE special_reference = :ref", [':ref' => $ref]);
        return $rows[0] ?? null;
    }
```

- [ ] **Step 5: Commit Payment.php**

```bash
git add includes/Payment.php
git commit -m "feat: add Payment.php — unified Paymob handler for subscriptions and print orders"
```

### Task 3: Refactor Billing.php & Callback

**Files:**
- Modify: `includes/Billing.php` (remove lines 89-95, 101-200, 301-440, 451-476)
- Modify: `paymob/callback.php` (route through Payment.php)
- Modify: `admin/billing.php` (update subscription flow)

- [ ] **Step 1: Update Billing.php — delegate to Payment.php**

In `Billing.php`, replace `createPaymobPaymentIntent()` body (around line 101) to delegate:

```php
// At top of file, add:
require_once __DIR__ . '/Payment.php';

// Replace createPaymobPaymentIntent() body with delegation:
private function createPaymobPaymentIntent($amount, $companyId, $planId, $billingCycle) {
    // Delegate to unified Payment class
    $company = DatabaseAdapter::getInstance()->query(
        "SELECT * FROM companies WHERE id = :id", [':id' => $companyId]
    );
    $company = $company[0] ?? [];
    $currency = $company['currency'] ?? 'OMR';

    $billingData = [
        'first_name' => $company['name'] ?? 'Customer',
        'last_name' => '',
        'email' => $company['billing_email'] ?? $company['admin_email'] ?? '',
        'phone' => ''
    ];

    $result = Payment::createIntent('subscription', $planId, $amount, $companyId, $billingData, $currency);

    if (isset($result['error'])) {
        return ['success' => false, 'error' => $result['error']];
    }

    return [
        'success' => true,
        'transaction_id' => $result['special_reference'],
        'payment_url' => $result['checkout_url'],
        'payment_data' => $result
    ];
}
```

Keep `handlePaymobCallback()` as a thin wrapper that calls `Payment::handleCallback()` for backward compatibility:

```php
public function handlePaymobCallback($data, $hmac = null) {
    return Payment::handleCallback($data, $hmac);
}
```

Remove the standalone `computePaymobHmac()` and `toSmallestUnit()` methods (now in Payment.php).

- [ ] **Step 2: Update paymob/callback.php**

Replace the callback file to route through Payment.php:

```php
<?php
// paymob/callback.php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Payment.php';

$isPost = ($_SERVER['REQUEST_METHOD'] === 'POST');

// Collect callback data
if ($isPost) {
    $rawBody = file_get_contents('php://input');
    $data = json_decode($rawBody, true) ?: [];
    $hmac = $_GET['hmac'] ?? ($data['hmac'] ?? null);
} else {
    $data = $_GET;
    $hmac = $_GET['hmac'] ?? null;
}

$result = Payment::handleCallback($data, $hmac);

if ($isPost) {
    // Webhook: return JSON
    header('Content-Type: application/json');
    http_response_code($result['success'] ? 200 : 400);
    echo json_encode($result);
    exit;
}

// GET redirect: send user back to correct page
$type = $result['type'] ?? 'subscription';
if ($result['success']) {
    if ($type === 'print_order') {
        $orderId = $result['reference_id'] ?? '';
        header('Location: /admin/print_orders.php?payment=success&order=' . urlencode($orderId));
    } else {
        header('Location: /admin/billing.php?payment=success');
    }
} else {
    $error = urlencode($result['error'] ?? 'Payment failed');
    if ($type === 'print_order') {
        $orderId = $result['reference_id'] ?? '';
        header('Location: /admin/order-checkout.php?payment=error&order=' . urlencode($orderId) . '&message=' . $error);
    } else {
        header('Location: /admin/billing.php?payment=error&message=' . $error);
    }
}
exit;
```

- [ ] **Step 3: Test subscription flow still works**

Verify `admin/billing.php` subscription flow works with the refactored code. The existing flow:
1. `admin/billing.php` line 201 calls `$billing->createSubscription()`
2. Which calls `createPaymobPaymentIntent()` (now delegates to Payment.php)
3. Returns `payment_url` → redirect to Paymob

No changes needed in `admin/billing.php` — the `Billing` class method signatures are unchanged.

- [ ] **Step 4: Commit refactored files**

```bash
git add includes/Billing.php paymob/callback.php
git commit -m "refactor: delegate Paymob calls from Billing.php to Payment.php"
```

### Task 4: Update PrintShopIntegration valid statuses

**Files:**
- Modify: `includes/PrintShopIntegration.php` (line 523)

- [ ] **Step 1: Add 'confirmed' to valid statuses**

At line 523 of `PrintShopIntegration.php`, change:
```php
$validStatuses = ['pending', 'submitted', 'processing', 'printing', 'shipped', 'delivered', 'cancelled'];
```
to:
```php
$validStatuses = ['pending', 'confirmed', 'submitted', 'processing', 'printing', 'shipped', 'delivered', 'cancelled'];
```

- [ ] **Step 2: Commit**

```bash
git add includes/PrintShopIntegration.php
git commit -m "feat: add 'confirmed' to valid order statuses for payment flow"
```

---

## Chunk 2: CreditManager.php

### Task 5: CreditManager — Core Methods

**Files:**
- Create: `includes/CreditManager.php`

- [ ] **Step 1: Create CreditManager.php with requestCredit, approve, reject, suspend**

```php
<?php
// includes/CreditManager.php
require_once __DIR__ . '/Database.php';

class CreditManager {

    /**
     * Company requests credit from a print shop
     */
    public static function requestCredit(
        string $companyId,
        int $printShopId,
        float $requestedLimit,
        ?string $notes = null
    ): array {
        $db = DatabaseAdapter::getInstance();

        // Check if account already exists
        $existing = self::getAccount($companyId, $printShopId);
        if ($existing) {
            if ($existing['status'] === 'pending') {
                return ['error' => 'Credit request already pending'];
            }
            if ($existing['status'] === 'approved') {
                return ['error' => 'Credit account already active'];
            }
            // Rejected/suspended — allow re-request by updating
            $db->execute(
                "UPDATE credit_accounts SET status = 'pending', requested_limit = :limit,
                 request_notes = :notes, rejected_reason = NULL, updated_at = NOW()
                 WHERE id = :id",
                [':limit' => $requestedLimit, ':notes' => $notes, ':id' => $existing['id']]
            );
            return ['credit_account_id' => $existing['id'], 'status' => 'pending'];
        }

        $id = generateUUID();
        $db->execute(
            "INSERT INTO credit_accounts (id, company_id, print_shop_id, requested_limit, request_notes, status, created_at)
             VALUES (:id, :cid, :psid, :limit, :notes, 'pending', NOW())",
            [
                ':id' => $id,
                ':cid' => $companyId,
                ':psid' => $printShopId,
                ':limit' => $requestedLimit,
                ':notes' => $notes
            ]
        );

        return ['credit_account_id' => $id, 'status' => 'pending'];
    }

    /**
     * Print shop approves credit request
     */
    public static function approve(
        string $creditAccountId,
        float $approvedLimit,
        string $paymentTerms,
        string $approvedBy
    ): bool {
        $db = DatabaseAdapter::getInstance();
        $result = $db->execute(
            "UPDATE credit_accounts SET status = 'approved', credit_limit = :limit,
             payment_terms = :terms, approved_by = :by, approved_at = NOW(), updated_at = NOW()
             WHERE id = :id AND status = 'pending'",
            [
                ':limit' => $approvedLimit,
                ':terms' => $paymentTerms,
                ':by' => $approvedBy,
                ':id' => $creditAccountId
            ]
        );
        return $result > 0;
    }

    /**
     * Print shop rejects credit request
     */
    public static function reject(
        string $creditAccountId,
        string $reason,
        string $rejectedBy
    ): bool {
        $db = DatabaseAdapter::getInstance();
        $result = $db->execute(
            "UPDATE credit_accounts SET status = 'rejected', rejected_reason = :reason,
             approved_by = :by, updated_at = NOW()
             WHERE id = :id AND status = 'pending'",
            [':reason' => $reason, ':by' => $rejectedBy, ':id' => $creditAccountId]
        );
        return $result > 0;
    }

    /**
     * Print shop suspends credit account
     */
    public static function suspend(string $creditAccountId): bool {
        $db = DatabaseAdapter::getInstance();
        $result = $db->execute(
            "UPDATE credit_accounts SET status = 'suspended', updated_at = NOW()
             WHERE id = :id AND status = 'approved'",
            [':id' => $creditAccountId]
        );
        return $result > 0;
    }

    /**
     * Reactivate a suspended credit account
     */
    public static function reactivate(string $creditAccountId): bool {
        $db = DatabaseAdapter::getInstance();
        $result = $db->execute(
            "UPDATE credit_accounts SET status = 'approved', updated_at = NOW()
             WHERE id = :id AND status = 'suspended'",
            [':id' => $creditAccountId]
        );
        return $result > 0;
    }
}
```

- [ ] **Step 2: Add charge method with DB transaction**

```php
    /**
     * Charge an order to credit account
     * Uses DB transaction with SELECT ... FOR UPDATE to prevent race conditions
     */
    public static function charge(
        string $creditAccountId,
        int $orderId,
        float $amount
    ): array {
        $db = DatabaseAdapter::getInstance();
        $pdo = $db->getPdo();

        $pdo->beginTransaction();
        try {
            // Lock the credit account row
            $stmt = $pdo->prepare("SELECT * FROM credit_accounts WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $creditAccountId]);
            $account = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$account || $account['status'] !== 'approved') {
                $pdo->rollBack();
                return ['error' => 'Credit account not active'];
            }

            $available = $account['credit_limit'] - $account['balance_used'];
            if ($amount > $available) {
                $pdo->rollBack();
                return ['error' => 'Insufficient credit. Available: ' . number_format($available, 3)];
            }

            $newBalance = $account['balance_used'] + $amount;

            // Update balance
            $stmt = $pdo->prepare("UPDATE credit_accounts SET balance_used = :bal, updated_at = NOW() WHERE id = :id");
            $stmt->execute([':bal' => $newBalance, ':id' => $creditAccountId]);

            // Create transaction record
            $txId = generateUUID();
            $stmt = $pdo->prepare(
                "INSERT INTO credit_transactions (id, credit_account_id, order_id, type, amount, balance_after, created_at)
                 VALUES (:id, :caid, :oid, 'charge', :amt, :bal, NOW())"
            );
            $stmt->execute([
                ':id' => $txId,
                ':caid' => $creditAccountId,
                ':oid' => $orderId,
                ':amt' => $amount,
                ':bal' => $newBalance
            ]);

            $pdo->commit();
            return ['transaction_id' => $txId, 'balance_after' => $newBalance];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['error' => 'Transaction failed: ' . $e->getMessage()];
        }
    }
```

- [ ] **Step 3: Add recordPayment, refund, and query methods**

```php
    /**
     * Record payment received (print shop marks company paid, e.g. bank transfer)
     */
    public static function recordPayment(
        string $creditAccountId,
        float $amount,
        ?string $notes = null,
        ?string $recordedBy = null
    ): array {
        $db = DatabaseAdapter::getInstance();
        $account = $db->query("SELECT * FROM credit_accounts WHERE id = :id", [':id' => $creditAccountId]);
        $account = $account[0] ?? null;

        if (!$account) {
            return ['error' => 'Credit account not found'];
        }

        $newBalance = max(0, $account['balance_used'] - $amount);
        $txId = generateUUID();

        $db->execute(
            "UPDATE credit_accounts SET balance_used = :bal, updated_at = NOW() WHERE id = :id",
            [':bal' => $newBalance, ':id' => $creditAccountId]
        );

        $db->execute(
            "INSERT INTO credit_transactions (id, credit_account_id, type, amount, balance_after, notes, recorded_by, created_at)
             VALUES (:id, :caid, 'payment', :amt, :bal, :notes, :by, NOW())",
            [
                ':id' => $txId, ':caid' => $creditAccountId,
                ':amt' => $amount, ':bal' => $newBalance,
                ':notes' => $notes, ':by' => $recordedBy
            ]
        );

        return ['transaction_id' => $txId, 'balance_after' => $newBalance];
    }

    /**
     * Refund a credit charge (order cancelled)
     */
    public static function refund(
        string $creditAccountId,
        int $orderId,
        float $amount
    ): array {
        $db = DatabaseAdapter::getInstance();
        $account = $db->query("SELECT * FROM credit_accounts WHERE id = :id", [':id' => $creditAccountId]);
        $account = $account[0] ?? null;

        if (!$account) {
            return ['error' => 'Credit account not found'];
        }

        $newBalance = max(0, $account['balance_used'] - $amount);
        $txId = generateUUID();

        $db->execute(
            "UPDATE credit_accounts SET balance_used = :bal, updated_at = NOW() WHERE id = :id",
            [':bal' => $newBalance, ':id' => $creditAccountId]
        );

        $db->execute(
            "INSERT INTO credit_transactions (id, credit_account_id, order_id, type, amount, balance_after, created_at)
             VALUES (:id, :caid, :oid, 'refund', :amt, :bal, NOW())",
            [
                ':id' => $txId, ':caid' => $creditAccountId,
                ':oid' => $orderId, ':amt' => $amount, ':bal' => $newBalance
            ]
        );

        return ['transaction_id' => $txId, 'balance_after' => $newBalance];
    }

    // --- Query methods ---

    public static function getAccount(string $companyId, int $printShopId): ?array {
        $db = DatabaseAdapter::getInstance();
        $rows = $db->query(
            "SELECT * FROM credit_accounts WHERE company_id = :cid AND print_shop_id = :psid",
            [':cid' => $companyId, ':psid' => $printShopId]
        );
        return $rows[0] ?? null;
    }

    public static function getAccountById(string $id): ?array {
        $db = DatabaseAdapter::getInstance();
        $rows = $db->query("SELECT * FROM credit_accounts WHERE id = :id", [':id' => $id]);
        return $rows[0] ?? null;
    }

    public static function getShopAccounts(int $printShopId, ?string $status = null): array {
        $db = DatabaseAdapter::getInstance();
        $sql = "SELECT ca.*, c.name as company_name, c.admin_email as company_email
                FROM credit_accounts ca
                JOIN companies c ON c.id = ca.company_id
                WHERE ca.print_shop_id = :psid";
        $params = [':psid' => $printShopId];
        if ($status) {
            $sql .= " AND ca.status = :status";
            $params[':status'] = $status;
        }
        $sql .= " ORDER BY ca.created_at DESC";
        return $db->query($sql, $params);
    }

    public static function getCompanyAccounts(string $companyId): array {
        $db = DatabaseAdapter::getInstance();
        return $db->query(
            "SELECT ca.*, ps.name as shop_name
             FROM credit_accounts ca
             JOIN print_shops ps ON ps.id = ca.print_shop_id
             WHERE ca.company_id = :cid ORDER BY ca.created_at DESC",
            [':cid' => $companyId]
        );
    }

    public static function getLedger(string $creditAccountId, int $limit = 50): array {
        $db = DatabaseAdapter::getInstance();
        return $db->query(
            "SELECT ct.*, po.order_number
             FROM credit_transactions ct
             LEFT JOIN print_orders po ON po.id = ct.order_id
             WHERE ct.credit_account_id = :caid
             ORDER BY ct.created_at DESC LIMIT :limit",
            [':caid' => $creditAccountId, ':limit' => $limit]
        );
    }

    public static function getAvailable(string $creditAccountId): float {
        $account = self::getAccountById($creditAccountId);
        if (!$account || $account['status'] !== 'approved') {
            return 0.0;
        }
        return max(0, $account['credit_limit'] - $account['balance_used']);
    }

    public static function getOutstandingSummary(int $printShopId): array {
        $db = DatabaseAdapter::getInstance();
        return $db->query(
            "SELECT ca.id, ca.company_id, c.name as company_name,
                    ca.credit_limit, ca.balance_used, ca.payment_terms,
                    (ca.credit_limit - ca.balance_used) as available
             FROM credit_accounts ca
             JOIN companies c ON c.id = ca.company_id
             WHERE ca.print_shop_id = :psid AND ca.status = 'approved' AND ca.balance_used > 0
             ORDER BY ca.balance_used DESC",
            [':psid' => $printShopId]
        );
    }
```

- [ ] **Step 4: Commit CreditManager.php**

```bash
git add includes/CreditManager.php
git commit -m "feat: add CreditManager.php — credit accounts, charges, payments, ledger"
```

---

## Chunk 3: PrintShopBilling.php & Order Checkout

### Task 6: PrintShopBilling.php

**Files:**
- Create: `includes/PrintShopBilling.php`

- [ ] **Step 1: Create PrintShopBilling.php**

```php
<?php
// includes/PrintShopBilling.php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Payment.php';
require_once __DIR__ . '/CreditManager.php';

class PrintShopBilling {

    /**
     * Get checkout options for a print order
     */
    public static function getCheckoutOptions(int $orderId): array {
        $db = DatabaseAdapter::getInstance();
        $orders = $db->query("SELECT * FROM print_orders WHERE id = :id", [':id' => $orderId]);
        $order = $orders[0] ?? null;

        if (!$order) {
            return ['error' => 'Order not found'];
        }

        $companyId = $order['company_id'];
        $printShopId = $order['print_shop_id'];

        $creditAccount = CreditManager::getAccount($companyId, $printShopId);
        $canUseCredit = false;
        $availableCredit = 0;
        $canRequestCredit = true;

        if ($creditAccount) {
            if ($creditAccount['status'] === 'approved') {
                $availableCredit = CreditManager::getAvailable($creditAccount['id']);
                $canUseCredit = ($availableCredit >= $order['total']);
            }
            if (in_array($creditAccount['status'], ['pending', 'approved'])) {
                $canRequestCredit = false;
            }
        }

        return [
            'order' => $order,
            'can_pay_online' => true,
            'can_use_credit' => $canUseCredit,
            'credit_account' => $creditAccount,
            'available_credit' => $availableCredit,
            'can_request_credit' => $canRequestCredit
        ];
    }

    /**
     * Process online payment for order via Paymob
     */
    public static function payOnline(int $orderId, array $billingData = []): array {
        $db = DatabaseAdapter::getInstance();
        $orders = $db->query("SELECT po.*, c.name as company_name, c.admin_email, c.billing_email
                              FROM print_orders po
                              JOIN companies c ON c.id = po.company_id
                              WHERE po.id = :id", [':id' => $orderId]);
        $order = $orders[0] ?? null;

        if (!$order) {
            return ['error' => 'Order not found'];
        }

        if ($order['payment_status'] === 'paid') {
            return ['error' => 'Order already paid'];
        }

        $billingData = array_merge([
            'first_name' => $order['company_name'] ?? 'Customer',
            'last_name' => '',
            'email' => $order['billing_email'] ?? $order['admin_email'] ?? '',
            'phone' => $order['shipping_phone'] ?? ''
        ], $billingData);

        $result = Payment::createIntent(
            'print_order',
            (string)$orderId,
            (float)$order['total'],
            $order['company_id'],
            $billingData,
            $order['currency'] ?? 'OMR'
        );

        return $result;
    }

    /**
     * Charge order to credit account
     */
    public static function chargeToCredit(int $orderId): array {
        $db = DatabaseAdapter::getInstance();
        $orders = $db->query("SELECT * FROM print_orders WHERE id = :id", [':id' => $orderId]);
        $order = $orders[0] ?? null;

        if (!$order) {
            return ['error' => 'Order not found'];
        }

        if ($order['payment_status'] === 'paid') {
            return ['error' => 'Order already paid'];
        }

        $creditAccount = CreditManager::getAccount($order['company_id'], $order['print_shop_id']);
        if (!$creditAccount || $creditAccount['status'] !== 'approved') {
            return ['error' => 'No active credit account'];
        }

        $result = CreditManager::charge($creditAccount['id'], $orderId, (float)$order['total']);

        if (isset($result['error'])) {
            return $result;
        }

        // Update order
        $db->execute(
            "UPDATE print_orders SET payment_status = 'paid', payment_method = 'credit',
             status = 'confirmed' WHERE id = :id",
            [':id' => $orderId]
        );

        return ['success' => true, 'transaction_id' => $result['transaction_id']];
    }

    /**
     * Upload Purchase Order for an order
     */
    public static function uploadPO(int $orderId, array $file, ?string $poNumber = null): array {
        $db = DatabaseAdapter::getInstance();
        $orders = $db->query("SELECT * FROM print_orders WHERE id = :id", [':id' => $orderId]);
        $order = $orders[0] ?? null;

        if (!$order) {
            return ['error' => 'Order not found'];
        }

        // Validate file
        $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png'];
        $maxSize = 5 * 1024 * 1024; // 5MB

        if (!in_array($file['type'], $allowedTypes)) {
            return ['error' => 'Invalid file type. Allowed: PDF, JPG, PNG'];
        }
        if ($file['size'] > $maxSize) {
            return ['error' => 'File too large. Maximum 5MB'];
        }

        // Create upload directory
        $uploadDir = __DIR__ . '/../uploads/purchase_orders/' . $order['company_id'];
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Save file
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = $orderId . '_' . time() . '.' . $ext;
        $filePath = $uploadDir . '/' . $filename;
        $relativePath = 'uploads/purchase_orders/' . $order['company_id'] . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            return ['error' => 'Failed to save file'];
        }

        // Update order
        $db->execute(
            "UPDATE print_orders SET po_file_path = :path, po_number = :num, po_required = 1, po_received_at = NOW()
             WHERE id = :id",
            [':path' => $relativePath, ':num' => $poNumber, ':id' => $orderId]
        );

        return ['po_file_path' => $relativePath, 'po_number' => $poNumber];
    }

    /**
     * Get payment summary for an order
     */
    public static function getOrderPaymentInfo(int $orderId): array {
        $db = DatabaseAdapter::getInstance();
        $orders = $db->query(
            "SELECT po.*, p.status as online_payment_status, p.payment_method as online_method,
                    p.paymob_transaction_id
             FROM print_orders po
             LEFT JOIN payments p ON p.id = po.payment_id
             WHERE po.id = :id",
            [':id' => $orderId]
        );
        return $orders[0] ?? [];
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add includes/PrintShopBilling.php
git commit -m "feat: add PrintShopBilling.php — order checkout, credit charge, PO upload"
```

### Task 7: Order Checkout Page (Company Side)

**Files:**
- Create: `admin/order-checkout.php`
- Reference: `printshop/dashboard.php` for layout pattern

- [ ] **Step 1: Create admin/order-checkout.php**

This page shows after a company places a print order. It displays the order summary and payment options.

```php
<?php
// admin/order-checkout.php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/PrintShopBilling.php';
require_once __DIR__ . '/../includes/CreditManager.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::requireLogin();
$user = Auth::getCurrentUser();
$companyId = getCurrentCompanyId();

$orderId = (int)($_GET['order'] ?? 0);
if (!$orderId) {
    header('Location: /admin/print_orders.php');
    exit;
}

$error = '';
$success = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRFToken($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';

    if ($action === 'pay_online') {
        $result = PrintShopBilling::payOnline($orderId);
        if (isset($result['checkout_url'])) {
            header('Location: ' . $result['checkout_url']);
            exit;
        }
        $error = $result['error'] ?? 'Payment failed';

    } elseif ($action === 'charge_credit') {
        $result = PrintShopBilling::chargeToCredit($orderId);
        if (isset($result['success']) && $result['success']) {
            $success = 'Order charged to credit account successfully';
        } else {
            $error = $result['error'] ?? 'Credit charge failed';
        }

    } elseif ($action === 'upload_po') {
        $poNumber = trim($_POST['po_number'] ?? '');
        if (isset($_FILES['po_file']) && $_FILES['po_file']['error'] === UPLOAD_ERR_OK) {
            $result = PrintShopBilling::uploadPO($orderId, $_FILES['po_file'], $poNumber ?: null);
            if (isset($result['error'])) {
                $error = $result['error'];
            } else {
                $success = 'Purchase Order uploaded successfully';
            }
        } else {
            $error = 'Please select a file to upload';
        }

    } elseif ($action === 'request_credit') {
        $requestedLimit = (float)($_POST['requested_limit'] ?? 0);
        $notes = trim($_POST['request_notes'] ?? '');
        $order = PrintShopBilling::getOrderPaymentInfo($orderId);
        if ($order && $requestedLimit > 0) {
            $result = CreditManager::requestCredit($companyId, (int)$order['print_shop_id'], $requestedLimit, $notes ?: null);
            if (isset($result['error'])) {
                $error = $result['error'];
            } else {
                $success = 'Credit request submitted. You will be notified when approved.';
            }
        } else {
            $error = 'Please enter a valid credit limit';
        }
    }
}

// Check for payment callback status
if (isset($_GET['payment'])) {
    if ($_GET['payment'] === 'success') $success = 'Payment successful!';
    if ($_GET['payment'] === 'error') $error = $_GET['message'] ?? 'Payment failed';
}

$checkout = PrintShopBilling::getCheckoutOptions($orderId);
if (isset($checkout['error'])) {
    header('Location: /admin/print_orders.php');
    exit;
}

$order = $checkout['order'];

// Verify company owns this order
if ($order['company_id'] !== $companyId && !Auth::hasRole('super_admin')) {
    header('Location: /admin/print_orders.php');
    exit;
}

$pageTitle = 'Order Payment — ' . ($order['order_number'] ?? 'Order #' . $orderId);
include __DIR__ . '/includes/admin-header.php';
?>

<div class="max-w-3xl mx-auto py-8 px-4">
    <h1 class="text-2xl font-bold mb-6">Order Payment</h1>

    <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded mb-4"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded mb-4"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- Order Summary -->
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <h2 class="text-lg font-semibold mb-4">Order Summary</h2>
        <div class="grid grid-cols-2 gap-4 text-sm">
            <div><span class="text-gray-500">Order:</span> <?= htmlspecialchars($order['order_number'] ?? '') ?></div>
            <div><span class="text-gray-500">Status:</span> <?= htmlspecialchars($order['status'] ?? 'pending') ?></div>
            <div><span class="text-gray-500">Quantity:</span> <?= (int)$order['quantity'] ?> cards</div>
            <div><span class="text-gray-500">Paper:</span> <?= htmlspecialchars($order['paper_type'] ?? 'standard') ?></div>
            <div><span class="text-gray-500">Finish:</span> <?= htmlspecialchars($order['finish'] ?? 'matte') ?></div>
            <div><span class="text-gray-500">Payment:</span> <?= htmlspecialchars($order['payment_status'] ?? 'pending') ?></div>
        </div>
        <div class="border-t mt-4 pt-4">
            <div class="flex justify-between text-sm"><span>Subtotal</span><span><?= number_format($order['subtotal'] ?? 0, 3) ?> <?= $order['currency'] ?? 'OMR' ?></span></div>
            <?php if ($order['setup_fee'] > 0): ?>
                <div class="flex justify-between text-sm"><span>Setup Fee</span><span><?= number_format($order['setup_fee'], 3) ?> <?= $order['currency'] ?? 'OMR' ?></span></div>
            <?php endif; ?>
            <?php if ($order['shipping_fee'] > 0): ?>
                <div class="flex justify-between text-sm"><span>Shipping</span><span><?= number_format($order['shipping_fee'], 3) ?> <?= $order['currency'] ?? 'OMR' ?></span></div>
            <?php endif; ?>
            <div class="flex justify-between font-bold text-lg mt-2 border-t pt-2">
                <span>Total</span>
                <span><?= number_format($order['total'] ?? 0, 3) ?> <?= $order['currency'] ?? 'OMR' ?></span>
            </div>
        </div>
    </div>

    <?php if ($order['payment_status'] !== 'paid'): ?>
    <!-- Payment Options -->
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <h2 class="text-lg font-semibold mb-4">Payment Options</h2>

        <!-- Pay Now -->
        <form method="POST" class="mb-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="pay_online">
            <button type="submit" class="w-full bg-blue-600 text-white py-3 px-6 rounded-lg font-medium hover:bg-blue-700 transition">
                Pay Now — <?= number_format($order['total'] ?? 0, 3) ?> <?= $order['currency'] ?? 'OMR' ?>
            </button>
            <p class="text-xs text-gray-500 mt-1 text-center">Card, OmanNet, or Apple Pay via Paymob</p>
        </form>

        <?php if ($checkout['can_use_credit']): ?>
        <!-- Charge to Credit -->
        <form method="POST" class="mb-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="charge_credit">
            <button type="submit" class="w-full bg-green-600 text-white py-3 px-6 rounded-lg font-medium hover:bg-green-700 transition">
                Charge to Credit Account
            </button>
            <p class="text-xs text-gray-500 mt-1 text-center">
                Available credit: <?= number_format($checkout['available_credit'], 3) ?> <?= $order['currency'] ?? 'OMR' ?>
                (<?= $checkout['credit_account']['payment_terms'] ?? 'net30' ?>)
            </p>
        </form>
        <?php endif; ?>

        <?php if ($checkout['can_request_credit']): ?>
        <!-- Request Credit -->
        <details class="border rounded-lg p-4">
            <summary class="cursor-pointer font-medium text-gray-700">Request Credit Account</summary>
            <form method="POST" class="mt-4 space-y-3">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="request_credit">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Requested Credit Limit (<?= $order['currency'] ?? 'OMR' ?>)</label>
                    <input type="number" name="requested_limit" step="0.001" min="0.001" required
                           class="w-full border rounded-lg px-3 py-2" placeholder="e.g. 500.000">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Notes (optional)</label>
                    <textarea name="request_notes" rows="2" class="w-full border rounded-lg px-3 py-2"
                              placeholder="Company details, expected order volume..."></textarea>
                </div>
                <button type="submit" class="bg-gray-600 text-white py-2 px-4 rounded-lg hover:bg-gray-700 transition">
                    Submit Credit Request
                </button>
            </form>
        </details>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="bg-green-50 border border-green-200 text-green-700 px-6 py-4 rounded-lg mb-6 text-center">
        <span class="text-2xl">&#10003;</span> Payment complete — <?= htmlspecialchars($order['payment_method'] ?? 'online') ?>
    </div>
    <?php endif; ?>

    <!-- Upload PO -->
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <h2 class="text-lg font-semibold mb-4">Purchase Order (Optional)</h2>
        <?php if ($order['po_file_path']): ?>
            <p class="text-sm text-green-600 mb-2">PO uploaded: <?= htmlspecialchars($order['po_number'] ?? 'No number') ?></p>
            <a href="/<?= htmlspecialchars($order['po_file_path']) ?>" target="_blank" class="text-blue-600 underline text-sm">View PO</a>
        <?php endif; ?>
        <form method="POST" enctype="multipart/form-data" class="mt-3 space-y-3">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="upload_po">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">PO Number</label>
                <input type="text" name="po_number" class="w-full border rounded-lg px-3 py-2" placeholder="e.g. PO-2026-001">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">PO Document (PDF, JPG, PNG — max 5MB)</label>
                <input type="file" name="po_file" accept=".pdf,.jpg,.jpeg,.png" required class="w-full">
            </div>
            <button type="submit" class="bg-gray-600 text-white py-2 px-4 rounded-lg hover:bg-gray-700 transition">
                Upload PO
            </button>
        </form>
    </div>

    <a href="/admin/print_orders.php" class="text-gray-500 hover:text-gray-700 text-sm">&larr; Back to Orders</a>
</div>

<?php include __DIR__ . '/includes/admin-footer.php'; ?>
```

- [ ] **Step 2: Verify admin header/footer includes exist**

Check that `admin/includes/admin-header.php` and `admin/includes/admin-footer.php` exist. If they don't, use the pattern from existing admin pages (e.g., `admin/billing.php`).

- [ ] **Step 3: Commit**

```bash
git add admin/order-checkout.php
git commit -m "feat: add order checkout page with online payment, credit, and PO upload"
```

---

## Chunk 4: Print Shop Credit Management UI

### Task 8: Print Shop Credit Accounts Page

**Files:**
- Create: `printshop/credit-accounts.php`
- Reference: `printshop/dashboard.php` for auth/layout pattern (lines 12-37 for auth, line 83 for header)

- [ ] **Step 1: Create printshop/credit-accounts.php**

Follow the auth pattern from `printshop/dashboard.php`:
- `Auth::requireLogin()` → get current user
- Check role is `print_shop` or `super_admin`
- `PrintShop::getByUserId($user['id'])` to get shop

Page shows:
- Pending credit requests with approve/reject buttons
- Active credit accounts with limit, used, available, terms
- Suspended accounts with reactivate option
- Summary stats at top (total accounts, total outstanding, pending requests)

Handle POST actions:
- `approve`: calls `CreditManager::approve()` with limit, terms, approvedBy
- `reject`: calls `CreditManager::reject()` with reason
- `suspend`: calls `CreditManager::suspend()`
- `reactivate`: calls `CreditManager::reactivate()`

Use Tailwind CSS. Table with responsive design. Modal for approve action (set limit + terms).

- [ ] **Step 2: Commit**

```bash
git add printshop/credit-accounts.php
git commit -m "feat: add print shop credit accounts management page"
```

### Task 9: Print Shop Credit Ledger Page

**Files:**
- Create: `printshop/credit-ledger.php`

- [ ] **Step 1: Create printshop/credit-ledger.php**

Same auth pattern as credit-accounts.php. Takes `?account=UUID` parameter.

Page shows:
- Account summary at top (company name, limit, used, available, terms)
- Transaction table: date, type (charge/payment/adjustment/refund), order number, amount, balance after, notes
- "Record Payment" form: amount, notes, submit
- Color-coded transaction types (charge=red, payment=green, refund=blue, adjustment=gray)

Handle POST:
- `record_payment`: calls `CreditManager::recordPayment()` with amount, notes, recordedBy

- [ ] **Step 2: Commit**

```bash
git add printshop/credit-ledger.php
git commit -m "feat: add print shop credit ledger page"
```

### Task 10: Company Credit Accounts Page

**Files:**
- Create: `admin/credit-accounts.php`

- [ ] **Step 1: Create admin/credit-accounts.php**

Auth: `Auth::requireLogin()`, `getCurrentCompanyId()`.

Page shows:
- All credit accounts for this company across print shops
- Status badges (pending=yellow, approved=green, suspended=red, rejected=gray)
- For approved: available credit, limit, terms, balance used
- Link to request credit from a new print shop (if applicable)
- Transaction history link per account

- [ ] **Step 2: Commit**

```bash
git add admin/credit-accounts.php
git commit -m "feat: add company credit accounts overview page"
```

---

## Chunk 5: Integration & Deploy

### Task 11: Wire Up Order Creation → Checkout

**Files:**
- Modify: `includes/PrintShopIntegration.php` (createOrder method)
- Modify: `admin/print_orders.php` (add checkout link)

- [ ] **Step 1: Add checkout redirect after order creation**

In `PrintShopIntegration::createOrder()` (around line 302), the method already returns the order_id. The caller should redirect to `admin/order-checkout.php?order={id}` after successful creation. Find where `createOrder` is called from the admin side and add the redirect.

- [ ] **Step 2: Add payment status and checkout link to orders table**

In `admin/print_orders.php`, add a "Payment" column showing payment_status badge. For unpaid orders, add a "Pay" button linking to `order-checkout.php?order={id}`.

- [ ] **Step 3: Add credit accounts link to print shop dashboard**

In `printshop/dashboard.php`, add a navigation link to `credit-accounts.php` in the top navbar (around line 88-113).

- [ ] **Step 4: Commit**

```bash
git add includes/PrintShopIntegration.php admin/print_orders.php printshop/dashboard.php
git commit -m "feat: wire up order checkout flow and add credit nav links"
```

### Task 12: Deploy & Test

- [ ] **Step 1: Push to GitHub**

```bash
cd /Users/ali/claude/projects/cardify.om
git push origin main
```

- [ ] **Step 2: Deploy to VPS**

```bash
ssh root@147.93.20.54 "cd /www/wwwroot/cardify.om && git pull origin main"
```

- [ ] **Step 3: Run migrations**

```bash
ssh root@147.93.20.54 "cd /www/wwwroot/cardify.om && php database/migrations/030_payments_table.php && php database/migrations/031_credit_tables.php && php database/migrations/032_print_orders_payment_columns.php"
```

- [ ] **Step 4: Verify tables created**

```bash
ssh root@147.93.20.54 "mysql -u bc -ppWewN3fwFmEHh32J bc -e 'SHOW TABLES LIKE \"%credit%\"; SHOW TABLES LIKE \"payments\"; DESCRIBE print_orders' | grep -E 'payment_method|payment_id|credit|payments'"
```

- [ ] **Step 5: Test subscription flow still works**

Navigate to cardify.om admin billing page, verify subscription checkout still redirects to Paymob correctly.

- [ ] **Step 6: Test print order payment flow**

1. Create a test print order
2. Navigate to order-checkout.php
3. Verify "Pay Now" redirects to Paymob checkout
4. Verify callback updates order status

- [ ] **Step 7: Commit any fixes**

```bash
git add -A && git commit -m "fix: deployment adjustments"
git push origin main
ssh root@147.93.20.54 "cd /www/wwwroot/cardify.om && git pull origin main"
```
