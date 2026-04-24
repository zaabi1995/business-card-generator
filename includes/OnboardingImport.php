<?php
/**
 * Step-6 CSV import + wizard-finish bulk commit for the onboarding
 * wizard. Separated from Onboarding.php so parsing/commit logic can
 * be unit-exercised and shared with admin/onboarding-save.php.
 *
 * Expected CSV shape (headers, case-insensitive, any order):
 *   name, email [, phone, mobile, title, department]
 */
class OnboardingImport
{
    public const MAX_PREVIEW_ROWS = 5;
    public const MAX_PARSE_ROWS   = 200;
    public const REQUIRED_HEADERS = ['name', 'email'];
    public const KNOWN_HEADERS    = ['name','email','phone','mobile','title','department'];

    /**
     * Parse a CSV text blob and return:
     *   ['rows' => [{name,email,phone,mobile,title,department}, ...],
     *    'errors' => [{row:int,msg:string}, ...],
     *    'total' => int,  // total non-blank rows
     *    'preview' => [...first 5 valid rows]]
     */
    public static function parseCsv(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') return ['rows' => [], 'errors' => [], 'total' => 0, 'preview' => []];

        // Normalise line endings.
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $raw);
        rewind($fh);

        $rows = [];
        $errors = [];
        $headers = null;
        $lineNo = 0;

        while (($line = fgetcsv($fh)) !== false) {
            $lineNo++;
            if (count($line) === 1 && trim((string) $line[0]) === '') continue;

            if ($headers === null) {
                $headers = array_map(function ($h) {
                    return strtolower(trim((string) $h));
                }, $line);

                // Header validation
                foreach (self::REQUIRED_HEADERS as $req) {
                    if (!in_array($req, $headers, true)) {
                        $errors[] = ['row' => 0, 'msg' => "missing_header_{$req}"];
                    }
                }
                continue;
            }

            if (count($rows) >= self::MAX_PARSE_ROWS) break;

            $row = [];
            foreach ($headers as $i => $col) {
                $row[$col] = isset($line[$i]) ? trim((string) $line[$i]) : '';
            }

            // Per-row validation
            if (empty($row['name']) || empty($row['email'])) {
                $errors[] = ['row' => $lineNo, 'msg' => 'missing_name_or_email'];
                continue;
            }
            if (!filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = ['row' => $lineNo, 'msg' => 'invalid_email'];
                continue;
            }

            // Normalise output shape.
            $rows[] = [
                'name'       => $row['name']       ?? '',
                'email'      => $row['email']      ?? '',
                'phone'      => $row['phone']      ?? '',
                'mobile'     => $row['mobile']     ?? ($row['phone'] ?? ''),
                'title'      => $row['title']      ?? '',
                'department' => $row['department'] ?? '',
            ];
        }
        fclose($fh);

        return [
            'rows'    => $rows,
            'errors'  => $errors,
            'total'   => count($rows),
            'preview' => array_slice($rows, 0, self::MAX_PREVIEW_ROWS),
        ];
    }

    /**
     * Insert parsed rows as employees belonging to $companyId and fire
     * invite messages via EmployeeEditToken::sendInvite(). De-duplicates
     * by email within the same company.
     *
     * Returns ['inserted' => int, 'skipped_existing' => int, 'invites_sent' => int].
     */
    public static function commit(string $companyId, array $parsedRows): array
    {
        if (empty($parsedRows)) return ['inserted' => 0, 'skipped_existing' => 0, 'invites_sent' => 0];

        $db = Database::getInstance();
        $inserted = 0; $skipped = 0; $invitesSent = 0;

        $company = $db->fetchOne(
            "SELECT id, name, slug, brand_color, logo_path AS logo_url FROM companies WHERE id = :id",
            ['id' => $companyId]
        ) ?: ['id' => $companyId, 'name' => 'Cardify'];

        foreach ($parsedRows as $r) {
            $email = trim((string) ($r['email'] ?? ''));
            $name  = trim((string) ($r['name']  ?? ''));
            if ($email === '' || $name === '') continue;

            $existing = $db->fetchOne(
                "SELECT id FROM employees WHERE company_id = :cid AND email = :em LIMIT 1",
                ['cid' => $companyId, 'em' => $email]
            );
            if ($existing) { $skipped++; continue; }

            $eid = generateUUID();
            try {
                $db->insert('employees', [
                    'id'          => $eid,
                    'company_id'  => $companyId,
                    'email'       => $email,
                    'name_en'     => $name,
                    'position_en' => $r['title']  ?? null,
                    'phone'       => $r['phone']  ?? null,
                    'mobile'      => $r['mobile'] ?? ($r['phone'] ?? null),
                    'status'      => 'unclaimed',
                ]);
                $inserted++;
            } catch (Throwable $e) {
                error_log('[onboarding-import] employee insert failed: ' . $e->getMessage());
                continue;
            }

            // Dispatch invite (both WA + email); EmployeeEditToken silently
            // no-ops if WhatsApp::isEnabled() is false or channel is missing.
            if (class_exists('EmployeeEditToken')) {
                try {
                    $res = EmployeeEditToken::sendInvite(
                        ['id' => $eid, 'email' => $email, 'name_en' => $name,
                         'phone' => $r['phone'] ?? null, 'mobile' => $r['mobile'] ?? null],
                        $company,
                        'both'
                    );
                    if (!empty($res['wa']) || !empty($res['email'])) $invitesSent++;
                } catch (Throwable $e) {
                    error_log('[onboarding-import] invite dispatch failed for ' . $email . ': ' . $e->getMessage());
                }
            }
        }

        return ['inserted' => $inserted, 'skipped_existing' => $skipped, 'invites_sent' => $invitesSent];
    }
}
