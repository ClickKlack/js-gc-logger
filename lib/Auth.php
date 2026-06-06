<?php

declare(strict_types=1);

/**
 * Einfacher Session-basierter Passwortschutz (Einzelnutzer).
 */
final class Auth
{
    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
            ]);
        }
    }

    public static function attempt(string $password, string $hash): bool
    {
        if ($hash === '') {
            return false;
        }
        if (password_verify($password, $hash)) {
            self::start();
            session_regenerate_id(true);
            $_SESSION['authed'] = true;
            return true;
        }
        return false;
    }

    public static function isAuthed(): bool
    {
        self::start();
        return !empty($_SESSION['authed']);
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }
}
