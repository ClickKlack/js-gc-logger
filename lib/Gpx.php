<?php

declare(strict_types=1);

/**
 * Parser für Geocaching-GPX-Dateien (Groundspeak-Extensions).
 * Liefert je Cache ein normalisiertes Array.
 *
 * gsak:GcNote (persönliche Cache-Notiz, enthält z. B. Final-Koordinaten) wird
 * bewusst NIE gelesen. Fremde Logs werden ignoriert — außer dem eigenen
 * (finder = $gcUsername) und denen bekannter Begleiter (ohne Text).
 */
final class Gpx
{
    /**
     * @param string        $gcUsername eigener geocaching.com-Name (erkennt das eigene Log)
     * @param list<string>  $companions bekannte Begleiter (deren Logs als Info merken)
     * @return array<int, array<string, mixed>>
     */
    public static function parse(string $xml, string $gcUsername = '', array $companions = []): array
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
        $gsakNs = null;
        foreach ($sx->getNamespaces(true) as $uri) {
            if (stripos($uri, 'gsak.net') !== false) {
                $gsakNs = $uri;
                break;
            }
        }
        $me    = mb_strtolower(trim($gcUsername));
        $known = [];
        foreach ($companions as $name) {
            $known[mb_strtolower(trim((string) $name))] = true;
        }
        unset($known['']);

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
                'owner'             => '',
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
                'attributes'        => [],
                'fav_points'        => null,
                'own_log'           => null,   // {type, date, ts} aus eigenem Log bzw. Fundstatus
                'companion_logs'    => [],     // [{name, type, date}] bekannter Begleiter
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
            $entry['owner']      = trim((string) $cache->owner);
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

            // Attribute; verneinte (inc="0") als solche kennzeichnen, falls der Text
            // es nicht schon tut (c:geo: "Nicht rollstuhlgerecht", PQ: "Wheelchair accessible")
            if (isset($cache->attributes)) {
                foreach ($cache->attributes->attribute as $attr) {
                    $text = trim((string) $attr);
                    if ($text === '') {
                        continue;
                    }
                    if ((string) $attr->attributes()['inc'] === '0' && !preg_match('/^(nicht|kein|no|not)\b/iu', $text)) {
                        $text = 'nicht: ' . $text;
                    }
                    $entry['attributes'][] = $text;
                }
            }

            // Logs: nur eigenes (neuestes) und die bekannter Begleiter, nie Texte
            $ownRaw = '';
            if (isset($cache->logs)) {
                foreach ($cache->logs->log as $log) {
                    $finder = trim((string) $log->finder);
                    $raw    = trim((string) $log->date);
                    $info   = ['type' => trim((string) $log->type), 'date' => substr($raw, 0, 10)];
                    $lc     = mb_strtolower($finder);
                    if ($me !== '' && $lc === $me) {
                        if ($entry['own_log'] === null || $raw > $ownRaw) {
                            $entry['own_log'] = $info + ['ts' => self::utcTimestamp($raw)];
                            $ownRaw = $raw;
                        }
                    } elseif (isset($known[$lc])) {
                        $entry['companion_logs'][] = ['name' => $finder] + $info;
                    }
                }
            }

            // gsak-Erweiterung: Favoritenpunkte, Fundstatus. GcNote NIE lesen (Spoiler).
            if ($gsakNs !== null) {
                $ext = $wpt->children($gsakNs)->wptExtension;
                if (isset($ext)) {
                    $fav = trim((string) $ext->FavPoints);
                    if ($fav !== '' && is_numeric($fav)) {
                        $entry['fav_points'] = (int) $fav;
                    }
                    if ($entry['own_log'] === null) {
                        $found = substr(trim((string) $ext->UserFound), 0, 10);
                        $dnf   = substr(trim((string) $ext->DNFDate), 0, 10);
                        if ($found !== '') {
                            $entry['own_log'] = ['type' => 'Found it', 'date' => $found, 'ts' => null];
                        } elseif ($dnf !== '') {
                            $entry['own_log'] = ['type' => "Didn't find it", 'date' => $dnf, 'ts' => null];
                        }
                    }
                }
            }

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
     * Log-Zeitpunkt als UTC-ISO (…Z) oder null, wenn er keine echte Uhrzeit trägt
     * (00:00:00 ist bei vielen Exporten nur Platzhalter für „nur Datum").
     */
    private static function utcTimestamp(string $raw): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $raw)) {
            return null;
        }
        try {
            $dt = new DateTimeImmutable($raw, new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }
        $dt = $dt->setTimezone(new DateTimeZone('UTC'));
        return $dt->format('H:i:s') === '00:00:00' ? null : $dt->format('Y-m-d\TH:i:s\Z');
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
