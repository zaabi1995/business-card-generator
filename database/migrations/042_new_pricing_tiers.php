<?php
/**
 * Migration 042: New pricing tiers (OMR-first)
 *
 * Starter (Free, 3 seats) | Professional (5 OMR/mo, 10 seats)
 * Business (15 OMR/mo, 50 seats) | Enterprise (Custom)
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    $pdo->beginTransaction();

    // 1. Deactivate old plans
    $pdo->exec("UPDATE subscription_plans SET is_active = 0 WHERE id IN ('free', 'pro', 'enterprise')");

    // 2. Create new plans
    $plans = [
        [
            'id' => 'starter',
            'name' => 'Starter',
            'description' => 'Perfect for freelancers and solo professionals',
            'price_monthly' => 0.000,
            'price_yearly' => 0.000,
            'max_employees' => 3,
            'max_templates' => 3,
            'max_storage_mb' => 100,
            'max_cards_per_month' => 10,
            'features_json' => json_encode([
                'Digital cards with QR code',
                'Basic templates',
                'Email support'
            ]),
            'sort_order' => 1,
            'is_active' => 1
        ],
        [
            'id' => 'professional',
            'name' => 'Professional',
            'description' => 'For growing teams that need more power',
            'price_monthly' => 5.000,
            'price_yearly' => 50.000,
            'max_employees' => 10,
            'max_templates' => -1,
            'max_storage_mb' => 500,
            'max_cards_per_month' => 100,
            'features_json' => json_encode([
                'Everything in Starter',
                'Unlimited templates',
                'Custom branding',
                'CSV bulk import',
                'Priority support',
                'Analytics dashboard'
            ]),
            'sort_order' => 2,
            'is_active' => 1
        ],
        [
            'id' => 'business',
            'name' => 'Business',
            'description' => 'For companies scaling their team cards',
            'price_monthly' => 15.000,
            'price_yearly' => 150.000,
            'max_employees' => 50,
            'max_templates' => -1,
            'max_storage_mb' => 2000,
            'max_cards_per_month' => 500,
            'features_json' => json_encode([
                'Everything in Professional',
                'Up to 50 employees',
                'Print ordering integration',
                'NFC card support',
                'Dedicated account manager',
                'API access'
            ]),
            'sort_order' => 3,
            'is_active' => 1
        ],
        [
            'id' => 'enterprise_v2',
            'name' => 'Enterprise',
            'description' => 'For large organisations with custom needs',
            'price_monthly' => 0.000,
            'price_yearly' => 0.000,
            'max_employees' => -1,
            'max_templates' => -1,
            'max_storage_mb' => -1,
            'max_cards_per_month' => -1,
            'features_json' => json_encode([
                'Everything in Business',
                'Unlimited employees',
                'Custom integrations',
                'SLA guarantee',
                'White-label options',
                'On-premise deployment'
            ]),
            'sort_order' => 4,
            'is_active' => 1
        ]
    ];

    $insertPlan = $pdo->prepare(
        "INSERT INTO subscription_plans (id, name, description, price_monthly, price_yearly, max_employees, max_templates, max_storage_mb, max_cards_per_month, features_json, sort_order, is_active)
         VALUES (:id, :name, :description, :price_monthly, :price_yearly, :max_employees, :max_templates, :max_storage_mb, :max_cards_per_month, :features_json, :sort_order, :is_active)
         ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            description = VALUES(description),
            price_monthly = VALUES(price_monthly),
            price_yearly = VALUES(price_yearly),
            max_employees = VALUES(max_employees),
            max_templates = VALUES(max_templates),
            max_storage_mb = VALUES(max_storage_mb),
            max_cards_per_month = VALUES(max_cards_per_month),
            features_json = VALUES(features_json),
            sort_order = VALUES(sort_order),
            is_active = VALUES(is_active)"
    );

    foreach ($plans as $plan) {
        $insertPlan->execute($plan);
    }

    // 3. Set OMR prices in plan_prices table
    $prices = [
        ['starter', 'OMR', 0.000, 0.000],
        ['starter', 'AED', 0.000, 0.000],
        ['starter', 'SAR', 0.000, 0.000],
        ['starter', 'USD', 0.000, 0.000],
        ['professional', 'OMR', 5.000, 50.000],
        ['professional', 'AED', 49.000, 490.000],
        ['professional', 'SAR', 49.000, 490.000],
        ['professional', 'USD', 13.000, 130.000],
        ['business', 'OMR', 15.000, 150.000],
        ['business', 'AED', 145.000, 1450.000],
        ['business', 'SAR', 145.000, 1450.000],
        ['business', 'USD', 39.000, 390.000],
        ['enterprise_v2', 'OMR', 0.000, 0.000],
        ['enterprise_v2', 'AED', 0.000, 0.000],
        ['enterprise_v2', 'SAR', 0.000, 0.000],
        ['enterprise_v2', 'USD', 0.000, 0.000],
    ];

    $insertPrice = $pdo->prepare(
        "INSERT INTO plan_prices (id, plan_id, currency, price_monthly, price_yearly)
         VALUES (UUID(), :plan_id, :currency, :price_monthly, :price_yearly)
         ON DUPLICATE KEY UPDATE price_monthly = VALUES(price_monthly), price_yearly = VALUES(price_yearly)"
    );

    foreach ($prices as [$planId, $currency, $monthly, $yearly]) {
        $insertPrice->execute([
            'plan_id' => $planId,
            'currency' => $currency,
            'price_monthly' => $monthly,
            'price_yearly' => $yearly
        ]);
    }

    // 4. Migrate existing companies on 'free' to 'starter', 'pro' to 'professional', 'enterprise' to 'business'
    $pdo->exec("UPDATE companies SET plan = 'starter' WHERE plan = 'free'");
    $pdo->exec("UPDATE companies SET plan = 'professional' WHERE plan = 'pro'");
    $pdo->exec("UPDATE companies SET plan = 'business' WHERE plan = 'enterprise'");

    $pdo->commit();
    echo "Migration 042: New pricing tiers created successfully\n";
    echo "  - starter (Free, 3 seats)\n";
    echo "  - professional (5 OMR/mo, 10 seats)\n";
    echo "  - business (15 OMR/mo, 50 seats)\n";
    echo "  - enterprise_v2 (Custom)\n";
    echo "  - Existing companies migrated to new plan IDs\n";
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Migration 042 failed: " . $e->getMessage() . "\n";
    exit(1);
}
