<?php
/**
 * VCF (vCard) Generator Helper Class
 * Generates vCard 3.0 format files for employee contact information
 */
require_once __DIR__ . '/VCardRfc.php';

class VCF {
    
    /**
     * Generate vCard content for an employee
     * @param array $employee Employee data
     * @param array $company Company data (optional)
     * @return string vCard content
     */
    public static function generate($employee, $company = null) {
        $lines = [];
        
        // vCard header
        $lines[] = 'BEGIN:VCARD';
        $lines[] = 'VERSION:3.0';
        
        // NOTE ON CHARSET: this file emits none. CHARSET is a vCard 2.1
        // parameter and is undefined in RFC 2426, which this file declares in
        // VERSION:3.0, where UTF-8 is already the default. Measured on a booted
        // iPhone 17 through CNContactVCardSerialization: an Arabic card with and
        // without CHARSET=UTF-8 parses to byte-identical CNContact fields. So it
        // buys nothing and would add a non-conformant parameter to the bytes
        // behind every QR code already in print.
        //
        // Every guard below is a strict "!== ''" test, never !empty(). empty()
        // is true for the literal string "0", which silently dropped a building
        // number, a floor, an extension, a note or a street of "0".

        // Full name. Emitted for the platforms that read it; iOS does NOT, it
        // discards FN and renders from N alone (see VCardRfc::splitName()).
        $fullName = self::getFullName($employee);
        $lines[] = 'FN:' . self::escape($fullName);

        // Structured name: N:LastName;FirstName;MiddleName;Prefix;Suffix.
        // This is the property Apple actually reads.
        $nameParts = self::parseNameParts($employee);
        $lines[] = 'N:' . self::escape($nameParts['last']) . ';' .
                   self::escape($nameParts['first']) . ';' .
                   self::escape($nameParts['middle']) . ';;';

        // Arabic name as an extra, and ONLY when it is not already the FN above,
        // otherwise the same string ships twice.
        //
        // X-PHONETIC-LAST-NAME was removed deliberately: iOS maps the phonetic
        // fields to the SORT key, so stuffing a whole Arabic name in there filed
        // the contact under that string in the Latin address book.
        $nameAr = self::firstNonEmpty($employee['name_ar'] ?? null);
        if ($nameAr !== '' && $nameAr !== $fullName) {
            $lines[] = 'X-ALT-NAME;LANGUAGE=ar:' . self::escape($nameAr);
        }

        // Organization. English wins when present; an Arabic-only card puts the
        // Arabic company name in ORG rather than emitting no ORG at all.
        $orgName = self::firstNonEmpty(
            $company['name'] ?? null, $company['name_en'] ?? null,
            $employee['company_name'] ?? null, $employee['company_en'] ?? null
        );
        $orgNameAr = self::firstNonEmpty($company['name_ar'] ?? null, $employee['company_ar'] ?? null);
        $orgPrimary = $orgName !== '' ? $orgName : $orgNameAr;
        if ($orgPrimary !== '') {
            $lines[] = 'ORG:' . self::escape($orgPrimary);
        }
        // Arabic organisation as X-property, only when it is not already ORG.
        if ($orgNameAr !== '' && $orgNameAr !== $orgPrimary) {
            $lines[] = 'X-ORG;LANGUAGE=ar:' . self::escape($orgNameAr);
        }

        // Title/Position, same rule: Arabic-only card gets a real TITLE.
        $title = self::firstNonEmpty(
            $employee['position_en'] ?? null, $employee['position'] ?? null, $employee['title'] ?? null
        );
        $titleAr = self::firstNonEmpty($employee['position_ar'] ?? null);
        $titlePrimary = $title !== '' ? $title : $titleAr;
        if ($titlePrimary !== '') {
            $lines[] = 'TITLE:' . self::escape($titlePrimary);
        }
        // Arabic title as X-property, only when it is not already TITLE.
        if ($titleAr !== '' && $titleAr !== $titlePrimary) {
            $lines[] = 'X-TITLE;LANGUAGE=ar:' . self::escape($titleAr);
        }

        // Department
        $department = self::firstNonEmpty(
            $employee['department_en'] ?? null, $employee['department'] ?? null,
            $employee['department_ar'] ?? null
        );
        if ($department !== '') {
            $lines[] = 'X-DEPARTMENT:' . self::escape($department);
        }

        // Phone numbers (English/Western numerals). De-dupe: if phone and
        // mobile are the same number in different formats, emit it once so the
        // saved contact doesn't show two identical numbers.
        $phone  = self::firstNonEmpty($employee['phone'] ?? null);
        $mobile = self::firstNonEmpty($employee['mobile'] ?? null);
        $fax    = self::firstNonEmpty($employee['fax'] ?? null);
        $__pDig = preg_replace('/\D+/', '', $phone);
        $__mDig = preg_replace('/\D+/', '', $mobile);
        $__samePhoneMobile = ($__pDig !== '' && $__pDig === $__mDig);
        if ($phone !== '' && !$__samePhoneMobile) {
            $lines[] = 'TEL;TYPE=WORK,VOICE:' . self::escape($phone);
        }
        if ($mobile !== '') {
            $lines[] = 'TEL;TYPE=CELL,VOICE:' . self::escape($mobile);
        }
        if ($fax !== '') {
            $lines[] = 'TEL;TYPE=FAX:' . self::escape($fax);
        }

        // Phone numbers (Arabic numerals) - stored as X-properties for reference
        $phoneAr  = self::firstNonEmpty($employee['phone_ar'] ?? null);
        $mobileAr = self::firstNonEmpty($employee['mobile_ar'] ?? null);
        if ($phoneAr !== '') {
            $lines[] = 'X-TEL-AR;TYPE=WORK:' . self::escape($phoneAr);
        }
        if ($mobileAr !== '') {
            $lines[] = 'X-TEL-AR;TYPE=CELL:' . self::escape($mobileAr);
        }

        // Email
        $email = self::firstNonEmpty($employee['email'] ?? null);
        if ($email !== '') {
            $lines[] = 'EMAIL;TYPE=INTERNET,WORK:' . self::escape($email);
        }

        // Website (English)
        $website = self::firstNonEmpty($employee['website'] ?? null, $company['website'] ?? null);
        if ($website !== '') {
            // Ensure URL has protocol
            if (!preg_match('/^https?:\/\//i', $website)) {
                $website = 'https://' . $website;
            }
            $lines[] = 'URL:' . self::escape($website);
        }

        // Website (Arabic)
        $websiteAr = self::firstNonEmpty($employee['website_ar'] ?? null);
        if ($websiteAr !== '') {
            if (!preg_match('/^https?:\/\//i', $websiteAr)) {
                $websiteAr = 'https://' . $websiteAr;
            }
            $lines[] = 'X-URL;LANGUAGE=ar:' . self::escape($websiteAr);
        }

        // Address (English)
        $address = self::buildAddress($employee, $company, 'en');
        if ($address !== '') {
            // ADR: PO Box;Extended;Street;City;Region;Postal;Country
            $lines[] = 'ADR;TYPE=WORK:;;' . $address;
        }

        // Address (Arabic)
        $addressAr = self::buildAddress($employee, $company, 'ar');
        if ($addressAr !== '') {
            $lines[] = 'ADR;TYPE=WORK;LANGUAGE=ar:;;' . $addressAr;
        }

        // Photo (if available as URL)
        $photo = self::firstNonEmpty($employee['photo'] ?? null, $employee['photo_url'] ?? null);
        if ($photo !== '' && filter_var($photo, FILTER_VALIDATE_URL)) {
            $lines[] = 'PHOTO;VALUE=URI:' . $photo;
        }

        // Note
        $note = self::firstNonEmpty($employee['note'] ?? null, $employee['bio'] ?? null);
        if ($note !== '') {
            $lines[] = 'NOTE:' . self::escape($note);
        }

        // Social media as X- properties
        $linkedin = self::firstNonEmpty($employee['linkedin'] ?? null);
        $twitter  = self::firstNonEmpty($employee['twitter'] ?? null);
        if ($linkedin !== '') {
            $lines[] = 'X-SOCIALPROFILE;TYPE=linkedin:' . self::escape($linkedin);
        }
        if ($twitter !== '') {
            $lines[] = 'X-SOCIALPROFILE;TYPE=twitter:' . self::escape($twitter);
        }
        
        // Revision timestamp
        $lines[] = 'REV:' . date('Y-m-d\TH:i:s\Z');
        
        // Unique ID
        $uid = $employee['id'] ?? $employee['email'] ?? uniqid();
        $lines[] = 'UID:' . self::escape($uid);
        
        // vCard footer
        $lines[] = 'END:VCARD';

        // Fold AFTER escaping, never before: escaping adds octets (a comma
        // becomes "\,"), so folding first would leave over-length lines behind.
        // RFC 2426 section 2.6. Arabic runs 2 octets per letter, so an Omani
        // address crosses 75 octets in about 37 characters.
        return implode("\r\n", VCardRfc::foldAll($lines, "\r\n"));
    }

