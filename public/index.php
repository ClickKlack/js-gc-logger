<?php

declare(strict_types=1);

/** @var array $config */
$config = require __DIR__ . '/../lib/bootstrap.php';
Auth::start();

$authed     = Auth::isAuthed();
$companions = $config['companions'] ?? [];
$storeLogs  = !empty($config['store_logs']);
$debug      = !empty($config['debug']);
$batchSize  = max(1, (int) ($config['batch_size'] ?? 5));
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <title>Geocaching-Log-Helfer</title>
    <link rel="stylesheet" href="assets/style.css?v=31">
</head>
<body data-authed="<?= $authed ? '1' : '0' ?>" data-store="<?= $storeLogs ? '1' : '0' ?>">

<header class="topbar">
    <h1>🧭 Log-Helfer</h1>
    <?php if ($authed): ?>
        <span id="save-indicator" class="save-indicator hidden" aria-live="polite"></span>
        <?php if ($storeLogs): ?>
            <button id="btn-nav-tours" class="ghost icon-btn" type="button" title="Meine Touren" aria-label="Meine Touren">🗂<span class="btn-text"> Meine Touren</span></button>
        <?php endif; ?>
        <button id="btn-logout" class="ghost icon-btn" type="button" title="Abmelden" aria-label="Abmelden">🚪<span class="btn-text"> Abmelden</span></button>
    <?php endif; ?>
</header>

