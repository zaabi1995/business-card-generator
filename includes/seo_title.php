<?php
/**
 * Shared <title> composition.
 *
 * Two defects live here, both of which only ever showed up in Arabic:
 *
 * 1. The brand check used to be stripos($pageTitle, 'Cardify'). Arabic titles
 *    carry the transliterated brand كارديفاي, never the Latin one, so every
 *    Arabic page got a SECOND brand appended and the rendered title ran past
 *    the 65-character band even when the authored string was inside it.
 * 2. Nothing fitted the composed title to a length at all, so the 2,536
 *    /ar/companies/ pages published titles of 66-101 characters, driven by the
 *    length of the company's legal name.
 *
 * seo_compose_title() is the single place that answers "what does <title>
 * actually say", so <title>, og:title and twitter:title cannot disagree.
 */

if (!function_exists('seo_brand_aliases')) {
    function seo_brand_aliases(string $brandName): array
    {
        return array_values(array_unique(array_filter([$brandName, 'Cardify', 'كارديفاي'])));
    }
}

if (!function_exists('seo_has_brand')) {
    function seo_has_brand(string $title, string $brandName): bool
    {
        foreach (seo_brand_aliases($brandName) as $alias) {
            if (mb_stripos($title, $alias, 0, 'UTF-8') !== false) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('seo_fit_title')) {
    /**
     * Shorten a title to $max characters by trimming the FIRST comma segment
     * (the variable part: a company name, a wilayat label) and never the
     * trailing brand or section labels, which are what tell a reader and a
     * model what the page is.
     */
    function seo_fit_title(string $title, int $max = 65): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title));
        if (mb_strlen($title, 'UTF-8') <= $max) {
            return $title;
        }

        // Split off the fixed tail: everything from the first ", " onwards.
        $commaPos = mb_strpos($title, ', ', 0, 'UTF-8');
        if ($commaPos !== false && $commaPos > 0) {
            $head = mb_substr($title, 0, $commaPos, 'UTF-8');
            $tail = mb_substr($title, $commaPos, null, 'UTF-8'); // includes ", "
            $budget = $max - mb_strlen($tail, 'UTF-8') - 1;      // -1 for the ellipsis
            if ($budget >= 12) {
                $head = rtrim(mb_substr($head, 0, $budget, 'UTF-8'));
                return $head . '…' . $tail;
            }
        }

        // No comma form: keep the tail after the LAST pipe (the brand) intact
        // and shorten the leading segment instead, so a long legal name never
        // eats the brand.
        $pipePos = mb_strrpos($title, ' | ', 0, 'UTF-8');
        if ($pipePos !== false && $pipePos > 0) {
            $head = mb_substr($title, 0, $pipePos, 'UTF-8');
            $tail = mb_substr($title, $pipePos, null, 'UTF-8');
            $budget = $max - mb_strlen($tail, 'UTF-8') - 1;
            if ($budget >= 12) {
                return rtrim(mb_substr($head, 0, $budget, 'UTF-8')) . '…' . $tail;
            }
        }

        return rtrim(mb_substr($title, 0, $max - 1, 'UTF-8')) . '…';
    }
}

if (!function_exists('seo_pick_title')) {
    /**
     * Given progressively shorter title candidates, return the first that fits
     * the band once the brand is appended. Used where the variable part (a
     * company's legal name) is the query term and must not be the thing cut.
     */
    function seo_pick_title(array $candidates, string $brandName = 'Cardify', int $max = 65): string
    {
        $last = '';
        foreach ($candidates as $cand) {
            $cand = trim((string) $cand);
            if ($cand === '') {
                continue;
            }
            $last = $cand;
            $composed = seo_has_brand($cand, $brandName) ? $cand : $cand . ' | ' . $brandName;
            if (mb_strlen($composed, 'UTF-8') <= $max) {
                return $cand;
            }
        }
        return $last;
    }
}

if (!function_exists('seo_compose_title')) {
    function seo_compose_title(string $pageTitle, string $brandName = 'Cardify', int $max = 65): string
    {
        $pageTitle = trim($pageTitle);
        if ($pageTitle === '') {
            return $brandName;
        }
        if (!seo_has_brand($pageTitle, $brandName)) {
            $pageTitle .= ' | ' . $brandName;
        }
        return seo_fit_title($pageTitle, $max);
    }
}
