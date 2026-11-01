<?php
/**
 * Router für den PHP-Entwicklungsserver (ersetzt public/.htaccess, die php -S nicht kennt):
 *   php -S localhost:8000 -t public tests/router.php
 * /share/TOKEN/ → share.php (liest den Token selbst aus der Adresse), sonst normal.
 */
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (preg_match('#^/share/[A-Za-z0-9_-]+/?$#', $path)) {
    require __DIR__ . '/../public/share.php';
    return true;
}
return false;
