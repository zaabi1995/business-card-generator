<?php
/**
 * NotificationCenter, thin query layer over the tables added by
 * migration 084.
 *
 * For dispatch, existing includes/Notifier.php still handles the
 * email + whatsapp side. NotificationCenter focuses on the in-app
 * bell feed, the preferences toggle, and the dispatch-log reads.
 *
 * Semantics:
 *   NotificationCenter::push($recipientId, $type, $eventType, $title, $body, $actionUrl, $meta, $companyId)
 *     Writes a row into notifications (in-app bell).
 *
 *   NotificationCenter::unreadCount($recipientId)
 *   NotificationCenter::recent($recipientId, $limit=20)
 *   NotificationCenter::markRead($ids, $recipientId)
 *   NotificationCenter::markAllRead($recipientId)
 *
 *   NotificationCenter::prefs($userId)
 *     Returns array keyed by event_type with channel + digest flags.
 *     Falls back to ['channel_email' => 1, 'channel_whatsapp' => 1,
 *     'channel_inapp' => 1, 'digest_mode' => 'instant'] per event.
 *
 *   NotificationCenter::setPref($userId, $eventType, $channels)
 *     Upsert a row in notification_preferences.
 *
 *   NotificationCenter::logDispatch($notificationId, $recipient, $event, $channel, $locale, $templateKey)
 *     Appends a notification_dispatches row; call again with status
 *     updates (sent/delivered/opened/clicked).
 *
 *   NotificationCenter::activeBanner($audience='all')
 *     Returns the most-recent live system_banners row for the
 *     audience (or null), used by the admin chrome banner.
 */
class NotificationCenter
{
    public const AUDIENCES = ['all','company_admins','employees','print_shops'];

    public static function push(
        string $recipientId,
        string $recipientType,
        string $eventType,
        string $title,
        ?string $body = null,
        ?string $actionUrl = null,
        array $metadata = [],
        ?string $companyId = null
    ): ?int {
        try {
            $db = Database::getInstance();
            $db->insert('notifications', [
                'recipient_id'   => $recipientId,
                'recipient_type' => $recipientType,
                'company_id'     => $companyId,
                'event_type'     => $eventType,
                'title'          => $title,
                'body'           => $body,
                'action_url'     => $actionUrl,
                'metadata'       => $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
            ]);
            return (int) $db->lastInsertId();
        } catch (Throwable $e) {
            error_log('[NotificationCenter::push] ' . $e->getMessage());
            return null;
        }
    }

    public static function unreadCount(string $recipientId): int
    {
        try {
            $db = Database::getInstance();
            $row = $db->fetchOne(
                "SELECT COUNT(*) c FROM notifications WHERE recipient_id = :r AND read_at IS NULL",
                ['r' => $recipientId]
            );
            return (int) ($row['c'] ?? 0);
        } catch (Throwable $e) { return 0; }
    }

    public static function recent(string $recipientId, int $limit = 20): array
    {
        try {
            $db = Database::getInstance();
            $limit = max(1, min(100, $limit));
            $rows = $db->fetchAll(
                "SELECT id, event_type, title, body, action_url, metadata, read_at, created_at
                 FROM notifications
                 WHERE recipient_id = :r
                 ORDER BY created_at DESC
                 LIMIT {$limit}",
                ['r' => $recipientId]
            );
            foreach ($rows as &$r) {
                $r['metadata'] = $r['metadata'] ? json_decode($r['metadata'], true) : null;
                $r['is_read']  = !empty($r['read_at']);
            }
            return $rows;
        } catch (Throwable $e) { return []; }
    }

    public static function markRead(array $ids, string $recipientId): int
    {
        $ids = array_values(array_filter($ids, fn($i) => (int)$i > 0));
        if (!$ids) return 0;
        try {
            $db = Database::getInstance();
            $placeholders = implode(',', array_map(fn($i, $n) => ":i{$n}", $ids, array_keys($ids)));
            $params = ['r' => $recipientId];
            foreach ($ids as $n => $i) $params["i{$n}"] = (int)$i;
            $db->exec(null); // noop, Database doesn't expose rowCount easily
            $db->update(
                'notifications',
                ['read_at' => date('Y-m-d H:i:s')],
                "recipient_id = :r AND read_at IS NULL AND id IN ({$placeholders})",
                $params
            );
            return count($ids);
        } catch (Throwable $e) { return 0; }
    }

