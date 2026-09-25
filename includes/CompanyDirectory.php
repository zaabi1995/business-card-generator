<?php
/**
 * Oman Business Directory: provenance, scores, review queue, export snapshots.
 *
 * Authoritative facts are never overwritten by a weaker source_type.
 * Unknown stays unknown. This class does not call external registries.
 */
final class CompanyDirectory
{
    public const SOURCE_RANK = [
        'official_registry' => 1,
        'government_directory' => 2,
        'company_website' => 3,
        'institutional' => 4,
        'third_party' => 5,
        'cardify_curated' => 6,
        'inferred' => 7,
    ];

    public static function ensureReady($db): bool
    {
        return $db && $db->tableExists('om_company_facts') && $db->columnExists('om_companies', 'data_completeness');
    }

    public static function metrics($db): array
    {
        $row = $db->fetchOne(
            "SELECT COUNT(*) total,
                    SUM(name_en <> '') en_names,
                    SUM(name_ar <> '') ar_names,
                    SUM(website IS NOT NULL AND website <> '') websites,
                    SUM(summary_en IS NOT NULL AND summary_en <> '') summaries_en,
                    SUM(summary_ar IS NOT NULL AND summary_ar <> '') summaries_ar,
                    SUM(sector <> 'other') sector_set,
                    SUM(cr_number IS NOT NULL AND cr_number <> '') with_cr,
                    SUM(data_completeness > 0) scored
               FROM om_companies
              WHERE slug IS NOT NULL AND TRIM(slug) <> ''"
        ) ?: [];
        $official = (int) ($db->fetchOne(
            "SELECT COUNT(DISTINCT company_id) c FROM om_company_facts WHERE source_type = 'official_registry'"
        )['c'] ?? 0);
        $review = (int) ($db->fetchOne(
            "SELECT COUNT(*) c FROM om_company_matches WHERE status = 'proposed'"
        )['c'] ?? 0);
        $stale = (int) ($db->fetchOne(
            "SELECT COUNT(*) c FROM om_companies
              WHERE source_checked_at IS NULL OR source_checked_at < (NOW() - INTERVAL 180 DAY)"
        )['c'] ?? 0);
        $failed = (int) ($db->fetchOne(
            "SELECT COUNT(*) c FROM om_company_jobs WHERE status = 'failed' AND started_at > (NOW() - INTERVAL 7 DAY)"
        )['c'] ?? 0);
        $updatedToday = (int) ($db->fetchOne(
            "SELECT COUNT(*) c FROM om_companies WHERE updated_at >= CURDATE()"
        )['c'] ?? 0);
        return [
            'total' => (int) ($row['total'] ?? 0),
            'english_names' => (int) ($row['en_names'] ?? 0),
            'arabic_names' => (int) ($row['ar_names'] ?? 0),
            'websites' => (int) ($row['websites'] ?? 0),
            'summaries_en' => (int) ($row['summaries_en'] ?? 0),
            'summaries_ar' => (int) ($row['summaries_ar'] ?? 0),
            'sector_set' => (int) ($row['sector_set'] ?? 0),
            'with_cr' => (int) ($row['with_cr'] ?? 0),
            'scored' => (int) ($row['scored'] ?? 0),
            'officially_verified' => $official,
            'review_queue' => $review,
            'stale' => $stale,
            'failed_jobs_7d' => $failed,
            'updated_today' => $updatedToday,
        ];
    }

