<?php
/**
 * The solutions shelf, defined ONCE.
 *
 * llm47-4: the shelf was a literal inside solutions.php while the homepage CTA
 * ("View all 20 solutions" / "عرض 20 حلاً") carried the count as a typed digit
 * inside two translation files, and /industries typed its own count a third
 * time. Three hand-written numbers describing two shelves is the shape r6-70
 * was closed for: a prose count with no query behind it is right until the day
 * somebody adds a file, and nothing tells them.
 *
 * Both counts are now rendered from the thing they count.
 */
function solutionShelf(): array
{
    return [
    'solutions.cat_industry' => [
        ['url' => 'digital-business-cards-oman-sales-teams',      'title_key' => 'solutions.ind_sales_title',        'desc_key' => 'solutions.ind_sales_desc'],
        ['url' => 'digital-business-cards-oil-gas-oman',          'title_key' => 'solutions.ind_oil_title',          'desc_key' => 'solutions.ind_oil_desc'],
        ['url' => 'business-cards-omani-law-firms',               'title_key' => 'solutions.ind_law_title',          'desc_key' => 'solutions.ind_law_desc'],
        ['url' => 'digital-cards-oman-real-estate-agents',        'title_key' => 'solutions.ind_realestate_title',   'desc_key' => 'solutions.ind_realestate_desc'],
        ['url' => 'business-cards-muscat-doctors-clinics',        'title_key' => 'solutions.ind_doctors_title',      'desc_key' => 'solutions.ind_doctors_desc'],
        ['url' => 'business-cards-oman-construction-companies',   'title_key' => 'solutions.ind_construction_title', 'desc_key' => 'solutions.ind_construction_desc'],
        ['url' => 'business-cards-oman-bank-employees',           'title_key' => 'solutions.ind_bank_title',         'desc_key' => 'solutions.ind_bank_desc'],
        ['url' => 'digital-business-cards-oman-hotels',           'title_key' => 'solutions.ind_hotels_title',       'desc_key' => 'solutions.ind_hotels_desc'],
    ],
    'solutions.cat_location' => [
        ['url' => 'qr-code-menu-muscat-restaurants',              'title_key' => 'solutions.loc_muscat_menus_title', 'desc_key' => 'solutions.loc_muscat_menus_desc'],
        ['url' => 'digital-business-cards-sohar-industrial-port', 'title_key' => 'solutions.loc_sohar_title',        'desc_key' => 'solutions.loc_sohar_desc'],
        ['url' => 'salalah-tourism-business-cards',               'title_key' => 'solutions.loc_salalah_title',      'desc_key' => 'solutions.loc_salalah_desc'],
        ['url' => 'business-cards-duqm-free-zone',                'title_key' => 'solutions.loc_duqm_title',         'desc_key' => 'solutions.loc_duqm_desc'],
    ],
    'solutions.cat_usecase' => [
        ['url' => 'bilingual-arabic-english-business-cards',      'title_key' => 'solutions.uc_bilingual_title',     'desc_key' => 'solutions.uc_bilingual_desc'],
        ['url' => 'nfc-business-cards-oman-executives',           'title_key' => 'solutions.uc_nfc_exec_title',      'desc_key' => 'solutions.uc_nfc_exec_desc'],
        ['url' => 'business-cards-for-ramadan-networking',        'title_key' => 'solutions.uc_ramadan_title',       'desc_key' => 'solutions.uc_ramadan_desc'],
        ['url' => 'business-cards-for-oman-trade-fairs',          'title_key' => 'solutions.uc_trade_title',         'desc_key' => 'solutions.uc_trade_desc'],
    ],
    'solutions.cat_staff' => [
        ['url' => 'business-cards-oman-government-employees',     'title_key' => 'solutions.staff_gov_title',        'desc_key' => 'solutions.staff_gov_desc'],
        ['url' => 'business-cards-oman-freelancers-consultants',  'title_key' => 'solutions.staff_freelance_title',  'desc_key' => 'solutions.staff_freelance_desc'],
        ['url' => 'business-cards-oman-startups',                 'title_key' => 'solutions.staff_startups_title',   'desc_key' => 'solutions.staff_startups_desc'],
        ['url' => 'business-cards-oman-omanisation',              'title_key' => 'solutions.staff_omanisation_title','desc_key' => 'solutions.staff_omanisation_desc'],
    ],
];
}

/** How many solution pages the shelf actually holds. */
function solutionCount(): int
{
    $n = 0;
    foreach (solutionShelf() as $group) $n += count($group);
    return $n;
}
