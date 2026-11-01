<?php

declare(strict_types=1);

/**
 * Passwortschutz (Einzelnutzer): PHP-Session plus „Angemeldet bleiben"-Token je Gerät.
 *
 * Das Token liegt im Cookie, in der DB nur sein SHA-256-Hash. Fehlt die Session, aber
 * das Token ist gültig, wird die Session neu aufgebaut und der Ablauf gleitend
 * verlängert. Jedes Token merkt sich einen Fingerabdruck des Passwort-Hashes aus
 * config.php — nach einer Passwortänderung sind alle Tokens ungültig.
 */
final class Auth
{
    public const REMEMBER_COOKIE = 'gc_remember';
    public const REMEMBER_DAYS   = 180;

    /** @var (Closure(): Db)|null */
    private static ?Closure $dbFactory = null;
    private static string $passwordHash = '';

    /** @param Closure(): Db $dbFactory DB wird nur geöffnet, wenn ein Token gebraucht wird */
    public static function init(Closure $dbFactory, string $passwordHash): void
    {
        self::$dbFactory    = $dbFactory;
        self::$passwordHash = $passwordHash;
    }

    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
                'cookie_secure'   => self::isHttps(),
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
            $_SESSION['pw_fp']  = self::fingerprint();
            if (self::$dbFactory !== null) {
                self::setCookie(self::issueToken((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')));
                $_SESSION['token_touched'] = time();
            }
            return true;
        }
        return false;
    }

    public static function isAuthed(): bool
    {
        self::start();
        if (!empty($_SESSION['authed'])) {
            if (($_SESSION['pw_fp'] ?? '') !== self::fingerprint()) {
                $_SESSION = [];       // Passwort wurde geändert
                return false;
            }
            // Gleitenden Ablauf auch bei langer Session höchstens einmal täglich nachziehen
            if (isset($_COOKIE[self::REMEMBER_COOKIE]) && time() - (int) ($_SESSION['token_touched'] ?? 0) > 86400) {
                if (self::useToken((string) $_COOKIE[self::REMEMBER_COOKIE])) {
                    self::setCookie((string) $_COOKIE[self::REMEMBER_COOKIE]);
                }
                $_SESSION['token_touched'] = time();
            }
            return true;
        }

        $token = (string) ($_COOKIE[self::REMEMBER_COOKIE] ?? '');
        if ($token === '' || self::$dbFactory === null) {
            return false;
        }
        if (!self::useToken($token)) {
            self::clearCookie();
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['authed']        = true;
        $_SESSION['pw_fp']         = self::fingerprint();
        $_SESSION['token_touched'] = time();
        self::setCookie($token);
        return true;
    }

    /** Meldet nur dieses Gerät ab (Session + eigenes Token). */
    public static function logout(): void
    {
        self::start();
        $token = (string) ($_COOKIE[self::REMEMBER_COOKIE] ?? '');
        if ($token !== '' && self::$dbFactory !== null) {
            self::db()->pdo()->prepare('DELETE FROM auth_token WHERE token_hash = ?')->execute([hash('sha256', $token)]);
        }
        self::clearCookie();

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    /** Legt ein neues Token an und gibt den Klartext (für das Cookie) zurück. */
    public static function issueToken(string $userAgent): string
    {
        $token = bin2hex(random_bytes(32));
        self::db()->pdo()->prepare(
            'INSERT INTO auth_token (token_hash, pw_fingerprint, last_used_at, expires_at, user_agent)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            hash('sha256', $token),
            self::fingerprint(),
            self::utc(),
            self::utc(self::REMEMBER_DAYS * 86400),
            mb_substr($userAgent, 0, 255),
        ]);
        return $token;
    }

    /**
     * Prüft ein Token (gültig, nicht abgelaufen, Passwort unverändert) und verlängert
     * es gleitend. Räumt abgelaufene bzw. veraltete Tokens auf.
     */
    public static function useToken(string $token): bool
    {
        $pdo = self::db()->pdo();
        $pdo->prepare('DELETE FROM auth_token WHERE expires_at < ? OR pw_fingerprint <> ?')
            ->execute([self::utc(), self::fingerprint()]);

        $stmt = $pdo->prepare(
            'UPDATE auth_token SET last_used_at = ?, expires_at = ? WHERE token_hash = ?'
        );
        $stmt->execute([self::utc(), self::utc(self::REMEMBER_DAYS * 86400), hash('sha256', $token)]);
        return $stmt->rowCount() > 0;
    }

    private static function fingerprint(): string
    {
        return hash('sha256', self::$passwordHash);
    }

    private static function db(): Db
    {
        if (self::$dbFactory === null) {
            throw new RuntimeException('Auth::init() wurde nicht aufgerufen.');
        }
        return (self::$dbFactory)();
    }

    /** UTC-Zeitstempel im SQLite-Format (vergleichbar mit datetime('now')). */
    private static function utc(int $offsetSeconds = 0): string
    {
        return gmdate('Y-m-d H:i:s', time() + $offsetSeconds);
    }

    private static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    private static function setCookie(string $token): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie(self::REMEMBER_COOKIE, $token, [
            'expires'  => time() + self::REMEMBER_DAYS * 86400,
            'path'     => '/',
            'secure'   => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function clearCookie(): void
    {
        unset($_COOKIE[self::REMEMBER_COOKIE]);
        if (headers_sent()) {
            return;
        }
        setcookie(self::REMEMBER_COOKIE, '', [
            'expires'  => time() - 42000,
            'path'     => '/',
            'secure'   => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
