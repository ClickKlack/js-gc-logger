<?php
/**
 * Hilfsprozess für tests/smoke.php: führt genau eine Auth-Aktion in einem frischen
 * PHP-Prozess aus (eigene, leere Session) und gibt das Ergebnis als JSON aus.
 *
 *   php tests/auth_probe.php <db> <passwort-hash> issue
 *   php tests/auth_probe.php <db> <passwort-hash> authed <token>
 *   php tests/auth_probe.php <db> <passwort-hash> logout <token>
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}

[, $dbPath, $hash, $action] = $argv;
$token = $argv[4] ?? '';

require __DIR__ . '/../lib/Auth.php';
require __DIR__ . '/../lib/Db.php';

ini_set('session.use_cookies', '0');
ini_set('session.save_path', sys_get_temp_dir());
ob_start();

Auth::init(static fn (): Db => new Db($dbPath), $hash);
if ($token !== '') {
    $_COOKIE[Auth::REMEMBER_COOKIE] = $token;
}

$out = match ($action) {
    'issue'  => ['token' => Auth::issueToken('smoke')],
    'authed' => ['authed' => Auth::isAuthed()],
    'logout' => (function (): array { Auth::logout(); return ['ok' => true]; })(),
    default  => ['error' => 'unbekannte Aktion'],
};

ob_end_clean();
echo json_encode($out);
