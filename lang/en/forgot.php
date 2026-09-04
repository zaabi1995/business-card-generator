<?php
return [
    'page_title'        => 'Forgot Password',
    'page_desc'    => 'Reset the password for your Cardify company account. We email you a link that expires in one hour.',

    // Flash / errors
    'invalid_csrf'      => 'Invalid request. Please try again.',
    'enter_email'       => 'Please enter your email address',
    'generic_error'     => 'An error occurred. Please try again later.',
    'not_available'     => 'Password reset is not available in file-based mode.',
    'sent_generic'      => "We've sent an email to that address with further instructions.",

    // Success card
    'check_email_h1'    => 'Check your email',
    'check_spam_hint'   => "Didn't receive the email? Check your spam folder or try again.",
    'back_to_sign_in'   => 'Back to sign in',

    // Form card
    'form_h1'           => 'Forgot your password?',
    'form_sub'          => "No worries! Enter your email address and we'll send you instructions to reset your password.",
    'email_label'       => 'Email address',
    'email_placeholder' => 'name@company.com',
    'submit_button'     => 'Send reset link',
    'remember_prompt'   => 'Remember your password?',
    'sign_in_link'      => 'Sign in',
    'back_home'         => 'Back to homepage',

    // "No account" email
    'noacc_subject'      => 'Password Reset Attempted - :site',
    'noacc_h2'           => 'Password Reset Attempted',
    'noacc_hi'           => 'Hi there,',
    'noacc_intro'        => 'Someone (hopefully you) requested a password reset for :email on :site.',
    'noacc_box_title'    => 'No account found',
    'noacc_box_body'     => "We couldn't find an account associated with this email address.",
    'noacc_could_mean'   => 'This could mean:',
    'noacc_reason_1'     => "You haven't registered yet",
    'noacc_reason_2'     => 'You registered with a different email address',
    'noacc_reason_3'     => 'Your account may have been created by your company administrator with a different email',
    'noacc_what_to_do'   => 'What you can do:',
    'noacc_if_admin'     => "If you're a company administrator:",
    'noacc_register_btn' => 'Register Your Company',
    'noacc_if_employee'  => "If you're an employee:",
    'noacc_employee_msg' => 'Contact your company administrator to set up your account or check which email was used for your profile.',
    'noacc_need_help'    => 'Need help?',
    'noacc_contact_msg'  => "If you believe this is an error, please :contactlink and we'll help you sort it out.",
    'noacc_contact_link' => 'contact us',
    'noacc_security'     => 'Security Note:',
    'noacc_security_msg' => "If you didn't request this, you can safely ignore this email. No action is required.",
    'noacc_signoff'      => 'Best regards,',
    'noacc_team'         => 'The :site Team',
];
