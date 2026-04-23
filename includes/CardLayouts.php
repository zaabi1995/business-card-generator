<?php
/**
 * Pre-designed HTML/CSS card layouts for Cardify
 * These work without Fabric.js templates - pure CSS rendering via html2canvas
 */

class CardLayouts {

    /**
     * Get all available pre-designed layouts
     */
    public static function getAll() {
        return [
            'classic' => [
                'id' => 'classic',
                'name' => 'Classic Professional',
                'description' => 'Clean, timeless design with subtle accent line',
            ],
            'modern' => [
                'id' => 'modern',
                'name' => 'Modern Minimal',
                'description' => 'Bold typography with geometric accent',
            ],
            'corporate' => [
                'id' => 'corporate',
                'name' => 'Corporate Bold',
                'description' => 'Full-color header with strong brand presence',
            ],
            'elegant' => [
                'id' => 'elegant',
                'name' => 'Elegant Serif',
                'description' => 'Refined serif typography with gold accent',
            ],
            'tech' => [
                'id' => 'tech',
                'name' => 'Tech Gradient',
                'description' => 'Modern gradient with tech-forward styling',
            ],
        ];
    }

    /**
     * Get a specific layout by ID
     */
    public static function get($layoutId) {
        $all = self::getAll();
        return $all[$layoutId] ?? $all['classic'];
    }

    /**
     * Render front card HTML for a given layout + employee data
     * Returns self-contained HTML div (1050x600) suitable for html2canvas
     *
     * $options:
     *   'arabic' => true  swap primary text fields to AR columns + RTL,
     *                     so an "EN front / AR back" bilingual pair can
     *                     be auto-mirrored without a separate template.
     */
    public static function renderFront($layoutId, $employee, $company, $theme = null, array $options = []) {
        $d = self::extractData($employee, $company, $theme, !empty($options['arabic']));
        $method = 'front' . ucfirst($layoutId);
        if (method_exists(self::class, $method)) {
            return self::$method($d);
        }
        return self::frontClassic($d);
    }

    /**
     * Render back card HTML
     *
     * Pass `$options = ['arabic' => true]` to mirror an EN front into an
     * AR back (uses name_ar/position_ar/address_ar columns + RTL dir +
     * IBM Plex Arabic fallback).
     */
    public static function renderBack($layoutId, $employee, $company, $theme = null, $qrDataUrl = '', array $options = []) {
        $d = self::extractData($employee, $company, $theme, !empty($options['arabic']));
        $d['qrHtml'] = $qrDataUrl ? '<img src="' . $qrDataUrl . '" style="width:120px;height:120px;">' : '';
        $method = 'back' . ucfirst($layoutId);
        if (method_exists(self::class, $method)) {
            return self::$method($d);
        }
        return self::backClassic($d);
    }

