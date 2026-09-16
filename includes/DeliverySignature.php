<?php
require_once __DIR__ . '/CardJob.php';
require_once __DIR__ . '/ERPSync.php';
require_once __DIR__ . '/MhdMailer.php';
require_once __DIR__ . '/AdminApprovalToken.php';

/**
 * DeliverySignature, the one-click acceptance of a delivery note.
 *
 * Ali, 16 Sep 2026: "our signature is on each DN by default and we just send
 * them and they click a button to sign it auto, make it simple, one click,
 * nothing more." So BHD's signature and stamp are already on the document when
 * the ERP renders it; this adds the customer's side and nothing else. No form,
 * no upload, no printing and scanning.
 *
 * The signed copy goes back to the division and to BHD, and the acceptance is
 * recorded against the job with who clicked, when and from where.
 */
class DeliverySignature
{
    public const PURPOSE = 'delivery_note';

    /** The link that goes in the documents email. */
    public static function mintLink(array $job, string $adminEmail): ?string
    {
        $requestId = (string)($job['id'] ?? '');
        $companyId = (string)($job['company_id'] ?? '');
        if ($requestId === '' || $companyId === '' || $adminEmail === '') {
            return null;
        }
        $token = AdminApprovalToken::mint($companyId, $requestId, $adminEmail, self::PURPOSE);
        $slug  = (string)($job['company_slug'] ?? 'mhd');
        return getTenantUrl($slug, '/admin/sign-delivery?t=' . urlencode($token));
    }

    /** Where the signed copies are kept: outside the web root, like the POs. */
    public static function dir(): string
    {
        return defined('MHD_DN_DIR') ? MHD_DN_DIR : dirname(BASE_DIR) . '/cardify-private/dn';
    }

    /**
     * Sign the delivery note of one job.
     *
     * @return array ['ok'=>bool,'error'=>?string,'path'=>?string,'already'=>bool]
     */
    public static function sign(array $job, string $signerEmail, string $ip = ''): array
    {
        $db   = Database::getInstance();
        $dept = !empty($job['department_id']) ? $db->fetchOne(
            "SELECT * FROM departments WHERE id = :d", ['d' => $job['department_id']]) : null;
        $order = !empty($job['erp_order_id']) ? $db->fetchOne(
            "SELECT delivery_note_external_id FROM print_orders WHERE id = :id",
            ['id' => $job['erp_order_id']]) : null;
        $dnId = (string)($order['delivery_note_external_id'] ?? '');
        if ($dnId === '') {
            return ['ok' => false, 'error' => 'this job has no delivery note yet', 'already' => false];
        }

        $ref  = (string)($job['job_ref'] ?? '');
        $dir  = self::dir() . '/' . date('Y/m');
        $dest = $dir . '/' . ($ref !== '' ? $ref : $job['id']) . '-delivery-note-signed.pdf';
        if (is_file($dest)) {
            return ['ok' => true, 'error' => null, 'path' => $dest, 'already' => true];
        }

        $src = ERPSync::fetchDocumentPdf('deliverynote', $dnId, ($ref ?: 'dn') . '-unsigned');
        if ($src === null) {
            return ['ok' => false, 'error' => 'the delivery note could not be fetched', 'already' => false];
        }
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            @unlink($src);
            return ['ok' => false, 'error' => 'cannot create ' . $dir, 'already' => false];
        }

        $meta = [
            'name'  => trim((string)($dept['name'] ?? '')),
            'email' => $signerEmail,
            'when'  => date('d/m/Y H:i') . ' GMT+4',
            'ip'    => $ip,
            'ref'   => $ref,
            'po'    => (string)($job['po_number'] ?? ''),
        ];
        $metaFile = sys_get_temp_dir() . '/dnsign-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($metaFile, json_encode($meta, JSON_UNESCAPED_UNICODE));

        $script = BASE_DIR . '/scripts/mhd/stamp-signature.py';
        shell_exec('python3 ' . escapeshellarg($script) . ' ' . escapeshellarg($src) . ' '
                   . escapeshellarg($dest) . ' ' . escapeshellarg($metaFile) . ' 2>/dev/null');
        @unlink($metaFile);
        @unlink($src);
        if (!is_file($dest)) {
            return ['ok' => false, 'error' => 'the signature could not be applied', 'already' => false];
        }
        @chmod($dest, 0640);

        CardJob::note((string)$job['id'], 'dn_signed', [
            'actor' => $signerEmail, 'ip' => $ip, 'delivery_note' => $dnId,
            'file'  => basename($dest), 'when' => date('c'),
        ]);
        self::email($job, $dept ?: [], $dest, $signerEmail);

        return ['ok' => true, 'error' => null, 'path' => $dest, 'already' => false];
    }

    /** Send the signed copy back to the division, with BHD copied. */
    private static function email(array $job, array $dept, string $path, string $signerEmail): void
    {
        $to = trim((string)($dept['responsible_email'] ?? '')) ?: $signerEmail;
        $e  = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
        $ref = (string)($job['job_ref'] ?? '');
        $name = trim((string)($job['name_en'] ?? '')) ?: 'the employee';

        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222;line-height:1.6">'
              . '<p>The delivery note for <strong>' . $e($name) . '</strong> is signed. '
              . 'The signed copy is attached for your records.</p>'
              . '<p style="color:#6b7280;font-size:13px">Signed by ' . $e($signerEmail)
              . ' on ' . $e(date('d/m/Y H:i')) . ' GMT+4.</p>'
              . '</div>';

        $cc = array_values(array_filter([
            trim((string)($dept['head_email'] ?? '')),
            'sales@bhdoman.com',
        ]));
        MhdMailer::sendRaw([$to], $cc,
            ($ref !== '' ? "[{$ref}] " : '') . "Signed delivery note: {$name}",
            $html, [['path' => $path, 'name' => ($ref ?: 'delivery-note') . '-signed.pdf']]);
    }
}
