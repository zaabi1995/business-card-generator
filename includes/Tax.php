<?php
/**
 * Cardify tax helper (Cat S action 445).
 *
 * Oman VAT is 5%. Prices on Cardify are quoted tax-INCLUSIVE to the
 * customer (what you see is what you pay), so the breakdown has to be
 * extracted from the total on the invoice. Use Tax::breakdown($total)
 * for a new order, and Tax::breakdownFromOrder($row) when reading a
 * stored order that may or may not have tax_amount persisted.
 */
class Tax
{
    /** Oman standard VAT rate as a decimal. */
    public const OMAN_VAT = 0.050;

    /**
     * Given a tax-inclusive total, return (subtotal_excl_vat, tax_amount)
     * rounded to 3 decimals (OMR baisa).
     */
    public static function breakdown(float $total, float $rate = self::OMAN_VAT): array
    {
        if ($total <= 0) return ['subtotal' => 0.000, 'tax' => 0.000, 'rate' => $rate];
        $subtotal = round($total / (1 + $rate), 3);
        $tax      = round($total - $subtotal, 3);
        return ['subtotal' => $subtotal, 'tax' => $tax, 'rate' => $rate];
    }

    /**
     * Pull VAT breakdown for an existing order row. Returns null when
     * the order pre-dates migration 089 AND has no persisted tax info,
     * so callers know to hide the tax line instead of showing a fake
     * 0.000.
     */
    public static function breakdownFromOrder(array $order): ?array
    {
        if (isset($order['tax_amount']) && $order['tax_amount'] !== null) {
            return [
                'subtotal' => (float) ($order['subtotal_excl_vat'] ?? 0),
                'tax'      => (float) $order['tax_amount'],
                'rate'     => (float) ($order['tax_rate'] ?? self::OMAN_VAT),
            ];
        }
        return null;
    }

    /**
     * When creating or updating an order, compute + persist the
     * breakdown columns. Safe no-op if the columns don't exist yet
     * (pre-migration-089 installs).
     */
    public static function persistOnOrder(int $orderId, float $total, float $rate = self::OMAN_VAT): void
    {
        try {
            $b = self::breakdown($total, $rate);
            $db = Database::getInstance();
            $db->exec(
                "UPDATE print_orders SET tax_rate = :r, tax_amount = :t, subtotal_excl_vat = :s WHERE id = :id",
                ['r' => $rate, 't' => $b['tax'], 's' => $b['subtotal'], 'id' => $orderId]
            );
        } catch (Throwable $_) { /* columns may be missing on very old installs */ }
    }
}
