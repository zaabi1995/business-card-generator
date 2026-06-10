<?php
/**
 * Cardify card-credit accounting (Cat S action 450).
 *
 * Credits live on companies.card_credits and are consumed when a card
 * is GENERATED (per first-generation for a given employee) rather than
 * at print-order time. Every movement is also persisted to
 * card_credit_ledger so we have an auditable ledger; the unique index
 * on (company_id, employee_id, reason) makes the generate-charge
 * idempotent across retries, browser refreshes and employee
 * regenerations.
 */
class CardCredits
{
    /** How many credits a fresh employee-card generation costs. */
    public const GENERATE_COST = 1;

    /**
     * Charge GENERATE_COST for this employee's first card generation.
     * No-op if the ledger already has a 'generate' row for this pair.
     *
     * Returns an array:
     *   ['charged' => bool, 'balance' => int, 'reason' => 'already_charged'|'insufficient'|'ok']
     *
     * Pass $inExternalTxn=true when the CALLER already opened a transaction
     * (e.g. log_generation.php wrapping the generated_cards insert + the
     * charge as one atomic unit). In that mode we do NOT open/commit/rollback
     * our own transaction, and a hard error is RE-THROWN so the caller can
     * roll the whole unit back. A raced duplicate-key is still swallowed as
     * 'already_charged' (InnoDB keeps the surrounding txn usable on a
     * statement-level dup-key error).
     */
    public static function chargeForGenerate(string $companyId, string $employeeId, bool $inExternalTxn = false): array
    {
        $db  = Database::getInstance();
        $pdo = $db->getConnection();
        $ownTxn = !$inExternalTxn;

        try {
            if ($ownTxn) $pdo->beginTransaction();

            // Was this employee already charged? UNIQUE index enforces it but
            // we read upfront so we can return a helpful status without
            // triggering a duplicate-key exception on the hot path.
            $existing = $db->fetchOne(
                "SELECT balance_after FROM card_credit_ledger
                  WHERE company_id = :cid AND employee_id = :eid AND reason = 'generate'
                  LIMIT 1",
                ['cid' => $companyId, 'eid' => $employeeId]
            );
            if ($existing) {
                if ($ownTxn) $pdo->commit();
                $current = self::getBalance($companyId);
                return ['charged' => false, 'balance' => $current, 'reason' => 'already_charged'];
            }

            // Lock the company row + check balance.
            $row = $db->fetchOne(
                "SELECT card_credits FROM companies WHERE id = :id FOR UPDATE",
                ['id' => $companyId]
            );
            $balance = (int) ($row['card_credits'] ?? 0);
            if ($balance < self::GENERATE_COST) {
                if ($ownTxn) $pdo->commit();
                return ['charged' => false, 'balance' => $balance, 'reason' => 'insufficient'];
            }

            $newBalance = $balance - self::GENERATE_COST;
            $db->exec(
                "UPDATE companies SET card_credits = :b WHERE id = :id",
                ['b' => $newBalance, 'id' => $companyId]
            );

            // Ledger row; unique index protects against races.
            $db->exec(
                "INSERT INTO card_credit_ledger
                    (company_id, employee_id, delta, balance_after, reason)
                 VALUES (:c, :e, :d, :b, 'generate')",
                ['c' => $companyId, 'e' => $employeeId, 'd' => -self::GENERATE_COST, 'b' => $newBalance]
            );

            if ($ownTxn) $pdo->commit();
            return ['charged' => true, 'balance' => $newBalance, 'reason' => 'ok'];
        } catch (Throwable $e) {
            if ($ownTxn && $pdo->inTransaction()) $pdo->rollBack();
            // Duplicate key (raced generation) = already charged; treat as success.
            if (strpos($e->getMessage(), '1062') !== false || stripos($e->getMessage(), 'duplicate') !== false) {
                return ['charged' => false, 'balance' => self::getBalance($companyId), 'reason' => 'already_charged'];
            }
            // Caller owns the transaction: re-throw so it can roll back the
            // generated_cards row too (no logged-but-unbilled card).
            if ($inExternalTxn) {
                throw $e;
            }
            error_log('CardCredits::chargeForGenerate failed: ' . $e->getMessage());
            return ['charged' => false, 'balance' => self::getBalance($companyId), 'reason' => 'error'];
        }
    }

    public static function getBalance(string $companyId): int
    {
        try {
            $row = Database::getInstance()->fetchOne(
                "SELECT card_credits FROM companies WHERE id = :id",
                ['id' => $companyId]
            );
            return (int) ($row['card_credits'] ?? 0);
        } catch (Throwable $_) { return 0; }
    }
}
