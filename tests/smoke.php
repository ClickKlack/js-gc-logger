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
foreach (['Auth', 'Gpx', 'GpxExport', 'FieldNotes', 'FieldNotesExport', 'Share', 'Prompts', 'BatchLogs', 'Db'] as $cls) {
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

    check('Bestand: Events/Webcams nicht mehr „Found it"', (int) $pdo->query(
        "SELECT COUNT(*) FROM cache_log WHERE log_type = 'Found it' AND COALESCE(manual_fields, '') NOT LIKE '%log_type%'
           AND (lower(json_extract(gpx, '$.type')) LIKE '%event%' OR lower(json_extract(gpx, '$.type')) LIKE '%webcam%')"
    )->fetchColumn() === 0);
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
section('Status, Datum, Tour-Datum (Schritt 7)');

$db7  = new Db(tmpdir() . '/s7.sqlite');
$tid7 = $db7->createTour([
    ['code' => 'GC7A', 'name' => 'A', 'type' => 'Traditional Cache'],
    ['code' => 'GC7B', 'name' => 'B', 'type' => 'Traditional Cache'],
    ['code' => 'GC7C', 'name' => 'C', 'type' => 'Traditional Cache'],
    ['code' => 'GC7D', 'name' => 'D', 'type' => 'Traditional Cache',
     'own_log' => ['type' => 'Found it', 'date' => '2026-10-30', 'ts' => '2026-10-30T10:00:00Z']],
], '<gpx/>', ['tour_date' => '2026-11-02']);
$c7 = array_column($db7->getTour($tid7)['caches'], null, 'code');
$db7->updateCache($c7['GC7B']['id'], ['visited_at' => '2026-11-02']);   // von Hand bestätigt
$db7->updateCache($c7['GC7C']['id'], ['visited_ts' => '2026-11-02T09:00:00Z'], false);
$db7->updateTour($tid7, ['tour_date' => '2026-11-01']);
$c7 = array_column($db7->getTour($tid7)['caches'], null, 'code');
check('Tour-Datum zieht Caches mit altem Datum mit', $c7['GC7A']['visited_at'] === '2026-11-01');
check('… außer von Hand gesetztes Datum', $c7['GC7B']['visited_at'] === '2026-11-02');
check('… außer Caches mit Uhrzeit', $c7['GC7C']['visited_at'] === '2026-11-02' && $c7['GC7D']['visited_at'] === '2026-10-30');
check('Tour-Datum gespeichert', $db7->getTour($tid7)['tour_date'] === '2026-11-01');

$bad = static function (callable $fn): bool {
    try { $fn(); } catch (InvalidArgumentException) { return true; }
    return false;
};
check('ungültiges Tour-Datum abgelehnt', $bad(fn () => $db7->updateTour($tid7, ['tour_date' => '2026-13-01'])));
check('unbekannter Log-Typ abgelehnt', $bad(fn () => $db7->updateCache($c7['GC7A']['id'], ['log_type' => 'Needs Maintenance'])));
check('ungültiges Datum abgelehnt', $bad(fn () => $db7->updateCache($c7['GC7A']['id'], ['visited_at' => '1.11.2026'])));
check('ungültiger Zeitpunkt abgelehnt', $bad(fn () => $db7->updateCache($c7['GC7A']['id'], ['visited_ts' => '2026-11-01 10:00'])));
$db7->updateCache($c7['GC7A']['id'], ['log_type' => 'Write note', 'visited_ts' => '2026-11-01T13:05:00Z', 'done' => false]);
$db7->updateCache($c7['GC7D']['id'], ['visited_ts' => null]);
$c7 = array_column($db7->getTour($tid7)['caches'], null, 'code');
check('gültige Werte gespeichert, manuell gemerkt', $c7['GC7A']['log_type'] === 'Write note' && $c7['GC7A']['visited_ts'] === '2026-11-01T13:05:00Z'
    && $c7['GC7A']['done'] === false && in_array('visited_ts', $c7['GC7A']['manual_fields'], true));
check('Uhrzeit löschbar', $c7['GC7D']['visited_ts'] === null);
check('createTour: ungültiges Tour-Datum → heute', $db7->getTour($db7->createTour([['code' => 'GC7E', 'name' => 'E']], '<gpx/>', ['tour_date' => 'xx']))['tour_date'] === Db::today());

// ---------------------------------------------------------------------------
section('c:geo-Feldnotizen (Schritt 8)');

$fn2 = $root . '/spec/20261101130803.txt';
$gpx8 = $root . '/spec/export-2026-11-01-13-07-47-2 Verlauf.gpx';
if (!is_file($fn2) || !is_file($gpx8)) {
    skip('Test-Feldnotizen oder GPX fehlen');
} else {
    $raw = (string) file_get_contents($fn2);
    $e   = FieldNotes::parse($raw);
    $by  = array_column($e, null, 'code');
    check('4 Einträge, sortiert nach Zeit', array_column($e, 'code') === ['GC7C18Z', 'GCBPV6W', 'GC7C24G', 'GCBQDDG'],
        implode(',', array_column($e, 'code')));
    check('Log-Typen', $by['GC7C18Z']['log_type'] === 'Found it' && $by['GC7C24G']['log_type'] === "Didn't find it"
        && $by['GCBQDDG']['log_type'] === 'Write note');
    check('Fundnummern 11578/11579/11580/11580', [$by['GC7C18Z']['find_no'], $by['GCBPV6W']['find_no'], $by['GC7C24G']['find_no'],
        $by['GCBQDDG']['find_no']] === [11578, 11579, 11580, 11580]);
    check('Zeitstempel UTC + Ortszeit der Nummernzeile', $by['GCBPV6W']['ts'] === '2026-11-01T11:56:04Z' && $by['GCBPV6W']['local_time'] === '12:56');
    check('„# " im Cache-Namen keine Fehl-Nummer', $by['GC7C18Z']['find_no'] === 11578);

    // Toleranz: BOM, UTF-8, CRLF, "" im Text, mehrere Einträge je Code
    $utf8 = mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
    check('UTF-16 LE mit BOM', FieldNotes::parse("\xFF\xFE" . $raw) == $e);
    check('UTF-16 BE mit BOM', FieldNotes::parse("\xFE\xFF" . mb_convert_encoding($utf8, 'UTF-16BE', 'UTF-8')) == $e);
    check('UTF-8 ohne BOM, CRLF', FieldNotes::parse(str_replace("\n", "\r\n", $utf8)) == $e);
    $extra = "GC7C18Z,2026-11-01T10:00:00Z,Didn't find it,\"Er sagte \"\"hallo\"\"\n\n*#11570 . 11:00*\"\n";
    $e2 = array_column(FieldNotes::parse($extra . $utf8), null, 'code');
    check('je Code gilt der neueste Eintrag', $e2['GC7C18Z']['log_type'] === 'Found it' && $e2['GC7C18Z']['find_no'] === 11578);
    $e3 = FieldNotes::parse($extra)[0];
    check('"" im Text, Trenner „." ohne „Uhr"', $e3['find_no'] === 11570 && $e3['local_time'] === '11:00');
    $threw = false;
    try { FieldNotes::parse("kein,csv\n"); } catch (RuntimeException) { $threw = true; }
    check('Datei ohne Feldnotizen → Fehler', $threw);

    // Anwenden auf Tour aus der passenden GPX (GPX-Reihenfolge ≠ Fundreihenfolge)
    $xml8 = (string) file_get_contents($gpx8);
    $db8  = new Db(tmpdir() . '/fn.sqlite');
    $all8 = array_merge(Gpx::parse($xml8), [['code' => 'GCOHNE1', 'name' => 'ohne Feldnotiz', 'type' => 'Traditional Cache']]);
    $tid8 = $db8->createTour($all8, $xml8, ['tour_date' => '2026-11-01']);
    check('GPX-Reihenfolge weicht ab', array_column($db8->getTour($tid8)['caches'], 'code')[0] === 'GC7C24G');
    $c8 = array_column($db8->getTour($tid8)['caches'], null, 'code');
    $db8->updateCache($c8['GCBPV6W']['id'], ['log_type' => 'Write note']);      // von Hand gesetzt

    $st = $db8->applyFieldNotes($tid8, FieldNotes::parse($utf8 . "GCFREMD,2026-11-01T09:00:00Z,Found it,\"x\"\n"));
    check('Statistik: 4 zugeordnet, 1 unbekannt, 1 ohne Notiz, 1 manuell', $st['matched'] === 4 && $st['unknown'] === ['GCFREMD']
        && $st['missing'] === 1 && $st['kept_manual'] === 1, json_encode($st));
    $c8 = array_column($db8->getTour($tid8)['caches'], null, 'code');
    check('Status übernommen (DNF, Notiz)', $c8['GC7C24G']['log_type'] === "Didn't find it" && $c8['GCBQDDG']['log_type'] === 'Write note');
    check('manueller Status bleibt', $c8['GCBPV6W']['log_type'] === 'Write note');
    check('Uhrzeit, Datum, Fundnummer', $c8['GC7C18Z']['visited_ts'] === '2026-11-01T11:49:07Z' && $c8['GC7C18Z']['visited_at'] === '2026-11-01'
        && $c8['GC7C18Z']['find_no'] === 11578 && $c8['GC7C18Z']['fieldnote_ts'] === '2026-11-01T11:49:07Z');
    check('ohne Feldnotiz: unverändert, markierbar', $c8['GCOHNE1']['fieldnote_ts'] === null && $db8->getTour($tid8)['fieldnotes_at'] !== null);

    $db8->sortByTime($tid8);
    $order = array_column($db8->getTour($tid8)['caches'], 'code');
    check('Sortierung nach Zeitstempel, ohne Notiz ans Ende', $order === ['GC7C18Z', 'GCBPV6W', 'GC7C24G', 'GCBQDDG', 'GCOHNE1'], implode(',', $order));
    check('manually_sorted zurückgesetzt', $db8->getTour($tid8)['manually_sorted'] === false);

    // Mehrtagestour: Uhrzeit aus anderer Quelle (eigenes Log) bzw. nur Datum sortieren mit ein
    $db8->pdo()->exec("UPDATE cache_log SET visited_at = '2026-10-31', visited_ts = '2026-10-31T14:33:39Z' WHERE tour_id = $tid8 AND code = 'GCOHNE1'");
    $db8->sortByTime($tid8);
    $order = array_column($db8->getTour($tid8)['caches'], 'code');
    check('Uhrzeit ohne Feldnotiz (Vortag) → vorn', $order[0] === 'GCOHNE1', implode(',', $order));
    $db8->pdo()->exec("UPDATE cache_log SET visited_ts = NULL WHERE tour_id = $tid8 AND code = 'GCOHNE1'");
    $db8->sortByTime($tid8);
    $order = array_column($db8->getTour($tid8)['caches'], 'code');
    check('nur Datum (Vortag) → vor dem Folgetag', $order[0] === 'GCOHNE1', implode(',', $order));
    $db8->pdo()->exec("UPDATE cache_log SET visited_at = '2026-11-01' WHERE tour_id = $tid8 AND code = 'GCOHNE1'");
    $db8->sortByTime($tid8);
    $order = array_column($db8->getTour($tid8)['caches'], 'code');
    check('ohne Uhrzeit am selben Tag → nach den Caches mit Uhrzeit', end($order) === 'GCOHNE1', implode(',', $order));

    // Gleicher Zeitstempel → Fundnummer entscheidet; manuelle Uhrzeit zählt
    $db8->pdo()->exec("UPDATE cache_log SET visited_ts = '2026-11-01T12:00:00Z' WHERE tour_id = $tid8 AND code IN ('GCBQDDG', 'GC7C24G')");
    $db8->pdo()->exec("UPDATE cache_log SET find_no = 11581 WHERE tour_id = $tid8 AND code = 'GC7C24G'");
    $db8->sortByTime($tid8);
    $order = array_column($db8->getTour($tid8)['caches'], 'code');
    check('gleicher Zeitstempel → Fundnummer', array_slice($order, 2, 2) === ['GCBQDDG', 'GC7C24G'], implode(',', $order));

    // Erneuter Import überschreibt nur Nicht-Manuelles
    $c8 = array_column($db8->getTour($tid8)['caches'], null, 'code');
    $db8->updateCache($c8['GC7C18Z']['id'], ['visited_ts' => '2026-11-01T08:00:00Z']);
    $db8->applyFieldNotes($tid8, FieldNotes::parse($raw));
    $c8 = array_column($db8->getTour($tid8)['caches'], null, 'code');
    check('Re-Import: manuelle Uhrzeit bleibt, Rest aktualisiert', $c8['GC7C18Z']['visited_ts'] === '2026-11-01T08:00:00Z'
        && $c8['GCBQDDG']['visited_ts'] === '2026-11-01T12:07:27Z');
    $fn1 = $root . '/spec/20261101125653.txt';
    if (is_file($fn1)) {
        $st1 = $db8->applyFieldNotes($tid8, FieldNotes::parse((string) file_get_contents($fn1)));
        check('kleinere Datei: früher Importierte zählen nicht als „ohne"', $st1['matched'] === 2 && $st1['missing'] === 1, json_encode($st1));
    }
    check('Notizen/Logs nie angefasst', $c8['GC7C18Z']['notes'] === '' && $c8['GC7C18Z']['log'] === '');
}

// ---------------------------------------------------------------------------
section('Doppelte GC-Codes (Schritt 9)');

$db9 = new Db(tmpdir() . '/d.sqlite');
$tA  = $db9->createTour([['code' => 'GCD1', 'name' => 'Eins'], ['code' => 'GCD2', 'name' => 'Zwei']], '<gpx/>', ['tour_date' => '2026-10-01', 'title' => 'Alt']);
$cA  = array_column($db9->getTour($tA)['caches'], null, 'code');
$db9->updateCache($cA['GCD2']['id'], ['log_type' => "Didn't find it"]);
$tB  = $db9->createTour([['code' => 'GCD1', 'name' => 'Eins'], ['code' => 'GCD2', 'name' => 'Zwei'], ['code' => 'GCD3', 'name' => 'Drei'],
    ['code' => 'GCD3', 'name' => 'Drei nochmal']], '<gpx/>', ['tour_date' => '2026-11-01']);
$cB  = $db9->getTour($tB)['caches'];
$d1  = $cB[0]['duplicates'];
check('früherer Fund → Warnung mit Tour/Datum/Status', count($d1) === 1 && $d1[0]['level'] === 'warn' && $d1[0]['tour_id'] === $tA
    && $d1[0]['title'] === 'Alt' && $d1[0]['date'] === '2026-10-01' && $d1[0]['log_type'] === 'Found it', json_encode($d1));
check('früherer DNF → nur Info', ($cB[1]['duplicates'][0]['level'] ?? '') === 'info');
check('doppelt in derselben GPX → Warnung', ($cB[2]['duplicates'][0]['same_tour'] ?? false) === true && $cB[2]['duplicates'][0]['level'] === 'warn'
    && ($cB[3]['duplicates'][0]['same_tour'] ?? false) === true);
check('Gegenrichtung: alte Tour sieht neue', count(array_column($db9->getTour($tA)['caches'], null, 'code')['GCD1']['duplicates']) === 1);
$tC = $db9->createTour([['code' => 'GCD9', 'name' => 'Neu']], '<gpx/>');
check('ohne Duplikat → leere Liste', $db9->getTour($tC)['caches'][0]['duplicates'] === []);
$db9->updateCache($cB[0]['id'], ['log_type' => 'Write note']);
check('eigener Eintrag nur Notiz (andere Tour Fund) → Info', $db9->getTour($tB)['caches'][0]['duplicates'][0]['level'] === 'info');
check('… und Gegenrichtung ebenfalls Info', array_column($db9->getTour($tA)['caches'], null, 'code')['GCD1']['duplicates'][0]['level'] === 'info');
$db9->updateCache($cB[0]['id'], ['log_type' => 'Found it']);
$db9->updateCache($cA['GCD1']['id'], ['log_type' => 'Write note']);
check('Status der alten Tour geändert → Info', $db9->getTour($tB)['caches'][0]['duplicates'][0]['level'] === 'info');
$db9->deleteTour($tA);
check('alte Tour gelöscht → keine Warnung mehr', $db9->getTour($tB)['caches'][0]['duplicates'] === []);

// ---------------------------------------------------------------------------
section('Begleiter normalisieren (Migration 5)');

check('nur Komma trennt; „und", „&", „;" bleiben im Namen', Db::splitCompanions(['die-steffi', 'Tom und Jerry', 'X & Y; Z', 'A, B', ' die-steffi '])
    === ['die-steffi', 'Tom und Jerry', 'X & Y; Z', 'A', 'B']);
if (isset($dbPath) && $dbPath !== null) {
    $pdoReal = (new Db($dbPath))->pdo();
    check('lokale DB-Kopie: „Ginsas und FrauOmegalanze" aufgeteilt',
        (int) $pdoReal->query("SELECT COUNT(*) FROM tour_companion WHERE name = 'Ginsas und FrauOmegalanze'")->fetchColumn() === 0
        && (int) $pdoReal->query("SELECT COUNT(*) FROM tour_companion WHERE name IN ('Ginsas', 'FrauOmegalanze')")->fetchColumn()
           === (int) $pdoReal->query("SELECT COUNT(*) * 2 FROM tour WHERE companions LIKE '%FrauOmegalanze%'")->fetchColumn());
}
$db5 = new Db(tmpdir() . '/c.sqlite');
$t5  = $db5->createTour([['code' => 'GC5', 'name' => 'x']], '<gpx/>');
$db5->updateTour($t5, ['companions' => ['Tom und Jerry', 'A,B']]);
check('update_tour: „und" bleibt, Komma trennt', $db5->getTour($t5)['companions'] === ['Tom und Jerry', 'A', 'B']);

// ---------------------------------------------------------------------------
section('Tour-Übersicht + Suche (Schritt 10)');

$db10 = new Db(tmpdir() . '/l.sqlite');
$t1 = $db10->createTour([['code' => 'GC61F3T', 'name' => 'SE-LP13 Totentanz', 'country' => 'Germany'],
    ['code' => 'GCAB12', 'name' => 'Tønder Stierne', 'country' => 'Denmark']], '<gpx/>',
    ['tour_date' => '2026-10-28', 'title' => 'Urlaub Dänemark', 'companions' => ['die-steffi', 'Felix_GC']]);
$c1 = array_column($db10->getTour($t1)['caches'], null, 'code');
$db10->updateCache($c1['GCAB12']['id'], ['visited_at' => '2026-10-31', 'done' => true]);
$t2 = $db10->createTour([['code' => 'GC99XY', 'name' => 'Störtebekers Schatz', 'country' => 'Germany']], '<gpx/>',
    ['tour_date' => '2026-09-15', 'title' => 'Sylt', 'companions' => ['Clicki17']]);
$t3 = $db10->createTour([['code' => 'GC61AAA', 'name' => 'Anderer', 'country' => 'Germany']], '<gpx/>',
    ['tour_date' => '2026-11-01', 'title' => 'Heimrunde', 'companions' => ['die-steffi']]);

$ids = static fn (array $f) => array_column($db10->listTours($f), 'id');
$all = array_column($db10->listTours(), null, 'id');
check('ohne Filter: neueste zuerst', array_keys($all) === [$t3, $t1, $t2], json_encode(array_keys($all)));
check('Zeitraum, Fortschritt, Begleiter, Länder', $all[$t1]['date_min'] === '2026-10-28' && $all[$t1]['date_max'] === '2026-10-31'
    && $all[$t1]['done_count'] === 1 && $all[$t1]['cache_count'] === 2
    && $all[$t1]['companions'] === ['Felix_GC', 'die-steffi'] && $all[$t1]['countries'] === ['Denmark', 'Germany'], json_encode($all[$t1]));
check('GC-Code exakt', $ids(['code' => 'gc61f3t']) === [$t1]);
check('GC-Code Präfix', $ids(['code' => 'GC61']) === [$t3, $t1]);
check('GC-Code: Treffer-Cache mitgeliefert', array_column($db10->listTours(['code' => 'GC61F3T'])[0]['hits'], 'name') === ['SE-LP13 Totentanz']);
check('Monat', $ids(['date' => '2026-10']) === [$t1]);
check('Jahr', $ids(['date' => '2026']) === [$t3, $t1, $t2] && $ids(['date' => '2025']) === []);
check('Tag trifft auch späteren Cache-Tag', $ids(['date' => '2026-10-31']) === [$t1] && $ids(['date' => '2026-10-29']) === []);
check('Begleiter', $ids(['companion' => 'die-steffi']) === [$t3, $t1]);
check('Freitext Titel', $ids(['q' => 'sylt']) === [$t2]);
check('Freitext Cache-Name mit Umlaut, ohne Groß/Klein', $ids(['q' => 'STÖRTEBEKER']) === [$t2] && $ids(['q' => 'tønder']) === [$t1]);
check('Freitext: Treffer-Cache mitgeliefert', array_column($db10->listTours(['q' => 'tønder'])[0]['hits'], 'code') === ['GCAB12']);
check('Filter kombiniert (UND)', $ids(['companion' => 'die-steffi', 'date' => '2026-11']) === [$t3]
    && $ids(['companion' => 'Clicki17', 'code' => 'GC61']) === []);
check('ungültiges Datum wird ignoriert', count($ids(['date' => 'Oktober'])) === 3);
$dbC = new Db(tmpdir() . '/ch.sqlite');
$dbC->createTour([['code' => 'GCZZ1', 'name' => 'z']], '<gpx/>', ['companions' => ['zorro', 'Amun1978', 'Ärger', 'beta', 'die-steffi']]);
$ch = $dbC->companionChoices(['die-steffi', 'Clicki17', 'Felix_GC', 'emblinux']);
check('Mitcacher-Auswahl: feste in Config-Reihenfolge', $ch['fixed'] === ['die-steffi', 'Clicki17', 'Felix_GC', 'emblinux']);
check('Mitcacher-Auswahl: übrige alphabetisch ohne Groß/Klein, Ä wie A', $ch['others'] === ['Amun1978', 'Ärger', 'beta', 'zorro'], json_encode($ch['others'], JSON_UNESCAPED_UNICODE));

for ($i = 1; $i <= 22; $i++) {
    $db10->createTour([['code' => 'GCP' . $i, 'name' => 'Seite ' . $i]], '<gpx/>', ['tour_date' => sprintf('2025-01-%02d', $i)]);
}
$p1 = $db10->listToursPage([], 1);
$p2 = $db10->listToursPage([], 2);
check('Seite 1: 20 Touren, 25 gesamt, 2 Seiten', count($p1['tours']) === 20 && $p1['total'] === 25 && $p1['pages'] === 2);
check('Seite 2: Rest, keine Überschneidung', count($p2['tours']) === 5
    && array_intersect(array_column($p1['tours'], 'id'), array_column($p2['tours'], 'id')) === []);
check('Seite außerhalb → letzte Seite', $db10->listToursPage([], 9)['page'] === 2 && $db10->listToursPage([], 0)['page'] === 1);
$pq = $db10->listToursPage(['q' => 'Seite'], 2);
check('Freitext-Filter vor dem Blättern', $pq['total'] === 22 && count($pq['tours']) === 2);

// ---------------------------------------------------------------------------
section('Field-Notes-Export für mich (Schritt 11)');

foreach (['20261101125653.txt', '20261101130803.txt'] as $fnName) {
    $fnFile = $root . '/spec/' . $fnName;
    if (!is_file($fnFile)) {
        skip('c:geo-Datei fehlt: ' . $fnName);
        continue;
    }
    // Rundlauf: c:geo-Datei zerlegen und neu schreiben → byte-identisch bis auf die
    // gewollte Abweichung (leere Zeilen im Text → U+200B, sonst verwirft gc.com sie)
    $orig = (string) file_get_contents($fnFile);
    $recs = FieldNotes::records(FieldNotes::decode($orig));
    $ents = array_map(static fn (array $r): array => ['code' => $r[0], 'ts' => $r[1], 'log_type' => $r[2], 'text' => $r[3]], $recs);
    shuffle($ents);   // Eingangsreihenfolge egal, Ausgabe sortiert wie c:geo
    $mine     = FieldNotesExport::build($ents);
    $expected = mb_convert_encoding(str_replace("\n\n", "\n\u{200B}\n", mb_convert_encoding($orig, 'UTF-8', 'UTF-16LE')), 'UTF-16LE', 'UTF-8');
    check("$fnName: Export byte-identisch zu c:geo (bis auf Leerzeilen-Füllung)", $mine === $expected, 'Längen ' . strlen($mine) . ' vs ' . strlen($expected));
}

$out = FieldNotesExport::build([
    ['code' => 'gc1abc', 'ts' => '2026-10-31T09:00:00Z', 'log_type' => 'Found it', 'text' => "Zeile 1\r\nZeile 2 mit \"Zitat\" 😀 Ümlaut"],
    ['code' => 'GC2DEF', 'ts' => '2026-10-31T10:00:00Z', 'log_type' => "Didn't find it", 'text' => 'kurz'],
]);
check('kein BOM, UTF-16 LE', substr($out, 0, 2) === "G\0");
$txt = mb_convert_encoding($out, 'UTF-8', 'UTF-16LE');
check('neuester zuerst, LF am Ende, kein CR', str_starts_with($txt, 'GC2DEF,2026-10-31T10:00:00Z,Didn\'t find it,"kurz"' . "\n")
    && str_ends_with($txt, "\"\n") && !str_contains($txt, "\r"));
check('" verdoppelt, Code groß, Emoji/Umlaut erhalten', str_contains($txt, 'GC1ABC,2026-10-31T09:00:00Z,Found it,"Zeile 1' . "\n" . 'Zeile 2 mit ""Zitat"" 😀 Ümlaut"'));
$back = FieldNotes::records(FieldNotes::decode($out));
check('Leerzeilen: innen U+200B, Ränder weg, mehrere bleiben mehrere',
    FieldNotesExport::keepBlankLines("\n\nA\n\nB\r\n \r\n\r\nC\n\n") === "A\n\u{200B}\nB\n\u{200B}\n\u{200B}\nC");
check('Rundlauf über Import-Parser', $back[1][3] === "Zeile 1\nZeile 2 mit \"Zitat\" 😀 Ümlaut" && $back[0][0] === 'GC2DEF');

// Geschätzte Zeiten: ab 10:00 Ortszeit, 10-min-Schritte, je Tag neu; Sommer-/Winterzeit
$sched = FieldNotesExport::schedule([
    ['visited_at' => '2026-10-24', 'visited_ts' => null],
    ['visited_at' => '2026-10-24', 'visited_ts' => '2026-10-24T13:33:00Z'],
    ['visited_at' => '2026-10-24', 'visited_ts' => null],
    ['visited_at' => '2026-10-25', 'visited_ts' => null],
    ['visited_at' => null, 'visited_ts' => null],
], '2026-11-01');
check('geschätzt: 10:00 MESZ = 08:00Z, nächster 10:10', $sched[0] === ['ts' => '2026-10-24T08:00:00Z', 'estimated' => true]
    && $sched[2]['ts'] === '2026-10-24T08:10:00Z');
check('echte Uhrzeit bleibt, nicht geschätzt', $sched[1] === ['ts' => '2026-10-24T13:33:00Z', 'estimated' => false]);
check('neuer Tag nach Zeitumstellung: 10:00 MEZ = 09:00Z', $sched[3]['ts'] === '2026-10-25T09:00:00Z');
check('ohne Datum → Tour-Datum', $sched[4]['ts'] === '2026-11-01T09:00:00Z');

// Kandidaten aus einer Tour
$db11 = new Db(tmpdir() . '/e.sqlite');
$t11  = $db11->createTour([
    ['code' => 'GCE1', 'name' => 'Mit Log'], ['code' => 'GCE2', 'name' => 'Ohne Log'],
    ['code' => 'GCE3', 'name' => 'Schon geloggt', 'own_log' => ['type' => 'Found it', 'date' => '2026-10-30', 'ts' => '2026-10-30T12:00:00Z']],
], '<gpx/>', ['tour_date' => '2026-10-30']);
$c11 = array_column($db11->getTour($t11)['caches'], null, 'code');
$db11->updateCache($c11['GCE1']['id'], ['log' => 'Tolles Log', 'log_type' => "Didn't find it"]);
$db11->updateCache($c11['GCE3']['id'], ['log' => 'Auch ein Log']);
$k = array_column(FieldNotesExport::candidatesForMe($db11->getTour($t11)), null, 'code');
check('Kandidat mit Log: gewählt, Status + Text', $k['GCE1']['selected'] && $k['GCE1']['log_type'] === "Didn't find it" && $k['GCE1']['text'] === 'Tolles Log'
    && $k['GCE1']['estimated'] && $k['GCE1']['ts'] === '2026-10-30T09:00:00Z');
check('Dateiname mit Datum + Tour', FieldNotesExport::filename(['id' => 45, 'tour_date' => '2026-10-31']) === 'geocache_visits_2026-10-31_tour45.txt'
    && FieldNotesExport::filename(['id' => 7, 'tour_date' => null]) === 'geocache_visits_tour7.txt');
check('ohne Log: nicht wählbar', !$k['GCE2']['available'] && !$k['GCE2']['selected']);
check('schon selbst geloggt: abgewählt, echte Zeit', $k['GCE3']['already'] && !$k['GCE3']['selected'] && !$k['GCE3']['estimated']);

// ---------------------------------------------------------------------------
section('Field-Notes-Export für Begleiter (Schritt 12)');

check('Namen verbinden', FieldNotesExport::joinNames(['A']) === 'A' && FieldNotesExport::joinNames(['A', 'B']) === 'A und B'
    && FieldNotesExport::joinNames(['A', 'B', 'C']) === 'A, B und C' && FieldNotesExport::joinNames([]) === '');
check('{mitcacher} für Clicki17: andere + ich, ohne Empfänger', FieldNotesExport::mitcacher(['die-steffi', 'Clicki17'], 'ClickKlack', 'Clicki17')
    === 'die-steffi und ClickKlack');
check('{mitcacher}: Empfänger ohne Groß/Klein, ich nicht doppelt', FieldNotesExport::mitcacher(['clicki17', 'ClickKlack', 'Felix_GC'], 'ClickKlack', 'Clicki17')
    === 'Felix_GC und ClickKlack');
check('unbekannte Platzhalter erkannt', FieldNotesExport::unknownPlaceholders('Hi {mitcacher} {tippfehler} {cachename} {Owner}') === ['tippfehler', 'Owner']);
check('Vorlage rendern', FieldNotesExport::render('{cachename} ({gc_code}) mit {mitcacher}, Dank an {owner} um {uhrzeit}', [
    'cachename' => 'Dose', 'gc_code' => 'GC1', 'mitcacher' => 'A und B', 'owner' => 'O', 'uhrzeit' => '12:49']) === 'Dose (GC1) mit A und B, Dank an O um 12:49');
check('Dateiname mit Empfänger', FieldNotesExport::filename(['id' => 45, 'tour_date' => '2026-10-31'], 'die-steffi') === 'geocache_visits_2026-10-31_tour45_die-steffi.txt');

$db12 = new Db(tmpdir() . '/b.sqlite');
$t12  = $db12->createTour([
    ['code' => 'GCB1', 'name' => 'Fund mit Zeit', 'owner' => 'Owner1', 'placed_by' => 'X, Y'],
    ['code' => 'GCB2', 'name' => 'Schon von Clicki17 geloggt', 'placed_by' => 'NurPlacedBy',
     'companion_logs' => [['name' => 'Clicki17', 'type' => 'Found it', 'date' => '2026-10-30']]],
    ['code' => 'GCB3', 'name' => 'DNF'],
], '<gpx/>', ['tour_date' => '2026-10-30', 'companions' => ['die-steffi', 'Clicki17']]);
$c12 = array_column($db12->getTour($t12)['caches'], null, 'code');
$db12->updateCache($c12['GCB1']['id'], ['visited_ts' => '2026-10-30T11:49:00Z']);
$db12->updateCache($c12['GCB3']['id'], ['log_type' => "Didn't find it"]);
$k12 = array_column(FieldNotesExport::candidatesForCompanion($db12->getTour($t12), 'Clicki17', '{mitcacher}|{cachename}|{owner}|{uhrzeit}|{gc_code}', 'ClickKlack'), null, 'code');
check('Text gerendert (Owner, Uhrzeit Ortszeit)', $k12['GCB1']['text'] === 'die-steffi und ClickKlack|Fund mit Zeit|Owner1|12:49|GCB1', $k12['GCB1']['text']);
check('Owner-Fallback placed_by, geschätzte Zeit → {uhrzeit} leer', $k12['GCB2']['text'] === 'die-steffi und ClickKlack|Schon von Clicki17 geloggt|NurPlacedBy||GCB2', $k12['GCB2']['text']);
check('Vorauswahl: Fund ja, schon geloggt nein, DNF nein', $k12['GCB1']['selected'] && !$k12['GCB2']['selected'] && $k12['GCB2']['companion_logged']
    && !$k12['GCB3']['selected'] && $k12['GCB3']['not_found']);

check('ohne gespeicherte Vorlage → eingebaute', $db12->companionTemplate('Clicki17')['source'] === 'builtin');
$db12->saveTemplate('', 'Standard {cachename}');
check('Standardvorlage aus DB', $db12->companionTemplate('Clicki17') === ['template' => 'Standard {cachename}', 'source' => 'default']);
$db12->saveTemplate('Clicki17', "Für Clicki17\r\n{cachename}");
check('Vorlage je Begleiter ersetzt Standard (CRLF → LF)', array_intersect_key($db12->companionTemplate('Clicki17'), ['template' => 1, 'source' => 1])
        === ['template' => "Für Clicki17\n{cachename}", 'source' => 'companion']
    &&$db12->companionTemplate('die-steffi')['source'] === 'default');
$db12->saveTemplate('Clicki17', '');
check('leere Begleiter-Vorlage → wieder Standard', $db12->companionTemplate('Clicki17')['source'] === 'default');

// ---------------------------------------------------------------------------
section('Backup-Export (Schritt 13)');

$db13 = new Db(tmpdir() . '/bk.sqlite');
$t13  = $db13->createTour([['code' => 'GCK1', 'name' => 'Ümlaut 😀'], ['code' => 'GCK2', 'name' => 'Zwei']], '<gpx>roh</gpx>',
    ['companions' => ['die-steffi'], 'tour_info' => 'Info']);
$c13 = $db13->getTour($t13)['caches'];
$db13->updateCache($c13[0]['id'], ['notes' => 'Notiz', 'log' => "Log\n\nmit Absatz"]);
$db13->saveTemplate('die-steffi', 'Vorlage {cachename}');
$db13->saveTemplate('', 'Standard');
Auth::init(static fn (): Db => $db13, 'hash');
Auth::issueToken('smoke');
$j = $db13->exportJson();
check('JSON: Meta + Touren + Caches', $j['app'] === 'gc_logger' && $j['schema_version'] >= 5 && count($j['tours']) === 1 && count($j['tours'][0]['caches']) === 2);
check('JSON: Notiz, Log, Begleiter, Vorlagen, Settings', $j['tours'][0]['caches'][0]['notes'] === 'Notiz' && $j['tours'][0]['caches'][0]['log'] === "Log\n\nmit Absatz"
    && $j['tours'][0]['companions'] === ['die-steffi'] && $j['companions'][0]['template'] === 'Vorlage {cachename}' && $j['settings']['fieldnotes_template'] === 'Standard');
$enc = json_encode($j, JSON_UNESCAPED_UNICODE);
check('JSON: ohne Token, ohne rohe GPX, ohne abgeleitete Duplikate', !str_contains($enc, 'token') && !str_contains($enc, 'gpx_raw')
    && !str_contains($enc, '<gpx>roh') && !str_contains($enc, 'duplicates'));
check('JSON: Umlaut/Emoji erhalten', str_contains($enc, 'Ümlaut 😀'));
$copy = $db13->exportSqliteCopy();
$cp   = new PDO('sqlite:' . $copy);
check('SQLite-Kopie: integrity ok, Touren da, rohe GPX da', $cp->query('PRAGMA integrity_check')->fetchColumn() === 'ok'
    && (int) $cp->query('SELECT COUNT(*) FROM tour')->fetchColumn() === 1
    && gzuncompress((string) $cp->query('SELECT gpx_raw FROM tour')->fetchColumn()) === '<gpx>roh</gpx>');
check('SQLite-Kopie: ohne Login-Tokens (Original behält sie)', (int) $cp->query('SELECT COUNT(*) FROM auth_token')->fetchColumn() === 0
    && (int) $db13->pdo()->query('SELECT COUNT(*) FROM auth_token')->fetchColumn() === 1);
$cp = null;
@unlink($copy);

// ---------------------------------------------------------------------------
section('Backup gefiltert + GPX-Export');

$db14 = new Db(tmpdir() . '/gx.sqlite');
$tA14 = $db14->createTour([
    ['code' => 'GCX1', 'name' => 'Dose & „Zitat" <b>', 'lat' => '54.1', 'lon' => '9.1', 'type' => 'Traditional Cache', 'owner' => 'Own',
     'placed_by' => 'Own, Zwei', 'difficulty' => '1.5', 'terrain' => '2', 'container' => 'Small', 'country' => 'Denmark',
     'hint' => 'Unter dem Stein', 'listing' => "Listing\nmit Zeile", 'short_description' => 'Kurz'],
    ['code' => 'GCX2', 'name' => 'Ohne Log', 'lat' => '54.2', 'lon' => '9.2', 'type' => 'Unknown Cache'],
], '<gpx/>', ['tour_date' => '2026-10-31', 'title' => 'Tour A']);
$tB14 = $db14->createTour([['code' => 'GCX1', 'name' => 'Dose & „Zitat" <b>', 'lat' => '54.1', 'lon' => '9.1']], '<gpx/>',
    ['tour_date' => '2026-11-01', 'title' => 'Tour B']);
$cA14 = array_column($db14->getTour($tA14)['caches'], null, 'code');
$db14->updateCache($cA14['GCX1']['id'], ['log' => "Log A mit Steuerzeichen \x07 und 😀", 'visited_ts' => '2026-10-31T11:00:00Z', 'log_type' => "Didn't find it"]);
$db14->updateCache($db14->getTour($tB14)['caches'][0]['id'], ['log' => 'Log B']);

$gpxOut = GpxExport::build([$db14->getTour($tA14), $db14->getTour($tB14)], 'ClickKlack');
$sx = simplexml_load_string($gpxOut);
check('GPX: gültiges XML, 2 Wegpunkte (GCX1 zusammengeführt)', $sx !== false && count($sx->wpt) === 2);
$re = array_column(Gpx::parse($gpxOut, 'ClickKlack'), null, 'code');
check('GPX → eigener Parser: Felder kommen zurück', $re['GCX1']['name'] === 'Dose & „Zitat" <b>' && $re['GCX1']['owner'] === 'Own'
    && $re['GCX1']['placed_by'] === 'Own, Zwei' && $re['GCX1']['difficulty'] === '1.5' && $re['GCX1']['country'] === 'Denmark'
    && $re['GCX1']['listing'] === "Listing\nmit Zeile", json_encode($re['GCX1'], JSON_UNESCAPED_UNICODE));
check('GPX: Hint im Klartext (nicht ROT13)', $re['GCX1']['hint'] === 'Unter dem Stein');
check('GPX: neuestes eigenes Log erkannt (Tour B, nur Datum)', $re['GCX1']['own_log'] === ['type' => 'Found it', 'date' => '2026-11-01', 'ts' => null],
    json_encode($re['GCX1']['own_log']));
$logs = $sx->wpt[0]->children('http://www.groundspeak.com/cache/1/0/1')->cache->logs->log;
check('GPX: beide Logs mit Typ, Zeit, Finder, Text', count($logs) === 2 && (string) $logs[0]->type === "Didn't find it"
    && (string) $logs[0]->date === '2026-10-31T11:00:00Z' && (string) $logs[0]->finder === 'ClickKlack'
    && (string) $logs[0]->text === 'Log A mit Steuerzeichen  und 😀' && (string) $logs[1]->date === '2026-11-01T00:00:00Z');
check('GPX: Cache ohne Log ohne <logs>', !isset($sx->wpt[1]->children('http://www.groundspeak.com/cache/1/0/1')->cache->logs));

$jf = $db14->exportJson([$tB14]);
check('JSON gefiltert: nur Tour B, als gefiltert markiert', $jf['filtered'] === true && count($jf['tours']) === 1 && $jf['tours'][0]['title'] === 'Tour B');
check('JSON ungefiltert: alle', $db14->exportJson()['filtered'] === false && count($db14->exportJson()['tours']) === 2);

// ---------------------------------------------------------------------------
section('Freigabe-Links (spec-v3 Schritt 1)');

$dbS = new Db(tmpdir() . '/share.sqlite');
check('Migration 6: user_version = 6', (int) $dbS->pdo()->query('PRAGMA user_version')->fetchColumn() === 6);
$tS1 = $dbS->createTour([['code' => 'GCS1', 'name' => 'S1'], ['code' => 'GCS2', 'name' => 'S2']], '<gpx/>',
    ['companions' => ['Clicki17', 'die-steffi'], 'tour_date' => '2026-10-31']);
$tS2 = $dbS->createTour([['code' => 'GCS3', 'name' => 'S3']], '<gpx/>', ['companions' => ['Clicki17'], 'tour_date' => '2026-11-01']);
$tS3 = $dbS->createTour([['code' => 'GCS4', 'name' => 'S4']], '<gpx/>', ['companions' => ['die-steffi']]);

$sh = $dbS->createShare('Clicki17', [$tS1, $tS2, $tS2]);
check('Token: 43 Zeichen base64url', (bool) preg_match('/^[A-Za-z0-9_-]{43}$/', $sh['token']), $sh['token']);
check('Touren doppelt → einmal', $sh['tour_ids'] === [$tS1, $tS2]);
check('Ablauf 14 Tage', abs(strtotime($sh['expires_at'] . ' UTC') - (time() + Db::SHARE_DAYS * 86400)) < 60);
$err = null;
try {
    $dbS->createShare('Clicki17', [$tS1, $tS3]);
} catch (InvalidArgumentException $e) {
    $err = $e->getMessage();
}
check('Tour ohne den Empfänger → abgelehnt', $err !== null && (int) $dbS->pdo()->query('SELECT COUNT(*) FROM share')->fetchColumn() === 1, (string) $err);
$err = null;
try {
    $dbS->createShare('Clicki17', []);
} catch (InvalidArgumentException $e) {
    $err = $e->getMessage();
}
check('ohne Touren → abgelehnt', $err !== null);

$found = $dbS->findShare($sh['token']);
check('findShare: gültig, last_used_at gesetzt', $found !== null && $found['recipient'] === 'Clicki17' && $found['last_used_at'] !== null);
check('findShare: falsches Token → null', $dbS->findShare(str_repeat('A', 43)) === null);

$cS = array_column($dbS->getTour($tS1)['caches'], 'id');
$dbS->markExported($sh['id'], [$cS[0]]);
$dbS->markExported($sh['id'], [$cS[0], $cS[1]]);
$exp = $dbS->exportedCaches($sh['id']);
check('markExported: beide gemerkt, Downloads gezählt', array_keys($exp) === [$cS[0], $cS[1]] && $dbS->findShare($sh['token'])['downloads'] === 2);
$ls = $dbS->listShares();
check('listShares: Touren + Anzahl exportiert', count($ls) === 1 && count($ls[0]['tours']) === 2 && $ls[0]['exported'] === 2
    && $ls[0]['tours'][0]['id'] === $tS2);

$dbS->deleteTour($tS1);
check('Tour gelöscht → Freigabe behält restliche Tour, Exporte weg',
    $dbS->findShare($sh['token'])['tour_ids'] === [$tS2] && $dbS->exportedCaches($sh['id']) === []);
$dbS->deleteTour($tS2);
check('letzte Tour gelöscht → Freigabe weg', $dbS->findShare($sh['token']) === null
    && (int) $dbS->pdo()->query('SELECT COUNT(*) FROM share')->fetchColumn() === 0);

$sh2 = $dbS->createShare('die-steffi', [$tS3]);
$dbS->pdo()->exec("UPDATE share SET expires_at = '2000-01-01 00:00:00'");
check('abgelaufen → null + gelöscht', $dbS->findShare($sh2['token']) === null
    && (int) $dbS->pdo()->query('SELECT COUNT(*) FROM share_tour')->fetchColumn() === 0);
$sh3 = $dbS->createShare('die-steffi', [$tS3]);
check('widerrufen', $dbS->deleteShare($sh3['id']) && $dbS->findShare($sh3['token']) === null);

$dbS->saveTemplate('Clicki17', 'Von ihm {cachename}', 'share');
$tp = $dbS->companionTemplate('Clicki17');
check('Vorlage über Link: changed_by = share', $tp['template'] === 'Von ihm {cachename}' && $tp['changed_by'] === 'share' && $tp['changed_at'] !== null);
$dbS->saveTemplate('Clicki17', 'Von mir');
check('Vorlage von mir: changed_by = me', $dbS->companionTemplate('Clicki17')['changed_by'] === 'me');

if (($srcS = copyLocalDb($root, tmpdir())) !== null) {
    $dbL = new Db($srcS);
    $cols = $dbL->pdo()->query('PRAGMA table_info(companion)')->fetchAll(PDO::FETCH_COLUMN, 1);
    check('lokale DB: auf Version 6 migriert, companion-Spalten da',
        (int) $dbL->pdo()->query('PRAGMA user_version')->fetchColumn() === 6 && in_array('template_changed_by', $cols, true));
} else {
    skip('lokale DB fehlt (Migration 6 auf Bestandsdaten)');
}

// ---------------------------------------------------------------------------
section('Freigabe-Links: Export-Logik + Gast-Ansicht (spec-v3 Schritt 2)');

$dbV = new Db(tmpdir() . '/share2.sqlite');
$tV1 = $dbV->createTour([
    ['code' => 'GCV1', 'name' => 'Dose „Eins"', 'type' => 'Traditional Cache', 'owner' => 'Own1',
        'listing' => 'LISTINGGEHEIM', 'hint' => 'HINTGEHEIM'],
    ['code' => 'GCV2', 'name' => 'Zwei', 'type' => 'Multi-cache', 'owner' => 'Own2',
        'companion_logs' => [['name' => 'clicki17', 'type' => 'Found it', 'date' => '2026-10-31']]],
    ['code' => 'GCV3', 'name' => 'Drei', 'type' => 'Traditional Cache'],
], '<gpx/>', ['companions' => ['Clicki17', 'die-steffi'], 'tour_date' => '2026-10-31']);
$tV2 = $dbV->createTour([['code' => 'GCV4', 'name' => 'Vier', 'type' => 'Earthcache']], '<gpx/>',
    ['companions' => ['Clicki17'], 'tour_date' => '2026-11-01']);
$dbV->updateTour($tV1, ['context' => 'KONTEXTGEHEIM', 'tour_info' => 'INFOGEHEIM', 'title' => 'Tour Eins']);
$cV = array_column($dbV->getTour($tV1)['caches'], 'id', 'code');
$dbV->updateCache($cV['GCV1'], ['notes' => 'NOTIZGEHEIM', 'log' => 'KILOGGEHEIM', 'visited_ts' => '2026-10-31T09:15:00Z']);
$dbV->updateCache($cV['GCV3'], ['log_type' => "Didn't find it"]);

$shV  = $dbV->createShare('Clicki17', [$tV1, $tV2]);
$tpl  = "Mit {mitcacher}: {cachename} ({uhrzeit})\n\nDanke {owner}";
$tsV  = Share::tours($dbV, $shV, $tpl, 'ClickKlack');
check('Touren neueste zuerst', array_column($tsV, 'id') === [$tV2, $tV1]);
check('{mitcacher} je Tour', $tsV[1]['mitcacher'] === 'die-steffi und ClickKlack' && $tsV[0]['mitcacher'] === 'ClickKlack');
$byCode = array_column(array_merge(...array_column($tsV, 'caches')), null, 'code');
check('Text aus Vorlage, Typ dabei', $byCode['GCV1']['text'] === "Mit die-steffi und ClickKlack: Dose „Eins\" (10:15)\n\nDanke Own1"
    && $byCode['GCV4']['type'] === 'Earthcache', json_encode($byCode['GCV1'], JSON_UNESCAPED_UNICODE));
check('Vorauswahl: Fund ja, schon geloggt nein, DNF nein', $byCode['GCV1']['selected'] && !$byCode['GCV2']['selected']
    && $byCode['GCV2']['companion_logged'] && !$byCode['GCV3']['selected'] && $byCode['GCV4']['selected']);

$json = json_encode(Share::publicTours($tsV), JSON_UNESCAPED_UNICODE);
$leak = array_filter(['LISTINGGEHEIM', 'HINTGEHEIM', 'KONTEXTGEHEIM', 'INFOGEHEIM', 'NOTIZGEHEIM', 'KILOGGEHEIM'],
    static fn (string $m): bool => str_contains($json, $m));
check('Gast-Ansicht: keine Listing/Hint/Kontext/Info/Notiz/KI-Log', $leak === [], implode(', ', $leak));
$pubCache = Share::publicTours($tsV)[1]['caches'][0];
check('Gast-Ansicht: nur Whitelist-Felder', array_keys($pubCache) === ['id', 'code', 'name', 'log_type', 'ts', 'estimated', 'text',
    'companion_logged', 'selected', 'type', 'exported_at'], implode(',', array_keys($pubCache)));

$entV = Share::entries($tsV, [$cV['GCV1'], $cV['GCV2'], 999999]);
check('entries: nur IDs der Freigabe', array_column($entV, 'code') === ['GCV1', 'GCV2']);
$single = FieldNotesExport::candidatesForCompanion($dbV->getTour($tV1), 'Clicki17', $tpl, 'ClickKlack');
check('Datei = build() wie normaler Export', FieldNotesExport::build($entV)
    === FieldNotesExport::build(array_values(array_filter($single, static fn ($c) => in_array($c['code'], ['GCV1', 'GCV2'], true)))));

$dbV->markExported($shV['id'], array_column($entV, 'id'));
$tsV2 = Share::tours($dbV, $dbV->findShare($shV['token']), $tpl, 'ClickKlack');
$by2  = array_column(array_merge(...array_column($tsV2, 'caches')), null, 'code');
check('exportiert → nicht vorausgewählt, Datum gesetzt', !$by2['GCV1']['selected']
    && $by2['GCV1']['exported_at'] !== null && $by2['GCV4']['exported_at'] === null && $by2['GCV4']['selected']);
check('entries: exportierte manuell erneut wählbar', array_column(Share::entries($tsV2, [$cV['GCV1'], $by2['GCV4']['id']]), 'code') === ['GCV4', 'GCV1']);
$dbV->pdo()->exec("UPDATE share_export SET exported_at = '2026-01-01 00:00:00'");
$dbV->markExported($shV['id'], [$cV['GCV1']]);
check('erneuter Export: Datum aktualisiert', $dbV->exportedCaches($shV['id'])[$cV['GCV1']] !== '2026-01-01 00:00:00'
    && $dbV->exportedCaches($shV['id'])[$cV['GCV2']] === '2026-01-01 00:00:00');

check('Dateiname: mehrere Touren', Share::filename('Clicki17', $tsV) === 'geocache_visits_Clicki17_' . Db::today() . '.txt');
check('Dateiname: eine Tour wie normaler Export', Share::filename('Clicki17', [$tsV[1]]) === 'geocache_visits_2026-10-31_tour' . $tV1 . '_Clicki17.txt');

// ---------------------------------------------------------------------------
echo "\n" . ($fails === 0 ? '✓ 0 Fehler' : "✗ {$fails} Fehler") . ($skips ? ", {$skips} übersprungen" : '') . "\n";
exit($fails === 0 ? 0 : 1);
