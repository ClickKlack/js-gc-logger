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
    <link rel="stylesheet" href="assets/style.css?v=13">
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
            <p id="load-status" class="muted"></p>
            <ul id="tour-list" class="tour-list"></ul>
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

            <label>Tour-Datum
                <input type="date" class="tour-date" id="tour-date">
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
                <label class="freitext">Weitere (Komma-getrennt)
                    <input type="text" id="companions-extra" placeholder="Name1, Name2">
                </label>
            </fieldset>

            <button id="btn-context" class="primary" type="button" disabled>Kontext erzeugen</button>
            <p id="context-status" class="muted"></p>
        </section>

        <!-- Schritt 2: Kontext -->
        <section id="step-context" class="card hidden">
            <h2>2 · Tour-Kontext</h2>
            <textarea id="context-text" rows="4"
                placeholder="Der generierte Kontext erscheint hier – du kannst ihn anpassen."></textarea>
            <p class="muted">Dieser Kontext wird für jedes Cache-Log als Hintergrund genutzt.</p>
            <label>Tour-Datum
                <input type="date" class="tour-date" id="tour-date-ctx">
            </label>
            <p class="muted">Gilt für alle Caches ohne eigene Uhrzeit bzw. ohne von Hand gesetztes Datum.</p>
        </section>

        <!-- Schritt 3: Caches abarbeiten -->
        <section id="step-caches" class="card hidden">
            <h2>3 · Caches <span id="progress" class="badge"></span></h2>

            <ul id="cache-list" class="cache-list"></ul>

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
                <button id="btn-tours" class="ghost" type="button">🗂 Meine Touren</button>
                <button id="btn-new" class="ghost" type="button">＋ Neue Tour</button>
            </div>
            <p id="save-status" class="muted"></p>
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
<script src="assets/app.js?v=14"></script>
</body>
</html>