    private static function extractData($employee, $company, $theme, bool $mirrorArabic = false) {
        $accent = ($theme && !empty($theme['primary_color'])) ? $theme['primary_color'] : '#1a56db';
        $secondary = ($theme && !empty($theme['secondary_color'])) ? $theme['secondary_color'] : '#0f3460';
        $logo = ($theme && !empty($theme['logo_path'])) ? $theme['logo_path'] : '';

        $e = function($k) use ($employee) {
            return htmlspecialchars($employee[$k] ?? '', ENT_QUOTES);
        };

        $phone = $e('phone');
        $mobile = $e('mobile');
        $email = $e('email');
        $website = $e('website');
        $address = $e('address_en');

        // When mirroring to Arabic, swap primary fields to *_ar columns
        // (fall back to EN when the AR column is empty).
        if ($mirrorArabic) {
            $name      = $e('name_ar')       ?: $e('name_en');
            $position  = $e('position_ar')   ?: $e('position_en');
            $companyN  = $e('company_ar')    ?: ($e('company_en') ?: htmlspecialchars($company['name_ar'] ?? ($company['name'] ?? ''), ENT_QUOTES));
            $addressN  = $e('address_ar')    ?: $address;
            $phoneN    = $e('phone_ar')      ?: $phone;
            $mobileN   = $e('mobile_ar')     ?: $mobile;
            $secondaryLang = $e('name_en');
            $secondaryLangDir = 'ltr';
        } else {
            $name      = $e('name_en');
            $position  = $e('position_en');
            $companyN  = $e('company_en') ?: htmlspecialchars($company['name'] ?? '', ENT_QUOTES);
            $addressN  = $address;
            $phoneN    = $phone;
            $mobileN   = $mobile;
            $secondaryLang = $e('name_ar');
            $secondaryLangDir = 'rtl';
        }

        $lines = [];
        if ($phoneN)  $lines[] = $phoneN;
        if ($mobileN && $mobileN !== $phoneN) $lines[] = $mobileN;
        if ($email)   $lines[] = $email;
        if ($website) $lines[] = $website;
        if ($addressN) $lines[] = $addressN;
        $contact = implode('<br>', $lines);

        return [
            'name' => $name,
            'nameAr' => $mirrorArabic ? $e('name_en') : $e('name_ar'),
            'position' => $position,
            'positionAr' => $mirrorArabic ? $e('position_en') : $e('position_ar'),
            'company' => $companyN,
            'companyAr' => $mirrorArabic ? $e('company_en') : $e('company_ar'),
            'phone' => $phoneN,
            'mobile' => $mobileN,
            'email' => $email,
            'website' => $website,
            'address' => $addressN,
            'addressAr' => $mirrorArabic ? $address : $e('address_ar'),
            'contact' => $contact,
            'accent' => $accent,
            'secondary' => $secondary,
            'logoHtml' => $logo ? '<img src="' . $logo . '" style="height:36px;max-width:160px;object-fit:contain;" crossorigin="anonymous">' : '',
            'logoHtmlLg' => $logo ? '<img src="' . $logo . '" style="height:56px;max-width:220px;object-fit:contain;" crossorigin="anonymous">' : '',
            'qrHtml' => '',
            'arabic' => $mirrorArabic,
            'dir' => $mirrorArabic ? 'rtl' : 'ltr',
            'font' => $mirrorArabic ? '\'IBM Plex Sans Arabic\',\'Noto Kufi Arabic\',sans-serif' : '\'Inter\',sans-serif',
        ];
    }

    // ── Classic Professional ──────────────────────────────────────

    private static function frontClassic($d) {
        $nameArHtml = $d['nameAr'] ? '<div style="font-size:22px;color:#374151;margin-top:4px;direction:rtl;font-family:\'Noto Kufi Arabic\',sans-serif;">' . $d['nameAr'] . '</div>' : '';
        $posArHtml = $d['positionAr'] ? '<div style="font-size:14px;color:#6b7280;margin-top:2px;direction:rtl;font-family:\'Noto Kufi Arabic\',sans-serif;">' . $d['positionAr'] . '</div>' : '';

        return '<div style="width:1050px;height:600px;background:#fff;font-family:\'Inter\',sans-serif;padding:48px 56px;box-sizing:border-box;display:flex;flex-direction:column;justify-content:space-between;position:relative;overflow:hidden;">'
            . '<div style="position:absolute;top:0;left:0;width:6px;height:100%;background:' . $d['accent'] . ';"></div>'
            . '<div>' . $d['logoHtml']
            . '<div style="margin-top:24px;">'
            . '<div style="font-size:32px;font-weight:700;color:#111827;letter-spacing:-0.5px;">' . $d['name'] . '</div>'
            . $nameArHtml
            . '<div style="font-size:16px;color:' . $d['accent'] . ';font-weight:600;margin-top:8px;text-transform:uppercase;letter-spacing:1px;">' . $d['position'] . '</div>'
            . $posArHtml
            . '</div></div>'
            . '<div>'
            . '<div style="font-size:14px;color:#6b7280;line-height:1.8;">' . $d['contact'] . '</div>'
            . '<div style="margin-top:12px;font-size:13px;font-weight:600;color:' . $d['secondary'] . ';text-transform:uppercase;letter-spacing:1.5px;">' . $d['company'] . '</div>'
            . '</div></div>';
    }

