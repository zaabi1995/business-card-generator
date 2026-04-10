<?php
/**
 * Currency Helper - Handles currency formatting and conversion
 */

class Currency {
    
    /**
     * Get base path for assets (handles subdirectory contexts)
     */
    private static function getAssetBasePath() {
        if (function_exists('getBasePath')) {
            return getBasePath();
        }
        return '/';
    }
    
    /**
     * Available currencies with symbols and formatting
     */
    public static function getAll() {
        return [
            'USD' => [
                'name' => 'US Dollar',
                'symbol' => '$',
                'symbol_position' => 'before',
                'decimal_separator' => '.',
                'thousands_separator' => ',',
                'decimals' => 2
            ],
            'EUR' => [
                'name' => 'Euro',
                'symbol' => '€',
                'symbol_position' => 'before',
                'decimal_separator' => ',',
                'thousands_separator' => '.',
                'decimals' => 2
            ],
            'GBP' => [
                'name' => 'British Pound',
                'symbol' => '£',
                'symbol_position' => 'before',
                'decimal_separator' => '.',
                'thousands_separator' => ',',
                'decimals' => 2
            ],
            'OMR' => [
                'name' => 'Omani Rial',
                'symbol' => 'OMR',
                'symbol_html' => true, // Use SVG symbol
                'symbol_position' => 'before', // Symbol on left
                'text_position' => 'after', // Text code on right
                'decimal_separator' => '.',
                'thousands_separator' => ',',
                'decimals' => 3
            ],
            'AED' => [
                'name' => 'UAE Dirham',
                'symbol' => 'د.إ',
                'symbol_position' => 'before', // Symbol on left
                'text_position' => 'after', // Text code on right
                'decimal_separator' => '.',
                'thousands_separator' => ',',
                'decimals' => 2
            ],
            'SAR' => [
                'name' => 'Saudi Riyal',
                'symbol' => '﷼',
                'symbol_position' => 'before', // Symbol on left
                'text_position' => 'after', // Text code on right
                'decimal_separator' => '.',
                'thousands_separator' => ',',
                'decimals' => 2
            ],
            'QAR' => [
                'name' => 'Qatari Riyal',
                'symbol' => '﷼',
                'symbol_position' => 'before', // Symbol on left
                'text_position' => 'after', // Text code on right
                'decimal_separator' => '.',
                'thousands_separator' => ',',
                'decimals' => 2
            ],
            'BHD' => [
                'name' => 'Bahraini Dinar',
                'symbol' => '.د.ب',
                'symbol_position' => 'before', // Symbol on left
                'text_position' => 'after', // Text code on right
                'decimal_separator' => '.',
                'thousands_separator' => ',',
                'decimals' => 3
            ],
            'KWD' => [
                'name' => 'Kuwaiti Dinar',
                'symbol' => 'د.ك',
                'symbol_position' => 'before', // Symbol on left
                'text_position' => 'after', // Text code on right
                'decimal_separator' => '.',
                'thousands_separator' => ',',
                'decimals' => 3
            ],
            'INR' => [
                'name' => 'Indian Rupee',
                'symbol' => '₹',
                'symbol_position' => 'before',
                'decimal_separator' => '.',
                'thousands_separator' => ',',
                'decimals' => 2
            ],
            'PKR' => [
                'name' => 'Pakistani Rupee',
                'symbol' => 'Rs',
                'symbol_position' => 'before',
                'decimal_separator' => '.',
                'thousands_separator' => ',',
                'decimals' => 2
            ],
            'EGP' => [
                'name' => 'Egyptian Pound',
                'symbol' => 'E£',
                'symbol_position' => 'before',
                'decimal_separator' => '.',
                'thousands_separator' => ',',
                'decimals' => 2
            ],
            'JOD' => [
                'name' => 'Jordanian Dinar',
                'symbol' => 'JOD',
                'symbol_position' => 'after',
                'decimal_separator' => '.',
                'thousands_separator' => ',',
                'decimals' => 3
            ],
            'CAD' => [
                'name' => 'Canadian Dollar',
                'symbol' => 'C$',
                'symbol_position' => 'before',
                'decimal_separator' => '.',
                'thousands_separator' => ',',
                'decimals' => 2
            ],
            'AUD' => [
                'name' => 'Australian Dollar',
                'symbol' => 'A$',
                'symbol_position' => 'before',
                'decimal_separator' => '.',
                'thousands_separator' => ',',
                'decimals' => 2
            ],
            'CHF' => [
                'name' => 'Swiss Franc',
                'symbol' => 'CHF',
                'symbol_position' => 'after',
                'decimal_separator' => '.',
                'thousands_separator' => "'",
                'decimals' => 2
            ],
            'CNY' => [
                'name' => 'Chinese Yuan',
                'symbol' => '¥',
                'symbol_position' => 'before',
                'decimal_separator' => '.',
                'thousands_separator' => ',',
                'decimals' => 2
            ],
            'JPY' => [
                'name' => 'Japanese Yen',
                'symbol' => '¥',
                'symbol_position' => 'before',
                'decimal_separator' => '.',
                'thousands_separator' => ',',
                'decimals' => 0
            ],
            'SGD' => [
                'name' => 'Singapore Dollar',
                'symbol' => 'S$',
                'symbol_position' => 'before',
                'decimal_separator' => '.',
                'thousands_separator' => ',',
                'decimals' => 2
            ],
            'MYR' => [
                'name' => 'Malaysian Ringgit',
                'symbol' => 'RM',
                'symbol_position' => 'before',
                'decimal_separator' => '.',
                'thousands_separator' => ',',
                'decimals' => 2
            ],
            'TRY' => [
                'name' => 'Turkish Lira',
                'symbol' => '₺',
                'symbol_position' => 'before',
                'decimal_separator' => ',',
                'thousands_separator' => '.',
                'decimals' => 2
            ],
            'ZAR' => [
                'name' => 'South African Rand',
                'symbol' => 'R',
                'symbol_position' => 'before',
                'decimal_separator' => '.',
                'thousands_separator' => ',',
                'decimals' => 2
            ],
            'NGN' => [
                'name' => 'Nigerian Naira',
                'symbol' => '₦',
                'symbol_position' => 'before',
                'decimal_separator' => '.',
                'thousands_separator' => ',',
                'decimals' => 2
            ]
        ];
    }
    
    /**
     * Get currency by code
     */
    public static function get($code) {
        $currencies = self::getAll();
        return $currencies[strtoupper($code)] ?? $currencies['USD'];
    }
    
