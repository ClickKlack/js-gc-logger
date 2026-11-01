# Spezifikation v2 — Erweiterungen gc_logger

Status: **Abgestimmt (bereit für Umsetzungsplan)** · Stand: 2026-11-01 · Quelle: `spec/ideas.md`
Basis: `spec/SPEC.md` (v1) und aktueller Code-Stand (Commit `3de0aa7`).

Dieses Dokument beschreibt die nächsten Ausbaustufen. Es enthält **keinen Code**, sondern
Anforderungen, Entscheidungen und offene Fragen. Es dient später als Grundlage für den
Umsetzungsplan.

Legende: **[E]** = entschieden · **[?]** = offen

---

## 0. Überblick

| # | Thema | Kern | Priorität |
|---|-------|------|-----------------------|
| F1 | Dauerhafter Login | Nicht bei jedem Besuch neu einloggen | hoch |
| F2 | Live-Speichern / kein Datenverlust | Eingaben überleben Browser-Reload; Tour entsteht automatisch | hoch |
| F3 | Land + Log-Status | Land sichtbar (Ursache prüfen!), Fund/DNF/Notiz pflegbar | mittel |
| F4 | Tour-Verwaltung & Suche | Suche nach GC-Code, Datum, Mitcacher | mittel |
| F5 | Doppelte GC-Codes erkennen | Deutlicher Hinweis, wenn Cache schon in einer Tour war | mittel |
| F6 | Field-Notes-Export | Draft-Datei für Begleiter (Vorlage) und für mich (KI-Logs) | mittel |
| F7 | Datenhaltung prüfen | Reicht SQLite? | niedrig (Analyse) |
| F8 | Backup | Rollierend auf Server, lokal bei Deploy, manueller Export | hoch |

---

## F1 — Dauerhafter Login

### Problem
Heute: PHP-Session mit Standard-Cookie (gültig bis Browser-Ende) und Server-Session-
Lebensdauer `session.gc_maxlifetime` (Default 24 min, auf Shared-Hosting oft vom Provider
aufgeräumt). Am Handy wird der Browser-Prozess häufig beendet → Login weg.

### Anforderungen
- Nach einmaligem Login bleibt das Gerät **180 Tage gleitend** angemeldet. [E]
- Logout-Button meldet das aktuelle Gerät ab.
- Sicherheit darf nicht spürbar sinken: der Gemini-Key darf weiterhin nicht über die URL
  missbraucht werden können.

### Lösungsansatz [E]
- **„Angemeldet bleiben"-Token** (Remember-me):
  - Bei Login wird ein zufälliges Token erzeugt, im Cookie gespeichert
    (`HttpOnly`, `Secure`, `SameSite=Lax`, Ablauf = gewählte Dauer).
  - In der DB wird nur der **Hash** des Tokens gespeichert (Tabelle `auth_token`:
    `id`, `token_hash`, `created_at`, `last_used_at`, `expires_at`, `user_agent`).
  - Fehlt die PHP-Session, aber ein gültiges Token ist da → Session wird neu aufgebaut,
    Ablauf wird gleitend verlängert (sliding expiration).
  - Passwortänderung in `config.php` ⇒ alle Tokens ungültig (z. B. Hash des Passwort-Hashes
    mitspeichern und vergleichen).
- Unabhängig davon die eigene PHP-Session-Ablage nutzen (`session.save_path` in `data/`),
  damit der Provider-Cleanup nicht greift — optional, Token reicht allein.

### Offene Punkte
- [E] Mehrere Geräte (Handy + Laptop) gleichzeitig angemeldet, ein Token pro Gerät.

---

## F2 — Live-Speichern und kein Datenverlust

### Problem
Beim Notizen-Tippen am Handy wird der Tab nach einer Pause vom Browser verworfen/neu
geladen. Der komplette Zustand liegt nur im JS-Speicher → alles weg, wenn nicht explizit
„Speichern" gedrückt wurde.

### Anforderungen
- **Kein expliziter Speichern-Klick** mehr nötig. Jede Änderung (Tour-Infos, Begleiter,
  Notizen, generierte Logs, Erledigt-Haken, Reihenfolge, Log-Status) wird automatisch
  gesichert.
- Nach Reload landet man **an derselben Stelle** (gleiche Tour, gleicher Cache, Notiz-
  Text vollständig).
- **Automatische Tour-Anlage:** Beim GPX-Upload wird sofort eine Tour angelegt —
  außer die Datei ist bereits bekannt, dann wird die bestehende Tour geöffnet.

