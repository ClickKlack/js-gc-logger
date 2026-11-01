# gc_logger — Geocaching-Log-Helfer

Kleines, handytaugliches Web-Tool, das dir beim Schreiben von Geocaching-Logs hilft:
GPX hochladen → Tour-Infos + Begleiter erfassen → KI baut einen Tour-Kontext → dann
Cache für Cache ein fertiges Log mit Kopier-Button.

- **Läuft auf:** Hetzner Shared-Hosting (PHP 8) — kein Build, kein Composer/Node.
- **KI:** kostenlose Google-Gemini-API (kein Guthaben, keine Kreditkarte, nur
  Rate-Limits).
- **Speicherung:** SQLite, automatisch; rollierende Backups vor jeder neuen Tour.

Vollständige Spezifikation: siehe [`spec/SPEC.md`](spec/SPEC.md), Erweiterungen in
[`spec/spec-v2.md`](spec/spec-v2.md).

## Einrichtung in 5 Schritten

### 1. Gratis-KI-Key holen
Auf <https://aistudio.google.com/apikey> mit Google-Konto anmelden und einen API-Key
erstellen. Es wird **keine Kreditkarte** verlangt; der Key hat ein kostenloses
Kontingent mit Rate-Limits.

### 2. Konfiguration anlegen
```sh
cp config.example.php config.php
```
In `config.php` eintragen:
- `gemini_api_key` → dein Key aus Schritt 1
- `password_hash` → erzeugen mit:
  ```sh
  php -r "echo password_hash('DEIN-PASSWORT', PASSWORD_DEFAULT), PHP_EOL;"
  ```
  (am eigenen Rechner ausführen, das Ergebnis einfügen — nie das Klartext-Passwort)
- `gc_username` → dein geocaching.com-Name (erkennt dein Log in der GPX, Teil von
  `{mitcacher}` beim Field-Notes-Export für Begleiter)
- `companions` → feste Begleiter (Haken in der Tour, oben in der Mitcacher-Suche)

### 3. Lokal testen (optional)
```sh
php -S localhost:8000 -t public
```
Browser auf <http://localhost:8000>, einloggen, eine Beispiel-GPX hochladen.

### 4. Auf Hetzner hochladen
Alle Dateien per FTP/SFTP übertragen. **Empfohlen:** den Web-Root (docroot) deines
Webhosting-Pakets auf den Ordner `public/` zeigen lassen. Dann liegen `config.php`,
`lib/` und die SQLite-Datei außerhalb des öffentlich erreichbaren Bereichs.

Geht das nicht und alles liegt im Web-Root: die mitgelieferte `.htaccess` sperrt den
direkten Zugriff auf `config.php`, `*.sqlite` und `lib/`.

### 5. Schreibrechte für die Datenbank
Der Ordner `data/` muss für PHP beschreibbar sein (dort entsteht `app.sqlite`). Bei den
meisten Hetzner-Paketen ist das standardmäßig der Fall; sonst Schreibrechte setzen.

## Bedienung

1. Einloggen – das Gerät bleibt 180 Tage angemeldet (🚪 meldet nur dieses Gerät ab).
2. GPX-Datei deiner gefundenen Caches hochladen – die Tour wird sofort angelegt. Ist die
   GPX schon bekannt, wird die bestehende Tour geöffnet bzw. um neue Caches ergänzt.
3. Optional die c:geo-Feldnotizen (`geocache_visits.txt`) hochladen: Status, Uhrzeit,
   Fundnummer und Reihenfolge werden übernommen.
4. Tour-Datum, allgemeine Infos und Begleiter eintragen, **Kontext erzeugen**.
5. Pro Cache: Notizen eingeben → **Log generieren** (oder ✨ alle offenen auf einmal) →
   **Kopieren** bzw. später als Draft-Datei exportieren → **Weiter**.
6. **📤 Field Notes**: Draft-Datei für geocaching.com (Drafts → Upload) – mit deinen
   KI-Logs oder für einen Begleiter mit Vorlage.

Alles wird automatisch gespeichert (Status oben rechts), auch offline im Browser.
**🗂 Meine Touren**: Suche nach GC-Code, Jahr/Monat/Tag, Mitcacher und Freitext,
doppelte Caches werden markiert. Darunter **💾 Backup** als JSON, GPX oder SQLite.

## Hinweis zum Datenschutz
GPX-Inhalte und deine Notizen werden zur Textgenerierung an Google (Gemini) gesendet.
Für öffentliche Geocaching-Logs ist das unkritisch.
