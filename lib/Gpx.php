<?php

declare(strict_types=1);

/**
 * Parser für Geocaching-GPX-Dateien (Groundspeak-Extensions).
 * Liefert je Cache ein normalisiertes Array.
 */
final class Gpx
{
    /**
     * @return array<int, array<string, string>>
     */
    public static function parse(string $xml): array
    {
        if (trim($xml) === '') {
            throw new RuntimeException('Die GPX-Datei ist leer.');
        }

        $prev = libxml_use_internal_errors(true);
        $sx = simplexml_load_string($xml);
        libxml_use_internal_errors($prev);

        if ($sx === false) {
            throw new RuntimeException('Die GPX-Datei konnte nicht gelesen werden (ungültiges XML).');
        }

        // Groundspeak-Namespace ermitteln (URL-Version kann variieren)
        $gsNs = null;
        foreach ($sx->getNamespaces(true) as $uri) {
            if (stripos($uri, 'groundspeak') !== false) {
                $gsNs = $uri;
                break;
            }
        }

        // Hints: In offiziellen geocaching.com-Pocket-Queries sind sie ROT13-
        // codiert. c:geo exportiert sie dagegen bereits im Klartext -> dann darf
        // NICHT dekodiert werden (sonst wird der Hint erst verschlüsselt).
        $creator     = strtolower((string) $sx['creator']);
        $decodeHints = strpos($creator, 'c:geo') === false
                    && strpos($creator, 'cgeo') === false;

        $caches = [];
        foreach ($sx->wpt as $wpt) {
            $code = trim((string) $wpt->name);
            $name = trim((string) $wpt->urlname);

            $entry = [
                'code'              => $code,
                'name'              => $name !== '' ? $name : $code,
                'type'              => '',
                'placed_by'         => '',
                'container'         => '',
                'difficulty'        => '',
                'terrain'           => '',
                'hint'              => '',
                'short_description' => '',
                'listing'           => '',
                'country'           => '',
                'state'             => '',
                'lat'               => (string) $wpt['lat'],
                'lon'               => (string) $wpt['lon'],
            ];

            // Nur echte Caches übernehmen. Kind-Wegpunkte (Stages, Finale,
            // Referenzpunkte aus c:geo/GSAK wie 01XXXXX, FNXXXXX) haben einen
            // <name>, aber KEIN <groundspeak:cache> -> diese überspringen.
            if ($gsNs === null) {
                continue;
            }
            $gs = $wpt->children($gsNs);
            if (!isset($gs->cache)) {
                continue;
            }

            $cache = $gs->cache;
            $cacheName = trim((string) $cache->name);
            if ($cacheName !== '') {
                $entry['name'] = $cacheName;
            }
            $entry['type']       = trim((string) $cache->type);
            $entry['placed_by']  = trim((string) $cache->placed_by);
            $entry['container']  = trim((string) $cache->container);
            $entry['difficulty'] = trim((string) $cache->difficulty);
            $entry['terrain']    = trim((string) $cache->terrain);
            $entry['country']    = trim((string) $cache->country);
            $entry['state']      = trim((string) $cache->state);

            // Hints ggf. aus ROT13 dekodieren (siehe $decodeHints oben)
            $rawHint = trim((string) $cache->encoded_hints);
            $entry['hint'] = ($rawHint !== '' && $decodeHints) ? str_rot13($rawHint) : $rawHint;

            $entry['short_description'] = mb_substr(self::htmlToText((string) $cache->short_description), 0, 800);

            // Vollständiges Listing (long_description) als Klartext.
            // Großzügiges Limit als Schutz gegen Extremfälle.
            $long = self::htmlToText((string) $cache->long_description);
            if ($long === '') {
                $long = $entry['short_description'];
            }
            $entry['listing'] = mb_substr($long, 0, 8000);

            if ($entry['code'] === '' && $entry['name'] === '') {
                continue; // unbrauchbarer Eintrag
            }
            $caches[] = $entry;
        }

        if ($caches === []) {
            throw new RuntimeException('In der GPX-Datei wurden keine Caches gefunden.');
        }

        return $caches;
    }

    /**
     * Wandelt einen HTML-Beschreibungstext (long/short_description) in lesbaren
     * Klartext um: Blockenden -> Zeilenumbruch, Tags entfernen, Entities dekodieren,
     * Leerraum normalisieren.
     */
    private static function htmlToText(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        // Absatz-/Zeilenenden in Umbrüche wandeln, damit Struktur erhalten bleibt
        $html = preg_replace('#<\s*(br|/p|/div|/li|/h[1-6])\s*[^>]*>#i', "\n", $html) ?? $html;
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xC2\xA0", ' ', $text);            // geschütztes Leerzeichen
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;  // Mehrfach-Leerraum
        $text = preg_replace('/\s*\n\s*/u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