    /** Score and store provenance from columns that already exist. Does not invent CR or contacts. */
    public static function scoreBatch($db, int $afterId, int $limit): int
    {
        require_once __DIR__ . '/CompanyNormalizer.php';
        $rows = $db->fetchAll(
            "SELECT id, name_en, name_ar, sector, wilayat, size_bucket, website, summary_en, summary_ar,
                    curated, verified_at
               FROM om_companies WHERE id > ? ORDER BY id ASC LIMIT $limit",
            [$afterId]
        );
        $last = $afterId;
        foreach ($rows as $row) {
            $last = (int) $row['id'];
            $normEn = CompanyNormalizer::englishName($row['name_en'] ?? '');
            $normAr = CompanyNormalizer::arabicName($row['name_ar'] ?? '');
            $domain = CompanyNormalizer::domain($row['website'] ?? '');
            $points = 0;
            $possible = 8;
            if ($normEn !== '') $points++;
            if ($normAr !== '') $points++;
            if (($row['sector'] ?? 'other') !== 'other') $points++;
            if (($row['wilayat'] ?? '') !== '') $points++;
            if (($row['size_bucket'] ?? '') !== '') $points++;
            if ($domain !== '') $points++;
            if (trim((string) ($row['summary_en'] ?? '')) !== '') $points++;
            if (trim((string) ($row['summary_ar'] ?? '')) !== '') $points++;
            $completeness = (int) round(100 * $points / $possible);
            // No official registry link exists yet, so confidence stays below 1.
            $confidence = 0.35;
            if ((int) ($row['curated'] ?? 0) === 1) $confidence += 0.25;
            if (!empty($row['verified_at'])) $confidence += 0.15;
            if ($domain !== '') $confidence += 0.10;
            $confidence = min(0.85, $confidence);
            $db->query(
                "UPDATE om_companies
                    SET normalized_name_en = ?, normalized_name_ar = ?,
                        data_completeness = ?, verification_confidence = ?,
                        profile_scored_at = NOW()
                  WHERE id = ?",
                [$normEn, $normAr, $completeness, $confidence, $last]
            );
            $sourceType = ((int) ($row['curated'] ?? 0) === 1) ? 'cardify_curated' : 'third_party';
            foreach ([
                'name_en' => $row['name_en'] ?? '',
                'name_ar' => $row['name_ar'] ?? '',
                'sector' => $row['sector'] ?? '',
                'wilayat' => $row['wilayat'] ?? '',
                'website' => $domain,
                'summary_en' => $row['summary_en'] ?? '',
            ] as $field => $value) {
                if (trim((string) $value) === '') {
                    continue;
                }
                self::putFact($db, $last, $field, (string) $value, 'Cardify Oman Business Index', $sourceType, null, $sourceType === 'cardify_curated' ? 0.70 : 0.40);
            }
        }
        return $last;
    }

