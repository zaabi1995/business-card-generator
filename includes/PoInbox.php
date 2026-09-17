<?php
require_once __DIR__ . '/CardJob.php';

/**
 * PoInbox, purchase-order intake from the sales@bhdoman.com mailbox.
 *
 * MHD reply to the quotation email with their purchase order as a PDF. The
 * reply is matched to its job by the [MHD-XXXXXX] tag in the subject, because
 * Outlook rewrites threading headers and References cannot be relied on. The
 * purchase-order number is ten digits beginning 41, which is MHD's own format
 * on every purchase order they have ever sent BHD (35 of them, checked).
 *
 * The mailbox is read from its Maildir on this same server, not over IMAP:
 * sales@bhdoman.com is a local Dovecot account, so there is no password to
 * store, and nothing here marks a message read or moves it. The sales team's
 * mailbox is left exactly as they left it.
 *
 * Idempotent on the message key: every message examined is recorded in
 * mhd_po_seen, so a replay files nothing twice.
 */
class PoInbox
{
    private const REF_RE = '/\[(MHD-[A-Z0-9]{6})\]/i';

    /**
     * The purchase-order formats MHD actually use, strictest first.
     * 41 plus eight digits is the standard one, on 35 of the purchase orders on
     * file. MHD Logistics number theirs MHDL-PO/255. A few divisions send other
     * lengths (480120014 on INV-4213), which are only taken when the number is
     * labelled as a purchase order, so a phone number is never mistaken for one.
     */
    private const PO_PATTERNS = [
        '/(?<!\d)(41\d{8})(?!\d)/',
        '/\b(MHDL-PO\/\d{1,6})\b/i',
        '/\b(?:P\.?O\.?|purchase\s+order)(?:\s*(?:no\.?|number|#|:))?[\s.:#-]{0,4}([0-9]{8,12})(?!\d)/i',
    ];

    /** The Maildir of the mailbox that receives the replies. */
    public static function maildir(): string
    {
        return defined('MHD_PO_MAILDIR') ? MHD_PO_MAILDIR : '/www/vmail/bhdoman.com/sales';
    }

    /** Purchase orders are MHD's commercial documents, so they are kept in
     *  private/, which nginx refuses to serve. Not outside the web root:
     *  PHP-FPM runs with open_basedir confined to the site directory, so a path
     *  above it cannot be written from a page at all. */
    public static function poDir(): string
    {
        return defined('MHD_PO_DIR') ? MHD_PO_DIR : BASE_DIR . '/private/po';
    }

    public static function extractRef(string $subject): ?string
    {
        return preg_match(self::REF_RE, $subject, $m) ? strtoupper($m[1]) : null;
    }

    public static function extractPoNumber(string $text): ?string
    {
        foreach (self::PO_PATTERNS as $re) {
            if (preg_match($re, $text, $m)) { return $m[1]; }
        }
        return null;
    }

    /**
     * Does this sender belong to the customer whose job this is?
     *
     * Their own division mailbox and head address define the domain, with
     * MHD's two domains as the floor, so a division that writes from a
     * subsidiary address still gets through and a stranger does not.
     */
    public static function isCustomerSender(string $from, array $job): bool
    {
        if (!preg_match('/([A-Z0-9._%+-]+@[A-Z0-9.-]+)/i', $from, $m)) {
            return false;
        }
        $domain = strtolower(substr(strrchr($m[1], '@'), 1));

        $allowed = ['mhd.co.om', 'mhdlogistics.com'];
        try {
            $dept = !empty($job['department_id']) ? Database::getInstance()->fetchOne(
                "SELECT responsible_email, head_email, cc_emails FROM departments WHERE id = :d",
                ['d' => $job['department_id']]) : null;
            foreach (['responsible_email', 'head_email', 'cc_emails'] as $k) {
                foreach (preg_split('/[,;\s]+/', (string)($dept[$k] ?? '')) as $addr) {
                    if (strpos($addr, '@') === false) { continue; }
                    $d = strtolower(substr(strrchr(trim($addr), '@'), 1));
                    if ($d !== '' && !self::isOwnSender('x@' . $d)) { $allowed[] = $d; }
                }
            }
        } catch (Exception $e) {
            error_log('PoInbox::isCustomerSender ' . $e->getMessage());
        }
        foreach (array_unique($allowed) as $d) {
            if ($domain === $d || str_ends_with($domain, '.' . $d)) { return true; }
        }
        return false;
    }

