<?php
/**
 * The published Cardify catalogue price. One number per product, written once.
 *
 * The gauntlet was pointed at a single question: /pricing, /nfc-business-card,
 * the FAQ, the home page JSON-LD and llms.txt all published OMR 10.000 for an
 * NFC card, while BHD's stored print-shop pricing JSON carried an nfc tier of
 * 25 per card. Nothing charged 25, because no order path could select `nfc` as
 * a paper type, but the two numbers sat in the estate disagreeing with each
 * other and either one could have surfaced first.
 *
 * Ali set the canonical selling price at OMR 10.000 per NFC card on 5 Sep 2026.
 * This file is now the only place any catalogue price is written:
 *
 *   - the marketing copy carries a :placeholder, never a number
 *   - the JSON-LD offers read decimal()
 *   - CardPrintPricing resolves `nfc` here and ignores whatever a shop stored
 *   - migration 156 rewrites the stored 25 to the canonical amount
 *   - tests/php/card_catalog_pricing_test.php fails if a number reappears in
 *     the copy, in llms.txt, or in a shop's stored NFC rate
 *
 * OMR carries three decimals, so every rendered amount goes through decimal().
 */
class CardCatalogPricing
{
    public const CURRENCY = 'OMR';
    public const DECIMALS = 3;

    /** Per-card price for `nfc`, per 100 cards for the printed stocks. */
    public const AMOUNTS = [
        'standard' => 5.0,
        'premium'  => 6.0,
        'luxury'   => 15.0,
        'nfc'      => 10.0,
    ];

    /** What the amount buys, so a caller cannot pair a price with the wrong unit. */
    public const UNITS = [
        'standard' => 'per_100',
        'premium'  => 'per_100',
        'luxury'   => 'per_100',
        'nfc'      => 'per_card',
    ];

    /** Paper-type keys that Cardify prices centrally, not the print shop. */
    public const CARDIFY_PRICED = ['nfc'];

    public static function has(string $key): bool
    {
        return isset(self::AMOUNTS[$key]);
    }

    public static function amount(string $key): float
    {
        if (!isset(self::AMOUNTS[$key])) {
            throw new InvalidArgumentException("Unknown catalogue product: {$key}");
        }
        return (float) self::AMOUNTS[$key];
    }

    /** "10.000". The three-decimal string every surface renders. */
    public static function decimal(string $key): string
    {
        return number_format(self::amount($key), self::DECIMALS, '.', '');
    }

    /** True when Cardify sets this paper type's price rather than the shop. */
    public static function isCardifyPriced(string $paperType): bool
    {
        return in_array($paperType, self::CARDIFY_PRICED, true);
    }

    /**
     * The tier shape print_shops.pricing expects, so a stored rate can be
     * rewritten from the canonical amount instead of typed again.
     */
    public static function shopTier(string $key): array
    {
        $amount = self::amount($key);
        return ['quantity_tiers' => ['1' => ['price' => $amount, 'per_card' => $amount]]];
    }

    /**
     * The parameters every price-carrying translation string takes. Copy holds
     * ":nfc_price", never "10.000", so a price change is one edit here.
     *
     * @return array<string,string>
     */
    public static function copyParams(): array
    {
        $out = [];
        foreach (array_keys(self::AMOUNTS) as $key) {
            $out[$key . '_price'] = self::decimal($key);
        }
        return $out;
    }
}
