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

    // ---- Page head ----
    'page_title'   => 'Get started with Cardify, free bilingual business cards for your Omani team',
    'page_desc'    => 'Build a real bilingual business card in under a minute, no account needed. Then invite your whole team. Free to design and share, from OMR :standard_price per 100 printed cards.',

    // ---- Hero ----
    'hero_badge_loc'  => 'Made in Oman',
    'hero_badge_copy' => 'Free for unlimited employees',
    'hero_h1'         => 'Your team\'s business cards,',
    'hero_h1_accent'  => 'built in the next two minutes.',
    'hero_sub'        => 'Cardify makes a bilingual digital and printed business card for every person on your team. Arabic and English on the same card, each reading in its own direction. Try it below before you sign up for anything.',
    'hero_cta_try'    => 'Build a card now',
    'hero_cta_signup' => 'Create a free account',
    'hero_signin'     => 'Already have an account?',
    'hero_signin_cta' => 'Sign in',
    'trust_free'      => 'Free until you print',
    'trust_no_card'   => 'No card details',
    'trust_bilingual' => 'Arabic and English',
    'trust_printed'   => 'Printed in Muscat',
    'sample_label'    => 'Sample',
    'sample_note'     => 'A sample card, not a customer.',

    // ---- Price anchor ----
    'price_eyebrow'   => 'What it costs',
    'price_free_h'    => 'The platform',
    'price_free_v'    => 'Free',
    'price_free_b'    => 'Unlimited employees, templates, digital cards, QR shares and analytics. No trial and no expiry.',
    'price_print_h'   => 'Printed cards',
    'price_print_v'   => 'From OMR :standard_price',
    'price_print_u'   => 'per 100 cards',
    'price_print_b'   => 'Ordered from BHD and verified Omani print shops, delivered across the sultanate.',
    'price_nfc_h'     => 'NFC tap cards',
    'price_nfc_v'     => 'OMR :nfc_price',
    'price_nfc_u'     => 'per card',
    'price_nfc_b'     => 'A re-programmable chip with a printed QR on the same card, so every phone can read it.',
    'price_link'      => 'See full pricing',

    // ---- How it works ----
    'how_eyebrow'  => 'How it works',
    'how_h2'       => 'Three steps, and you are done',
    'how_sub'      => 'Most companies go from signup to a shared card in under five minutes.',
    'how_1_h'      => 'Create your company',
    'how_1_b'      => 'Your email and your company name. You get your own address on cardify.om, such as yourcompany.cardify.om.',
    'how_2_h'      => 'Approve one design',
    'how_2_b'      => 'Pick a template, add your logo and colours. Every employee card is generated from that one approved design.',
    'how_3_h'      => 'Share or print',
    'how_3_b'      => 'Send a digital card by QR code or WhatsApp today. Order printed cards from an Omani shop when you are ready.',

    // ---- Feature grid ----
    'feat_eyebrow'  => 'What you get',
    'feat_h2'       => 'Everything a team needs, on the free plan',
    'feat_1_h'      => 'Bilingual by default',
    'feat_1_b'      => 'Arabic and English sit side by side on the same card, each set in its own direction. No designer round trip when a title changes.',
    'feat_2_h'      => 'One design, every employee',
    'feat_2_b'      => 'Approve a template once. Cardify renders a card for each person from their own details, so branding never drifts.',
    'feat_3_h'      => 'QR that you can measure',
    'feat_3_b'      => 'Every card carries a trackable QR code. See how often a card is opened and which ones are working.',
    'feat_4_h'      => 'Wallet and NFC',
    'feat_4_b'      => 'Add a card to Apple Wallet or Google Wallet, or tap an NFC card to open the same profile without an app.',
    'feat_5_h'      => 'Staff edit their own details',
    'feat_5_b'      => 'Each employee gets a portal to fix a phone number or a title. Admins keep control of the design.',
    'feat_6_h'      => 'Printed by BHD in Muscat',
    'feat_6_b'      => 'The digital card and the printed card come from the same place, so what you approve is what is delivered.',

    // ---- Closing ----
    'close_h2'     => 'Start with one card. Add the team when you are ready.',
    'close_b'      => 'The platform stays free. You pay only when you order printed cards.',
    'close_cta'    => 'Create a free account',
    'close_demo'   => 'Talk to us on WhatsApp',
    'close_help'   => 'Questions? Read the :faq or :contact.',
    'close_faq'    => 'FAQ',
    'close_contact'=> 'contact us',

];
