<?php
/**
 * Vorlage für config.php — kopieren und ausfüllen:
 *   cp config.example.php config.php
 *
 * config.php NIEMALS committen (steht in .gitignore).
 */

declare(strict_types=1);

return [
    // Gratis-Key von https://aistudio.google.com/apikey (keine Kreditkarte nötig)
    'gemini_api_key' => '',

    // Modellname. Default ist ein günstiges/kostenloses Flash-Modell.
    // Bei Bedarf an aktuelle Verfügbarkeit anpassen (z. B. 'gemini-flash-latest').
    'gemini_model' => 'gemini-2.5-flash',

    // Ausweich-Modelle, falls das Hauptmodell überlastet ist (503) ODER sein
    // Tages-/Minutenlimit erreicht hat (429). Kommagetrennt, wird der Reihe nach
    // probiert. WICHTIG: Jedes Modell hat ein EIGENES kostenloses Tageslimit –
    // die Kette vervielfacht also dein Tagesbudget.
    'gemini_model_fallback' => 'gemini-2.5-flash-lite,gemini-2.0-flash',

    // Passwort-Hash erzeugen (am eigenen Rechner):
    //   php -r "echo password_hash('DEIN-PASSWORT', PASSWORD_DEFAULT), PHP_EOL;"
    // und das Ergebnis hier einsetzen:
    'password_hash' => '',

    // Pfad zur SQLite-Datei (außerhalb von public/ — wird bei Bedarf angelegt).
    'db_path' => __DIR__ . '/data/app.sqlite',

    // Sammel-Modus: max. Caches pro KI-Anfrage. Caches außerhalb Deutschlands
    // (zweisprachige Logs) zählen doppelt. Größere Werte = weniger Anfragen, aber
    // die letzten Logs einer langen Antwort werden schnell schlechter.
    'batch_size' => 5,

    // Optionales Speichern der Touren in SQLite aktivieren?
    'store_logs' => true,

    // Debug-Modus: schreibt ein Log nach data/debug.log und liefert in
    // Fehlermeldungen zusätzliche Details (Datei, Zeile, Trace, KI-Rohantwort).
    // Im Tool erscheint dann ein "🐞 Debug"-Bereich. Für den Live-Betrieb auf false!
    'debug' => false,

    // Feste Vorauswahl der Begleiter (Checkboxen im Frontend).
    'companions' => ['die-steffi', 'Clicki17', 'Felix_GC', 'emblinux'],
];
