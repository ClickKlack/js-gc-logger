# Spezifikation v3 — Öffentlicher Field-Notes-Export für Begleiter

Status: **Abgestimmt (bereit für Umsetzung)** · Stand: 2026-11-01 · Quelle: `spec/ideas.md`
Basis: `spec/spec-v2.md` (insb. F6) und Code-Stand `367a271`.

Legende: **[E]** = entschieden

---

## 0. Überblick

| # | Thema | Kern |
|---|-------|------|
| F9 | Freigabe-Link | Ich erzeuge für einen Begleiter einen geheimen Link auf ausgewählte Touren |
| F10 | Gastseite | Begleiter wählt Caches, passt Logtext an, lädt Draft-Datei herunter — ohne Login |
| F11 | Ablauf | Link verfällt nach 14 Tagen und wird gelöscht |

**Abgrenzung zu v2:** Das ist die erste Funktion **ohne Login**. Die v2-Regel „alle neuen
API-Actions hinter dem Login" gilt weiter für alles außer den hier beschriebenen
Gast-Aktionen. Kein Mehrbenutzerbetrieb: Der Gast hat keinen Account, nur den Link.

---

## 1. Prüfung der Idee (Befund)

Die Idee passt gut zum Bestand — fast alles ist schon da:

| Baustein aus ideas.md | Vorhanden in | Anpassung |
|---|---|---|
| Empfänger + Touren auswählen | `tour_companion`, `Db::listToursPage(['companion' => …])` | Mehrfachauswahl von Touren |
| „nur Touren, bei denen er dabei war" | `tour_companion` | Prüfung beim Anlegen (serverseitig) |
| Cache-Liste mit Typ-Logo | `typeIcon()` in `app.js` | in gemeinsame JS-Datei auslagern |
| Checkboxen + Vorauswahl | `FieldNotesExport::candidatesForCompanion` | pro Tour aufrufen, Ergebnisse zusammenführen |
| Logtext aus Vorlage | `Db::companionTemplate`, `render()`, `unknownPlaceholders()` | unverändert |
| Vorlage speichern | `Db::saveTemplate($name, …)` | nur für den Empfänger des Links |
| Datei erzeugen | `FieldNotesExport::build()` | unverändert (Format bleibt abgenommen) |

Geklärte Punkte (Details im Fragen-Log):

1. **Link wiederfinden:** Link bleibt in meiner Freigabe-Liste kopierbar → Token wird im
   Klartext gespeichert. [E]
2. **Logtext:** Der Gast passt nur die Vorlage an, keine Einzeltexte je Cache. [E]
3. **Live statt Momentaufnahme:** Der Gast sieht immer den aktuellen Stand der Touren. [E]
4. **Mehrfach-Nutzung:** Exportierte Caches werden serverseitig je Link gemerkt (Link am
   Handy öffnen, Upload am PC muss gehen). [E]
5. **Vorlage:** Die Begleiter-Vorlage ist **ein** Datenfeld (`companion.template`), das
   er und ich gleichermaßen pflegen; er darf sie überschreiben, ich sehe in meinem
   Export-Dialog „geändert von <Empfänger> über Link am …". [E]
