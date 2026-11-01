# CLAUDE.md — gc_logger

Leitfaden für Claude-Code-Sessions in diesem Repo. Kurz halten, beim Ändern aktuell
halten.

## Was das ist

Mobil-first Web-Tool zum Schreiben von Geocaching-Logs mit KI-Unterstützung. Läuft auf
Hetzner Shared-Hosting (PHP). Siehe `spec/SPEC.md` für die vollständige Spezifikation
(Erweiterungen: `spec/spec-v2.md`, `spec/spec-v3.md`).

## Stack & Konventionen

- **PHP 8**, reines Vanilla-JS-Frontend, **kein Build-Schritt** (kein Composer, kein
  npm). Alles muss direkt per FTP/SFTP lauffähig sein.
- **KI:** ausschließlich **Google Gemini** über die kostenlose REST-API
  (`generativelanguage.googleapis.com`). **Kein Anthropic/Claude**, kein
  kostenpflichtiger API-Key. Die `claude-api`-Skill ist hier NICHT einschlägig.
- Modellname steht in `config.php` (`gemini_model`, Default `gemini-2.5-flash`). Bei
  Änderungen an der API gegen die aktuelle AI-Studio-Doku verifizieren statt raten.
- **Secrets niemals committen.** `config.php` und `*.sqlite` sind in `.gitignore`.
  Vorlage ist `config.example.php`.

## Struktur

```
config.example.php   Vorlage für config.php (Key, Modell, Passwort-Hash, DB-Pfad)
schema.sql           SQLite-Schema (idempotent, CREATE TABLE IF NOT EXISTS)
lib/                 Backend-Klassen (außerhalb des Web-Roots)
  bootstrap.php      lädt config.php + alle Klassen, gibt $config zurück
  Auth.php           Passwortschutz: Session + „angemeldet bleiben"-Token je Gerät
  Gpx.php            GPX-Parser (Groundspeak/gsak; eigenes + Begleiter-Logs, nie GcNote)
  FieldNotes.php     Import c:geo-Feldnotizen (UTF-16, CSV-artig, Nummernzeile)
  FieldNotesExport.php Draft-Datei für geocaching.com (c:geo-Format, Vorlagen)
  GpxExport.php      GPX-Export (Caches + eigene Logs)
  Gemini.php         REST-Client (cURL) für generateContent
  Prompts.php        System-Instruction + Stufe-1/2-Templates
  BatchLogs.php      Sammel-Antwort auswerten (abgeschnittenes JSON, Müll-Filter)
  Share.php          Freigabe-Links: Kandidaten über Touren, Gast-Whitelist, Dateiname
  Db.php             SQLite via PDO, Migrationen, Backups, Abfragen
public/              Web-Root (docroot hierauf setzen, falls möglich)
  index.php          App-Shell + Login-Gate, rendert serverseitig den Auth-Status
  api.php            JSON-Router (POST-Actions), alles außer login/logout hinter Login
  share.php          Gastseite für Freigabe-Links (ohne Login, eigene Mini-API)
  assets/common.js   Gemeinsame Helfer (api, typeIcon, Datum) für app.js + share.js
  assets/app.js      Frontend-Logik (State-Maschine)
  assets/share.js    Frontend der Gastseite
  assets/style.css   Mobile-first Styles
tests/smoke.php      CLI-Smoketests (php tests/smoke.php), nur auf Temp-Kopie der DB
tests/auth_probe.php Hilfsprozess für die Login-Tests
tests/router.php     Router für php -S (ersetzt public/.htaccess: /share/TOKEN/)
```

## Datenbank

- Schema-Änderungen: neue Spalten in `schema.sql` (für Neuinstallationen) **und** als
  neue Migration in `Db::migrations()` anhängen (nie bestehende ändern). Version steht in
  `PRAGMA user_version`; vor jeder Migration einer bestehenden DB wird automatisch
  gesichert (`data/backup/`, letzte 3, `VACUUM INTO`).
- WAL-Modus ist aktiv (`*.sqlite-wal`/`-shm` neben der DB sind normal).
- Frontend speichert automatisch (localStorage sofort, API feldweise per Debounce:
  `update_tour`/`update_cache`/`reorder`); es gibt keinen Speichern-Button.

## Freigabe-Links (öffentlich)

- `public/share.php` ist die **einzige Seite ohne Login**. Nichts aus `api.php` dort
  einbinden; nur die Actions `load`, `save_template`, `download` mit Token-Prüfung.
- Gast-Ausgabe nur über die Whitelist in `Share::publicTours()` — neue Cache-Felder
  tauchen dort nicht automatisch auf (KI-Log, Notizen, Kontext bleiben privat).
- Gast darf nur `companion.template` des Link-Empfängers schreiben (`changed_by =
  'share'`), nie die Standardvorlage. Links: Token im Klartext, 14 Tage, Lazy Purge.
- Link-Form `…/share/TOKEN/`: Apache über `public/.htaccess`, lokal über
  `tests/router.php`. Assets in `share.php` deshalb mit Basispfad, nie relativ.

## Field-Notes-Export

- Format exakt wie c:geo (UTF-16 LE ohne BOM, LF, UTC, neuester zuerst). Einzige
  Abweichung: leere Zeilen werden mit U+200B gefüllt, sonst verwirft der Draft-Import von
  geocaching.com sie (Upload-Test 01.11.2026, siehe spec-v2 F6).
- Änderungen am Format nur mit erneutem Test-Upload unter Drafts → Upload.

## Lokal testen

```sh
cp config.example.php config.php          # dann Key + Passwort-Hash eintragen
php -r "echo password_hash('GEHEIM', PASSWORD_DEFAULT), PHP_EOL;"   # Hash erzeugen
php -S localhost:8000 -t public tests/router.php   # http://localhost:8000 (Router: /share/TOKEN/)
php tests/smoke.php                       # Smoketests (DB-Kopie, spec/-Testdateien)
```

Beispiel-GPX aus einer Pocket Query oder „Cache → Mit GPX herunterladen" verwenden.

## Deployment (Hetzner)

- `config.php`, `lib/`, `schema.sql` und `data/` (SQLite) **außerhalb** des Web-Roots
  ablegen; docroot auf `public/` setzen. Wo das nicht möglich ist, schützt die
  Root-`.htaccess` die sensiblen Dateien.
- Der `data/`-Ordner muss vom PHP-Prozess beschreibbar sein (SQLite-Datei).
