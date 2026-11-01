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
foreach (['Auth', 'Gpx', 'Prompts', 'BatchLogs', 'Db'] as $cls) {
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

    check('user_version aktuell', (int) $pdo->query('PRAGMA user_version')->fetchColumn() >= 1);
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
    if ($versionBefore < (int) $pdo->query("PRAGMA user_version")->fetchColumn()) {
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

}

// Frische DB (Neuinstallation)
$fresh = new Db(tmpdir() . '/fresh.sqlite');
check('frische DB: user_version aktuell', (int) $fresh->pdo()->query('PRAGMA user_version')->fetchColumn() >= 1);
$id = $fresh->createTour([['code' => 'GC1', 'name' => 'A']], '<gpx/>', ['companions' => ['die-steffi']]);
$t  = $fresh->getTour($id);
check('frische DB: Tour mit Datum + Begleiter', $t['tour_date'] === Db::today() && $t['caches'][0]['visited_at'] === Db::today()
    && (int) $fresh->pdo()->query('SELECT COUNT(*) FROM tour_companion')->fetchColumn() === 1);
check('frische DB: kein Migrations-Backup (nur das vor der Tour-Anlage)',
    count(glob(dirname((string) $fresh->pdo()->query('PRAGMA database_list')->fetch()['file']) . '/backup/*.sqlite') ?: []) === 1);

// ---------------------------------------------------------------------------
section('Login: Angemeldet-bleiben-Token (Schritt 3)');

$authDb = tmpdir() . '/auth.sqlite';
$hashA  = password_hash('alt', PASSWORD_DEFAULT);
$hashB  = password_hash('neu', PASSWORD_DEFAULT);
$probe  = static function (string $hash, string $action, string $token = '') use ($authDb, $root): array {
    $cmd = implode(' ', array_map('escapeshellarg', [PHP_BINARY, $root . '/tests/auth_probe.php', $authDb, $hash, $action, $token]));
    return json_decode((string) shell_exec($cmd . ' 2>&1'), true) ?? [];
};

$token = (string) ($probe($hashA, 'issue')['token'] ?? '');
check('Token erzeugt (64 Hex-Zeichen)', (bool) preg_match('/^[0-9a-f]{64}$/', $token));
$authPdo = new PDO('sqlite:' . $authDb);
$row = $authPdo->query('SELECT * FROM auth_token')->fetch(PDO::FETCH_ASSOC) ?: [];
check('DB speichert nur den Hash', ($row['token_hash'] ?? '') === hash('sha256', $token) && !in_array($token, $row, true));
check('Ablauf ~180 Tage', abs(strtotime($row['expires_at'] . ' UTC') - (time() + Auth::REMEMBER_DAYS * 86400)) < 60);

check('ohne Session, mit Token → angemeldet', ($probe($hashA, 'authed', $token)['authed'] ?? null) === true);
check('falsches Token → abgelehnt', ($probe($hashA, 'authed', str_repeat('0', 64))['authed'] ?? null) === false);

// Gleitender Ablauf: Token künstlich fast abgelaufen → Nutzung verlängert wieder auf 180 Tage
$authPdo->exec("UPDATE auth_token SET expires_at = datetime('now', '+1 day')");
$probe($hashA, 'authed', $token);
$exp = (string) $authPdo->query('SELECT expires_at FROM auth_token')->fetchColumn();
check('gleitende Verlängerung', strtotime($exp . ' UTC') > time() + 170 * 86400);

// Abgelaufenes Token
$authPdo->exec("UPDATE auth_token SET expires_at = datetime('now', '-1 minute')");
check('abgelaufenes Token → abgelehnt', ($probe($hashA, 'authed', $token)['authed'] ?? null) === false);
check('abgelaufenes Token aufgeräumt', (int) $authPdo->query('SELECT COUNT(*) FROM auth_token')->fetchColumn() === 0);

// Zwei Geräte; Logout trifft nur eines
$t1 = (string) ($probe($hashA, 'issue')['token'] ?? '');
$t2 = (string) ($probe($hashA, 'issue')['token'] ?? '');
$probe($hashA, 'logout', $t1);
check('Logout: Gerät 1 abgemeldet', ($probe($hashA, 'authed', $t1)['authed'] ?? null) === false);
check('Logout: Gerät 2 weiter angemeldet', ($probe($hashA, 'authed', $t2)['authed'] ?? null) === true);

// Passwortänderung macht alle Tokens ungültig
check('Passwort geändert → Token ungültig', ($probe($hashB, 'authed', $t2)['authed'] ?? null) === false);

// ---------------------------------------------------------------------------
section('Tour-API feldweise + GPX-Wiedererkennung (Schritt 4)');

$gpxFile = $root . '/spec/export-2026-11-01-13-07-47-2 Verlauf.gpx';
if (!is_file($gpxFile)) {
    skip('Test-GPX fehlt: ' . basename($gpxFile));
} else {
    $dir4 = tmpdir();
    $db4  = new Db($dir4 . '/t.sqlite');
    $xml  = (string) file_get_contents($gpxFile);
    $all  = Gpx::parse($xml);
    check('Test-GPX: 4 Caches', count($all) === 4);

    check('leere DB → match none', $db4->findMatch($all)['type'] === 'none');
    $tid = $db4->createTour($all, $xml, ['companions' => ['die-steffi', 'Clicki17']]);
    $t   = $db4->getTour($tid);
    check('Tour angelegt, 4 Caches mit ID', count($t['caches']) === 4 && $t['caches'][0]['id'] > 0);
    check('Tour-Datum = heute, Caches übernehmen es', $t['tour_date'] === Db::today() && $t['caches'][3]['visited_at'] === Db::today());
    check('rohe GPX gespeichert', $t['has_gpx_raw']
        && gzuncompress((string) $db4->pdo()->query("SELECT gpx_raw FROM tour WHERE id = $tid")->fetchColumn()) === $xml);
    check('codes_hash gesetzt', $t['codes_hash'] === Db::codesHash(array_column($all, 'code')));
    check('getTour ohne Binärdaten JSON-fähig', json_encode($t) !== false && !array_key_exists('gpx_raw', $t));
    check('Begleiter in tour_companion', (int) $db4->pdo()->query("SELECT COUNT(*) FROM tour_companion WHERE tour_id = $tid")->fetchColumn() === 2);
    check('Backup vor Anlage', count(glob($dir4 . '/backup/*.sqlite') ?: []) === 1);

    // Wiedererkennung: gleiche Codes (Reihenfolge egal) → exact; 1 neuer bei 3 bekannten → partial
    $m = $db4->findMatch(array_reverse($all));
    check('gleiche GPX → exact', $m['type'] === 'exact' && $m['tour_id'] === $tid);
    $three = array_slice($all, 0, 3);
    $tid3  = $db4->createTour($three, $xml);
    $db4->deleteTour($tid);
    $m = $db4->findMatch($all);
    check('3 von 4 bekannt → partial', $m['type'] === 'partial' && $m['known'] === 3 && $m['total'] === 4 && $m['tour_id'] === $tid3);
    $other = $all;
    $other[0]['code'] = 'GCNEU1'; $other[1]['code'] = 'GCNEU2'; $other[2]['code'] = 'GCNEU3';
    check('1 von 4 bekannt → none', $db4->findMatch($other)['type'] === 'none');

    // Feldweise Updates
    $t3   = $db4->getTour($tid3);
    $cid  = $t3['caches'][0]['id'];
    $db4->pdo()->exec("UPDATE tour SET updated_at = '2000-01-01 00:00:00' WHERE id = $tid3");
    $res  = $db4->updateCache($cid, ['notes' => 'Dose im Baum', 'evil' => 'x']);
    $t3b  = $db4->getTour($tid3);
    check('update_cache: nur Notiz geändert', $t3b['caches'][0]['notes'] === 'Dose im Baum'
        && $t3b['caches'][0]['log'] === '' && $t3b['caches'][1]['notes'] === '');
    check('update_cache: tour.updated_at steigt', $res['updated_at'] > '2000-01-01 00:00:00');
    $db4->updateCache($cid, ['log_type' => "Didn't find it", 'done' => true]);
    $c0 = $db4->getTour($tid3)['caches'][0];
    check('update_cache: Status + done, manual_fields gemerkt', $c0['log_type'] === "Didn't find it" && $c0['done'] === true
        && $c0['manual_fields'] === ['log_type']);
    check('update_cache: unbekannte ID → null', $db4->updateCache(999999, ['notes' => 'x']) === null);

    $db4->updateTour($tid3, ['tour_info' => 'Regen', 'companions' => ['emblinux', ' '], 'context' => 'K', 'id' => 5]);
    $t3c = $db4->getTour($tid3);
    check('update_tour: Felder + Begleiter', $t3c['tour_info'] === 'Regen' && $t3c['companions'] === ['emblinux'] && $t3c['id'] === $tid3
        && $db4->pdo()->query("SELECT group_concat(name) FROM tour_companion WHERE tour_id = $tid3")->fetchColumn() === 'emblinux');

    $ids = array_reverse(array_column($t3c['caches'], 'id'));
    $db4->reorder($tid3, $ids);
    $t3d = $db4->getTour($tid3);
    check('reorder: neue Reihenfolge + manually_sorted', array_column($t3d['caches'], 'id') === $ids && $t3d['manually_sorted']);
    $threw = false;
    try { $db4->reorder($tid3, array_slice($ids, 1)); } catch (InvalidArgumentException) { $threw = true; }
    check('reorder: unvollständige Liste abgelehnt', $threw);

    // Re-Upload: GPX-Felder auffrischen, Nutzerdaten bleiben; add hängt Neues an
    $db4->pdo()->exec("UPDATE cache_log SET gpx = json_set(gpx, '$.country', '') WHERE tour_id = $tid3");
    $added = $db4->mergeGpx($tid3, $all, $xml, false);
    $t3e   = $db4->getTour($tid3);
    $byId  = array_column($t3e['caches'], null, 'id');
    check('refresh: nichts angehängt', $added === 0 && count($t3e['caches']) === 3);
    check('refresh: Land wieder da', $byId[$cid]['country'] !== '' && $byId[$cid]['country'] === $all[0]['country']);
    check('refresh: Notiz/Status/Reihenfolge bleiben', $byId[$cid]['notes'] === 'Dose im Baum'
        && $byId[$cid]['log_type'] === "Didn't find it" && array_column($t3e['caches'], 'id') === $ids);
    $added = $db4->mergeGpx($tid3, $all, $xml, true);
    $t3f   = $db4->getTour($tid3);
    check('add: 1 Cache hinten angehängt', $added === 1 && count($t3f['caches']) === 4
        && end($t3f['caches'])['code'] === $all[3]['code']);
    check('add: codes_hash aktualisiert → jetzt exact', $db4->findMatch($all)['type'] === 'exact');

    for ($i = 0; $i < 4; $i++) {
        $db4->createTour($three, $xml);
    }
    check('Backups rollierend max. ' . Db::BACKUP_KEEP, count(glob($dir4 . '/backup/*.sqlite') ?: []) === Db::BACKUP_KEEP);
}

if (isset($dbPath) && $dbPath !== null) {
    $real = new Db($dbPath);
    check('lokale DB-Kopie: codes_hash für alle Touren nachgetragen',
        (int) $real->pdo()->query('SELECT COUNT(*) FROM tour WHERE codes_hash IS NULL')->fetchColumn() === 0);
}

// ---------------------------------------------------------------------------
echo "\n" . ($fails === 0 ? '✓ 0 Fehler' : "✗ {$fails} Fehler") . ($skips ? ", {$skips} übersprungen" : '') . "\n";
exit($fails === 0 ? 0 : 1);
