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
require __DIR__ . '/Gemini.php';
require __DIR__ . '/Prompts.php';
require __DIR__ . '/BatchLogs.php';
require __DIR__ . '/Db.php';

return $config;
