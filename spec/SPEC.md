# Spezifikation — Geocaching-Log-Helfer (gc_logger)

## Ziel

Ein kleines, mobil-first Web-Tool, das beim Schreiben von Geocaching-Logs unterstützt.
Es läuft auf **Hetzner Shared-Hosting (PHP)** und wird fast ausschließlich am Handy
bedient. Aus einer hochgeladenen GPX-Datei der gefundenen Caches, allgemeinen
Tour-Infos und den Begleitern erzeugt eine KI zunächst einen gemeinsamen Tour-Kontext
und danach Cache für Cache ein individuelles Log, das per Klick in die Zwischenablage
kopiert werden kann.

## Rahmenbedingungen / Entscheidungen

- **Stack:** PHP 8 + Vanilla-JS-Frontend, **kein Build-Schritt** (läuft auf
  Shared-Hosting ohne Composer/Node).
- **KI:** ausschließlich **kostenlose Google-Gemini-API**. Der Key wird gratis über
  <https://aistudio.google.com/apikey> erstellt — **keine Kreditkarte, kein Guthaben,
  kein Billing**, nur Rate-Limits. Damit ist ein Kosten-Überlauf technisch
  ausgeschlossen.
- **Speicherung:** optional via **SQLite** (Datei-DB, kein Datenbank-Setup nötig).
- **Zugriffsschutz:** einfacher **Passwortschutz** (PHP-Session), damit der Gratis-Key
  nicht von Fremden über die URL verbraucht wird.

## Nutzerflow

1. **Login** mit Passwort.
2. **GPX hochladen** → das Tool listet alle Caches (Name, Typ, D/T).
3. **Allgemeine Tour-Infos** (Freitext) + **Begleiter** wählen. Feste Vorauswahl als
   Checkboxen: `die-steffi`, `Clicki17`, `Felix_GC`, `emblinux` + Freitextfeld für
   weitere Namen.
4. **„Kontext erzeugen"** → KI-Aufruf **Stufe 1**: aus Tour-Infos + Begleitern +
   Cache-Übersicht entsteht ein gemeinsamer **Tour-Kontext**, der für alle Einzel-Logs
   als roter Faden wiederverwendet wird.
5. **Cache für Cache:** Liste zum Anspringen; **erledigte Caches werden abgehakt**. Für
   den aktuellen Cache werden die GPX-Details angezeigt + ein **Notizfeld** für den
   konkreten Fund.
6. **„Log generieren"** → KI-Aufruf **Stufe 2**: Tour-Kontext + GPX-Daten *dieses*
   Caches + Notizen → fertiges Log. **Kopier-Button** legt es in die Zwischenablage,
   **Weiter-Button** schließt den Fund ab und springt zum nächsten offenen Cache.
7. **Optional speichern** (SQLite) → später unter „Meine Touren" nachschlagbar.

## Datenmodell (SQLite)

- **`tour`**: `id`, `created_at`, `title`, `tour_info`, `companions` (JSON),
  `context`.
- **`cache_log`**: `id`, `tour_id`, `code` (GC-Code), `name`, `gpx` (JSON-Snapshot der
  GPX-Felder), `notes`, `log` (generierter Text), `done`, `position`.

## GPX-Verarbeitung

Gelesen werden die Groundspeak-Extensions (`groundspeak:cache`): GC-Code (`wpt/name`),
Cache-Name, `placed_by` (Owner), `type`, `container`, `difficulty`, `terrain`,
`encoded_hints` (ROT13 → wird dekodiert) und Kurzbeschreibung. Fehlende Felder werden
tolerant behandelt. Format: GPX aus Pocket Queries / „Mit GPX herunterladen".

## Prompt-Design (zwei Stufen, deutsch)

- **System-Instruction:** authentische, persönliche Logs; Ich-/Wir-Form je nach
  Begleitern; Dank an den Owner ohne Spoiler; abwechslungsreiche Formulierungen;
  passende Länge; Ausgabe nur des reinen Log-Texts.
- **Stufe 1 (Kontext):** verdichtet Tour-Infos + Begleiter + Cache-Übersicht zu einem
  wiederverwendbaren Kontextabsatz.
- **Stufe 2 (pro Cache):** kombiniert Stufe-1-Kontext + GPX-Felder + Notizen zum Log.
  Der Hint wird als „nicht ins Log"-Spoiler übergeben.

## Sicherheit & Datenschutz

- Passwortschutz vor jedem KI-Aufruf.
- `config.php` (enthält Key + Passwort-Hash) und die SQLite-Datei liegen außerhalb des
  Web-Roots bzw. werden per `.htaccess` gesperrt. Der Key wird nie ins Repo committet.
- GPX- und Notiztexte werden zur Verarbeitung an Google (Gemini) gesendet. Für
  öffentliche Geocaching-Logs unkritisch, aber transparent dokumentiert.

## Nicht-Ziele

- Kein eigener Account-/Mehrbenutzer-Betrieb (Einzelnutzer, ein Passwort).
- Kein direktes Posten zu geocaching.com (Copy-Paste durch den Nutzer).
- Keine Offline-Funktion / kein PWA-Caching in v1.
