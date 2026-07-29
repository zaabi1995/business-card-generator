<?php
/**
 * Retired blog slugs and where they went.
 *
 * A slug rename shipped without rewriting in-content and related-post
 * anchors, so 12 old slugs were still linked from 20+ live posts and every
 * one of them answered 404. The anchors themselves are rewritten in the
 * blog_posts rows (scripts/rewrite_dead_blog_anchors.php), but external
 * links, bookmarks and anything already in an index need a permanent
 * target, so the map stays here for good rather than being a migration.
 *
 * Both sides are verified: every key returned 404 and every value returned
 * 200 on cardify.om before this file was written.
 */
final class BlogSlugRedirects
{
    /** old slug => live slug */
    private const MAP = [
        '5-ways-qr-code-business-cards-are-transforming-networking'
            => '5-ways-qr-code-business-cards-transforming-networking-oman',
        'business-card-etiquette-in-oman'
            => 'business-card-etiquette-oman-dos-donts',
        'business-cards-for-real-estate-agents'
            => 'business-cards-real-estate-agents-oman',
        'business-cards-for-restaurants-and-cafes'
            => 'business-cards-restaurants-cafes-oman',
        'complete-guide-to-ordering-business-cards-online-in-oman'
            => 'complete-guide-ordering-business-cards-online-oman',
        'how-digital-business-cards-save-companies-money'
            => 'how-digital-business-cards-save-omani-companies-money',
        'how-to-create-business-cards-for-your-entire-team'
            => 'create-business-cards-entire-team-minutes',
        'how-to-design-the-perfect-business-card'
            => 'how-to-design-perfect-business-card-guide-omani-professionals',
        'how-to-share-your-business-card-without-paper'
            => 'how-share-business-card-without-paper',
        'nfc-business-cards-the-future-of-networking'
            => 'nfc-business-cards-future-networking-oman',
        'print-vs-digital-business-cards'
            => 'print-vs-digital-business-cards-which-right-omani-company',
        'top-10-business-card-design-trends-for-2026'
            => 'top-10-business-card-design-trends-2026',
    ];

    /** @return array<string,string> */
    public static function all(): array
    {
        return self::MAP;
    }

    /** Live slug for a retired one, or null if the slug was never retired. */
    public static function target(string $slug): ?string
    {
        return self::MAP[$slug] ?? null;
    }
}
