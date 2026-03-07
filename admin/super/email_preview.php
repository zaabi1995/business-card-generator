<?php
/**
 * Email Preview - Shows what emails will look like
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Mailer.php';

Auth::requireRole('super_admin');

// Generate sample email content
$sampleContent = <<<HTML
<h2>Welcome to Cardify!</h2>
<p>Dear John Smith,</p>
<p>Thank you for joining <strong>Acme Corporation</strong> on our platform. Your professional digital business card is ready!</p>

<div class="success-box">
    <strong>Your card has been generated successfully!</strong><br>
    You can now share it digitally or order prints.
</div>

<table class="data-table">
    <tr><td>Name</td><td>John Smith</td></tr>
    <tr><td>Position</td><td>Senior Marketing Manager</td></tr>
    <tr><td>Email</td><td>john.smith@acme.com</td></tr>
    <tr><td>Phone</td><td>+968 1234 5678</td></tr>
    <tr><td>Department</td><td>Marketing</td></tr>
</table>

<div class="info-box">
    <strong>What's Next?</strong><br>
    Share your digital card via QR code or direct link. You can also order professional prints through our platform.
</div>

<p style="text-align: center; margin: 30px 0;">
    <a href="#" class="btn">View Your Card</a>
</p>

<p>If you have any questions, feel free to reach out to your administrator or contact our support team.</p>

<p>Best regards,<br>
<strong>The Cardify Team</strong></p>
HTML;

// Use reflection to access the private wrapInTemplate method
$mailerClass = new ReflectionClass('Mailer');
$method = $mailerClass->getMethod('wrapInTemplate');
$method->setAccessible(true);

// Generate the wrapped email
$wrappedEmail = $method->invoke(null, $sampleContent);

// Output the email HTML
header('Content-Type: text/html; charset=UTF-8');
echo $wrappedEmail;
