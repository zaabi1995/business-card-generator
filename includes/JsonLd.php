<?php
/**
 * One encoder for everything that goes inside a <script> tag.
 *
 * An HTML parser ends a <script> block at the first "</script>" byte sequence
 * it sees. It does not care that the sequence sits inside a JSON string. So a
 * value like
 *
 *     "name": "<script>alert(1)</script>"
 *
 * closes the ld+json block early and the browser parses the rest as HTML. That
 * was live on the public digital card: an employee called
 * "<script>alert(1)</script>" executed on the page their whole company shares,
 * and the name is settable by a tenant admin or by the employee themselves
 * through the self-edit link. Verified on cardify.om, 5 Sep 2026. The title and
 * meta tags on that same page escaped it correctly; only the JSON-LD did not.
 *
 * JSON_HEX_TAG turns < and > into < and >, which is still valid JSON
 * and can no longer close a tag. The other three cover the same trick in an
 * inline event handler or an attribute.
 *
 * Use block() for a full <script type="application/ld+json"> element, and
 * value() for a bare value interpolated into a plain <script>.
 */
class JsonLd
{
    /** The flags that make a value safe to sit inside a script element. */
    public const SAFE = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

    /**
     * A complete ld+json script element.
     *
     * @param array $data      the structured-data graph
     * @param bool  $pretty    keep the pretty printing some pages already had
     * @param int   $extra     extra json_encode flags, e.g. JSON_UNESCAPED_UNICODE
     */
    public static function block(array $data, bool $pretty = false, int $extra = 0): string
    {
        return '<script type="application/ld+json">'
            . self::encode($data, $pretty, $extra)
            . '</script>';
    }

    /** The encoded body only, for callers that write their own script tag. */
    public static function encode(array $data, bool $pretty = false, int $extra = 0): string
    {
        $flags = self::SAFE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | $extra;
        if ($pretty) $flags |= JSON_PRETTY_PRINT;
        $out = json_encode($data, $flags);
        return $out === false ? '{}' : $out;
    }

    /**
     * One value for a plain <script> block, such as
     *   var title = <?= JsonLd::value($name) ?>;
     * Same reasoning: a name holding "</script>" would end the block.
     *
     * @param mixed $value
     */
    public static function value($value, int $extra = 0): string
    {
        $out = json_encode($value, self::SAFE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | $extra);
        return $out === false ? 'null' : $out;
    }
}