    /**
     * Format amount with currency
     */
    /**
     * Format amount with currency
     * Uses text codes (OMR, AED) - code goes on right for GCC currencies
     * Uses symbols ($, £, €) - symbol goes on left
     */
    public static function format($amount, $currencyCode = 'USD') {
        $currency = self::get($currencyCode);
        $code = strtoupper($currencyCode);
        
        $formatted = number_format(
            (float)$amount,
            $currency['decimals'],
            $currency['decimal_separator'],
            $currency['thousands_separator']
        );
        
        // For plain text format, use text_position if available (GCC currencies show code on right)
        // Otherwise use symbol_position
        $position = $currency['text_position'] ?? $currency['symbol_position'];
        
        // Determine what to display: use code for GCC currencies, symbol for others
        $hasTextPosition = isset($currency['text_position']);
        $displaySymbol = $hasTextPosition ? $code : $currency['symbol'];
        
        if ($position === 'before') {
            return $displaySymbol . ' ' . $formatted;
        } else {
            return $formatted . ' ' . $displaySymbol;
        }
    }
    
    /**
     * Format amount without symbol (just number)
     */
    public static function formatNumber($amount, $currencyCode = 'USD') {
        $currency = self::get($currencyCode);
        
        return number_format(
            (float)$amount,
            $currency['decimals'],
            $currency['decimal_separator'],
            $currency['thousands_separator']
        );
    }
    
    /**
     * Get symbol only
     */
    public static function getSymbol($currencyCode = 'USD') {
        $currency = self::get($currencyCode);
        return $currency['symbol'];
    }
    
    /**
     * Get currency name
     */
    public static function getName($currencyCode = 'USD') {
        $currency = self::get($currencyCode);
        return $currency['name'];
    }
    
    /**
     * Get options for select dropdown
     */
    public static function getOptions($selectedCode = 'USD') {
        $currencies = self::getAll();
        $html = '';
        
        foreach ($currencies as $code => $currency) {
            $selected = ($code === strtoupper($selectedCode)) ? 'selected' : '';
            $html .= sprintf(
                '<option value="%s" %s>%s (%s)</option>',
                $code,
                $selected,
                $currency['name'],
                $currency['symbol']
            );
        }
        
        return $html;
    }
    
    /**
     * Get common GCC/Middle East currencies
     */
    public static function getGCCCurrencies() {
        return ['OMR', 'AED', 'SAR', 'QAR', 'BHD', 'KWD'];
    }
    
    /**
     * Get popular currencies (for featured display)
     */
    public static function getPopular() {
        return ['USD', 'EUR', 'GBP', 'OMR', 'AED', 'SAR'];
    }
    
    /**
     * Get HTML symbol for currency (supports SVG for OMR)
     * @param string $currencyCode Currency code
     * @param string $size Size class: 'sm' (12px), 'md' (16px), 'lg' (20px), 'xl' (24px)
     * @param string $variant Color variant: 'dark' (default) or 'light' (white)
     */
    public static function getSymbolHtml($currencyCode = 'USD', $size = 'md', $variant = 'dark') {
        $currency = self::get($currencyCode);
        $code = strtoupper($currencyCode);
        
        // Size mapping
        $sizeMap = [
            'sm' => ['h' => '12', 'class' => 'h-3 w-3'],
            'md' => ['h' => '16', 'class' => 'h-4 w-4'],
            'lg' => ['h' => '20', 'class' => 'h-5 w-5'],
            'xl' => ['h' => '24', 'class' => 'h-6 w-6']
        ];
        $sizeInfo = $sizeMap[$size] ?? $sizeMap['md'];
        
        // Use SVG for OMR
        if ($code === 'OMR' && !empty($currency['symbol_html'])) {
            $svgFile = $variant === 'light' ? 'Medium-White.svg' : 'Medium.svg';
            $basePath = self::getAssetBasePath();
            return sprintf(
                '<img src="%sassets/omr-symbol/%s" alt="OMR" class="inline-block align-middle %s" style="height:%spx">',
                $basePath,
                $svgFile,
                $sizeInfo['class'],
                $sizeInfo['h']
            );
        }
        
        // Return text symbol for other currencies
        return htmlspecialchars($currency['symbol']);
    }
    
    /**
     * Format amount with HTML-safe currency symbol (supports SVG)
     * @param float $amount Amount to format
     * @param string $currencyCode Currency code
     * @param string $symbolSize Size for SVG symbols
     * @param string $variant Color variant for SVG
     */
    /**
     * Format amount with HTML symbol (uses SVG for OMR, etc.)
     * Symbol always goes on the LEFT for proper formatting
     */
    public static function formatHtml($amount, $currencyCode = 'USD', $symbolSize = 'md', $variant = 'dark') {
        $currency = self::get($currencyCode);
        $code = strtoupper($currencyCode);
        
        $formatted = number_format(
            (float)$amount,
            $currency['decimals'],
            $currency['decimal_separator'],
            $currency['thousands_separator']
        );
        
        $symbol = self::getSymbolHtml($currencyCode, $symbolSize, $variant);
        
        // When using HTML symbols (like SVG), symbol goes on the left
        if ($currency['symbol_position'] === 'before') {
            return $symbol . ' ' . $formatted;
        } else {
            // For currencies where symbol would be after, still use it but with proper spacing
            return $formatted . ' ' . $symbol;
        }
    }
    