    private static function backClassic($d) {
        $compArHtml = $d['companyAr'] ? '<div style="font-size:18px;color:rgba(255,255,255,0.7);margin-top:4px;direction:rtl;font-family:\'Noto Kufi Arabic\',sans-serif;">' . $d['companyAr'] . '</div>' : '';

        return '<div style="width:1050px;height:600px;background:' . $d['secondary'] . ';font-family:\'Inter\',sans-serif;padding:48px 56px;box-sizing:border-box;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;position:relative;overflow:hidden;">'
            . '<div style="position:absolute;bottom:0;left:0;right:0;height:6px;background:' . $d['accent'] . ';"></div>'
            . $d['logoHtmlLg']
            . '<div style="margin-top:24px;font-size:28px;font-weight:700;color:#fff;letter-spacing:0.5px;">' . $d['company'] . '</div>'
            . $compArHtml
            . '<div style="margin-top:20px;">' . $d['qrHtml'] . '</div>'
            . '<div style="margin-top:16px;font-size:13px;color:rgba(255,255,255,0.6);">' . $d['website'] . '</div>'
            . '</div>';
    }

    // ── Modern Minimal ────────────────────────────────────────────

    private static function frontModern($d) {
        return '<div style="width:1050px;height:600px;background:#fff;font-family:\'DM Sans\',sans-serif;box-sizing:border-box;display:flex;overflow:hidden;">'
            . '<div style="width:40%;background:' . $d['accent'] . ';padding:48px 40px;display:flex;flex-direction:column;justify-content:flex-end;">'
            . '<div style="font-size:34px;font-weight:700;color:#fff;line-height:1.15;">' . $d['name'] . '</div>'
            . '<div style="font-size:15px;color:rgba(255,255,255,0.85);margin-top:8px;font-weight:500;">' . $d['position'] . '</div>'
            . '<div style="font-size:12px;color:rgba(255,255,255,0.6);margin-top:16px;text-transform:uppercase;letter-spacing:2px;">' . $d['company'] . '</div>'
            . '</div>'
            . '<div style="flex:1;padding:48px 40px;display:flex;flex-direction:column;justify-content:space-between;">'
            . '<div style="text-align:right;">' . $d['logoHtml'] . '</div>'
            . '<div style="font-size:14px;color:#4b5563;line-height:2;">' . $d['contact'] . '</div>'
            . '</div></div>';
    }

    private static function backModern($d) {
        return '<div style="width:1050px;height:600px;background:#fff;font-family:\'DM Sans\',sans-serif;box-sizing:border-box;display:flex;overflow:hidden;">'
            . '<div style="width:40%;background:' . $d['accent'] . ';display:flex;align-items:center;justify-content:center;">'
            . $d['qrHtml']
            . '</div>'
            . '<div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:40px;">'
            . $d['logoHtmlLg']
            . '<div style="margin-top:20px;font-size:24px;font-weight:700;color:#111827;">' . $d['company'] . '</div>'
            . ($d['companyAr'] ? '<div style="font-size:16px;color:#6b7280;margin-top:4px;direction:rtl;font-family:\'Noto Kufi Arabic\',sans-serif;">' . $d['companyAr'] . '</div>' : '')
            . '<div style="margin-top:16px;font-size:13px;color:#9ca3af;">' . $d['website'] . '</div>'
            . '</div></div>';
    }

    // ── Corporate Bold ────────────────────────────────────────────

