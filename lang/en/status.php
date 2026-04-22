<?php
return [
    'page_title'       => 'Cardify status',
    'page_desc'        => 'Live health of every Cardify component plus recent incidents.',

    'overall_up'       => 'All systems operational',
    'overall_degraded' => 'Partial service disruption',
    'overall_down'     => 'Major outage',
    'overall_sub'      => 'Checks refresh every time this page loads.',

    'comp_app'         => 'Cardify web app',
    'compdesc_app'     => 'Card editor, admin dashboards, public pages.',
    'comp_database'    => 'Database',
    'compdesc_database'=> 'MySQL storing employees, templates and orders.',
    'comp_storage'     => 'File storage',
    'compdesc_storage' => 'Uploaded logos, generated card images, exports.',
    'comp_erp_sync'    => 'BHD-ERP sync',
    'compdesc_erp_sync'=> 'Invoice creation against the group accounting ERP.',
    'comp_paymob'      => 'Paymob payments',
    'compdesc_paymob'  => 'Card + Apple Pay + OmanNet online checkout.',

    'state_up'         => 'Operational',
    'state_degraded'   => 'Degraded',
    'state_down'       => 'Outage',

    'incidents_h'      => 'Recent incidents (30 days)',
    'incidents_empty'  => 'No incidents in the last 30 days. Clean skies.',
    'resolved'         => 'Resolved',
    'open'             => 'Open',
    'sev_minor'        => 'Minor',
    'sev_major'        => 'Major',
    'sev_info'         => 'Info',

    'footer_note'      => 'Machine-readable status is available at :endpoint. External uptime monitors use it too.',
];