    /**
     * Get all countries with their currencies
     */
    public static function getCountries() {
        return [
            // GCC Countries (prioritized)
            'OM' => ['name' => 'Oman', 'currency' => 'OMR', 'region' => 'gcc'],
            'AE' => ['name' => 'United Arab Emirates', 'currency' => 'AED', 'region' => 'gcc'],
            'SA' => ['name' => 'Saudi Arabia', 'currency' => 'SAR', 'region' => 'gcc'],
            'BH' => ['name' => 'Bahrain', 'currency' => 'BHD', 'region' => 'gcc'],
            'KW' => ['name' => 'Kuwait', 'currency' => 'KWD', 'region' => 'gcc'],
            'QA' => ['name' => 'Qatar', 'currency' => 'QAR', 'region' => 'gcc'],
            
            // Middle East
            'JO' => ['name' => 'Jordan', 'currency' => 'JOD', 'region' => 'middle_east'],
            'LB' => ['name' => 'Lebanon', 'currency' => 'USD', 'region' => 'middle_east'],
            'EG' => ['name' => 'Egypt', 'currency' => 'EGP', 'region' => 'middle_east'],
            'IQ' => ['name' => 'Iraq', 'currency' => 'USD', 'region' => 'middle_east'],
            'YE' => ['name' => 'Yemen', 'currency' => 'USD', 'region' => 'middle_east'],
            
            // Europe
            'GB' => ['name' => 'United Kingdom', 'currency' => 'GBP', 'region' => 'europe'],
            'DE' => ['name' => 'Germany', 'currency' => 'EUR', 'region' => 'europe'],
            'FR' => ['name' => 'France', 'currency' => 'EUR', 'region' => 'europe'],
            'IT' => ['name' => 'Italy', 'currency' => 'EUR', 'region' => 'europe'],
            'ES' => ['name' => 'Spain', 'currency' => 'EUR', 'region' => 'europe'],
            'NL' => ['name' => 'Netherlands', 'currency' => 'EUR', 'region' => 'europe'],
            'BE' => ['name' => 'Belgium', 'currency' => 'EUR', 'region' => 'europe'],
            'AT' => ['name' => 'Austria', 'currency' => 'EUR', 'region' => 'europe'],
            'PT' => ['name' => 'Portugal', 'currency' => 'EUR', 'region' => 'europe'],
            'IE' => ['name' => 'Ireland', 'currency' => 'EUR', 'region' => 'europe'],
            'GR' => ['name' => 'Greece', 'currency' => 'EUR', 'region' => 'europe'],
            'CH' => ['name' => 'Switzerland', 'currency' => 'CHF', 'region' => 'europe'],
            'SE' => ['name' => 'Sweden', 'currency' => 'EUR', 'region' => 'europe'],
            'NO' => ['name' => 'Norway', 'currency' => 'EUR', 'region' => 'europe'],
            'DK' => ['name' => 'Denmark', 'currency' => 'EUR', 'region' => 'europe'],
            'FI' => ['name' => 'Finland', 'currency' => 'EUR', 'region' => 'europe'],
            'PL' => ['name' => 'Poland', 'currency' => 'EUR', 'region' => 'europe'],
            'TR' => ['name' => 'Turkey', 'currency' => 'TRY', 'region' => 'europe'],
            
            // Asia
            'IN' => ['name' => 'India', 'currency' => 'INR', 'region' => 'asia'],
            'PK' => ['name' => 'Pakistan', 'currency' => 'PKR', 'region' => 'asia'],
            'BD' => ['name' => 'Bangladesh', 'currency' => 'USD', 'region' => 'asia'],
            'LK' => ['name' => 'Sri Lanka', 'currency' => 'USD', 'region' => 'asia'],
            'NP' => ['name' => 'Nepal', 'currency' => 'USD', 'region' => 'asia'],
            'PH' => ['name' => 'Philippines', 'currency' => 'USD', 'region' => 'asia'],
            'SG' => ['name' => 'Singapore', 'currency' => 'SGD', 'region' => 'asia'],
            'MY' => ['name' => 'Malaysia', 'currency' => 'MYR', 'region' => 'asia'],
            'ID' => ['name' => 'Indonesia', 'currency' => 'USD', 'region' => 'asia'],
            'TH' => ['name' => 'Thailand', 'currency' => 'USD', 'region' => 'asia'],
            'VN' => ['name' => 'Vietnam', 'currency' => 'USD', 'region' => 'asia'],
            'CN' => ['name' => 'China', 'currency' => 'CNY', 'region' => 'asia'],
            'JP' => ['name' => 'Japan', 'currency' => 'JPY', 'region' => 'asia'],
            'KR' => ['name' => 'South Korea', 'currency' => 'USD', 'region' => 'asia'],
            'HK' => ['name' => 'Hong Kong', 'currency' => 'USD', 'region' => 'asia'],
            
            // Americas
            'US' => ['name' => 'United States', 'currency' => 'USD', 'region' => 'americas'],
            'CA' => ['name' => 'Canada', 'currency' => 'CAD', 'region' => 'americas'],
            'MX' => ['name' => 'Mexico', 'currency' => 'USD', 'region' => 'americas'],
            'BR' => ['name' => 'Brazil', 'currency' => 'USD', 'region' => 'americas'],
            
            // Oceania
            'AU' => ['name' => 'Australia', 'currency' => 'AUD', 'region' => 'oceania'],
            'NZ' => ['name' => 'New Zealand', 'currency' => 'AUD', 'region' => 'oceania'],
            
            // Africa
            'ZA' => ['name' => 'South Africa', 'currency' => 'ZAR', 'region' => 'africa'],
            'NG' => ['name' => 'Nigeria', 'currency' => 'NGN', 'region' => 'africa'],
            'KE' => ['name' => 'Kenya', 'currency' => 'USD', 'region' => 'africa'],
            'ET' => ['name' => 'Ethiopia', 'currency' => 'USD', 'region' => 'africa'],
        ];
    }
    
    /**
     * Get country by code
     */
    public static function getCountry($code) {
        $countries = self::getCountries();
        return $countries[strtoupper($code)] ?? null;
    }
    
    /**
     * Get country name by code
     */
    public static function getCountryName($code) {
        $country = self::getCountry($code);
        return $country['name'] ?? $code;
    }
    
    /**
     * Get currency based on country code
     * @param string $countryCode ISO 2-letter country code
     */
    public static function getByCountry($countryCode) {
        $country = self::getCountry($countryCode);
        return $country['currency'] ?? 'USD';
    }
    
    /**
     * Get country flag HTML using flag-icons CSS library
     * @param string $code ISO 2-letter country code
     * @param string $size Size class: 'sm', 'md', 'lg'
     * @return string HTML for flag icon
     */
    public static function getCountryFlag($code, $size = 'md') {
        if (empty($code) || strlen($code) !== 2) {
            return '<span class="fi fi-un fis"></span>';
        }
        $code = strtolower($code);
        $sizeClass = match($size) {
            'sm' => 'w-4 h-3',
            'lg' => 'w-6 h-4',
            default => 'w-5 h-4'
        };
        // Using flag-icons library: https://flagicons.lipis.dev/
        return sprintf('<span class="fi fi-%s fis rounded-sm %s"></span>', $code, $sizeClass);
    }
    
    /**
     * Get the CSS link for flag-icons library
     * Include this in your page head
     */
    public static function getFlagIconsCss() {
        return '<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/lipis/flag-icons@7.0.0/css/flag-icons.min.css">';
    }
    
