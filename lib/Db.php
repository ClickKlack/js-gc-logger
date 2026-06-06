<?php

declare(strict_types=1);

/**
 * SQLite-Persistenz via PDO. Legt DB-Datei und Schema bei Bedarf an.
 */
final class Db
{
    private PDO $pdo;

    public function __construct(string $path)
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $this->pdo = new PDO('sqlite:' . $path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->migrate();
    }

    private function migrate(): void
    {
        $schema = file_get_contents(__DIR__ . '/../schema.sql');
        if ($schema === false) {
            throw new RuntimeException('schema.sql konnte nicht gelesen werden.');
        }
        $this->pdo->exec($schema);
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
                    'UPDATE tour SET title = ?, tour_info = ?, companions = ?, context = ? WHERE id = ?'
                );
                $stmt->execute([$t['title'] ?? null, $t['tour_info'] ?? '', $companions, $t['context'] ?? '', $id]);
                $this->pdo->prepare('DELETE FROM cache_log WHERE tour_id = ?')->execute([$id]);
            } else {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO tour (title, tour_info, companions, context) VALUES (?, ?, ?, ?)'
                );
                $stmt->execute([$t['title'] ?? null, $t['tour_info'] ?? '', $companions, $t['context'] ?? '']);
                $id = (int) $this->pdo->lastInsertId();
            }

            $ins = $this->pdo->prepare(
                'INSERT INTO cache_log (tour_id, code, name, gpx, notes, log, done, position)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
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
        $stmt = $this->pdo->prepare('DELETE FROM tour WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }
}
