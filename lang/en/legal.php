<?php
/**
 * Shared Cardify legal copy (Terms + Privacy). Two top-level arrays
 * keyed `terms` and `privacy`, each a list of ordered sections where
 * every section has a `h` heading + one of (`p` paragraph or `list`
 * array of bullet items), plus optional trailing entries.
 */
return [
    'last_updated'   => 'Last updated',
    'back_home'      => 'Back to home',

    'terms' => [
        'page_title'     => 'Terms of Service, Cardify',
        'page_desc'      => 'Terms and conditions for using Cardify, the business card platform run by BHD Group in the Sultanate of Oman.',
        'h1'             => 'Terms of Service',
        'sections' => [
            [
                'h' => 'Agreement to terms',
                'p' => 'By accessing or using Cardify, a service provided by BHD Group (Bin Haider Darwish LLC, C.R. No. 1334733), you agree to these Terms of Service. If you do not agree, please do not use the service.',
            ],
            [
                'h' => 'Description of service',
                'p' => 'Cardify is a digital and printed business card platform. The service includes card design tools, bilingual templates, digital sharing, QR and NFC-ready cards, team management, analytics and an optional print marketplace.',
            ],
            [
                'h' => 'User accounts',
                'list' => [
                    'You must provide accurate and complete registration information.',
                    'You are responsible for the security of your account and any activity under it.',
                    'You must notify us immediately of any unauthorised use.',
                    'You may not use another person’s account without permission.',
                    'We may suspend or terminate accounts that violate these terms.',
                ],
            ],
            [
                'h' => 'Acceptable use',
                'p' => 'You agree not to:',
                'list' => [
                    'Use the service for any unlawful purpose or in a way that violates the laws of Oman.',
                    'Upload content that infringes intellectual property or personal rights.',
                    'Transmit malware, viruses or harmful code.',
                    'Attempt to gain unauthorised access to our systems or other accounts.',
                    'Interfere with or disrupt the service or its infrastructure.',
                    'Impersonate any person or entity or misrepresent your affiliation.',
                    'Send spam, unsolicited marketing or phishing through the platform.',
                ],
            ],
            [
                'h' => 'Intellectual property',
                'p' => 'The Cardify platform, including its design, code and content, belongs to BHD Group and is protected by intellectual-property law. You keep ownership of the content you create, but grant us a limited licence to host, render and print it for the purpose of delivering the service to you.',
            ],
            [
                'h' => 'Payment terms',
                'list' => [
                    'The Cardify platform is provided free of charge for unlimited employees, templates and digital cards.',
                    'Print orders are charged per order in Omani Rial (OMR) based on the price you see at checkout, set by the verified print shop you select.',
                    'Print fees are non-refundable once the order has been sent to production, except where required by law or stated in writing.',
                    'We may change print pricing or product specifications on 30 days notice; changes do not apply to orders already paid.',
                    'Unpaid balances on credit accounts (where granted) may result in suspension of new print orders until settled.',
                ],
            ],
            [
                'h' => 'Limitation of liability',
                'p' => 'To the maximum extent allowed by Omani law, Cardify and BHD Group are not liable for indirect, incidental, special or consequential losses, including loss of profit, data or goodwill, arising from your use of the service. Our total liability for any claim is capped at the amount you paid Cardify in the 12 months preceding the claim.',
            ],
            [
                'h' => 'Disclaimer of warranties',
                'p' => 'The service is provided "as is" and "as available" without warranties of any kind, express or implied, including merchantability, fitness for a particular purpose, and non-infringement, except where warranties are implied by Omani consumer law and cannot be disclaimed.',
            ],
            [
                'h' => 'Termination',
                'p' => 'You may close your account at any time through the admin settings. We may suspend or terminate your account if you breach these terms, if required by law, or if your account is inactive for 24 months. On termination, your right to use the service ends and we retain data only as described in the Privacy Policy.',
            ],
            [
                'h' => 'Governing law',
                'p' => 'These terms are governed by the laws of the Sultanate of Oman. Any dispute will be resolved in the courts of Muscat.',
            ],
            [
                'h' => 'Contact',
                'p' => 'For questions about these terms, contact BHD Group (Bin Haider Darwish LLC, C.R. No. 1334733) via the Cardify contact page.',
            ],
        ],
    ],

    'privacy' => [
        'page_title'     => 'Privacy Policy, Cardify',
        'page_desc'      => 'How Cardify (BHD Group) collects, uses, stores and protects your data. Compliant with Oman PDPL and GDPR-style requests.',
        'h1'             => 'Privacy Policy',
        'sections' => [
            [
                'h' => 'Introduction',
                'p' => 'Cardify ("we", "our", "us"), a service of BHD Group (Bin Haider Darwish LLC, C.R. No. 1334733), takes your privacy seriously. This policy explains what data we collect, why, how we store and protect it, and what rights you have. It is aligned with Oman’s Personal Data Protection Law (PDPL) and respects GDPR-style requests from international users.',
            ],
            [
                'h' => 'Information we collect',
                'p' => 'We collect only what is needed to deliver the service:',
                'list' => [
                    'Identity and contact: name, email, phone, company, job title.',
                    'Card content: the text, photos, logos and design choices you put on your cards.',
                    'Payment data: billing address and the last four digits of your card. We never see or store full card numbers; Paymob handles those.',
                    'Device and usage: IP address, browser, OS, pages viewed, actions taken, all used to secure the service and improve it.',
                    'Cookies: a minimal session cookie (required) and optional analytics cookies that you can decline.',
                ],
            ],
            [
                'h' => 'How we use your data',
                'list' => [
                    'Create and deliver your digital and printed cards.',
                    'Authenticate you and protect your account.',
                    'Process payments and issue invoices in OMR.',
                    'Respond to support, sales and partnership inquiries.',
                    'Improve the product (aggregate analytics, no individual profiling).',
                    'Comply with Omani law, tax reporting and court orders.',
                ],
            ],
            [
                'h' => 'Who we share data with',
                'list' => [
                    'Print partners (BHD Printing & Designing and marketplace shops) receive only the card design and shipping address, nothing more.',
                    'Payment processor (Paymob) to settle OMR transactions under Oman Central Bank rules.',
                    'ERP (internal to BHD Group) for invoicing and accounting.',
                    'Hosting (Hostinger KVM, Muscat region) for storage and uptime.',
                    'Legal authorities when required by Omani law or a court order.',
                ],
            ],
            [
                'h' => 'Data retention',
                'p' => 'We keep account data for as long as your account is active, plus 24 months after closure for tax and dispute retention. Print order records are kept for 7 years to comply with Omani commercial-book requirements. You can request earlier deletion and we will honour it except where law requires us to keep specific records.',
            ],
            [
                'h' => 'Data security',
                'list' => [
                    'TLS 1.2+ on every request, HSTS enforced.',
                    'Passwords hashed with bcrypt, never stored in plain text.',
                    'Session cookies marked Secure, HttpOnly, SameSite=Lax.',
                    'Audit-log table is append-only at the database level.',
                    'Regular nightly backups, stored encrypted off-box.',
                ],
            ],
            [
                'h' => 'Your rights',
                'p' => 'You can always:',
                'list' => [
                    'Access a copy of your personal data (self-service export from the admin portal).',
                    'Correct inaccurate data by editing your profile or contacting us.',
                    'Request deletion of your account and associated data.',
                    'Object to specific processing, such as analytics cookies.',
                    'Withdraw consent for marketing emails at any time via the unsubscribe link.',
                    'Lodge a complaint with Oman’s Ministry of Transport, Communications and Information Technology if you believe we have mishandled your data.',
                ],
            ],
            [
                'h' => 'International transfers',
                'p' => 'Our servers are in Oman. Some processors (Paymob for payments, Google for fonts, Hostinger for infrastructure) may process data in their own regions under contractual safeguards. We do not sell your data to anyone.',
            ],
            [
                'h' => 'Changes to this policy',
                'p' => 'We may update this policy to reflect new features or legal changes. If a change is material, we will notify you by email or in-product banner before it takes effect, and keep the prior version available on request.',
            ],
            [
                'h' => 'Contact',
                'p' => 'Questions about this policy, or to exercise any of your rights, email privacy@cardify.om or use the Cardify contact page.',
            ],
        ],
    ],
];