    /**
     * Get country options for select dropdown (grouped by region)
     * @param string $selectedCode Currently selected country code
     * @param bool $grouped Whether to group by region
     * @param bool $showFlags Whether to show flags (not used in select, use data attributes for JS)
     */
    public static function getCountryOptions($selectedCode = '', $grouped = true, $showFlags = true) {
        $countries = self::getCountries();
        $phoneCodes = self::getPhoneCodes();
        $html = '<option value="">Select Country</option>';
        
        if ($grouped) {
            $regions = [
                'gcc' => 'GCC Countries',
                'middle_east' => 'Middle East',
                'europe' => 'Europe',
                'asia' => 'Asia',
                'americas' => 'Americas',
                'oceania' => 'Oceania',
                'africa' => 'Africa'
            ];
            
            foreach ($regions as $regionKey => $regionName) {
                $regionCountries = array_filter($countries, fn($c) => $c['region'] === $regionKey);
                if (!empty($regionCountries)) {
                    $html .= sprintf('<optgroup label="%s">', htmlspecialchars($regionName));
                    foreach ($regionCountries as $code => $country) {
                        $selected = (strtoupper($selectedCode) === $code) ? 'selected' : '';
                        $phoneCode = $phoneCodes[$code] ?? '';
                        $html .= sprintf(
                            '<option value="%s" data-currency="%s" data-phone-code="%s" data-flag-class="fi fi-%s" %s>%s</option>',
                            $code,
                            $country['currency'],
                            $phoneCode,
                            strtolower($code),
                            $selected,
                            htmlspecialchars($country['name'])
                        );
                    }
                    $html .= '</optgroup>';
                }
            }
        } else {
            // Flat list sorted by name
            uasort($countries, fn($a, $b) => strcmp($a['name'], $b['name']));
            foreach ($countries as $code => $country) {
                $selected = (strtoupper($selectedCode) === $code) ? 'selected' : '';
                $phoneCode = $phoneCodes[$code] ?? '';
                $html .= sprintf(
                    '<option value="%s" data-currency="%s" data-phone-code="%s" data-flag-class="fi fi-%s" %s>%s</option>',
                    $code,
                    $country['currency'],
                    $phoneCode,
                    strtolower($code),
                    $selected,
                    htmlspecialchars($country['name'])
                );
            }
        }
        
        return $html;
    }
    
    /**
     * Get currency options for select dropdown
     */
    public static function getCurrencyOptions($selectedCode = 'USD') {
        $currencies = self::getAll();
        $html = '';
        
        // Prioritize common currencies
        $priority = ['USD', 'OMR', 'AED', 'SAR', 'BHD', 'KWD', 'QAR', 'GBP', 'EUR'];
        $sorted = [];
        
        foreach ($priority as $code) {
            if (isset($currencies[$code])) {
                $sorted[$code] = $currencies[$code];
            }
        }
        foreach ($currencies as $code => $currency) {
            if (!isset($sorted[$code])) {
                $sorted[$code] = $currency;
            }
        }
        
        foreach ($sorted as $code => $currency) {
            $selected = (strtoupper($selectedCode) === $code) ? 'selected' : '';
            $html .= sprintf(
                '<option value="%s" %s>%s - %s (%s)</option>',
                $code,
                $selected,
                $code,
                htmlspecialchars($currency['name']),
                htmlspecialchars($currency['symbol'])
            );
        }
        
        return $html;
    }
    
    /**
     * Get JSON data for JavaScript country-currency mapping
     */
    public static function getCountryCurrencyMapJson() {
        $countries = self::getCountries();
        $map = [];
        foreach ($countries as $code => $country) {
            $map[$code] = $country['currency'];
        }
        return json_encode($map);
    }
    
    /**
     * Get phone codes for countries
     */
    public static function getPhoneCodes() {
        return [
            'OM' => '+968',
            'AE' => '+971',
            'SA' => '+966',
            'BH' => '+973',
            'KW' => '+965',
            'QA' => '+974',
            'JO' => '+962',
            'LB' => '+961',
            'EG' => '+20',
            'IQ' => '+964',
            'YE' => '+967',
            'GB' => '+44',
            'DE' => '+49',
            'FR' => '+33',
            'IT' => '+39',
            'ES' => '+34',
            'NL' => '+31',
            'BE' => '+32',
            'AT' => '+43',
            'PT' => '+351',
            'IE' => '+353',
            'GR' => '+30',
            'CH' => '+41',
            'SE' => '+46',
            'NO' => '+47',
            'DK' => '+45',
            'FI' => '+358',
            'PL' => '+48',
            'TR' => '+90',
            'IN' => '+91',
            'PK' => '+92',
            'BD' => '+880',
            'LK' => '+94',
            'NP' => '+977',
            'PH' => '+63',
            'SG' => '+65',
            'MY' => '+60',
            'ID' => '+62',
            'TH' => '+66',
            'VN' => '+84',
            'CN' => '+86',
            'JP' => '+81',
            'KR' => '+82',
            'HK' => '+852',
            'US' => '+1',
            'CA' => '+1',
            'MX' => '+52',
            'BR' => '+55',
            'AU' => '+61',
            'NZ' => '+64',
            'ZA' => '+27',
            'NG' => '+234',
            'KE' => '+254',
            'ET' => '+251',
        ];
    }
    
    /**
     * Get phone code for a country
     */
    public static function getPhoneCode($countryCode) {
        $codes = self::getPhoneCodes();
        return $codes[strtoupper($countryCode)] ?? '+1';
    }
    
