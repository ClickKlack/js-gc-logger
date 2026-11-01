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

    /** Heutiges Datum in Ortszeit (Tour-/Besuchsdatum). */
    public static function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
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

    /**
     * Speichert eine Tour inkl. Caches. Vorhandene id => Update, sonst Insert.
     * @param array<string,mixed> $t
     */
    public function saveTour(array $t): int
    {
        $this->pdo->beginTransaction();
        try {
            $companions = json_encode($t['companions'] ?? [], JSON_UNESCAPED_UNICODE);

            if (!empty($t['id'])) {
                $id = (int) $t['id'];
                $stmt = $this->pdo->prepare(
                    "UPDATE tour SET title = ?, tour_info = ?, companions = ?, context = ?, updated_at = datetime('now') WHERE id = ?"
                );
                $stmt->execute([$t['title'] ?? null, $t['tour_info'] ?? '', $companions, $t['context'] ?? '', $id]);
                $this->pdo->prepare('DELETE FROM cache_log WHERE tour_id = ?')->execute([$id]);
            } else {
                $stmt = $this->pdo->prepare(
                    "INSERT INTO tour (title, tour_info, companions, context, tour_date, updated_at)
                     VALUES (?, ?, ?, ?, ?, datetime('now'))"
                );
                $stmt->execute([$t['title'] ?? null, $t['tour_info'] ?? '', $companions, $t['context'] ?? '', self::today()]);
                $id = (int) $this->pdo->lastInsertId();
            }
            $this->syncCompanions($id, $t['companions'] ?? []);
            $tourDate = (string) $this->pdo->query('SELECT tour_date FROM tour WHERE id = ' . $id)->fetchColumn();

            $ins = $this->pdo->prepare(
                "INSERT INTO cache_log (tour_id, code, name, gpx, notes, log, done, position,
                                        log_type, visited_at, visited_ts, find_no, manual_fields, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))"
            );
            $pos = 0;
            foreach (($t['caches'] ?? []) as $c) {
                $ins->execute([
                    $id,
                    $c['code'] ?? '',
                    $c['name'] ?? '',
                    json_encode($c, JSON_UNESCAPED_UNICODE),
                    $c['notes'] ?? '',
                    $c['log'] ?? '',
                    !empty($c['done']) ? 1 : 0,
                    $pos++,
                    ($c['log_type'] ?? '') !== '' ? $c['log_type'] : 'Found it',
                    ($c['visited_at'] ?? '') !== '' ? $c['visited_at'] : $tourDate,
                    $c['visited_ts'] ?? null,
                    $c['find_no'] ?? null,
                    json_encode($c['manual_fields'] ?? []),
                ]);
            }

            $this->pdo->commit();
            return $id;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
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
        $stmt = $this->pdo->prepare('SELECT * FROM tour WHERE id = ?');
        $stmt->execute([$id]);
        $tour = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($tour === false) {
            return null;
        }

        $tour['companions'] = json_decode((string) ($tour['companions'] ?? '[]'), true) ?: [];

        $cs = $this->pdo->prepare('SELECT * FROM cache_log WHERE tour_id = ? ORDER BY position');
        $cs->execute([$id]);

        $caches = [];
        foreach ($cs->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $c          = json_decode((string) ($row['gpx'] ?? '{}'), true) ?: [];
            $c['notes'] = $row['notes'] ?? '';
            $c['log']   = $row['log'] ?? '';
            $c['done']  = (bool) $row['done'];
            $c['log_type']      = $row['log_type'];
            $c['visited_at']    = $row['visited_at'];
            $c['visited_ts']    = $row['visited_ts'];
            $c['find_no']       = $row['find_no'] !== null ? (int) $row['find_no'] : null;
            $c['manual_fields'] = json_decode((string) ($row['manual_fields'] ?? '[]'), true) ?: [];
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
