<?php
return [
    // Meta
    'page_title' => 'Oman Business Index 2026: 2,414 Largest Enterprises | Cardify',
    'page_desc'  => 'Free public directory of the :count largest & medium enterprises in Oman, classified by sector and governorate, sourced from MoCIIP.',

    // Hero
    'hero_badge'     => 'Research Report &middot; Edition 2026',
    'hero_h1_line1'  => 'Oman Business Index 2026:',
    'hero_h1_line2'  => 'The :count Largest Enterprises',
    'hero_h1_suffix' => 'in the Sultanate',
    'hero_sub'       => 'A free, bilingual, public directory of large and medium-sized enterprises registered in Oman, sourced from the MoCIIP public register and classified by sector, governorate, and scale.',
    'hero_last_upd'  => 'Last updated',
    'hero_langs'     => 'EN &middot; AR',
    'hero_cta_search' => 'Search the full index',
    'hero_cta_cite'   => 'Cite this index',
    'hero_cta_methodology' => 'Methodology &rarr;',
    'ar_banner'      => 'The deep analysis on this page (executive summary, methodology, key findings) is currently in English. For a full bilingual browse of each company, open the interactive directory where names, activities, and data appear in both languages.',
    'ar_banner_link' => 'open the interactive directory',

    // Key stats grid
    'stat_total'       => 'Total enterprises',
    'stat_large'       => 'Large enterprises',
    'stat_medium'      => 'Medium enterprises',
    'stat_of_total'    => 'of total',
    'stat_sectors'     => 'Sectors',
    'stat_governorates'=> 'Governorates',

    // Sections
    'section'          => 'Section',
    'exec_summary'     => 'Executive Summary',
    'exec_p1'          => 'The Sultanate of Oman is in the middle of the most consequential economic transformation of its modern history. Oman Vision 2040 has set a national target to raise the private sector\'s contribution to GDP, diversify away from hydrocarbons, and build a globally-competitive, knowledge-based economy. Private enterprise, not government, is the engine that must deliver this outcome. Yet the country has never had a free, public, machine-readable list of the enterprises that are actually doing the work.',
    'exec_p2'          => 'The <strong>Oman Business Index 2026</strong> is an attempt to close that gap. It catalogues <strong>:total</strong> Omani enterprises, the <strong>:large</strong> businesses registered at the "large" scale band on the MoCIIP public register plus <strong>:medium</strong> at the "medium" scale band, and organises them into :sectors canonical sectors across all :wilayats governorates of the Sultanate. Every record carries the company\'s Arabic and English legal names, its sector classification, its registered governorate, and its scale band.',
    'exec_p3'          => 'The index is not a ranking. It makes no claim about revenue, employment, or market share, the MoCIIP register does not publish those figures. Instead, it is a <em>structural map</em>: a plain-language answer to the question "which substantial companies operate in Oman, and where?". That map is the foundation on which journalists, analysts, policy researchers, investors, and the companies themselves can build sharper conclusions. Publishing it openly, bilingually, permanently crawlable, and free, is Cardify\'s contribution to the digital backbone of Oman\'s private-sector ecosystem.',

    'methodology'      => 'Methodology',
    'meth_p1'          => '<strong>Data source.</strong> The base list is the public enterprise register maintained by the Ministry of Commerce, Industry and Investment Promotion (MoCIIP) of the Sultanate of Oman. The register groups registered enterprises into four scale bands, micro, small, medium, large, using the official Omani thresholds on registered capital and declared headcount. The Oman Business Index includes only the "large" and "medium" bands; micro and small are excluded for signal-to-noise reasons.',
    'meth_p2'          => '<strong>Enrichment.</strong> Two classification layers are added on top of the raw register. First, every record is mapped to one of :sectors canonical sectors (Oil &amp; Gas, Construction, Finance &amp; Banking, Manufacturing, and so on). Sector assignment uses a rules-based classifier over the company\'s registered commercial activity, validated by hand on the largest 100 records. Second, every record is mapped to one of :wilayats Omani governorates based on the primary registered address. Both the sector taxonomy and the governorate list are published on the dataset page and are stable between index updates.',
    'meth_p3'          => '<strong>Update cadence.</strong> The index refreshes on a rolling quarterly basis against the live MoCIIP register. New registrations, band re-classifications, dissolutions, and English name normalisations are reconciled at each refresh. The timestamp at the top of this page reflects the most recent verification pass.',
    'meth_p4'          => '<strong>Disclaimers.</strong> This index is independent research published by Cardify. It is <em>not</em> endorsed by, affiliated with, or produced in cooperation with MoCIIP or any Omani government entity. The underlying data is public-record information used factually. Classifications (sector, governorate, scale) reflect the state of the register at the verification date; companies reorganise, rename, and re-register continuously. Cardify operates a takedown and correction policy: any listed enterprise may request an edit or removal via the contact form, and we act on verified requests within five working days.',

    'key_findings'     => 'Key Findings',
    'key_findings_sub' => 'All figures below are computed live against the :total-record dataset at the time this page is served. They are not pre-rendered; they reflect the current state of the index.',

    'finding01_title'  => 'Five sectors hold most of the registered mass',
    'finding01_body'   => 'The top five sectors by enterprise count concentrate the majority of Oman\'s large and medium businesses.',
    'finding01_unavail' => 'Sector breakdown temporarily unavailable.',

    'finding02_title'  => 'Muscat remains the overwhelming centre of gravity',
    'finding02_body'   => 'Enterprise registrations concentrate heavily in the capital governorate, with regional centres trailing well behind.',
    'finding02_unavail' => 'Governorate breakdown temporarily unavailable.',

    'finding03_title'  => 'Medium enterprises outnumber large, by a wide margin',
    'finding03_body'   => 'Of the :total indexed enterprises, <strong>:large (:largePct%)</strong> fall in the "large" band and <strong>:medium (:mediumPct%)</strong> in the "medium" band. Medium-scale employers dominate, a pattern consistent with Oman\'s stated Vision 2040 emphasis on SME growth as a diversification lever.',
    'finding03_large'  => 'Large',
    'finding03_medium' => 'Medium',

    'finding04_title'  => 'The thinnest sector signals the diversification frontier',
    'finding04_body'   => '<strong>:sector</strong> is the smallest non-"other" sector in the index with just <strong>:count</strong> registered large-or-medium enterprise:plural. That thinness is less a judgment and more a hint: it marks segments of the Omani economy where the private-sector footprint is still forming, and where Vision 2040\'s diversification mandate has the most white space to work with.',
    'finding04_browse' => 'Browse :sector enterprises',
    'finding04_unavail' => 'Sector size distribution temporarily unavailable.',

    'finding05_title'  => 'Nationwide coverage, every governorate is represented',
    'finding05_body'   => 'The index carries registered enterprises in all :wilayats governorates of the Sultanate. From the Musandam peninsula in the far north to Dhofar\'s frankincense coast in the south and the empty quarter of Al Wusta in between, the index is a full-country map of where substantive commercial activity is licensed. This is the first time, to our knowledge, that a dataset of this shape has been published freely.',

    'top10_heading'    => 'Top 10 Flagship Enterprises',
    'top10_sub'        => 'A curated shortlist of ten flagship Omani enterprises, organisations that anchor the country\'s hydrocarbon, industrial, and financial backbone. Order is editorial and does not imply revenue ranking.',
    'top10_fulllist'   => 'See the full ranked list at',
    'top10_fulllist_suffix' => ', searchable by name, sector, and governorate.',
    'top10_unavail'    => 'Flagship list temporarily unavailable. See',

    'by_sector'        => 'Explore by Sector',
    'by_sector_sub'    => 'Every enterprise in the index is assigned a canonical sector. Click any sector to view the full list of registered large and medium enterprises operating in that space.',
    'sector_count_suffix' => 'companies',

    'by_gov'           => 'Explore by Governorate',
    'by_gov_sub'       => 'Every enterprise is mapped to one of the :count governorates of the Sultanate based on its registered primary address. Click any governorate to open the regional listing.',

    'search_heading'   => 'Search the Full Index',
    'search_sub'       => 'All :total enterprises are fully searchable, in English and Arabic, with filters by sector and governorate, and paginated listings. Every company has its own permanent URL.',
    'search_cta'       => 'Open the directory',
    'search_chk_bilingual' => 'Bilingual EN/AR',
    'search_chk_sector'    => 'Sector filter',
    'search_chk_gov'       => 'Governorate filter',
    'search_chk_permalink' => 'Permalink per company',

    'cite_heading'     => 'Cite This Index',
    'cite_sub'         => 'Journalists, analysts, and researchers are welcome, and encouraged, to cite the Oman Business Index. The dataset is published under a Creative Commons Attribution 4.0 International licence: reuse aggregate figures freely, with attribution.',
    'cite_short_label' => 'Short citation',
    'cite_apa_label'   => 'APA style',
    'cite_copy'        => 'Copy',
    'cite_contact'     => 'For data-licensing queries, bulk data access, or press enquiries, contact',

    'about_cardify'    => 'About Cardify',
    'about_cardify_body' => '<strong>Cardify</strong> is the Sultanate\'s digital business-card platform, built in Oman, for Oman. We help companies issue beautiful, on-brand digital cards to every employee, and print physical cards on demand through a nationwide print-shop network. We publish the Oman Business Index as a public good: accurate company data is oxygen for professional networking, and a thriving networking ecosystem is one of the quiet compounding forces behind Vision 2040\'s private-sector ambition. If the index is useful to you, the product is likely useful to your team.',
    'about_cta_create' => 'Create a free card',
    'about_cta_more'   => 'More about Cardify',

    'faq_heading'      => 'Frequently Asked Questions',
    'faq_footer'       => 'Still have questions? Email',

    // FAQ items
    'faq1_q' => 'How was the Oman Business Index compiled?',
    'faq1_a' => 'The index is built from the public register of the Ministry of Commerce, Industry and Investment Promotion (MoCIIP) of the Sultanate of Oman. Cardify extracted registered enterprises classified as "large" or "medium" in the official scale bands, then enriched each record with a sector label (one of :sectors canonical categories) and a governorate detected from the registered address. All names are preserved in their original Arabic and English forms.',
    'faq2_q' => 'Is my company listed?',
    'faq2_a' => 'If your enterprise is registered in Oman and classified as large or medium on the MoCIIP scale, it is almost certainly in the index. Use the search at /companies to find your company by English or Arabic name. If it is missing and you believe it should be listed, please contact us.',
    'faq3_q' => 'How do I request an edit or removal?',
    'faq3_a' => 'Each company profile has a "Request edit / takedown" link in the footer. You can also email us at info@cardify.om with supporting documentation (commercial registration number, authorized signatory confirmation). We review and act on takedown requests within five working days.',
    'faq4_q' => 'Is this an official government publication?',
    'faq4_a' => 'No. The Oman Business Index is an independent research product published by Cardify. It uses public register data factually and is not endorsed by, affiliated with, or produced in cooperation with MoCIIP or any Omani government entity. The data is presented for research, journalism, and professional networking use.',
    'faq5_q' => 'Can I cite this index in articles or reports?',
    'faq5_a' => 'Yes, citations are welcome and encouraged. See the "Cite This Index" section above for the suggested citation format. The dataset is published under a Creative Commons BY 4.0 license; you may reuse aggregate figures with attribution to "Cardify Oman Business Index 2026".',
    'faq6_q' => 'Is there an Arabic version?',
    'faq6_a' => 'Every company profile already includes the Arabic legal name alongside the English name. A fully Arabic-localised version of the index landing page is in active development and will be published at /ar/oman-business-index.',
    'faq7_q' => 'When will the index be updated?',
    'faq7_a' => 'The index refreshes on a rolling quarterly cadence against the live MoCIIP public register. The "Last updated" date at the top of this page reflects the most recent verification pass. Material changes (newly licensed enterprises, re-classifications, dissolutions) are reconciled between updates.',
    'faq8_q' => 'Why did Cardify publish this?',
    'faq8_a' => 'Cardify is a business-card platform built in Oman, for Oman. We rely on accurate public company data every day to help professionals network. Publishing the index back to the community, bilingual, free, and search-engine indexable, strengthens the digital backbone of Oman\'s private sector and directly supports Vision 2040\'s economic diversification and digital transformation priorities.',
    'faq9_q' => 'Where can I download Omani company logos?',
    'faq9_a' => 'The companion Omani Logo Library at cardify.om/logos hosts downloadable brand marks (SVG + PNG) for companies covered in this index, spanning ministries, sovereign entities, banks, logistics, retail, and more. Logos are indexed for identification and reference; brand owners can claim and verify their profiles, and request takedowns through the takedown form.',

    // GCC upsell
    'gcc_eyebrow'   => 'Part of a larger federation',
    'gcc_heading'   => 'GCC Business Index 2026',
    'gcc_body'      => 'Oman is the first country fully indexed. Saudi Arabia, UAE, Qatar, Bahrain, and Kuwait are rolling out through 2026 under the same methodology.',
    'gcc_cta'       => 'Open GCC index',

    // Logo library companion
    'lib_eyebrow'   => 'Companion archive',
    'lib_heading'   => 'The Omani Logo Library',
    'lib_body'      => 'Every company in this index can have a logo. :count+ Omani brand marks are already indexed, ministries, sovereign entities, banks, logistics, retail, and more. Search, filter by sector, and download SVG / PNG for identification and reference use.',
    'lib_cta'       => 'Open the Logo Library',
    'lib_browse'    => 'Browse by sector',
    'lib_why_title' => 'Why a logo library?',
    'lib_why_body'  => 'Research, journalism, pitch decks, dashboards, and analyst reports all need Omani brand marks at the right resolution. This archive centralizes them.',
    'lib_free_title'=> 'Is it free?',
    'lib_free_body' => 'Yes. Browsing and downloading indexed + verified logos is free. Marks remain trademarks of their owners; use is permitted for identification under nominative fair-use.',
    'lib_td_title'  => 'Takedown-ready',
    'lib_td_body_prefix' => 'Brand owners can',
    'lib_td_request'     => 'request removal',
    'lib_td_body_suffix' => '. We acknowledge within 48 hours and hide within 24 hours of valid requests.',

    // Logo sector shortcut labels
    'sc_government'   => 'Government',
    'sc_finance'      => 'Banks & Finance',
    'sc_logistics'    => 'Logistics & Shipping',
    'sc_oil_gas'      => 'Oil & Gas',
    'sc_healthcare'   => 'Healthcare',
    'sc_education'    => 'Education',
    'sc_telecom'      => 'Telecom',
    'sc_food_bev'     => 'Food & Beverage',
    'sc_hospitality'  => 'Hospitality',
    'sc_retail'       => 'Retail',
    'sc_manufacturing'=> 'Manufacturing',
    'sc_real_estate'  => 'Real Estate',

    // Footer meta
    'last_verified' => 'Last verified',
    'source_label'  => 'Source: MoCIIP public register',
    'request_edit'  => 'Request edit / takedown',
];
