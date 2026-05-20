<?php
return [
    'page_title'           => 'Employees',
    'free_plan_notice'     => 'Card previews shown in preview quality. Printed cards will be high quality.',
    'upgrade_for_hd'       => 'HD card generation',
    'team_members_count'   => ':n team members',

    'generate_all'         => 'Generate all (:n)',
    'generate_all_tooltip' => "Generate cards for all employees who don't have one yet",
    'bulk_regenerate'      => 'Bulk regenerate',
    'bulk_regenerate_tooltip' => 'Regenerate cards for multiple employees',
    'export_csv'           => 'Export CSV',
    'export_excel'         => 'Export Excel',
    'import_csv'           => 'Import CSV',
    'add_employee'         => 'Add employee',

    'search_placeholder'   => 'Search by name or email...',
    'all_departments'      => 'All departments',

    // Table columns
    'col_employee'         => 'Employee',
    'col_position'         => 'Position',
    'col_department'       => 'Department',
    'col_card_preview'     => 'Card preview',
    'col_qr_scans'         => 'QR scans',
    'col_actions'          => 'Actions',

    // Empty state
    'no_employees'         => 'No employees yet',
    'no_employees_body'    => 'Add yourself first to get your digital business card, then add your team.',
    'add_myself_first'     => 'Add myself first',
    'add_an_employee'      => 'Add an employee',

    // Row-level
    'no_card_generated'    => 'No card generated yet',
    'no_cards_generated'   => 'No cards generated',
    'no_print_orders'      => 'No print orders',
    'dark_mode_hint'       => 'Allow visitors to toggle theme',

    // Import modal
    'import_title'             => 'Import from CSV/Excel',
    'import_need_template'     => 'Need a template?',
    'import_template_sub'      => 'Download a sample CSV with the correct format',
    'import_download'          => 'Download',
    'import_select_file'       => 'Select File',
    'import_skip_duplicates'   => 'Skip duplicates',
    'import_skip_duplicates_hint' => "If an employee with the same email exists, skip (don't update)",
    'import_auto_arabic'       => 'Auto-convert Arabic numerals',
    'import_auto_arabic_hint'  => 'Automatically convert phone/mobile to Arabic numerals (٠١٢٣٤٥٦٧٨٩)',
    'import_expected_cols'     => 'Expected Columns',
    'import_expected_cols_hint'=> 'Your file should have these column headers:',
    'import_cols_note'         => '<strong>Note:</strong> phone_ar and mobile_ar are optional, they will be auto-generated if enabled above.',
    'import_cancel'            => 'Cancel',
    'import_submit'            => 'Import',

    // Detail modal stats
    'stat_card_versions'   => 'Card Versions',
    'stat_cards_printed'   => 'Cards Printed',
    'stat_qr_scans'        => 'QR Scans',
    'stat_print_orders'    => 'Print Orders',
    'current_card'         => 'Current Business Card',
    'copy_link_title'      => 'Copy edit link',
    'edit_invite_title'    => 'Re-send edit link',
    'edit_invite_confirm'  => 'Send a fresh edit-link invite to this employee?',
    'edit_invite_sent'     => 'Edit link sent via :channels.',
    'edit_invite_failed'   => 'Could not send the edit link. Check WhatsApp and email settings.',
    'send_edit_link_all'   => 'Send edit link to all (:n)',
    'bulk_invites_tooltip' => 'WhatsApp + email each employee a personal link to update their card details.',
    'bulk_invites_confirm' => 'Send a personal edit-link to all :n active employees? They will receive a WhatsApp message and email with a link to update their card.',
    'bulk_invites_result'  => 'Sent :sent invite(s). Skipped :skipped.',
];
