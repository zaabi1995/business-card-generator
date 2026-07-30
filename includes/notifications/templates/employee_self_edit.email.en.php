<?php
/** @var string $adminName */
/** @var string $employeeName */
/** @var string $companyName */
/** @var array  $changedFields */
/** @var string $employeeUrl */
$subject = "{$employeeName} updated their card";
$fieldList = '';
foreach ($changedFields as $f) {
    $label = ucwords(str_replace('_', ' ', $f));
    $fieldList .= "<li>{$label}</li>";
}
$body = <<<HTML
<div style="font-family:system-ui,sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#1f2937;">
  <h2 style="color:#00718c;margin-bottom:8px;">A team member updated their details</h2>
  <p>Hi {$adminName},</p>
  <p><strong>{$employeeName}</strong> on {$companyName} just edited their card from the self-service portal.</p>
  <p><strong>Fields changed:</strong></p>
  <ul style="color:#4b5563;font-size:14px;">{$fieldList}</ul>
  <p style="margin:20px 0;">
    <a href="{$employeeUrl}" style="background:#009bc1;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600;">Review in admin</a>
  </p>
  <p style="color:#6b7280;font-size:12px;">Turn this email off under Company Settings, Notifications.</p>
</div>
HTML;
