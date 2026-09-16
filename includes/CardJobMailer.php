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
     * The approval email. One link, to the prefetch-safe interstitial that
     * offers Approve and Reject, rather than two links: a plain reject link
     * would be followed by a scanner.
     *
     * @return array ['ok'=>bool, 'error'=>?string, 'recipients'=>array]
     */
    public static function sendForApproval(array $req, array $dept, string $token, array $designs = []): array
    {
        $to = trim((string)($dept['responsible_email'] ?? ''));
        if ($to === '') {
            return ['ok' => false, 'error' => 'department has no approver', 'recipients' => []];
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
            if ($path !== '' && is_file($path)) {
                $files[] = ['path' => $path,
                            'name' => ($ref !== '' ? $ref . '-' : '') . $side . '.png'];
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
            'Mobile'   => ($req['mobile'] ?? '') !== '' ? '+968 ' . $req['mobile'] : '',
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
              . '</div>';

        $cc = array_values(array_filter([
            trim((string)($dept['head_email'] ?? '')),
            self::BHD_OWNER,
        ]));

        $subject = ($ref !== '' ? "[{$ref}] " : '') . "Business card approval: {$name}, {$div}";
        return MhdMailer::sendRaw([$to], $cc, $subject, $html, $files);
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
        $to = trim((string)($dept['responsible_email'] ?? ''));
        if ($to === '') {
            return ['ok' => false, 'error' => 'department has no approver', 'recipients' => []];
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

        // Only when the PDF could not be fetched. Otherwise the attachment is
        // the quotation and the body stays short.
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
            ? 'Our quotation' . ($num !== '' ? ' <strong>' . $e($num) . '</strong>' : '') . ' is attached.'
            : 'Our quotation' . ($num !== '' ? ' <strong>' . $e($num) . '</strong>' : '') . ' is below.';

        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222;line-height:1.6">'
              . '<p>Thank you. The card for <strong>' . $e($name) . '</strong> is approved for <strong>'
              . $e($div) . '</strong>. ' . $line . '</p>'
              . $figures
              . '<p style="background:#f1f5f9;border-radius:8px;padding:14px 16px;margin:20px 0">'
              . '<strong>To go ahead, reply to this email with your purchase order attached.</strong><br>'
              . '<span style="color:#6b7280;font-size:13px">Keep the subject line as it is. That is how your '
              . 'purchase order is matched to this job. We will send the invoice and the delivery note back '
              . 'as soon as it arrives, and the cards go to print.</span></p>'
              . '</div>';

        $cc = array_values(array_filter([
            trim((string)($dept['head_email'] ?? '')),
            self::BHD_OWNER,
        ]));
        $subject = ($ref !== '' ? "[{$ref}] " : '') . "Quotation for {$name}, {$div}";
        $sent = MhdMailer::sendRaw([$to], $cc, $subject, $html, $files);

        foreach ($files as $f) { @unlink($f['path']); }
        return $sent;
    }

}
