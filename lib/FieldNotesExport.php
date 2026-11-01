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

    /**
     * Dateiname mit Tour-Datum, -Nummer und ggf. Empfänger,
     * z. B. geocache_visits_2026-10-31_tour45.txt bzw. …_tour45_Clicki17.txt
     */
    public static function filename(array $tour, string $recipient = ''): string
    {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($tour['tour_date'] ?? '')) ? $tour['tour_date'] : '';
        $who  = trim((string) preg_replace('/[^A-Za-z0-9_\-]+/', '_', $recipient), '_');
        return 'geocache_visits' . ($date !== '' ? '_' . $date : '') . '_tour' . (int) ($tour['id'] ?? 0)
            . ($who !== '' ? '_' . $who : '') . '.txt';
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

    /** Standardvorlage für Begleiter (überschreibbar je Begleiter bzw. global in der DB). */
    public const DEFAULT_TEMPLATE = "Heute zusammen mit {mitcacher} unterwegs gewesen und „{cachename}“ gefunden.\n\n"
        . "Vielen Dank an {owner} fürs Verstecken und Pflegen!";

    /** Platzhalter, die Vorlagen kennen. */
    public const PLACEHOLDERS = ['mitcacher', 'cachename', 'owner', 'uhrzeit', 'gc_code'];

    /** „A", „A und B", „A, B und C" */
    public static function joinNames(array $names): string
    {
        $names = array_values($names);
        if (count($names) <= 1) {
            return (string) ($names[0] ?? '');
        }
        $last = array_pop($names);
        return implode(', ', $names) . ' und ' . $last;
    }

    /**
     * Alle anderen Teilnehmer aus Sicht des Empfängers: Begleiter der Tour ohne ihn,
     * dazu ich (gc_username) am Ende.
     * @param list<string> $companions
     */
    public static function mitcacher(array $companions, string $me, string $recipient): string
    {
        $others = array_values(array_filter($companions, static fn ($n): bool =>
            mb_strtolower((string) $n) !== mb_strtolower($recipient) && mb_strtolower((string) $n) !== mb_strtolower($me)));
        if ($me !== '' && mb_strtolower($me) !== mb_strtolower($recipient)) {
            $others[] = $me;
        }
        return self::joinNames($others);
    }

    /** Platzhalter in der Vorlage, die es nicht gibt (Tippfehler-Schutz). */
    public static function unknownPlaceholders(string $template): array
    {
        preg_match_all('/\{([^{}\s]+)\}/u', $template, $m);
        return array_values(array_unique(array_diff($m[1], self::PLACEHOLDERS)));
    }

    /** @param array<string,string> $vars */
    public static function render(string $template, array $vars): string
    {
        $map = [];
        foreach (self::PLACEHOLDERS as $p) {
            $map['{' . $p . '}'] = (string) ($vars[$p] ?? '');
        }
        return strtr($template, $map);
    }

    /**
     * Export-Kandidaten für einen Begleiter: Text = gerenderte Vorlage, Typ = Status.
     * Vorausgewählt: Status Fund (bzw. Teilgenommen/Webcam-Foto), sofern der Begleiter
     * laut GPX noch nicht selbst geloggt hat. Die Teilnahme je Cache wird nur hier
     * (per Abwahl) gepflegt.
     * @param array<string,mixed> $tour Ergebnis von Db::getTour
     * @return list<array<string,mixed>>
     */
    public static function candidatesForCompanion(array $tour, string $recipient, string $template, string $me): array
    {
        $caches   = $tour['caches'] ?? [];
        $times    = self::schedule($caches, (string) ($tour['tour_date'] ?? ''));
        $mit      = self::mitcacher((array) ($tour['companions'] ?? []), $me, $recipient);
        $out      = [];
        foreach ($caches as $i => $c) {
            $loggedBy = false;
            foreach ($c['companion_logs'] ?? [] as $l) {
                $loggedBy = $loggedBy || mb_strtolower((string) ($l['name'] ?? '')) === mb_strtolower($recipient);
            }
            $owner = trim((string) ($c['owner'] ?? '')) !== '' ? (string) $c['owner'] : (string) ($c['placed_by'] ?? '');
            $time  = $times[$i]['estimated'] ? '' : (new DateTimeImmutable($times[$i]['ts']))
                ->setTimezone(new DateTimeZone(self::TZ))->format('H:i');
            $isFound = in_array((string) ($c['log_type'] ?? 'Found it'), Db::FOUND_TYPES, true);
            $out[] = [
                'id'              => (int) $c['id'],
                'code'            => (string) $c['code'],
                'name'            => (string) ($c['name'] ?? ''),
                'log_type'        => (string) ($c['log_type'] ?? 'Found it'),
                'ts'              => $times[$i]['ts'],
                'estimated'       => $times[$i]['estimated'],
                'text'            => self::render($template, [
                    'mitcacher' => $mit,
                    'cachename' => (string) ($c['name'] ?? ''),
                    'owner'     => $owner,
                    'uhrzeit'   => $time,
                    'gc_code'   => (string) $c['code'],
                ]),
                'available'        => true,
                'already'          => false,
                'companion_logged' => $loggedBy,
                'not_found'        => !$isFound,
                'double_find'      => false,
                'selected'         => $isFound && !$loggedBy,
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