    /**
     * Write a fact only when the incoming source is at least as strong as the stored one.
     * Returns true when the current fact changed.
     */
    public static function putFact($db, int $companyId, string $field, string $value, string $sourceName, string $sourceType, ?string $sourceUrl, float $confidence): bool
    {
        if (!isset(self::SOURCE_RANK[$sourceType])) {
            throw new InvalidArgumentException('Unknown source type');
        }
        $value = trim($value);
        if ($value === '') {
            return false;
        }
        $existing = $db->fetchOne(
            "SELECT id, source_type, value_text FROM om_company_facts WHERE company_id = ? AND field_name = ?",
            [$companyId, $field]
        );
        if ($existing) {
            $oldRank = self::SOURCE_RANK[$existing['source_type']] ?? 99;
            $newRank = self::SOURCE_RANK[$sourceType];
            if ($newRank > $oldRank) {
                return false;
            }
            if ($existing['value_text'] === $value && $existing['source_type'] === $sourceType) {
                $db->query(
                    "UPDATE om_company_facts SET source_checked_at = NOW(), confidence = ? WHERE id = ?",
                    [$confidence, $existing['id']]
                );
                return false;
            }
        }
        $db->query(
            "INSERT INTO om_company_facts
                (company_id, field_name, value_text, source_name, source_type, source_url, confidence, verified_at, source_checked_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                value_text = VALUES(value_text),
                source_name = VALUES(source_name),
                source_type = VALUES(source_type),
                source_url = VALUES(source_url),
                confidence = VALUES(confidence),
                verified_at = NOW(),
                source_checked_at = NOW()",
            [$companyId, $field, $value, $sourceName, $sourceType, $sourceUrl, $confidence]
        );
        return true;
    }

    /** Queue exact normalized-name or exact-domain pairs. Never auto-merges. */
    public static function queueDeterministicCandidates($db): int
    {
        $inserted = 0;
        $nameGroups = $db->fetchAll(
            "SELECT normalized_name_en n, GROUP_CONCAT(id ORDER BY id) ids, COUNT(*) c
               FROM om_companies
              WHERE normalized_name_en IS NOT NULL AND normalized_name_en <> ''
              GROUP BY normalized_name_en HAVING c > 1 AND c <= 6
              LIMIT 200"
        );
        foreach ($nameGroups as $g) {
            $inserted += self::pairGroup($db, $g['ids'], 0.72, 'normalized_english_name');
        }
        $domains = $db->fetchAll(
            "SELECT LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(REPLACE(REPLACE(website,'https://',''),'http://',''),'/',1),'www.',-1)) d,
                    GROUP_CONCAT(id ORDER BY id) ids, COUNT(*) c
               FROM om_companies
              WHERE website IS NOT NULL AND website <> ''
              GROUP BY d HAVING c > 1 AND c <= 8 AND d <> ''
              LIMIT 200"
        );
        foreach ($domains as $g) {
            $inserted += self::pairGroup($db, $g['ids'], 0.55, 'shared_domain');
        }
        return $inserted;
    }

    private static function pairGroup($db, string $ids, float $score, string $reason): int
    {
        $list = array_map('intval', explode(',', $ids));
        $n = 0;
        for ($i = 0; $i < count($list); $i++) {
            for ($j = $i + 1; $j < count($list); $j++) {
                $a = min($list[$i], $list[$j]);
                $b = max($list[$i], $list[$j]);
                $stmt = $db->query(
                    "INSERT IGNORE INTO om_company_matches (company_a, company_b, score, reason, status)
                     VALUES (?, ?, ?, ?, 'proposed')",
                    [$a, $b, $score, $reason]
                );
                $n += $stmt->rowCount() > 0 ? 1 : 0;
            }
        }
        return $n;
    }

    public static function decideMatch($db, int $matchId, string $decision, string $actor): void
    {
        if (!in_array($decision, ['accepted', 'rejected'], true)) {
            throw new InvalidArgumentException('decision');
        }
        $db->query(
            "UPDATE om_company_matches SET status = ?, decided_by = ?, decided_at = NOW() WHERE id = ? AND status = 'proposed'",
            [$decision, $actor, $matchId]
        );
    }

    /**
     * Reproduce a field-locked export. Does not add personal fields.
     * Returns ['path','checksum','count','version'].
     */
    public static function exportSnapshot($db, string $customer, string $version, array $fields, string $licence, ?string $amount): array
    {
        $allowed = ['name_en','name_ar','sector','wilayat','size_bucket','website','summary_en','summary_ar','cr_number','slug'];
        foreach ($fields as $f) {
            if (!in_array($f, $allowed, true)) {
                throw new InvalidArgumentException('Field not exportable: ' . $f);
            }
        }
        $dir = dirname(__DIR__) . '/storage/directory-exports';
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create export directory');
        }
        $safeVersion = preg_replace('/[^a-zA-Z0-9._-]/', '', $version) ?: 'export';
        $path = $dir . '/' . $safeVersion . '.csv';
        $fh = fopen($path, 'wb');
        if (!$fh) {
            throw new RuntimeException('Cannot write export');
        }
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, $fields);
        $count = 0;
        $after = 0;
        $colSql = implode(', ', $fields);
        while (true) {
            $rows = $db->fetchAll(
                "SELECT id, $colSql FROM om_companies WHERE id > ? AND slug IS NOT NULL AND TRIM(slug) <> '' ORDER BY id ASC LIMIT 400",
                [$after]
            );
            if (!$rows) {
                break;
            }
            foreach ($rows as $row) {
                $after = (int) $row['id'];
                $line = [];
                foreach ($fields as $f) {
                    $line[] = $row[$f] ?? '';
                }
                fputcsv($fh, $line);
                $count++;
            }
        }
        fclose($fh);
        $checksum = hash_file('sha256', $path);
        $db->query(
            "INSERT INTO om_directory_exports
                (customer, dataset_version, record_count, fields_json, licence, amount_omr, checksum, file_path)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [$customer, $version, $count, json_encode($fields), $licence, $amount, $checksum, $path]
        );
        return ['path' => $path, 'checksum' => $checksum, 'count' => $count, 'version' => $version];
    }

    public static function recordJob($db, string $type, string $status, int $changed, ?string $error, int $durationMs, ?string $source): void
    {
        $db->query(
            "INSERT INTO om_company_jobs (job_type, status, records_changed, error_text, duration_ms, attempted_source, started_at, finished_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())",
            [$type, $status, $changed, $error, $durationMs, $source]
        );
    }
}
