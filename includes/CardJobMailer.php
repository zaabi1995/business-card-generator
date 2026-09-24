<?php
require_once __DIR__ . '/MhdMailer.php';
require_once __DIR__ . '/CardJob.php';
require_once __DIR__ . '/ERPSync.php';

/**
 * CardJobMailer, the emails of the MHD card flow.
 *
 * Why not Mailer::sendTemplate, which portal.php used before: two reasons.
 * Mailer has no CC, so the division head could never be copied. And it sends as
 * cardify.om, which MHD's Trend Micro blocks. MhdMailer's transport leaves as
 * sales@bhdoman.com over localhost:25, so Postfix's sender_dependent_relayhost
 * routes it through the Microsoft 365 smarthost, the only path live-verified to
 * reach MHD.
 *
 * Every subject carries the job ref as [MHD-XXXXXX]. That tag is how a
 * purchase-order reply is matched back to its job later, because Outlook
 * rewrites threading headers and cannot be relied on.
 */
class CardJobMailer
{
    private const BHD_OWNER = 'sales@bhdoman.com';

    /**
     * Who a flow email goes to.
     *
     * Ali, 17 Sep 2026: the head of the division decides, so the head is the
     * recipient and BHD is copied. The division mailbox is copied too, because
     * that is where the division keeps its own record and where a purchase
     * order is replied from. A division with no named head keeps its mailbox as
     * the recipient, which is every division that has not named one.
     *
     * @return array{0:array<int,string>,1:array<int,string>} [to, cc]
     */
    private static function recipients(array $dept): array
    {
        $head = strtolower(trim((string)($dept['head_email'] ?? '')));
        $box  = strtolower(trim((string)($dept['responsible_email'] ?? '')));

        $to = $head !== '' ? [$head] : ($box !== '' ? [$box] : []);
        $cc = array_values(array_unique(array_filter([
            $head !== '' && $box !== $head ? $box : '',
            self::BHD_OWNER,
        ])));
        return [$to, $cc];
    }

    /**
     * The approval email. One link, to the prefetch-safe interstitial that
     * offers Approve and Reject, rather than two links: a plain reject link
     * would be followed by a scanner.
     *
     * @return array ['ok'=>bool, 'error'=>?string, 'recipients'=>array]
     */
    public static function sendForApproval(array $req, array $dept, string $token, array $designs = []): array
    {
        [$to, $cc] = self::recipients($dept);
        if (!$to) {
            return ['ok' => false, 'error' => 'division has nobody to write to', 'recipients' => []];
        }
        $name = trim((string)($req['name_en'] ?? '')) ?: trim((string)($req['name_ar'] ?? '')) ?: 'An employee';
        $div  = (string)($dept['name'] ?? 'MHD');
        $ref  = (string)($req['job_ref'] ?? '');

        $slug   = (string)($req['company_slug'] ?? 'mhd');
        $action = getTenantUrl($slug, '/admin/one-tap-approve?t=' . urlencode($token));
        // The one-tap page only confirms an approval; rejecting and editing live
        // on the full review page. Saying otherwise sent people looking for a
        // button that is not there.
        $review = getTenantUrl($slug, '/admin/approve-request?t=' . urlencode($token));
        $settings = getTenantUrl($slug, '/admin/division-login');

        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);

