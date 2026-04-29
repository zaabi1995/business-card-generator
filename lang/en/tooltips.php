<?php
// Shared tooltip / inline-help strings used across admin forms.
// One line per field, plain English, under 120 chars each so the
// .cardify-tip popover never runs more than 3 short lines.
return [
    // Employee form
    'emp_name'           => 'The name shown on the printed card and the employee\'s digital card URL.',
    'emp_email'          => 'Used to send the edit-link invite and the monthly card-engagement summary.',
    'emp_phone'          => 'Shown on the card and used as the tap-to-call number.',
    'emp_mobile'         => 'Optional second phone, usually mobile. Shown below the main phone on the card.',
    'emp_position'       => 'Job title printed under the employee name.',
    'emp_department'     => 'Assigns a design template if the department has its own override.',
    'emp_website'        => 'Company URL on the card; defaults to your main domain if left blank.',
    'emp_photo'          => 'Square image, at least 512x512. PNG or JPG, 5 MB max.',
    'emp_socials'        => 'Add icons to LinkedIn, Instagram, Twitter, or TikTok profiles.',

    // Template form
    'tpl_name'           => 'Internal name only; employees do not see this.',
    'tpl_description'    => 'Helps other admins find the right template.',
    'tpl_industry'       => 'Used to group templates in the gallery and power preset suggestions.',
    'tpl_lock'           => 'Lock the template to prevent employee self-edits from changing the design.',

    // Order checkout
    'ord_qty_per_emp'    => 'Default is 100 cards per employee. Change here to mix bulk + light runs.',
    'ord_rush'           => 'Adds 20% for a 24-hour turnaround when the shop supports it.',
    'ord_referral'       => 'Optional referral code from another Cardify company; earns you credit.',
    'ord_po'             => 'Purchase order document (PDF/JPG/PNG, 5 MB max). Optional.',

    // Credit account
    'credit_limit'       => 'Maximum outstanding amount your print shop allows on this account.',
    'credit_exposure'    => 'Short-term exposure cap, usually lower than the credit limit.',
    'credit_terms'       => 'Payment schedule: net 30 means 30 days from invoice date.',

    // Settings
    'set_portal_enabled' => 'Let employees request cards via a public /{slug}/portal URL.',
    'set_passcode'       => '4-digit code to protect the portal page.',
    'set_custom_domain'  => 'Point your own subdomain at Cardify. DNS instructions shown after add.',

    // Onboarding wizard
    'wiz_primary_color'  => 'Applied to buttons, accents, and the digital card background gradient.',
    'wiz_accent_color'   => 'Paired with the primary color on gradients, link highlights.',
    'wiz_template'       => 'Preview with your brand before committing; you can change it later.',

    // Portal (employee self-edit)
    'portal_edit_saved'  => 'Your changes are saved automatically as you type.',

    // Analytics
    'an_taps'            => 'A tap is one open of your digital card via QR, NFC, or shared link.',
    'an_saves'           => 'Count of visitors who added the employee to their phone contacts.',
    'an_wa_clicks'       => 'Clicks on the WhatsApp button on any employee card.',
    'an_conversion'      => 'Taps that turned into a saved contact or WhatsApp message.',

    // Generic
    'generic_help'       => 'Click for help',
];
