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
     * The per-card price a given shop charges at a given quantity.
     *
     * A shop stores its rates in one of three shapes, and the shop chooser read
     * only the simplest: a scalar `per_card`. BHD prices in `quantity_tiers`
     * instead, so the chooser fell through to a generic fallback and advertised
     * 0.100 beside a review panel charging BHD's real 0.045. Resolve the shop's
     * own ladder first, and use the shared tiers only when the shop has
     * published nothing at all.
     *
     * @param array  $pricing   decoded print_shops.pricing
     * @param int    $quantity  cards per design
     * @param string $paperType key into paper_type_pricing, when the shop
     *                          prices per paper
     */
    public static function shopPricePerCard(array $pricing, int $quantity, string $paperType = 'matte'): float
    {
        $tiers = null;

        // Per-paper rates win when the shop publishes them for this paper.
        if (!empty($pricing['paper_type_pricing'][$paperType]['quantity_tiers'])
            && is_array($pricing['paper_type_pricing'][$paperType]['quantity_tiers'])) {
            $tiers = $pricing['paper_type_pricing'][$paperType]['quantity_tiers'];
        } elseif (!empty($pricing['quantity_tiers']) && is_array($pricing['quantity_tiers'])) {
            $tiers = $pricing['quantity_tiers'];
        }

        if ($tiers) {
            ksort($tiers, SORT_NUMERIC);
            $applied = null;
            foreach ($tiers as $minQty => $row) {
                $perCard = is_array($row)
                    ? (isset($row['per_card'])
                        ? (float) $row['per_card']
                        : (((int) $minQty) > 0 ? ((float) ($row['price'] ?? 0)) / (int) $minQty : 0.0))
                    : (float) $row;
                if ($applied === null) { $applied = $perCard; continue; }
                if ($quantity >= (int) $minQty) $applied = $perCard; else break;
            }
            if ($applied !== null) return round($applied, 4);
        }

        if (isset($pricing['per_card'])) return round((float) $pricing['per_card'], 4);

        return self::pricePerCard($quantity);
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
