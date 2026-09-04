<?php
/**
 * Static quantity-breakpoint pricing for printed business cards.
 * Used by the onboarding wizard (step 7) and the normal print-order
 * flow to keep the per-card price consistent across both entry points.
 *
 * Tiers are OMR per card, assuming a single-design run. They match the
 * internal cost curve; the print shop flow ultimately overrides with
 * the shop's own configured price when an order goes through.
 */
class CardPrintPricing
{
    // [min_qty => price_per_card] in ascending order. First tier is the
    // minimum order quantity accepted by the print-order form (50).
    public const TIERS = [
        50   => 0.120,
        100  => 0.100,
        250  => 0.090,
        500  => 0.075,
        1000 => 0.060,
    ];

    public const MIN_QTY = 50;

    /**
     * Return the per-card OMR price for the given quantity. Below
     * MIN_QTY the first tier price is returned (callers should
     * still enforce the minimum elsewhere).
     */
    public static function pricePerCard(int $quantity): float
    {
        // reset() takes its argument by reference and a class constant cannot be
        // passed that way, so this threw "reset(): Argument #1 ($array) could
        // not be passed by reference" on every call under PHP 8. Confirmed on
        // production PHP 8.3.25: pricePerCard() and lineTotal() both fatalled,
        // and only tiersForJs() worked, which is why the one caller (the
        // onboarding wizard, which uses tiersForJs and MIN_QTY) never noticed.
        $tiers = self::TIERS;
        $applied = reset($tiers);
        foreach ($tiers as $minQty => $price) {
            if ($quantity >= $minQty) $applied = $price;
            else break;
        }
        return (float) $applied;
    }

    /**
     * Return the line total (quantity * per-card), rounded to 3dp
     * per Oman Rial convention.
     */
    public static function lineTotal(int $quantity): float
    {
        return round($quantity * self::pricePerCard($quantity), 3);
    }

    /**
     * Return tiers as a JSON-friendly numerically-indexed array so
     * JS can iterate them without Object.keys gymnastics.
     */
    public static function tiersForJs(): array
    {
        $out = [];
        foreach (self::TIERS as $minQty => $price) {
            $out[] = ['min' => (int) $minQty, 'price' => (float) $price];
        }
        return $out;
    }
}
