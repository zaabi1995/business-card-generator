<?php
/**
 * print_shops columns that exist on live Cardify.
 *
 * Source of truth:
 *   - database/migrations/008_print_shops.php CREATE TABLE
 *   - Live SHOW COLUMNS FROM print_shops on 147.93.20.54 (2026-09-03):
 *     logo_url, pricing, min_order_quantity
 *
 * There is no logo_path, pricing_tiers, or min_quantity column.
 * Do not invent columns. Additive later migrations may add more
 * names; create() must still only write names from this list or
 * a later documented ADD COLUMN.
 */
return [
    'id',
    'name',
    'slug',
    'description',
    'logo_url',
    'email',
    'phone',
    'website',
    'address',
    'city',
    'state',
    'country',
    'postal_code',
    'user_id',
    'services',
    'paper_types',
    'finishes',
    'card_sizes',
    'pricing',
    'currency',
    'min_order_quantity',
    'turnaround_days',
    'express_available',
    'express_days',
    'express_fee',
    'shipping_zones',
    'free_shipping_threshold',
    'api_enabled',
    'api_endpoint',
    'api_key',
    'webhook_secret',
    'status',
    'featured',
    'verified',
    'rating',
    'total_orders',
    'created_at',
    'updated_at',
    'approved_at',
    'approved_by',
];
