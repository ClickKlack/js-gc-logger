<?php

declare(strict_types=1);

/**
 * SQLite-Persistenz via PDO. Legt DB-Datei und Schema bei Bedarf an.
 */
final class Db
{
    /** Anzahl rollierender Server-Backups in data/backup/ (F8). */
    public const BACKUP_KEEP = 3;

    private PDO $pdo;
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $this->pdo = new PDO('sqlite:' . $path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->pdo->exec('PRAGMA journal_mode = WAL');   // robuster bei häufigen Autosave-Writes
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->migrate();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    private function migrate(): void
    {
        $version = (int) $this->pdo->query('PRAGMA user_version')->fetchColumn();
        $migrations = $this->migrations();

        // Vor dem ersten Schema-Umbau einer bestehenden DB sichern.
        $isExisting = (bool) $this->pdo
            ->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'tour'")
            ->fetchColumn();
        if ($isExisting && $version < count($migrations)) {
            $this->backup();
        }

        $schema = file_get_contents(__DIR__ . '/../schema.sql');
        if ($schema === false) {
            throw new RuntimeException('schema.sql konnte nicht gelesen werden.');
        }
        $this->pdo->exec($schema);

        foreach ($migrations as $i => $step) {
            $target = $i + 1;
            if ($version >= $target) {
                continue;
            }
            $this->pdo->beginTransaction();
            try {
                $step();
                $this->pdo->exec('PRAGMA user_version = ' . $target);
                $this->pdo->commit();
            } catch (Throwable $e) {
                $this->pdo->rollBack();
                throw $e;
            }
        }
    }

    /**
     * Versionierte Migrationen; Index + 1 = user_version nach dem Schritt.
     * Nur anhängen, nie bestehende Einträge ändern.
     * @return list<callable():void>
     */
    private function migrations(): array
    {
        return [
            // 1: spec-v2-Datenmodell (Autosave, Status, Datum, Suche, Login-Token)
            function (): void {
                $this->addColumnIfMissing('tour', 'updated_at', 'TEXT');
                $this->addColumnIfMissing('tour', 'tour_date', 'TEXT');
                $this->addColumnIfMissing('tour', 'gpx_hash', 'TEXT');
                $this->addColumnIfMissing('tour', 'codes_hash', 'TEXT');
                $this->addColumnIfMissing('tour', 'gpx_raw', 'BLOB');
                $this->addColumnIfMissing('tour', 'manually_sorted', 'INTEGER NOT NULL DEFAULT 0');

                $this->addColumnIfMissing('cache_log', 'log_type', "TEXT NOT NULL DEFAULT 'Found it'");
                $this->addColumnIfMissing('cache_log', 'visited_at', 'TEXT');
                $this->addColumnIfMissing('cache_log', 'visited_ts', 'TEXT');
                $this->addColumnIfMissing('cache_log', 'find_no', 'INTEGER');
                $this->addColumnIfMissing('cache_log', 'manual_fields', 'TEXT');
                $this->addColumnIfMissing('cache_log', 'companion_logs', 'TEXT');
                $this->addColumnIfMissing('cache_log', 'updated_at', 'TEXT');

                $this->pdo->exec("UPDATE tour SET tour_date = date(created_at) WHERE tour_date IS NULL");
                $this->pdo->exec("UPDATE tour SET updated_at = created_at WHERE updated_at IS NULL");
                $this->pdo->exec(
                    "UPDATE cache_log SET visited_at = (SELECT tour_date FROM tour WHERE tour.id = cache_log.tour_id)
                     WHERE visited_at IS NULL"
                );
                $this->pdo->exec(
                    "INSERT OR IGNORE INTO tour_companion (tour_id, name)
                     SELECT t.id, trim(j.value) FROM tour t, json_each(t.companions) j
                     WHERE json_valid(t.companions) AND trim(j.value) <> ''"
                );

                $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_cache_log_code ON cache_log(code)');
                $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_tour_date ON tour(tour_date)');
                $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_tour_codes_hash ON tour(codes_hash)');
            },
            // 2: codes_hash für bestehende Touren nachtragen (GPX-Wiedererkennung)
            function (): void {
                $upd = $this->pdo->prepare('UPDATE tour SET codes_hash = ? WHERE id = ?');
                $rows = $this->pdo->query(
                    "SELECT tour_id, group_concat(code, char(10)) FROM cache_log GROUP BY tour_id"
                )->fetchAll(PDO::FETCH_NUM);
                foreach ($rows as [$tourId, $codes]) {
                    $upd->execute([self::codesHash(explode("\n", (string) $codes)), $tourId]);
                }
            },
            // 3: Bestandsdaten — Events/Webcams kennen kein „Found it" (Migration 1 setzte es pauschal)
            function (): void {
                $this->pdo->exec(
                    "UPDATE cache_log
                     SET log_type = CASE WHEN lower(json_extract(gpx, '$.type')) LIKE '%event%' THEN 'Attended'
                                         ELSE 'Webcam Photo Taken' END
                     WHERE log_type = 'Found it'
                       AND COALESCE(manual_fields, '') NOT LIKE '%log_type%'
                       AND json_valid(gpx)
                       AND (lower(json_extract(gpx, '$.type')) LIKE '%event%'
                            OR lower(json_extract(gpx, '$.type')) LIKE '%webcam%')"
                );
            },
            // 4: c:geo-Feldnotizen (Import-Zeitpunkt je Tour, Eintrag je Cache)
            function (): void {
                $this->addColumnIfMissing('tour', 'fieldnotes_at', 'TEXT');
                $this->addColumnIfMissing('cache_log', 'fieldnote_ts', 'TEXT');
            },
        ];
    }

    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        $cols = $this->pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array($column, $cols, true)) {
            $this->pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
        }
    }

    /**
     * Konsistente Kopie der DB nach data/backup/ (VACUUM INTO, WAL-sicher); behält
     * die letzten $keep. Fehler werden nur protokolliert — ein fehlgeschlagenes Backup
     * darf die eigentliche Aktion nicht verhindern.
     * @return string|null Pfad des Backups oder null bei Fehler
     */
    public function backup(int $keep = self::BACKUP_KEEP): ?string
    {
        try {
            $dir = dirname($this->path) . '/backup';
            if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
                throw new RuntimeException('Backup-Ordner nicht anlegbar: ' . $dir);
            }
            // Mikrosekunden im Namen: eindeutig und lexikografisch = chronologisch
            $target = $dir . '/gc_logger-' . (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Ymd-His-u') . '.sqlite';
            $this->pdo->prepare('VACUUM INTO ?')->execute([$target]);

            $files = glob($dir . '/gc_logger-*.sqlite') ?: [];
            rsort($files);
            foreach (array_slice($files, $keep) as $old) {
                @unlink($old);
            }
            return $target;
        } catch (Throwable $e) {
            error_log('[gc_logger] Backup fehlgeschlagen: ' . $e->getMessage());
            return null;
        }
    }

    private static function isDate(mixed $v): bool
    {
        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1
            && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4));
    }

    /** UTC-ISO-Zeitpunkt → Datum in Ortszeit (YYYY-MM-DD). */
    public static function localDate(string $utcIso): string
    {
        return (new DateTimeImmutable($utcIso, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d');
    }

    /** Heutiges Datum in Ortszeit (Tour-/Besuchsdatum). */
    public static function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
    }

    /** Alle je verwendeten Begleiter (für Erkennung ihrer Logs in der GPX). */
    public function knownCompanions(): array
    {
        return $this->pdo->query('SELECT DISTINCT name FROM tour_companion ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Hält tour_companion synchron zur JSON-Spalte tour.companions. */
    private function syncCompanions(int $tourId, array $companions): void
    {
        $this->pdo->prepare('DELETE FROM tour_companion WHERE tour_id = ?')->execute([$tourId]);
        $ins = $this->pdo->prepare('INSERT OR IGNORE INTO tour_companion (tour_id, name) VALUES (?, ?)');
        foreach ($companions as $name) {
            $name = trim((string) $name);
            if ($name !== '') {
                $ins->execute([$tourId, $name]);
            }
        }
    }

    /** Felder, die update_tour/update_cache ändern dürfen. */
    public const TOUR_FIELDS  = ['title', 'tour_info', 'companions', 'context', 'tour_date'];
    public const CACHE_FIELDS = ['notes', 'log', 'done', 'log_type', 'visited_at', 'visited_ts'];
    /** Werden sie von Hand gesetzt, überschreibt ein Feldnotizen-Import sie nicht mehr. */
    public const MANUAL_FIELDS = ['log_type', 'visited_at', 'visited_ts'];

    /** Log-Typen in geocaching.com-Schreibweise (Export, Feldnotizen). */
    public const LOG_TYPES = ['Found it', "Didn't find it", 'Write note', 'Attended', 'Will Attend', 'Webcam Photo Taken'];

    /**
     * Start-Status eines Caches: eigenes Log/Fundstatus aus der GPX, sonst nach
     * Cache-Typ (Events → Attended, Webcams → Webcam Photo Taken), sonst Found it.
     * @param array<string,mixed> $c
     */
    public static function defaultLogType(array $c): string
    {
        $type  = (string) ($c['type'] ?? '');
        // „Fund" heißt bei Events/Webcams anders (Found it gibt es dort nicht)
        $found = stripos($type, 'event') !== false ? 'Attended'
            : (stripos($type, 'webcam') !== false ? 'Webcam Photo Taken' : 'Found it');
        $own = (string) ($c['own_log']['type'] ?? '');
        if ($own === 'Found it') {
            return $found;
        }
        return in_array($own, self::LOG_TYPES, true) ? $own : $found;
    }

    /** SHA-256 der sortierten, eindeutigen GC-Codes (robust gegen Neu-Exporte). */
    public static function codesHash(array $codes): string
    {
        $codes = array_values(array_unique(array_filter(array_map(
            static fn ($c): string => strtoupper(trim((string) $c)),
            $codes
        ))));
        sort($codes);
        return hash('sha256', implode(',', $codes));
    }

    /**
     * Sucht die Tour, die am meisten Caches der GPX enthält.
     * - exact:   alle GPX-Caches sind schon in der Tour (nichts Neues)
     * - partial: mind. die Hälfte bekannt, aber neue dabei
     * - none:    sonst (einzelne Wiederbesuche sind Sache der Duplikat-Warnung)
     * @param list<array<string,mixed>> $caches geparste GPX-Caches
     * @return array<string,mixed>
     */
    public function findMatch(array $caches): array
    {
        $codes = array_values(array_unique(array_filter(array_map(
            static fn (array $c): string => strtoupper(trim((string) ($c['code'] ?? ''))),
            $caches
        ))));
        $total = count($codes);
        if ($total === 0) {
            return ['type' => 'none'];
        }

        $in   = implode(',', array_fill(0, $total, '?'));
        $stmt = $this->pdo->prepare(
            "SELECT t.id, t.title, t.tour_date, COUNT(DISTINCT upper(c.code)) AS known,
                    (SELECT COUNT(*) FROM cache_log x WHERE x.tour_id = t.id) AS tour_total
             FROM cache_log c JOIN tour t ON t.id = c.tour_id
             WHERE upper(c.code) IN ($in)
             GROUP BY t.id
             ORDER BY known DESC, t.id DESC
             LIMIT 1"
        );
        $stmt->execute($codes);
        $best = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($best === false || (int) $best['known'] * 2 < $total) {
            return ['type' => 'none', 'total' => $total];
        }

        return [
            'type'       => (int) $best['known'] === $total ? 'exact' : 'partial',
            'tour_id'    => (int) $best['id'],
            'title'      => $best['title'],
            'tour_date'  => $best['tour_date'],
            'known'      => (int) $best['known'],
            'total'      => $total,
            'tour_total' => (int) $best['tour_total'],
        ];
    }

    /**
     * Legt eine Tour aus einer GPX an (vorher rollierendes Backup).
     * @param list<array<string,mixed>> $caches geparste GPX-Caches
     * @param array<string,mixed> $meta optional: title, tour_info, companions, tour_date
     */
    public function createTour(array $caches, string $xml, array $meta = []): int
    {
        $this->backup();

        $tourDate   = self::isDate($meta['tour_date'] ?? null) ? (string) $meta['tour_date'] : self::today();
        $companions = array_values((array) ($meta['companions'] ?? []));
        $title      = (string) ($meta['title'] ?? '') !== ''
            ? (string) $meta['title']
            : ($caches !== [] ? $caches[0]['name'] . ' …' : 'Tour');

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO tour (title, tour_info, companions, context, tour_date, gpx_hash, codes_hash, gpx_raw, updated_at)
                 VALUES (?, ?, ?, '', ?, ?, ?, ?, datetime('now'))"
            );
            $stmt->bindValue(1, $title);
            $stmt->bindValue(2, (string) ($meta['tour_info'] ?? ''));
            $stmt->bindValue(3, json_encode($companions, JSON_UNESCAPED_UNICODE));
            $stmt->bindValue(4, $tourDate);
            $stmt->bindValue(5, hash('sha256', $xml));
            $stmt->bindValue(6, self::codesHash(array_column($caches, 'code')));
            $stmt->bindValue(7, gzcompress($xml, 6), PDO::PARAM_LOB);
            $stmt->execute();
            $id = (int) $this->pdo->lastInsertId();

            $this->syncCompanions($id, $companions);
            $this->insertCaches($id, $caches, $tourDate, 0);

            $this->pdo->commit();
            return $id;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Bekannte Tour erneut hochgeladen: GPX-Felder der vorhandenen Caches auffrischen
     * (Notizen, Logs, Status, Datum bleiben) und — bei $addNew — neue Caches hinten
     * anhängen. Gibt die Zahl der neu angehängten Caches zurück.
     * @param list<array<string,mixed>> $caches
     */
    public function mergeGpx(int $tourId, array $caches, string $xml, bool $addNew): int
    {
        if ($addNew) {
            $this->backup();
        }
        $this->pdo->beginTransaction();
        try {
            $known = $this->pdo->prepare('SELECT DISTINCT upper(code) FROM cache_log WHERE tour_id = ?');
            $known->execute([$tourId]);
            $knownCodes = array_flip($known->fetchAll(PDO::FETCH_COLUMN));

            $upd = $this->pdo->prepare(
                "UPDATE cache_log SET gpx = ?, name = ?, companion_logs = ?, updated_at = datetime('now')
                 WHERE tour_id = ? AND upper(code) = ?"
            );
            // Eigenes Log ergänzt nur Fehlendes: Uhrzeit (falls leer, nicht manuell) + erledigt.
            // Status und Datum bleiben beim Re-Upload unangetastet.
            $updOwn = $this->pdo->prepare(
                "UPDATE cache_log SET done = 1,
                        visited_ts = CASE WHEN visited_ts IS NULL AND ? IS NOT NULL
                                           AND COALESCE(manual_fields, '') NOT LIKE '%visited_ts%'
                                          THEN ? ELSE visited_ts END
                 WHERE tour_id = ? AND upper(code) = ?"
            );
            $new = [];
            foreach ($caches as $c) {
                $code = strtoupper(trim((string) ($c['code'] ?? '')));
                if (isset($knownCodes[$code])) {
                    $upd->execute([
                        json_encode($c, JSON_UNESCAPED_UNICODE),
                        $c['name'] ?? '',
                        json_encode($c['companion_logs'] ?? [], JSON_UNESCAPED_UNICODE),
                        $tourId,
                        $code,
                    ]);
                    if (!empty($c['own_log'])) {
                        $ts = $c['own_log']['ts'] ?? null;
                        $updOwn->execute([$ts, $ts, $tourId, $code]);
                    }
                } elseif ($addNew) {
                    $new[] = $c;
                    $knownCodes[$code] = true;
                }
            }

            if ($new !== []) {
                $tourDate = (string) $this->pdo->query('SELECT tour_date FROM tour WHERE id = ' . $tourId)->fetchColumn();
                $maxPos   = (int) $this->pdo->query('SELECT COALESCE(MAX(position), -1) FROM cache_log WHERE tour_id = ' . $tourId)->fetchColumn();
                $this->insertCaches($tourId, $new, $tourDate, $maxPos + 1);
            }

            // Rohe GPX + Hashes auf den neuesten Upload setzen
            $all = $this->pdo->prepare('SELECT code FROM cache_log WHERE tour_id = ?');
            $all->execute([$tourId]);
            $stmt = $this->pdo->prepare(
                "UPDATE tour SET gpx_hash = ?, codes_hash = ?, gpx_raw = ?, updated_at = datetime('now') WHERE id = ?"
            );
            $stmt->bindValue(1, hash('sha256', $xml));
            $stmt->bindValue(2, self::codesHash($all->fetchAll(PDO::FETCH_COLUMN)));
            $stmt->bindValue(3, gzcompress($xml, 6), PDO::PARAM_LOB);
            $stmt->bindValue(4, $tourId, PDO::PARAM_INT);
            $stmt->execute();

            $this->pdo->commit();
            return count($new);
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @param list<array<string,mixed>> $caches */
    private function insertCaches(int $tourId, array $caches, string $visitedAt, int $startPos): void
    {
        $ins = $this->pdo->prepare(
            "INSERT INTO cache_log (tour_id, code, name, gpx, notes, log, done, position, visited_at,
                                    visited_ts, log_type, companion_logs, updated_at)
             VALUES (?, ?, ?, ?, '', '', ?, ?, ?, ?, ?, ?, datetime('now'))"
        );
        $pos = $startPos;
        foreach ($caches as $c) {
            // Eigenes, bereits abgeschicktes Log: Zeitpunkt übernehmen, als erledigt markieren
            $own     = $c['own_log'] ?? null;
            $ownTs   = $own['ts'] ?? null;
            $ownDate = $ownTs !== null ? self::localDate($ownTs) : (string) ($own['date'] ?? '');
            $ins->execute([
                $tourId,
                $c['code'] ?? '',
                $c['name'] ?? '',
                json_encode($c, JSON_UNESCAPED_UNICODE),
                $own !== null ? 1 : 0,
                $pos++,
                preg_match('/^\d{4}-\d{2}-\d{2}$/', $ownDate) ? $ownDate : $visitedAt,
                $ownTs,
                self::defaultLogType($c),
                json_encode($c['companion_logs'] ?? [], JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    /**
     * Ändert einzelne Tour-Felder (Whitelist TOUR_FIELDS).
     * @param array<string,mixed> $fields
     * @return string|null neues updated_at oder null, wenn die Tour fehlt
     */
    public function updateTour(int $id, array $fields): ?string
    {
        $fields = array_intersect_key($fields, array_flip(self::TOUR_FIELDS));
        if (array_key_exists('tour_date', $fields) && !self::isDate($fields['tour_date'])) {
            throw new InvalidArgumentException('Ungültiges Tour-Datum.');
        }
        $this->pdo->beginTransaction();
        try {
            $oldDate = null;
            if (array_key_exists('tour_date', $fields)) {
                $q = $this->pdo->prepare('SELECT tour_date FROM tour WHERE id = ?');
                $q->execute([$id]);
                $oldDate = $q->fetchColumn();
            }
            $sets = [];
            $vals = [];
            foreach ($fields as $k => $v) {
                if ($k === 'companions') {
                    $list = array_values(array_filter(array_map('trim', array_map('strval', (array) $v)), 'strlen'));
                    $this->syncCompanions($id, $list);
                    $v = json_encode($list, JSON_UNESCAPED_UNICODE);
                }
                $sets[] = $k . ' = ?';
                $vals[] = $v;
            }
            $sets[] = "updated_at = datetime('now')";
            $vals[] = $id;
            $stmt = $this->pdo->prepare('UPDATE tour SET ' . implode(', ', $sets) . ' WHERE id = ?');
            $stmt->execute($vals);
            if ($stmt->rowCount() === 0) {
                $this->pdo->rollBack();
                return null;
            }
            // Neues Tour-Datum gilt für Caches, die noch das alte tragen — außer sie haben
            // eine echte Uhrzeit (eigenes Log/Feldnotiz) oder ein von Hand gesetztes Datum.
            if (is_string($oldDate) && $oldDate !== $fields['tour_date']) {
                $this->pdo->prepare(
                    "UPDATE cache_log SET visited_at = ?, updated_at = datetime('now')
                     WHERE tour_id = ? AND visited_at = ? AND visited_ts IS NULL
                       AND COALESCE(manual_fields, '') NOT LIKE '%visited_at%'"
                )->execute([$fields['tour_date'], $id, $oldDate]);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $this->tourUpdatedAt($id);
    }

    /**
     * Ändert einzelne Cache-Felder (Whitelist CACHE_FIELDS); merkt sich von Hand
     * gesetzte MANUAL_FIELDS und zieht tour.updated_at mit.
     * @param array<string,mixed> $fields
     * @return array{tour_id:int, updated_at:string}|null
     */
    public function updateCache(int $id, array $fields, bool $manual = true): ?array
    {
        $fields = array_intersect_key($fields, array_flip(self::CACHE_FIELDS));
        if (array_key_exists('log_type', $fields) && !in_array($fields['log_type'], self::LOG_TYPES, true)) {
            throw new InvalidArgumentException('Unbekannter Log-Typ.');
        }
        if (array_key_exists('visited_at', $fields) && $fields['visited_at'] !== null && $fields['visited_at'] !== ''
            && !self::isDate($fields['visited_at'])) {
            throw new InvalidArgumentException('Ungültiges Datum.');
        }
        if (array_key_exists('visited_ts', $fields) && $fields['visited_ts'] !== null && $fields['visited_ts'] !== ''
            && !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $fields['visited_ts'])) {
            throw new InvalidArgumentException('Ungültiger Zeitpunkt.');
        }
        $stmt = $this->pdo->prepare('SELECT tour_id, manual_fields FROM cache_log WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $sets = [];
        $vals = [];
        foreach ($fields as $k => $v) {
            if ($k === 'done') {
                $v = !empty($v) && $v !== 'false' && $v !== '0' ? 1 : 0;
            } elseif ($v !== null) {
                $v = (string) $v;
            }
            if (($k === 'visited_ts' || $k === 'visited_at') && $v === '') {
                $v = null;
            }
            $sets[] = $k . ' = ?';
            $vals[] = $v;
        }
        if ($manual) {
            $man = json_decode((string) ($row['manual_fields'] ?? '[]'), true) ?: [];
            $man = array_values(array_unique(array_merge($man, array_intersect(array_keys($fields), self::MANUAL_FIELDS))));
            $sets[] = 'manual_fields = ?';
            $vals[] = json_encode($man);
        }
        $sets[] = "updated_at = datetime('now')";
        $vals[] = $id;

        $tourId = (int) $row['tour_id'];
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE cache_log SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
            $this->pdo->prepare("UPDATE tour SET updated_at = datetime('now') WHERE id = ?")->execute([$tourId]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return ['tour_id' => $tourId, 'updated_at' => (string) $this->tourUpdatedAt($tourId)];
    }

    /**
     * Neue Reihenfolge (alle Cache-IDs der Tour). Setzt manually_sorted, wenn $manual.
     * @param list<int> $cacheIds
     */
    public function reorder(int $tourId, array $cacheIds, bool $manual = true): ?string
    {
        $cacheIds = array_map('intval', $cacheIds);
        $stmt = $this->pdo->prepare('SELECT id FROM cache_log WHERE tour_id = ?');
        $stmt->execute([$tourId]);
        $existing = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $sortedA = $existing;
        $sortedB = $cacheIds;
        sort($sortedA);
        sort($sortedB);
        if ($existing === [] || $sortedA !== $sortedB) {
            throw new InvalidArgumentException('Reihenfolge passt nicht zu den Caches der Tour.');
        }

        $this->pdo->beginTransaction();
        try {
            $upd = $this->pdo->prepare('UPDATE cache_log SET position = ? WHERE id = ?');
            foreach ($cacheIds as $pos => $cid) {
                $upd->execute([$pos, $cid]);
            }
            $this->pdo->prepare(
                "UPDATE tour SET updated_at = datetime('now')" . ($manual ? ', manually_sorted = 1' : '') . ' WHERE id = ?'
            )->execute([$tourId]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $this->tourUpdatedAt($tourId);
    }

    /**
     * c:geo-Feldnotizen übernehmen: je Cache Status, Datum, Uhrzeit (außer von Hand
     * gesetzte Felder) und Fundnummer. Notizen/Logs bleiben unberührt.
     * @param list<array{code:string, ts:string, log_type:string, find_no:?int}> $entries
     * @return array{matched:int, unknown:list<string>, missing:int, kept_manual:int}
     */
    public function applyFieldNotes(int $tourId, array $entries): array
    {
        $byCode = [];
        foreach ($entries as $e) {
            $byCode[strtoupper($e['code'])] = $e;
        }

        $stmt = $this->pdo->prepare('SELECT id, upper(code) AS code, manual_fields FROM cache_log WHERE tour_id = ?');
        $stmt->execute([$tourId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $matched    = 0;
        $keptManual = 0;
        $seen       = [];
        $this->pdo->beginTransaction();
        try {
            foreach ($rows as $row) {
                $e = $byCode[$row['code']] ?? null;
                if ($e === null) {
                    continue;
                }
                $seen[$row['code']] = true;
                $matched++;
                $manual = json_decode((string) ($row['manual_fields'] ?? '[]'), true) ?: [];

                $sets = ["fieldnote_ts = ?", "find_no = ?", "updated_at = datetime('now')"];
                $vals = [$e['ts'], $e['find_no']];
                $want = ['visited_ts' => $e['ts'], 'visited_at' => self::localDate($e['ts'])];
                if (in_array($e['log_type'], self::LOG_TYPES, true)) {
                    $want['log_type'] = $e['log_type'];
                }
                foreach ($want as $field => $value) {
                    if (in_array($field, $manual, true)) {
                        $keptManual++;
                        continue;
                    }
                    $sets[] = $field . ' = ?';
                    $vals[] = $value;
                }
                $vals[] = (int) $row['id'];
                $this->pdo->prepare('UPDATE cache_log SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
            }
            $this->pdo->prepare("UPDATE tour SET fieldnotes_at = datetime('now'), updated_at = datetime('now') WHERE id = ?")
                ->execute([$tourId]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        // „ohne Feldnotiz" = auch aus früheren Importen keine (die behalten ihre Daten)
        $missing = $this->pdo->prepare('SELECT COUNT(*) FROM cache_log WHERE tour_id = ? AND fieldnote_ts IS NULL');
        $missing->execute([$tourId]);

        return [
            'matched'     => $matched,
            'unknown'     => array_values(array_diff(array_keys($byCode), array_keys($seen))),
            'missing'     => (int) $missing->fetchColumn(),
            'kept_manual' => $keptManual,
        ];
    }

    /**
     * Nach Fundzeit sortieren: zuerst nach Datum (Mehrtagestouren), innerhalb eines
     * Tages Caches mit Uhrzeit chronologisch (Gleichstand: Fundnummer), danach die
     * ohne Uhrzeit in bisheriger Reihenfolge. Quelle der Uhrzeit ist egal
     * (Feldnotiz, eigenes Log, Handeingabe). Setzt manually_sorted zurück.
     */
    public function sortByTime(int $tourId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id, COALESCE(c.visited_at, t.tour_date) AS day, c.visited_ts AS ts, c.find_no
             FROM cache_log c JOIN tour t ON t.id = c.tour_id
             WHERE c.tour_id = ? ORDER BY c.position'
        );
        $stmt->execute([$tourId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return null;
        }
        foreach ($rows as $i => &$r) {
            $r['pos'] = $i;
        }
        unset($r);
        usort($rows, static fn (array $a, array $b): int =>
            [(string) $a['day'], $a['ts'] === null, (string) $a['ts'], (int) $a['find_no'], $a['pos']]
            <=> [(string) $b['day'], $b['ts'] === null, (string) $b['ts'], (int) $b['find_no'], $b['pos']]);

        $updatedAt = $this->reorder($tourId, array_map('intval', array_column($rows, 'id')), false);
        $this->pdo->prepare('UPDATE tour SET manually_sorted = 0 WHERE id = ?')->execute([$tourId]);
        return $updatedAt;
    }

    private function tourUpdatedAt(int $id): ?string
    {
        $stmt = $this->pdo->prepare('SELECT updated_at FROM tour WHERE id = ?');
        $stmt->execute([$id]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    /** @return array<int, array<string,mixed>> */
    public function listTours(): array
    {
        $sql = 'SELECT t.id, t.created_at, t.title, t.context,
                       (SELECT COUNT(*) FROM cache_log c WHERE c.tour_id = t.id) AS cache_count,
                       (SELECT COUNT(*) FROM cache_log c WHERE c.tour_id = t.id AND c.done = 1) AS done_count
                FROM tour t
                ORDER BY t.id DESC';
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed>|null */
    public function getTour(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, created_at, title, tour_info, companions, context, updated_at, tour_date,
                    gpx_hash, codes_hash, manually_sorted, fieldnotes_at, gpx_raw IS NOT NULL AS has_gpx_raw
             FROM tour WHERE id = ?'
        );
        $stmt->execute([$id]);
        $tour = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($tour === false) {
            return null;
        }

        $tour['companions'] = json_decode((string) ($tour['companions'] ?? '[]'), true) ?: [];
        $tour['manually_sorted'] = (bool) $tour['manually_sorted'];
        $tour['has_gpx_raw']     = (bool) $tour['has_gpx_raw'];

        $cs = $this->pdo->prepare('SELECT * FROM cache_log WHERE tour_id = ? ORDER BY position');
        $cs->execute([$id]);

        $caches = [];
        foreach ($cs->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $c          = json_decode((string) ($row['gpx'] ?? '{}'), true) ?: [];
            $c['id']    = (int) $row['id'];
            $c['notes'] = $row['notes'] ?? '';
            $c['log']   = $row['log'] ?? '';
            $c['done']  = (bool) $row['done'];
            $c['log_type']      = $row['log_type'];
            $c['visited_at']    = $row['visited_at'];
            $c['visited_ts']    = $row['visited_ts'];
            $c['find_no']       = $row['find_no'] !== null ? (int) $row['find_no'] : null;
            $c['manual_fields'] = json_decode((string) ($row['manual_fields'] ?? '[]'), true) ?: [];
            $c['updated_at']    = $row['updated_at'];
            $c['companion_logs'] = json_decode((string) ($row['companion_logs'] ?? '[]'), true) ?: [];
            $c['fieldnote_ts']   = $row['fieldnote_ts'];
            $caches[]   = $c;
        }
        $tour['caches'] = $caches;

        return $tour;
    }

    /** Löscht eine Tour samt ihrer Cache-Logs. Gibt true zurück, wenn etwas gelöscht wurde. */
    public function deleteTour(int $id): bool
    {
        // cache_log explizit löschen (unabhängig von ON DELETE CASCADE)
        $this->pdo->prepare('DELETE FROM cache_log WHERE tour_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM tour_companion WHERE tour_id = ?')->execute([$id]);
        $stmt = $this->pdo->prepare('DELETE FROM tour WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }
}
