<?php
/**
 * Smoke-Tests ohne Framework (CLI):  php tests/smoke.php
 *
 * Arbeitet nur auf einer temporären Kopie der lokalen DB (data/app.sqlite) und den
 * lokalen Testdateien in spec/ (gitignored). Fehlen sie, wird der Teiltest
 * übersprungen. Ruft nie Gemini auf. Exit-Code 1 bei Fehlern.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}

$root = dirname(__DIR__);
foreach (['Gpx', 'Prompts', 'BatchLogs', 'Db'] as $cls) {
    require $root . '/lib/' . $cls . '.php';
}

$fails = 0;
$skips = 0;

function section(string $title): void
{
    echo "\n== {$title}\n";
}

function check(string $label, bool $ok, string $detail = ''): void
{
    global $fails;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . ($ok || $detail === '' ? '' : '  → ' . $detail) . "\n";
    if (!$ok) {
        $fails++;
    }
}

function skip(string $label): void
{
    global $skips;
    echo "  – übersprungen: {$label}\n";
    $skips++;
}

/** Neues, leeres Temp-Verzeichnis (wird am Ende gelöscht). */
function tmpdir(): string
{
    $dir = sys_get_temp_dir() . '/gc_logger_smoke_' . bin2hex(random_bytes(4));
    mkdir($dir, 0775, true);
    register_shutdown_function(static function () use ($dir): void {
        exec('rm -rf ' . escapeshellarg($dir));
    });
    return $dir;
}

/** Konsistente Kopie der lokalen DB ohne sie zu migrieren; null, wenn keine da ist. */
function copyLocalDb(string $root, string $dir): ?string
{
    $src = $root . '/data/app.sqlite';
    if (!is_file($src)) {
        return null;
    }
    $target = $dir . '/app.sqlite';
    (new PDO('sqlite:' . $src))->prepare('VACUUM INTO ?')->execute([$target]);
    return $target;
}

// ---------------------------------------------------------------------------
section('DB: Migration, Backup, WAL (Schritt 1)');