6. **Teilnahme pro Cache:** Gibt es weiterhin nicht (v2 #28). Der Gast wählt selbst ab, wo
   er nicht dabei war; ich wähle vorab nichts ab. [E]
7. **Mehrere Touren in einer Datei:** `build()` sortiert global nach Zeit — geht.
   `{mitcacher}` wird **je Tour** berechnet (andere Begleiter pro Tour).

---

## F9 — Freigabe-Link erzeugen (angemeldet)

### Ablauf
1. In **Meine Touren** Knopf „Freigabe-Link für Begleiter".
2. **Empfänger wählen** (Liste aus `companionChoices`).
3. Tourliste zeigt **nur Touren mit diesem Begleiter** (bestehender Filter `companion`),
   neueste zuerst, mit Checkboxen. Default: nichts gewählt.
4. „Link erzeugen" → Link wird angezeigt mit **Kopieren** und **Teilen**
   (`navigator.share`, am Handy direkt WhatsApp/Signal).
5. Abkürzung: Im Field-Notes-Export einer Tour (Empfänger = Begleiter) Knopf
   „Als Link freigeben" → Schritt 2–3 mit dieser Tour vorbelegt.

### Liste aktiver Freigaben
- Unter dem Knopf: Empfänger, Touren (Datum/Nr.), läuft ab am, zuletzt geöffnet,
  Anzahl Downloads, Anzahl exportierter Caches.
- Aktionen: **Link kopieren**, **Teilen**, **Widerrufen** (sofort löschen).

### Regeln [E]
- Serverseitige Prüfung: Jede gewählte Tour muss den Empfänger in `tour_companion` haben,
  sonst Fehler (nicht nur im UI filtern).
- Mehrere aktive Links pro Empfänger sind erlaubt.
- Keine Verlängerung; nach Ablauf bei Bedarf neuen Link erzeugen.

### Token [E]
- 32 Byte aus `random_bytes`, Base64url → 43 Zeichen (256 Bit, nicht erratbar).
- **Im Klartext** in der DB gespeichert (Link soll wieder kopierbar sein; Laufzeit nur
  14 Tage; die DB ist ohnehin das Schutzgut und nicht öffentlich erreichbar).
- Link-Form: `https://<host>/share.php?t=<token>`. Basis-URL aus dem Request ableiten,
  optional `base_url` in `config.php` (für Reverse-Proxy-Fälle).

---

## F10 — Gastseite (ohne Login)

### Anzeige
- Kopf: „Field Notes für **Clicki17**", Gültigkeit („noch 12 Tage").
- **Cache-Liste gruppiert nach Tour** (Datum + Titel), je Cache wie in der Tourliste:
  Typ-Logo, Name, GC-Code, Datum/Uhrzeit („geschätzt" markiert), Status (Fund/DNF/…).
- Daten **live** aus den Touren (spätere Korrekturen an Status, Uhrzeit, neue Caches
  erscheinen beim nächsten Öffnen).
- Checkbox je Cache, Vorauswahl wie heute: Status Fund und noch nicht selbst geloggt
  (Begleiter-Log aus GPX). Hinweis „👥 schon geloggt" (abwählbar wie bisher).
- **Bereits über diesen Link exportiert:** Hinweis „✓ exportiert am …", Checkbox
  **deaktiviert** und abgewählt. Zurücksetzen nur durch einen neuen Link (der hat eine
  eigene, leere Export-Historie).
- „Alle / keine" je Tour (wirkt nicht auf deaktivierte).

### Logtext [E]
- Vorlage wie in meinem Export-Dialog: eigene Vorlage des Empfängers, sonst meine
  Standardvorlage (`companionTemplate`). Editierbar, Platzhalter-Hilfe und Warnung bei
  unbekannten Platzhaltern.
- Ungespeicherte Änderungen gelten nur für diesen Download.
- Vorschau der ersten zwei gerenderten Logs.
- **Ein** Speichern-Knopf: „Als meine Vorlage speichern" → `saveTemplate(<Empfänger>, …)`.
  Das ist **dasselbe Feld** wie mein Knopf „Als Vorlage für <Empfänger> speichern" — wer
  zuletzt speichert, gilt.
- **Hinweis für mich:** Hat der Gast zuletzt gespeichert, zeigt mein Export-Dialog bei
  diesem Empfänger neben der Vorlagen-Quelle „geändert von <Empfänger> über Link am
  TT.MM.JJJJ HH:MM". Speichere ich die Vorlage selbst, verschwindet der Hinweis.
  Kein Rückgängig.
- „Als Standardvorlage speichern" gibt es auf der Gastseite **nicht**; die
  Standardvorlage ändere nur ich. Serverseitig: Gast-`save_template` schreibt
  ausschließlich `companion.template` des Link-Empfängers, nie `setting`.
- Keine Einzeltexte je Cache.

### Download
- Knopf „Field Notes herunterladen (n Caches)" → Datei via `FieldNotesExport::build()`,
  Dateiname `geocache_visits_<Empfänger>_<JJJJ-MM-TT>.txt` (bei einer Tour wie bisher
  `FieldNotesExport::filename()`).
- Mit dem Download werden die enthaltenen Caches als exportiert gemerkt; die Liste
  aktualisiert sich danach.
- Darunter kurze Anleitung: Datei auf geocaching.com unter **Drafts → Upload** hochladen.

### Was der Gast sehen darf (Datenminimierung) [E]
Nur: Cachename, GC-Code, Typ, Datum/Uhrzeit, Log-Typ, Vorlage, `{mitcacher}`, `{owner}`,
Tourdatum/-titel. **Nicht:** meine KI-Logs, Notizen, Tour-Kontext, `tour_info`, rohe GPX,
andere Touren, andere Vorlagen. Umsetzung über eine **Whitelist** in der Ausgabe, nicht
über das Weglassen einzelner Felder.

---

## F11 — Ablauf und Löschung

- `expires_at = created_at + 14 Tage`, nicht verlängerbar. [E]
- Kein Cron auf Shared-Hosting → **Lazy Purge**: Bei jedem Aufruf von `share.php` und der
  Freigabe-Liste `DELETE FROM share WHERE expires_at < now` (Cascade auf `share_tour`,
  `share_export`).
- Abgelaufener/unbekannter Link → freundliche Seite „Link abgelaufen oder ungültig, bitte
  neu anfragen" (HTTP 404, keine Unterscheidung nach außen).
- Tour gelöscht → `share_tour`-Zeilen weg; Freigabe ohne Touren wird mitgelöscht.

---

## 2. Datenmodell (Migration 6)

```
share
  id              INTEGER PK
  token           TEXT UNIQUE NOT NULL   -- Klartext (Link wieder kopierbar)
  recipient       TEXT NOT NULL          -- Begleiter-Name wie in tour_companion
  created_at      TEXT NOT NULL
  expires_at      TEXT NOT NULL
  last_used_at    TEXT
  downloads       INTEGER NOT NULL DEFAULT 0

share_tour
  share_id  INTEGER  → share(id) ON DELETE CASCADE
  tour_id   INTEGER  → tour(id)  ON DELETE CASCADE
  PRIMARY KEY (share_id, tour_id)

share_export
  share_id    INTEGER → share(id)     ON DELETE CASCADE
  cache_id    INTEGER → cache_log(id) ON DELETE CASCADE
  exported_at TEXT NOT NULL
  PRIMARY KEY (share_id, cache_id)

companion  (+ Spalten)
  template_changed_at  TEXT    -- letzte Änderung der Vorlage (UTC)
  template_changed_by  TEXT    -- 'me' | 'share'
```

Neue Tabellen/Spalten in `schema.sql` **und** als Migration 6 in `Db::migrations()`.
`foreign_keys = ON` ist aktiv; `Db::deleteTour()` löscht trotzdem explizit (wie bei `tour_companion`) auch `share_tour` und leere Freigaben.

---

## 3. Technik & Sicherheit

- **Eigener Einstieg `public/share.php`** (Seite + JSON-Actions per `?t=…&action=…`),
  bewusst **getrennt von `api.php`**: Kein Codepfad aus `share.php` erreicht
  Gemini-, Backup-, Tour-Bearbeitungs- oder Login-Funktionen. Kein `Auth::start()`.
- Gast-Actions (alle mit Token-Prüfung + Lazy Purge):
  `load` · `preview` (Vorlage → gerenderte Texte) · `save_template` · `download`.
- `download` rechnet die Kandidaten **serverseitig neu** (wie `export_fieldnotes`) und
  akzeptiert vom Client nur: gewählte Cache-IDs (müssen zu den Touren der Freigabe
  gehören und dürfen noch nicht exportiert sein) und die Vorlage. Längenlimit für die
  Vorlage (z. B. 4.000 Zeichen).
- Header: `X-Robots-Tag: noindex`, `Referrer-Policy: no-referrer` (Token nicht per
  Referer an geocaching.com o. ä. leaken), `Cache-Control: no-store`.
- Brute Force ist bei 256 Bit sinnlos; trotzdem bei ungültigem Token kurze Verzögerung
  (`usleep`), kein Unterschied zwischen „abgelaufen" und „gibt es nicht".
- Frontend: Gemeinsame Helfer (`escapeHtml`, `typeIcon`, `LOG_TYPE_LABELS`, `api`)
  nach `public/assets/common.js` auslagern, von `app.js` und neuer `share.js` genutzt.
  Kein Build-Schritt, nur zwei `<script>`-Tags.
- Root-`.htaccess` bleibt gültig (share.php liegt in `public/`).
- Dateiformat unverändert → keine neue Pflicht-Abnahme des Formats; aber **einmal echter
  Test**: Link am Handy öffnen, Datei herunterladen, bei geocaching.com hochladen
  (prüft Download-Verhalten mobiler Browser bei UTF-16-Dateien).

---

## 4. Umsetzungsschritte

Branch `feature/spec-v3`. Nach jedem Schritt Smoketests + Browser-Prüfliste, Commit erst
nach Freigabe.

| # | Schritt | Dateien | Prüfung |
|---|---------|---------|---------|
| 1 | Migration 6 + Db-Methoden: `createShare`, `findShare(token)` (inkl. Purge), `listShares`, `deleteShare`, `markExported`, `exportedIds`; `saveTemplate` mit Herkunft (`me`/`share`); `deleteTour` erweitern | `schema.sql`, `lib/Db.php` | Smoketests: Token, Ablauf, Empfänger-Prüfung, Cascade |
| 2 | Export-Logik für mehrere Touren: Kandidaten je Tour + Tour-Bezug, Exportiert-Status, Whitelist-Ausgabe für Gäste, Dateiname | `lib/FieldNotesExport.php` (ggf. `lib/Share.php`) | Smoketest: Gast-JSON enthält keine `log`/`notes`/`context`; Datei = `build()` derselben Einträge; exportierte IDs abgelehnt |
| 3 | Admin-API: `share_create`, `share_list`, `share_delete` (hinter Login) | `public/api.php` | Link erzeugen per Browser |
| 4 | Admin-UI: Freigabe-Bereich in „Meine Touren", Abkürzung und Hinweis „geändert von … über Link" im Export-Dialog | `index.php`, `app.js`, `style.css` | Browser |
| 5 | `common.js` auslagern (reines Refactoring) | `assets/common.js`, `app.js`, `index.php` | Bestehende Ansichten unverändert |
| 6 | Gastseite + Gast-Actions inkl. Exportiert-Markierung | `public/share.php`, `assets/share.js`, `style.css` | Browser (Handy + PC), ungültiger/abgelaufener Link |
| 7 | Realer Test-Upload über den Gast-Link; Doku | `CLAUDE.md`, `README.md` | Upload bei geocaching.com |

Grobe Größe: 1 Migration, ~6 neue Db-Methoden, 3 Admin- + 4 Gast-Actions, 1 neue Seite.

---

## 5. Fragen-Log

| # | Frage | Antwort | Datum |
|---|-------|---------|-------|
| 1 | „Logtext individuell anpassen" | Nur Vorlage: für diesen Export oder als „seine" Vorlage gespeichert (= `companion.template`, dasselbe Feld wie mein „Als Vorlage für XXX speichern"). Standardvorlage nur durch mich. Keine Einzeltexte | 2026-11-01 |
| 2 | Link später erneut kopieren | Ja → Token im Klartext speichern | 2026-11-01 |
| 3 | Live oder Momentaufnahme | Aktueller Stand (live) | 2026-11-01 |
| 4 | Gast speichert Vorlage | Überschreibt meine Vorlage für ihn; Hinweis „geändert von XXX über Link am …" in meinem Export-Dialog, kein Rückgängig | 2026-11-01 |
| 5 | Exportierte Caches merken | Ja, serverseitig je Link; anzeigen und Checkbox deaktivieren | 2026-11-01 |
| 6 | Link verlängerbar | Nein | 2026-11-01 |
| 7 | Vorab Caches abwählen | Nein, Gast wählt selbst | 2026-11-01 |
