<?php
return [
    // HTTP errors
    'missing_company'   => 'Missing company',
    'company_not_found' => 'Company not found',

    // Validation errors
    'invalid_csrf'      => 'Invalid CSRF token',
    'invalid_proof'     => 'Invalid proof type',
    'upload_cr'         => 'Please upload your CR document (PDF/JPG/PNG, up to 5MB)',
    'bad_file'          => 'Proof file must be PDF/JPG/PNG under 5MB',
    'save_failed'       => 'Could not save proof file. Check back later.',

    // Page head
    'page_title'        => 'Claim :name, Logo Library',
    'page_description'  => 'Verify ownership of :name to unlock logo downloads.',

    // Back link
    'back_to_company'   => 'Back to :name',

    // Header banner
    'badge'             => 'Claim, Verify ownership',
    'h1'                => 'Claim :name',
    'subtitle'          => 'Verify you represent this company to unlock logo downloads and profile management.',

    // Success (auto-verified)
    'auto_h2'           => 'Verified instantly',
    'auto_sub'          => 'Your company domain matched. The logo is now verified and downloadable.',
    'auto_back_profile' => 'Back to profile',
    'auto_order_cards'  => 'Order business cards for your team',

    // Success (queued for review)
    'queued_h2'         => 'Claim submitted',
    'queued_sub'        => "Your claim is in our review queue. We'll respond within 48 hours.",
    'queued_back'       => 'Back to profile',

    // Form labels
    'proof_type_label'     => 'Proof type',
    'proof_email_option'   => 'Company email (:email), fastest, auto-verifies if domain matches',
    'proof_cr_option'      => 'Upload CR document (PDF/image)',
    'proof_dns_option'     => 'Add DNS TXT record (instructions via email)',
    'proof_other_option'   => 'Other (describe in note)',

    'proof_file_label'     => 'Proof file (PDF/JPG/PNG, up to 5MB)',

    'role_label'           => 'Your role at the company',
    'role_placeholder'     => '-',
    'role_owner'           => 'Owner',
    'role_ceo'             => 'CEO / Managing Director',
    'role_marketing'       => 'Marketing',
    'role_admin'           => 'Admin',
    'role_legal'           => 'Legal',
    'role_other'           => 'Other',

    'note_label'           => 'Note (optional)',

    'submit'               => 'Submit claim',
    'cancel'               => 'Cancel',
];
