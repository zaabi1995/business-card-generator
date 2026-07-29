<?php
/**
 * Rewrite in-content anchors that point at retired blog slugs.
 *
 * The 301s in blog.php keep external links alive, but an internal link that
 * costs a hop is still a link to a URL we know is dead, and the crawl budget
 * is spent either way. This rewrites the anchors in blog_posts.content and
 * content_ar so the live estate links directly.
 *
 * Usage:
 *   php scripts/rewrite_dead_blog_anchors.php          # report only
 *   php scripts/rewrite_dead_blog_anchors.php --apply  # write
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/BlogSlugRedirects.php';

$apply = in_array('--apply', $argv, true);
$db    = Database::getInstance();
$map   = BlogSlugRedirects::all();

$rows = $db->fetchAll("SELECT id, slug, content, content_ar FROM blog_posts");
$totalHits = 0;
$touched   = 0;

foreach ($rows as $row) {
    $new = [];
    foreach (['content', 'content_ar'] as $col) {
        $html = $row[$col] ?? null;
        if (!is_string($html) || $html === '') continue;
        $out = $html;
        foreach ($map as $old => $live) {
            // Anchor the slug on the /blog/ prefix and a closing quote so a
            // retired slug can never match a longer live slug that contains
            // it as a prefix.
            foreach (['"', "'"] as $q) {
                $out = str_replace(
                    '/blog/' . $old . $q,
                    '/blog/' . $live . $q,
                    $out
                );
            }
        }
        if ($out !== $html) {
            $hits = 0;
            foreach ($map as $old => $_) {
                $hits += substr_count($html, '/blog/' . $old . '"')
                       + substr_count($html, "/blog/" . $old . "'");
            }
            $totalHits += $hits;
            $new[$col]  = $out;
            echo sprintf("post %d (%s) %s: %d anchor(s)\n", $row['id'], $row['slug'], $col, $hits);
        }
    }
    if (!$new) continue;
    $touched++;
    if (!$apply) continue;
    $sets = [];
    $args = ['id' => $row['id']];
    foreach ($new as $col => $val) {
        $sets[] = "$col = :$col";
        $args[$col] = $val;
    }
    $db->query("UPDATE blog_posts SET " . implode(', ', $sets) . " WHERE id = :id", $args);
}

echo sprintf(
    "%s: %d post(s), %d anchor(s)\n",
    $apply ? 'REWROTE' : 'WOULD REWRITE (pass --apply)',
    $touched,
    $totalHits
);
