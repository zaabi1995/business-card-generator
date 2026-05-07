<?php
/**
 * scripts/seed-bhd-brands.php
 *
 * Seeds the 10 BHD Group brands (plus the parent BHD Group holding entry)
 * into om_companies with verified logos. Idempotent — safe to re-run.
 *
 * Source files live in scripts/bhd-logos/. They are copied into
 * /storage/logos/indexed/{id}.svg (or .png for ReachScreens) and the
 * companion render-logo-variants.php script fills in PNG 512/1024/2048
 * + WebP + dominant color afterwards.
 *
 * Run:   php scripts/seed-bhd-brands.php
 * Then:  php scripts/render-logo-variants.php --only-missing
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/LogoLibrary.php';

$db   = Database::getInstance();
$pdo  = $db->getConnection();
$root = dirname(__DIR__);
$src  = __DIR__ . '/bhd-logos';

$brands = [
    [
        'slug'     => 'bhd-group',
        'name_en'  => 'BHD Group',
        'name_ar'  => 'مجموعة بي إتش دي',
        'sector'   => 'conglomerate',
        'size'     => 'large',
        'website'  => 'https://bhd.om',
        'file'     => 'bhd-group.svg',
        'summary_en' => 'BHD Group — Oman\'s first AI-powered business conglomerate. 10 companies across printing, technology, digital platforms, and investments. Building Oman\'s Future.',
        'summary_ar' => 'مجموعة بي إتش دي — أول مجموعة أعمال معززة بالذكاء الاصطناعي في سلطنة عمان. عشر شركات في الطباعة والتكنولوجيا والمنصات الرقمية والاستثمار.',
    ],
    [
        'slug'     => 'bhd-printing-and-designing',
        'name_en'  => 'BHD Printing & Designing',
        'name_ar'  => 'شركة بن حيدر درويش للطباعة والتصميم',
        'sector'   => 'media-advertising',
        'size'     => 'medium',
        'website'  => 'https://bhdoman.com',
        'file'     => 'bhd-printing.svg',
        'summary_en' => 'BHD Printing & Designing — flagship short-run digital printing business in Muscat. Business cards, flyers, banners, packaging, branded merchandise.',
        'summary_ar' => 'بي إتش دي للطباعة والتصميم — الشركة الرئيسية للطباعة الرقمية قصيرة المدى في مسقط.',
    ],
    [
        'slug'     => 'bhd-digital-and-technology',
        'name_en'  => 'BHD Digital & Technology',
        'name_ar'  => 'بي إتش دي للرقمية والتكنولوجيا',
        'sector'   => 'technology',
        'size'     => 'medium',
        'website'  => 'https://bhd.om',
        'file'     => 'bhd-digital.svg',
        'summary_en' => 'BHD Digital & Technology — enterprise technology solutions and digital transformation for Omani businesses.',
        'summary_ar' => 'بي إتش دي للرقمية والتكنولوجيا — حلول تقنية مؤسسية والتحول الرقمي للشركات العمانية.',
    ],
    [
        'slug'     => 'tech-foundry',
        'name_en'  => 'Tech Foundry',
        'name_ar'  => 'تك فاوندري',
        'sector'   => 'technology',
        'size'     => 'medium',
        'website'  => 'https://bhd.om',
        'file'     => 'tech-foundry.svg',
        'summary_en' => 'Tech Foundry — AI-powered platforms and innovation lab by BHD Group.',
        'summary_ar' => 'تك فاوندري — منصات مدعومة بالذكاء الاصطناعي ومختبر ابتكار تابع لمجموعة بي إتش دي.',
    ],
    [
        'slug'     => 'dardasha',
        'name_en'  => 'Dardasha',
        'name_ar'  => 'درداشا',
        'sector'   => 'technology',
        'size'     => 'medium',
        'website'  => 'https://dardasha.om',
        'file'     => 'dardasha.svg',
        'summary_en' => 'Dardasha — WhatsApp Business API platform for Omani companies. Multi-line management, AI agents, and automation.',
        'summary_ar' => 'درداشا — منصة واتساب للأعمال للشركات العمانية. إدارة خطوط متعددة، وكلاء ذكاء اصطناعي، وأتمتة.',
    ],
    [
        'slug'     => 'cups-by-aa',
        'name_en'  => 'Cups by AA',
        'name_ar'  => 'كابس باي إيه إيه',
        'sector'   => 'manufacturing',
        'size'     => 'medium',
        'website'  => 'https://cupsbyaa.com',
        'file'     => 'cupsbyaa.svg',
        'summary_en' => 'Cups by AA — custom-printed paper cups and branded disposables for cafes, events, and businesses across Oman.',
        'summary_ar' => 'كابس باي إيه إيه — أكواب ورقية مطبوعة بتصميم مخصص للمقاهي والفعاليات والشركات في عمان.',
    ],
    [
        'slug'     => 'cardify',
        'name_en'  => 'Cardify',
        'name_ar'  => 'كاردفاي',
        'sector'   => 'technology',
        'size'     => 'medium',
        'website'  => 'https://cardify.om',
        'file'     => 'cardify.svg',
        'summary_en' => 'Cardify — digital and printed business cards platform for Omani businesses. One place for all business card needs.',
        'summary_ar' => 'كاردفاي — منصة بطاقات الأعمال الرقمية والمطبوعة للشركات العمانية.',
    ],
    [
        'slug'     => 'reachscreens',
        'name_en'  => 'ReachScreens',
        'name_ar'  => 'ريتش سكرينز',
        'sector'   => 'technology',
        'size'     => 'medium',
        'website'  => 'https://reachscreens.com',
        'file'     => 'reachscreens.png',
        'summary_en' => 'ReachScreens — digital signage platform for retail, offices, and public spaces.',
        'summary_ar' => 'ريتش سكرينز — منصة اللافتات الرقمية للتجزئة والمكاتب والأماكن العامة.',
    ],
    [
        'slug'     => 'alali-investments',
        'name_en'  => 'Alali Investments',
        'name_ar'  => 'استثمارات العالي',
        'sector'   => 'finance',
        'size'     => 'medium',
        'website'  => 'https://alali.om',
        'file'     => 'alali.svg',
        'summary_en' => 'Alali Investments — strategic investments in F&B, digital infrastructure, and emerging sectors in Oman.',
        'summary_ar' => 'استثمارات العالي — استثمارات استراتيجية في قطاعات الأغذية والبنية التحتية الرقمية في عمان.',
    ],
    [
        'slug'     => 'paper-and-pen',
        'name_en'  => 'Paper & Pen',
        'name_ar'  => 'ورق وقلم',
        'sector'   => 'technology',
        'size'     => 'medium',
        'website'  => 'https://paperandpen.om',
        'file'     => 'paper-pen.svg',
        'summary_en' => 'Paper & Pen — multi-tenant ERP SaaS for Omani small and medium businesses.',
        'summary_ar' => 'ورق وقلم — نظام تخطيط موارد المؤسسات متعدد المستأجرين للشركات الصغيرة والمتوسطة في عمان.',
    ],
    [
        'slug'     => 'arabian-ceo',
        'name_en'  => 'Arabian.CEO',
        'name_ar'  => 'عربيان.سي إي أو',
        'sector'   => 'media-advertising',
        'size'     => 'medium',
        'website'  => 'https://arabian.ceo',
        'file'     => 'arabian-ceo.svg',
        'summary_en' => 'Arabian.CEO — Google review acquisition and social media growth platform. Real accounts across 50+ countries.',
        'summary_ar' => 'عربيان.سي إي أو — منصة اكتساب مراجعات جوجل ونمو وسائل التواصل الاجتماعي.',
    ],
];

$indexedDir = $root . '/storage/logos/indexed';
if (!is_dir($indexedDir)) { @mkdir($indexedDir, 0755, true); }

echo "[seed-bhd] rows=" . count($brands) . "\n";
$inserted = 0; $updated = 0; $skipped = 0;

foreach ($brands as $b) {
    $slug     = $b['slug'];
    $srcAbs   = $src . '/' . $b['file'];
    $ext      = strtolower(pathinfo($b['file'], PATHINFO_EXTENSION));
    if (!is_readable($srcAbs)) {
        echo "  ! {$slug} source missing: {$srcAbs}\n";
        $skipped++;
        continue;
    }

    $domain = LogoLibrary::deriveDomain($b['website']);

    $existing = $pdo->prepare("SELECT id FROM om_companies WHERE slug = :slug");
    $existing->execute(['slug' => $slug]);
    $row = $existing->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $id = (int) $row['id'];
        $pdo->prepare("UPDATE om_companies SET
            name_en = :name_en,
            name_ar = :name_ar,
            sector = :sector,
            wilayat = 'muscat',
            size_bucket = :size,
            website = :website,
            website_domain_cache = :domain,
            summary_en = :summary_en,
            summary_ar = :summary_ar,
            curated = 1,
            verified_at = COALESCE(verified_at, NOW())
          WHERE id = :id")->execute([
            'name_en'    => $b['name_en'],
            'name_ar'    => $b['name_ar'],
            'sector'     => $b['sector'],
            'size'       => $b['size'],
            'website'    => $b['website'],
            'domain'     => $domain,
            'summary_en' => $b['summary_en'],
            'summary_ar' => $b['summary_ar'],
            'id'         => $id,
        ]);
        $updated++;
        echo "  ~ {$slug} (id={$id}) updated\n";
    } else {
        $pdo->prepare("INSERT INTO om_companies
            (name_en, name_ar, slug, sector, wilayat, size_bucket, website, website_domain_cache,
             summary_en, summary_ar, curated, verified_at)
          VALUES (:name_en, :name_ar, :slug, :sector, 'muscat', :size, :website, :domain,
                  :summary_en, :summary_ar, 1, NOW())")->execute([
            'name_en'    => $b['name_en'],
            'name_ar'    => $b['name_ar'],
            'slug'       => $slug,
            'sector'     => $b['sector'],
            'size'       => $b['size'],
            'website'    => $b['website'],
            'domain'     => $domain,
            'summary_en' => $b['summary_en'],
            'summary_ar' => $b['summary_ar'],
        ]);
        $id = (int) $pdo->lastInsertId();
        $inserted++;
        echo "  + {$slug} (id={$id}) inserted\n";
    }

    // Copy source file into /storage/logos/indexed/{id}.{ext}
    $relPath = "/storage/logos/indexed/{$id}.{$ext}";
    $absPath = $root . $relPath;
    if (!copy($srcAbs, $absPath)) {
        echo "    ! copy failed: {$srcAbs} -> {$absPath}\n";
        continue;
    }
    @chmod($absPath, 0644);

    // Determine svg/png path columns
    $svgCol = $ext === 'svg' ? $relPath : null;
    $pngCol = $ext === 'png' ? $relPath : null;

    // Extract dominant color where possible
    $domColor = null;
    try { $domColor = LogoLibrary::dominantColor($absPath); } catch (Throwable $t) {}

    // Try to read intrinsic width/height
    $w = null; $h = null;
    if ($ext === 'png' || $ext === 'jpg' || $ext === 'jpeg' || $ext === 'webp') {
        $info = @getimagesize($absPath);
        if ($info) { $w = (int) $info[0]; $h = (int) $info[1]; }
    } elseif ($ext === 'svg') {
        // parse viewBox or width/height from SVG
        $svg = @file_get_contents($absPath);
        if ($svg && preg_match('~viewBox\s*=\s*"[^"]*?(\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)"~', $svg, $m)) {
            $w = (int) round((float) $m[1]);
            $h = (int) round((float) $m[2]);
        }
    }

    $pdo->prepare("UPDATE om_companies SET
        logo_url = :url,
        logo_svg_path = COALESCE(:svg, logo_svg_path),
        logo_png_path = COALESCE(:png, logo_png_path),
        logo_source   = 'admin_upload',
        logo_source_url = :srcurl,
        logo_status   = 'verified',
        logo_dominant_color = :color,
        logo_width    = :w,
        logo_height   = :h,
        logo_verified_at = COALESCE(logo_verified_at, NOW()),
        logo_updated_at  = NOW()
      WHERE id = :id")->execute([
        'url'    => $relPath,
        'svg'    => $svgCol,
        'png'    => $pngCol,
        'srcurl' => $b['website'],
        'color'  => $domColor,
        'w'      => $w,
        'h'      => $h,
        'id'     => $id,
    ]);

    echo "    → {$ext} copied, color=" . ($domColor ?: '–') . " size=" . ($w ?: '–') . "×" . ($h ?: '–') . "\n";
}

echo "\n[seed-bhd] done. inserted={$inserted} updated={$updated} skipped={$skipped}\n";
echo "[seed-bhd] next: php scripts/render-logo-variants.php --only-missing\n";