### Lösungsansatz [E]
Zwei Ebenen:
1. **Lokal (sofort):** Zustand bei jeder Eingabe in `localStorage` spiegeln
   (Tastendruck-genau, kein Netzwerk nötig — schützt auch bei Funkloch im Wald).
2. **Server (verzögert):** Änderungen per Debounce (z. B. 1,5 s nach letzter Eingabe sowie
   bei `visibilitychange`/`pagehide`) an die API senden; feldweise Updates statt
   komplette Tour.
- Beim Start: lokaler Entwurf vs. Server-Stand vergleichen (Zeitstempel `updated_at`),
  neueren Stand nehmen.
- Sichtbarer Speicherstatus (z. B. „✓ gespeichert" / „⏳ speichert…" / „⚠ offline, lokal
  gesichert").

### Wiedererkennung der GPX-Datei
- **Hash über den Dateiinhalt** (SHA-256) ist einfach, aber fragil: c:geo/GSAK schreiben
  Zeitstempel ins GPX, ein erneuter Export derselben Caches ergibt einen anderen Hash.
- [E] Erkennung über **Hash der sortierten GC-Code-Liste** (robust gegen Neu-Exporte mit
  anderem Zeitstempel). Datei-Hash wird nur informativ mitgespeichert.
- [E] **Teil-Treffer** (z. B. Tour um 2 Caches erweitert): Hinweis „8 von 10 Caches sind
  in Tour X" → Wahl: **neue Caches zu Tour X hinzufügen** oder **neue Tour anlegen**.
  Bestehende Notizen/Logs bleiben unverändert.

### Datenmodell-Änderungen
- `tour`: + `updated_at`, + `gpx_hash`, + `codes_hash`, + `tour_date` (siehe F4).
- `cache_log`: + `log_type`, + `visited_at` (siehe F3/F4).
- „Gespeichert vs. nicht gespeichert" entfällt — jede Tour ist gespeichert.

---

## F3 — Anzeige Land und Log-Status

### Land
- **Befund (Analyse der lokalen DB, 2026-11-01):**
  - Der Export liefert das Land korrekt (z. B. Tour #45: `Denmark / Region Syddanmark`,
    `Germany / Schleswig-Holstein`).
  - Touren **ab #41** (nach Commit `15b6cbc`, 28.10.2026) haben das Land im Snapshot.
  - Touren **bis #40** haben **kein Land**: Der Parser hat es damals noch nicht gelesen,
    und die rohe GPX wird nicht gespeichert → nachträglich nicht rekonstruierbar.
  - Folge: In alten Touren fehlt das Land in Liste und Detail, und die zweisprachigen
    Logs greifen dort nicht.
- [E] Land in **Cache-Liste** (kompakt, z. B. Länderkürzel `DK`) und **Detailansicht**
  anzeigen. Tour-Übersicht (F4): Länder der Tour anzeigen.
- [E] **Nachtragen über Re-Upload:** Lädt man die GPX einer bestehenden Tour erneut hoch
  (Erkennung über GC-Codes, F2), werden die **GPX-Felder** des Snapshots aktualisiert —
  Notizen, Logs, Status, Datum bleiben unangetastet. Damit lassen sich Altdaten und künftige
  Parser-Erweiterungen nachziehen.
- [E] Zusätzlich die **rohe GPX** pro Tour speichern (komprimiert), damit künftige
  Parser-Erweiterungen ohne Re-Upload nachgezogen werden können.
- Kein weiterer Fallback (Koordinaten/manuell) nötig, da der Export das Land liefert.

### Log-Status
- [E] Je Cache ein Status:

  | Anzeige | geocaching.com-Typ (Export) |
  |---------|-----------------------------|
  | Fund | `Found it` |
  | DNF | `Didn't find it` |
  | Notiz | `Write note` |
  | Teilgenommen | `Attended` (Events) |
  | Werde teilnehmen | `Will Attend` (Events) |
  | Webcam-Foto | `Webcam Photo Taken` (Webcam-Caches) |

  Nicht dabei: Needs Maintenance. Exakte Schreibweise gegen geocaching.com prüfen.
  Event-/Webcam-Typen werden nur bei passenden Cache-Typen angeboten [E]; Default bei
  Events = Teilgenommen.
- Status ist **sichtbar** in Cache-Liste und Detail, **änderbar** per Auswahl.
- Status beeinflusst den Prompt (DNF-Log klingt anders als Fund-Log).
- Status wird für den Field-Notes-Export (F6) benötigt.
- [E] Quellen für Status, Datum, Uhrzeit, Sortierung (Rangfolge):
  1. **c:geo-Feldnotizen** (optionaler zweiter Upload, siehe unten),
  2. eigenes **abgeschicktes** Log bzw. Fundstatus aus dem GPX (falls mitexportiert),
  3. Default: Status **Fund**, Datum = Tour-Datum, Uhrzeit leer, Reihenfolge wie GPX.
- Alles bleibt im Tool **manuell änderbar** (Status-Auswahl, Datum, ▲/▼).

### Befund c:geo-Exporte (2026-11-01)
Geprüft: `export-2026-10-30-…Verlauf.gpx` (ohne Optionen) sowie `GC7C18Z Verlauf.gpx` und
`GC7C18Z Verlauf (1).gpx` (mit „Logs einbeziehen").
- c:geo-Quellcode (`GpxSerializer.java`) kennt zwei Export-Optionen:
  - **„Logs einbeziehen"** → `groundspeak:logs` (`date`, `type`, `finder`, `text`).
  - **„Fundstatus einbeziehen"** → `gsak:UserFound`/`DNFDate`, Symbol „Geocache Found".
- Mit Logs: 43 **fremde** Logs exportiert (Datei ~5× größer: 26 KB statt 5 KB pro Cache),
  aber **kein eigenes Log** und **kein Fundstatus**.
- Ursache: **Offline-Logs (Entwürfe) exportiert c:geo nie** (`getLogs()` liest nur
  Server-Logs, das Offline-Log liegt separat). Solange nicht abgeschickt, gilt der Cache
  für c:geo auch nicht als gefunden. Die `#NR . HH:MM Uhr`-Zeile steht im Offline-Log.
- ⇒ Über die GPX allein kommt man an Uhrzeit und Log-Nummer **nicht** heran.
- `<wpt><time>` ist das **Legedatum**, nicht das Funddatum → nie als Besuchsdatum nutzen.
- Reihenfolge der Caches in der GPX ist **nicht** zuverlässig die Fundreihenfolge.

### Optionaler Upload: c:geo-Feldnotizen [E]
- Beim Anlegen einer Tour (und nachträglich in einer bestehenden Tour) kann **zusätzlich**
  die c:geo-Feldnotizen-Datei (`geocache_visits.txt`, Export aus c:geo) hochgeladen werden.
  Pflicht bleibt nur die GPX.
- **Format (verifiziert an `spec/20261101125653.txt`, c:geo, 2026-11-01):**
  - Kodierung **UTF-16 LE ohne BOM**, Zeilenende **LF**. Parser tolerant bauen: mit/ohne
    BOM, LF/CRLF, notfalls UTF-8.
  - Je Eintrag `GC-Code,Zeitstempel,Log-Typ,"Text"`, z. B.
    `GCBPV6W,2026-11-01T11:56:04Z,Found it,"…"`.
  - Zeitstempel **UTC**, sekundengenau.
  - Text in Anführungszeichen, **mehrzeilig** (Zeilenumbrüche innerhalb der Quotes) →
    CSV-artig parsen, nicht zeilenweise.
  - Reihenfolge in der Datei: **neueste zuerst**.
- Zuordnung über den GC-Code; Einträge zu Caches, die nicht in der Tour sind, werden
  ignoriert (mit Hinweis).
- Übernommen werden:
  - **Log-Typ** → Status (F3-Tabelle),
  - **Zeitstempel** → Datum und Uhrzeit des Caches (UTC → Ortszeit für die Anzeige),
  - **Text** → nach der Nummernzeile durchsucht. Tatsächliches Muster:
    `*#11579 • 12:56 Uhr*` (kursiv in `*`, Trenner `•`, Uhrzeit = **Ortszeit**). Regex
    tolerant: optionale `*`, Trenner `•`/`.`/`-`, `Uhr` optional.
    - **Fundnummer** (`#11579`) → Anzeige und **zweites** Sortierkriterium.
    - Uhrzeit der Zeile ist Ortszeit und passt normalerweise zum UTC-Zeitstempel
      (12:56 MEZ = 11:56Z). Maßgeblich ist immer der **Zeitstempel** — er wird in c:geo
      angepasst, wenn vergessene Offline-Logs nachträglich ergänzt werden; die Zeile im Text
      bleibt dabei ggf. veraltet.
  - [E] Der übrige Text (Signatur: `---`, Dank an Owner, Gruß von ClickKlack) wird **nicht**
    übernommen — **nur Nummer und Uhrzeit** werden ausgewertet. Die Signatur fügt der Nutzer
    selbst auf geocaching.com ein.
  - [E] Fundnummer im Tool anzeigen (z. B. „#11579 · 12:56") in Liste und Detail.
- Nachträglicher Upload setzt **nur** Status/Datum/Uhrzeit/Sortierung — nie Notizen oder
  generierte Logs.

### Ablauf GPX → Feldnotizen [E]
1. **GPX-Upload:** Tour entsteht; die GPX enthält keine Fundzeiten → je Cache Status
   „Fund" (bzw. Fundstatus, falls mitexportiert), Datum = Tour-Datum, **Uhrzeit leer**,
   Reihenfolge wie in der GPX.
2. **Feldnotizen-Upload** (später, in der geöffneten Tour): Status, Datum und Uhrzeit
   werden pro Cache nachgetragen (manuell gesetzte Werte bleiben, s. u.).
3. **Sortierung** danach:
   - Sortierschlüssel: **1. Zeitstempel** (Uhrzeit hat Vorrang), **2. Fundnummer** bei
     gleichem Zeitstempel.
   - Wurde die Tour **noch nicht** manuell (▲/▼) umsortiert → automatisch neu sortieren.
   - Wurde sie **schon** manuell umsortiert → Rückfrage „Nach Uhrzeit neu sortieren?".
     Dafür merkt sich die Tour ein Flag `manually_sorted`.
   - Caches **ohne** Feldnotiz-Eintrag (nur noch Sonderfall, z. B. Log in c:geo vergessen):
     ans Ende in bisheriger Reihenfolge, mit Hinweis „keine Feldnotiz".
4. Erneuter Feldnotizen-Upload (z. B. nach Nachtragen vergessener Logs in c:geo) läuft
   genauso: neue Uhrzeiten werden übernommen, dann wird nach obiger Regel sortiert.
- [E] **Manuell geänderte Werte bleiben erhalten:** Das Tool merkt sich pro Cache, welche
  dieser Felder von Hand gesetzt wurden (z. B. `manual_fields`), und der Upload füllt nur
  die übrigen.
- **Test DNF/Notiz (2026-11-01, `spec/20261101130803.txt` + `export-2026-11-01-13-07-47-2
  Verlauf.gpx`, 2 Funde + 1 DNF + 1 Notiz):**
  - c:geo exportiert **alle Log-Typen** in die Feldnotizen. Verifizierte Schreibweisen:
    `Found it`, `Didn't find it`, `Write note`.
  - DNF und Notiz haben ebenfalls Zeitstempel und die Nummernzeile — die Nummer ist aber
    die **nächste Fundnummer** (beide `#11580`, nicht hochgezählt). ⇒ Die Fundnummer ist
    **nicht eindeutig**; Sortierung nach Zeitstempel ist zwingend (bestätigt Entscheidung
    #33). Zeitstempel sind sekundengenau und unterscheiden sich (12:07:17 vs. 12:07:27).
  - GPX: Reihenfolge `GC7C24G, GCBPV6W, GCBQDDG, GC7C18Z` — weder chronologisch noch
    umgekehrt. Tatsächliche Reihenfolge laut Zeitstempel: `GC7C18Z → GCBPV6W → GC7C24G →
    GCBQDDG`. Keine Logs, kein Fundstatus/DNF-Status in der GPX.
  - Feldnotizen-Datei endet mit LF nach dem letzten Eintrag.
  - **Regex-Falle:** Cache-Namen enthalten selbst `#` (z. B. „131 # Tønder stierne."). Die
    Nummernzeile nur als **eigene Zeile** erkennen: `^\*?#(\d+)\s*[•.\-]\s*(\d{1,2}:\d{2})(\s*Uhr)?\*?$`
    (multiline), nicht irgendwo im Text.
- Beispiel-GPX `spec/export-2026-11-01-12-56-28-1 Verlauf.gpx` passt dazu (GC7C18Z,
  GCBPV6W; keine Logs, kein Fundstatus — bestätigt den Befund oben). Die GPX-Reihenfolge
  weicht von der Fundreihenfolge ab → Sortierung über die Feldnotizen ist nötig.

### Parser-Regeln GPX
- Eigenes Log = `groundspeak:log` mit `finder` = `gc_username` (**ClickKlack**); bei
  mehreren das neueste.
- [E] **Begleiter-Logs erkennen:** Logs, deren `finder` ein bekannter Begleiter ist, werden
  als Info gespeichert (Name, Typ, Datum — **kein Text**) → Anzeige „die-steffi hat schon
  geloggt" und Vorbelegung beim F6-Export (bereits geloggte Begleiter-Caches abwählen).
- Alle übrigen fremden Logs werden **ignoriert** (nicht gespeichert, nicht an die KI).

### Weitere GPX-Felder für den Prompt [E]
- **Attribute** (`groundspeak:attributes`, mit `inc="0"` = verneint) → als Stichworte an die
  KI (z. B. „Für Kinder geeignet", „Besondere Aussicht").
- **Owner** (`groundspeak:owner`) statt `placed_by` für den Dank; `placed_by` kann mehrere
  Namen enthalten (z. B. „deichbehren, DinoSijoru") → als Zusatzinfo behalten.
- **Favoritenpunkte** (`gsak:FavPoints`) → beliebte Caches darf das Log würdigen.
- **Niemals verwenden:** `gsak:GcNote` (persönliche Cache-Notiz, enthält z. B.
  Final-Koordinaten) — weder im Prompt noch in der Anzeige ohne Spoiler-Schutz.
- Kind-Wegpunkte (Parkplatz, Originalkoordinaten, Referenzpunkte) werden wie bisher
  übersprungen.



---

## F4 — Verwaltung gespeicherter Touren

### Anforderungen
- Liste aller Touren, neueste zuerst, mit: Datum, Titel, Anzahl Caches, Begleiter,
  Fortschritt (x/y erledigt).
- **Suche/Filter:**
  - **GC-Code** (exakt oder Präfix) → zeigt Touren, die den Cache enthalten.
  - **Datum**: Tag oder Monat (Datumsauswahl bzw. „2026-10").
  - **Mitcacher**: Auswahl aus allen bisher verwendeten Begleitern.
  - [E] Freitext über Titel/Cache-Namen.
- Filter kombinierbar (UND).

### Offene Punkte
- [E] **Datum pro Cache** (`cache_log.visited_at`): aus eigenem GPX-Log (F3), sonst
  Tour-Datum; im Tool änderbar. Mehrtagestouren (Urlaub) sind damit eine Tour.
- Tour-Datum (`tour.tour_date`) = Default für neue Caches; in der Liste als Zeitraum
  angezeigt („28.–31.10.2026"), wenn die Cache-Daten abweichen.
- Datumssuche trifft eine Tour, wenn **mindestens ein Cache** im gesuchten Tag/Monat liegt.
- Begleiter heute als JSON-Text gespeichert → für Suche [E] eigene Tabelle
  `tour_companion(tour_id, name)` oder SQLite-JSON-Funktionen (`json_each`).

---

## F5 — Doppelte GC-Codes erkennen (Qualitätssicherung)

### Anforderungen
- Beim Laden/Anlegen einer Tour prüft das System jeden GC-Code gegen alle anderen Touren.
- Treffer werden **deutlich** markiert (z. B. Warnfarbe + Hinweis „bereits in Tour
  ‚X' vom TT.MM.JJJJ als Fund").
- Hinweis verlinkt auf die andere Tour.

### Abstufung [E]
- Früher **Fund** → **starke Warnung** (möglicher Doppelfund).
- Früher **DNF/Notiz** → nur **Info** (Wiederbesuch ist normal).
- Keine automatische Konsequenz — der Nutzer entscheidet (z. B. Status ändern oder Cache
  aus dem Export nehmen).
- Auch Duplikate **innerhalb derselben GPX** werden markiert.

---

## F6 — Field-Notes-Export für Begleiter (geocaching.com-Drafts)

### Ziel
Aus einer Tour eine Datei erzeugen, die auf geocaching.com unter **Drafts → Upload** (bzw.
„Field Notes hochladen") importiert werden kann. Damit kann ein **Begleiter** (Beispiel:
Clicki17 — gilt aber für **jeden** Mitcacher) die Caches der Tour schnell nachloggen.

### Log-Text: Vorlage mit Platzhaltern [E]
- Der Text ist **nicht** das KI-Log, sondern eine **einheitliche Vorlage**, die beim
  Export angepasst werden kann.
- Platzhalter (Pflicht): **Mitcacher**, **Cachename**.
  - `{mitcacher}` [E]: alle anderen Teilnehmer der Tour **inkl. mir**, ohne den Empfänger
    (z. B. für Clicki17: „die-steffi und <mein Name>"). Mein Geocaching-Name steht in
    `config.php` (`gc_username` = `ClickKlack`) — derselbe Wert erkennt auch mein Log im
    GPX (F3).
  - `{cachename}`: Cache-Name aus dem GPX.
  - ~~`{versteckort}`~~ [E] **entfällt** (zu aufwendig, Ortsname bräuchte Geocoding).
- [E] Zusätzliche Platzhalter:
  - `{owner}`: Owner des Caches (`groundspeak:owner`, Fallback `placed_by`).
  - `{uhrzeit}`: Fundzeit in Ortszeit (`HH:MM`); leer, wenn unbekannt.
  - `{gc_code}`: GC-Code.
- Unbekannte Platzhalter werden in der Vorschau markiert (Tippfehler-Schutz).
- [E] **Vorlage je Begleiter:** Es gibt eine allgemeine Standardvorlage; pro Begleiter kann
  eine eigene Vorlage hinterlegt werden, die die Standardvorlage ersetzt. Beim Export wird
  die passende Vorlage vorbefüllt und ist für diesen Export editierbar (optional „als
  Vorlage für <Begleiter> speichern").
- Speicherort: neue Tabelle `setting` bzw. `companion(name, template)` in SQLite (nicht in
  `config.php`, da im UI editierbar).
- Vorschau der ersten ein, zwei gerenderten Logs vor dem Download.

### Dateiformat [E]
- Die Datei wird **exakt wie die c:geo-Feldnotizen** erzeugt (Vorbild:
  `spec/20261101125653.txt`), da c:geo-Dateien nachweislich bei geocaching.com
  funktionieren:
  - Dateiname `geocache_visits.txt`, Kodierung **UTF-16 LE ohne BOM**, Zeilenende **LF**.
  - Je Cache ein Eintrag `GCXXXXX,2026-10-31T14:05:00Z,Found it,"Log-Text"`.
  - Zeitstempel in **UTC**, sekundengenau mit `Z` (wie c:geo).
  - Log-Typ englisch: `Found it`, `Didn't find it`, `Write note`, …
  - Log-Text in Anführungszeichen, mehrzeilig erlaubt; innere `"` verdoppelt (Verhalten
    von c:geo bei `"` im Text noch prüfen).
  - [E] **Leerzeilen:** Der Draft-Import von geocaching.com verwirft leere Zeilen (Absätze
    kleben zusammen). Test-Upload 01.11.2026 mit vier Varianten: nur eine Zeile mit
    **Null-Breite-Leerzeichen (U+200B)** bleibt als Leerzeile erhalten; geschütztes
    Leerzeichen (U+00A0), CRLF und `&nbsp;` nicht (letzteres erscheint wörtlich). ⇒ Leere
    Zeilen im Text werden beim Export mit U+200B gefüllt — einzige Abweichung vom c:geo-Format.
  - [E] Dateiname mit Tour-Datum und -Nummer, z. B. `geocache_visits_2026-10-31_tour45.txt`
    (für geocaching.com ist nur der Inhalt maßgeblich).

> **⚠ Vor dem Commit der Export-Funktion (Pflicht-Abnahme):**
> 1. Erzeugte Datei byteweise mit einer c:geo-Feldnotizen-Datei vergleichen (Kodierung,
>    kein BOM, LF, Zeitstempel-Format, Quoting).
> 2. Testdatei real bei geocaching.com unter **Drafts → Upload** hochladen und prüfen:
>    Umlaute/Emojis, mehrzeiliger Text, Log-Typ, Datum/Uhrzeit, Reihenfolge der Drafts.
> 3. Erst nach erfolgreichem Test committen.

### Export für mich selbst [E]
- Zusätzlich kann ich die **eigenen KI-Logs** als Draft-Datei exportieren (statt Copy-Paste
  pro Cache). Log-Text = generiertes Log, Log-Typ = Status aus F3.
- Caches ohne generiertes Log werden ausgelassen (bzw. in der Vorschau als fehlend gezeigt).

### Ablauf
1. In einer Tour „Field Notes exportieren" wählen.
2. **Empfänger auswählen:** „Ich (KI-Logs)" oder ein Begleiter der Tour (Vorlage).
3. Vorlage prüfen/anpassen.
4. **Cache-Auswahl** (Checkbox-Liste): Default alle mit Status Fund; Caches, bei denen der
   Begleiter nicht dabei war oder schon geloggt hat (Begleiter-Log aus GPX, F3), werden
   hier **abgewählt**. [E] Die Teilnahme wird **nur im Export-Dialog** gepflegt, die Tour
   selbst kennt keine Teilnahme pro Cache.
5. Download der Datei.

### Zeitstempel [E]
- Uhrzeit pro Cache aus den c:geo-Feldnotizen (F3).
- Caches **ohne Uhrzeit**: **fortlaufend ab 10:00 Ortszeit** im Abstand von 10 Minuten in
  Tour-Reihenfolge (pro Datum neu beginnend) → Drafts erscheinen richtig sortiert. In der
  Vorschau als „geschätzt" markiert.
- Umrechnung Ortszeit → UTC mit Zeitzone `Europe/Berlin` (Sommer-/Winterzeit beachten).

### Offene Punkte
- [E] Mehrere Begleiter: Export läuft **pro Empfänger** (eine Datei je Durchgang); für den
  nächsten Begleiter den Dialog erneut starten.

---

## F7 — Datenhaltung: Reicht SQLite?

### Abschätzung
- **Ist-Stand lokal (2026-11-01):** 44 Touren, 402 Caches, Datei **1,7 MB** → rund
  **4 KB pro Cache** (GPX-Snapshot ~2,5 KB, Log ~0,5 KB).
- Hochrechnung: 2 000 Caches/Jahr (sehr aktiv) → **~8 MB/Jahr**; mit roher GPX (F3,
  komprimiert) eher 15–20 MB/Jahr.
- SQLite ist bis in den GB-Bereich problemlos; ein Einzelnutzer mit gelegentlichen Writes
  ist der Idealfall.

### Bewertung [E]
- **SQLite bleibt.** Kein Wechsel zu MySQL nötig.
- Empfehlungen:
  - **WAL-Modus** aktivieren (bessere Robustheit bei häufigen Autosave-Writes aus F2).
  - Indizes auf `cache_log.code`, `tour.tour_date` (für F4/F5).
  - Backup: siehe **F8**.
  - Hetzner: prüfen, dass `data/` nicht auf einem NFS-Share liegt (SQLite-Locking).

---

## F8 — Backup [E]

Drei Ebenen:

1. **Rollierendes Server-Backup vor jeder Tour-Erzeugung**
   - Bevor eine neue Tour angelegt wird (GPX-Upload → F2), wird die SQLite-Datei auf dem
     Webspace kopiert: `data/backup/gc_logger-<Zeitstempel>.sqlite`.
   - Es werden die **letzten 3** Backups behalten, ältere gelöscht.
   - Kopie konsistent erstellen (SQLite `VACUUM INTO` bzw. Backup-API, nicht blindes
     Dateikopieren während eines Writes — wichtig mit WAL-Modus).
   - Schlägt das Backup fehl, wird die Tour trotzdem angelegt, aber mit Warnung (Logdatei).
2. **Lokales Backup bei jedem Deploy**
   - `local_scripts/deploy.sh` lädt **vor** dem Deploy die aktuelle SQLite vom Server nach
     lokal (z. B. `backups/gc_logger-<Zeitstempel>.sqlite`, gitignored).
   - Mindestens bei jedem Deploy; konsistente Kopie (remote `VACUUM INTO` in temporäre
     Datei, dann herunterladen).
   - [E] Lokal rollierend die **letzten 10** behalten.
3. **Manueller Backup-Export im UI**
   - Download aller Touren als **JSON** (menschenlesbar, unabhängig vom Schema) und/oder
     der SQLite-Datei.

---

## Querschnitt

- Alle neuen API-Actions hinter dem Login (F1).
- Mobile-first: Suche/Filter als einklappbarer Bereich, große Touch-Ziele.
- Schema-Migrationen idempotent (`ALTER TABLE` nur, wenn Spalte fehlt).

## Nicht-Ziele (weiterhin)
- Kein Mehrbenutzerbetrieb.
- Kein direktes Posten zu geocaching.com (Field-Notes-Upload macht der Nutzer selbst).
- Keine vollständige Offline-PWA (lokale Sicherung aus F2 ist aber Pflicht).

---

## Fragen-Log

| # | Frage | Antwort | Datum |
|---|-------|---------|-------|
| 1 | Login-Dauer | 180 Tage gleitend, Token pro Gerät | 2026-11-01 |
| 2 | GPX-Wiedererkennung | Über GC-Code-Liste | 2026-11-01 |
| 3 | Quelle Log-Status | Aus GPX (eigenes Log), sonst Fund | 2026-11-01 |
| 4 | Field-Notes-Text | Einheitliche Vorlage mit Platzhaltern (Mitcacher, Cachename, Versteckort), beim Export anpassbar; für jeden Begleiter nutzbar | 2026-11-01 |
| 5 | Bedeutung `{versteckort}` | Ort/Region aus dem GPX | 2026-11-01 |
| 6 | Inhalt `{mitcacher}` | Alle anderen Teilnehmer inkl. mir; eigener GC-Name in config | 2026-11-01 |
| 7 | Doppelte GC-Codes | Nach Status abgestuft, keine Automatik | 2026-11-01 |
| 8 | Datum | Pro Cache (Mehrtagestouren möglich) | 2026-11-01 |
| 9 | Teil-Treffer GPX | Nachfragen: hinzufügen oder neue Tour | 2026-11-01 |
| 10 | Wo fehlt Land | Cache-Liste und Detailansicht → Export liefert vermutlich kein Land | 2026-11-01 |
| 11 | `{versteckort}` | Entfällt (zu kompliziert) | 2026-11-01 |
| 12 | Zusatz-Features | Backup (Server rollierend 3× vor Tour-Anlage, lokal bei Deploy, manueller Export), Field Notes für mich, Vorlage je Begleiter | 2026-11-01 |
| 13 | Log-Typen | Fund, DNF, Notiz, Attended, Will Attend, Webcam Photo Taken | 2026-11-01 |
| 14 | Uhrzeit/Sortierung | Aus c:geo-Logzeile `#NR . HH:MM Uhr`, sonst leer; Log-Nr. = initiale Sortierung | 2026-11-01 |
| 15 | Land fehlt — Ursache | Touren ≤ #40 vor Parser-Erweiterung angelegt; Export liefert Land korrekt | 2026-11-01 |
| 16 | Lokale Deploy-Backups | Letzte 10 | 2026-11-01 |
| 17 | Log-Quelle ohne zweite Datei | c:geo-Exportoptionen „Logs" + „Fundstatus" aktivieren; Test-Export ausstehend | 2026-11-01 |
| 18 | Zusätzliche Prompt-Felder | Attribute, Owner statt placed_by, Favoritenpunkte; GcNote nie | 2026-11-01 |
| 19 | Test-Exporte mit Logs | Nur fremde Logs, kein eigenes (Offline-Logs werden von c:geo nicht exportiert) | 2026-11-01 |
| 20 | Quelle Status/Uhrzeit/Sortierung | c:geo-Feldnotizen als optionaler zweiter Upload | 2026-11-01 |
| 21 | Fremde Logs | Nur Begleiter-Logs erkennen (ohne Text), Rest ignorieren | 2026-11-01 |
| 22 | Feldnotizen-Format | UTF-16 LE ohne BOM, LF, mehrzeilige Texte, UTC; Zeile `*#NR • HH:MM Uhr*` (Ortszeit) | 2026-11-01 |
| 23 | Offline-Log-Text | Nur Fundnummer und Uhrzeit auswerten, Signatur nicht übernehmen | 2026-11-01 |
| 24 | gc_username | ClickKlack | 2026-11-01 |
| 25 | Testdaten in spec/ | `spec/*.txt` und `*.gpx` werden nicht committet | 2026-11-01 |
| 26 | Feldnotizen-Upload vs. manuelle Werte | Manuelle Werte behalten | 2026-11-01 |
| 27 | Export ohne Uhrzeit | Fortlaufend ab 10:00, 10-min-Schritte | 2026-11-01 |
| 28 | Teilnahme pro Begleiter | Nur im Export-Dialog abwählen | 2026-11-01 |
| 29 | Weitere Platzhalter | `{owner}`, `{uhrzeit}`, `{gc_code}` | 2026-11-01 |
| 30 | Vorschläge [V] | Alle übernommen (Technik F1/F2/F7, Re-Upload + rohe GPX, Anzeige-Extras, Freitextsuche, Export pro Empfänger) | 2026-11-01 |
| 31 | Export-Dateiformat | Exakt wie c:geo-Feldnotizen; Pflicht-Abnahme (Byte-Vergleich + Test-Upload) vor Commit | 2026-11-01 |
| 32 | Ablauf GPX → Feldnotizen | GPX ohne Uhrzeit; Feldnotizen tragen nach; Sortierung neu (Rückfrage bei manueller Sortierung) | 2026-11-01 |
| 33 | Sortierschlüssel | Zeitstempel vor Fundnummer (Zeit wird bei nachgetragenen Logs angepasst) | 2026-11-01 |
| 34 | Caches ohne Feldnotiz | Test ausstehend, ob DNF/Notiz mitexportiert werden; vorläufig ans Ende | 2026-11-01 |
| 35 | Test DNF/Notiz | Alle Log-Typen kommen in den Feldnotizen an; Fundnummer bei DNF/Notiz nicht hochgezählt → Zeitstempel sortiert | 2026-11-01 |
| 36 | Leerzeilen im Draft-Upload | gc.com verwirft leere Zeilen; Füllung mit U+200B (einzige funktionierende Variante im Test) | 2026-11-01 |
| 37 | Export-Dateiname | Mit Tour-Datum und Tour-Nummer | 2026-11-01 |
