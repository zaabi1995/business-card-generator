<?php
require_once __DIR__ . '/MhdMailer.php';
require_once __DIR__ . '/CardJob.php';

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
    public static function sendForApproval(array $req, array $dept, string $token, string $previewUrl = ''): array
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

        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
        $preview = $previewUrl !== ''
            ? '<p style="text-align:center;margin:18px 0"><img src="' . $e($previewUrl)
              . '" alt="Card design" style="max-width:340px;width:100%;border-radius:8px;border:1px solid #e5e7eb"></p>'
            : '';

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
              . 'Review and approve</a></p>'
              . '<p style="color:#6b7280;font-size:13px">Approving raises the quotation and emails it to this '
              . 'address. Nothing prints until you send the purchase order. The same page lets you reject.</p>'
              . '<p>Regards,<br>BHD Printing &amp; Designing</p></div>';

        $cc = array_values(array_filter([
            trim((string)($dept['head_email'] ?? '')),
            self::BHD_OWNER,
        ]));

        $subject = ($ref !== '' ? "[{$ref}] " : '') . "Business card approval: {$name}, {$div}";
        return MhdMailer::sendRaw([$to], $cc, $subject, $html);
    }
}
