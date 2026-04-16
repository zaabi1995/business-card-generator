<?php
/**
 * EmployeeSocials — flexible social link catalog + persistence.
 *
 * - PLATFORMS is the allow-list. Anything else is rejected.
 * - Each platform defines: label, Font Awesome icon class, URL prefix hint.
 * - `website` and `email` are intentionally not brand icons.
 * - Reads fall back to the legacy employees.linkedin / employees.twitter
 *   columns when the new table has no rows for the employee.
 */
class EmployeeSocials
{
    // 25 brand platforms + website + email (27 total)
    const PLATFORMS = [
        'facebook'      => ['label' => 'Facebook',       'icon' => 'fa-brands fa-facebook',       'hint' => 'https://facebook.com/…'],
        'twitter'       => ['label' => 'X / Twitter',    'icon' => 'fa-brands fa-x-twitter',      'hint' => 'https://x.com/…'],
        'instagram'     => ['label' => 'Instagram',      'icon' => 'fa-brands fa-instagram',      'hint' => 'https://instagram.com/…'],
        'linkedin'      => ['label' => 'LinkedIn',       'icon' => 'fa-brands fa-linkedin',       'hint' => 'https://linkedin.com/in/…'],
        'youtube'       => ['label' => 'YouTube',        'icon' => 'fa-brands fa-youtube',        'hint' => 'https://youtube.com/@…'],
        'tiktok'        => ['label' => 'TikTok',         'icon' => 'fa-brands fa-tiktok',         'hint' => 'https://tiktok.com/@…'],
        'whatsapp'      => ['label' => 'WhatsApp',       'icon' => 'fa-brands fa-whatsapp',       'hint' => 'https://wa.me/…'],
        'telegram'      => ['label' => 'Telegram',       'icon' => 'fa-brands fa-telegram',       'hint' => 'https://t.me/…'],
        'snapchat'      => ['label' => 'Snapchat',       'icon' => 'fa-brands fa-snapchat',       'hint' => 'https://snapchat.com/add/…'],
        'pinterest'     => ['label' => 'Pinterest',      'icon' => 'fa-brands fa-pinterest',      'hint' => 'https://pinterest.com/…'],
        'github'        => ['label' => 'GitHub',         'icon' => 'fa-brands fa-github',         'hint' => 'https://github.com/…'],
        'gitlab'        => ['label' => 'GitLab',         'icon' => 'fa-brands fa-gitlab',         'hint' => 'https://gitlab.com/…'],
        'bitbucket'     => ['label' => 'Bitbucket',      'icon' => 'fa-brands fa-bitbucket',      'hint' => 'https://bitbucket.org/…'],
        'stackoverflow' => ['label' => 'Stack Overflow', 'icon' => 'fa-brands fa-stack-overflow', 'hint' => 'https://stackoverflow.com/users/…'],
        'dribbble'      => ['label' => 'Dribbble',       'icon' => 'fa-brands fa-dribbble',       'hint' => 'https://dribbble.com/…'],
        'behance'       => ['label' => 'Behance',        'icon' => 'fa-brands fa-behance',        'hint' => 'https://behance.net/…'],
        'medium'        => ['label' => 'Medium',         'icon' => 'fa-brands fa-medium',         'hint' => 'https://medium.com/@…'],
        'dev-to'        => ['label' => 'Dev.to',         'icon' => 'fa-brands fa-dev',            'hint' => 'https://dev.to/…'],
        'reddit'        => ['label' => 'Reddit',         'icon' => 'fa-brands fa-reddit',         'hint' => 'https://reddit.com/user/…'],
        'discord'       => ['label' => 'Discord',        'icon' => 'fa-brands fa-discord',        'hint' => 'https://discord.gg/…'],
        'twitch'        => ['label' => 'Twitch',         'icon' => 'fa-brands fa-twitch',         'hint' => 'https://twitch.tv/…'],
        'soundcloud'    => ['label' => 'SoundCloud',     'icon' => 'fa-brands fa-soundcloud',     'hint' => 'https://soundcloud.com/…'],
        'spotify'       => ['label' => 'Spotify',        'icon' => 'fa-brands fa-spotify',        'hint' => 'https://open.spotify.com/…'],
        'apple-music'   => ['label' => 'Apple Music',    'icon' => 'fa-brands fa-apple',          'hint' => 'https://music.apple.com/…'],
        'bandcamp'      => ['label' => 'Bandcamp',       'icon' => 'fa-brands fa-bandcamp',       'hint' => 'https://…bandcamp.com'],
        'website'       => ['label' => 'Website',        'icon' => 'fa-solid fa-globe',           'hint' => 'https://…'],
        'email'         => ['label' => 'Email',          'icon' => 'fa-solid fa-envelope',        'hint' => 'mailto:…'],
    ];

