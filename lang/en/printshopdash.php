<?php
return [
    // Flash
    'invalid_request'   => 'Invalid request',
    'order_updated'     => 'Order #:id updated to :status',
    'update_error'      => 'Error: :msg',
    'unknown_error'     => 'Unknown',

    // Top nav
    'nav_verified'      => 'Verified',
    'nav_dashboard'     => 'Dashboard',
    'nav_orders'        => 'Orders',
    'nav_analytics'     => 'Analytics',
    'nav_credit'        => 'Credit',
    'nav_settings'      => 'Settings',

    // Status banners
    'pending_approval_h' => 'Pending Approval',
    'pending_approval_b' => "Your print shop is awaiting admin approval. You'll be notified once approved.",
    'suspended_h'       => 'Shop Suspended',
    'suspended_b'       => 'Your print shop has been suspended. Please contact support for more information.',

    // KPI tiles
    'kpi_total_orders'  => 'Total Orders',
    'kpi_pending'       => 'Pending',
    'kpi_completed'     => 'Completed',
    'kpi_revenue'       => 'Revenue',

    // Quick actions
    'qa_view_orders_h'  => 'View All Orders',
    'qa_view_orders_s'  => 'Manage incoming print orders',
    'qa_settings_h'     => 'Shop Settings',
    'qa_settings_s'     => 'Update pricing and services',
    'qa_profile_h'      => 'Shop Profile',
    'qa_profile_s'      => 'Edit public shop information',

    // Recent orders
    'recent_orders_h'   => 'Recent Orders',
    'view_all'          => 'View All',
    'empty_h'           => 'No Orders Yet',
    'empty_s'           => 'Orders from companies will appear here',
    'unknown_company'   => 'Unknown Company',
    'order_meta'        => ':n cards, :paper',

    // Status chips
    'status_pending'    => 'Pending',
    'status_submitted'  => 'Submitted',
    'status_processing' => 'Processing',
    'status_printing'   => 'Printing',
    'status_shipped'    => 'Shipped',
    'status_delivered'  => 'Delivered',
    'status_cancelled'  => 'Cancelled',

    // Quick status update
    'btn_update'        => 'Update',

    // Greeting bar
    'greeting_morning'        => 'Good morning, :name',
    'greeting_afternoon'      => 'Good afternoon, :name',
    'greeting_evening'        => 'Good evening, :name',
    'greeting_today_summary'  => ':n orders today, :rev so far',
    'greeting_today_empty'    => 'No orders yet today',
    'badge_internal_provider' => 'Internal provider',

    // KPI strip
    'kpi_today_h'         => 'Today',
    'kpi_awaiting_h'      => 'Awaiting action',
    'kpi_in_production_h' => 'In production',
    'kpi_shipped_week_h'  => 'Shipped (7d)',
    'kpi_revenue_30d_h'   => 'Revenue (30d)',
    'kpi_outstanding_h'   => 'Outstanding credit',
    'kpi_delta_up'        => '+:pct% vs prior 30d',
    'kpi_delta_down'      => ':pct% vs prior 30d',
    'kpi_delta_flat'      => 'Flat vs prior 30d',
    'kpi_delta_new'       => 'New revenue',

    // Action queue
    'queue_h'                  => 'Action queue',
    'queue_sub'                => 'Orders that need your attention, grouped by stage.',
    'queue_stage_submitted'    => 'Submitted',
    'queue_stage_processing'   => 'Processing',
    'queue_stage_printing'     => 'At press',
    'queue_stage_shipped'      => 'Ready to deliver',
    'queue_count_one'          => '1 order',
    'queue_count_many'         => ':n orders',
    'queue_empty'              => 'Nothing here.',
    'queue_advance_processing' => 'Mark processing',
    'queue_advance_printing'   => 'Send to press',
    'queue_advance_shipped'    => 'Mark shipped',
    'queue_advance_delivered'  => 'Mark delivered',
    'queue_open'               => 'Open',

    // Internal provider panel
    'internal_h'                => 'Clients on your roster',
    'internal_top_clients'      => 'Top 5 by orders, last 30 days',
    'internal_order_on_behalf'  => 'Order on behalf',
    'internal_browse_clients'   => 'Browse all clients',
    'internal_dormant'          => ':n clients have not ordered in 60+ days',
    'internal_active_count'     => ':n active clients in 30d',
    'internal_no_recent'        => 'No client orders in the last 30 days yet.',

    // Credit risk panel
    'credit_risk_h'           => 'Credit exposure',
    'credit_risk_total'       => ':used of :limit used',
    'credit_risk_review'      => 'Review accounts',
    'credit_risk_top_exposed' => 'Highest exposure',
    'credit_risk_no_accounts' => 'No approved credit accounts yet.',
    'credit_risk_terms'       => 'Net :n',

    // Operator activity panel
    'operator_activity_h'      => 'Operator activity (7d)',
    'operator_orders_unit_one' => '1 order',
    'operator_orders_unit'     => ':n orders',
    'operator_no_activity'     => 'No orders yet',
    'operator_view_team'       => 'Manage team',

    // Revenue trend widget
    'revenue_widget_h'      => 'Revenue trend',
    'revenue_widget_sub'    => 'Daily order revenue from your shop',
    'revenue_period_today'  => 'Today',
    'revenue_period_7d'     => 'Last 7 days',
    'revenue_period_30d'    => 'Last 30 days',
    'revenue_full_analytics'=> 'Full analytics',
    'revenue_widget_empty'  => 'Not enough data yet to show a trend.',

    // Recent activity feed
    'activity_h'                  => 'Recent activity',
    'activity_view_all'           => 'View all',
    'activity_empty'              => 'No recent activity yet.',
    'activity_erp_failed'         => 'ERP sync failed for :ref',
    'activity_credit_requested'   => 'Credit requested by :ref',
    'activity_template_requested' => 'Template request from :ref',
    'activity_new_order'          => 'New order :ref',
    'activity_just_now'           => 'just now',
    'activity_minutes_ago'        => ':n min ago',
    'activity_hours_ago'          => ':n h ago',
    'activity_days_ago'           => ':n d ago',
];
