<?php
declare(strict_types=1);

namespace BWFC\DailyBrief;

/**
 * Authentication stub.
 *
 * Local XAMPP: no-op. Everyone is the seeded admin user.
 * Deployment: switch AUTH_ENABLED=true in .env and implement M365 OIDC
 * flow in authenticate() / callback() / logout().
 */
final class Auth
{
    public static function enabled(): bool
    {
        return env('AUTH_ENABLED', false) === true;
    }

    public static function requireLogin(): void
    {
        if (!self::enabled()) {
            // Local dev: seed the admin user into session silently
            if (!isset($_SESSION['user_id'])) {
                $row = Database::selectOne(
                    "SELECT id, display_name, role FROM users WHERE email = 'comms@bwfc.co.uk' LIMIT 1"
                );
                if ($row !== null) {
                    $_SESSION['user_id'] = (int)$row['id'];
                    $_SESSION['user_name'] = (string)$row['display_name'];
                    $_SESSION['user_role'] = (string)$row['role'];
                }
            }
            return;
        }

        // Production: redirect to M365 if no session
        if (!isset($_SESSION['user_id'])) {
            header('Location: /bwfc-daily-brief/public/auth/login.php');
            exit;
        }
    }

    public static function requireRole(string $role): void
    {
        self::requireLogin();
        $current = $_SESSION['user_role'] ?? 'viewer';

        $hierarchy = ['viewer' => 1, 'editor' => 2, 'admin' => 3];
        $needed = $hierarchy[$role] ?? 999;
        $have = $hierarchy[$current] ?? 0;

        if ($have < $needed) {
            http_response_code(403);
            echo 'Insufficient permissions';
            exit;
        }
    }

    public static function currentUser(): ?array
    {
        if (!isset($_SESSION['user_id'])) {
            return null;
        }
        return [
            'id' => (int)$_SESSION['user_id'],
            'name' => (string)($_SESSION['user_name'] ?? ''),
            'role' => (string)($_SESSION['user_role'] ?? 'viewer'),
        ];
    }
}
