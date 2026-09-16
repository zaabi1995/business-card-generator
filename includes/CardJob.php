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
    public static function mintRef(string $requestId): string
    {
        return 'MHD-' . strtoupper(substr(hash('sha256', $requestId), 0, 6));
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
     * Move a job forward. Returns false and writes nothing when the move is not
     * allowed, or when the row has already moved on because another worker won.
     */
    public static function transition(string $requestId, string $to, array $evidence = []): bool
    {
        if (!in_array($to, self::STATES, true)) {
            return false;
        }
        $db  = Database::getInstance();
        $row = $db->fetchOne(
            "SELECT id, company_id, status FROM card_requests WHERE id = :id", ['id' => $requestId]);
        if (!$row) {
            return false;
        }
        $from = (string)$row['status'];
        if (!self::canTransition($from, $to)) {
            return false;
        }

        $stmt = $db->getConnection()->prepare(
            "UPDATE card_requests SET status = :to WHERE id = :id AND status = :from");
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
