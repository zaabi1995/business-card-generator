<?php
/**
 * The paper and finish options a card can be ordered on.
 *
 * There were three lists and they disagreed. admin/order_print.php offered
 * matte, glossy and silk to a company; printshop/order-on-behalf.php offered
 * uncoated, matte and silk to a shop ordering for that same company; and
 * printshop/client-pricing.php, where a shop actually sets its rates, offered
 * uncoated, matte and silk. So a company could order "glossy", which no shop
 * could price, and no company could order "uncoated", which every shop could.
 *
 * Two more types exist in the label file and in BHD's stored pricing JSON,
 * soft_touch and luxury_450, and no screen has ever offered either. They stay
 * out of ORDERABLE until someone decides to sell them; listing them here would
 * put them in front of customers, which is not a decision this file gets to
 * make. PRICEABLE carries them so a shop's existing rates are not orphaned.
 *
 * `nfc` is the same shape and matters more, because the published NFC price is
 * OMR 10.000 on /pricing, /nfc-business-card, the FAQ and llms.txt, while BHD's
 * shop JSON carries an nfc tier of 25 per card. No order path can select it, so
 * nothing charges 25 today. tests/php/card_paper_types_test.php fails if any
 * path starts offering it while the two numbers still disagree.
 */
class CardPaperTypes
{
    /** Selectable on every order path. Keys match the pricing JSON. */
    public const ORDERABLE = [
        'uncoated' => 'printshopinternal.paper_uncoated',
        'matte'    => 'printshopinternal.paper_matte',
        'silk'     => 'printshopinternal.paper_silk',
    ];

    /**
     * Types a shop may hold a rate for. A superset of ORDERABLE: a stored rate
     * for something nobody can order yet is dormant, not invalid.
     */
    public const PRICEABLE = [
        'uncoated', 'matte', 'silk', 'glossy', 'soft_touch', 'luxury_450', 'nfc',
    ];

    /** Finishes, shared by the same screens for the same reason. */
    public const FINISHES = [
        'standard'        => 'printshopinternal.finish_standard',
        'rounded_corners' => 'printshopinternal.finish_rounded',
    ];

    public const DEFAULT_TYPE = 'matte';

    /** [key => translated label] for rendering a select. */
    public static function orderableOptions(): array
    {
        $out = [];
        foreach (self::ORDERABLE as $key => $langKey) {
            $out[$key] = function_exists('t') ? t($langKey) : $key;
        }
        return $out;
    }

    public static function finishOptions(): array
    {
        $out = [];
        foreach (self::FINISHES as $key => $langKey) {
            $out[$key] = function_exists('t') ? t($langKey) : $key;
        }
        return $out;
    }

    public static function isOrderable(string $key): bool
    {
        return array_key_exists($key, self::ORDERABLE);
    }
}
