<?php
require_once dirname(__DIR__, 2) . '/includes/BusinessGovOm.php';

$html = <<<'HTML'
<form id="searchCompanyForm" action="/portal/searchEstablishments?execution=e1s1" method="POST">
<img class="BDC_CaptchaImage" id="exampleCaptcha_CaptchaImage" src="/portal/botdetectcaptcha?get=image&amp;c=exampleCaptcha&amp;t=abc" alt="Retype the CAPTCHA code from the image" />
<input type="hidden" name="BDC_VCID_exampleCaptcha" value="abc" />
<input id="captchaCode" type="text" name="captchaCode" value="" />
</form>
HTML;

$form = BusinessGovOm::parseForm($html);
if ($form['captcha_image'] !== '/portal/botdetectcaptcha?get=image&c=exampleCaptcha&t=abc') {
    fwrite(STDERR, "captcha src not parsed: {$form['captcha_image']}\n");
    exit(1);
}
if (($form['fields']['BDC_VCID_exampleCaptcha'] ?? '') !== 'abc') {
    fwrite(STDERR, "hidden captcha field missing\n");
    exit(1);
}
if (($form['inputs'][0]['name'] ?? '') !== 'captchaCode') {
    fwrite(STDERR, "captcha input missing\n");
    exit(1);
}

$tableHtml = '<table><tr><th>CR</th><th>Name</th></tr><tr><td>1234567</td><td>Example LLC</td></tr></table>';
$tables = BusinessGovOm::parseTables($tableHtml);
if (($tables[0][1][0] ?? '') !== '1234567') {
    fwrite(STDERR, "result table not parsed\n");
    exit(1);
}
echo "business_gov_om_parse_test ok\n";