    public static function isValidPlatform($platform)
    {
        return is_string($platform) && isset(self::PLATFORMS[$platform]);
    }

    public static function iconFor($platform)
    {
        return self::PLATFORMS[$platform]['icon'] ?? 'fa-solid fa-link';
    }

    public static function labelFor($platform)
    {
        return self::PLATFORMS[$platform]['label'] ?? ucfirst((string)$platform);
    }

    /**
     * Load ordered socials for an employee, falling back to legacy columns
     * when the employee has no rows in employee_socials.
     */
    public static function loadForEmployee($employeeId)
    {
        if (empty($employeeId)) return [];
        $db = Database::getInstance();
        $rows = $db->fetchAll(
            "SELECT id, platform, url, position FROM employee_socials
             WHERE employee_id = :eid
             ORDER BY position ASC, id ASC",
            ['eid' => $employeeId]
        ) ?: [];

        if (!empty($rows)) {
            return array_map([__CLASS__, 'decorate'], $rows);
        }

        // Legacy fallback — surface linkedin/twitter columns if present
        $legacy = $db->fetchOne(
            "SELECT linkedin, twitter FROM employees WHERE id = :eid",
            ['eid' => $employeeId]
        );
        if (!$legacy) return [];
        $out = [];
        $pos = 0;
        foreach (['linkedin' => $legacy['linkedin'] ?? '', 'twitter' => $legacy['twitter'] ?? ''] as $p => $u) {
            $u = trim((string)$u);
            if ($u === '') continue;
            $out[] = self::decorate([
                'id' => null, 'platform' => $p, 'url' => $u, 'position' => $pos++,
            ]);
        }
        return $out;
    }

    private static function decorate(array $row)
    {
        $row['icon']  = self::iconFor($row['platform']);
        $row['label'] = self::labelFor($row['platform']);
        return $row;
    }

    /**
     * Replace the entire social-link set for an employee atomically.
     * $items = [['platform' => '...', 'url' => '...'], ...]  (order = position)
     *
     * Tenant-safety: caller must supply $companyId; we only operate on rows
     * that belong to (employee_id, company_id).
     */
    public static function replaceAll($employeeId, $companyId, array $items)
    {
        $db = Database::getInstance();
        $pdo = $db->getConnection();

        $clean = [];
        foreach ($items as $it) {
            $platform = isset($it['platform']) ? trim((string)$it['platform']) : '';
            $url = isset($it['url']) ? trim((string)$it['url']) : '';
            if ($platform === '' || $url === '') continue;
            if (!self::isValidPlatform($platform)) continue;
            if (strlen($url) > 512) $url = substr($url, 0, 512);
            $clean[] = ['platform' => $platform, 'url' => $url];
        }

        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare(
                "DELETE FROM employee_socials WHERE employee_id = :eid AND company_id = :cid"
            );
            $del->execute([':eid' => $employeeId, ':cid' => $companyId]);

            if (!empty($clean)) {
                $ins = $pdo->prepare(
                    "INSERT INTO employee_socials (employee_id, company_id, platform, url, position)
                     VALUES (:eid, :cid, :p, :u, :pos)"
                );
                foreach ($clean as $i => $row) {
                    $ins->execute([
                        ':eid' => $employeeId,
                        ':cid' => $companyId,
                        ':p'   => $row['platform'],
                        ':u'   => $row['url'],
                        ':pos' => $i,
                    ]);
                }
            }
            $pdo->commit();
            return count($clean);
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Turn whatever the user typed into a safe href.
     * For brand platforms, ensure https://; for email, prefix mailto:.
     */
    public static function hrefFor($platform, $url)
    {
        $url = trim((string)$url);
        if ($url === '') return '#';
        if ($platform === 'email') {
            if (stripos($url, 'mailto:') === 0) return $url;
            return 'mailto:' . $url;
        }
        if (preg_match('~^(https?:)?//~i', $url) || stripos($url, 'http') === 0) {
            return $url;
        }
        return 'https://' . ltrim($url, '/');
    }
}
