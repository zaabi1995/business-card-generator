<?php
/**
 * ProductionWhatsApp: post a print-ready card sheet to BHD's production
 * WhatsApp group, next to the production email.
 *
 * Ali, 24 Sep 2026: "can it also auto send to the whatsapp group?" The team
 * prints from the "BHD - Production" group (120363019903540623), and business
 * cards are printed in-house, so every card that reaches production is posted
 * there as one message: the A4 PDF with a short caption.
 *
 * Sends from Ali's line through Dardasha on this host. The public dardasha.om
 * URL returns 403 from the VPS itself (Cloudflare), so it must be 127.0.0.1.
 * Same contract as bhd-erp scripts/host/audit-group-notify.py.
 *
 * Config (server-only, config.php): PRODUCTION_WA_GROUP, PRODUCTION_WA_FROM,
 * PRODUCTION_WA_TOKEN, and optionally PRODUCTION_WA_URL. With no group or
 * token defined this does nothing and says so.
 */
class ProductionWhatsApp
{
    private const DEFAULT_URL = 'http://127.0.0.1:3000/api/qr/rest/send_message';

    /** @return array ['ok'=>bool, 'skipped'=>bool, 'error'=>?string, 'messageId'=>?string] */
    public static function post(array $job, array $dept, string $pdfPath, string $caption, ?string $fileName = null): array
    {
        // The division's own client group when it has one (OHB: "OHB Cards",
        // where BHD production staff sit with the client), else BHD - Production.
        $group = trim((string)($dept['whatsapp_group'] ?? ''));
        if ($group === '') { $group = defined('PRODUCTION_WA_GROUP') ? (string)PRODUCTION_WA_GROUP : ''; }
        $token = defined('PRODUCTION_WA_TOKEN') ? (string)PRODUCTION_WA_TOKEN : '';
        $from  = defined('PRODUCTION_WA_FROM')  ? (string)PRODUCTION_WA_FROM  : '';
        $url   = defined('PRODUCTION_WA_URL')   ? (string)PRODUCTION_WA_URL   : self::DEFAULT_URL;
        if ($group === '' || $token === '' || $from === '') {
            return ['ok' => false, 'skipped' => true, 'error' => 'production group not configured', 'messageId' => null];
        }
        if (!is_file($pdfPath)) {
            return ['ok' => false, 'skipped' => false, 'error' => 'no print file', 'messageId' => null];
        }

        $ref  = preg_replace('/[^A-Za-z0-9._-]/', '-', (string)($job['job_ref'] ?? 'card'));
        $name = $fileName ?: $ref . '-print-ready-A4.pdf';
        $body = [
            'messageType'  => 'document',
            'requestType'  => 'POST',
            'token'        => $token,
            'from'         => $from,
            'to'           => $group,
            'media'        => ['data' => base64_encode((string)file_get_contents($pdfPath)), 'filename' => $name],
            'documentName' => $name,
            'caption'      => $caption,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 60,
        ]);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        $res = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        if ($err !== '' || $code !== 200 || empty($res['success'])) {
            $why = $err ?: ('HTTP ' . $code . ' ' . substr((string)($res['message'] ?? $raw), 0, 200));
            error_log('[production whatsapp] ' . $ref . ': ' . $why);
            return ['ok' => false, 'skipped' => false, 'error' => $why, 'messageId' => null];
        }
        return ['ok' => true, 'skipped' => false, 'error' => null,
                'messageId' => (string)($res['data']['messageId'] ?? '')];
    }

    /** The quotation caption for the client group. */
    public static function quoteCaption(array $job, array $dept, array $price, string $quoteNumber): string
    {
        $name = trim((string)($job['name_en'] ?? '')) ?: trim((string)($job['name_ar'] ?? ''));
        $line = 'Request received: business cards, ' . $name . '.';
        $line .= "\n" . (int)$price['qty'] . ' cards, OMR ' . number_format((float)$price['gross'], 3) . ' incl. VAT.';
        if ($quoteNumber !== '') { $line .= ' Quotation ' . $quoteNumber . ' attached.'; }
        $line .= !empty($dept['invoice_without_po'])
            ? "\nNo PO needed."
            : "\nPlease reply to the quotation email with the PO.";
        return $line;
    }

    /** The group caption: short, what to print and how many. */
    public static function caption(array $job, array $dept, int $sheets = 0): string
    {
        $qty  = (int)($job['quantity_ordered'] ?? 0);
        $name = trim((string)($job['name_en'] ?? '')) ?: trim((string)($job['name_ar'] ?? ''));
        $line = 'Print ready: business cards, ' . $name . ', ' . (string)($dept['name'] ?? '') . '.';
        $line .= "\n" . ($qty ? $qty . ' cards' : 'Cards') . ($sheets ? ' = ' . $sheets . ' A4 sheets (10-up)' : '')
               . '. Art 300 GSM matte, double sided.';
        if (!empty($job['job_ref'])) { $line .= "\nRef " . $job['job_ref'] . '.'; }
        return $line;
    }
}
