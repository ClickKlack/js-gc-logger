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
        Du hilfst beim Schreiben authentischer, persönlicher Geocaching-Logs auf Deutsch (bzw. zweisprachig, wenn es für einen Cache verlangt wird).
        Schreibe so, wie ein echter Cacher es ins Logbuch auf geocaching.com schreiben würde:
        - Natürlicher, freundlicher Ton; nicht werblich, keine Hashtags, keine Emojis-Flut.
        - Waren Begleiter dabei, schreibe in der Wir-Form, sonst in der Ich-Form.
        - Bedanke dich beim Owner für den Cache/das Versteck, ohne Spoiler zu verraten.
        - Attribute des Caches (z. B. „Für Kinder geeignet“, „Besondere Aussicht“) darfst du aufgreifen, wenn sie passen; zähle sie nicht auf.
        - Hat ein Cache viele Favoritenpunkte, darfst du würdigen, dass er beliebt ist bzw. zu Recht beliebt ist.
        - Richte dich nach dem angegebenen Log-Status: Ein DNF- oder Notiz-Log ist kein Fund-Log.
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
     * @param array<string>        $companions
     */
    public static function log(string $context, array $cache, string $notes, array $companions = []): string
    {
        $context = trim($context) !== '' ? trim($context) : '(kein gesonderter Tour-Kontext)';
        $companionLine = self::companionLine($companions);
        $notes   = trim($notes) !== '' ? trim($notes) : '(keine besonderen Notizen)';
        $name    = $cache['name'] ?? 'dieser Cache';

        // Hint wird bewusst NICHT an die KI geschickt (sie schrieb ihn sonst ins Log).
        // Er bleibt nur lokal in der Detailansicht sichtbar.
        $details    = self::details($cache);
        $detailText = $details !== [] ? implode("\n", $details) : '(keine weiteren Details)';
        $statusRule = self::statusRule($cache);

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

        $companionLine

        Cache: $name
        $detailText
        $statusRule

        Cache-Listing (vollständige Beschreibung des Owners – nutze sie als Inspiration für
        thematische Bezüge, aber kopiere sie NICHT wörtlich und verrate KEINE Lösung,
        Koordinaten oder Spoiler):
        $listingBlock

        Meine Notizen zum Fund (vollständig einarbeiten):
        $notes
        TXT . self::languageRule($cache);
    }

    /**
     * Sammel-Prompt: erzeugt in EINEM Aufruf Logs für mehrere Caches.
     * Ausgabe als JSON-Array [{"code": "...", "log": "..."}].
     *
     * @param array<int, array<string,string>> $caches je Eintrag inkl. 'notes'
     * @param array<string>                    $companions
     */
    public static function logBatch(string $context, array $caches, array $companions = []): string
    {
        $context = trim($context) !== '' ? trim($context) : '(kein gesonderter Tour-Kontext)';
        $companionLine = self::companionLine($companions);

        $blocks = [];
        foreach ($caches as $c) {
            $code = (string) ($c['code'] ?? '');
            $name = (string) ($c['name'] ?? 'Cache');

            $details    = self::details($c);
            $detailLine = $details !== [] ? implode(', ', $details) : '(keine weiteren Details)';
            $statusRule = self::statusRule($c);

            $listing = trim((string) ($c['listing'] ?? ''));
            $listing = $listing !== '' ? $listing : '(kein Listing-Text vorhanden)';

            $notes = trim((string) ($c['notes'] ?? ''));
            $notes = $notes !== '' ? $notes : '(keine besonderen Notizen)';

            $blocks[] = <<<BLOCK
            ===== CACHE code=$code =====
            Name: $name
            $detailLine
            $statusRule
            Listing (nur als Inspiration, nicht wörtlich kopieren, keine Spoiler/Lösung/Koordinaten): $listing
            Meine Notizen (vollständig einarbeiten): $notes
            BLOCK . self::languageRule($c);
        }
        $cacheBlocks = implode("\n\n", $blocks);

        return <<<TXT
        Schreibe für JEDEN der folgenden Caches ein eigenes, individuelles Log nach den oben
        genannten Stil-Regeln.

        WICHTIG: Jedes Log wird einzeln beim jeweiligen Cache auf geocaching.com veröffentlicht
        und dort OHNE die anderen Logs gelesen. Jedes Log muss daher für sich allein vollständig
        sein – genauso ausführlich, als würdest du nur dieses eine Log schreiben. Die Menge der
        Caches ist KEIN Grund, die Logs kürzer zu machen. Beachte pro Cache:
        - Beginne JEDES Log mit einer kurzen Einleitung zur Tour (Anlass, Stimmung, Wetter o. Ä. aus dem Tour-Kontext). Formuliere diese Einleitung in jedem Log anders, lass sie aber nicht weg.
        - Waren Begleiter dabei, nenne sie in JEDEM Log namentlich.
        - Variieren heißt: anders formulieren – NICHT Inhalte weglassen.
        - Beziehe dich auf die Details und das Listing des jeweiligen Caches; die Länge richtet sich wie gewohnt nach der Länge des Listings.
        - Verarbeite die Notizen des jeweiligen Caches IMMER vollständig: jede Information daraus MUSS im zugehörigen Log vorkommen.
        - Absätze im Logtext als \\n\\n innerhalb des JSON-Strings kodieren.
        - Steht bei einem Cache eine SPRACHE-Anweisung, gilt sie für dessen Log (beide Sprachteile gehören zusammen in denselben "log"-String).

        Gib AUSSCHLIESSLICH ein JSON-Array zurück – ein Objekt pro Cache, exakt in dieser Form:
        [{"code": "<GC-Code des Caches>", "log": "<fertiger Logtext>"}]
        Keine Erklärungen, kein Markdown, nur das JSON-Array. Verwende exakt die unten angegebenen Codes.

        Tour-Kontext:
        $context

        $companionLine

        Caches:
        $cacheBlocks
        TXT;
    }

    /**
     * @param array<string> $companions
     */
    private static function companionLine(array $companions): string
    {
        return $companions !== []
            ? 'Begleiter (im Log namentlich erwähnen): ' . implode(', ', $companions)
            : 'Begleiter: keine (alleine unterwegs, Ich-Form)';
    }

    /**
     * Detailzeilen für den Prompt. Owner (groundspeak:owner) für den Dank;
     * placed_by nur zusätzlich, wenn abweichend (kann mehrere Namen enthalten).
     * @param array<string,mixed> $c
     * @return list<string>
     */
    private static function details(array $c): array
    {
        $d = [];
        $owner    = trim((string) ($c['owner'] ?? ''));
        $placedBy = trim((string) ($c['placed_by'] ?? ''));
        if ($owner === '') {
            $owner = $placedBy;
        }
        if (!empty($c['type']))       { $d[] = 'Typ: ' . $c['type']; }
        if (!empty($c['difficulty'])) { $d[] = 'Schwierigkeit (D): ' . $c['difficulty']; }
        if (!empty($c['terrain']))    { $d[] = 'Gelände (T): ' . $c['terrain']; }
        if (!empty($c['container']))  { $d[] = 'Behälter: ' . $c['container']; }
        if ($owner !== '')            { $d[] = 'Owner (für den Dank): ' . $owner; }
        if ($placedBy !== '' && mb_strtolower($placedBy) !== mb_strtolower($owner)) {
            $d[] = 'Gelegt von: ' . $placedBy;
        }
        if (!empty($c['country']))    { $d[] = 'Ort: ' . self::location($c); }
        if (!empty($c['attributes']) && is_array($c['attributes'])) {
            $d[] = 'Attribute: ' . implode(', ', $c['attributes']);
        }
        if (isset($c['fav_points']) && $c['fav_points'] !== null && $c['fav_points'] !== '') {
            $d[] = 'Favoritenpunkte: ' . (int) $c['fav_points'];
        }
        return $d;
    }

    /**
     * Anweisung je Log-Status (geocaching.com-Typ). Fund ist der Normalfall.
     * @param array<string,mixed> $c
     */
    private static function statusRule(array $c): string
    {
        return match ((string) ($c['log_type'] ?? 'Found it')) {
            "Didn't find it"     => 'LOG-STATUS: NICHT GEFUNDEN (DNF). Schreibe ein DNF-Log: Wir haben gesucht, '
                . 'aber nicht gefunden. Kein Dank fürs Finden und kein „gefunden“/„geloggt“; gerne Dank für den '
                . 'Cache bzw. den schönen Ort. Keine Vermutungen über das Versteck (Spoiler).',
            'Write note'         => 'LOG-STATUS: NOTIZ. Kein Fund-Log: Schreibe eine Notiz zum Besuch '
                . '(z. B. Hinweis zum Zustand oder kurzer Besuchsbericht laut Notizen), ohne einen Fund zu behaupten.',
            'Attended'           => 'LOG-STATUS: EVENT BESUCHT. Schreibe ein Teilnahme-Log für das Event '
                . '(Begegnungen, Stimmung, Dank an die Veranstalter) statt eines Fund-Logs.',
            'Will Attend'        => 'LOG-STATUS: TEILNAHME ANGEKÜNDIGT. Das Event liegt in der Zukunft: '
                . 'kurze, freundliche Ankündigung, dass wir kommen – kein Erlebnisbericht.',
            'Webcam Photo Taken' => 'LOG-STATUS: WEBCAM-FOTO. Wir haben uns vor der Webcam fotografiert; '
                . 'schreibe das Log entsprechend.',
            default              => 'LOG-STATUS: GEFUNDEN.',
        };
    }

    /** Liegt der Cache außerhalb Deutschlands? Ohne Länderangabe: nein. */
    public static function isForeign(array $cache): bool
    {
        $country = mb_strtolower(trim((string) ($cache['country'] ?? '')));
        return $country !== '' && !in_array($country, ['germany', 'deutschland'], true);
    }

    /** @param array<string,mixed> $cache */
    private static function location(array $cache): string
    {
        $state = trim((string) ($cache['state'] ?? ''));
        return trim((string) $cache['country']) . ($state !== '' ? " ($state)" : '');
    }

    /**
     * Zusatzanweisung für Caches außerhalb Deutschlands: Log zweisprachig
     * (Landessprache + Deutsch), jeweils mit Flagge gekennzeichnet.
     *
     * @param array<string,mixed> $cache
     */
    private static function languageRule(array $cache): string
    {
        if (!self::isForeign($cache)) {
            return '';
        }
        $loc = self::location($cache);

        return "\n\n" . <<<TXT
        SPRACHE: Dieser Cache liegt in $loc, also nicht in Deutschland. Schreibe das Log
        deshalb in ZWEI Teilen:
        1. Zuerst das vollständige Log in der Landessprache am Cache-Ort (bei mehrsprachigen
           Ländern die dort übliche Sprache – Region und Sprache des Listings helfen dabei).
           Beginne diesen Teil mit dem Flaggen-Emoji des Landes.
        2. Danach, durch eine Leerzeile getrennt, dasselbe Log auf Deutsch, beginnend mit 🇩🇪.
        Beide Teile haben denselben Inhalt (inkl. aller Notizen), natürlich formuliert statt
        wörtlich übersetzt. Keine weiteren Überschriften oder Trennlinien.
        Ausnahme: Ist die Landessprache am Cache-Ort Deutsch (z. B. Österreich, Liechtenstein,
        deutschsprachige Schweiz, Südtirol), schreibe nur ein deutsches Log ohne Flagge.
        TXT;
    }
}
