# CLAUDE.md — gc_logger

Leitfaden für Claude-Code-Sessions in diesem Repo. Kurz halten, beim Ändern aktuell
halten.

## Was das ist

Mobil-first Web-Tool zum Schreiben von Geocaching-Logs mit KI-Unterstützung. Läuft auf
Hetzner Shared-Hosting (PHP). Siehe `spec/SPEC.md` für die vollständige Spezifikation
(Erweiterungen: `spec/spec-v2.md`).

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
  Auth.php           Session-Passwortschutz
  Gpx.php            GPX-Parser (SimpleXML, Groundspeak-Extensions)
  Gemini.php         REST-Client (cURL) für generateContent
  Prompts.php        System-Instruction + Stufe-1/2-Templates
  BatchLogs.php      Sammel-Antwort auswerten (abgeschnittenes JSON, Müll-Filter)
  Db.php             SQLite via PDO
public/              Web-Root (docroot hierauf setzen, falls möglich)
  index.php          App-Shell + Login-Gate, rendert serverseitig den Auth-Status
  api.php            JSON-Router (POST-Actions)
  assets/app.js      Frontend-Logik (State-Maschine)
  assets/style.css   Mobile-first Styles
tests/smoke.php      CLI-Smoketests (php tests/smoke.php), nur auf Temp-Kopie der DB
```

## Datenbank

- Schema-Änderungen: neue Spalten in `schema.sql` (für Neuinstallationen) **und** als
  neue Migration in `Db::migrations()` anhängen (nie bestehende ändern). Version steht in
  `PRAGMA user_version`; vor jeder Migration einer bestehenden DB wird automatisch
  gesichert (`data/backup/`, letzte 3, `VACUUM INTO`).
- WAL-Modus ist aktiv (`*.sqlite-wal`/`-shm` neben der DB sind normal).

## Lokal testen

```sh
cp config.example.php config.php          # dann Key + Passwort-Hash eintragen
php -r "echo password_hash('GEHEIM', PASSWORD_DEFAULT), PHP_EOL;"   # Hash erzeugen
php -S localhost:8000 -t public           # http://localhost:8000 öffnen
php tests/smoke.php                       # Smoketests (DB-Kopie, spec/-Testdateien)
```

Beispiel-GPX aus einer Pocket Query oder „Cache → Mit GPX herunterladen" verwenden.

## Deployment (Hetzner)

- `config.php`, `lib/`, `schema.sql` und `data/` (SQLite) **außerhalb** des Web-Roots
  ablegen; docroot auf `public/` setzen. Wo das nicht möglich ist, schützt die
  Root-`.htaccess` die sensiblen Dateien.
- Der `data/`-Ordner muss vom PHP-Prozess beschreibbar sein (SQLite-Datei).
