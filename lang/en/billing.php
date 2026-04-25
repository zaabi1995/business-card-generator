<?php
return [
    // Callback messages
    'pay_success'          => 'Payment completed successfully.',
    'pay_error_default'    => 'Payment processing failed. Please try again.',
    'subscribe_failed'     => 'Could not start the payment',
    'buy_cards_failed'     => 'Could not initiate card purchase',

    // Current plan card
    'current_plan'         => 'Current Plan',
    'monthly_price'        => 'Monthly Price',
    'per_month_short'      => '/mo',
    'currency_label'       => 'Currency:',
    'plan_default_desc'    => 'Basic features for small teams',

    // Usage
    'usage_h3'             => 'Usage',
    'usage_employees'      => 'Employees',
    'usage_templates'      => 'Templates',
    'usage_storage'        => 'Storage',
    'unlimited'            => 'Unlimited',
    'gb_suffix'            => ':n GB',

    // Premium banner (kept for the legacy hidden block; rewritten neutral).
    'unlock_premium_h3'    => 'Free Forever Platform',
    'unlock_premium_body'  => 'HD card generation, QR analytics, bulk generation, all included at no charge.',
    'feat_hd_quality'      => 'HD Quality Cards (300+ DPI)',
    'feat_qr_analytics'    => 'QR Scan Analytics',
    'feat_bulk_gen'        => 'Bulk Card Generation',
    'view_plans_btn'       => 'See Print Pricing',

    // Feature matrix
    'feature_matrix_h3'    => 'Plan Feature Comparison',
    'col_feature'          => 'Feature',
    'col_free'             => 'Free',
    'col_pro'              => 'Pro',
    'col_enterprise'       => 'Enterprise',
    'fm_employees'         => 'Employees',
    'fm_cards_month'       => 'Cards / Month',
    'fm_preview_quality'   => 'Card Preview Quality',
    'fm_print_quality'     => 'Print Quality',
    'fm_qr_analytics'      => 'QR Scan Analytics',
    'fm_bulk_gen'          => 'Bulk Card Generation',
    'fm_api_access'        => 'API Access',
    'fm_custom_brand'      => 'Custom Branding',
    'fm_priority_support'  => 'Priority Support',
    'limit_up_to'          => 'Up to :n',
    'quality_low'          => 'Low (~100 DPI)',
    'quality_high'         => 'High (~300 DPI)',
    'matrix_note'          => '<strong>Note:</strong> All plans include full print quality when ordering physical cards from print shops. Preview quality affects only the digital card images displayed in the dashboard.',

    // Plans grid
    'upgrade_your_plan'    => 'Upgrade Your Plan',
    'cycle_monthly'        => 'Monthly',
    'cycle_yearly'         => 'Yearly',
    'save_20'              => 'Save 20%',
    'save_pct'             => 'Save :n%',
    'no_plans_h3'          => 'No Subscription Plans Available',
    'no_plans_body'        => 'Subscription plans have not been configured yet. Contact your administrator.',
    'popular_badge'        => 'Popular',
    'per_month'            => '/month',
    'per_year'             => '/year',
    'employees_suffix'     => 'employees',
    'templates_suffix'     => 'templates',
    'storage_suffix'       => 'storage',
    'current_plan_btn'     => 'Current Plan',
    'upgrade_to'           => 'Upgrade to :name',

    // Unpaid orders panel
    'pending_payments'     => 'Pending Print Payments',
    'n_unpaid'             => ':n unpaid',
    'order_num'            => 'Order #:num',
    'n_cards'              => ':n cards',
    'pay_now'              => 'Pay Now',
    'total_outstanding'    => 'Total outstanding:',
    'view_all_orders'      => 'View all orders',

    // Card generation, free + unlimited since Apr 2026 pricing reset.
    'card_credits_h3'      => 'Card Generation',
    'card_credits_sub'     => 'Free for every team, unlimited cards',
    'credits_available'    => 'Credits Available',
    'generated_this_month' => 'Generated This Month',
    'monthly_limit_plan'   => 'Monthly Limit',
    'buy_credits_lead'     => '<strong>Buy card credits</strong>, each credit lets you generate one business card. Price: <strong>:price :cur</strong> per card.',
    'number_of_cards'      => 'Number of Cards',
    'cards_option'         => ':qty cards, :total :cur',
    'buy_credits_btn'      => 'Buy Credits via Paymob',
    'card_included'        => 'Card generation is free and unlimited for every team. No per-card charge.',

    // Payment methods
    'payment_methods_h3'   => 'Payment Methods',
    'payment_methods_lead' => 'We accept the following payment methods:',
    'apple_pay'            => 'Apple Pay',
    'omannet'              => 'Omannet',

    // Load errors
    'err_billing_missing'  => 'Billing module is not configured. Please contact your administrator.',
    'err_billing_load'     => 'Error loading billing module:',
    'err_billing_init'     => 'Error initializing billing system:',
    'invalid_request'      => 'Invalid request',
];