    /**
     * Output JavaScript helper for country/currency selectors
     * Include this once on pages that use country selectors
     */
    public static function getJavaScriptHelper() {
        $countryCurrencyMap = self::getCountryCurrencyMapJson();
        $phoneCodes = json_encode(self::getPhoneCodes());
        
        return <<<JS
<script>
// Cardify Country/Currency Helper
const CardifyGeo = {
    countryCurrency: {$countryCurrencyMap},
    phoneCodes: {$phoneCodes},
    
    // Update currency select when country changes
    updateCurrency: function(countrySelect, currencySelectId) {
        const selectedOption = countrySelect.options[countrySelect.selectedIndex];
        const currency = selectedOption?.getAttribute('data-currency') || this.countryCurrency[countrySelect.value];
        const currencySelect = document.getElementById(currencySelectId);
        if (currencySelect && currency) {
            currencySelect.value = currency;
        }
    },
    
    // Get currency for country code
    getCurrency: function(countryCode) {
        return this.countryCurrency[countryCode] || 'USD';
    },
    
    // Get phone code for country
    getPhoneCode: function(countryCode) {
        return this.phoneCodes[countryCode] || '+1';
    },
    
    // Update phone code prefix when country changes
    updatePhoneCode: function(countrySelect, phoneInputId) {
        const phoneCode = this.getPhoneCode(countrySelect.value);
        const phoneInput = document.getElementById(phoneInputId);
        if (phoneInput) {
            // If input has a prefix display element
            const prefixEl = document.querySelector('[data-phone-prefix="' + phoneInputId + '"]');
            if (prefixEl) {
                prefixEl.textContent = phoneCode;
            }
        }
    },
    
    // Initialize all country selectors on the page
    init: function() {
        document.querySelectorAll('[data-country-select]').forEach(select => {
            const currencyId = select.getAttribute('data-currency-select');
            const phoneId = select.getAttribute('data-phone-input');
            
            select.addEventListener('change', () => {
                if (currencyId) this.updateCurrency(select, currencyId);
                if (phoneId) this.updatePhoneCode(select, phoneId);
            });
        });
    }
};

// Auto-initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => CardifyGeo.init());
} else {
    CardifyGeo.init();
}
</script>
JS;
    }
    
    /**
     * Render a complete country select with optional currency select
     * @param array $options Configuration options
     */
    public static function renderCountrySelect($options = []) {
        $defaults = [
            'name' => 'country',
            'id' => 'country',
            'selected' => '',
            'required' => false,
            'class' => 'w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500',
            'currency_id' => null, // If set, will auto-update this currency select
            'phone_id' => null, // If set, will auto-update phone prefix
            'grouped' => true,
            'show_flags' => true
        ];
        
        $opts = array_merge($defaults, $options);
        $required = $opts['required'] ? 'required' : '';
        
        $dataAttrs = 'data-country-select';
        if ($opts['currency_id']) {
            $dataAttrs .= ' data-currency-select="' . htmlspecialchars($opts['currency_id']) . '"';
        }
        if ($opts['phone_id']) {
            $dataAttrs .= ' data-phone-input="' . htmlspecialchars($opts['phone_id']) . '"';
        }
        
        $html = sprintf(
            '<select name="%s" id="%s" class="%s" %s %s>%s</select>',
            htmlspecialchars($opts['name']),
            htmlspecialchars($opts['id']),
            htmlspecialchars($opts['class']),
            $dataAttrs,
            $required,
            self::getCountryOptions($opts['selected'], $opts['grouped'], $opts['show_flags'])
        );
        
        return $html;
    }
    
    /**
     * Render a complete currency select
     */
    public static function renderCurrencySelect($options = []) {
        $defaults = [
            'name' => 'currency',
            'id' => 'currency',
            'selected' => 'USD',
            'required' => false,
            'class' => 'w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500'
        ];
        
        $opts = array_merge($defaults, $options);
        $required = $opts['required'] ? 'required' : '';
        
        $html = sprintf(
            '<select name="%s" id="%s" class="%s" %s>%s</select>',
            htmlspecialchars($opts['name']),
            htmlspecialchars($opts['id']),
            htmlspecialchars($opts['class']),
            $required,
            self::getCurrencyOptions($opts['selected'])
        );
        
        return $html;
    }
    
    /**
     * Get countries with phone codes for phone input dropdown
     * Returns array with country data including flag class
     */
    public static function getCountriesWithPhoneCodes() {
        $countries = self::getCountries();
        $phoneCodes = self::getPhoneCodes();
        $result = [];
        
        foreach ($countries as $code => $country) {
            $result[$code] = [
                'name' => $country['name'],
                'phone_code' => $phoneCodes[$code] ?? '+1',
                'currency' => $country['currency'],
                'region' => $country['region'],
                'flag_class' => 'fi fi-' . strtolower($code)
            ];
        }
        
        return $result;
    }
    
    /**
     * Get phone input options HTML for dropdown
     * @param string $selectedCode Selected country code
     * @param bool $prioritizeGCC Show GCC countries first
     */
    public static function getPhoneInputOptions($selectedCode = 'OM', $prioritizeGCC = true) {
        $countries = self::getCountriesWithPhoneCodes();
        $html = '';
        
        if ($prioritizeGCC) {
            // GCC countries first
            $gccCodes = ['OM', 'AE', 'SA', 'BH', 'KW', 'QA'];
            $gccCountries = [];
            $otherCountries = [];
            
            foreach ($countries as $code => $country) {
                if (in_array($code, $gccCodes)) {
                    $gccCountries[$code] = $country;
                } else {
                    $otherCountries[$code] = $country;
                }
            }
            
            // Sort others by name
            uasort($otherCountries, fn($a, $b) => strcmp($a['name'], $b['name']));
            
            $sortedCountries = $gccCountries + $otherCountries;
        } else {
            uasort($countries, fn($a, $b) => strcmp($a['name'], $b['name']));
            $sortedCountries = $countries;
        }
        
        foreach ($sortedCountries as $code => $country) {
            $selected = (strtoupper($selectedCode) === $code) ? 'selected' : '';
            $html .= sprintf(
                '<option value="%s" data-phone-code="%s" data-flag-class="%s" %s>%s (%s)</option>',
                $code,
                $country['phone_code'],
                $country['flag_class'],
                $selected,
                htmlspecialchars($country['name']),
                $country['phone_code']
            );
        }
        
        return $html;
    }
    
    /**
     * Render a phone input component with country code dropdown (Flowbite-style)
     * Uses flag-icons CSS library for proper flag display
     * @param array $options Configuration options
     */
    public static function renderPhoneInput($options = []) {
        $defaults = [
            'name' => 'phone',
            'id' => 'phone-input',
            'country_name' => 'phone_country',
            'country_id' => 'phone-country',
            'selected_country' => 'OM',
            'value' => '',
            'placeholder' => 'Enter phone number',
            'required' => false,
            'class' => '',
            'label' => 'Phone Number',
            'show_label' => true,
            'helper_text' => '',
            'prioritize_gcc' => true
        ];
        
        $opts = array_merge($defaults, $options);
        $required = $opts['required'] ? 'required' : '';
        $countries = self::getCountriesWithPhoneCodes();
        $selectedCountry = $countries[$opts['selected_country']] ?? $countries['OM'];
        $uniqueId = $opts['id'] . '-' . substr(md5(uniqid()), 0, 6);
        
        $html = '';
        
        // Label
        if ($opts['show_label'] && $opts['label']) {
            $html .= sprintf(
                '<label for="%s" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">%s</label>',
                htmlspecialchars($opts['id']),
                htmlspecialchars($opts['label'])
            );
        }
        
        // Phone input container with Flowbite styling
        $html .= '<div class="flex">';
        
        // Country dropdown button - Flowbite style
        $html .= sprintf('
            <button type="button" 
                id="%s-btn" 
                data-dropdown-toggle="%s-dropdown"
                class="flex-shrink-0 z-10 inline-flex items-center py-2.5 px-4 text-sm font-medium text-center text-gray-500 bg-gray-100 border border-gray-300 rounded-s-lg hover:bg-gray-200 focus:ring-4 focus:outline-none focus:ring-gray-100 dark:bg-gray-700 dark:hover:bg-gray-600 dark:focus:ring-gray-700 dark:text-white dark:border-gray-600">
                <span class="%s fis rounded-sm me-2"></span>
                <span data-phone-code-display="%s">%s</span>
                <svg class="w-2.5 h-2.5 ms-2.5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 10 6">
                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 4 4 4-4"/>
                </svg>
            </button>',
            $uniqueId,
            $uniqueId,
            $selectedCountry['flag_class'],
            $uniqueId,
            $selectedCountry['phone_code']
        );
        
        // Dropdown menu - Flowbite style with search
        $html .= sprintf('
            <div id="%s-dropdown" class="z-10 hidden bg-white divide-y divide-gray-100 rounded-lg shadow w-64 dark:bg-gray-700">
                <div class="p-3">
                    <label for="%s-search" class="sr-only">Search</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 start-0 flex items-center ps-3 pointer-events-none">
                            <svg class="w-4 h-4 text-gray-500 dark:text-gray-400" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 20 20">
                                <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m19 19-4-4m0-7A7 7 0 1 1 1 8a7 7 0 0 1 14 0Z"/>
                            </svg>
                        </div>
                        <input type="text" id="%s-search" class="block w-full p-2 ps-10 text-sm text-gray-900 border border-gray-300 rounded-lg bg-gray-50 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-600 dark:border-gray-500 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500" placeholder="Search country...">
                    </div>
                </div>
                <ul class="h-48 py-2 overflow-y-auto text-sm text-gray-700 dark:text-gray-200" data-phone-list="%s">',
            $uniqueId,
            $uniqueId,
            $uniqueId,
            $uniqueId
        );
        
        // Country options with flags
        $countriesWithPhone = self::getCountriesWithPhoneCodes();
        if ($opts['prioritize_gcc']) {
            $gccCodes = ['OM', 'AE', 'SA', 'BH', 'KW', 'QA'];
            $gccCountries = [];
            $otherCountries = [];
            
            foreach ($countriesWithPhone as $code => $country) {
                if (in_array($code, $gccCodes)) {
                    $gccCountries[$code] = $country;
                } else {
                    $otherCountries[$code] = $country;
                }
            }
            uasort($otherCountries, fn($a, $b) => strcmp($a['name'], $b['name']));
            $sortedCountries = $gccCountries + $otherCountries;
        } else {
            uasort($countriesWithPhone, fn($a, $b) => strcmp($a['name'], $b['name']));
            $sortedCountries = $countriesWithPhone;
        }
        
        foreach ($sortedCountries as $code => $country) {
            $html .= sprintf('
                <li>
                    <button type="button" 
                        class="inline-flex w-full px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-600 dark:hover:text-white"
                        data-country="%s"
                        data-phone-code="%s"
                        data-flag-class="%s">
                        <span class="%s fis rounded-sm me-2"></span>
                        <span class="flex-1 text-start">%s</span>
                        <span class="text-gray-500 dark:text-gray-400">%s</span>
                    </button>
                </li>',
                $code,
                $country['phone_code'],
                $country['flag_class'],
                $country['flag_class'],
                htmlspecialchars($country['name']),
                $country['phone_code']
            );
        }
        
        $html .= '</ul></div>';
        
        // Hidden country input
        $html .= sprintf(
            '<input type="hidden" name="%s" id="%s" value="%s">',
            htmlspecialchars($opts['country_name']),
            htmlspecialchars($opts['country_id']),
            htmlspecialchars($opts['selected_country'])
        );
        
        // Phone number input - Flowbite style
        $html .= sprintf('
            <input type="tel" 
                name="%s" 
                id="%s" 
                value="%s"
                class="block p-2.5 w-full z-20 text-sm text-gray-900 bg-gray-50 rounded-e-lg border-s-0 border border-gray-300 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-s-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:border-blue-500 %s" 
                placeholder="%s" 
                %s>',
            htmlspecialchars($opts['name']),
            htmlspecialchars($opts['id']),
            htmlspecialchars($opts['value']),
            htmlspecialchars($opts['class']),
            htmlspecialchars($opts['placeholder']),
            $required
        );
        
        $html .= '</div>';
        
        // Helper text
        if ($opts['helper_text']) {
            $html .= sprintf(
                '<p class="mt-2 text-sm text-gray-500 dark:text-gray-400">%s</p>',
                htmlspecialchars($opts['helper_text'])
            );
        }
        
        return $html;
    }
    
    /**
     * Render a simple phone input with inline country code select (Flowbite-style)
     * This is the recommended component for most forms
     * @param array $options Configuration options
     */
    public static function renderSimplePhoneInput($options = []) {
        $defaults = [
            'name' => 'phone',
            'id' => 'phone-input',
            'country_name' => 'phone_country',
            'country_id' => 'phone-country',
            'selected_country' => 'OM',
            'value' => '',
            'placeholder' => 'Enter phone number',
            'required' => false,
            'label' => 'Phone Number',
            'show_label' => true,
            'helper_text' => ''
        ];
        
        $opts = array_merge($defaults, $options);
        $required = $opts['required'] ? 'required' : '';
        $countries = self::getCountriesWithPhoneCodes();
        $selectedCountry = $countries[$opts['selected_country']] ?? $countries['OM'];
        
        $html = '';
        
        // Label
        if ($opts['show_label'] && $opts['label']) {
            $html .= sprintf(
                '<label for="%s" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">%s</label>',
                htmlspecialchars($opts['id']),
                htmlspecialchars($opts['label'])
            );
        }
        
        // Phone input container - Flowbite style
        $html .= '<div class="flex">';
        
        // Country select with flag display
        $html .= sprintf('
            <div class="flex-shrink-0 relative">
                <button type="button" id="%s-btn" data-dropdown-toggle="%s-dropdown" 
                    class="inline-flex items-center py-2.5 px-4 text-sm font-medium text-center text-gray-500 bg-gray-100 border border-gray-300 rounded-s-lg border-e-0 hover:bg-gray-200 focus:ring-4 focus:outline-none focus:ring-gray-100 dark:bg-gray-700 dark:hover:bg-gray-600 dark:focus:ring-gray-700 dark:text-white dark:border-gray-600">
                    <span class="%s fis rounded-sm me-2" id="%s-flag"></span>
                    <span id="%s-code">%s</span>
                    <svg class="w-2.5 h-2.5 ms-2.5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 10 6">
                        <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 4 4 4-4"/>
                    </svg>
                </button>
                <div id="%s-dropdown" class="absolute left-0 top-full mt-1 z-50 hidden bg-white divide-y divide-gray-100 rounded-lg shadow-lg w-60 dark:bg-gray-700">
                    <ul class="py-2 text-sm text-gray-700 dark:text-gray-200 max-h-64 overflow-y-auto">',
            $opts['country_id'],
            $opts['country_id'],
            $selectedCountry['flag_class'],
            $opts['country_id'],
            $opts['country_id'],
            $selectedCountry['phone_code'],
            $opts['country_id']
        );
        
        // Generate country list items
        $countriesWithPhone = self::getCountriesWithPhoneCodes();
        $gccCodes = ['OM', 'AE', 'SA', 'BH', 'KW', 'QA'];
        $gccCountries = [];
        $otherCountries = [];
        
        foreach ($countriesWithPhone as $code => $country) {
            if (in_array($code, $gccCodes)) {
                $gccCountries[$code] = $country;
            } else {
                $otherCountries[$code] = $country;
            }
        }
        uasort($otherCountries, fn($a, $b) => strcmp($a['name'], $b['name']));
        $sortedCountries = $gccCountries + $otherCountries;
        
        foreach ($sortedCountries as $code => $country) {
            $html .= sprintf('
                        <li>
                            <button type="button" onclick="selectPhoneCountry(\'%s\', \'%s\', \'%s\', \'%s\')" 
                                class="inline-flex w-full px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-600 dark:hover:text-white">
                                <span class="%s fis rounded-sm me-2"></span>
                                %s <span class="ms-auto text-gray-500">%s</span>
                            </button>
                        </li>',
                $opts['country_id'],
                $code,
                $country['phone_code'],
                $country['flag_class'],
                $country['flag_class'],
                htmlspecialchars($country['name']),
                $country['phone_code']
            );
        }
        
        $html .= '
                    </ul>
                </div>
            </div>';
        
        // Hidden country input
        $html .= sprintf(
            '<input type="hidden" name="%s" id="%s" value="%s">',
            htmlspecialchars($opts['country_name']),
            htmlspecialchars($opts['country_id']),
            htmlspecialchars($opts['selected_country'])
        );
        
        // Phone number input - Flowbite style
        $html .= sprintf('
            <input type="tel" 
                name="%s" 
                id="%s" 
                value="%s"
                class="block p-2.5 w-full z-20 text-sm text-gray-900 bg-gray-50 rounded-e-lg border border-gray-300 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:border-blue-500" 
                placeholder="%s" 
                %s>',
            htmlspecialchars($opts['name']),
            htmlspecialchars($opts['id']),
            htmlspecialchars($opts['value']),
            htmlspecialchars($opts['placeholder']),
            $required
        );
        
        $html .= '</div>';
        
        // Helper text
        if ($opts['helper_text']) {
            $html .= sprintf(
                '<p class="mt-2 text-sm text-gray-500 dark:text-gray-400">%s</p>',
                htmlspecialchars($opts['helper_text'])
            );
        }
        
        return $html;
    }
    
    /**
     * Get JavaScript for phone input components
     * Handles dropdown interactions, search, and country selection
     */
    public static function getPhoneInputJavaScript() {
        return <<<'JS'
<script>
// Phone country selection handler for simple phone inputs
function selectPhoneCountry(inputId, countryCode, phoneCode, flagClass) {
    // Update hidden input
    const hiddenInput = document.getElementById(inputId);
    if (hiddenInput) hiddenInput.value = countryCode;
    
    // Update displayed phone code
    const codeDisplay = document.getElementById(inputId + '-code');
    if (codeDisplay) codeDisplay.textContent = phoneCode;
    
    // Update flag
    const flagDisplay = document.getElementById(inputId + '-flag');
    if (flagDisplay) {
        flagDisplay.className = flagClass + ' fis rounded-sm me-2';
    }
    
    // Close dropdown
    const dropdown = document.getElementById(inputId + '-dropdown');
    if (dropdown) dropdown.classList.add('hidden');
}

// Phone Input Component Handler
const CardifyPhoneInput = {
    init: function() {
        // Handle dropdown toggles (if Flowbite isn't loaded)
        document.querySelectorAll('[data-dropdown-toggle]').forEach(button => {
            const dropdownId = button.getAttribute('data-dropdown-toggle');
            const dropdown = document.getElementById(dropdownId);
            if (!dropdown) return;
            
            // Only attach if not already handled by Flowbite
            if (!button.hasAttribute('data-phone-handler-attached')) {
                button.setAttribute('data-phone-handler-attached', 'true');
                button.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    dropdown.classList.toggle('hidden');
                    
                    // Focus search input if exists
                    const searchInput = dropdown.querySelector('input[type="text"]');
                    if (searchInput) setTimeout(() => searchInput.focus(), 100);
                });
            }
        });
        
        // Handle advanced phone input country selection
        document.querySelectorAll('[data-phone-list] button').forEach(button => {
            if (button.hasAttribute('data-phone-handler-attached')) return;
            button.setAttribute('data-phone-handler-attached', 'true');
            
            button.addEventListener('click', (e) => {
                e.preventDefault();
                const countryCode = button.getAttribute('data-country');
                const phoneCode = button.getAttribute('data-phone-code');
                const flagClass = button.getAttribute('data-flag-class');
                
                // Find parent dropdown and related elements
                const listEl = button.closest('[data-phone-list]');
                const uniqueId = listEl.getAttribute('data-phone-list');
                
                // Update display
                const codeDisplay = document.querySelector(`[data-phone-code-display="${uniqueId}"]`);
                if (codeDisplay) codeDisplay.textContent = phoneCode;
                
                // Update flag in button
                const btn = document.getElementById(uniqueId + '-btn');
                if (btn) {
                    const flagSpan = btn.querySelector('span[class*="fi-"]');
                    if (flagSpan) flagSpan.className = flagClass + ' fis rounded-sm me-2';
                }
                
                // Update hidden country input (find by looking for related input)
                const form = button.closest('form');
                if (form) {
                    const hiddenInputs = form.querySelectorAll('input[type="hidden"]');
                    hiddenInputs.forEach(input => {
                        if (input.name.includes('country')) {
                            input.value = countryCode;
                        }
                    });
                }
                
                // Close dropdown
                const dropdown = document.getElementById(uniqueId + '-dropdown');
                if (dropdown) dropdown.classList.add('hidden');
            });
        });
        
        // Handle search in dropdown
        document.querySelectorAll('[id$="-search"]').forEach(searchInput => {
            if (searchInput.hasAttribute('data-phone-handler-attached')) return;
            searchInput.setAttribute('data-phone-handler-attached', 'true');
            
            searchInput.addEventListener('input', (e) => {
                const searchTerm = e.target.value.toLowerCase();
                const dropdown = searchInput.closest('[id$="-dropdown"]');
                const items = dropdown.querySelectorAll('li');
                
                items.forEach(li => {
                    const btn = li.querySelector('button');
                    if (!btn) return;
                    const text = btn.textContent.toLowerCase();
                    li.style.display = text.includes(searchTerm) ? '' : 'none';
                });
            });
        });
        
        // Close dropdowns when clicking outside
        document.addEventListener('click', (e) => {
            if (!e.target.closest('[data-dropdown-toggle]') && !e.target.closest('[id$="-dropdown"]')) {
                document.querySelectorAll('[id$="-dropdown"]').forEach(dropdown => {
                    if (!dropdown.classList.contains('hidden')) {
                        dropdown.classList.add('hidden');
                    }
                });
            }
        });
    }
};

