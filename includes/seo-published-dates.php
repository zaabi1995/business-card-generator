<?php
/**
 * First-publication dates for pages that carry Article schema.
 *
 * r6-88: the 20 /solutions/* Article nodes shipped with no datePublished, so
 * every one of them was undateable to a model. The value is not invented here:
 * each entry is the authoring date of the commit that added the file, taken
 * from `git log --diff-filter=A --format=%aI -1 -- <file>`. dateModified is NOT
 * listed, it comes from the file's own mtime at render time so an edit updates
 * the freshness signal without anyone having to remember to.
 *
 * Keys are repo-relative paths. A page with no entry emits no datePublished
 * rather than a guess.
 */
return [
    'solutions/bilingual-arabic-english-business-cards.php'        => '2026-04-16',
    'solutions/business-cards-duqm-free-zone.php'                  => '2026-04-16',
    'solutions/business-cards-for-oman-trade-fairs.php'            => '2026-04-16',
    'solutions/business-cards-for-ramadan-networking.php'          => '2026-04-16',
    'solutions/business-cards-muscat-doctors-clinics.php'          => '2026-04-16',
    'solutions/business-cards-oman-bank-employees.php'             => '2026-04-16',
    'solutions/business-cards-oman-construction-companies.php'     => '2026-04-16',
    'solutions/business-cards-oman-freelancers-consultants.php'    => '2026-04-16',
    'solutions/business-cards-oman-government-employees.php'       => '2026-04-16',
    'solutions/business-cards-oman-omanisation.php'                => '2026-04-16',
    'solutions/business-cards-oman-startups.php'                   => '2026-04-16',
    'solutions/business-cards-omani-law-firms.php'                 => '2026-04-16',
    'solutions/digital-business-cards-oil-gas-oman.php'            => '2026-04-16',
    'solutions/digital-business-cards-oman-hotels.php'             => '2026-04-16',
    'solutions/digital-business-cards-oman-sales-teams.php'        => '2026-04-16',
    'solutions/digital-business-cards-sohar-industrial-port.php'   => '2026-04-16',
    'solutions/digital-cards-oman-real-estate-agents.php'          => '2026-04-16',
    'solutions/nfc-business-cards-oman-executives.php'             => '2026-04-16',
    'solutions/qr-code-menu-muscat-restaurants.php'                => '2026-04-16',
    'solutions/salalah-tourism-business-cards.php'                 => '2026-04-16',
    // r328, first published 20 Aug 2026. Head-term, comparison and glossary
    // clusters. Same rule as the rows above: this is the authoring date of
    // the commit that added the file, not a guess and not a backdate.
    'digital-business-card.php'                            => '2026-08-20',
    'nfc-business-card.php'                                => '2026-08-20',
    'virtual-business-card.php'                            => '2026-08-20',
    'compare/index.php'                                    => '2026-08-20',
    'compare/cardify-vs-popl.php'                          => '2026-08-20',
    'compare/cardify-vs-blinq.php'                         => '2026-08-20',
    'compare/cardify-vs-hihello.php'                       => '2026-08-20',
    'compare/best-digital-business-card-gcc.php'           => '2026-08-20',
    'glossary/index.php'                                   => '2026-08-20',
    'glossary/term.php'                                    => '2026-08-20',
];