<main class="wrap">

    <!-- LOGIN -->
    <section id="view-login" class="view<?= $authed ? ' hidden' : '' ?>">
        <h2>Anmelden</h2>
        <form id="form-login">
            <label>Passwort
                <input type="password" id="login-password" autocomplete="current-password" required>
            </label>
            <button type="submit" class="primary">Einloggen</button>
        </form>
        <p id="login-error" class="error"></p>
    </section>

    <!-- APP -->
    <section id="view-app" class="view<?= $authed ? '' : ' hidden' ?>">

        <?php if ($storeLogs): ?>
        <!-- Meine Touren: eigene Ansicht, schließt die Tour-Bearbeitung aus -->
        <section id="view-tours" class="card hidden">
            <h2>Meine Touren</h2>
            <div class="row">
                <button id="btn-tours-new" class="primary" type="button">＋ Neue Tour</button>
            </div>
            <details id="tour-filter" class="filter">
                <summary>🔍 Suchen &amp; Filtern <span id="filter-count" class="badge"></span></summary>
                <label>GC-Code (ganz oder Anfang)
                    <input type="text" id="f-code" placeholder="z. B. GC61F3T oder GC61" autocapitalize="characters" autocomplete="off" spellcheck="false">
                </label>
                <div class="visit-row">
                    <label>Jahr oder Monat
                        <input type="text" id="f-month" placeholder="2025 oder 10.2026" inputmode="numeric" autocomplete="off"
                               aria-describedby="f-month-hint">
                        <span id="f-month-hint" class="field-hint hidden">Bitte z. B. 2025 oder 10.2026 eingeben</span>
                    </label>
                    <label>oder Tag
                        <input type="date" id="f-day">
                    </label>
                </div>
                <label>Mitcacher
                    <select id="f-companion"><option value="">– alle –</option></select>
                </label>
                <label>Freitext (Titel, Cache-Name)
                    <input type="text" id="f-q" placeholder="z. B. Tønder" autocomplete="off">
                </label>
                <button id="btn-filter-reset" class="ghost" type="button">Filter zurücksetzen</button>
            </details>
            <p id="load-status" class="muted"></p>
            <ul id="tour-list" class="tour-list"></ul>
            <div id="tour-pager" class="pager hidden">
                <button id="btn-page-prev" class="ghost" type="button" aria-label="Vorherige Seite">‹ Zurück</button>
                <span id="page-info" class="muted"></span>
                <button id="btn-page-next" class="ghost" type="button" aria-label="Nächste Seite">Weiter ›</button>
            </div>
        </section>

        <!-- Freigabe-Links (spec-v3): sichtbar zusammen mit „Meine Touren" -->
        <section id="view-share" class="card hidden">
            <h2>🔗 Freigabe-Links für Begleiter</h2>
            <p class="muted">Ein Begleiter lädt über den Link ohne Login seine Field Notes für die gewählten Touren herunter. Gültig 14 Tage.</p>
            <details id="share-new" class="filter">
                <summary>＋ Neuen Link erzeugen</summary>
                <label>Empfänger
                    <select id="share-recipient"><option value="">– Begleiter wählen –</option></select>
                </label>
                <p id="share-tours-status" class="muted"></p>
                <ul id="share-tours" class="export-list"></ul>
                <div class="row">
                    <button id="btn-share-create" class="primary" type="button" disabled>🔗 Link erzeugen</button>
                </div>
                <p id="share-create-status" class="muted"></p>
            </details>
            <ul id="share-list" class="export-list"></ul>
            <p id="share-status" class="muted"></p>
        </section>

        <!-- Backup: eigene Karte, sichtbar zusammen mit „Meine Touren" -->
        <section id="view-backup" class="card hidden">
            <h2>💾 Backup</h2>
            <p class="muted">Touren zum Sichern herunterladen (ohne Login-Daten).</p>
            <p id="backup-filter-hint" class="dup-box info hidden"></p>
            <div class="row">
                <button id="btn-backup-json" class="ghost" type="button">JSON (lesbar)</button>
                <button id="btn-backup-gpx" class="ghost" type="button">GPX (Caches + Logs)</button>
                <button id="btn-backup-sqlite" class="ghost" type="button">SQLite-Datenbank</button>
            </div>
            <p class="muted">GPX: alle Caches mit deinen Logs – für c:geo, GSAK oder Karten-Apps. SQLite: immer die komplette Datenbank (zum Wiederherstellen).</p>
            <p id="backup-status" class="muted"></p>
        </section>
        <?php endif; ?>

        <!-- Schritt 1: GPX + Tour-Infos -->
        <section id="step-setup" class="card">
            <h2>1 · Tour einrichten</h2>

            <label class="filelabel">
                GPX-Datei der gefundenen Caches
                <input type="file" id="gpx-file" accept=".gpx,application/gpx+xml,text/xml">
            </label>
            <p id="gpx-status" class="muted"></p>

            <label class="filelabel">
                c:geo-Feldnotizen (optional, geocache_visits.txt)
                <input type="file" class="fieldnotes-file" accept=".txt,text/plain">
            </label>
            <p class="muted fieldnotes-status">Status, Uhrzeit, Fundnummer und Reihenfolge aus c:geo – auch später in der Tour möglich.</p>

            <!-- Tour-Daten: vor dem Kontext hier, danach in der Kontext-Karte (JS verschiebt den Block) -->
            <div id="tour-fields">
            <label>Tour-Datum
                <input type="date" class="tour-date" id="tour-date">
                <span class="muted field-note">Gilt für alle Caches ohne eigene Uhrzeit bzw. ohne von Hand gesetztes Datum.</span>
            </label>

            <label>Allgemeine Infos zur Tour
                <textarea id="tour-info" rows="3"
                    placeholder="z. B. Sonniger Samstag, Radtour entlang der Elbe, gute Laune …"></textarea>
            </label>

            <fieldset class="companions">
                <legend>Begleiter</legend>
                <?php foreach ($companions as $name): ?>
                    <label class="chk">
                        <input type="checkbox" class="companion" value="<?= htmlspecialchars($name, ENT_QUOTES) ?>">
                        <?= htmlspecialchars($name, ENT_QUOTES) ?>
                    </label>
                <?php endforeach; ?>
                <label class="freitext">Weitere (nur mit Komma trennen)
                    <input type="text" id="companions-extra" placeholder="Name1, Name2">
                </label>
            </fieldset>
            </div>

            <button id="btn-context" class="primary" type="button" disabled>Kontext erzeugen</button>
            <p id="context-status" class="muted"></p>
        </section>

        <!-- Schritt 2: Kontext -->
        <section id="step-context" class="card hidden">
            <h2>2 · Tour-Kontext</h2>
            <textarea id="context-text" rows="4"
                placeholder="Der generierte Kontext erscheint hier – du kannst ihn anpassen."></textarea>
            <p class="muted">Dieser Kontext wird für jedes Cache-Log als Hintergrund genutzt.</p>
            <h3 class="sub-h">Tour-Daten</h3>
            <p class="muted">Änderungen an Infos oder Begleitern fließen erst in einen neu erzeugten Kontext ein.</p>
            <div id="tour-fields-slot"></div>
        </section>

        <!-- Schritt 3: Caches abarbeiten -->
        <section id="step-caches" class="card hidden">
            <h2 class="h2-row">3 · Caches <span id="progress" class="badge"></span>
                <button id="btn-sort-time" class="ghost icon-btn" type="button" title="Nach Fundzeit sortieren" aria-label="Nach Fundzeit sortieren">⏱</button>
            </h2>

            <p id="dup-banner" class="dup-box warn hidden"></p>
            <ul id="cache-list" class="cache-list"></ul>

            <label class="filelabel">
                📝 c:geo-Feldnotizen importieren
                <input type="file" class="fieldnotes-file" accept=".txt,text/plain">
            </label>
            <p class="muted fieldnotes-status">Übernimmt Status, Uhrzeit und Fundnummer (von Hand gesetzte Werte bleiben) und sortiert nach Fundzeit.</p>

            <div class="row">
                <button id="btn-generate-all" class="primary" type="button">✨ Offene Logs generieren</button>
            </div>
            <p id="generate-all-status" class="muted">Spart Anfragen: erzeugt alle Caches ohne Log in wenigen Sammel-Anfragen. Was nicht klappt, bleibt leer – einfach nochmal klicken. Notizen vorher eintragen ist optional.</p>

            <div id="cache-detail" class="detail hidden">
                <h3 id="cache-title"></h3>
                <div id="cache-meta" class="meta"></div>

                <div class="visit-row">
                    <label>Status
                        <select id="cache-status"></select>
                    </label>
                    <label>Datum
                        <input type="date" id="cache-date">
                    </label>
                    <label>Uhrzeit
                        <input type="time" id="cache-time">
                    </label>
                </div>

                <details id="cache-listing-wrap" class="listing-wrap">
                    <summary>Cache-Listing <span id="cache-listing-len" class="badge"></span></summary>
                    <div id="cache-listing" class="listing"></div>
                </details>

                <label>Notizen zu diesem Fund
                    <textarea id="cache-notes" rows="3"
                        placeholder="z. B. schnell gefunden, schöner Ausblick, Dose etwas feucht …"></textarea>
                </label>

                <button id="btn-log" class="primary" type="button">Log generieren</button>
                <p id="log-status" class="muted"></p>

                <label class="loglabel">Generiertes Log
                    <textarea id="log-text" rows="6"></textarea>
                </label>

                <div class="row">
                    <button id="btn-copy" class="secondary" type="button">📋 Kopieren</button>
                    <button id="btn-next" class="primary" type="button">Weiter ▸</button>
                </div>
            </div>
        </section>

        <!-- Touren (gespeichert wird automatisch) -->
        <section id="step-save" class="card hidden">
            <h2>4 · Touren</h2>
            <p class="muted">Alles wird automatisch gespeichert – Status oben rechts.</p>
            <div class="row">
                <button id="btn-export" class="ghost" type="button">📤 Field Notes</button>
                <button id="btn-tours" class="ghost" type="button">🗂 Meine Touren</button>
                <button id="btn-new" class="ghost" type="button">＋ Neue Tour</button>
            </div>
            <p id="save-status" class="muted"></p>
        </section>

        <!-- Field-Notes-Export (Draft-Datei für geocaching.com) -->
        <section id="step-export" class="card hidden">
            <h2>📤 Field Notes exportieren</h2>
            <p class="muted">Erzeugt <code>geocache_visits_DATUM_tourNR.txt</code> für geocaching.com → <b>Drafts</b> → Upload (gleiches Format wie c:geo).</p>
            <label>Empfänger
                <select id="export-recipient">
                    <option value="me">Ich (KI-Logs)</option>
                </select>
            </label>
            <div id="export-template-wrap" class="hidden">
                <label>Vorlage <span id="template-source" class="badge"></span>
                    <textarea id="export-template" rows="5"></textarea>
                </label>
                <p class="muted">Platzhalter antippen zum Einfügen:
                    <span class="ph-chips">
                        <button type="button" class="ph-chip" data-ph="{mitcacher}">{mitcacher}</button>
                        <button type="button" class="ph-chip" data-ph="{cachename}">{cachename}</button>
                        <button type="button" class="ph-chip" data-ph="{owner}">{owner}</button>
                        <button type="button" class="ph-chip" data-ph="{uhrzeit}">{uhrzeit}</button>
                        <button type="button" class="ph-chip" data-ph="{gc_code}">{gc_code}</button>
                    </span>
                </p>
                <p id="template-changed" class="dup-box info hidden"></p>
                <p id="export-mitcacher" class="muted"></p>
                <p id="template-unknown" class="field-hint hidden"></p>
                <div class="row">
                    <button id="btn-tpl-save-companion" class="ghost" type="button">Als Vorlage für … speichern</button>
                    <button id="btn-tpl-save-default" class="ghost" type="button">Als Standardvorlage speichern</button>
                    <button id="btn-export-share" class="ghost" type="button">🔗 Als Link freigeben</button>
                </div>
                <p id="template-status" class="muted"></p>
            </div>
            <p id="export-summary" class="muted"></p>
            <ul id="export-list" class="export-list"></ul>
            <details class="listing-wrap">
                <summary>Vorschau der ersten Einträge</summary>
                <pre id="export-preview" class="export-preview"></pre>
            </details>
            <div class="row">
                <button id="btn-export-download" class="primary" type="button">⬇ Datei herunterladen</button>
                <button id="btn-export-close" class="ghost" type="button">Schließen</button>
            </div>
            <p id="export-status" class="muted"></p>
        </section>

        <!-- Debug (nur wenn in config.php aktiviert) -->
        <?php if ($debug): ?>
        <section id="debug-panel" class="card">
            <h2>🐞 Debug</h2>
            <p class="muted">Aktiv via <code>config.php</code> (<code>'debug' =&gt; true</code>). Für den Live-Betrieb wieder ausschalten.</p>
            <div class="row">
                <button id="btn-debuglog" class="secondary" type="button">Log anzeigen</button>
                <button id="btn-debugclear" class="ghost" type="button">Log leeren</button>
            </div>
            <pre id="debug-out" class="debug-out"></pre>
        </section>
        <?php endif; ?>

    </section>
</main>

<script>
    window.GC_COMPANIONS = <?= json_encode(array_values($companions), JSON_UNESCAPED_UNICODE) ?>;
    window.GC_STORE = <?= $storeLogs ? 'true' : 'false' ?>;
    window.GC_DEBUG = <?= $debug ? 'true' : 'false' ?>;
    window.GC_BATCH_SIZE = <?= $batchSize ?>;
</script>
<script src="assets/common.js?v=1"></script>
<script src="assets/app.js?v=40"></script>
</body>
</html>
