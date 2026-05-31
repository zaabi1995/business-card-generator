<?php
/**
 * Seed falaj.cardify.om showcase tenant using RAW PDO (bypasses config.php).
 * Clones the live `otech` tenant rows so all columns are valid. Idempotent.
 *   /www/server/php/83/bin/php scripts/seed-falaj-pdo.php
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

$out = ['steps' => []];
try {
    $pdo = new PDO('mysql:host=127.0.0.1;dbname=bc;charset=utf8mb4', 'bc', 'pWewN3fwFmEHh32J', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $uuid = function () {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
    };
    $insert = function ($table, $row) use ($pdo) {
        $cols = array_keys($row);
        $ph = array_map(fn($c) => ':' . $c, $cols);
        $sql = "INSERT INTO `$table` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $ph) . ")";
        $st = $pdo->prepare($sql);
        foreach ($row as $k => $v) $st->bindValue(':' . $k, $v);
        $st->execute();
    };
    $set = function (&$a, $k, $v) { if (array_key_exists($k, $a)) $a[$k] = $v; };

    $SRC = $pdo->query("SELECT * FROM companies WHERE slug = 'otech'")->fetch();
    if (!$SRC) throw new Exception('otech company not found');
    $srcId = $SRC['id'];
    $stt = $pdo->prepare("SELECT * FROM company_themes WHERE company_id = ?"); $stt->execute([$srcId]); $srcTheme = $stt->fetch();
    $ste = $pdo->prepare("SELECT * FROM employees WHERE company_id = ? AND status='active' LIMIT 1"); $ste->execute([$srcId]); $srcEmp = $ste->fetch();
    if (!$srcEmp) { $ste = $pdo->prepare("SELECT * FROM employees WHERE company_id = ? LIMIT 1"); $ste->execute([$srcId]); $srcEmp = $ste->fetch(); }
    if (!$srcEmp) throw new Exception('no otech employee');

    $FID = 'falaj-showcase-cardify-demo-0001';

    $p = $pdo->prepare("SELECT id FROM companies WHERE slug='falaj'"); $p->execute(); $prior = $p->fetch();
    if ($prior) {
        $pdo->prepare("DELETE FROM employees WHERE company_id=?")->execute([$prior['id']]);
        $pdo->prepare("DELETE FROM company_themes WHERE company_id=?")->execute([$prior['id']]);
        $pdo->prepare("DELETE FROM companies WHERE id=?")->execute([$prior['id']]);
        $out['steps'][] = 'deleted prior ' . $prior['id'];
    }

    // company
    $co = $SRC;
    $co['id'] = $FID; $co['slug'] = 'falaj'; $co['name'] = 'Falaj Trading LLC';
    $set($co, 'name_ar', 'شركة فلج للتجارة');
    $set($co, 'parent_company_id', null); $set($co, 'company_path', null);
    $set($co, 'admin_email', 'admin@falaj.om'); $set($co, 'notification_email', 'admin@falaj.om');
    $set($co, 'email_domain', 'falaj.om'); $set($co, 'domain', null); $set($co, 'billing_email', 'admin@falaj.om');
    $set($co, 'default_website', 'https://falaj.om');
    $set($co, 'slogan_en', 'Building materials and contracting'); $set($co, 'slogan_ar', 'مواد البناء والمقاولات');
    $set($co, 'default_address_en', 'Al Khuwair, Muscat, Oman'); $set($co, 'default_address_ar', 'الخوير، مسقط، عُمان');
    // keep password_hash (NOT NULL) cloned from otech; null only the safe optional cols
    foreach (['sample_card_front','sample_card_back','subscription_id','erp_client_name'] as $k) $set($co, $k, null);
    $set($co, 'status', 'active'); $set($co, 'portal_enabled', 1); $set($co, 'onboarding_completed', 1);
    $insert('companies', $co);
    $out['steps'][] = 'company inserted';

    // theme
    if ($srcTheme) {
        $th = $srcTheme; $th['id'] = $uuid(); $th['company_id'] = $FID;
        $th['primary_color'] = '#2563eb'; $th['secondary_color'] = '#1d4ed8';
        $set($th, 'logo_path', null); $set($th, 'favicon_path', null); $set($th, 'custom_css', null);
        $insert('company_themes', $th);
        $out['steps'][] = 'theme inserted';
    }

    // employees
    $people = [
        ['l'=>'yousef.alharthy','en'=>'Yousef Al Harthy','ar'=>'يوسف الحارثي','pe'=>'Sales Director','pa'=>'مدير المبيعات','m'=>'+96891234567','sc'=>186],
        ['l'=>'maryam.albalushi','en'=>'Maryam Al Balushi','ar'=>'مريم البلوشي','pe'=>'Account Manager','pa'=>'مديرة حسابات','m'=>'+96891234568','sc'=>154],
        ['l'=>'khalid.alhinai','en'=>'Khalid Al Hinai','ar'=>'خالد الهنائي','pe'=>'Project Engineer','pa'=>'مهندس مشاريع','m'=>'+96891234569','sc'=>121],
    ];
    foreach ($people as $pp) {
        $e = $srcEmp; $e['id'] = $uuid(); $e['company_id'] = $FID;
        $set($e, 'department_id', null);
        $e['email'] = $pp['l'] . '@falaj.om';
        $set($e, 'email_ar', $pp['l'] . '@falaj.om');
        $set($e, 'name_en', $pp['en']); $set($e, 'name_ar', $pp['ar']);
        $set($e, 'position_en', $pp['pe']); $set($e, 'position_ar', $pp['pa']);
        $set($e, 'mobile', $pp['m']); $set($e, 'mobile_ar', $pp['m']); $set($e, 'phone', $pp['m']); $set($e, 'phone_ar', $pp['m']);
        $set($e, 'company_en', 'Falaj Trading LLC'); $set($e, 'company_ar', 'شركة فلج للتجارة');
        $set($e, 'website', 'https://falaj.om'); $set($e, 'website_ar', 'https://falaj.om');
        $set($e, 'address_en', 'Al Khuwair, Muscat, Oman'); $set($e, 'address_ar', 'الخوير، مسقط، عُمان');
        $set($e, 'address_2_en', null); $set($e, 'address_2_ar', null);
        $set($e, 'photo', null); $set($e, 'total_scans', $pp['sc']); $set($e, 'last_scanned_at', null);
        $set($e, 'linkedin', null); $set($e, 'twitter', null);
        $set($e, 'employee_id', strtoupper(substr($pp['l'], 0, 3)));
        $set($e, 'card_template_id', null); $set($e, 'qr_redirect_url', null);
        $set($e, 'fax', null); $set($e, 'fax_ar', null); $set($e, 'po_box', null); $set($e, 'po_box_ar', null);
        $set($e, 'status', 'active'); $set($e, 'deleted_at', null); $set($e, 'deleted_by', null);
        $insert('employees', $e);
        $out['steps'][] = 'employee ' . $pp['l'];
    }

    $out['ok'] = true; $out['company_id'] = $FID;
} catch (Throwable $ex) {
    $out['ok'] = false; $out['error'] = $ex->getMessage();
}
echo "RESULT:" . json_encode($out, JSON_UNESCAPED_UNICODE) . "\n";
