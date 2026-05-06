<?php
return [
    // Page chrome
    'page_title'           => ':shop, Client Pricing',
    'nav_label'            => 'Client Pricing',
    'heading'              => 'Client-specific pricing',
    'subheading'           => 'Override your default tiers for individual client companies. Useful for honouring a quoted rate without changing prices for everyone else.',

    // Default tiers reference
    'default_tiers_h'      => 'Your default tiers (apply to all clients without an override)',
    'no_default_tiers'     => 'No tiers set yet. Visit Settings to configure your default pricing.',

    // Empty state
    'empty_h'              => 'No client overrides yet',
    'empty_s'              => 'Add a client to set custom prices that override your default tiers for that company only.',

    // Row
    'unknown_company'      => 'Unknown company',
    'no_tiers_set'         => 'No tiers set',
    'min_qty_badge'        => 'Min :n',
    'per_paper_badge'      => 'Per paper-type pricing',
    'edit_btn'             => 'Edit',
    'reset_btn'            => 'Reset',
    'reset_confirm'        => 'Remove this override and revert to default pricing?',

    // Min quantity
    'field_min_qty'        => 'Minimum order quantity',
    'min_qty_help'         => 'Block orders below this quantity. Leave 0 to allow any quantity.',

    // Pricing mode toggle
    'mode_single'          => 'Single tier table',
    'mode_per_paper'       => 'Per paper-type pricing',
    'per_paper_help'       => 'Set separate tier tables for each paper variant. Prices already include the variant, no silk multiplier is applied on top. Currency: :currency.',

    // Add button + modal
    'add_btn'              => 'Add Client Pricing',
    'modal_add_h'          => 'Add client pricing',
    'modal_edit_h'         => 'Edit client pricing',

    // Form fields
    'field_company'        => 'Client company',
    'select_company'       => 'Choose a company...',
    'no_candidates'        => 'No companies have ordered from your shop yet. Once a company places an order, you can set custom pricing for them here.',
    'field_tiers'          => 'Quantity tiers',
    'tiers_help'           => 'Quantity, total price for that quantity in :currency. Per-card column is calculated automatically.',
    'col_quantity'         => 'Qty',
    'col_total_price'      => 'Total (:currency)',
    'col_per_card'         => 'Per card',
    'add_tier_btn'         => 'Add tier',
    'copy_default_btn'     => 'Copy from defaults',

    'field_notes'          => 'Notes',
    'optional'             => 'optional',
    'notes_placeholder'    => 'e.g. per OTECH-2026 quote, valid until December 2026',

    // Buttons
    'cancel_btn'           => 'Cancel',
    'save_btn'             => 'Save',

    // Flash
    'flash_saved'          => 'Client pricing saved.',
    'flash_reset'          => 'Override removed. Default pricing now applies.',
    'invalid_request'      => 'Invalid request',
    'error_missing_company'=> 'Please choose a client company.',
    'error_no_tiers'       => 'Please add at least one quantity tier.',
    'close_btn'            => 'Close',
];