    /**
     * First value that is a non-empty string, after casting.
     *
     * Deliberately NOT empty(): empty() is false for the literal string "0", so
     * a building number, floor or PO box of "0" would vanish. Deliberately not
     * ?? either: ?? only falls through on null, so an explicit empty-string
     * column would win over a populated fallback.
     */
    private static function firstNonEmpty(...$values) {
        foreach ($values as $value) {
            if ($value === null || is_array($value) || is_object($value)) {
                continue;
            }
            $value = (string) $value;
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }
    
    /**
     * Get full name from employee data
     */
    private static function getFullName($employee) {
        // Try English name first
        $nameEn = self::firstNonEmpty($employee['name_en'] ?? null, $employee['name'] ?? null);
        if ($nameEn !== '') {
            return $nameEn;
        }
        // Construct from first/last
        $first = self::firstNonEmpty($employee['first_name'] ?? null, $employee['first_name_en'] ?? null);
        $last  = self::firstNonEmpty($employee['last_name'] ?? null, $employee['last_name_en'] ?? null);
        if ($first !== '' || $last !== '') {
            return trim($first . ' ' . $last);
        }
        // Arabic-only card: the Arabic name IS the name. This has to sit ABOVE
        // the email fallback. Below it, an Arabic-only contact landed in iOS and
        // Google Contacts named "someone@example.om" while the real name sat in
        // an X- property neither app reads.
        $nameAr = self::firstNonEmpty($employee['name_ar'] ?? null);
        if ($nameAr !== '') {
            return $nameAr;
        }
        // Last resort
        return self::firstNonEmpty($employee['email'] ?? null) ?: 'Contact';
    }
    
    /**
     * Parse name into parts (first, middle, last)
     */
    private static function parseNameParts($employee) {
        $parts = [
            'first' => '',
            'middle' => '',
            'last' => ''
        ];
        
        // Explicit columns are authoritative. If the record actually carries a
        // first_name/last_name we never guess.
        $explicitFirst = self::firstNonEmpty($employee['first_name'] ?? null);
        $explicitLast  = self::firstNonEmpty($employee['last_name'] ?? null);
        if ($explicitFirst !== '' || $explicitLast !== '') {
            $parts['first']  = $explicitFirst;
            $parts['last']   = $explicitLast;
            $parts['middle'] = self::firstNonEmpty($employee['middle_name'] ?? null);
            return $parts;
        }

        // Otherwise derive N from the printed name under the shared policy: last
        // token is the family name (what Apple's own fallback splitter does, and
        // right for Omani names, which run 3-4 tokens ending in the family name),
        // except for explicit patronymic chains. See VCardRfc::splitName().
        $split = VCardRfc::splitName(self::getFullName($employee));
        $parts['first']  = $split['given'];
        $parts['middle'] = $split['middle'];
        $parts['last']   = $split['family'];

        return $parts;
    }
    
    /**
     * Build address string for vCard
     * @param array $employee Employee data
     * @param array $company Company data
     * @param string $lang Language code ('en' or 'ar')
     * @return string Formatted address string
     */
    private static function buildAddress($employee, $company, $lang = 'en') {
        $parts = [];
        
        // Street address - check language-specific fields first
        if ($lang === 'ar') {
            $street = self::firstNonEmpty($employee['address_ar'] ?? null, $company['address_ar'] ?? null);
        } else {
            $street = self::firstNonEmpty(
                $employee['address_en'] ?? null, $employee['address'] ?? null,
                $company['address_en'] ?? null, $company['address'] ?? null
            );
        }
        $parts[] = self::escape($street);

        // City
        if ($lang === 'ar') {
            $city = self::firstNonEmpty($employee['city_ar'] ?? null, $company['city_ar'] ?? null);
        } else {
            $city = self::firstNonEmpty($employee['city'] ?? null, $company['city'] ?? null);
        }
        $parts[] = self::escape($city);

        // State/Region
        if ($lang === 'ar') {
            $state = self::firstNonEmpty($employee['state_ar'] ?? null, $company['state_ar'] ?? null);
        } else {
            $state = self::firstNonEmpty($employee['state'] ?? null, $company['state'] ?? null);
        }
        $parts[] = self::escape($state);

        // Postal code (same for both languages)
        $postal = self::firstNonEmpty($employee['postal_code'] ?? null, $company['postal_code'] ?? null);
        $parts[] = self::escape($postal);

        // Country
        if ($lang === 'ar') {
            $country = self::firstNonEmpty($employee['country_ar'] ?? null, $company['country_ar'] ?? null);
        } else {
            $country = self::firstNonEmpty($employee['country'] ?? null, $company['country'] ?? null);
        }
        $parts[] = self::escape($country);

        // The postal code is language-neutral and shared by both blocks, so on
        // its own it must not conjure an Arabic address onto a card that carries
        // no Arabic data at all. Before "0" was allowed to survive escape() this
        // was masked, because a postal code of "0" got dropped anyway and the
        // all-empty check below caught it.
        if ($lang === 'ar' && $street === '' && $city === '' && $state === '' && $country === '') {
            return '';
        }

        // Return only if we have at least one non-empty part. Strict compare,
        // not array_filter()'s default truthiness test, which discards "0" and
        // would drop an address whose only populated component is a PO box,
        // floor or building number of "0".
        $filtered = array_filter($parts, function ($part) { return $part !== ''; });
        if (empty($filtered)) {
            return '';
        }

        return implode(';', $parts);
    }
    
    /**
     * Escape special characters for vCard format
     */
    private static function escape($value) {
        // Strict, not empty(): empty() is false for the literal string "0", so a
        // building number, floor or PO box of "0" was silently dropped from the
        // emitted vCard.
        $value = (string) ($value ?? '');
        if ($value === '') {
            return '';
        }

        // vCard escaping rules
        $value = str_replace('\\', '\\\\', $value);  // Backslash first
        $value = str_replace(',', '\\,', $value);
        $value = str_replace(';', '\\;', $value);
        $value = str_replace("\n", '\\n', $value);
        $value = str_replace("\r", '', $value);
        
        return $value;
    }
    
    /**
     * Save VCF file for an employee
     * @param array $employee Employee data
     * @param array $company Company data
     * @param string|null $customPath Custom path (optional)
     * @return array ['success' => bool, 'path' => string, 'url' => string, 'error' => string]
     */
    public static function save($employee, $company, $customPath = null) {
        $companyId = $company['id'] ?? $employee['company_id'] ?? null;
        
        if (!$companyId) {
            return ['success' => false, 'error' => 'Company ID required'];
        }
        
        // Generate VCF content
        $vcfContent = self::generate($employee, $company);
        
        // Determine file path
        if ($customPath) {
            $filePath = $customPath;
        } else {
            // Default path: /uploads/companies/{company_id}/vcf/{email}.vcf
            $vcfDir = getCompanyUploadsPath($companyId) . '/vcf';
            if (!is_dir($vcfDir)) {
                @mkdir($vcfDir, 0755, true);
            }
            
            // Use email as filename (sanitized)
            $filename = self::sanitizeFilename($employee['email']) . '.vcf';
            $filePath = $vcfDir . '/' . $filename;
        }
        
        // Save file
        $result = @file_put_contents($filePath, $vcfContent);
        
        if ($result === false) {
            return ['success' => false, 'error' => 'Failed to write VCF file'];
        }
        
        // Generate URL
        $webPath = getWebPath($filePath);
        $url = getBaseUrl() . ltrim($webPath, '/');
        
        return [
            'success' => true,
            'path' => $filePath,
            'web_path' => $webPath,
            'url' => $url,
            'filename' => basename($filePath)
        ];
    }
    
    /**
     * Get VCF URL for an employee
     * Short format for smaller QR codes: /{slug}/{email}.vcf
     * @param array $employee Employee data
     * @param array $company Company data
     * @param bool $tracked Whether to use tracking URL (default: true)
     * @return string VCF URL
     */
    public static function getUrl($employee, $company, $tracked = true) {
        $slug = $company['slug'] ?? '';
        $email = $employee['email'] ?? '';
        
        if (empty($slug) || empty($email)) {
            return '';
        }
        
        if ($tracked) {
            // Use tracking URL format: /qr.php?c={slug}&e={email}
            return self::getTrackingUrl($employee, $company);
        }
        
        // Direct VCF URL format: /{company_slug}/{email}.vcf
        // Example: https://cardify.om/acme/john@acme.com.vcf
        return getBaseUrl() . urlencode($slug) . '/' . urlencode($email) . '.vcf';
    }
    
    /**
     * Get tracking URL for QR code scans
     * This URL logs analytics before serving the VCF
     * @param array $employee Employee data
     * @param array $company Company data
     * @return string Tracking URL
     */
    public static function getTrackingUrl($employee, $company) {
        $slug = $company['slug'] ?? '';
        $email = $employee['email'] ?? '';
        
        if (empty($slug) || empty($email)) {
            return '';
        }
        
        // Tracking URL format: /qr.php?c={slug}&e={email}
        // Example: https://cardify.om/qr.php?c=acme&e=john@acme.com
        return getBaseUrl() . 'qr.php?c=' . urlencode($slug) . '&e=' . urlencode($email);
    }
    
    /**
     * Get direct VCF URL (no tracking)
     * @param array $employee Employee data
     * @param array $company Company data
     * @return string Direct VCF URL
     */
    public static function getDirectUrl($employee, $company) {
        return self::getUrl($employee, $company, false);
    }
    
    /**
     * Get short VCF path (without domain) for QR codes
     * @param array $employee Employee data
     * @param array $company Company data
     * @return string Short path like /bhd/ali@bhd.om.vcf
     */
    public static function getShortPath($employee, $company) {
        $slug = $company['slug'] ?? '';
        $email = $employee['email'] ?? '';
        
        if (empty($slug) || empty($email)) {
            return '';
        }
        
        return '/' . urlencode($slug) . '/' . urlencode($email) . '.vcf';
    }
    
    /**
     * Sanitize filename for safe storage
     * @param string $filename Original filename
     * @return string Sanitized filename
     */
    public static function sanitizeFilename($filename) {
        // Remove or replace invalid characters
        $filename = preg_replace('/[^a-zA-Z0-9@._-]/', '_', $filename);
        $filename = preg_replace('/_+/', '_', $filename);
        return strtolower(trim($filename, '_'));
    }
    
    /**
     * Generate QR code URL for VCF
     * Uses a simple QR code service or returns data for client-side generation
     * @param string $vcfUrl URL to the VCF file
     * @param int $size QR code size
     * @return string QR code image URL
     */
    public static function getQRCodeUrl($vcfUrl, $size = 200) {
        // Use Google Charts API (simple, no library needed)
        // Note: This is deprecated but still works. For production, consider using a library.
        return 'https://chart.googleapis.com/chart?cht=qr&chs=' . $size . 'x' . $size . 
               '&chl=' . urlencode($vcfUrl) . '&choe=UTF-8';
    }
    
    /**
     * Generate QR code data URL using inline SVG
     * This avoids external dependencies
     * @param string $data Data to encode
     * @return array ['svg' => string, 'data_url' => string]
     */
    public static function generateQRSvg($data) {
        // This is a placeholder - for full implementation, use a QR library
        // For now, return a link to use client-side QR generation
        return [
            'svg' => null,
            'data' => $data,
            'use_client' => true
        ];
    }
}
