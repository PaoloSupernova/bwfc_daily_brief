<?php
declare(strict_types=1);

namespace BWFC\DailyBrief;

/**
 * Lightweight audit logger. Called from API endpoints and repository methods
 * to capture who did what and when.
 */
final class AuditLog
{
    public static function record(
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        array $details = [],
        ?int $userId = null
    ): void {
        // Audit logging is best-effort: a logging failure (e.g. the audit_log
        // table being read-only / needing repair) must never break the actual
        // user action, so swallow any error here.
        try {
            $userId ??= self::currentUserId();
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;

            Database::insert(
                'INSERT INTO audit_log (user_id, action, entity_type, entity_id, details, ip_address)
                 VALUES (:u, :a, :et, :ei, :d, :ip)',
                [
                    'u' => $userId,
                    'a' => $action,
                    'et' => $entityType,
                    'ei' => $entityId,
                    'd' => count($details) > 0 ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
                    'ip' => $ip,
                ]
            );
        } catch (\Throwable $e) {
            error_log('AuditLog::record failed: ' . $e->getMessage());
        }
    }

    /**
     * On local XAMPP, use the seeded placeholder admin user.
     * On deployment with SSO, pull from session.
     */
    public static function currentUserId(): ?int
    {
        if (isset($_SESSION['user_id'])) {
            return (int)$_SESSION['user_id'];
        }

        $row = Database::selectOne(
            "SELECT id FROM users WHERE email = 'comms@bwfc.co.uk' LIMIT 1"
        );
        return $row === null ? null : (int)$row['id'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function forEntity(string $type, int $id, int $limit = 50): array
    {
        return Database::select(
            'SELECT al.*, u.display_name AS user_name
             FROM audit_log al
             LEFT JOIN users u ON u.id = al.user_id
             WHERE entity_type = :t AND entity_id = :i
             ORDER BY al.created_at DESC
             LIMIT ' . $limit,
            ['t' => $type, 'i' => $id]
        );
    }
}
