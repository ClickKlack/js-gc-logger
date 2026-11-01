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
section('GPX-Parser-Erweiterungen + Prompt (Schritt 6)');

foreach (glob($root . '/spec/*.gpx') ?: [] as $f) {
    $cs = Gpx::parse((string) file_get_contents($f));
    $isCgeo = str_contains((string) file_get_contents($f), 'c:geo');
    if ($isCgeo) {
        check(basename($f) . ': Owner + Attribute + Favoriten',
            array_filter($cs, fn ($c) => $c['owner'] === '' || !is_array($c['attributes']) || $c['fav_points'] === null) === []);
    }
}
$cg = $root . '/spec/export-2026-11-01-13-07-47-2 Verlauf.gpx';
if (is_file($cg)) {
    $byCode = array_column(Gpx::parse((string) file_get_contents($cg)), null, 'code');
    check('c:geo: verneintes Attribut bleibt lesbar', in_array('Nicht rollstuhlgerecht', $byCode['GCBPV6W']['attributes'] ?? [], true),
        json_encode($byCode['GCBPV6W']['attributes'] ?? null, JSON_UNESCAPED_UNICODE));
    check('c:geo: Favoritenpunkte 11', ($byCode['GCBPV6W']['fav_points'] ?? null) === 11);
}

$pq = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<gpx xmlns="http://www.topografix.com/GPX/1/0" creator="Groundspeak Pocket Query"
     xmlns:groundspeak="http://www.groundspeak.com/cache/1/0/1" xmlns:gsak="http://www.gsak.net/xmlv1/6">
  <wpt lat="54.1" lon="9.1"><name>GCAAA01</name>
    <groundspeak:cache><groundspeak:name>Mit Logs</groundspeak:name>
      <groundspeak:placed_by>deichbehren, DinoSijoru</groundspeak:placed_by><groundspeak:owner>deichbehren</groundspeak:owner>
      <groundspeak:type>Traditional Cache</groundspeak:type>
      <groundspeak:attributes>
        <groundspeak:attribute id="24" inc="0">Wheelchair accessible</groundspeak:attribute>
        <groundspeak:attribute id="6" inc="1">Recommended for kids</groundspeak:attribute>
      </groundspeak:attributes>
      <groundspeak:logs>
        <groundspeak:log id="1"><groundspeak:date>2026-10-30T19:00:00Z</groundspeak:date><groundspeak:type>Found it</groundspeak:type>
          <groundspeak:finder>Fremder</groundspeak:finder><groundspeak:text>GEHEIMER FREMDTEXT</groundspeak:text></groundspeak:log>
        <groundspeak:log id="2"><groundspeak:date>2026-10-31T08:00:00Z</groundspeak:date><groundspeak:type>Found it</groundspeak:type>
          <groundspeak:finder>Clicki17</groundspeak:finder><groundspeak:text>BEGLEITERTEXT</groundspeak:text></groundspeak:log>
        <groundspeak:log id="3"><groundspeak:date>2026-10-20T08:00:00Z</groundspeak:date><groundspeak:type>Write note</groundspeak:type>
          <groundspeak:finder>clickklack</groundspeak:finder><groundspeak:text>alt</groundspeak:text></groundspeak:log>
        <groundspeak:log id="4"><groundspeak:date>2026-10-31T08:00:00Z</groundspeak:date><groundspeak:type>Didn't find it</groundspeak:type>
          <groundspeak:finder>ClickKlack</groundspeak:finder><groundspeak:text>EIGENER TEXT</groundspeak:text></groundspeak:log>
      </groundspeak:logs>
    </groundspeak:cache>
    <gsak:wptExtension><gsak:FavPoints>42</gsak:FavPoints><gsak:GcNote>FINAL N 54 12.345 E 009 01.234</gsak:GcNote></gsak:wptExtension>
  </wpt>
  <wpt lat="54.2" lon="9.2"><name>GCAAA02</name>
    <groundspeak:cache><groundspeak:name>Ein Event</groundspeak:name><groundspeak:type>Event Cache</groundspeak:type></groundspeak:cache>
    <gsak:wptExtension><gsak:UserFound>2026-10-29T00:00:00Z</gsak:UserFound></gsak:wptExtension>
  </wpt>
  <wpt lat="54.3" lon="9.3"><name>GCAAA03</name>
    <groundspeak:cache><groundspeak:name>Webcam</groundspeak:name><groundspeak:type>Webcam Cache</groundspeak:type></groundspeak:cache>
  </wpt>
</gpx>
XML;
$p = array_column(Gpx::parse($pq, 'ClickKlack', ['die-steffi', 'Clicki17']), null, 'code');
$a = $p['GCAAA01'];
check('Owner getrennt von placed_by', $a['owner'] === 'deichbehren' && $a['placed_by'] === 'deichbehren, DinoSijoru');
check('PQ: verneintes Attribut markiert', $a['attributes'] === ['nicht: Wheelchair accessible', 'Recommended for kids'],
    json_encode($a['attributes']));
check('Favoritenpunkte aus gsak', $a['fav_points'] === 42);
check('eigenes Log: neuestes, Name ohne Groß/Klein, mit Uhrzeit',
    $a['own_log'] === ['type' => "Didn't find it", 'date' => '2026-10-31', 'ts' => '2026-10-31T08:00:00Z'], json_encode($a['own_log']));