        // Both sides, English front and Arabic back, shown inline and attached.
        // The approver should be able to check the Arabic without opening
        // anything, and keep both files without going back to the portal.
        $preview = '';
        $files   = [];
        foreach ([['front', 'English front'], ['back', 'Arabic back']] as [$side, $label]) {
            $url  = trim((string)($designs[$side . '_url']  ?? ''));
            $path = trim((string)($designs[$side . '_path'] ?? ''));
            if ($url !== '') {
                $preview .= '<div style="display:inline-block;margin:8px 6px;text-align:center;vertical-align:top">'
                          . '<img src="' . $e($url) . '" alt="' . $e($label) . '" '
                          . 'style="max-width:320px;width:100%;border-radius:8px;border:1px solid #e5e7eb"><br>'
                          . '<span style="font-size:12px;color:#6b7280">' . $e($label) . '</span></div>';
            }
            // Belt and braces after the portal's own check: the only thing
            // that may be attached here is an image inside uploads/. A path
            // that resolves anywhere else, or a file that is not a picture, is
            // dropped rather than mailed to the customer.
            if ($path !== '' && is_file($path)) {
                $real = realpath($path);
                $root = realpath(BASE_DIR . '/uploads');
                $type = $real ? @getimagesize($real) : false;
                if ($real && $root && strpos($real, $root . DIRECTORY_SEPARATOR) === 0
                    && $type && in_array($type[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
                    $files[] = ['path' => $real,
                                'name' => ($ref !== '' ? $ref . '-' : '') . $side . '.png'];
                } else {
                    error_log('[mhd approval] refused to attach ' . $path);
                }
            }
        }
        if ($preview !== '') {
            $preview = '<div style="text-align:center;margin:18px 0">' . $preview . '</div>';
        }

        $rows = '';
        foreach ([
            'Name'     => $req['name_en'] ?? '',
            'Name (AR)'=> $req['name_ar'] ?? '',
            'Position' => $req['position_en'] ?? '',
            'Mobile'   => ($req['mobile'] ?? '') !== ''
                          ? self::dialCode($dept) . ' ' . $req['mobile'] : '',
            'Email'    => $req['email'] ?? '',
            'Quantity' => (int)($req['quantity_ordered'] ?? 200) . ' cards',
        ] as $k => $v) {
            if (trim((string)$v) === '') { continue; }
            $rows .= '<tr><td style="padding:3px 14px 3px 0;color:#6b7280">' . $e($k)
                   . '</td><td style="padding:3px 0"><strong>' . $e($v) . '</strong></td></tr>';
        }

        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222;line-height:1.6">'
              . '<p><strong>' . $e($name) . '</strong> has requested a business card for <strong>' . $e($div) . '</strong>.</p>'
              . $preview
              . '<table style="border-collapse:collapse;margin:14px 0">' . $rows . '</table>'
              . '<p style="margin:26px 0"><a href="' . $e($action) . '" style="background:#0f4c81;color:#fff;'
              . 'padding:13px 30px;border-radius:6px;text-decoration:none;font-weight:bold;display:inline-block">'
              . 'Approve</a></p>'
              . '<p style="color:#6b7280;font-size:13px">Approving raises the quotation and emails it to this '
              . 'address. Nothing prints until you send the purchase order.</p>'
              . '<p style="color:#6b7280;font-size:13px">To decline instead, or to change anything first, '
              . 'open <a href="' . $e($review) . '" style="color:#0f4c81">the full request</a>.</p>'
              . '<p style="color:#9ca3af;font-size:12px">To change who approves for ' . $e($div)
              . ', or the account it is billed to, sign in at '
              . '<a href="' . $e($settings) . '" style="color:#6b7280">division settings</a>.</p>'
              . '</div>';


        $subject = ($ref !== '' ? "[{$ref}] " : '') . "Business card approval: {$name}, {$div}";
        return MhdMailer::sendRaw($to, $cc, $subject, $html, $files);
    }

    /**
     * The quotation email, sent the moment a division approves.
     *
     * Ali, 16 Sep 2026: the quotation belongs in the attachment, not in the
     * body. The ERP's own PDF is fetched and attached, so what the division
     * reads is the same document BHD files. If that fetch fails, the figures
     * fall back into the body rather than sending a quotation with no price.
     *
     * The subject keeps the job ref so the purchase-order reply can be matched
     * back to this job without relying on threading headers.
     */
    public static function sendQuotation(array $req, array $dept, array $price, array $erp = []): array
    {
        [$to, $cc] = self::recipients($dept);
        if (!$to) {
            return ['ok' => false, 'error' => 'division has nobody to write to', 'recipients' => []];
        }
        $e    = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
        $name = trim((string)($req['name_en'] ?? '')) ?: trim((string)($req['name_ar'] ?? '')) ?: 'the employee';
        $div  = (string)($dept['name'] ?? 'MHD');
        $ref  = (string)($req['job_ref'] ?? '');
        $num  = trim((string)($erp['quoteNumber'] ?? ''));
        $id   = trim((string)($erp['quoteId'] ?? ''));
        $omr  = fn($v) => number_format((float)$v, 3);

        $files = [];
        if ($id !== '') {
            $fileName = $num !== '' ? str_replace('/', '-', $num) : ($ref !== '' ? $ref . '-quotation' : 'quotation');
            $pdf = ERPSync::fetchDocumentPdf('quote', $id, $fileName);
            if ($pdf !== null) {
                $files[] = ['path' => $pdf, 'name' => $fileName . '.pdf'];
            }
        }

        // Only when the PDF could not be fetched. The body otherwise carries no
        // figures at all: Ali, 17 Sep 2026, the rate is standard and settled,
        // so there is nothing for a division to weigh up. A purchase order
        // still cannot be raised against nothing, so if the document is missing
        // the numbers appear here rather than nowhere.
        $figures = '';
        if (!$files) {
            $figures = '<table style="border-collapse:collapse;margin:16px 0;font-size:14px">'
              . '<tr><td style="padding:6px 16px 6px 0;color:#6b7280">Item</td>'
              . '<td style="padding:6px 0"><strong>' . $e($price['description']) . '</strong></td></tr>'
              . '<tr><td style="padding:6px 16px 6px 0;color:#6b7280">Quantity</td>'
              . '<td style="padding:6px 0"><strong>' . (int)$price['qty'] . ' cards</strong></td></tr>'
              . '<tr><td style="padding:6px 16px 6px 0;color:#6b7280">Unit price</td>'
              . '<td style="padding:6px 0">OMR ' . $omr($price['unit']) . '</td></tr>'
              . '<tr><td style="padding:6px 16px 6px 0;color:#6b7280">Net</td>'
              . '<td style="padding:6px 0">OMR ' . $omr($price['net']) . '</td></tr>'
              . '<tr><td style="padding:6px 16px 6px 0;color:#6b7280">VAT 5%</td>'
              . '<td style="padding:6px 0">OMR ' . $omr($price['vat']) . '</td></tr>'
              . '<tr><td style="padding:8px 16px 6px 0;color:#111"><strong>Total</strong></td>'
              . '<td style="padding:8px 0"><strong>OMR ' . $omr($price['gross']) . '</strong></td></tr>'
              . '</table>';
        }

        $line = $files
            ? 'The quotation' . ($num !== '' ? ' <strong>' . $e($num) . '</strong>' : '') . ' is attached.'
            : 'The quotation' . ($num !== '' ? ' <strong>' . $e($num) . '</strong>' : '') . ' is below.';

        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222;line-height:1.6">'
              . '<p>The card for <strong>' . $e($name) . '</strong> is approved for <strong>'
              . $e($div) . '</strong> and is on the standard rate. ' . $line . '</p>'
              . $figures
              . (!empty($dept['invoice_without_po'])
                  // A division invoiced on approval (OHB) is never asked for a PO.
                  ? '<p style="background:#f1f5f9;border-radius:8px;padding:14px 16px;margin:20px 0">'
                    . '<strong>No purchase order is needed.</strong> The invoice and the delivery '
                    . 'note follow with the cards.</p>'
                  : '<p style="background:#f1f5f9;border-radius:8px;padding:14px 16px;margin:20px 0">'
                    . '<strong>To go ahead, reply to this email with your purchase order attached.</strong><br>'
                    . '<span style="color:#6b7280;font-size:13px">Keep the subject line as it is. That is how your '
                    . 'purchase order is matched to this job. We will send the invoice and the delivery note back '
                    . 'as soon as it arrives, and the cards go to print.</span></p>')
              . '</div>';

        $subject = ($ref !== '' ? "[{$ref}] " : '')
                 . (!empty($dept['invoice_without_po']) ? 'Quotation' : 'Purchase order needed')
                 . ": {$name}, {$div}";
        $sent = MhdMailer::sendRaw($to, $cc, $subject, $html, $files);

        // And the division's own WhatsApp group, when it has one (OHB Cards).
        if (!empty($dept['whatsapp_group']) && $files) {
            require_once __DIR__ . '/ProductionWhatsApp.php';
            $sent['whatsapp'] = ProductionWhatsApp::post($req, $dept, $files[0]['path'],
                ProductionWhatsApp::quoteCaption($req, $dept, $price, $num), $files[0]['name']);
        }

        foreach ($files as $f) { @unlink($f['path']); }
        return $sent;
    }

    /**
     * The documents email, sent the moment the purchase order is filed.
     *
     * Quotation, invoice and delivery note, all three as the ERP's own PDFs,
     * which already carry BHD's signature and stamp. Nothing is retyped into
     * the body: the documents are the documents.
     *
     * @param array $erp ['quoteId','invoiceId','invoiceNumber','deliveryId','po']
     */
    public static function sendDocuments(array $req, array $dept, array $erp, ?string $signUrl = null): array
    {
        [$to, $cc] = self::recipients($dept);
        if (!$to) {
            return ['ok' => false, 'error' => 'division has nobody to write to', 'recipients' => []];
        }
        $e    = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
        $name = trim((string)($req['name_en'] ?? '')) ?: trim((string)($req['name_ar'] ?? '')) ?: 'the employee';
        $div  = (string)($dept['name'] ?? 'MHD');
        $ref  = (string)($req['job_ref'] ?? '');
        $po   = trim((string)($erp['po'] ?? ''));
        $inv  = trim((string)($erp['invoiceNumber'] ?? ''));

        $files = [];
        $wanted = [
            ['quote',        (string)($erp['quoteId'] ?? ''),    'Quotation'],
            ['invoice',      (string)($erp['invoiceId'] ?? ''),  'Invoice'],
            ['deliverynote', (string)($erp['deliveryId'] ?? ''), 'Delivery note'],
        ];
        $missing = [];
        foreach ($wanted as [$type, $id, $label]) {
            if ($id === '') { $missing[] = $label; continue; }
            $fileName = $ref !== '' ? $ref . '-' . $type : $type;
            $path = ERPSync::fetchDocumentPdf($type, $id, $fileName);
            if ($path === null) { $missing[] = $label; continue; }
            $files[] = ['path' => $path, 'name' => $fileName . '.pdf', 'label' => strtolower($label)];
        }

        $sign = $signUrl
            ? '<p style="margin:26px 0"><a href="' . $e($signUrl) . '" style="background:#0f4c81;color:#fff;'
              . 'padding:13px 30px;border-radius:6px;text-decoration:none;font-weight:bold;display:inline-block">'
              . 'Sign the delivery note</a></p>'
              . '<p style="color:#6b7280;font-size:13px">One click signs it. There is nothing to print, '
              . 'scan or send back.</p>'
            : '';

        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222;line-height:1.6">'
              . '<p>Your purchase order' . ($po !== '' ? ' <strong>' . $e($po) . '</strong>' : '')
              . ' is received' . ($inv !== '' ? ', and invoice <strong>' . $e($inv) . '</strong> is raised' : '')
              . '. The card for <strong>' . $e($name) . '</strong> (' . $e($div) . ') is now in production.</p>'
              . ($files
                  ? '<p>' . $e(self::listOf(array_map(fn($f) => $f['label'], $files)))
                    . (count($files) === 1 ? ' is attached.' : ' are attached.') . '</p>'
                  : '')
              . ($missing ? '<p style="color:#6b7280;font-size:13px">Following separately: '
                            . $e(self::listOf($missing)) . '.</p>' : '')
              . $sign
              . '</div>';

        $subject = ($ref !== '' ? "[{$ref}] " : '') . "Invoice and delivery note: {$name}, {$div}";
        $sent = MhdMailer::sendRaw($to, $cc, $subject, $html, $files);

        foreach ($files as $f) { @unlink($f['path']); }
        return $sent;
    }

    /**
     * Hand the print-ready artwork to production.
     *
     * Ali asked for WhatsApp first and then chose email, 16 Sep 2026: the
     * production mailbox is where BHD print from today, so the artwork goes
     * there. MHD_PRODUCTION_EMAIL overrides it if production ever move.
     */
    public static function sendToProduction(array $req, array $dept, string $artworkPath): array
    {
        if (!is_file($artworkPath)) {
            return ['ok' => false, 'error' => 'no artwork file', 'recipients' => []];
        }
        $e    = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
        $name = trim((string)($req['name_en'] ?? '')) ?: trim((string)($req['name_ar'] ?? '')) ?: 'employee';
        $div  = (string)($dept['name'] ?? 'MHD');
        $ref  = (string)($req['job_ref'] ?? '');
        $qty  = (int)($req['quantity_ordered'] ?? 0);
        $po   = trim((string)($req['po_number'] ?? ''));

        $rows = '';
        foreach ([
            'Name'     => $req['name_en'] ?? '',
            'Division' => $div,
            'Quantity' => $qty ? $qty . ' cards' : '',
            'Stock'    => 'Art 300 GSM, matte, double sided',
            'PO'       => $po,
        ] as $k => $v) {
            if (trim((string)$v) === '') { continue; }
            $rows .= '<tr><td style="padding:3px 14px 3px 0;color:#6b7280">' . $e($k)
                   . '</td><td style="padding:3px 0"><strong>' . $e($v) . '</strong></td></tr>';
        }

        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222;line-height:1.6">'
              . '<p>Print ready. The artwork is attached on A4, both sides at exact size, with crop marks.</p>'
              . '<table style="border-collapse:collapse;margin:14px 0">' . $rows . '</table>'
              . '</div>';

        $file = [['path' => $artworkPath,
                  'name' => ($ref !== '' ? $ref : 'card') . '-print-ready-A4.pdf']];
        $subject = ($ref !== '' ? "[{$ref}] " : '') . "Print ready: {$name}, {$div}"
                 . ($qty ? ", {$qty} cards" : '');
        return MhdMailer::sendRaw([self::productionMailbox()], [], $subject, $html, $file);
    }

    /**
     * The country code printed in front of a bare mobile number.
     *
     * The portal stores digits only, because the card art bakes the prefix.
     * Consumer is the Bahrain entity and its card reads +973, so a hardcoded
     * +968 showed the approver a number that is not on the card they are
     * approving. Read it from the division's own entity name.
     */
    private static function dialCode(array $dept): string
    {
        $haystack = strtolower(($dept['name'] ?? '') . ' ' . ($dept['erp_client_name'] ?? ''));
        return strpos($haystack, 'bahrain') !== false || strpos($haystack, 'w.l.l') !== false
            ? '+973' : '+968';
    }

    /** "a, b and c", so the email names exactly what it carries. */
    private static function listOf(array $items): string
    {
        $items = array_values(array_filter($items));
        if (count($items) <= 1) { return ucfirst((string)($items[0] ?? '')); }
        $last = array_pop($items);
        return ucfirst(implode(', ', $items) . ' and ' . $last);
    }

    private static function productionMailbox(): string
    {
        return defined('MHD_PRODUCTION_EMAIL') ? MHD_PRODUCTION_EMAIL : self::BHD_OWNER;
    }

    /**
     * Sent when a purchase order lands but the invoice cannot be raised yet.
     *
     * Found on 17 Sep 2026: three MHD accounts are credit blocked in the ERP,
     * and the ERP refuses to convert a quote for a blocked client. The purchase
     * order was filed correctly and then nothing was said to anyone, which is
     * the one outcome a person cannot act on.
     *
     * The division is told their purchase order arrived. BHD gets its own
     * message with the reason, because the reason is ours, not theirs.
     */
    public static function sendPoAcknowledgement(array $req, array $dept, string $po, string $reason): array
    {
        [$to, $cc] = self::recipients($dept);
        if (!$to) {
            return ['ok' => false, 'error' => 'division has nobody to write to', 'recipients' => []];
        }
        $e    = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
        $name = trim((string)($req['name_en'] ?? '')) ?: trim((string)($req['name_ar'] ?? '')) ?: 'the employee';
        $div  = (string)($dept['name'] ?? 'MHD');
        $ref  = (string)($req['job_ref'] ?? '');

        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222;line-height:1.6">'
              . '<p>Thank you, your purchase order' . ($po !== '' ? ' <strong>' . $e($po) . '</strong>' : '')
              . ' is received for <strong>' . $e($name) . '</strong> (' . $e($div) . ').</p>'
              . '<p>The invoice and the delivery note follow shortly.</p>'
              . '</div>';
        $sent = MhdMailer::sendRaw($to, $cc,
            ($ref !== '' ? "[{$ref}] " : '') . "Purchase order received: {$name}, {$div}", $html);

        // The internal one. Same job ref, so it threads with the rest.
        $internal = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222;line-height:1.6">'
                  . '<p>A purchase order arrived and the ERP would not raise the invoice.</p>'
                  . '<table style="border-collapse:collapse;margin:12px 0">'
                  . '<tr><td style="padding:3px 14px 3px 0;color:#6b7280">Job</td><td style="padding:3px 0"><strong>' . $e($ref) . '</strong></td></tr>'
                  . '<tr><td style="padding:3px 14px 3px 0;color:#6b7280">Division</td><td style="padding:3px 0"><strong>' . $e($div) . '</strong></td></tr>'
                  . '<tr><td style="padding:3px 14px 3px 0;color:#6b7280">Purchase order</td><td style="padding:3px 0"><strong>' . $e($po) . '</strong></td></tr>'
                  . '<tr><td style="padding:3px 14px 3px 0;color:#6b7280">Reason</td><td style="padding:3px 0"><strong>' . $e($reason) . '</strong></td></tr>'
                  . '</table>'
                  . '<p style="color:#6b7280;font-size:13px">The job is holding at po_received. It retries by itself '
                  . 'every quarter of an hour, so clearing the cause is all that is needed.</p>'
                  . '</div>';
        MhdMailer::sendRaw([self::BHD_OWNER], [],
            ($ref !== '' ? "[{$ref}] " : '') . "Invoice held: {$div}, {$reason}", $internal);

        return $sent;
    }

    /**
     * Sent to BHD when a card is approved but the ERP would not raise the
     * quotation. Nothing goes to the division: a quotation they cannot act on
     * is worse than waiting, and the job heals itself every quarter of an hour.
     */
    public static function sendQuoteHeldAlert(array $req, array $dept, string $reason): array
    {
        $e    = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
        $name = trim((string)($req['name_en'] ?? '')) ?: trim((string)($req['name_ar'] ?? '')) ?: 'an employee';
        $div  = (string)($dept['name'] ?? 'MHD');
        $ref  = (string)($req['job_ref'] ?? '');

        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222;line-height:1.6">'
              . '<p>A card was approved and the ERP would not raise the quotation, so the division has '
              . 'not been asked for a purchase order.</p>'
              . '<table style="border-collapse:collapse;margin:12px 0">'
              . '<tr><td style="padding:3px 14px 3px 0;color:#6b7280">Job</td><td style="padding:3px 0"><strong>' . $e($ref) . '</strong></td></tr>'
              . '<tr><td style="padding:3px 14px 3px 0;color:#6b7280">Employee</td><td style="padding:3px 0"><strong>' . $e($name) . '</strong></td></tr>'
              . '<tr><td style="padding:3px 14px 3px 0;color:#6b7280">Division</td><td style="padding:3px 0"><strong>' . $e($div) . '</strong></td></tr>'
              . '<tr><td style="padding:3px 14px 3px 0;color:#6b7280">Reason</td><td style="padding:3px 0"><strong>' . $e($reason) . '</strong></td></tr>'
              . '</table>'
              . '<p style="color:#6b7280;font-size:13px">The job is holding at approved and retries by itself '
              . 'every quarter of an hour. The quotation goes out on its own as soon as the cause is cleared.</p>'
              . '</div>';

        return MhdMailer::sendRaw([self::BHD_OWNER], [],
            ($ref !== '' ? "[{$ref}] " : '') . "Quotation held: {$div}, {$reason}", $html);
    }

}