    private static function frontCorporate($d) {
        return '<div style="width:1050px;height:600px;background:#fff;font-family:\'Plus Jakarta Sans\',sans-serif;box-sizing:border-box;overflow:hidden;display:flex;flex-direction:column;">'
            . '<div style="height:180px;background:linear-gradient(135deg,' . $d['secondary'] . ',' . $d['accent'] . ');padding:36px 48px;display:flex;align-items:flex-start;justify-content:space-between;">'
            . $d['logoHtml']
            . '<div style="text-align:right;"><div style="font-size:13px;color:rgba(255,255,255,0.7);text-transform:uppercase;letter-spacing:2px;">' . $d['company'] . '</div></div>'
            . '</div>'
            . '<div style="flex:1;padding:32px 48px;display:flex;flex-direction:column;justify-content:space-between;">'
            . '<div><div style="font-size:30px;font-weight:800;color:#111827;">' . $d['name'] . '</div>'
            . '<div style="font-size:15px;color:' . $d['accent'] . ';font-weight:600;margin-top:6px;">' . $d['position'] . '</div></div>'
            . '<div style="font-size:13px;color:#6b7280;line-height:1.9;border-top:1px solid #e5e7eb;padding-top:16px;">' . $d['contact'] . '</div>'
            . '</div></div>';
    }

    private static function backCorporate($d) {
        return '<div style="width:1050px;height:600px;background:linear-gradient(135deg,' . $d['secondary'] . ',' . $d['accent'] . ');font-family:\'Plus Jakarta Sans\',sans-serif;box-sizing:border-box;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;overflow:hidden;">'
            . $d['logoHtmlLg']
            . '<div style="margin-top:24px;font-size:32px;font-weight:800;color:#fff;">' . $d['company'] . '</div>'
            . ($d['companyAr'] ? '<div style="font-size:18px;color:rgba(255,255,255,0.7);margin-top:6px;direction:rtl;font-family:\'Noto Kufi Arabic\',sans-serif;">' . $d['companyAr'] . '</div>' : '')
            . '<div style="margin-top:28px;">' . $d['qrHtml'] . '</div>'
            . '<div style="margin-top:16px;font-size:13px;color:rgba(255,255,255,0.5);">' . $d['website'] . '</div>'
            . '</div>';
    }

    // ── Elegant Serif ─────────────────────────────────────────────

    private static function frontElegant($d) {
        $gold = '#b8860b';
        return '<div style="width:1050px;height:600px;background:#faf9f7;font-family:\'Playfair Display\',serif;padding:48px 56px;box-sizing:border-box;display:flex;flex-direction:column;justify-content:space-between;overflow:hidden;border:1px solid #e8e4df;">'
            . '<div style="display:flex;justify-content:space-between;align-items:flex-start;">'
            . $d['logoHtml']
            . '<div style="width:48px;height:2px;background:' . $gold . ';margin-top:16px;"></div>'
            . '</div>'
            . '<div>'
            . '<div style="font-size:34px;font-weight:700;color:#1a1a1a;letter-spacing:-0.3px;">' . $d['name'] . '</div>'
            . '<div style="font-size:15px;color:' . $gold . ';margin-top:10px;font-family:\'Inter\',sans-serif;font-weight:500;text-transform:uppercase;letter-spacing:2px;">' . $d['position'] . '</div>'
            . '</div>'
            . '<div>'
            . '<div style="width:40px;height:1px;background:' . $gold . ';margin-bottom:16px;"></div>'
            . '<div style="font-size:13px;color:#6b7280;line-height:1.9;font-family:\'Inter\',sans-serif;">' . $d['contact'] . '</div>'
            . '<div style="margin-top:14px;font-size:12px;font-weight:600;color:#1a1a1a;text-transform:uppercase;letter-spacing:2px;font-family:\'Inter\',sans-serif;">' . $d['company'] . '</div>'
            . '</div></div>';
    }

