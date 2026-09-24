<?php
/**
 * CardPrice, the only rate the self-service portal may quote.
 *
 * Art 300 GSM matte, the stock MHD buys on almost every order, at the flat
 * 0.030 per card BHD already bills them. Verified against 365 days of invoices:
 * 62 orders, 9,634 cards, the same unit price throughout.
 *
 * Spot UV, FBB 400 GSM, foil, die cut and embossing are real BHD products but
 * they price differently every time, so they are refused here rather than
 * guessed at. Those stay a phone call to sales.
 */
class CardPrice
{
    /** The lots BHD actually bills. Anything else is quoted by hand. */
    public const QUANTITIES  = [100, 200, 300, 400];
    public const DEFAULT_QTY = 200;
    public const UNIT_PRICE  = 0.030;
    public const VAT_RATE    = 0.05;
    public const DESCRIPTION = 'Business Card (Art 300 GSM, Matte)';

    /**
     * The per-card rate a division pays for a lot. departments.card_price_tiers
     * (JSON {"100":0.040,"200":0.030}) wins: the exact lot, else the largest tier
     * at or below it. Otherwise the division's flat card_unit_price, else 0.030.
     * OHB (Ali, OHB Cards group, 21 Sep 2026): 100 pcs 0.040, 200 pcs 0.030.
     */
    public static function unitFor(array $dept, int $qty): float
    {
        $tiers = json_decode((string)($dept['card_price_tiers'] ?? ''), true);
        if (is_array($tiers) && $tiers) {
            $best = null;
            foreach ($tiers as $lot => $rate) {
                $lot = (int)$lot;
                if ($lot <= $qty && ($best === null || $lot > $best[0])) { $best = [$lot, (float)$rate]; }
            }
            if ($best !== null && $best[1] > 0) { return $best[1]; }
        }
        $flat = (float)($dept['card_unit_price'] ?? 0);
        return $flat > 0 ? $flat : self::UNIT_PRICE;
    }

    public static function isStandardQuantity(int $qty): bool
    {
        return in_array($qty, self::QUANTITIES, true);
    }

    /**
     * @return array{qty:int,unit:float,net:float,vat:float,gross:float,description:string}
     * @throws InvalidArgumentException when the quantity is not a standard lot.
     */
    public static function quote(int $qty, ?float $unit = null): array
    {
        if (!self::isStandardQuantity($qty)) {
            throw new InvalidArgumentException("Quantity {$qty} is not a standard lot");
        }
        $unit = $unit !== null ? $unit : self::UNIT_PRICE;
        $net  = round($qty * $unit, 3);
        $vat  = round($net * self::VAT_RATE, 3);
        return [
            'qty'         => $qty,
            'unit'        => $unit,
            'net'         => $net,
            'vat'         => $vat,
            'gross'       => round($net + $vat, 3),
            'description' => self::DESCRIPTION,
        ];
    }
}