    public static function markAllRead(string $recipientId): int
    {
        try {
            $db = Database::getInstance();
            $db->update(
                'notifications',
                ['read_at' => date('Y-m-d H:i:s')],
                'recipient_id = :r AND read_at IS NULL',
                ['r' => $recipientId]
            );
            return 1;
        } catch (Throwable $e) { return 0; }
    }

    public static function prefs(string $userId): array
    {
        try {
            $db = Database::getInstance();
            $rows = $db->fetchAll(
                "SELECT event_type, channel_email, channel_whatsapp, channel_inapp, digest_mode
                 FROM notification_preferences WHERE user_id = :u",
                ['u' => $userId]
            );
            $out = [];
            foreach ($rows as $r) {
                $out[$r['event_type']] = [
                    'email'    => (bool) $r['channel_email'],
                    'whatsapp' => (bool) $r['channel_whatsapp'],
                    'inapp'    => (bool) $r['channel_inapp'],
                    'digest'   => $r['digest_mode'],
                ];
            }
            return $out;
        } catch (Throwable $e) { return []; }
    }

    public static function setPref(string $userId, string $eventType, array $channels): bool
    {
        try {
            $db = Database::getInstance();
            $data = [
                'user_id'          => $userId,
                'event_type'       => $eventType,
                'channel_email'    => !empty($channels['email']) ? 1 : 0,
                'channel_whatsapp' => !empty($channels['whatsapp']) ? 1 : 0,
                'channel_inapp'    => !empty($channels['inapp']) ? 1 : 0,
                'digest_mode'      => $channels['digest'] ?? 'instant',
            ];
            $existing = $db->fetchOne(
                "SELECT id FROM notification_preferences WHERE user_id = :u AND event_type = :e",
                ['u' => $userId, 'e' => $eventType]
            );
            if ($existing) {
                unset($data['user_id'], $data['event_type']);
                $db->update(
                    'notification_preferences',
                    $data,
                    'id = :id',
                    ['id' => $existing['id']]
                );
            } else {
                $db->insert('notification_preferences', $data);
            }
            return true;
        } catch (Throwable $e) {
            error_log('[NotificationCenter::setPref] ' . $e->getMessage());
            return false;
        }
    }

    public static function logDispatch(
        ?int $notificationId,
        string $recipientId,
        ?string $recipientContact,
        string $eventType,
        string $channel,
        string $locale = 'en',
        ?string $templateKey = null,
        string $status = 'sent'
    ): ?int {
        try {
            $db = Database::getInstance();
            $row = [
                'notification_id' => $notificationId,
                'recipient_id'    => $recipientId,
                'event_type'      => $eventType,
                'channel'         => $channel,
                'template_key'    => $templateKey,
                'locale'          => $locale,
                'status'          => $status,
            ];
            if ($channel === 'email')    $row['recipient_email'] = $recipientContact;
            if ($channel === 'whatsapp') $row['recipient_phone'] = $recipientContact;
            if ($status === 'sent') $row['sent_at'] = date('Y-m-d H:i:s');
            $db->insert('notification_dispatches', $row);
            return (int) $db->lastInsertId();
        } catch (Throwable $e) {
            error_log('[NotificationCenter::logDispatch] ' . $e->getMessage());
            return null;
        }
    }

    public static function activeBanner(string $audience = 'all'): ?array
    {
        try {
            $db = Database::getInstance();
            $row = $db->fetchOne(
                "SELECT * FROM system_banners
                 WHERE enabled = 1
                   AND starts_at <= NOW()
                   AND (ends_at IS NULL OR ends_at > NOW())
                   AND (audience = 'all' OR audience = :a)
                 ORDER BY severity = 'critical' DESC, starts_at DESC
                 LIMIT 1",
                ['a' => $audience]
            );
            return $row ?: null;
        } catch (Throwable $e) { return null; }
    }
}
