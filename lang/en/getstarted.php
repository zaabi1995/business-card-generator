<?php
/**
 * Strings for the get-started landing page's instant-card demo.
 *
 * instant_card.php has been a complete, working endpoint that no page ever
 * called: it mints a real demo card under the `demo` tenant and emails a
 * verify link. Paid traffic landed on a hero and a signup button with nothing
 * to try. This is the demo wired up.
 */
return [
    'demo_eyebrow'     => 'Try it first',
    'demo_h2'          => 'See your own card before you sign up',
    'demo_sub'         => 'Type your details. We build a real card and send you the link. No account, no card details.',

    'demo_name'        => 'Full name',
    'demo_name_ph'     => 'Ali Adnan Haider Darwish',
    'demo_title'       => 'Job title',
    'demo_title_ph'    => 'Managing Director',
    'demo_company'     => 'Company',
    'demo_company_ph'  => 'Your company',
    'demo_email'       => 'Work email',
    'demo_email_ph'    => 'you@company.om',
    'demo_color'       => 'Brand colour',

    'demo_submit'      => 'Build my card',
    'demo_building'    => 'Building your card...',
    'demo_privacy'     => 'We email you the link and nothing else. Your card stays private until you verify it.',

    'demo_done_h3'     => 'Your card is live',
    'demo_done_sub'    => 'We sent the link to your inbox. Open it to claim the card and keep it.',
    'demo_view'        => 'View my card',
    'demo_signup'      => 'Create my free account',

    // Errors, mapped from the endpoint's error slugs.
    'demo_err_generic'       => 'Something went wrong. Please try again.',
    'demo_err_invalid_email' => 'That email does not look right. Please check it.',
    'demo_err_bad_domain'    => 'Please use a work email address.',
    'demo_err_slug_taken'    => 'A card already exists for that email. Check your inbox, or sign in.',
    'demo_err_rate'          => 'That is a few too many tries. Please wait a moment and try again.',
    'demo_err_busy'          => 'We are busy right now. Please try again in a minute.',
];
