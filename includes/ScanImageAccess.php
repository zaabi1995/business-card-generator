<?php
/** Private image resolution. Call only after authenticating an employee. */
final class ScanImageAccess
{
    public static function resolve($db, string $employeeId, int $scanId, string $side, string $root): ?string
    {
        if ($scanId < 1 || !in_array($side, ['front', 'back'], true)) return null;
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $employeeId)) return null;
        $row = $db->fetchOne(
            "SELECT image_path, image_path_back FROM scans WHERE id = :i AND employee_id = :e AND status != 'deleted'",
            ['i' => $scanId, 'e' => $employeeId]
        );
        if (!$row) return null;
        $relative = $row[$side === 'back' ? 'image_path_back' : 'image_path'] ?? '';
        $prefix = 'uploads/scans/' . $employeeId . '/';
        if (!preg_match('/^' . preg_quote($prefix, '/') . '[0-9a-f]{24}\.(?:jpg|png|webp)$/D', $relative)) return null;
        if (is_link($root . '/' . rtrim($prefix, '/'))) return null;
        $allowed = realpath($root . '/' . $prefix);
        $file = realpath($root . '/' . $relative);
        if ($allowed === false || $file === false || !is_file($file)) return null;
        if (strpos($file, $allowed . DIRECTORY_SEPARATOR) !== 0 || is_link($root . '/' . $relative)) return null;
        // Do not let a symlinked employee directory point outside scan storage.
        $storage = realpath($root . '/uploads/scans');
        if ($storage === false || strpos($allowed, $storage . DIRECTORY_SEPARATOR) !== 0) return null;
        return $file;
    }
}
