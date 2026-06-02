<?php
/**
 * Mailer Class - Email functionality for Cardify
 * Supports SMTP with SSL/TLS encryption
 * Includes email logging for audit trail
 */
class Mailer {
    private static $lastError = '';
    private static $currentTemplate = null;
    private static $currentMetadata = [];
    
    /**
     * Get email setting from database or config
     */
    private static function getSetting($key, $default = '') {
        // Try database first
        try {
            if (class_exists('Database')) {
                $db = Database::getInstance();
                if ($db && $db->isConnected() && $db->tableExists('system_settings')) {
                    $result = $db->fetchOne(
                        "SELECT setting_value FROM system_settings WHERE setting_key = :key",
                        ['key' => $key]
                    );
                    if ($result && !empty($result['setting_value'])) {
                        return $result['setting_value'];
                    }
                }
            }
        } catch (Exception $e) {
            // Fall through to config
        }
        
        // Fall back to config constant
        $constantName = strtoupper($key);
        if (defined($constantName)) {
            return constant($constantName);
        }
        
        return $default;
    }
    
    /**
     * Send an email
     * @param string $to Recipient email
     * @param string $subject Email subject
     * @param string $body HTML body content
     * @param array $attachments Array of ['path' => '/path/to/file', 'name' => 'filename.pdf']
     * @param array $options Optional: ['company_id' => int, 'employee_id' => int, 'recipient_name' => string]
     * @return bool Success status
     */
    public static function send($to, $subject, $body, $attachments = [], $options = []) {
        self::$lastError = '';
        
        // Get settings from database first, then config.php
        $host = self::getSetting('mail_host', '');
        $port = self::getSetting('mail_port', 587);
        $username = self::getSetting('mail_username', '');
        $password = self::getSetting('mail_password', '');
        $fromEmail = self::getSetting('mail_from_email', $username);
        $fromName = self::getSetting('mail_from_name', 'Cardify');
        $encryption = self::getSetting('mail_encryption', '');
        
        $success = false;
        $errorMessage = null;
        
        // If no SMTP configured, try PHP mail()
        if (empty($host) || empty($username)) {
            $success = self::sendWithPhpMail($to, $subject, $body, $attachments, $fromEmail, $fromName);
            if (!$success) {
                $errorMessage = 'PHP mail() failed';
            }
        } else {
            $smtpConfig = [
                'host' => $host,
                'port' => $port,
                'username' => $username,
                'password' => $password,
                'from_email' => $fromEmail,
                'from_name' => $fromName,
                'encryption' => $encryption
            ];
            // Retry transient SMTP failures with backoff. The Mailer opens a
            // fresh connection + AUTH per message, so a burst (e.g. an admin
            // bulk-adding employees, each triggering an invite) can trip the
            // relay's "450 4.7.1 too many AUTH commands" / "421" / greylisting.
            // These are temporary; a short wait + retry clears them instead of
            // silently dropping the welcome/invite email. (BHD loop audit iter 7.)
            $attempts = 0;
            $maxAttempts = 3;
            while ($attempts < $maxAttempts) {
                $attempts++;
                try {
                    $success = self::sendWithSMTP($to, $subject, $body, $attachments, $smtpConfig);
                    $errorMessage = null;
                    break;
                } catch (Exception $e) {
                    self::$lastError = $e->getMessage();
                    $errorMessage = $e->getMessage();
                    $success = false;
                    $transient = (bool) preg_match('/\b4\d\d\b|too many AUTH|greylist|try again|temporarily/i', $e->getMessage());
                    if ($transient && $attempts < $maxAttempts) {
                        // 1.5s, then 3s backoff. usleep keeps it sub-process-friendly.
                        usleep(($attempts * 1500) * 1000);
                        continue;
                    }
                    error_log("Mailer error (attempt {$attempts}/{$maxAttempts}): " . $e->getMessage());
                    break;
                }
            }
        }
        
        // Log the email
        self::logEmail($to, $subject, $body, $success, $errorMessage, $options);
        
        // Reset template tracking
        self::$currentTemplate = null;
        self::$currentMetadata = [];
        
        return $success;
    }
    
    /**
     * Log email to database
     */
    private static function logEmail($to, $subject, $body, $success, $errorMessage = null, $options = []) {
        try {
            if (!class_exists('Database')) {
                return;
            }
            
            $db = Database::getInstance();
            if (!$db || !$db->isConnected() || !$db->tableExists('email_logs')) {
                return;
            }
            
            // Get actor info from session
            $actorId = $_SESSION['user_id'] ?? null;
            $actorEmail = $_SESSION['user_email'] ?? null;
            $actorRole = $_SESSION['user_role'] ?? null;
            
            // Create body preview (first 500 chars of plain text)
            $plainText = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body));
            $bodyPreview = substr($plainText, 0, 500);
            
            $logData = [
                'recipient_email' => $to,
                'recipient_name' => $options['recipient_name'] ?? null,
                'subject' => $subject,
                'template' => self::$currentTemplate,
                'body_preview' => $bodyPreview,
                'status' => $success ? 'sent' : 'failed',
                'error_message' => $errorMessage,
                'company_id' => $options['company_id'] ?? $_SESSION['company_id'] ?? null,
                'employee_id' => $options['employee_id'] ?? null,
                'actor_id' => $actorId,
                'actor_email' => $actorEmail,
                'actor_role' => $actorRole,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'metadata' => json_encode(array_merge(self::$currentMetadata, $options['metadata'] ?? []))
            ];
            