    /** Our own domains. A purchase order never comes from one of these. */
    public static function isOwnSender(string $from): bool
    {
        $own = ['bhdoman.com', 'bhd.om', 'cardify.om'];
        if (!preg_match('/([A-Z0-9._%+-]+@[A-Z0-9.-]+)/i', $from, $m)) {
            return false;
        }
        $domain = strtolower(substr(strrchr($m[1], '@'), 1));
        foreach ($own as $d) {
            if ($domain === $d || str_ends_with($domain, '.' . $d)) { return true; }
        }
        return false;
    }

    /**
     * File one message.
     *
     * @param array $message ['message_key','message_id','subject','from','body',
     *                        'attachments'=>[['name','data']]]
     * @return array ['matched'=>bool,'ref'=>?string,'po'=>?string,'reason'=>?string]
     */
    public static function ingest(array $message): array
    {
        $ref = self::extractRef((string)($message['subject'] ?? ''));
        if (!$ref) {
            return ['matched' => false, 'reason' => 'no job ref in subject'];
        }
        // sales@bhdoman.com is copied on every quotation we send, so our own
        // outgoing mail is sitting in this mailbox carrying the same job ref and
        // a PDF. Without this guard the poller would file BHD's own quotation as
        // MHD's purchase order.
        if (self::isOwnSender((string)($message['from'] ?? ''))) {
            return ['matched' => false, 'ref' => $ref, 'reason' => 'sent by us'];
        }
        $job = CardJob::findByRef($ref);
        if (!$job) {
            return ['matched' => false, 'ref' => $ref, 'reason' => 'no such job'];
        }
        // The flow state, not card_requests.status, which only ever holds the
        // approval verdict.
        // 'approved' is accepted as well as 'quoted': the quotation may not have
        // reached the ERP yet, and a purchase order that is already in hand must
        // never be thrown away for being early. Anything further along, or
        // rejected, is genuinely not ours to act on.
        $state = (string)($job['fulfilment_state'] ?? '');
        if (!in_array($state, ['quoted', 'approved'], true)) {
            return ['matched' => false, 'ref' => $ref,
                    'reason' => "job is {$state}, not awaiting a purchase order",
                    'transient' => $state === 'submitted'];
        }

        // The sender must belong to the customer. Refusing only our own domains
        // left the door open to anyone who learned a job ref, which is printed
        // in the subject of every quotation we send: one email from any address
        // would have raised a real invoice on MHD's account and released the
        // artwork to production.
        if (!self::isCustomerSender((string)($message['from'] ?? ''), $job)) {
            return ['matched' => false, 'ref' => $ref,
                    'reason' => 'sender is not this customer', 'transient' => false];
        }

        $pdf = null;
        foreach (($message['attachments'] ?? []) as $a) {
            if (preg_match('/\.pdf$/i', (string)($a['name'] ?? ''))) { $pdf = $a; break; }
        }
        if (!$pdf) {
            return ['matched' => false, 'ref' => $ref, 'reason' => 'no pdf attached'];
        }

        $dir = self::poDir() . '/' . date('Y/m');
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return ['matched' => false, 'ref' => $ref, 'reason' => 'cannot create ' . $dir];
        }
        $path = $dir . '/' . $ref . '-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $pdf['name']);
        if (file_put_contents($path, $pdf['data']) === false) {
            return ['matched' => false, 'ref' => $ref, 'reason' => 'cannot write ' . $path];
        }
        @chmod($path, 0640);
        self::ownedByTheWebUser($dir, $path);