    private static function backElegant($d) {
        $gold = '#b8860b';
        return '<div style="width:1050px;height:600px;background:#1a1a1a;font-family:\'Playfair Display\',serif;padding:48px 56px;box-sizing:border-box;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;overflow:hidden;position:relative;">'
            . '<div style="position:absolute;top:24px;left:50%;transform:translateX(-50%);width:60px;height:1px;background:' . $gold . ';"></div>'
            . $d['logoHtmlLg']
            . '<div style="margin-top:24px;font-size:28px;font-weight:700;color:#faf9f7;">' . $d['company'] . '</div>'
            . ($d['companyAr'] ? '<div style="font-size:16px;color:rgba(250,249,247,0.5);margin-top:6px;direction:rtl;font-family:\'Noto Kufi Arabic\',sans-serif;">' . $d['companyAr'] . '</div>' : '')
            . '<div style="margin-top:28px;">' . $d['qrHtml'] . '</div>'
            . '<div style="margin-top:16px;font-size:12px;color:rgba(250,249,247,0.4);font-family:\'Inter\',sans-serif;">' . $d['website'] . '</div>'
            . '<div style="position:absolute;bottom:24px;left:50%;transform:translateX(-50%);width:60px;height:1px;background:' . $gold . ';"></div>'
            . '</div>';
    }

    // ── Tech Gradient ─────────────────────────────────────────────

    private static function frontTech($d) {
        return '<div style="width:1050px;height:600px;background:linear-gradient(160deg,#0f172a 0%,#1e293b 50%,' . $d['accent'] . '22 100%);font-family:\'Sora\',sans-serif;padding:48px 56px;box-sizing:border-box;display:flex;flex-direction:column;justify-content:space-between;overflow:hidden;position:relative;">'
            . '<div style="position:absolute;top:-60px;right:-60px;width:200px;height:200px;border-radius:50%;background:' . $d['accent'] . ';opacity:0.08;"></div>'
            . '<div style="display:flex;justify-content:space-between;align-items:flex-start;position:relative;">'
            . $d['logoHtml']
            . '<div style="font-size:11px;color:rgba(255,255,255,0.4);text-transform:uppercase;letter-spacing:2px;">' . $d['company'] . '</div>'
            . '</div>'
            . '<div style="position:relative;">'
            . '<div style="font-size:34px;font-weight:700;color:#fff;letter-spacing:-0.5px;">' . $d['name'] . '</div>'
            . '<div style="font-size:15px;color:' . $d['accent'] . ';font-weight:600;margin-top:8px;">' . $d['position'] . '</div>'
            . '<div style="width:40px;height:3px;background:' . $d['accent'] . ';margin-top:16px;border-radius:2px;"></div>'
            . '<div style="margin-top:16px;font-size:13px;color:rgba(255,255,255,0.55);line-height:1.9;">' . $d['contact'] . '</div>'
            . '</div></div>';
    }

    private static function backTech($d) {
        return '<div style="width:1050px;height:600px;background:linear-gradient(160deg,#0f172a 0%,#1e293b 60%,' . $d['accent'] . '33 100%);font-family:\'Sora\',sans-serif;box-sizing:border-box;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;overflow:hidden;position:relative;">'
            . '<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:400px;height:400px;border-radius:50%;border:1px solid rgba(255,255,255,0.03);"></div>'
            . $d['logoHtmlLg']
            . '<div style="margin-top:20px;font-size:26px;font-weight:700;color:#fff;position:relative;">' . $d['company'] . '</div>'
            . ($d['companyAr'] ? '<div style="font-size:15px;color:rgba(255,255,255,0.4);margin-top:6px;direction:rtl;font-family:\'Noto Kufi Arabic\',sans-serif;">' . $d['companyAr'] . '</div>' : '')
            . '<div style="margin-top:24px;position:relative;">' . $d['qrHtml'] . '</div>'
            . '<div style="margin-top:14px;font-size:12px;color:rgba(255,255,255,0.35);">' . $d['website'] . '</div>'
            . '</div>';
    }
}
