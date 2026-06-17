<?php
declare(strict_types=1);

namespace BWFC\DailyBrief;

use TheNetworg\OAuth2\Client\Provider\Azure;

/**
 * Authentication — two modes:
 *
 * AUTH_ENABLED=false (local XAMPP)
 *   No-op. The seeded admin user is silently put into session.
 *
 * AUTH_ENABLED=true (server / production)
 *   Full Microsoft 365 / Azure AD OIDC flow via league/oauth2-client
 *   + thenetworg/oauth2-azure. Requires AZURE_* env vars.
 */
final class Auth
{
    public static function enabled(): bool
    {
        return env('AUTH_ENABLED', false) === true;
    }

    // ──────────────────────────────────────────────────────────────
    // Request guards
    // ──────────────────────────────────────────────────────────────

    public static function requireLogin(): void
    {
        if (!self::enabled()) {
            if (!isset($_SESSION['user_id'])) {
                $row = Database::selectOne(
                    "SELECT id, display_name, role FROM users WHERE email = 'comms@bwfc.co.uk' LIMIT 1"
                );
                if ($row !== null) {
                    $_SESSION['user_id']   = (int)$row['id'];
                    $_SESSION['user_name'] = (string)$row['display_name'];
                    $_SESSION['user_role'] = (string)$row['role'];
                    $_SESSION['user_email'] = 'comms@bwfc.co.uk';
                }
            }
            return;
        }

        if (!isset($_SESSION['user_id'])) {
            $_SESSION['auth_return'] = $_SERVER['REQUEST_URI'] ?? '/';
            header('Location: ' . self::authBase() . '/login.php');
            exit;
        }
    }

    public static function currentUser(): ?array
    {
        if (!isset($_SESSION['user_id'])) {
            return null;
        }
        return [
            'id'    => (int)$_SESSION['user_id'],
            'name'  => (string)($_SESSION['user_name'] ?? ''),
            'role'  => (string)($_SESSION['user_role'] ?? 'editor'),
            'email' => (string)($_SESSION['user_email'] ?? ''),
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // OAuth2 flow
    // ──────────────────────────────────────────────────────────────

    /**
     * Step 1 — redirect the browser to Microsoft's login page.
     * Called from public/auth/login.php.
     */
    public static function redirectToMicrosoft(): never
    {
        $provider = self::provider();

        $authUrl = $provider->getAuthorizationUrl([
            'scope' => ['openid', 'profile', 'email', 'User.Read'],
        ]);

        $_SESSION['oauth2_state'] = $provider->getState();
        header('Location: ' . $authUrl);
        exit;
    }

    /**
     * Step 2 — Microsoft redirects back with a code.
     * Exchange it for a token, fetch the user's profile, create/update
     * the local user record, and set the session.
     * Called from public/auth/callback.php.
     *
     * Returns the URL to redirect to after successful login.
     */
    public static function handleCallback(): string
    {
        // CSRF check
        if (empty($_GET['state']) || $_GET['state'] !== ($_SESSION['oauth2_state'] ?? '')) {
            unset($_SESSION['oauth2_state']);
            throw new \RuntimeException('OAuth2 state mismatch. Possible CSRF attack.');
        }
        unset($_SESSION['oauth2_state']);

        if (!empty($_GET['error'])) {
            $desc = htmlspecialchars((string)($_GET['error_description'] ?? $_GET['error']), ENT_QUOTES);
            throw new \RuntimeException('Microsoft login error: ' . $desc);
        }

        if (empty($_GET['code'])) {
            throw new \RuntimeException('No authorisation code returned by Microsoft.');
        }

        $provider = self::provider();
        $token    = $provider->getAccessToken('authorization_code', ['code' => $_GET['code']]);

        /** @var \TheNetworg\OAuth2\Client\Provider\AzureResourceOwner $me */
        $me    = $provider->getResourceOwner($token);
        $email = strtolower(trim((string)($me->getUpn() ?: $me->getEmail() ?: '')));
        $name  = trim((string)($me->getFirstName() . ' ' . $me->getLastName())) ?: $me->getDisplayName() ?: $email;
        $oid   = (string)($me->getId() ?? '');

        if ($email === '') {
            throw new \RuntimeException('Microsoft did not return an email address. Check API permissions.');
        }

        // Allowlist check — if AZURE_ALLOWED_EMAILS is set, only those addresses may log in
        $allowlist = (string)env('AZURE_ALLOWED_EMAILS', '');
        if ($allowlist !== '') {
            $allowed = array_map('trim', explode(',', strtolower($allowlist)));
            if (!in_array($email, $allowed, true)) {
                throw new \RuntimeException(
                    'Your account (' . $email . ') has not been granted access to the BWFC Daily Brief. '
                    . 'Contact the communications team administrator.'
                );
            }
        }

        // Upsert local user record
        $existing = Database::selectOne(
            "SELECT id, display_name, role FROM users WHERE email = :e LIMIT 1",
            ['e' => $email]
        );

        if ($existing === null) {
            $userId = Database::insertRow('users', [
                'email'          => $email,
                'display_name'   => $name,
                'role'           => 'editor',
                'm365_object_id' => $oid,
                'is_active'      => 1,
            ]);
            $role = 'editor';
        } else {
            $userId = (int)$existing['id'];
            $role   = (string)$existing['role'];
            Database::updateRow('users', [
                'display_name'   => $name,
                'm365_object_id' => $oid,
            ], ['id' => $userId]);
        }

        $_SESSION['user_id']    = $userId;
        $_SESSION['user_name']  = $name;
        $_SESSION['user_role']  = $role;
        $_SESSION['user_email'] = $email;

        $returnTo = $_SESSION['auth_return'] ?? '/';
        unset($_SESSION['auth_return']);

        return $returnTo;
    }

    /**
     * Destroy the local session and redirect to Microsoft's logout endpoint.
     * Called from public/auth/logout.php.
     */
    public static function logout(): never
    {
        session_destroy();

        $postLogoutUri = urlencode((string)env('APP_URL', 'http://localhost'));
        $tenantId      = (string)env('AZURE_TENANT_ID', 'common');
        $logoutUrl     = "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/logout"
                       . "?post_logout_redirect_uri={$postLogoutUri}";

        header('Location: ' . $logoutUrl);
        exit;
    }

    // ──────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────

    private static function provider(): Azure
    {
        $appUrl      = rtrim((string)env('APP_URL', ''), '/');
        $redirectUri = $appUrl . '/auth/callback.php';

        return new Azure([
            'clientId'                => (string)env('AZURE_CLIENT_ID', ''),
            'clientSecret'            => (string)env('AZURE_CLIENT_SECRET', ''),
            'redirectUri'             => $redirectUri,
            'tenant'                  => (string)env('AZURE_TENANT_ID', 'common'),
            'defaultEndPointVersion'  => Azure::ENDPOINT_VERSION_2_0,
        ]);
    }

    /** Base URL of the auth scripts (public/auth/) derived from the request. */
    private static function authBase(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        // Walk up from the current script to find /auth relative to public/
        $scriptDir = rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        // If script is in public/ subdir, auth is at public/auth/
        // If script IS in public/ (e.g. index.php), go to /auth/
        if (str_ends_with($scriptDir, '/auth')) {
            $base = $scriptDir;
        } else {
            $base = $scriptDir . '/auth';
        }
        return $scheme . '://' . $host . $base;
    }
}