check('Begleiter-Log ohne Text', $a['companion_logs'] === [['name' => 'Clicki17', 'type' => 'Found it', 'date' => '2026-10-31']]);
$dump = json_encode($p, JSON_UNESCAPED_UNICODE);
check('GcNote nirgends im Ergebnis', !str_contains($dump, 'FINAL') && !str_contains($dump, '12.345'));
check('keine Logtexte im Ergebnis', !str_contains($dump, 'GEHEIMER') && !str_contains($dump, 'BEGLEITERTEXT') && !str_contains($dump, 'EIGENER'));
check('Fundstatus (gsak:UserFound) als Fallback, 00:00 ohne Uhrzeit',
    $p['GCAAA02']['own_log'] === ['type' => 'Found it', 'date' => '2026-10-29', 'ts' => null], json_encode($p['GCAAA02']['own_log']));

check('Start-Status: eigenes Log', Db::defaultLogType($a) === "Didn't find it");
check('Start-Status: Event → Attended', Db::defaultLogType(['type' => 'Event Cache']) === 'Attended');
check('Start-Status: Event mit Fundstatus → Attended statt Found it', Db::defaultLogType($p['GCAAA02']) === 'Attended');
check('Start-Status: Webcam', Db::defaultLogType($p['GCAAA03']) === 'Webcam Photo Taken');
check('Start-Status: sonst Found it', Db::defaultLogType(['type' => 'Traditional Cache']) === 'Found it');

$db6 = new Db(tmpdir() . '/p.sqlite');
$t6  = $db6->getTour($db6->createTour(array_values($p), $pq, ['tour_date' => '2026-11-01']));
$c6  = array_column($t6['caches'], null, 'code');
check('Tour: Status/Datum/Uhrzeit aus eigenem Log', $c6['GCAAA01']['log_type'] === "Didn't find it"
    && $c6['GCAAA01']['visited_at'] === '2026-10-31' && $c6['GCAAA01']['visited_ts'] === '2026-10-31T08:00:00Z');
check('Tour: eigenes Log → erledigt', $c6['GCAAA01']['done'] === true && $c6['GCAAA02']['done'] === true && $c6['GCAAA03']['done'] === false);
check('Tour: Fundstatus ohne Uhrzeit → visited_ts leer', $c6['GCAAA02']['visited_ts'] === null && $c6['GCAAA02']['visited_at'] === '2026-10-29');

// Uhrzeit nahe Mitternacht: Datum in Ortszeit (23:30Z am 31.10. = 00:30 MEZ am 1.11.)
check('localDate: UTC → Europe/Berlin', Db::localDate('2026-10-31T23:30:00Z') === '2026-11-01' && Db::localDate('2026-07-01T21:30:00Z') === '2026-07-01');

// Re-Upload: eigenes Log ergänzt nur Fehlendes (Uhrzeit, erledigt); Status/Datum bleiben
$pdo6 = $db6->pdo();
$id6  = (int) $c6['GCAAA01']['id'];
$pdo6->exec("UPDATE cache_log SET done = 0, visited_ts = NULL, log_type = 'Found it', visited_at = '2026-11-01' WHERE id = $id6");
$db6->mergeGpx((int) $t6['id'], array_values($p), $pq, false);
$r6 = array_column($db6->getTour((int) $t6['id'])['caches'], null, 'code')['GCAAA01'];
check('Re-Upload: Uhrzeit + erledigt ergänzt', $r6['visited_ts'] === '2026-10-31T08:00:00Z' && $r6['done'] === true);
check('Re-Upload: Status/Datum unverändert', $r6['log_type'] === 'Found it' && $r6['visited_at'] === '2026-11-01');
$pdo6->exec("UPDATE cache_log SET visited_ts = '2026-10-31T09:15:00Z' WHERE id = $id6");
$db6->mergeGpx((int) $t6['id'], array_values($p), $pq, false);
$r6 = array_column($db6->getTour((int) $t6['id'])['caches'], null, 'code')['GCAAA01'];
check('Re-Upload: vorhandene Uhrzeit bleibt', $r6['visited_ts'] === '2026-10-31T09:15:00Z');
check('Tour: Begleiter-Log gespeichert', $c6['GCAAA01']['companion_logs'][0]['name'] === 'Clicki17');
check('Tour: ohne eigenes Log → Tour-Datum', $c6['GCAAA03']['visited_at'] === '2026-11-01' && $c6['GCAAA03']['log_type'] === 'Webcam Photo Taken');

$prompt = Prompts::log('Kontext', $a + ['log_type' => "Didn't find it"], 'Notiz');
check('Prompt: Owner für Dank + Gelegt von', str_contains($prompt, 'Owner (für den Dank): deichbehren') && str_contains($prompt, 'Gelegt von: deichbehren, DinoSijoru'));
check('Prompt: Attribute + Favoriten', str_contains($prompt, 'Attribute: nicht: Wheelchair accessible, Recommended for kids') && str_contains($prompt, 'Favoritenpunkte: 42'));
check('Prompt: DNF-Anweisung', str_contains($prompt, 'NICHT GEFUNDEN (DNF)'));
check('Prompt: keine GcNote/Logtexte', !str_contains($prompt, 'FINAL') && !str_contains($prompt, 'BEGLEITERTEXT'));
$batch = Prompts::logBatch('K', [$a + ['log_type' => 'Write note'], $p['GCAAA02'] + ['log_type' => 'Attended']]);
check('Sammel-Prompt: Status je Cache', str_contains($batch, 'LOG-STATUS: NOTIZ') && str_contains($batch, 'LOG-STATUS: EVENT BESUCHT'));
check('Prompt: Fund ist Normalfall', str_contains(Prompts::log('K', ['name' => 'X'], ''), 'LOG-STATUS: GEFUNDEN.'));

// ---------------------------------------------------------------------------
echo "\n" . ($fails === 0 ? '✓ 0 Fehler' : "✗ {$fails} Fehler") . ($skips ? ", {$skips} übersprungen" : '') . "\n";
exit($fails === 0 ? 0 : 1);