        // The number is on the document itself more often than in the covering
        // note, so read the PDF's own text last rather than give up.
        $po = self::extractPoNumber($pdf['name'] . ' ' . (string)($message['subject'] ?? '')
                                    . ' ' . (string)($message['body'] ?? ''));
        if (!$po) {
            $po = self::extractPoNumber(self::pdfText($path));
        }
        // A PDF with no purchase-order number anywhere in it is not a purchase
        // order. Filing one used to raise a real invoice off any attachment.
        if (!$po) {
            @unlink($path);
            return ['matched' => false, 'ref' => $ref,
                    'reason' => 'no purchase-order number in the reply or the pdf',
                    'transient' => false];
        }

        $db = Database::getInstance();
        $db->query("UPDATE card_requests SET po_number = ?, po_file = ? WHERE id = ?",
                   [$po, $path, $job['id']]);
        $moved = CardJob::transition($job['id'], 'po_received', [
            'actor'      => $message['from'] ?? null,
            'po'         => $po,
            'message_id' => $message['message_id'] ?? null,
            'file'       => basename($path),
        ]);

        if (!$moved) {
            return ['matched' => false, 'ref' => $ref, 'po' => $po,
                    'reason' => 'job moved on before this reply was filed'];
        }

        // The purchase order is filed and the job has moved. Everything after
        // this point is best effort: a slow ERP must never cost us the PO.
        $after = [];
        try {
            require_once __DIR__ . '/CardFulfilment.php';
            $fresh = Database::getInstance()->fetchOne(
                "SELECT * FROM card_requests WHERE id = :id", ['id' => $job['id']]);
            $after = CardFulfilment::afterPo($fresh ?: $job);
        } catch (Throwable $e) {
            error_log('[mhd afterPo] ' . $e->getMessage());
            $after = ['errors' => [$e->getMessage()]];
        }

