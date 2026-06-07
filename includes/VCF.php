<?php
/**
 * VCF (vCard) Generator Helper Class
 * Generates vCard 3.0 format files for employee contact information
 */
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
        
        // Full name (required) - English version
        $fullName = self::getFullName($employee);
        $lines[] = 'FN:' . self::escape($fullName);
        
        // Structured name: N:LastName;FirstName;MiddleName;Prefix;Suffix
        $nameParts = self::parseNameParts($employee);
        $lines[] = 'N:' . self::escape($nameParts['last']) . ';' . 
                   self::escape($nameParts['first']) . ';' .
                   self::escape($nameParts['middle']) . ';;';
        
        // Arabic name as alternate (X-property for compatibility)
        if (!empty($employee['name_ar'])) {
            $lines[] = 'X-PHONETIC-LAST-NAME;LANGUAGE=ar:' . self::escape($employee['name_ar']);
            $lines[] = 'X-ALT-NAME;LANGUAGE=ar:' . self::escape($employee['name_ar']);
        }
        
        // Organization (English)
        $orgName = $company['name'] ?? $company['name_en'] ?? $employee['company_name'] ?? $employee['company_en'] ?? '';
        if (!empty($orgName)) {
            $lines[] = 'ORG:' . self::escape($orgName);
        }
        
        // Organization (Arabic) as X-property
        $orgNameAr = $company['name_ar'] ?? $employee['company_ar'] ?? '';
        if (!empty($orgNameAr)) {
            $lines[] = 'X-ORG;LANGUAGE=ar:' . self::escape($orgNameAr);
        }
        
        // Title/Position (English)
        $title = $employee['position_en'] ?? $employee['position'] ?? $employee['title'] ?? '';
        if (!empty($title)) {
            $lines[] = 'TITLE:' . self::escape($title);
        }
        
        // Title/Position (Arabic) as X-property
        if (!empty($employee['position_ar'])) {
            $lines[] = 'X-TITLE;LANGUAGE=ar:' . self::escape($employee['position_ar']);
        }
        
        // Department
        $department = $employee['department_en'] ?? $employee['department'] ?? '';
        if (!empty($department)) {
            $lines[] = 'X-DEPARTMENT:' . self::escape($department);
        }
        
        // Phone numbers (English/Western numerals). De-dupe: if phone and
        // mobile are the same number in different formats, emit it once so the
        // saved contact doesn't show two identical numbers.
        $__pDig = preg_replace('/\D+/', '', (string) ($employee['phone'] ?? ''));
        $__mDig = preg_replace('/\D+/', '', (string) ($employee['mobile'] ?? ''));
        $__samePhoneMobile = ($__pDig !== '' && $__pDig === $__mDig);
        if (!empty($employee['phone']) && !$__samePhoneMobile) {
            $lines[] = 'TEL;TYPE=WORK,VOICE:' . self::escape($employee['phone']);
        }
        if (!empty($employee['mobile'])) {
            $lines[] = 'TEL;TYPE=CELL,VOICE:' . self::escape($employee['mobile']);
        }
        if (!empty($employee['fax'])) {
            $lines[] = 'TEL;TYPE=FAX:' . self::escape($employee['fax']);
        }
        
        // Phone numbers (Arabic numerals) - stored as X-properties for reference
        if (!empty($employee['phone_ar'])) {
            $lines[] = 'X-TEL-AR;TYPE=WORK:' . self::escape($employee['phone_ar']);
        }
        if (!empty($employee['mobile_ar'])) {
            $lines[] = 'X-TEL-AR;TYPE=CELL:' . self::escape($employee['mobile_ar']);
        }
        
        // Email
        if (!empty($employee['email'])) {
            $lines[] = 'EMAIL;TYPE=INTERNET,WORK:' . self::escape($employee['email']);
        }
        
        // Website (English)
        $website = $employee['website'] ?? $company['website'] ?? '';
        if (!empty($website)) {
            // Ensure URL has protocol
            if (!preg_match('/^https?:\/\//i', $website)) {
                $website = 'https://' . $website;
            }
            $lines[] = 'URL:' . self::escape($website);
        }
        
        // Website (Arabic)
        if (!empty($employee['website_ar'])) {
            $websiteAr = $employee['website_ar'];
            if (!preg_match('/^https?:\/\//i', $websiteAr)) {
                $websiteAr = 'https://' . $websiteAr;
            }
            $lines[] = 'X-URL;LANGUAGE=ar:' . self::escape($websiteAr);
        }
        
        // Address (English)
        $address = self::buildAddress($employee, $company, 'en');
        if (!empty($address)) {
            // ADR: PO Box;Extended;Street;City;Region;Postal;Country
            $lines[] = 'ADR;TYPE=WORK:;;' . $address;
        }
        
        // Address (Arabic)
        $addressAr = self::buildAddress($employee, $company, 'ar');
        if (!empty($addressAr)) {
            $lines[] = 'ADR;TYPE=WORK;LANGUAGE=ar:;;' . $addressAr;
        }
        
        // Photo (if available as URL)
        $photo = $employee['photo'] ?? $employee['photo_url'] ?? '';
        if (!empty($photo) && filter_var($photo, FILTER_VALIDATE_URL)) {
            $lines[] = 'PHOTO;VALUE=URI:' . $photo;
        }
        
        // Note
        $note = $employee['note'] ?? $employee['bio'] ?? '';
        if (!empty($note)) {
            $lines[] = 'NOTE:' . self::escape($note);
        }
        
        // Social media as X- properties
        if (!empty($employee['linkedin'])) {
            $lines[] = 'X-SOCIALPROFILE;TYPE=linkedin:' . self::escape($employee['linkedin']);
        }
        if (!empty($employee['twitter'])) {
            $lines[] = 'X-SOCIALPROFILE;TYPE=twitter:' . self::escape($employee['twitter']);
        }
        
        // Revision timestamp
        $lines[] = 'REV:' . date('Y-m-d\TH:i:s\Z');
        
        // Unique ID
        $uid = $employee['id'] ?? $employee['email'] ?? uniqid();
        $lines[] = 'UID:' . self::escape($uid);
        
        // vCard footer
        $lines[] = 'END:VCARD';
        
        return implode("\r\n", $lines);
    }
    
    /**
     * Get full name from employee data
     */
    private static function getFullName($employee) {
        // Try English name first
        if (!empty($employee['name_en'])) {
            return $employee['name_en'];
        }
        // Try generic name field
        if (!empty($employee['name'])) {
            return $employee['name'];
        }
        // Construct from first/last
        $first = $employee['first_name'] ?? $employee['first_name_en'] ?? '';
        $last = $employee['last_name'] ?? $employee['last_name_en'] ?? '';
        if (!empty($first) || !empty($last)) {
            return trim($first . ' ' . $last);
        }
        // Fallback to email
        return $employee['email'] ?? 'Contact';
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
        
        // Check if explicit fields exist
        if (!empty($employee['first_name']) || !empty($employee['last_name'])) {
            $parts['first'] = $employee['first_name'] ?? '';
            $parts['last'] = $employee['last_name'] ?? '';
            $parts['middle'] = $employee['middle_name'] ?? '';
            return $parts;
        }
        
        // Parse from full name
        $fullName = self::getFullName($employee);
        $namePieces = preg_split('/\s+/', trim($fullName));
        
        if (count($namePieces) === 1) {
            $parts['first'] = $namePieces[0];
        } elseif (count($namePieces) === 2) {
            $parts['first'] = $namePieces[0];
            $parts['last'] = $namePieces[1];
        } else {
            $parts['first'] = $namePieces[0];
            $parts['last'] = array_pop($namePieces);
            array_shift($namePieces);
            $parts['middle'] = implode(' ', $namePieces);
        }
        
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
            $street = $employee['address_ar'] ?? $company['address_ar'] ?? '';
        } else {
            $street = $employee['address_en'] ?? $employee['address'] ?? $company['address_en'] ?? $company['address'] ?? '';
        }
        $parts[] = self::escape($street);
        
        // City
        if ($lang === 'ar') {
            $city = $employee['city_ar'] ?? $company['city_ar'] ?? '';
        } else {
            $city = $employee['city'] ?? $company['city'] ?? '';
        }
        $parts[] = self::escape($city);
        
        // State/Region
        if ($lang === 'ar') {
            $state = $employee['state_ar'] ?? $company['state_ar'] ?? '';
        } else {
            $state = $employee['state'] ?? $company['state'] ?? '';
        }
        $parts[] = self::escape($state);
        
        // Postal code (same for both languages)
        $postal = $employee['postal_code'] ?? $company['postal_code'] ?? '';
        $parts[] = self::escape($postal);
        
        // Country
        if ($lang === 'ar') {
            $country = $employee['country_ar'] ?? $company['country_ar'] ?? '';
        } else {
            $country = $employee['country'] ?? $company['country'] ?? '';
        }
        $parts[] = self::escape($country);
        
        // Return only if we have at least one non-empty part
        $filtered = array_filter($parts);
        if (empty($filtered)) {
            return '';
        }
        
        return implode(';', $parts);
    }
    
    /**
     * Escape special characters for vCard format
     */
    private static function escape($value) {
        if (empty($value)) {
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
