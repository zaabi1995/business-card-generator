<?php
/**
 * r6-100: serve logo bitmaps at the size they are displayed.
 *
 * The library stores masters up to 2048x2048 and the grids render them at
 * 40-256px, so every card downloaded a full master. logo_thumb() returns a
 * cached derivative capped to $box on its long edge, generated once with GD.
 * SVG, unmeasurable files and anything already small pass straight through:
 * the caller always gets a usable src.
 */

if (!function_exists('logo_thumb')) {

    function logo_thumb(?string $webPath, int $box = 256): ?string
    {
        if (!$webPath) {
            return $webPath;
        }
        $clean = parse_url($webPath, PHP_URL_PATH) ?: $webPath;
        $ext = strtolower(pathinfo($clean, PATHINFO_EXTENSION));
        if ($ext === 'svg' || $ext === '') {
            return $webPath;          // vector: already the right size at any box
        }

        $root = dirname(__DIR__);
        $srcFile = $root . '/' . ltrim($clean, '/');
        if (!is_file($srcFile) || !function_exists('imagecreatetruecolor')) {
            return $webPath;
        }

        $info = @getimagesize($srcFile);
        if (!$info || max($info[0], $info[1]) <= $box) {
            return $webPath;          // already at or below the display size
        }

        $rel = 'storage/logos/thumb/' . $box . '/' . sha1($clean) . '.webp';
        $out = $root . '/' . $rel;
        if (is_file($out) && filemtime($out) >= filemtime($srcFile)) {
            return '/' . $rel;
        }

        $src = logo_thumb_load($srcFile, $info[2]);
        if (!$src) {
            return $webPath;
        }

        [$w, $h] = [imagesx($src), imagesy($src)];
        $scale = $box / max($w, $h);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        if (!is_dir(dirname($out)) && !@mkdir(dirname($out), 0775, true) && !is_dir(dirname($out))) {
            imagedestroy($src); imagedestroy($dst);
            return $webPath;
        }
        $ok = function_exists('imagewebp') ? @imagewebp($dst, $out, 82) : false;
        imagedestroy($src);
        imagedestroy($dst);

        return $ok ? '/' . $rel : $webPath;
    }

    function logo_thumb_load(string $file, int $type)
    {
        switch ($type) {
            case IMAGETYPE_PNG:  return function_exists('imagecreatefrompng')  ? @imagecreatefrompng($file)  : null;
            case IMAGETYPE_JPEG: return function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($file) : null;
            case IMAGETYPE_GIF:  return function_exists('imagecreatefromgif')  ? @imagecreatefromgif($file)  : null;
            case IMAGETYPE_WEBP: return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : null;
        }
        return null;
    }
}