        return ['matched' => true, 'ref' => $ref, 'po' => $po,
                'invoice' => $after['invoice'] ?? null,
                'state'   => $after['state'] ?? 'po_received',
                'errors'  => $after['errors'] ?? []];
    }

    /**
     * Read the mailbox and file every purchase-order reply found.
     *
     * @param  int $limit    messages to examine in one run
     * @param  int $sinceDays how far back to look on a first run
     * @return array one result row per message examined
     */
    public static function poll(int $limit = 50, int $sinceDays = 3): array
    {
        $root = self::maildir();
        $files = [];
        foreach (['new', 'cur'] as $box) {
            foreach (glob($root . '/' . $box . '/*') ?: [] as $f) {
                if (is_file($f) && filemtime($f) >= time() - ($sinceDays * 86400)) {
                    $files[$f] = filemtime($f);
                }
            }
        }
        asort($files);

        $out = [];
        foreach (array_keys($files) as $file) {
            if (count($out) >= $limit) { break; }
            // The Maildir flag suffix changes when a human reads the message,
            // so the key is the part before it: the same message keeps one key.
            $key = preg_replace('/:2,.*$/', '', basename($file));
            if (self::alreadySeen($key)) { continue; }

            $msg = self::parse($file);
            if ($msg === null) {
                self::remember($key, '', null, 'unparseable');
                $out[] = ['matched' => false, 'reason' => 'unparseable', 'file' => basename($file)];
                continue;
            }
            $msg['message_key'] = $key;
            // Claim the message before the slow part. ingest() can run for a
            // minute on a real purchase order (an ERP convert, three document
            // fetches, a render and an SMTP send), and the poller fires every
            // two minutes, so without the claim a second run could file the
            // same reply again. INSERT IGNORE means exactly one run wins.
            if (!self::claim($key, (string)$msg['subject'])) { continue; }
            $r = self::ingest($msg);
            // A reply refused for a reason that can change, a job that has not
            // been quoted yet, is left unrecorded so the next run looks at it
            // again. Recording it was how a real purchase order could be
            // discarded for ever on the strength of one early glance.
            if (empty($r['transient'])) {
                self::remember($key, (string)$msg['subject'], $r['ref'] ?? null,
                               $r['matched'] ? 'filed' : ($r['reason'] ?? 'skipped'));
            } else {
                // Nothing durable happened and the reason can change, so let
                // the next run look at this reply again.
                self::forget($key);
            }
            // Only the ones that concern a job are worth reporting.
            if (!empty($r['ref'])) {
                $out[] = $r + ['subject' => $msg['subject']];
            }
        }
        return $out;
    }

    // ---- internals -------------------------------------------------

    /** Parse one message file with the Python helper. */
    private static function parse(string $file): ?array
    {
        $script = BASE_DIR . '/scripts/mhd/parse-mail.py';
        if (!is_file($script)) { return null; }
        $cmd = 'python3 ' . escapeshellarg($script) . ' ' . escapeshellarg($file) . ' 2>/dev/null';
        $json = shell_exec($cmd);
        $data = json_decode((string)$json, true);
        if (!is_array($data)) { return null; }
        foreach ($data['attachments'] ?? [] as $i => $a) {
            $data['attachments'][$i]['data'] = base64_decode((string)($a['b64'] ?? ''), true) ?: '';
            unset($data['attachments'][$i]['b64']);
        }
        return $data;
    }

    /**
     * The poller runs as root from cron, so what it writes lands root:root and
     * the console, which runs as www, could not read it back. Hand it over.
     */
    private static function ownedByTheWebUser(string ...$paths): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return;
        }
        foreach ($paths as $p) {
            @chown($p, 'www');
            @chgrp($p, 'www');
        }
    }

    private static function pdfText(string $path): string
    {
        $out = shell_exec('pdftotext -q ' . escapeshellarg($path) . ' - 2>/dev/null');
        return (string)$out;
    }

    private static function alreadySeen(string $key): bool
    {
        return (bool)Database::getInstance()->fetchOne(
            "SELECT message_key FROM mhd_po_seen
              WHERE message_key = :k
                AND NOT (outcome = 'reading' AND created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE))",
            ['k' => $key]);
    }

    /** Take this message, if no other run has. */
    private static function claim(string $key, string $subject): bool
    {
        try {
            $db = Database::getInstance();
            // A run that died mid message would otherwise hold its claim for
            // ever and the purchase order would never be read again.
            $db->query("DELETE FROM mhd_po_seen
                         WHERE message_key = ? AND outcome = 'reading'
                           AND created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)", [$key]);
            $stmt = $db->query(
                "INSERT IGNORE INTO mhd_po_seen (message_key, subject, outcome)
                 VALUES (?, ?, 'reading')",
                [$key, mb_substr($subject, 0, 255)]);
            // One row inserted means this run took the message; zero means
            // another run already has it, or it was examined on an earlier run.
            return $stmt->rowCount() === 1;
        } catch (Exception $e) {
            error_log('PoInbox::claim ' . $e->getMessage());
            return true;   // never let bookkeeping stop a purchase order
        }
    }

    /** Release a claim, so the next run reads the message again. */
    private static function forget(string $key): void
    {
        try {
            Database::getInstance()->query(
                "DELETE FROM mhd_po_seen WHERE message_key = ? AND outcome IN ('reading','')", [$key]);
        } catch (Exception $e) {
            error_log('PoInbox::forget ' . $e->getMessage());
        }
    }

    private static function remember(string $key, string $subject, ?string $ref, string $outcome): void
    {
        try {
            Database::getInstance()->query(
                "INSERT INTO mhd_po_seen (message_key, subject, job_ref, outcome)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE job_ref = VALUES(job_ref), outcome = VALUES(outcome)",
                [$key, mb_substr($subject, 0, 255), $ref, mb_substr($outcome, 0, 190)]);
        } catch (Exception $e) {
            error_log('PoInbox::remember ' . $e->getMessage());
        }
    }
}
