<?php
return [
    // Flash + errors
    'invalid_request'   => 'Invalid request',
    'created_ok'        => 'Company created successfully!',
    'create_failed'     => 'Failed to create company',
    'updated_ok'        => 'Company updated successfully!',
    'update_failed'     => 'Failed to update company',

    // Header
    'registered_count'  => ':n registered companies',
    'add_company'       => 'Add Company',

    // Table
    'col_company'       => 'Company',
    'col_admin'         => 'Admin',
    'col_plan'          => 'Plan',
    'col_currency'      => 'Currency',
    'col_stats'         => 'Stats',
    'col_status'        => 'Status',
    'col_actions'       => 'Actions',
    'billing_prefix'    => 'Billing:',
    'tip_employees'     => 'Employees',
    'tip_orders'        => 'Orders',
    'tip_spend'         => 'Total Spend',
    'tip_view_portal'   => 'View Portal',
    'tip_edit'          => 'Edit Company',

    // Empty
    'empty_body'        => 'No companies found. Create your first company to get started.',

    // Plan labels
    'plan_free'         => 'Free',
    'plan_pro'          => 'Pro',
    'plan_enterprise'   => 'Enterprise',

    // Status badges
    'status_active'     => 'Active',
    'status_suspended'  => 'Suspended',
    'status_pending'    => 'Pending',

    // Modal
    'modal_edit_title'  => 'Edit Company',
    'modal_create_title'=> 'Create Company',
    'field_name'        => 'Company Name',
    'field_slug'        => 'Company Slug',
    'field_slug_short'  => 'Slug',
    'slug_auto_ph'      => 'auto-generated if empty',
    'field_admin_email' => 'Admin Email',
    'field_billing_email' => 'Billing Email',
    'billing_same_ph'   => 'Same as admin if empty',
    'field_password'    => 'Password',
    'password_keep_ph'  => 'Leave blank to keep current',
    'field_plan'        => 'Plan',
    'field_currency'    => 'Currency',
    'field_status'      => 'Status',
    'field_parent'      => 'Parent Company',
    'parent_none'       => 'None (Standalone)',
    'btn_cancel'        => 'Cancel',
    'btn_save_changes'  => 'Save Changes',
    'btn_create'        => 'Create Company',

    // Beautiful UI companies grid (web-react/src/surfaces/CompaniesGrid.tsx).
    // Passed through data-props; the grid holds no strings of its own.
    'grid_search_ph' => 'Search name, slug or admin email',
    'grid_col_people' => 'People',
    'grid_col_orders' => 'Orders',
    'grid_col_spend' => 'Spend',
    'grid_filter_all' => 'all',
    'grid_billing' => 'Billing',
    'grid_empty' => 'No companies yet',
    'grid_no_matches' => 'Nothing matches that filter',
    'grid_of' => 'of',
    'grid_companies' => 'companies',
    'grid_mixed_cur' => 'mixed currencies',
];
