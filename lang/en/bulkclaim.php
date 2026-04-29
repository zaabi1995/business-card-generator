<?php
return [
    // Flash + errors
    'invalid_csrf'        => 'Invalid CSRF token',
    'parse_failed'        => 'Could not parse any rows. Expected header row with: name, phone, company_name, title, email.',
    'batch_too_large'     => 'Batch too large (:n rows). Max :max per batch, split the CSV.',
    'invalid_batch'       => 'Invalid or oversized batch. Re-upload CSV and preview first.',
    'company_not_found'   => 'Selected company not found.',
    'dispatched_summary'  => 'Batch dispatched, created :created, sent :sent WhatsApps, failed :failed, skipped :skipped.',

    // Header
    'page_h1'             => 'Bulk Claim',
    'page_sub'            => 'Pre-build cards for cold leads and send magic-link claims via WhatsApp.',

    // Send results panel
    'send_results'        => 'Send Results',
    'view_dashboard'      => 'View batch dashboard →',
    'sent_label'          => 'Sent',
    'skipped_label'       => 'Skipped (:reason)',
    'failed_label'        => 'Failed',

    // Upload panel
    'step_1'              => '1. Paste or upload CSV',
    'step_1_hint'         => 'Columns: <code class="text-[11px] bg-gray-50 px-1 rounded">name, phone, company_name, title, email</code>. First row must be the header.',
    'field_batch_label'   => 'Batch label (optional)',
    'batch_label_ph'      => 'e.g. Cardify expansion Apr 2026',
    'field_host_company'  => 'Host company (card records will be created under this company)',
    'field_upload'        => 'Upload .csv',
    'field_paste'         => '…or paste CSV',
    'max_leads_note'      => 'Max :max leads per batch.',
    'btn_preview'         => 'Preview',

    // Recent batches panel
    'recent_batches'      => 'Recent batches',
    'no_batches'          => 'No batches yet.',
    'unnamed'             => '(unnamed)',
    'batch_counts'        => ':sent/:total sent · :claimed claimed · :active active',

    // Preview table
    'step_2'              => '2. Preview (:n rows)',
    'count_ready'         => ':n ready',
    'count_duplicate'     => ':n duplicate',
    'count_invalid'       => ':n invalid',
    'confirm_send'        => 'Send :n WhatsApp magic-links now? Duplicates + invalids will be skipped automatically.',
    'btn_send_n'          => 'Send to :n lead',
    'btn_send_n_plural'   => 'Send to :n leads',
    'col_name'            => 'Name',
    'col_phone'           => 'Phone',
    'col_company'         => 'Company',
    'col_title'           => 'Title',
    'col_status'          => 'Status',
    'status_ready'        => 'Ready',
    'status_already_card' => 'Already has a card',
    'status_already_lead' => 'Already contacted',

    // Reasons (skipped rows)
    'reason_missing_name'      => 'missing name',
    'reason_invalid_phone'     => 'invalid phone',
    'reason_already_in_leads'  => 'already contacted',
    'reason_already_has_card'  => 'already has card',
    'reason_employee_insert_failed' => 'employee insert failed',
    'reason_lead_insert_failed'     => 'lead insert failed',
    'reason_wa_send_failed'    => 'WhatsApp send failed',

    // Batch detail dashboard
    'batch_prefix'        => 'Batch: :label',
    'batch_meta'          => 'Created :date · :sent/:total sent · Status: :status',
    'col_wa'              => 'WA',
    'col_opened'          => 'Opened',
    'col_claimed'         => 'Claimed',
    'col_active'          => 'Active',
    'wa_sent'             => 'sent',
    'wa_failed'           => 'failed',
    'wa_pending'          => 'pending',
];