            $db->insert('email_logs', $logData);
        } catch (Exception $e) {
            error_log("Failed to log email: " . $e->getMessage());
        }
    }
    
    /**
     * Send email using SMTP
     */
    private static function sendWithSMTP($to, $subject, $body, $attachments, $config) {
        $boundary = md5(uniqid(time()));
        $boundaryMixed = 'mixed-' . $boundary;
        $boundaryAlt = 'alt-' . $boundary;
        
        // Build headers
        $headers = [];
        $headers[] = "MIME-Version: 1.0";
        $headers[] = "From: {$config['from_name']} <{$config['from_email']}>";
        $headers[] = "Reply-To: {$config['from_email']}";
        $headers[] = "To: {$to}";
        $headers[] = "Subject: {$subject}";
        $headers[] = "Date: " . date('r');
        $headers[] = "Message-ID: <" . md5(uniqid()) . "@" . parse_url(getBaseUrl(), PHP_URL_HOST) . ">";
        
        if (!empty($attachments)) {
            $headers[] = "Content-Type: multipart/mixed; boundary=\"{$boundaryMixed}\"";
        } else {
            $headers[] = "Content-Type: multipart/alternative; boundary=\"{$boundaryAlt}\"";
        }
        
        // Build message body
        $message = "";
        
        if (!empty($attachments)) {
            $message .= "--{$boundaryMixed}\r\n";
            $message .= "Content-Type: multipart/alternative; boundary=\"{$boundaryAlt}\"\r\n\r\n";
        }
        
        // Plain text version
        $message .= "--{$boundaryAlt}\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $message .= chunk_split(base64_encode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body))));
        $message .= "\r\n";
        
        // HTML version
        $message .= "--{$boundaryAlt}\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $message .= chunk_split(base64_encode(self::wrapInTemplate($body)));
        $message .= "\r\n";
        
        $message .= "--{$boundaryAlt}--\r\n";
        
        // Attachments
        if (!empty($attachments)) {
            foreach ($attachments as $attachment) {
                $filePath = $attachment['path'] ?? '';
                $fileName = $attachment['name'] ?? basename($filePath);
                
                if (!file_exists($filePath)) {
                    continue;
                }
                
                $fileContent = file_get_contents($filePath);
                $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
                
                $message .= "--{$boundaryMixed}\r\n";
                $message .= "Content-Type: {$mimeType}; name=\"{$fileName}\"\r\n";
                $message .= "Content-Disposition: attachment; filename=\"{$fileName}\"\r\n";
                $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
                $message .= chunk_split(base64_encode($fileContent));
                $message .= "\r\n";
            }
            $message .= "--{$boundaryMixed}--\r\n";
        }
        
        // Connect to SMTP server
        $contextOptions = [];
        if ($config['encryption'] === 'ssl') {
            // Verify the SMTP peer by default. Operators who truly need to
            // talk to a self-signed relay can set SMTP_INSECURE=1 in config.
            $insecure = defined('SMTP_INSECURE') && SMTP_INSECURE;
            $contextOptions['ssl'] = [
                'verify_peer'       => !$insecure,
                'verify_peer_name'  => !$insecure,
                'allow_self_signed' => $insecure,
            ];
            $host = 'ssl://' . $config['host'];
        } elseif ($config['encryption'] === 'tls') {
            $host = $config['host'];
        } else {
            $host = $config['host'];
        }
        
        $context = stream_context_create($contextOptions);
        $socket = @stream_socket_client(
            $host . ':' . $config['port'],
            $errno,
            $errstr,
            30,
            STREAM_CLIENT_CONNECT,
            $context
        );
        
        if (!$socket) {
            throw new Exception("Failed to connect to SMTP server: {$errstr} ({$errno})");
        }
        
        // Set timeout
        stream_set_timeout($socket, 30);
        
        // Read greeting
        self::smtpGetResponse($socket);
        
        // EHLO
        self::smtpCommand($socket, "EHLO " . gethostname());
        
        // STARTTLS for TLS encryption
        if ($config['encryption'] === 'tls') {
            self::smtpCommand($socket, "STARTTLS");
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            self::smtpCommand($socket, "EHLO " . gethostname());
        }
        
        // AUTH LOGIN
        self::smtpCommand($socket, "AUTH LOGIN");
        self::smtpCommand($socket, base64_encode($config['username']));
        self::smtpCommand($socket, base64_encode($config['password']));
        
        // MAIL FROM
        self::smtpCommand($socket, "MAIL FROM:<{$config['from_email']}>");
        
        // RCPT TO
        self::smtpCommand($socket, "RCPT TO:<{$to}>");
        
        // DATA
        self::smtpCommand($socket, "DATA");
        
        // Send message
        fwrite($socket, implode("\r\n", $headers) . "\r\n\r\n" . $message . "\r\n.\r\n");
        self::smtpGetResponse($socket);
        
        // QUIT
        self::smtpCommand($socket, "QUIT");
        
        fclose($socket);
        
        return true;
    }
    
    /**
     * Send SMTP command and get response
     */
    private static function smtpCommand($socket, $command) {
        fwrite($socket, $command . "\r\n");
        return self::smtpGetResponse($socket);
    }
    
    /**
     * Get SMTP response
     */
    private static function smtpGetResponse($socket) {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) == ' ') {
                break;
            }
        }
        
        $code = substr($response, 0, 3);
        if ($code[0] == '4' || $code[0] == '5') {
            throw new Exception("SMTP error: " . trim($response));
        }
        
        return $response;
    }
    
    /**
     * Fallback to PHP mail()
     */
    private static function sendWithPhpMail($to, $subject, $body, $attachments, $fromEmail, $fromName) {
        $boundary = md5(uniqid(time()));
        
        $headers = "From: {$fromName} <{$fromEmail}>\r\n";
        $headers .= "Reply-To: {$fromEmail}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        
        if (empty($attachments)) {
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $message = self::wrapInTemplate($body);
        } else {
            $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";
            
            $message = "--{$boundary}\r\n";
            $message .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
            $message .= self::wrapInTemplate($body) . "\r\n\r\n";
            
            foreach ($attachments as $attachment) {
                $filePath = $attachment['path'] ?? '';
                $fileName = $attachment['name'] ?? basename($filePath);
                
                if (!file_exists($filePath)) continue;
                
                $fileContent = file_get_contents($filePath);
                $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
                
                $message .= "--{$boundary}\r\n";
                $message .= "Content-Type: {$mimeType}; name=\"{$fileName}\"\r\n";
                $message .= "Content-Disposition: attachment; filename=\"{$fileName}\"\r\n";
                $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
                $message .= chunk_split(base64_encode($fileContent)) . "\r\n";
            }
            $message .= "--{$boundary}--";
        }
        
        return mail($to, $subject, $message, $headers);
    }
    
    /**
     * Wrap content in HTML email template
     */
    private static function wrapInTemplate($content) {
        $siteName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
        $siteUrl = function_exists('getBaseUrl') ? getBaseUrl() : 'https://cardify.om/';
        $supportEmail = self::getSetting('mail_support_email', 'support@cardify.om');
        $year = date('Y');
        
        // Cardify logo - use hosted PNG for universal email client support
        $logoHtml = '<img src="https://cardify.om/assets/images/email-logo.png" alt="Cardify" width="160" height="40" style="display: block; margin: 0 auto;">';
        
        // Instagram - simple image link (works in all clients with hosted image)
        $instagramIcon = '<a href="https://instagram.com/cardifyom" title="Follow us on Instagram" style="display: inline-block; text-decoration: none;"><img src="https://cardify.om/assets/images/instalogo.png" alt="@cardifyom" width="32" height="32" style="display: block; border: 0; margin: 0 auto;"></a>';
        
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>{$siteName}</title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
    <style type="text/css">
        /* Reset styles */
        body, table, td, p, a, li { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
        
        /* Base styles */
        body {
            margin: 0 !important;
            padding: 0 !important;
            background-color: #f8fafc;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #1e293b;
        }
        
        /* Container */
        .email-wrapper {
            width: 100%;
            background-color: #f8fafc;
            padding: 40px 20px;
        }
        
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
        
        /* Header */
        .email-header {
            background: linear-gradient(135deg, #1e40af 0%, #3b82f6 50%, #0ea5e9 100%);
            padding: 32px 40px;
            text-align: center;
        }
        
        .email-header-logo {
            margin-bottom: 8px;
        }
        
        .email-header-tagline {
            color: rgba(255, 255, 255, 0.9);
            font-size: 13px;
            font-weight: 400;
            letter-spacing: 0.5px;
            margin: 0;
        }
        
        /* Content */
        .email-content {
            padding: 40px;
        }
        
        .email-content h2 {
            color: #1e3a5f;
            font-size: 22px;
            font-weight: 700;
            margin: 0 0 16px 0;
            line-height: 1.3;
        }
        
        .email-content p {
            color: #475569;
            font-size: 15px;
            line-height: 1.7;
            margin: 0 0 16px 0;
        }
        
        /* Buttons */
        .btn {
            display: inline-block;
            padding: 14px 32px;
            background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%);
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            text-align: center;
            margin: 8px 0;
            box-shadow: 0 4px 14px 0 rgba(30, 64, 175, 0.3);
        }
        
        .btn:hover {
            background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
        }
        
        /* Info boxes */
        .info-box {
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
            border-left: 4px solid #3b82f6;
            padding: 20px;
            margin: 20px 0;
            border-radius: 0 12px 12px 0;
        }
        
        .success-box {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border-left: 4px solid #22c55e;
            padding: 20px;
            margin: 20px 0;
            border-radius: 0 12px 12px 0;
        }
        
        .warning-box {
            background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
            border-left: 4px solid #f59e0b;
            padding: 20px;
            margin: 20px 0;
            border-radius: 0 12px 12px 0;
        }
        
        .error-box {
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
            border-left: 4px solid #ef4444;
            padding: 20px;
            margin: 20px 0;
            border-radius: 0 12px 12px 0;
        }
        
        /* Tables */
        .data-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin: 20px 0;
            background: #f8fafc;
            border-radius: 12px;
            overflow: hidden;
        }
        
        .data-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 14px;
        }
        
        .data-table tr:last-child td {
            border-bottom: none;
        }
        
        .data-table td:first-child {
            font-weight: 600;
            color: #64748b;
            width: 40%;
            background: #f1f5f9;
        }
        
        .data-table td:last-child {
            color: #1e293b;
        }
        
        /* Divider */
        .divider {
            height: 1px;
            background: linear-gradient(90deg, transparent, #e2e8f0, transparent);
            margin: 32px 0;
        }
        
        /* Footer */
        .email-footer {
            background: #f8fafc;
            padding: 32px 40px;
            text-align: center;
            border-top: 1px solid #e2e8f0;
        }
        
        .footer-logo {
            margin-bottom: 16px;
        }
        
        .footer-links {
            margin: 16px 0;
        }
        
        .footer-links a {
            color: #3b82f6;
            text-decoration: none;
            font-size: 13px;
            margin: 0 12px;
            font-weight: 500;
        }
        
        .footer-links a:hover {
            text-decoration: underline;
        }
        
        .footer-social {
            margin: 24px 0;
            text-align: center;
        }
        
        .footer-contact {
            color: #64748b;
            font-size: 13px;
            line-height: 1.8;
            margin: 16px 0;
        }
        
        .footer-contact a {
            color: #3b82f6;
            text-decoration: none;
        }
        
        .footer-legal {
            color: #94a3b8;
            font-size: 11px;
            margin-top: 20px;
            line-height: 1.6;
        }
        
        .footer-legal a {
            color: #94a3b8;
            text-decoration: underline;
        }
        
        /* Card preview styles */
        .card-preview {
            display: inline-block;
            margin: 12px;
            text-align: center;
        }
        
        .card-preview-label {
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .card-preview img {
            max-width: 280px;
            border-radius: 12px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.15), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
        }
        
        /* Responsive */
        @media only screen and (max-width: 600px) {
            .email-wrapper {
                padding: 20px 10px;
            }
            .email-header,
            .email-content,
            .email-footer {
                padding: 24px 20px;
            }
            .email-content h2 {
                font-size: 20px;
            }
            .btn {
                display: block;
                width: 100%;
                box-sizing: border-box;
            }
            .card-preview {
                display: block;
                margin: 12px 0;
            }
            .card-preview img {
                max-width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="email-wrapper">
        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
            <tr>
                <td align="center">
                    <div class="email-container">
                        <!-- Header - table-based for Outlook compatibility -->
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #1e40af;">
                            <tr>
                                <td align="center" style="padding: 32px 40px; background-color: #1e40af;">
                                    {$logoHtml}
                                    <p style="color: rgba(255,255,255,0.9); font-size: 13px; font-weight: 400; letter-spacing: 0.5px; margin: 12px 0 0 0; font-family: 'Gill Sans', 'Gill Sans MT', Calibri, Arial, sans-serif;">Business Cards Made Simple</p>
                                </td>
                            </tr>
                        </table>
                        
                        <!-- Content -->
                        <div class="email-content">
                            {$content}
                        </div>
                        
                        <!-- Footer -->
                        <div class="email-footer">
                            <div class="footer-links">
                                <a href="{$siteUrl}">Website</a>
                                <a href="{$siteUrl}about.php">About Us</a>
                                <a href="{$siteUrl}contact.php">Contact</a>
                            </div>
                            
                            <div class="footer-social">
                                {$instagramIcon}
                            </div>
                            
                            <div class="footer-contact">
                                <strong>{$siteName}</strong><br>
                                Muscat, Sultanate of Oman<br>
                                <a href="mailto:{$supportEmail}">{$supportEmail}</a>
                            </div>
                            
                            <div class="footer-legal">
                                &copy; {$year} {$siteName}. All rights reserved.<br>
                                <a href="{$siteUrl}privacy.php">Privacy Policy</a> &bull; 
                                <a href="{$siteUrl}terms.php">Terms of Service</a>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Unsubscribe note -->
                    <p style="color: #94a3b8; font-size: 11px; margin-top: 20px; text-align: center;">
                        This email was sent by {$siteName}. If you have questions, please contact us at <a href="mailto:{$supportEmail}" style="color: #64748b;">{$supportEmail}</a>
                    </p>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
HTML;
    }
    
    /**
     * Send using a predefined template
     * @param string $to Recipient email
     * @param string $template Template name
     * @param array $data Data to merge into template
     * @param array $attachments Optional attachments
     * @param array $options Optional: ['company_id' => int, 'employee_id' => int, 'recipient_name' => string]
     * @return bool Success status
     */
    /**
     * Locale-aware templated mail.
     *
     * Pulls `{$key}_subject` and `{$key}_body` from lang/{locale}/emails.php,
     * interpolates named params via :name, and ships via self::send().
     *
     * Usage:
     *   Mailer::sendTemplated($to, 'welcome', 'ar', ['name' => 'Ali']);
     *   Mailer::sendTemplated($to, 'otp', $employee['locale'] ?? 'en', ['code' => $code]);
     *
     * Wraps body in a minimal bilingual HTML shell that honors RTL for Arabic.
     */
    public static function sendTemplated($to, string $key, string $locale = 'en', array $params = [], array $attachments = [], array $options = [])
    {
        if (!class_exists('I18n')) {
            require_once __DIR__ . '/I18n.php';
        }
        $subject = I18n::t("emails.{$key}_subject", $params, $locale);
        $bodyText = I18n::t("emails.{$key}_body",    $params, $locale);
        if ($subject === "emails.{$key}_subject" || $bodyText === "emails.{$key}_body") {
            self::$lastError = "Templated mail key missing: emails.{$key}_{subject|body} in locale {$locale}";
            return false;
        }
        $dir  = ($locale === 'ar') ? 'rtl' : 'ltr';
        $font = ($locale === 'ar') ? '"IBM Plex Sans Arabic", "Segoe UI", Tahoma, sans-serif' : 'Inter, "Segoe UI", Arial, sans-serif';
        $greet = I18n::t('emails.greeting_name', ['name' => $params['name'] ?? $params['admin_name'] ?? ''], $locale);
        if (empty($params['name']) && empty($params['admin_name'])) {
            $greet = I18n::t('emails.greeting_there', [], $locale);
        }
        $signoff     = I18n::t('emails.signoff', [], $locale);
        $signoffTeam = I18n::t('emails.signoff_team', [], $locale);
        $addr        = I18n::t('emails.footer_address', [], $locale);
        $unsubLabel  = I18n::t('emails.footer_unsubscribe', [], $locale);

        $ctaBlock = '';
        if (!empty($options['cta_url'])) {
            $ctaLabelKey = $options['cta_label_key'] ?? "{$key}_cta";
            $ctaLabel = I18n::t("emails.{$ctaLabelKey}", $params, $locale);
            if ($ctaLabel === "emails.{$ctaLabelKey}") { $ctaLabel = I18n::t('common.open', [], $locale); }
            $ctaBlock = '<p style="margin:24px 0;"><a href="' . htmlspecialchars($options['cta_url']) . '" style="display:inline-block;background:#009bc1;color:#fff;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:600;">' . htmlspecialchars($ctaLabel) . '</a></p>';
        }

        $body = '<!doctype html><html lang="' . $locale . '" dir="' . $dir . '"><head><meta charset="utf-8"></head>'
              . '<body style="margin:0;padding:0;background:#f8fafc;font-family:' . $font . ';color:#0f172a;line-height:1.55;">'
              . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f8fafc;padding:24px 0;"><tr><td align="center">'
              . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="560" style="background:#ffffff;border-radius:12px;padding:32px;box-shadow:0 1px 3px rgba(15,23,42,0.06);">'
              . '<tr><td>'
              . '<p style="margin:0 0 16px;">' . htmlspecialchars($greet) . '</p>'
              . '<p style="margin:0 0 16px;">' . htmlspecialchars($bodyText) . '</p>'
              . $ctaBlock
              . '<p style="margin:24px 0 4px;">' . htmlspecialchars($signoff) . '</p>'
              . '<p style="margin:0;color:#475569;">' . htmlspecialchars($signoffTeam) . '</p>'
              . '</td></tr></table>'
              . '<p style="margin:16px 0 4px;color:#94a3b8;font-size:12px;">' . htmlspecialchars($addr) . '</p>'
              . (!empty($options['unsub_url']) ? '<p style="margin:0;color:#94a3b8;font-size:12px;"><a href="' . htmlspecialchars($options['unsub_url']) . '" style="color:#94a3b8;">' . htmlspecialchars($unsubLabel) . '</a></p>' : '')
              . '</td></tr></table></body></html>';

        self::$currentTemplate = "i18n:{$key}:{$locale}";
        self::$currentMetadata = ['template_key' => $key, 'locale' => $locale, 'template_params' => $params];
        return self::send($to, $subject, $body, $attachments, $options);
    }

    public static function sendTemplate($to, $template, $data = [], $attachments = [], $options = []) {
        $templates = self::getTemplates();
        
        if (!isset($templates[$template])) {
            self::$lastError = "Template not found: {$template}";
            return false;
        }
        
        // Track template name for logging
        self::$currentTemplate = $template;
        self::$currentMetadata = ['template_data' => $data];
        
        $tpl = $templates[$template];
        $subject = self::replacePlaceholders($tpl['subject'], $data);
        $body = self::replacePlaceholders($tpl['body'], $data);
        
        // Extract recipient name from data if not provided in options
        if (empty($options['recipient_name'])) {
            $options['recipient_name'] = $data['employee_name'] ?? $data['admin_name'] ?? $data['contact_name'] ?? $data['shop_name'] ?? null;
        }
        
        return self::send($to, $subject, $body, $attachments, $options);
    }
    
    /**
     * Replace {{placeholders}} in text
     */
    private static function replacePlaceholders($text, $data) {
        foreach ($data as $key => $value) {
            $text = str_replace('{{' . $key . '}}', htmlspecialchars($value ?? ''), $text);
        }
        return $text;
    }
    
    /**
     * Get email templates
     */
    private static function getTemplates() {
        $baseUrl = getBaseUrl();
        
        return [
            'request_submitted' => [
                'subject' => 'Card Request Received - {{company_name}}',
                'body' => <<<HTML
<h2>Thank you for your submission!</h2>
<p>Dear {{employee_name}},</p>
<p>We have received your business card request for <strong>{{company_name}}</strong>.</p>

<div class="info-box">
    <strong>Request Details:</strong>
    <table>
        <tr><td>Name</td><td>{{employee_name}}</td></tr>
        <tr><td>Position</td><td>{{position}}</td></tr>
        <tr><td>Email</td><td>{{email}}</td></tr>
        <tr><td>Phone</td><td>{{phone}}</td></tr>
    </table>
</div>

<p>Your request is now pending review. You will receive another email once your card has been approved and generated.</p>

<p>Best regards,<br>The {{company_name}} Team</p>
HTML
            ],
            
            'request_approved' => [
                'subject' => 'Your Business Card is Ready! - {{company_name}}',
                'body' => <<<HTML
<h2>Great news! Your business card is ready.</h2>
<p>Dear {{employee_name}},</p>
<p>Your business card request has been <strong style="color: #22c55e;">approved</strong> and your card has been generated.</p>

<div class="success-box">
    <strong>Your card is attached to this email.</strong><br>
    You can download and use it for printing or digital sharing.
</div>

<table>
    <tr><td>Name</td><td>{{employee_name}}</td></tr>
    <tr><td>Position</td><td>{{position}}</td></tr>
    <tr><td>Email</td><td>{{email}}</td></tr>
    <tr><td>Phone</td><td>{{phone}}</td></tr>
</table>

<p>If you need any changes, please contact your administrator.</p>

<p>Best regards,<br>The {{company_name}} Team</p>
HTML
            ],
            
            'card_proof' => [
                'subject' => 'Business Card Proof - {{employee_name}} | {{company_name}}',
                'body' => <<<HTML
<h2>Business Card Proof</h2>
<p>Dear {{recipient_name}},</p>
<p>A business card has been generated for <strong>{{employee_name}}</strong> at <strong>{{company_name}}</strong>.</p>

<div class="info-box">
    <strong>Card Preview:</strong>
</div>

<div style="text-align: center; margin: 20px 0;">
    <div style="display: inline-block; margin: 10px; text-align: center;">
        <p style="font-weight: 600; color: #374151; margin-bottom: 8px;">FRONT</p>
        <img src="cid:front_card" alt="Front Card" style="max-width: 300px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.15);">
    </div>
    <div style="display: inline-block; margin: 10px; text-align: center;">
        <p style="font-weight: 600; color: #374151; margin-bottom: 8px;">BACK</p>
        <img src="cid:back_card" alt="Back Card" style="max-width: 300px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.15);">
    </div>
</div>

<div class="info-box">
    <strong>Employee Details:</strong>
    <table>
        <tr><td>Name (EN)</td><td>{{name_en}}</td></tr>
        <tr><td>Name (AR)</td><td>{{name_ar}}</td></tr>
        <tr><td>Position (EN)</td><td>{{position_en}}</td></tr>
        <tr><td>Position (AR)</td><td>{{position_ar}}</td></tr>
        <tr><td>Email</td><td>{{email}}</td></tr>
        <tr><td>Phone</td><td>{{phone}}</td></tr>
        <tr><td>Mobile</td><td>{{mobile}}</td></tr>
        <tr><td>Department</td><td>{{department}}</td></tr>
    </table>
</div>

<div class="success-box">
    <strong>A digital proof sheet is attached for your records.</strong><br>
    Please review and contact the administrator if any changes are needed.
</div>

<p>Best regards,<br>The {{company_name}} Team</p>

<div style="text-align: center; padding: 20px 0; border-top: 1px solid #eee; margin-top: 20px;">
    <p style="font-size: 12px; color: #999; margin: 0;">
        Create your team's digital business cards at
        <a href="https://cardify.om?ref=email" style="color: #3b82f6; text-decoration: none;">cardify.om</a>
    </p>
</div>
HTML
            ],

            'card_proof_admin' => [
                'subject' => 'New Card Generated: {{employee_name}} | {{company_name}}',
                'body' => <<<HTML
<h2>New Business Card Generated</h2>
<p>A new business card has been generated and requires your attention.</p>

<div style="text-align: center; margin: 20px 0;">
    <div style="display: inline-block; margin: 10px; text-align: center;">
        <p style="font-weight: 600; color: #374151; margin-bottom: 8px;">FRONT</p>
        <img src="cid:front_card" alt="Front Card" style="max-width: 300px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.15);">
    </div>
    <div style="display: inline-block; margin: 10px; text-align: center;">
        <p style="font-weight: 600; color: #374151; margin-bottom: 8px;">BACK</p>
        <img src="cid:back_card" alt="Back Card" style="max-width: 300px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.15);">
    </div>
</div>

<div class="info-box">
    <strong>Employee Details:</strong>
    <table>
        <tr><td>Name (EN)</td><td>{{name_en}}</td></tr>
        <tr><td>Name (AR)</td><td>{{name_ar}}</td></tr>
        <tr><td>Position (EN)</td><td>{{position_en}}</td></tr>
        <tr><td>Position (AR)</td><td>{{position_ar}}</td></tr>
        <tr><td>Email</td><td>{{email}}</td></tr>
        <tr><td>Phone</td><td>{{phone}}</td></tr>
        <tr><td>Mobile</td><td>{{mobile}}</td></tr>
        <tr><td>Department</td><td>{{department}}</td></tr>
    </table>
</div>

<p style="text-align: center;">
    <a href="{{admin_url}}" class="btn">View in Dashboard</a>
</p>

<div class="success-box">
    <strong>A digital proof sheet is attached.</strong><br>
    High-quality files are available in the admin dashboard.
</div>

<p>Best regards,<br>The {{site_name}} Team</p>

<div style="text-align: center; padding: 20px 0; border-top: 1px solid #eee; margin-top: 20px;">
    <p style="font-size: 12px; color: #999; margin: 0;">
        Create your team's digital business cards at
        <a href="https://cardify.om?ref=email" style="color: #3b82f6; text-decoration: none;">cardify.om</a>
    </p>
</div>
HTML
            ],

            'request_rejected' => [
                'subject' => 'Card Request Update - {{company_name}}',
                'body' => <<<HTML
<h2>Card Request Update</h2>
<p>Dear {{employee_name}},</p>
<p>Unfortunately, your business card request could not be approved at this time.</p>

<div class="warning-box">
    <strong>Reason:</strong><br>
    {{rejection_reason}}
</div>

<p>If you believe this is an error or would like to submit a new request with corrections, please contact your administrator or visit the portal again.</p>

<p>Best regards,<br>The {{company_name}} Team</p>
HTML
            ],
            
            'admin_new_request' => [
                'subject' => 'New Card Request: {{employee_name}} - {{company_name}}',
                'body' => <<<HTML
<h2>New Business Card Request</h2>
<p>A new business card request has been submitted and requires your review.</p>

<div class="info-box">
    <strong>Employee Details:</strong>
    <table>
        <tr><td>Name (EN)</td><td>{{name_en}}</td></tr>
        <tr><td>Name (AR)</td><td>{{name_ar}}</td></tr>
        <tr><td>Position (EN)</td><td>{{position_en}}</td></tr>
        <tr><td>Position (AR)</td><td>{{position_ar}}</td></tr>
        <tr><td>Email</td><td>{{email}}</td></tr>
        <tr><td>Phone</td><td>{{phone}}</td></tr>
        <tr><td>Mobile</td><td>{{mobile}}</td></tr>
        <tr><td>Department</td><td>{{department}}</td></tr>
    </table>
</div>

<p style="text-align: center;">
    <a href="{{admin_url}}" class="btn">Review Request</a>
</p>

<p>Please log in to your admin dashboard to approve or reject this request.</p>
HTML
            ],
            
            'card_generated' => [
                'subject' => 'Your Business Cards - {{company_name}}',
                'body' => <<<HTML
<h2>Your Business Cards</h2>
<p>Dear {{employee_name}},</p>
<p>Your business cards have been generated and are attached to this email.</p>

<div class="success-box">
    <strong>Attachments included:</strong>
    <ul>
        <li>Business Card (PDF - print quality)</li>
        <li>Business Card (PNG - digital use)</li>
    </ul>
</div>

<p>You can use the PDF for professional printing and the PNG for digital sharing.</p>

<p>Best regards,<br>The {{company_name}} Team</p>
HTML
            ],
            
            'welcome_company' => [
                'subject' => 'Welcome to {{site_name}}! Your account is ready',
                'body' => <<<HTML
<h2>Welcome to {{site_name}}!</h2>
<p>Hi {{admin_name}},</p>
<p>Congratulations! Your company account <strong>{{company_name}}</strong> has been created successfully.</p>

<div class="success-box">
    <strong>You're all set to start creating business cards!</strong>
</div>

<h3>Next Steps:</h3>
<ol style="margin: 15px 0; padding-left: 20px;">
    <li><strong>Design your card template</strong> - Create beautiful, professional designs</li>
    <li><strong>Add employees</strong> - Import or add your team members</li>
    <li><strong>Generate cards</strong> - Create cards for everyone instantly</li>
    <li><strong>Enable the portal</strong> - Let employees request their own cards</li>
</ol>

<p style="text-align: center; margin: 30px 0;">
    <a href="{{admin_url}}" class="btn">Go to Dashboard</a>
</p>

<div class="info-box">
    <strong>Your Portal URL:</strong><br>
    <a href="{{portal_url}}" style="color: #2563eb;">{{portal_url}}</a><br>
    <small>Share this with employees so they can request their cards.</small>
</div>

<p>If you have any questions, feel free to contact us.</p>

<p>Best regards,<br>The {{site_name}} Team</p>
HTML
            ],
            
            'welcome_print_shop' => [
                'subject' => 'Welcome to {{site_name}} Print Network!',
                'body' => <<<HTML
<h2>Welcome to the {{site_name}} Print Network!</h2>
<p>Hi {{shop_name}},</p>
<p>Thank you for registering as a print shop partner. Your account is now active!</p>

<div class="success-box">
    <strong>Your print shop is now part of our network!</strong><br>
    You'll start receiving print orders from companies using our platform.
</div>

<h3>Getting Started:</h3>
<ol style="margin: 15px 0; padding-left: 20px;">
    <li><strong>Complete your profile</strong> - Add your logo, contact info, and services</li>
    <li><strong>Set your pricing</strong> - Configure prices for different card types</li>
    <li><strong>Check your dashboard</strong> - Monitor incoming orders</li>
</ol>

<p style="text-align: center; margin: 30px 0;">
    <a href="{{dashboard_url}}" class="btn">Go to Dashboard</a>
</p>

<div class="info-box">
    <strong>Account Details:</strong>
    <table>
        <tr><td>Shop Name</td><td>{{shop_name}}</td></tr>
        <tr><td>Email</td><td>{{email}}</td></tr>
        <tr><td>Location</td><td>{{location}}</td></tr>
    </table>
</div>

<p>Best regards,<br>The {{site_name}} Team</p>
HTML
            ],
            
            'print_order_submitted' => [
                'subject' => 'Print Order #{{order_id}} Submitted - {{company_name}}',
                'body' => <<<HTML
<h2>Print Order Submitted</h2>
<p>Dear {{contact_name}},</p>
<p>Your print order has been submitted successfully and is being processed.</p>

<div class="info-box">
    <strong>Order Details:</strong>
    <table>
        <tr><td>Order ID</td><td>#{{order_id}}</td></tr>
        <tr><td>Quantity</td><td>{{quantity}} cards</td></tr>
        <tr><td>Paper Type</td><td>{{paper_type}}</td></tr>
        <tr><td>Finish</td><td>{{finish}}</td></tr>
        <tr><td>Total</td><td>{{total}}</td></tr>
    </table>
</div>

<div class="info-box">
    <strong>Print Shop:</strong><br>
    {{print_shop_name}}<br>
    {{print_shop_location}}
</div>

<p>You will receive updates as your order progresses through printing and shipping.</p>

<p>Best regards,<br>The {{company_name}} Team</p>
HTML
            ],
            
            'print_order_status' => [
                'subject' => 'Order #{{order_id}} Update: {{status}} - {{site_name}}',
                'body' => <<<HTML
<h2>Order Status Update</h2>
<p>Dear {{contact_name}},</p>
<p>Your print order status has been updated.</p>

<div class="{{status_class}}">
    <strong>Status: {{status}}</strong>
</div>

<div class="info-box">
    <strong>Order Details:</strong>
    <table>
        <tr><td>Order ID</td><td>#{{order_id}}</td></tr>
        <tr><td>Quantity</td><td>{{quantity}} cards</td></tr>
        <tr><td>Print Shop</td><td>{{print_shop_name}}</td></tr>
    </table>
</div>

{{tracking_info}}

<p>Best regards,<br>The {{site_name}} Team</p>
HTML
            ],
            
            'employee_welcome' => [
                'subject' => 'Welcome to {{company_name}}!',
                'body' => <<<HTML
<h2>Welcome to {{company_name}}!</h2>
<p>Dear {{employee_name}},</p>
<p>You have been added to the {{company_name}} team on {{site_name}}.</p>

<div class="success-box">
    <strong>Your account is ready!</strong>
</div>

<div class="info-box">
    <strong>Your Details:</strong>
    <table>
        <tr><td>Name</td><td>{{employee_name}}</td></tr>
        <tr><td>Position</td><td>{{position}}</td></tr>
        <tr><td>Email</td><td>{{email}}</td></tr>
        <tr><td>Department</td><td>{{department}}</td></tr>
    </table>
</div>

<p>You can now access your digital business card and share it with contacts.</p>

<p style="text-align: center; margin: 30px 0;">
    <a href="{{card_url}}" class="btn">View Your Card</a>
</p>

<p>Best regards,<br>The {{company_name}} Team</p>
HTML
            ],
            
            'print_order_received' => [
                'subject' => 'New Print Order #{{order_id}} - {{company_name}}',
                'body' => <<<HTML
<h2>New Print Order Received!</h2>
<p>Hi {{shop_name}},</p>
<p>You have received a new print order that requires your attention.</p>

<div class="info-box">
    <strong>Order Details:</strong>
    <table>
        <tr><td>Order Number</td><td><strong>#{{order_number}}</strong></td></tr>
        <tr><td>Company</td><td>{{company_name}}</td></tr>
        <tr><td>Employee</td><td>{{employee_name}}</td></tr>
        <tr><td>Quantity</td><td>{{quantity}} cards</td></tr>
        <tr><td>Paper Type</td><td>{{paper_type}}</td></tr>
        <tr><td>Finish</td><td>{{finish}}</td></tr>
        <tr><td>Delivery</td><td>{{express}}</td></tr>
        <tr><td>Total</td><td><strong>{{total}}</strong></td></tr>
    </table>
</div>

<div class="info-box">
    <strong>Shipping Address:</strong><br>
    {{shipping_name}}<br>
    {{shipping_address}}<br>
    {{shipping_city}}, {{shipping_country}}
</div>

<div class="info-box">
    <strong>Notes:</strong><br>
    {{notes}}
</div>

<p style="text-align: center; margin: 30px 0;">
    <a href="{{dashboard_url}}" class="btn">View Order Details</a>
</p>

<p>Please process this order promptly.</p>

<p>Best regards,<br>The {{site_name}} Team</p>
HTML
            ],
            
            'print_order_shipped' => [
                'subject' => 'Your Order #{{order_id}} Has Been Shipped!',
                'body' => <<<HTML
<h2>Your Order Has Been Shipped!</h2>
<p>Dear {{contact_name}},</p>
<p>Great news! Your business card order has been shipped and is on its way.</p>

<div class="success-box">
    <strong>Order Status: Shipped</strong>
</div>

<div class="info-box">
    <strong>Tracking Information:</strong>
    <table>
        <tr><td>Order ID</td><td>#{{order_id}}</td></tr>
        <tr><td>Tracking Number</td><td><strong>{{tracking_number}}</strong></td></tr>
        <tr><td>Carrier</td><td>{{carrier}}</td></tr>
        <tr><td>Estimated Delivery</td><td>{{estimated_delivery}}</td></tr>
    </table>
</div>

<div class="info-box">
    <strong>Order Details:</strong>
    <table>
        <tr><td>Quantity</td><td>{{quantity}} cards</td></tr>
        <tr><td>Print Shop</td><td>{{print_shop_name}}</td></tr>
    </table>
</div>

<p>You will receive another notification when your order is delivered.</p>

<p>Best regards,<br>The {{site_name}} Team</p>
HTML
            ],
            
            'print_order_delivered' => [
                'subject' => 'Your Order #{{order_id}} Has Been Delivered!',
                'body' => <<<HTML
<h2>Your Order Has Been Delivered!</h2>
<p>Dear {{contact_name}},</p>
<p>Your business card order has been successfully delivered.</p>

<div class="success-box">
    <strong>Order Status: Delivered</strong><br>
    Your {{quantity}} business cards should now be in your hands!
</div>

<div class="info-box">
    <strong>Order Details:</strong>
    <table>
        <tr><td>Order ID</td><td>#{{order_id}}</td></tr>
        <tr><td>Quantity</td><td>{{quantity}} cards</td></tr>
        <tr><td>Print Shop</td><td>{{print_shop_name}}</td></tr>
    </table>
</div>

<p>We hope you're satisfied with your order. If you have any questions or need to order more cards in the future, feel free to reach out!</p>

<p>Best regards,<br>The {{site_name}} Team</p>
HTML
            ],
            
            'quotation_sent' => [
                'subject' => 'Quotation #{{quotation_number}} for Order #{{order_id}} - {{print_shop_name}}',
                'body' => <<<HTML
<h2>Quotation Received</h2>
<p>Dear {{contact_name}},</p>
<p>You have received a quotation for your print order from <strong>{{print_shop_name}}</strong>.</p>

<div class="info-box">
    <strong>Quotation Details:</strong>
    <table>
        <tr><td>Quotation Number</td><td><strong>{{quotation_number}}</strong></td></tr>
        <tr><td>Order Reference</td><td>#{{order_id}}</td></tr>
        <tr><td>Quantity</td><td>{{quantity}} cards</td></tr>
        <tr><td>Paper Type</td><td>{{paper_type}}</td></tr>
        <tr><td>Finish</td><td>{{finish}}</td></tr>
        <tr><td>Amount</td><td><strong>{{amount}}</strong></td></tr>
        <tr><td>Valid Until</td><td>{{valid_until}}</td></tr>
    </table>
</div>

<div class="warning-box">
    <strong>Action Required:</strong><br>
    Please review the quotation and respond by {{valid_until}}. To proceed, you may need to issue a Purchase Order (PO).
</div>

<p>If you have any questions about the quotation, please contact the print shop directly.</p>

<p>Best regards,<br>{{print_shop_name}}</p>
HTML
            ],
            
            'po_received' => [
                'subject' => 'Purchase Order #{{po_number}} Received - Order #{{order_id}}',
                'body' => <<<HTML
<h2>Purchase Order Received</h2>
<p>Dear {{contact_name}},</p>
<p>Thank you! We have received your Purchase Order for the print order.</p>

<div class="success-box">
    <strong>PO Successfully Received!</strong><br>
    We will begin processing your order shortly.
</div>

<div class="info-box">
    <strong>Order Details:</strong>
    <table>
        <tr><td>PO Number</td><td><strong>{{po_number}}</strong></td></tr>
        <tr><td>Order Reference</td><td>#{{order_id}}</td></tr>
        <tr><td>Quantity</td><td>{{quantity}} cards</td></tr>
        <tr><td>Total Amount</td><td>{{amount}}</td></tr>
    </table>
</div>

<p>You will receive updates as your order progresses through production and shipping.</p>

<p>Best regards,<br>{{print_shop_name}}</p>
HTML
            ],
            
            'invoice_sent' => [
                'subject' => 'Invoice #{{invoice_number}} for Order #{{order_id}} - {{print_shop_name}}',
                'body' => <<<HTML
<h2>Invoice</h2>
<p>Dear {{contact_name}},</p>
<p>Please find attached the invoice for your print order from <strong>{{print_shop_name}}</strong>.</p>

<div class="info-box">
    <strong>Invoice Details:</strong>
    <table>
        <tr><td>Invoice Number</td><td><strong>{{invoice_number}}</strong></td></tr>
        <tr><td>Order Reference</td><td>#{{order_id}}</td></tr>
        <tr><td>Invoice Date</td><td>{{invoice_date}}</td></tr>
        <tr><td>Due Date</td><td><strong>{{due_date}}</strong></td></tr>
        <tr><td>Amount Due</td><td><strong>{{amount}}</strong></td></tr>
    </table>
</div>

<div class="warning-box">
    <strong>Payment Information:</strong><br>
    Please ensure payment is made by <strong>{{due_date}}</strong> to avoid any delays.
</div>

<p>If you have any questions regarding this invoice, please contact us.</p>

<p>Best regards,<br>{{print_shop_name}}</p>
HTML
            ],
            
            'payment_received' => [
                'subject' => 'Payment Received - Invoice #{{invoice_number}} | Order #{{order_id}}',
                'body' => <<<HTML
<h2>Payment Confirmation</h2>
<p>Dear {{contact_name}},</p>
<p>Thank you! We have received your payment for Order #{{order_id}}.</p>

<div class="success-box">
    <strong>Payment Confirmed!</strong><br>
    Your payment has been successfully processed.
</div>

<div class="info-box">
    <strong>Payment Details:</strong>
    <table>
        <tr><td>Invoice Number</td><td>{{invoice_number}}</td></tr>
        <tr><td>Order Reference</td><td>#{{order_id}}</td></tr>
        <tr><td>Amount Paid</td><td><strong>{{amount}}</strong></td></tr>
        <tr><td>Payment Date</td><td>{{payment_date}}</td></tr>
    </table>
</div>

<p>Thank you for your business!</p>

<p>Best regards,<br>{{print_shop_name}}</p>
HTML
            ],
            
            'delivery_note_sent' => [
                'subject' => 'Delivery Note #{{dn_number}} - Order #{{order_id}} Ready for Delivery',
                'body' => <<<HTML
<h2>Delivery Note</h2>
<p>Dear {{contact_name}},</p>
<p>Your order is ready and a delivery note has been issued.</p>

<div class="success-box">
    <strong>Your Order is Ready!</strong><br>
    {{quantity}} business cards are being prepared for delivery.
</div>

<div class="info-box">
    <strong>Delivery Details:</strong>
    <table>
        <tr><td>Delivery Note</td><td><strong>{{dn_number}}</strong></td></tr>
        <tr><td>Order Reference</td><td>#{{order_id}}</td></tr>
        <tr><td>Quantity</td><td>{{quantity}} cards</td></tr>
    </table>
</div>

<div class="info-box">
    <strong>Shipping Address:</strong><br>
    {{shipping_name}}<br>
    {{shipping_address}}<br>
    {{shipping_city}}, {{shipping_country}}
</div>

<p>You will receive a tracking notification once the shipment is dispatched.</p>

<p>Best regards,<br>{{print_shop_name}}</p>
HTML
            ],
            
            'activation_reminder' => [
                'subject' => 'Your {{company_name}} business cards are ready to create',
                'body' => <<<HTML
<h2>Your team is ready, just one step left!</h2>
<p>Hi {{admin_name}},</p>
<p>Your <strong>{{company_name}}</strong> account on Cardify has been set up and your employees have been added. The only thing missing is your card design template.</p>

<div class="info-box">
    <strong>Here's where you stand:</strong>
    <table>
        <tr><td>Employees added</td><td><strong>{{employee_count}}</strong></td></tr>
        <tr><td>Card templates</td><td><strong>0, create yours now</strong></td></tr>
    </table>
</div>

<div class="warning-box">
    <strong>One action needed:</strong><br>
    Design your business card template so we can generate cards for all {{employee_count}} employees instantly.
</div>

<p style="text-align: center; margin: 30px 0;">
    <a href="{{admin_url}}" class="btn">Design Your Template Now</a>
</p>

<p>It takes just a few minutes to set up a professional template with your company logo, colors, and layout. Once done, we'll generate cards for everyone automatically.</p>

<p>Best regards,<br>The {{site_name}} Team</p>
HTML
            ],

            'po_request' => [
                'subject' => 'Purchase Order Required - Order #{{order_id}} | {{print_shop_name}}',
                'body' => <<<HTML
<h2>Purchase Order Required</h2>
<p>Dear {{contact_name}},</p>
<p>A Purchase Order (PO) is required to proceed with your print order.</p>

<div class="warning-box">
    <strong>Action Required:</strong><br>
    Please submit your company's Purchase Order to proceed with production.
</div>

<div class="info-box">
    <strong>Order Details:</strong>
    <table>
        <tr><td>Order Reference</td><td>#{{order_id}}</td></tr>
        <tr><td>Quantity</td><td>{{quantity}} cards</td></tr>
        <tr><td>Estimated Amount</td><td>{{amount}}</td></tr>
        <tr><td>Print Shop</td><td>{{print_shop_name}}</td></tr>
    </table>
</div>

<p>You can upload your PO through the order portal or email it directly to the print shop.</p>

<p>Best regards,<br>{{print_shop_name}}</p>
HTML
            ],

            // === Onboarding Drip Sequence ===

            'onboarding_day2' => [
                'subject' => 'Your team is waiting for their business cards',
                'body' => <<<HTML
<h2>Your employees are waiting!</h2>
<p>Hi {{admin_name}},</p>
<p>You created your <strong>{{company_name}}</strong> account yesterday -- great start! But your team doesn't have their business cards yet.</p>

<div class="info-box">
    <strong>Adding employees takes under 2 minutes:</strong>
    <ol style="margin: 10px 0 0; padding-left: 20px;">
        <li>Go to <strong>Employees</strong> in your dashboard</li>
        <li>Click <strong>Add Employee</strong> (or import from Excel)</li>
        <li>Fill in name, position, email &amp; phone</li>
        <li>Click <strong>Generate Card</strong></li>
    </ol>
</div>

<p style="text-align: center; margin: 30px 0;">
    <a href="{{admin_url}}" class="btn">Add Your First Employee</a>
</p>

<div class="info-box">
    <strong>Or let employees request their own cards:</strong><br>
    Share your portal link: <a href="{{portal_url}}" style="color: #2563eb;">{{portal_url}}</a>
</div>

<p>Questions? Just reply to this email.</p>

<p>Best regards,<br>The {{site_name}} Team</p>
HTML
            ],

            'onboarding_day5' => [
                'subject' => '{{company_name}}: ready to print your cards?',
                'body' => <<<HTML
<h2>From digital to printed -- in one click</h2>
<p>Hi {{admin_name}},</p>
<p>Companies using {{site_name}} save hours on business card management. Here's what you can do next:</p>

<div class="success-box">
    <strong>Order printed cards directly from your dashboard.</strong><br>
    Professional quality, delivered to your office. No design files needed -- we use the card you already built.
</div>

<h3>What other companies are doing:</h3>
<ul style="margin: 15px 0; padding-left: 20px; color: #475569;">
    <li>Generating cards for new hires on day one</li>
    <li>Letting employees update their own info via the portal</li>
    <li>Ordering printed batches for events and conferences</li>
</ul>

<p style="text-align: center; margin: 30px 0;">
    <a href="{{admin_url}}" class="btn">Go to Dashboard</a>
</p>

<div class="info-box">
    <strong>Need help?</strong><br>
    Reply to this email or reach us on WhatsApp at <a href="https://wa.me/96898899100" style="color: #2563eb;">+968 9889 9100</a>.
</div>

<p>Best regards,<br>The {{site_name}} Team</p>
HTML
            ]
        ];
    }

    /**
     * Get last error message
     */
    public static function getLastError() {
        return self::$lastError;
    }

    /**
     * Queue onboarding drip emails for a new company signup.
     * Email 1 (welcome_company) is sent immediately by the registration flow.
     * This queues Email 2 (day 2) and Email 3 (day 5).
     */
    public static function queueOnboardingEmails($companyId, $email, $data) {
        try {
            $db = Database::getInstance();
            if (!$db || !$db->isConnected() || !$db->tableExists('email_queue')) {
                return false;
            }

            $now = new DateTime('now', new DateTimeZone('UTC'));

            $emails = [
                ['template' => 'onboarding_day2', 'delay_days' => 2],
                ['template' => 'onboarding_day5', 'delay_days' => 5],
            ];

            foreach ($emails as $item) {
                $sendAt = clone $now;
                $sendAt->modify("+{$item['delay_days']} days");
                // Send at 9:00 AM GST (05:00 UTC)
                $sendAt->setTime(5, 0, 0);

                $db->insert('email_queue', [
                    'id'            => generateUUID(),
                    'company_id'    => $companyId,
                    'user_email'    => $email,
                    'template'      => $item['template'],
                    'template_data' => json_encode($data),
                    'send_at'       => $sendAt->format('Y-m-d H:i:s'),
                    'status'        => 'pending',
                ]);
            }

            return true;
        } catch (Exception $e) {
            error_log("Mailer::queueOnboardingEmails error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Process the email queue -- called by cron.
     * Sends all pending emails whose send_at has passed.
     * @param int $batchSize Max emails to process per run
     * @return array ['sent' => int, 'failed' => int]
     */
    public static function processQueue($batchSize = 20) {
        $stats = ['sent' => 0, 'failed' => 0];
        try {
            $db = Database::getInstance();
            if (!$db || !$db->isConnected() || !$db->tableExists('email_queue')) {
                return $stats;
            }

            $rows = $db->fetchAll(
                "SELECT * FROM email_queue WHERE status = 'pending' AND send_at <= NOW() AND attempts < 3 ORDER BY send_at ASC LIMIT :limit",
                ['limit' => $batchSize]
            );

            foreach ($rows as $row) {
                $data = json_decode($row['template_data'], true) ?: [];
                $success = self::sendTemplate($row['user_email'], $row['template'], $data, [], [
                    'company_id' => $row['company_id'],
                ]);

                if ($success) {
                    $db->update('email_queue', [
                        'status'  => 'sent',
                        'sent_at' => date('Y-m-d H:i:s'),
                        'attempts' => $row['attempts'] + 1,
                    ], 'id = :id', ['id' => $row['id']]);
                    $stats['sent']++;
                } else {
                    $db->update('email_queue', [
                        'attempts'   => $row['attempts'] + 1,
                        'last_error' => self::getLastError(),
                        'status'     => ($row['attempts'] + 1 >= 3) ? 'failed' : 'pending',
                    ], 'id = :id', ['id' => $row['id']]);
                    $stats['failed']++;
                }
            }
        } catch (Exception $e) {
            error_log("Mailer::processQueue error: " . $e->getMessage());
        }
        return $stats;
    }

    /**
     * Get email logs with filtering
     * @param array $filters ['company_id', 'recipient_email', 'status', 'template', 'date_from', 'date_to']
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public static function getLogs($filters = [], $limit = 50, $offset = 0) {
        try {
            $db = Database::getInstance();
            if (!$db || !$db->isConnected() || !$db->tableExists('email_logs')) {
                return [];
            }
            
            $where = [];
            $params = [];
            
            if (!empty($filters['company_id'])) {
                $where[] = "company_id = :company_id";
                $params['company_id'] = $filters['company_id'];
            }
            
            if (!empty($filters['recipient_email'])) {
                $where[] = "recipient_email LIKE :recipient_email";
                $params['recipient_email'] = '%' . $filters['recipient_email'] . '%';
            }
            
            if (!empty($filters['status'])) {
                $where[] = "status = :status";
                $params['status'] = $filters['status'];
            }
            
            if (!empty($filters['template'])) {
                $where[] = "template = :template";
                $params['template'] = $filters['template'];
            }
            
            if (!empty($filters['date_from'])) {
                $where[] = "created_at >= :date_from";
                $params['date_from'] = $filters['date_from'];
            }
            
            if (!empty($filters['date_to'])) {
                $where[] = "created_at <= :date_to";
                $params['date_to'] = $filters['date_to'] . ' 23:59:59';
            }
            
            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
            
            $sql = "SELECT el.*, c.name as company_name 
                    FROM email_logs el 
                    LEFT JOIN companies c ON el.company_id = c.id 
                    {$whereClause} 
                    ORDER BY el.created_at DESC 
                    LIMIT {$limit} OFFSET {$offset}";
            
            return $db->fetchAll($sql, $params);
        } catch (Exception $e) {
            error_log("Failed to get email logs: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Count email logs with filtering
     */
    public static function countLogs($filters = []) {
        try {
            $db = Database::getInstance();
            if (!$db || !$db->isConnected() || !$db->tableExists('email_logs')) {
                return 0;
            }
            
            $where = [];
            $params = [];
            
            if (!empty($filters['company_id'])) {
                $where[] = "company_id = :company_id";
                $params['company_id'] = $filters['company_id'];
            }
            
            if (!empty($filters['recipient_email'])) {
                $where[] = "recipient_email LIKE :recipient_email";
                $params['recipient_email'] = '%' . $filters['recipient_email'] . '%';
            }
            
            if (!empty($filters['status'])) {
                $where[] = "status = :status";
                $params['status'] = $filters['status'];
            }
            
            if (!empty($filters['template'])) {
                $where[] = "template = :template";
                $params['template'] = $filters['template'];
            }
            
            if (!empty($filters['date_from'])) {
                $where[] = "created_at >= :date_from";
                $params['date_from'] = $filters['date_from'];
            }
            
            if (!empty($filters['date_to'])) {
                $where[] = "created_at <= :date_to";
                $params['date_to'] = $filters['date_to'] . ' 23:59:59';
            }
            
            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
            
            $result = $db->fetchOne("SELECT COUNT(*) as count FROM email_logs {$whereClause}", $params);
            return (int)($result['count'] ?? 0);
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * Get available templates list
     */
    public static function getTemplatesList() {
        return array_keys(self::getTemplates());
    }
    
    /**
     * Send card proof email to multiple recipients
     * @param array $recipients Array of ['email' => '', 'name' => '', 'type' => 'employee|admin|department_admin']
     * @param array $employeeData Employee information
     * @param array $companyData Company information
     * @param string $frontImagePath Path to front card image
     * @param string $backImagePath Path to back card image
     * @param string|null $proofSheetPath Path to proof sheet PDF/HTML
     * @return array Results for each recipient
     */
    public static function sendCardProof($recipients, $employeeData, $companyData, $frontImagePath, $backImagePath, $proofSheetPath = null) {
        $results = [];
        $baseUrl = getBaseUrl();
        $siteName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
        
        // Prepare inline images as base64 for email embedding
        $frontBase64 = '';
        $backBase64 = '';
        
        if (file_exists($frontImagePath)) {
            $frontData = file_get_contents($frontImagePath);
            $frontMime = mime_content_type($frontImagePath) ?: 'image/png';
            $frontBase64 = 'data:' . $frontMime . ';base64,' . base64_encode($frontData);
        }
        
        if (file_exists($backImagePath)) {
            $backData = file_get_contents($backImagePath);
            $backMime = mime_content_type($backImagePath) ?: 'image/png';
            $backBase64 = 'data:' . $backMime . ';base64,' . base64_encode($backData);
        }
        
        foreach ($recipients as $recipient) {
            $email = $recipient['email'] ?? '';
            $name = $recipient['name'] ?? '';
            $type = $recipient['type'] ?? 'employee';
            
            if (empty($email) || !isValidEmail($email)) {
                $results[$email] = ['success' => false, 'error' => 'Invalid email'];
                continue;
            }
            
            // Choose template based on recipient type
            $template = ($type === 'employee') ? 'card_proof' : 'card_proof_admin';
            
            // Prepare data - replace CID with base64 for broader email client support
            $data = [
                'recipient_name' => $name ?: $employeeData['name_en'] ?? 'Team Member',
                'employee_name' => $employeeData['name_en'] ?? '',
                'name_en' => $employeeData['name_en'] ?? '',
                'name_ar' => $employeeData['name_ar'] ?? '',
                'position_en' => $employeeData['position_en'] ?? '',
                'position_ar' => $employeeData['position_ar'] ?? '',
                'email' => $employeeData['email'] ?? '',
                'phone' => $employeeData['phone'] ?? '',
                'mobile' => $employeeData['mobile'] ?? '',
                'department' => $employeeData['department_name'] ?? '',
                'company_name' => $companyData['name_en'] ?? $companyData['name'] ?? '',
                'site_name' => $siteName,
                'admin_url' => $baseUrl . 'admin/employees.php'
            ];
            
            // Attachments (proof sheet PDF and card files)
            $attachments = [];
            if ($proofSheetPath && file_exists($proofSheetPath)) {
                $ext = pathinfo($proofSheetPath, PATHINFO_EXTENSION);
                $attachments[] = [
                    'path' => $proofSheetPath,
                    'name' => 'digital_proof_' . preg_replace('/[^a-z0-9]/i', '_', $employeeData['name_en'] ?? 'card') . '.' . $ext
                ];
            }
            
            // Also attach the card PDFs if available
            $frontPdf = str_replace('.png', '.pdf', $frontImagePath);
            $backPdf = str_replace('.png', '.pdf', $backImagePath);
            
            if (file_exists($frontPdf)) {
                $attachments[] = ['path' => $frontPdf, 'name' => 'business_card_front.pdf'];
            }
            if (file_exists($backPdf)) {
                $attachments[] = ['path' => $backPdf, 'name' => 'business_card_back.pdf'];
            }
            
            // Get template and customize body with embedded images
            $templates = self::getTemplates();
            $tpl = $templates[$template];
            $subject = self::replacePlaceholders($tpl['subject'], $data);
            $body = self::replacePlaceholders($tpl['body'], $data);
            
            // Replace CID references with base64 data URIs for better compatibility
            $body = str_replace('src="cid:front_card"', 'src="' . $frontBase64 . '"', $body);
            $body = str_replace('src="cid:back_card"', 'src="' . $backBase64 . '"', $body);
            
            // Track template for logging
            self::$currentTemplate = $template;
            self::$currentMetadata = ['template_data' => $data, 'recipient_type' => $type];
            
            $success = self::send($email, $subject, $body, $attachments, [
                'recipient_name' => $name,
                'company_id' => $companyData['id'] ?? null,
                'employee_id' => $employeeData['id'] ?? null
            ]);
            
            $results[$email] = [
                'success' => $success,
                'error' => $success ? null : self::getLastError()
            ];
        }
        
        return $results;
    }
    
    /**
     * Send combined card PDF via email
     * Creates and sends a single PDF with both card sides
     */
    public static function sendCombinedCard($to, $employeeData, $companyData, $frontImagePath, $backImagePath, $options = []) {
        require_once INCLUDES_DIR . '/ProofSheet.php';
        
        // Generate proof sheet if not provided
        $proofSheetPath = $options['proof_sheet'] ?? null;
        if (!$proofSheetPath) {
            $proofSheetPath = ProofSheet::generate(
                $frontImagePath,
                $backImagePath,
                $employeeData,
                $companyData
            );
        }
        
        // Build recipients list
        $recipients = [
            ['email' => $to, 'name' => $employeeData['name_en'] ?? '', 'type' => 'employee']
        ];
        
        // Add admin recipients if specified
        if (!empty($options['cc_admin']) && !empty($companyData['admin_email'])) {
            $recipients[] = [
                'email' => $companyData['admin_email'],
                'name' => $companyData['admin_name'] ?? 'Admin',
                'type' => 'admin'
            ];
        }
        
        // Add department admin if specified
        if (!empty($options['cc_department_admin']) && !empty($options['department_admin_email'])) {
            $recipients[] = [
                'email' => $options['department_admin_email'],
                'name' => $options['department_admin_name'] ?? 'Department Admin',
                'type' => 'department_admin'
            ];
        }
        
        return self::sendCardProof($recipients, $employeeData, $companyData, $frontImagePath, $backImagePath, $proofSheetPath);
    }
    
    /**
     * Test email configuration
     */
    public static function testConnection() {
        $host = self::getSetting('mail_host', '');
        $port = self::getSetting('mail_port', 587);
        $encryption = self::getSetting('mail_encryption', '');
        
        if (empty($host)) {
            return ['success' => false, 'error' => 'SMTP host not configured'];
        }
        
        try {
            $contextOptions = [];
            if ($encryption === 'ssl') {
                $contextOptions['ssl'] = [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false
                ];
                $connectHost = 'ssl://' . $host;
            } else {
                $connectHost = $host;
            }
            
            $context = stream_context_create($contextOptions);
            $socket = @stream_socket_client(
                $connectHost . ':' . $port,
                $errno,
                $errstr,
                10,
                STREAM_CLIENT_CONNECT,
                $context
            );
            
            if (!$socket) {
                return ['success' => false, 'error' => "Connection failed: {$errstr} ({$errno})"];
            }
            
            $response = fgets($socket, 515);
            fclose($socket);
            
            return ['success' => true, 'message' => 'Connection successful', 'response' => trim($response)];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
