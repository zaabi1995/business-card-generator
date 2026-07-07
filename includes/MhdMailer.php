<?php
/**
 * MhdMailer - sends the MHD business-card email on portal Send.
 *
 * Sends AS sales@bhdoman.com so Postfix's sender_dependent_relayhost_maps
 * routes @bhdoman.com through the Microsoft 365 smarthost (only reliable path
 * to Trend-Micro-protected MHD; a cardify.om sender is blocked). Submits over
 * localhost:25 which is mynetworks-trusted, so no SMTP AUTH is required and the
 * reject_sender_login_mismatch rule (which would block sending as another
 * mailbox on the authenticated path) does not apply.
 *
 * To   = the employee
 * Cc   = department responsible_email + cc_emails + sales@bhdoman.com (BHD owner)
 * Body = HTML, with the print-ready card PDF attached.
 */
class MhdMailer
{
    const SENDER      = 'sales@bhdoman.com';
    const SENDER_NAME = 'BHD Printing & Designing';
    const BHD_OWNER   = 'sales@bhdoman.com';
    const SMTP_HOST   = '127.0.0.1';
    const SMTP_PORT   = 25;

    /**
     * @param array $c keys: employee_email, employee_name, division_name,
     *                 responsible_email, cc_emails (array), pdf_path, include_qr (bool)
     * @return array ['ok'=>bool, 'error'=>?string, 'recipients'=>array]
     */
    public static function sendCard(array $c): array
    {
        $to = trim((string)($c['employee_email'] ?? ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'invalid employee email', 'recipients' => []];
        }
        $pdf = (string)($c['pdf_path'] ?? '');
        if ($pdf === '' || !is_file($pdf)) {
            return ['ok' => false, 'error' => 'card pdf not found', 'recipients' => []];
        }

        $name     = trim((string)($c['employee_name'] ?? '')) ?: 'MHD Employee';
        $division = trim((string)($c['division_name'] ?? '')) ?: 'MHD';

        // Build CC: responsible mailbox + extra CCs + BHD owner, deduped, minus the To.
        $cc = array_merge(
            [(string)($c['responsible_email'] ?? '')],
            is_array($c['cc_emails'] ?? null) ? $c['cc_emails'] : [],
            [self::BHD_OWNER]
        );
        $cc = array_values(array_unique(array_filter(array_map('trim', $cc), function ($e) use ($to) {
            return $e !== '' && strcasecmp($e, $to) !== 0 && filter_var($e, FILTER_VALIDATE_EMAIL);
        })));

        $subject = 'MHD Business Card: ' . $name . ', ' . $division;
        $html = self::body($name, $division);
        $filename = 'MHD-Card-' . preg_replace('/[^A-Za-z0-9]+/', '-', $name) . '.pdf';

        $mime = self::buildMime($to, $cc, $subject, $html, $pdf, $filename);
        $recipients = array_merge([$to], $cc);

        $res = self::smtpSend(self::SENDER, $recipients, $mime);
        self::log($to, $subject, $res['ok'], $res['error'] ?? null, [
            'cc' => implode(',', $cc), 'division' => $division,
            'include_qr' => !empty($c['include_qr']),
        ]);
        return ['ok' => $res['ok'], 'error' => $res['error'] ?? null, 'recipients' => $recipients];
    }

    private static function body(string $name, string $division): string
    {
        $n = htmlspecialchars($name, ENT_QUOTES);
        $d = htmlspecialchars($division, ENT_QUOTES);
        return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222;line-height:1.6">'
             . '<p>Dear ' . $n . ',</p>'
             . '<p>Your MHD business card for the <strong>' . $d . '</strong> division is attached as a print-ready PDF.</p>'
             . '<p>A copy has also gone to your division contact and to BHD Printing for processing. '
             . 'If any detail needs a change, reply to this email.</p>'
             . '<p>Regards,<br>BHD Printing &amp; Designing</p>'
             . '</div>';
    }

