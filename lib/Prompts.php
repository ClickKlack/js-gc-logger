<?php

declare(strict_types=1);

/**
 * Prompt-Bausteine an einer Stelle. Alles auf Deutsch.
 */
final class Prompts
{
    public static function system(): string
    {
        return <<<TXT
        Du hilfst beim Schreiben authentischer, persönlicher Geocaching-Logs auf Deutsch.
        Schreibe so, wie ein echter Cacher es ins Logbuch auf geocaching.com schreiben würde:
        - Natürlicher, freundlicher Ton; nicht werblich, keine Hashtags, keine Emojis-Flut.
        - Waren Begleiter dabei, schreibe in der Wir-Form, sonst in der Ich-Form.
        - Bedanke dich beim Owner für den Cache/das Versteck, ohne Spoiler zu verraten.
        - Versuche trotzdem Bezüge zum Cache-Listing herzustellen (z. B. zum Namen, zur Art des Verstecks, zum Ort).
        - Variiere Formulierungen; vermeide immer gleiche Floskeln und Wiederholungen.
        - Länge passend zur Länge des Cachelistings: meist 2–10 Sätze, bei längeeren Listings gerne etwas ausführlicher, bei kurzen Listings eher kurz und knackig.
        - Gib NUR den fertigen Log-Text zurück, ohne Anführungszeichen oder Vorbemerkungen oder Linien.
        - verwendse Smileys nur sparsam und passend zum Stil des Caches (z. B. bei einem lustigen Mystery, aber nicht bei einem ernsten EarthCache).
        - Sturkturiere das Log in Absätzen, wenn es längert ist, aber vermeide Aufzählungen oder Listen. Es soll wie ein Fließtext wirken.
        TXT;
    }

    /**
     * @param array<string>                    $companions
     * @param array<int, array<string,string>> $caches
     */
    public static function context(string $tourInfo, array $companions, array $caches): string
    {
        $companionList = $companions !== [] ? implode(', ', $companions) : 'alleine unterwegs';
        $tourInfo      = trim($tourInfo) !== '' ? trim($tourInfo) : '(keine besonderen Angaben)';

        $lines = [];
        foreach ($caches as $c) {
            $lines[] = sprintf('- %s (%s)', $c['name'] ?? 'Cache', ($c['type'] ?? '') ?: 'Cache');
        }
        $overview = implode("\n", $lines);

        return <<<TXT
        Ich bereite Geocaching-Logs für eine Tour vor. Fasse aus den folgenden Angaben einen
        gemeinsamen Tour-Kontext in 2–5 Sätzen zusammen, den ich als roten Faden für die
        einzelnen Cache-Logs wiederverwenden kann (Stimmung, Anlass, Wetter, mit wem, Region).
        Schreibe nur diesen Kontextabsatz, keine Aufzählung, keine Vorbemerkung.

        Allgemeine Infos zur Tour:
        $tourInfo

        Begleiter: $companionList

        Gefundene Caches (Übersicht):
        $overview
        TXT;
    }

    /**
     * @param array<string,string> $cache
     */
    public static function log(string $context, array $cache, string $notes): string
    {
        $context = trim($context) !== '' ? trim($context) : '(kein gesonderter Tour-Kontext)';
        $notes   = trim($notes) !== '' ? trim($notes) : '(keine besonderen Notizen)';
        $name    = $cache['name'] ?? 'dieser Cache';

        $details = [];
        if (!empty($cache['type']))       { $details[] = 'Typ: ' . $cache['type']; }
        if (!empty($cache['difficulty'])) { $details[] = 'Schwierigkeit (D): ' . $cache['difficulty']; }
        if (!empty($cache['terrain']))    { $details[] = 'Gelände (T): ' . $cache['terrain']; }
        if (!empty($cache['container']))  { $details[] = 'Behälter: ' . $cache['container']; }
        if (!empty($cache['placed_by']))  { $details[] = 'Owner: ' . $cache['placed_by']; }
        // Hint wird bewusst NICHT an die KI geschickt (sie schrieb ihn sonst ins Log).
        // Er bleibt nur lokal in der Detailansicht sichtbar.
        $detailText = $details !== [] ? implode("\n", $details) : '(keine weiteren Details)';

        $listing      = trim((string) ($cache['listing'] ?? ''));
        $listingBlock = $listing !== '' ? $listing : '(kein Listing-Text vorhanden)';

        return <<<TXT
        Schreibe ein Log für diesen einzelnen Cache. Nutze den Tour-Kontext als Hintergrund,
        wiederhole ihn aber nicht wörtlich in jedem Log und versuche zu varieren. Beziehe dich
        auf die Details dieses Caches (z. B. Typ, Schwierigkeit, Ort), wenn möglich. Schreibe
        so, als ob du den Log direkt ins Logbuch auf geocaching.com schreiben würdest.

        WICHTIG: Verarbeite meine Notizen zum Fund IMMER vollständig. Jede Information und
        jeder Punkt aus den Notizen MUSS im Log vorkommen – lasse nichts davon weg und
        verkürze sie nicht. Formuliere die Notizen dabei natürlich aus (kein Stichwort-Stil),
        aber gib ihren gesamten Inhalt wieder.

        Tour-Kontext:
        $context

        Cache: $name
        $detailText

        Cache-Listing (vollständige Beschreibung des Owners – nutze sie als Inspiration für
        thematische Bezüge, aber kopiere sie NICHT wörtlich und verrate KEINE Lösung,
        Koordinaten oder Spoiler):
        $listingBlock

        Meine Notizen zum Fund (vollständig einarbeiten):
        $notes
        TXT;
    }
}
