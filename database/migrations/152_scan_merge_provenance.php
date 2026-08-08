<?php
/**
 * Merge provenance for a scanned contact.
 *
 * When the app merges a re-scanned card into an existing contact, it records what
 * the merge did: which values were kept, which were replaced, and what the losing
 * value was. That record is the only way a user can tell what a merge destroyed.
 *
 * It was being written to a local `notes` field that does not exist on this table,
 * so it never left the device: `tags` gained a `merged` flag and synced, while the
 * record of WHAT was overwritten did not. On a second device, a reinstall or a
 * restore, the contact showed as merged with nothing to explain it and no way back.
 *
 * Stored as a JSON string rather than prose because the app renders it in the
 * user's own language; machine-written English inside a bilingual product is not
 * a record an Arabic user can read. LONGTEXT rather than TEXT to match `parsed`,
 * since a contact merged repeatedly accumulates entries.
 *
 * Nullable with no default and no backfill: every existing row has genuinely never
 * been merged on this device, and NULL says exactly that. Inventing an empty
 * provenance record for 100% of existing rows would assert a merge history that
 * does not exist.
 */
function migration_152_scan_merge_provenance(PDO $pdo): array
{
    $result = ['success' => false, 'errors' => [], 'messages' => []];
    try {
        $columns = $pdo->query(
            "SELECT column_name
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'scans'
               AND column_name = 'merge_provenance'"
        )->fetchAll(PDO::FETCH_COLUMN);

        if ($columns) {
            $result['success'] = true;
            $result['messages'][] = 'scans.merge_provenance already present, nothing to do';
            return $result;
        }

        $pdo->exec("ALTER TABLE scans ADD COLUMN merge_provenance LONGTEXT NULL AFTER met_where");
        $result['success'] = true;
        $result['messages'][] = 'added scans.merge_provenance';
        return $result;
    } catch (Throwable $e) {
        $result['errors'][] = $e->getMessage();
        return $result;
    }
}