    /** Build a multipart/mixed message: HTML body + base64 PDF attachment. */
    private static function buildMime(string $to, array $cc, string $subject, string $html, string $pdfPath, string $filename): string
    {
        $eol = "\r\n";
        $boundary = 'mhd-' . bin2hex(substr(md5($subject . $to), 0, 12));
        $subjEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $data = base64_encode((string)file_get_contents($pdfPath));

        $h  = 'From: ' . self::SENDER_NAME . ' <' . self::SENDER . '>' . $eol;
        $h .= 'To: ' . $to . $eol;
        if ($cc) { $h .= 'Cc: ' . implode(', ', $cc) . $eol; }
        $h .= 'Subject: ' . $subjEnc . $eol;
        $h .= 'MIME-Version: 1.0' . $eol;
        $h .= 'Content-Type: multipart/mixed; boundary="' . $boundary . '"' . $eol;

        $b  = '--' . $boundary . $eol;
        $b .= 'Content-Type: text/html; charset=UTF-8' . $eol;
        $b .= 'Content-Transfer-Encoding: base64' . $eol . $eol;
        $b .= chunk_split(base64_encode($html)) . $eol;
        $b .= '--' . $boundary . $eol;
        $b .= 'Content-Type: application/pdf; name="' . $filename . '"' . $eol;
        $b .= 'Content-Transfer-Encoding: base64' . $eol;
        $b .= 'Content-Disposition: attachment; filename="' . $filename . '"' . $eol . $eol;
        $b .= chunk_split($data) . $eol;
        $b .= '--' . $boundary . '--' . $eol;

        return $h . $eol . $b;
    }

    /** Minimal SMTP client to localhost:25 (mynetworks, no AUTH). */
    private static function smtpSend(string $from, array $recipients, string $message): array
    {
        $errno = 0; $errstr = '';
        $fp = @stream_socket_client(self::SMTP_HOST . ':' . self::SMTP_PORT, $errno, $errstr, 15);
        if (!$fp) { return ['ok' => false, 'error' => "connect: $errstr ($errno)"]; }
        stream_set_timeout($fp, 20);

        $read = function () use ($fp) {
            $out = '';
            while (($line = fgets($fp, 515)) !== false) {
                $out .= $line;
                if (isset($line[3]) && $line[3] === ' ') break; // last line of a reply
            }
            return $out;
        };
        $cmd = function (string $c, string $expect) use ($fp, $read) {
            fwrite($fp, $c . "\r\n");
            $resp = $read();
            return [strncmp($resp, $expect, strlen($expect)) === 0, $resp];
        };

        $err = null;
        try {
            $banner = $read();
            if (strncmp($banner, '220', 3) !== 0) { throw new Exception('banner: ' . trim($banner)); }
            [$ok, $r] = $cmd('EHLO cardify.om', '250'); if (!$ok) throw new Exception('EHLO: ' . trim($r));
            [$ok, $r] = $cmd('MAIL FROM:<' . $from . '>', '250'); if (!$ok) throw new Exception('MAIL FROM: ' . trim($r));
            foreach ($recipients as $rcpt) {
                [$ok, $r] = $cmd('RCPT TO:<' . $rcpt . '>', '250');
                if (!$ok) throw new Exception('RCPT ' . $rcpt . ': ' . trim($r));
            }
            [$ok, $r] = $cmd('DATA', '354'); if (!$ok) throw new Exception('DATA: ' . trim($r));
            // dot-stuff any line beginning with '.'
            $safe = preg_replace('/^\./m', '..', $message);
            fwrite($fp, $safe . "\r\n.\r\n");
            $r = $read();
            if (strncmp($r, '250', 3) !== 0) throw new Exception('end-of-data: ' . trim($r));
            $cmd('QUIT', '221');
        } catch (Exception $e) {
            $err = $e->getMessage();
        }
        fclose($fp);
        return ['ok' => $err === null, 'error' => $err];
    }

    private static function log(string $to, string $subject, bool $ok, ?string $error, array $meta): void
    {
        try {
            if (!class_exists('Database')) return;
            $db = Database::getInstance();
            if (!$db || !$db->isConnected() || !$db->tableExists('email_logs')) return;
            $db->insert('email_logs', [
                'recipient_email' => $to,
                'subject'         => $subject,
                'template'        => 'mhd_card',
                'status'          => $ok ? 'sent' : 'failed',
                'error_message'   => $error,
                'metadata'        => json_encode($meta),
            ]);
        } catch (Exception $e) {
            error_log('[MhdMailer] log failed: ' . $e->getMessage());
        }
    }
}
