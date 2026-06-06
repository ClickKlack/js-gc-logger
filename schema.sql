-- SQLite-Schema für gc_logger (idempotent)

CREATE TABLE IF NOT EXISTS tour (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    title      TEXT,
    tour_info  TEXT,
    companions TEXT,            -- JSON-Array der Begleiter
    context    TEXT             -- generierter Tour-Kontext (Stufe 1)
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
    FOREIGN KEY (tour_id) REFERENCES tour(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_cache_log_tour ON cache_log(tour_id);
