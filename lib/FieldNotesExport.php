<?php

declare(strict_types=1);

/**
 * Erzeugt Draft-Dateien für geocaching.com („Drafts → Upload") exakt im Format der
 * c:geo-Feldnotizen (geocache_visits.txt):
 *   GCXXXXX,2026-10-31T14:05:00Z,Found it,"Log-Text"
 * UTF-16 LE ohne BOM, Zeilenende LF (auch nach dem letzten Eintrag), Zeitstempel UTC,
 * Text in Anführungszeichen (mehrzeilig erlaubt, innere " verdoppelt), neuester
 * Eintrag zuerst — wie c:geo.
 *
 * Einzige Abweichung: Der Draft-Import von geocaching.com verwirft leere Zeilen
 * (Absätze kleben zusammen; auch mit CRLF oder geschütztem Leerzeichen). Leere Zeilen
 * im Text bekommen daher ein unsichtbares Null-Breite-Leerzeichen (U+200B) — im
 * Upload-Test vom 01.11.2026 die einzige Variante, die Absätze erhält.
 */
final class FieldNotesExport
{
    public const FILENAME = 'geocache_visits.txt';
    private const BLANK_LINE = "\u{200B}";

    /** Dateiname mit Tour-Datum und -Nummer, z. B. geocache_visits_2026-10-31_tour45.txt */
    public static function filename(array $tour): string
    {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($tour['tour_date'] ?? '')) ? $tour['tour_date'] : '';
        return 'geocache_visits' . ($date !== '' ? '_' . $date : '') . '_tour' . (int) ($tour['id'] ?? 0) . '.txt';
    }
    private const TZ = 'Europe/Berlin';

    /** Geschätzte Uhrzeiten: ab 10:00 Ortszeit in 10-Minuten-Schritten je Tag. */
    private const ESTIMATE_START = '10:00';
    private const ESTIMATE_STEP_MIN = 10;

    /**
     * Zeitstempel je Cache: echte Uhrzeit (visited_ts) oder geschätzt (fortlaufend
     * ab 10:00 Ortszeit in Tour-Reihenfolge, je Datum neu beginnend).
     * @param list<array<string,mixed>> $caches in Tour-Reihenfolge (visited_at, visited_ts)
     * @return list<array{ts:string, estimated:bool}> gleiche Reihenfolge
     */
    public static function schedule(array $caches, string $tourDate): array
    {
        $tz      = new DateTimeZone(self::TZ);
        $utc     = new DateTimeZone('UTC');
        $perDay  = [];
        $out     = [];
        foreach ($caches as $c) {
            $ts = (string) ($c['visited_ts'] ?? '');
            if ($ts !== '') {
                $out[] = ['ts' => $ts, 'estimated' => false];
                continue;
            }
            $day = (string) ($c['visited_at'] ?? '') !== '' ? (string) $c['visited_at'] : $tourDate;
            $n   = $perDay[$day] = ($perDay[$day] ?? -1) + 1;
            $local = new DateTimeImmutable($day . ' ' . self::ESTIMATE_START, $tz);
            $local = $local->modify('+' . ($n * self::ESTIMATE_STEP_MIN) . ' minutes');
            $out[] = ['ts' => $local->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'), 'estimated' => true];
        }
        return $out;
    }

    /**
     * Export-Kandidaten „für mich": je Cache Text = generiertes Log, Typ = Status.
     * Vorausgewählt sind Caches mit Log, die noch nicht selbst geloggt wurden
     * (eigenes Log in der GPX). Ohne Log → nicht wählbar.
     * @param array<string,mixed> $tour Ergebnis von Db::getTour
     * @return list<array<string,mixed>> in Tour-Reihenfolge
     */
    public static function candidatesForMe(array $tour): array
    {
        $caches = $tour['caches'] ?? [];
        $times  = self::schedule($caches, (string) ($tour['tour_date'] ?? ''));
        $out    = [];
        foreach ($caches as $i => $c) {
            $text    = trim((string) ($c['log'] ?? ''));
            $already = !empty($c['own_log']);
            $doubleFind = false;
            foreach ($c['duplicates'] ?? [] as $d) {
                $doubleFind = $doubleFind || ($d['level'] ?? '') === 'warn';
            }
            $out[] = [
                'id'          => (int) $c['id'],
                'code'        => (string) $c['code'],
                'name'        => (string) ($c['name'] ?? ''),
                'log_type'    => (string) ($c['log_type'] ?? 'Found it'),
                'ts'          => $times[$i]['ts'],
                'estimated'   => $times[$i]['estimated'],
                'text'        => $text,
                'available'   => $text !== '',
                'already'     => $already,
                'double_find' => $doubleFind,
                'selected'    => $text !== '' && !$already,
            ];
        }
        return $out;
    }

    /** Zeilenenden auf LF, Rand-Leerzeilen weg, leere Zeilen innen mit U+200B füllen. */
    public static function keepBlankLines(string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text), "\n");
        return (string) preg_replace('/^[ \t]*$/m', self::BLANK_LINE, $text);
    }

    /**
     * Datei-Inhalt (Bytes, UTF-16 LE ohne BOM).
     * @param list<array{code:string, ts:string, log_type:string, text:string}> $entries
     */
    public static function build(array $entries): string
    {
        // wie c:geo: neuester Eintrag zuerst (bei Gleichstand stabile Reihenfolge)
        $sorted = array_values($entries);
        $order  = array_keys($sorted);
        usort($order, static fn (int $a, int $b): int => [$sorted[$b]['ts'], $a] <=> [$sorted[$a]['ts'], $b]);

        $lines = '';
        foreach ($order as $i) {
            $e    = $sorted[$i];
            $text = self::keepBlankLines((string) $e['text']);
            $lines .= strtoupper(trim($e['code'])) . ',' . $e['ts'] . ',' . $e['log_type'] . ','
                . '"' . str_replace('"', '""', $text) . '"' . "\n";
        }
        return mb_convert_encoding($lines, 'UTF-16LE', 'UTF-8');
    }
}
