<?php

declare(strict_types=1);

/**
 * Parser für c:geo-Feldnotizen (geocache_visits.txt).
 *
 * Format: je Eintrag  GC-Code,Zeitstempel-UTC,Log-Typ,"Text"  — Text in Quotes,
 * mehrzeilig, innere " verdoppelt. c:geo schreibt UTF-16 LE ohne BOM mit LF; toleriert
 * werden auch BOM, UTF-16 BE, UTF-8 und CRLF.
 *
 * Aus dem Text wird nur die Nummernzeile ausgewertet (z. B. "*#11579 • 12:56 Uhr*"),
 * und nur als eigene Zeile — Cache-Namen enthalten selbst "#". Signatur & Co. werden
 * verworfen.
 */
final class FieldNotes
{
    private const NUMBER_LINE = '/^\*?#(\d+)\s*[•.\-]\s*(\d{1,2}:\d{2})(?:\s*Uhr)?\*?$/mu';

    /**
     * @return list<array{code:string, ts:string, log_type:string, find_no:?int, local_time:?string}>
     *         je GC-Code nur der neueste Eintrag, sortiert nach Zeitstempel
     */
    public static function parse(string $raw): array
    {
        $text = self::decode($raw);
        if (trim($text) === '') {
            throw new RuntimeException('Die Feldnotizen-Datei ist leer.');
        }

        $byCode = [];
        foreach (self::records($text) as $rec) {
            if (count($rec) < 3) {
                continue;
            }
            $code = strtoupper(trim($rec[0]));
            $ts   = self::utcIso(trim($rec[1]));
            if (!preg_match('/^GC[0-9A-Z]+$/', $code) || $ts === null) {
                continue;
            }
            $findNo    = null;
            $localTime = null;
            if (preg_match(self::NUMBER_LINE, str_replace("\r", '', $rec[3] ?? ''), $m)) {
                $findNo    = (int) $m[1];
                $localTime = str_pad($m[2], 5, '0', STR_PAD_LEFT);
            }
            $entry = [
                'code'       => $code,
                'ts'         => $ts,
                'log_type'   => trim($rec[2]),
                'find_no'    => $findNo,
                'local_time' => $localTime,
            ];
            if (!isset($byCode[$code]) || $ts > $byCode[$code]['ts']) {
                $byCode[$code] = $entry;
            }
        }
        if ($byCode === []) {
            throw new RuntimeException('In der Datei wurden keine Feldnotizen erkannt.');
        }

        $entries = array_values($byCode);
        usort($entries, static fn (array $a, array $b): int => $a['ts'] <=> $b['ts']);
        return $entries;
    }

    /** Bytes → UTF-8-String (BOM bzw. Null-Byte-Muster erkennen). */
    public static function decode(string $raw): string
    {
        if (str_starts_with($raw, "\xFF\xFE")) {
            return mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
        }
        if (str_starts_with($raw, "\xFE\xFF")) {
            return mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
        }
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            return substr($raw, 3);
        }
        // Ohne BOM: ASCII-lastiger Text in UTF-16 hat Null-Bytes an jeder zweiten Stelle
        $sample = substr($raw, 0, 200);
        $len    = strlen($sample) - strlen($sample) % 2;
        $zeroEven = 0;
        $zeroOdd  = 0;
        for ($i = 0; $i < $len; $i += 2) {
            $zeroEven += $sample[$i] === "\0" ? 1 : 0;
            $zeroOdd  += $sample[$i + 1] === "\0" ? 1 : 0;
        }
        if ($len > 0 && $zeroOdd > $len / 4) {
            return mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
        }
        if ($len > 0 && $zeroEven > $len / 4) {
            return mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE');
        }
        return $raw;
    }

    /**
     * CSV-artig zerlegen: Komma trennt Felder, Zeilenende (außerhalb von Quotes)
     * trennt Einträge, "" in Quotes ist ein ".
     * @return list<list<string>>
     */
    public static function records(string $text): array
    {
        $records = [];
        $fields  = [];
        $field   = '';
        $quoted  = false;
        $n       = strlen($text);
        for ($i = 0; $i < $n; $i++) {
            $ch = $text[$i];
            if ($quoted) {
                if ($ch === '"') {
                    if ($i + 1 < $n && $text[$i + 1] === '"') {
                        $field .= '"';
                        $i++;
                    } else {
                        $quoted = false;
                    }
                } else {
                    $field .= $ch;
                }
            } elseif ($ch === '"') {
                $quoted = true;
            } elseif ($ch === ',') {
                $fields[] = $field;
                $field    = '';
            } elseif ($ch === "\n" || $ch === "\r") {
                if ($ch === "\r" && $i + 1 < $n && $text[$i + 1] === "\n") {
                    $i++;
                }
                $fields[] = $field;
                if (implode('', $fields) !== '') {
                    $records[] = $fields;
                }
                $fields = [];
                $field  = '';
            } else {
                $field .= $ch;
            }
        }
        $fields[] = $field;
        if (implode('', $fields) !== '') {
            $records[] = $fields;
        }
        return $records;
    }

    /** "2026-11-01T11:56:04Z" → normiertes UTC-ISO oder null. */
    private static function utcIso(string $s): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:?\d{2})?$/', $s)) {
            return null;
        }
        try {
            $dt = new DateTimeImmutable($s, new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
