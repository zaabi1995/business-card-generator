<?php
/**
 * CardJob, the MHD card fulfilment state machine.
 *
 * A card request moves through exactly the states below and only forwards. Every
 * move writes a row to card_request_events carrying who did it and the proof
 * (the token used, the PO number, the ERP document id), so the console can show
 * a history and nothing can advance without leaving a trace.
 *
 * transition() guards and writes in one statement, so two workers racing the
 * same purchase-order reply cannot both move the job on.
 */
class CardJob
{
    public const STATES = [
        'submitted', 'approved', 'quoted', 'po_received',
        'in_production', 'dispatched', 'delivered', 'rejected',
    ];

    /** The column the flow lives in. card_requests.status stays the approval
     *  verdict (pending / approved / rejected), which admin/requests.php filters
     *  and counts on, and which is an ENUM that cannot hold these states. */
    private const COL = 'fulfilment_state';

    /** The only moves allowed. Anything absent here is refused. */
    private const NEXT = [
        'submitted'     => ['approved', 'rejected'],
        'approved'      => ['quoted'],
        'quoted'        => ['po_received'],
        'po_received'   => ['in_production'],
        'in_production' => ['dispatched'],
        'dispatched'    => ['delivered'],
        'delivered'     => [],
        'rejected'      => [],
    ];

    public static function canTransition(string $from, string $to): bool
    {
        return isset(self::NEXT[$from]) && in_array($to, self::NEXT[$from], true);
    }

    /**
     * A short, stable tag for the email subject. It is derived from the request
     * id, so the same job always produces the same ref and a reply can be matched
     * back to it without relying on threading headers, which Outlook rewrites.
     */
    public static function mintRef(string $requestId, string $prefix = 'MHD'): string
    {
        // The tenant's own prefix (OHB-...), so a client never reads another
        // client's name in its email subjects. Letters only, 2 to 6 of them.
        $prefix = strtoupper(preg_replace('/[^A-Za-z]/', '', $prefix));
        if (strlen($prefix) < 2 || strlen($prefix) > 6) { $prefix = 'MHD'; }
        return $prefix . '-' . strtoupper(substr(hash('sha256', $requestId), 0, 6));
    }

    public static function findByRef(string $ref): ?array
    {
        $row = Database::getInstance()->fetchOne(
            "SELECT * FROM card_requests WHERE job_ref = :r LIMIT 1", ['r' => $ref]);
        return $row ?: null;
    }

    public static function events(string $requestId): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT * FROM card_request_events WHERE request_id = :r ORDER BY created_at ASC, id ASC",
            ['r' => $requestId]);
    }

    /**
     * Record something that happened to a job without moving it on: a signed
     * delivery note, a document re-sent, a note from the console. The event is
     * written with to_state = 'note:<kind>', so a reader filtering on STATES
     * sees only real transitions and the history still carries the proof.
     */
    public static function note(string $requestId, string $kind, array $evidence = []): bool
    {
        $db  = Database::getInstance();
        $row = $db->fetchOne(
            "SELECT id, company_id, " . self::COL . " AS state FROM card_requests WHERE id = :id",
            ['id' => $requestId]);
        if (!$row) {
            return false;
        }
        $db->insert('card_request_events', [
            'id'         => function_exists('generateUUID') ? generateUUID() : bin2hex(random_bytes(16)),
            'request_id' => $requestId,
            'company_id' => $row['company_id'],
            'from_state' => $row['state'],
            'to_state'   => 'note:' . $kind,
            'actor'      => $evidence['actor'] ?? null,
            'evidence'   => json_encode($evidence, JSON_UNESCAPED_UNICODE),
        ]);
        return true;
    }

    /**
     * Move a job forward. Returns false and writes nothing when the move is not
     * allowed, or when the row has already moved on because another worker won.
     */
    /**
     * Hold one job for the length of a slow step (an ERP call, a render, an
     * email). The PO poller runs every two minutes and the heal cron every
     * fifteen, and both could work the same job at once: two production emails,
     * two quotations. A MariaDB named lock is released by the server if the
     * process dies, so a crash never leaves a job held.
     */
    public static function lock(string $requestId): bool
    {
        $row = Database::getInstance()->fetchOne(
            "SELECT GET_LOCK(:n, 0) AS l", ['n' => 'cardify-mhd-job-' . $requestId]);
        return (int)($row['l'] ?? 0) === 1;
    }

    public static function unlock(string $requestId): void
    {
        Database::getInstance()->fetchOne(
            "SELECT RELEASE_LOCK(:n) AS r", ['n' => 'cardify-mhd-job-' . $requestId]);
    }

    /**
     * Put back the employee record a rejected request overwrote. The portal
     * writes the requested details onto the employee before approval (the card
     * is rendered from that row), so without this a rejected update left the
     * person's card showing the rejected text, and offline.
     */
    public static function restoreEmployee(string $requestId): void
    {
        try {
            $db  = Database::getInstance();
            $req = $db->fetchOne("SELECT company_id, employee_snapshot FROM card_requests WHERE id = :id",
                                 ['id' => $requestId]);
            $snap = json_decode((string)($req['employee_snapshot'] ?? ''), true);
            if (!is_array($snap) || empty($snap['id'])) {
                return;
            }
            $id = (string)$snap['id'];
            unset($snap['id'], $snap['company_id']);
            if ($snap) {
                $db->update('employees', $snap, 'id = :eid AND company_id = :ecid',
                            ['eid' => $id, 'ecid' => $req['company_id']]);
            }
        } catch (Throwable $e) {
            error_log('[CardJob restoreEmployee] ' . $e->getMessage());
        }
    }

    public static function transition(string $requestId, string $to, array $evidence = []): bool
    {
        if (!in_array($to, self::STATES, true)) {
            return false;
        }
        $db  = Database::getInstance();
        $row = $db->fetchOne(
            "SELECT id, company_id, " . self::COL . " AS state FROM card_requests WHERE id = :id",
            ['id' => $requestId]);
        if (!$row) {
            return false;
        }
        $from = (string)$row['state'];
        if (!self::canTransition($from, $to)) {
            return false;
        }

        $stmt = $db->getConnection()->prepare(
            "UPDATE card_requests SET " . self::COL . " = :to
              WHERE id = :id AND " . self::COL . " = :from");
        $stmt->execute([':to' => $to, ':id' => $requestId, ':from' => $from]);
        if ($stmt->rowCount() === 0) {
            return false;   // someone else advanced it first
        }

        $db->insert('card_request_events', [
            'id'         => function_exists('generateUUID') ? generateUUID() : bin2hex(random_bytes(16)),
            'request_id' => $requestId,
            'company_id' => $row['company_id'],
            'from_state' => $from,
            'to_state'   => $to,
            'actor'      => $evidence['actor'] ?? null,
            'evidence'   => json_encode($evidence, JSON_UNESCAPED_UNICODE),
        ]);
        return true;
    }
}
