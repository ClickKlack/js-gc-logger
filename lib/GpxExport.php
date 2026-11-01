<?php

declare(strict_types=1);

/**
 * GPX-Export (GPX 1.0 + Groundspeak-Erweiterung 1.0.1, wie Pocket Queries/c:geo) aus
 * den gespeicherten Cache-Daten — für c:geo, GSAK oder Karten-Apps.
 *
 * Je GC-Code ein Wegpunkt; jedes generierte Log (je Tour eins) wird als
 * groundspeak:log mit dem eigenen Namen als Finder angehängt. Hints stehen im
 * Klartext (creator enthält „gc_logger", der eigene Parser dekodiert dann nicht).
 * Attribute fehlen: gespeichert ist nur ihr Text, nicht die geocaching.com-ID.
 */
final class GpxExport
{
    private const GS = 'http://www.groundspeak.com/cache/1/0/1';

    /**
     * @param list<array<string,mixed>> $tours Ergebnisse von Db::getTour
     */
    public static function build(array $tours, string $finder): string
    {
        // Caches je GC-Code zusammenführen (gleicher Cache in mehreren Touren)
        $byCode = [];
        foreach ($tours as $t) {
            foreach ($t['caches'] ?? [] as $c) {
                $code = strtoupper(trim((string) ($c['code'] ?? '')));
                if ($code === '' || (string) ($c['lat'] ?? '') === '' || (string) ($c['lon'] ?? '') === '') {
                    continue;
                }
                $byCode[$code] ??= ['cache' => $c, 'logs' => []];
                if (trim((string) ($c['log'] ?? '')) !== '') {
                    $byCode[$code]['logs'][] = $c + ['tour_date' => $t['tour_date'] ?? ''];
                }
            }
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $out = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<gpx version="1.0" creator="gc_logger" xmlns="http://www.topografix.com/GPX/1/0"'
            . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:groundspeak="' . self::GS . '"'
            . ' xsi:schemaLocation="http://www.topografix.com/GPX/1/0 http://www.topografix.com/GPX/1/0/gpx.xsd '
            . self::GS . ' ' . self::GS . '/cache.xsd">' . "\n"
            . '  <name>gc_logger Export</name>' . "\n"
            . '  <desc>' . self::x(count($byCode) . ' Caches aus ' . count($tours) . ' Tour(en)') . '</desc>' . "\n"
            . '  <time>' . $now . '</time>' . "\n";

        foreach ($byCode as $code => $entry) {
            $out .= self::waypoint($code, $entry['cache'], $entry['logs'], $finder);
        }
        return $out . "</gpx>\n";
    }

    /** @param array<string,mixed> $c @param list<array<string,mixed>> $logs */
    private static function waypoint(string $code, array $c, array $logs, string $finder): string
    {
        $name  = (string) ($c['name'] ?? $code);
        $owner = trim((string) ($c['owner'] ?? '')) !== '' ? (string) $c['owner'] : (string) ($c['placed_by'] ?? '');
        $type  = (string) ($c['type'] ?? '');
        $dt    = trim(($c['difficulty'] ?? '') . '/' . ($c['terrain'] ?? ''), '/');

        $w = '  <wpt lat="' . self::x((string) $c['lat']) . '" lon="' . self::x((string) $c['lon']) . '">' . "\n"
            . '    <name>' . self::x($code) . '</name>' . "\n"
            . '    <desc>' . self::x($name . ($owner !== '' ? ' by ' . $owner : '') . ($type !== '' ? ', ' . $type : '')
                . ($dt !== '' ? ' (' . $dt . ')' : '')) . '</desc>' . "\n"
            . '    <url>https://coord.info/' . self::x($code) . '</url>' . "\n"
            . '    <urlname>' . self::x($name) . '</urlname>' . "\n"
            . '    <sym>Geocache</sym>' . "\n"
            . '    <type>Geocache' . ($type !== '' ? '|' . self::x($type) : '') . '</type>' . "\n"
            . '    <groundspeak:cache available="True" archived="False">' . "\n"
            . '      <groundspeak:name>' . self::x($name) . '</groundspeak:name>' . "\n"
            . '      <groundspeak:placed_by>' . self::x((string) ($c['placed_by'] ?? $owner)) . '</groundspeak:placed_by>' . "\n"
            . '      <groundspeak:owner>' . self::x($owner) . '</groundspeak:owner>' . "\n"
            . '      <groundspeak:type>' . self::x($type) . '</groundspeak:type>' . "\n"
            . '      <groundspeak:container>' . self::x((string) ($c['container'] ?? '')) . '</groundspeak:container>' . "\n"
            . '      <groundspeak:difficulty>' . self::x((string) ($c['difficulty'] ?? '')) . '</groundspeak:difficulty>' . "\n"
            . '      <groundspeak:terrain>' . self::x((string) ($c['terrain'] ?? '')) . '</groundspeak:terrain>' . "\n"
            . '      <groundspeak:country>' . self::x((string) ($c['country'] ?? '')) . '</groundspeak:country>' . "\n"
            . '      <groundspeak:state>' . self::x((string) ($c['state'] ?? '')) . '</groundspeak:state>' . "\n"
            . '      <groundspeak:short_description html="False">' . self::x((string) ($c['short_description'] ?? '')) . '</groundspeak:short_description>' . "\n"
            . '      <groundspeak:long_description html="False">' . self::x((string) ($c['listing'] ?? '')) . '</groundspeak:long_description>' . "\n"
            . '      <groundspeak:encoded_hints>' . self::x((string) ($c['hint'] ?? '')) . '</groundspeak:encoded_hints>' . "\n";

        if ($logs !== []) {
            $w .= '      <groundspeak:logs>' . "\n";
            foreach ($logs as $l) {
                $date = (string) ($l['visited_ts'] ?? '') !== ''
                    ? (string) $l['visited_ts']
                    : ((string) ($l['visited_at'] ?? '') !== '' ? $l['visited_at'] : $l['tour_date']) . 'T00:00:00Z';
                $w .= '        <groundspeak:log id="' . (int) ($l['id'] ?? 0) . '">' . "\n"
                    . '          <groundspeak:date>' . self::x($date) . '</groundspeak:date>' . "\n"
                    . '          <groundspeak:type>' . self::x((string) ($l['log_type'] ?? 'Found it')) . '</groundspeak:type>' . "\n"
                    . '          <groundspeak:finder>' . self::x($finder) . '</groundspeak:finder>' . "\n"
                    . '          <groundspeak:text encoded="False">' . self::x((string) $l['log']) . '</groundspeak:text>' . "\n"
                    . '        </groundspeak:log>' . "\n";
            }
            $w .= '      </groundspeak:logs>' . "\n";
        }
        return $w . '    </groundspeak:cache>' . "\n" . '  </wpt>' . "\n";
    }

    /** XML-escapen; in XML 1.0 verbotene Steuerzeichen entfernen. */
    private static function x(string $s): string
    {
        $s = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s);
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