$tmp    = tmpdir();
$dbPath = copyLocalDb($root, $tmp);
if ($dbPath === null) {
    skip('keine lokale DB data/app.sqlite');
} else {
    $raw        = new PDO('sqlite:' . $dbPath);
    $toursBefore = (int) $raw->query('SELECT COUNT(*) FROM tour')->fetchColumn();
    $cachesBefore = (int) $raw->query('SELECT COUNT(*) FROM cache_log')->fetchColumn();
    $versionBefore = (int) $raw->query('PRAGMA user_version')->fetchColumn();
    $raw = null;

    $db  = new Db($dbPath);
    $pdo = $db->pdo();
    $cols = static fn (string $t): array => $pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_COLUMN, 1);

    check('user_version = 1', (int) $pdo->query('PRAGMA user_version')->fetchColumn() === 1);
    check('journal_mode = wal', strtolower((string) $pdo->query('PRAGMA journal_mode')->fetchColumn()) === 'wal');
    $missing = array_diff(['updated_at', 'tour_date', 'gpx_hash', 'codes_hash', 'gpx_raw', 'manually_sorted'], $cols('tour'));
    check('tour: neue Spalten', $missing === [], implode(', ', $missing));
    $missing = array_diff(['log_type', 'visited_at', 'visited_ts', 'find_no', 'manual_fields', 'companion_logs', 'updated_at'], $cols('cache_log'));
    check('cache_log: neue Spalten', $missing === [], implode(', ', $missing));
    foreach (['tour_companion', 'auth_token', 'companion', 'setting'] as $t) {
        check("Tabelle $t", $cols($t) !== []);
    }
    check('Touren/Caches unverändert',
        (int) $pdo->query('SELECT COUNT(*) FROM tour')->fetchColumn() === $toursBefore
        && (int) $pdo->query('SELECT COUNT(*) FROM cache_log')->fetchColumn() === $cachesBefore);
    check('tour_date überall gesetzt', (int) $pdo->query('SELECT COUNT(*) FROM tour WHERE tour_date IS NULL')->fetchColumn() === 0);
    check('visited_at überall gesetzt', (int) $pdo->query('SELECT COUNT(*) FROM cache_log WHERE visited_at IS NULL')->fetchColumn() === 0);
    $withCompanions = (int) $pdo->query(
        "SELECT COUNT(*) FROM tour WHERE json_valid(companions) AND json_array_length(companions) > 0"
    )->fetchColumn();
    $backfilled = (int) $pdo->query('SELECT COUNT(DISTINCT tour_id) FROM tour_companion')->fetchColumn();
    check("tour_companion-Backfill ($backfilled/$withCompanions Touren)", $backfilled === $withCompanions);

    $backups = glob($tmp . '/backup/gc_logger-*.sqlite') ?: [];
    if ($versionBefore === 0) {
        check('Backup vor Migration angelegt', count($backups) === 1);
    }
    $db = null;
    $pdo = null;

    // Zweites Öffnen: idempotent, kein weiteres Backup
    $db2 = new Db($dbPath);
    check('zweites Öffnen idempotent, kein neues Backup', count(glob($tmp . '/backup/gc_logger-*.sqlite') ?: []) === count($backups));

    // Rotation: es bleiben BACKUP_KEEP Dateien, die neuesten
    $made = [];
    for ($i = 0; $i < Db::BACKUP_KEEP + 2; $i++) {
        $made[] = $db2->backup();
    }
    $left = glob($tmp . '/backup/gc_logger-*.sqlite') ?: [];
    sort($left);
    check('Rotation behält ' . Db::BACKUP_KEEP, count($left) === Db::BACKUP_KEEP);
    check('neueste Backups bleiben', array_slice($made, -Db::BACKUP_KEEP) === $left);
    $bk = new PDO('sqlite:' . end($left));
    check('Backup lesbar + vollständig', (int) $bk->query('SELECT COUNT(*) FROM tour')->fetchColumn() === $toursBefore);

    // Rundreise über den (Übergangs-)save erhält die neuen Felder
    $tourId = (int) $db2->pdo()->query('SELECT id FROM tour ORDER BY id DESC LIMIT 1')->fetchColumn();
    $tour   = $db2->getTour($tourId);
    $tour['caches'][0]['log_type'] = "Didn't find it";
    $db2->saveTour($tour);
    $again = $db2->getTour($tourId);
    check('save erhält log_type', $again['caches'][0]['log_type'] === "Didn't find it");
    check('save erhält visited_at', $again['caches'][0]['visited_at'] === $tour['caches'][0]['visited_at']);
}

// Frische DB (Neuinstallation)
$fresh = new Db(tmpdir() . '/fresh.sqlite');
check('frische DB: user_version = 1', (int) $fresh->pdo()->query('PRAGMA user_version')->fetchColumn() === 1);
$id = $fresh->saveTour(['title' => 'T', 'companions' => ['die-steffi'], 'caches' => [['code' => 'GC1', 'name' => 'A']]]);
$t  = $fresh->getTour($id);
check('frische DB: Tour mit Datum + Begleiter', $t['tour_date'] === Db::today() && $t['caches'][0]['visited_at'] === Db::today()
    && (int) $fresh->pdo()->query('SELECT COUNT(*) FROM tour_companion')->fetchColumn() === 1);
check('frische DB: kein Backup', !is_dir(dirname((string) $fresh->pdo()->query('PRAGMA database_list')->fetch()['file']) . '/backup'));

// ---------------------------------------------------------------------------
echo "\n" . ($fails === 0 ? '✓ 0 Fehler' : "✗ {$fails} Fehler") . ($skips ? ", {$skips} übersprungen" : '') . "\n";
exit($fails === 0 ? 0 : 1);
