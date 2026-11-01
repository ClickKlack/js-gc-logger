<?php
/**
 * Lädt config.php und alle Backend-Klassen. Gibt das $config-Array zurück.
 * Wird von public/index.php und public/api.php eingebunden.
 */

declare(strict_types=1);

$configPath = __DIR__ . '/../config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Konfiguration fehlt. Bitte config.example.php nach config.php kopieren und ausfüllen.");
}

/** @var array $config */
$config = require $configPath;

require __DIR__ . '/Auth.php';
require __DIR__ . '/Gpx.php';
require __DIR__ . '/FieldNotes.php';
require __DIR__ . '/FieldNotesExport.php';
require __DIR__ . '/Share.php';
require __DIR__ . '/GpxExport.php';
require __DIR__ . '/Gemini.php';
require __DIR__ . '/Prompts.php';
require __DIR__ . '/BatchLogs.php';
require __DIR__ . '/Db.php';

Auth::init(
    static function () use ($config): Db {
        static $db = null;
        return $db ??= new Db((string) $config['db_path']);
    },
    (string) ($config['password_hash'] ?? '')
);

return $config;