// Auto-initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => CardifyPhoneInput.init());
} else {
    CardifyPhoneInput.init();
}
</script>
JS;
    }
    
    /**
     * Render complete phone input component with all necessary JS
     * @param array $options Configuration options
     * @param bool $includeJs Whether to include the JavaScript
     */
    public static function renderPhoneInputComplete($options = [], $includeJs = true) {
        $html = self::renderPhoneInput($options);
        if ($includeJs) {
            $html .= self::getPhoneInputJavaScript();
        }
        return $html;
    }
    
    /**
     * Get full country data for Alpine.js or JavaScript use
     */
    public static function getCountryDataJson() {
        return json_encode(self::getCountriesWithPhoneCodes());
    }
    
    /**
     * Get standard Alpine.js phone input component data
     * Use with x-data="CardifyPhoneData()"
     */
    public static function getAlpinePhoneData($selectedCountry = 'OM') {
        $countries = self::getCountriesWithPhoneCodes();
        $countriesJson = json_encode($countries);
        $selected = $countries[$selectedCountry] ?? $countries['OM'];
        
        return <<<JS
function CardifyPhoneData() {
    return {
        countries: {$countriesJson},
        selectedCountry: '{$selectedCountry}',
        selectedFlagClass: '{$selected['flag_class']}',
        selectedPhoneCode: '{$selected['phone_code']}',
        phoneNumber: '',
        isOpen: false,
        searchTerm: '',
        
        get filteredCountries() {
            if (!this.searchTerm) return Object.entries(this.countries);
            const term = this.searchTerm.toLowerCase();
            return Object.entries(this.countries).filter(([code, country]) => 
                country.name.toLowerCase().includes(term) || 
                country.phone_code.includes(term)
            );
        },
        
        selectCountry(code) {
            this.selectedCountry = code;
            this.selectedFlagClass = this.countries[code].flag_class;
            this.selectedPhoneCode = this.countries[code].phone_code;
            this.isOpen = false;
            this.searchTerm = '';
        },
        
        getFullPhoneNumber() {
            return this.selectedPhoneCode + this.phoneNumber;
        }
    }
}
JS;
    }

    // ========================================================================
    // Multi-currency display layer (added 2026-04-10 for free-forever pivot)
    //
    // OMR is the canonical charging currency. These helpers convert OMR
    // amounts to any supported display currency via the fx_rates table and
    // resolve the user's preferred display currency.
    //
    // All rates come from fx_rates — no hardcoded conversion constants.
    // ========================================================================

    /** Supported currency whitelist for the display layer. Order matters for the header dropdown. */
    const SUPPORTED = [
        'OMR' => 'Omani Rial',
        'USD' => 'US Dollar',
        'AED' => 'UAE Dirham',
        'SAR' => 'Saudi Riyal',
        'EUR' => 'Euro',
    ];

    /** Cookie name for persistent user preference. */
    const COOKIE_NAME = 'cardify_currency';

    /** In-process cache so we don't hit fx_rates on every convert() call in a request. */
    private static $rateCache = null;

    /**
     * Convert an OMR amount to another currency.
     * Returns the original amount unchanged if target is OMR, unknown, or rate missing.
     *
     * @param float $omrAmount Amount in OMR
     * @param string $toCurrency Target currency code (3 letters)
     * @return float Converted amount
     */
    public static function convert(float $omrAmount, string $toCurrency): float {
        $toCurrency = strtoupper($toCurrency);
        if ($toCurrency === 'OMR') return $omrAmount;
        if (!isset(self::SUPPORTED[$toCurrency])) return $omrAmount;

        $rate = self::getRate($toCurrency);
        if ($rate === null || $rate <= 0) return $omrAmount;

        return $omrAmount * $rate;
    }

    /**
     * Resolve the user's display currency in priority order:
     *   1. Logged-in company's currency column (if set)
     *   2. Session/cookie 'cardify_currency'
     *   3. Auto-detect from CF-IPCountry header
     *   4. Fallback to OMR
     */
    public static function getUserCurrency(): string {
        // 1. Logged-in company
        if (isset($_SESSION['company']['currency'])) {
            $cur = strtoupper($_SESSION['company']['currency']);
            if (isset(self::SUPPORTED[$cur])) return $cur;
        }

        // 2. Cookie
        if (!empty($_COOKIE[self::COOKIE_NAME])) {
            $cur = strtoupper($_COOKIE[self::COOKIE_NAME]);
            if (isset(self::SUPPORTED[$cur])) return $cur;
        }

        // 3. Auto-detect from CF-IPCountry (Cloudflare sets this on every request)
        $country = $_SERVER['HTTP_CF_IPCOUNTRY'] ?? '';
        $countryMap = [
            'OM' => 'OMR',
            'AE' => 'AED',
            'SA' => 'SAR',
            'US' => 'USD', 'GB' => 'USD', 'CA' => 'USD', 'AU' => 'USD',
            'DE' => 'EUR', 'FR' => 'EUR', 'IT' => 'EUR', 'ES' => 'EUR', 'NL' => 'EUR',
        ];
        if (isset($countryMap[$country])) return $countryMap[$country];

        // 4. Fallback
        return 'OMR';
    }

    /**
     * Persist user's currency preference (cookie + logged-in company row).
     */
    public static function setUserCurrency(string $currency): bool {
        $currency = strtoupper($currency);
        if (!isset(self::SUPPORTED[$currency])) return false;

        // Cookie: 365 days, secure, httponly=false so JS can read if needed
        setcookie(self::COOKIE_NAME, $currency, [
            'expires' => time() + 86400 * 365,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE_NAME] = $currency;

        // If logged in, persist to companies.currency
        if (isset($_SESSION['company']['id'])) {
            try {
                $db = Database::getInstance();
                $db->update('companies', ['currency' => $currency], 'id = :id', ['id' => $_SESSION['company']['id']]);
                $_SESSION['company']['currency'] = $currency;
            } catch (Exception $e) {
                error_log('[Currency] failed to persist currency to company row: ' . $e->getMessage());
            }
        }
        return true;
    }

    /**
     * List of supported display currencies as [code => display_name].
     * Used by the header dropdown and the /api/set-currency endpoint.
     */
    public static function supportedCurrencies(): array {
        return self::SUPPORTED;
    }

    /**
     * Load all rates from fx_rates into the in-process cache.
     */
    private static function loadRates(): void {
        if (self::$rateCache !== null) return;
        self::$rateCache = ['OMR' => 1.0];
        try {
            $db = Database::getInstance();
            $rows = $db->fetchAll(
                "SELECT target_currency, rate FROM fx_rates WHERE base_currency = 'OMR'"
            );
            foreach ($rows as $row) {
                self::$rateCache[strtoupper($row['target_currency'])] = (float)$row['rate'];
            }
        } catch (Exception $e) {
            error_log('[Currency] failed to load fx_rates: ' . $e->getMessage());
        }
    }

    private static function getRate(string $target): ?float {
        self::loadRates();
        return self::$rateCache[$target] ?? null;
    }
}
