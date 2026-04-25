<?php
return [
    // Flash messages
    'invalid_csrf'         => 'Invalid CSRF token',
    'domain_added'         => 'Domain added. Set up DNS below, then click Verify.',
    'domain_add_failed'    => 'Failed to add domain',
    'verified_prefix'      => 'Verified: :msg',
    'not_verified_prefix'  => 'Not yet verified: :msg',
    'domain_removed'       => 'Domain removed',
    'ssl_status_updated'   => 'SSL status updated',

    // Header
    'page_h1'              => 'Custom Domains',
    'page_sub'             => "Point your own domain at any employee's digital card.",
    'pro_enabled'          => 'Custom domains enabled',
    'pro_required'         => 'Custom domains available',

    // Legacy upgrade gate (HD/custom domains are free for every team since the Apr 2026 reset).
    'gate_h2'              => 'Custom Domains',
    'gate_body'            => 'Serve your team\'s digital cards from your own domain, for example <code>ceo.acme.com</code>. Free for every Cardify team.',
    'gate_cta'             => 'Add a domain',

    // Add form
    'add_h2'               => 'Add a custom domain',
    'field_employee'       => 'Employee',
    'select_employee_ph'   => 'Select employee...',
    'field_domain'         => 'Domain',
    'add_btn'              => 'Add domain',
    'add_note'             => 'After adding, set up DNS exactly as shown below, then click <strong>Verify</strong>. We issue SSL certificates by hand for now, so contact support once your domain is verified.',

    // Domains list
    'list_h2'              => 'Your domains',
    'empty'                => 'No custom domains yet. Add one above.',
    'col_domain'           => 'Domain',
    'col_employee'         => 'Employee',
    'col_dns'              => 'DNS',
    'col_ssl'              => 'SSL',
    'col_actions'          => 'Actions',
    'status_verified'      => 'Verified',
    'status_pending'       => 'Pending',
    'ssl_active'           => 'Active',
    'ssl_failed'           => 'Failed',
    'btn_verify'           => 'Verify',
    'btn_delete'           => 'Delete',
    'confirm_remove'       => 'Remove this custom domain?',

    // DNS instructions (plain English)
    'dns_setup_label'      => 'DNS setup:',
    'dns_instruction'      => 'In your domain provider (e.g. GoDaddy, Cloudflare, Namecheap), open DNS settings and add a <strong>CNAME record</strong> for <code>:host</code> pointing to <code class="px-1.5 py-0.5 bg-white border border-gray-200 rounded">cardify.om</code>. Save, wait 5 to 60 minutes for it to propagate, then click Verify above.',
    'copy'                 => 'copy',

    // SSL panel (plain English)
    'ssl_panel_title'      => 'How SSL works right now',
    'ssl_panel_body'       => "Your digital card needs an SSL certificate (the padlock icon in browsers) so visitors connect securely. For now we issue these by hand: once your DNS above shows <strong>Verified</strong>, email <a href=\"mailto:support@cardify.om\" class=\"underline\">support@cardify.om</a> and we'll turn on SSL within 24 hours. Automatic SSL is coming soon.",
];
