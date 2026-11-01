-- SQLite-Schema für gc_logger (idempotent)
--
-- Wird bei jedem Öffnen der DB ausgeführt. Spalten, die nach v1 dazugekommen sind,
-- stehen hier für Neuinstallationen; bestehende DBs bekommen sie über die
-- Migrationen in lib/Db.php (PRAGMA user_version). Indizes auf neuen Spalten
-- gehören deshalb in die Migrationen, nicht hierher.

CREATE TABLE IF NOT EXISTS tour (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    title      TEXT,
    tour_info  TEXT,
    companions TEXT,            -- JSON-Array der Begleiter
    context    TEXT,            -- generierter Tour-Kontext (Stufe 1)
    updated_at TEXT,            -- letzte Änderung (UTC), für Autosave-Abgleich
    tour_date  TEXT,            -- Default-Datum für Caches (YYYY-MM-DD)
    gpx_hash   TEXT,            -- SHA-256 der GPX-Datei (informativ)
    codes_hash TEXT,            -- SHA-256 der sortierten GC-Code-Liste (Wiedererkennung)
    gpx_raw    BLOB,            -- rohe GPX, gzcompress
    manually_sorted INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS cache_log (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    tour_id   INTEGER NOT NULL,
    code      TEXT,             -- GC-Code
    name      TEXT,
    gpx       TEXT,             -- JSON-Snapshot der GPX-Felder dieses Caches
    notes     TEXT,             -- Notizen des Nutzers zum Fund
    log       TEXT,             -- generiertes Log (Stufe 2)
    done      INTEGER NOT NULL DEFAULT 0,
    position  INTEGER NOT NULL DEFAULT 0,
    log_type  TEXT NOT NULL DEFAULT 'Found it',  -- geocaching.com-Log-Typ
    visited_at TEXT,            -- Besuchsdatum, Ortszeit (YYYY-MM-DD)
    visited_ts TEXT,            -- Besuchszeitpunkt UTC (ISO, …Z) oder NULL
    find_no   INTEGER,          -- Fundnummer aus c:geo-Feldnotiz
    manual_fields TEXT,         -- JSON-Array der von Hand gesetzten Felder
    companion_logs TEXT,        -- JSON: Begleiter-Logs aus GPX (Name, Typ, Datum)
    updated_at TEXT,
    FOREIGN KEY (tour_id) REFERENCES tour(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_cache_log_tour ON cache_log(tour_id);

CREATE TABLE IF NOT EXISTS tour_companion (
    tour_id INTEGER NOT NULL,
    name    TEXT NOT NULL,
    PRIMARY KEY (tour_id, name),
    FOREIGN KEY (tour_id) REFERENCES tour(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS auth_token (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    token_hash     TEXT NOT NULL UNIQUE,  -- SHA-256 des Cookie-Tokens
    pw_fingerprint TEXT NOT NULL,         -- SHA-256 des Passwort-Hashes zum Login-Zeitpunkt
    created_at     TEXT NOT NULL DEFAULT (datetime('now')),
    last_used_at   TEXT,
    expires_at     TEXT NOT NULL,
    user_agent     TEXT
);

CREATE TABLE IF NOT EXISTS companion (
    name     TEXT PRIMARY KEY,
    template TEXT                -- eigene Field-Notes-Vorlage (ersetzt Standard)
);

CREATE TABLE IF NOT EXISTS setting (
    key   TEXT PRIMARY KEY,
    value TEXT
);
