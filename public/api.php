<?php

declare(strict_types=1);

// Die API muss IMMER sauberes JSON liefern. Fremdausgaben (PHP-Warnungen,
// Deprecations, ein BOM/Leerzeichen aus config.php o. ä.) würden den Body
// zerstören -> daher Ausgabe puffern und Fehleranzeige abschalten.
ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

/** @var array $config */
$config = require __DIR__ . '/../lib/bootstrap.php';

Auth::start();

// --- Debug-Modus -----------------------------------------------------------
$DEBUG     = !empty($config['debug']);
$DEBUG_LOG = rtrim(dirname((string) ($config['db_path'] ?? __DIR__ . '/../data/x')), '/\\') . '/debug.log';

// Optionale Ausweich-Modelle (kommagetrennt) für den Fall, dass das Hauptmodell überlastet ist.
$GEMINI_FALLBACK = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) ($config['gemini_model_fallback'] ?? ''))
)));

/** Schreibt eine Zeile ins Debug-Log (nur wenn Debug aktiv). */
function dbg_log(string $msg): void
{
    global $DEBUG, $DEBUG_LOG;
    if (!$DEBUG) {
        return;
    }
    $dir = dirname($DEBUG_LOG);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($DEBUG_LOG, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

function respond(array $data, int $code = 200): never
{
    global $DEBUG;
    // Alles, was versehentlich vor dem JSON ausgegeben wurde, verwerfen
    // (und zur Diagnose ins Debug-/PHP-Log schreiben).
    $stray = ob_get_level() > 0 ? ob_get_clean() : '';
    if (is_string($stray) && $stray !== '') {
        error_log('[gc_logger] Fremdausgabe vor JSON verworfen: ' . substr($stray, 0, 500));
        dbg_log('Fremdausgabe vor JSON verworfen: ' . $stray);
        if ($DEBUG) {
            $data['_stray'] = $stray;
        }
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function fail(string $msg, int $code = 400, ?Throwable $e = null): never
{
    global $DEBUG;
    $payload = ['ok' => false, 'error' => $msg];
    if ($e !== null) {
        dbg_log('Exception: ' . $e->getMessage()
            . ' @ ' . $e->getFile() . ':' . $e->getLine()
            . "\n" . $e->getTraceAsString());
        if ($DEBUG) {
            $payload['error']  = $msg . '  [' . basename($e->getFile()) . ':' . $e->getLine() . ']';
            $payload['_trace'] = explode("\n", $e->getTraceAsString());
        }
    } else {
        dbg_log('Fehler: ' . $msg);
    }
    respond($payload, $code);
}

// Auch fatale Fehler (z. B. fehlende cURL-Erweiterung) als JSON ausliefern.
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Serverfehler: ' . $e['message']], JSON_UNESCAPED_UNICODE);
    }
});

/** Inhalt der hochgeladenen GPX-Datei (Feld "gpx"). */
function gpx_upload(): string
{
    if (!isset($_FILES['gpx']) || $_FILES['gpx']['error'] !== UPLOAD_ERR_OK) {
        fail('Keine GPX-Datei empfangen.');
    }
    return (string) file_get_contents($_FILES['gpx']['tmp_name']);
}

/** JSON-Objekt aus einem POST-Feld (z. B. "fields"). */
function post_json(string $key): array
{
    $v = json_decode((string) ($_POST[$key] ?? ''), true);
    return is_array($v) ? $v : [];
}

/** GPX parsen; erkennt eigenes Log (gc_username) und Logs bekannter Begleiter. */
function parse_gpx(string $xml, array $config): array
{
    $companions = (array) ($config['companions'] ?? []);
    if (!empty($config['store_logs'])) {
        $companions = array_merge($companions, (new Db((string) $config['db_path']))->knownCompanions());
    }
    return Gpx::parse($xml, (string) ($config['gc_username'] ?? ''), array_values(array_unique($companions)));
}

/** DB für Aktionen, die Speichern voraussetzen. */
function storage_db(array $config): Db
{
    if (empty($config['store_logs'])) {
        fail('Speichern ist in der Konfiguration deaktiviert.');
    }
    return new Db((string) $config['db_path']);
}

$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');
dbg_log('Action: ' . ($action !== '' ? $action : '(leer)'));

try {
    // --- Öffentliche Aktionen (ohne Login) ---
    if ($action === 'login') {
        $pw = (string) ($_POST['password'] ?? '');
        if (Auth::attempt($pw, (string) $config['password_hash'])) {
            respond(['ok' => true]);
        }
        fail('Falsches Passwort.', 401);
    }

    if ($action === 'logout') {
        Auth::logout();
        respond(['ok' => true]);
    }

    // --- Ab hier Login erforderlich ---
    if (!Auth::isAuthed()) {
        fail('Nicht angemeldet.', 401);
    }

    switch ($action) {
        case 'upload_gpx': {
            // Nur parsen + Wiedererkennung prüfen; angelegt wird mit create_tour.
            $xml    = gpx_upload();
            $caches = parse_gpx($xml, $config);
            $match  = !empty($config['store_logs'])
                ? (new Db((string) $config['db_path']))->findMatch($caches)
                : ['type' => 'none'];
            respond(['ok' => true, 'caches' => $caches, 'match' => $match]);
        }

        case 'create_tour': {
            // mode: new (Default) | add (neue Caches an tour_id anhängen) | refresh (nur GPX-Felder auffrischen)
            $db     = storage_db($config);
            $xml    = gpx_upload();
            $caches = parse_gpx($xml, $config);
            $mode   = (string) ($_POST['mode'] ?? 'new');
            $added  = 0;
            if ($mode === 'new') {
                $id = $db->createTour($caches, $xml, post_json('meta'));
            } elseif ($mode === 'add' || $mode === 'refresh') {
                $id = (int) ($_POST['tour_id'] ?? 0);
                if ($db->getTour($id) === null) {
                    fail('Tour nicht gefunden.', 404);
                }
                $added = $db->mergeGpx($id, $caches, $xml, $mode === 'add');
            } else {
                fail('Unbekannter Modus.');
            }
            respond(['ok' => true, 'tour' => $db->getTour($id), 'added' => $added]);
        }

        case 'update_tour': {
            $db = storage_db($config);
            try {
                $updatedAt = $db->updateTour((int) ($_POST['id'] ?? 0), post_json('fields'));
            } catch (InvalidArgumentException $e) {
                fail($e->getMessage());
            }
            if ($updatedAt === null) {
                fail('Tour nicht gefunden.', 404);
            }
            respond(['ok' => true, 'updated_at' => $updatedAt]);
        }

        case 'update_cache': {
            $db  = storage_db($config);
            try {
                $res = $db->updateCache((int) ($_POST['id'] ?? 0), post_json('fields'));
            } catch (InvalidArgumentException $e) {
                fail($e->getMessage());
            }
            if ($res === null) {
                fail('Cache nicht gefunden.', 404);
            }
            respond(['ok' => true] + $res);
        }

        case 'reorder': {
            $db  = storage_db($config);
            $ids = json_decode((string) ($_POST['ids'] ?? '[]'), true);
            try {
                $updatedAt = $db->reorder((int) ($_POST['tour_id'] ?? 0), is_array($ids) ? $ids : []);
            } catch (InvalidArgumentException $e) {
                fail($e->getMessage());
            }
            respond(['ok' => true, 'updated_at' => $updatedAt]);
        }

        case 'generate_context': {
            $tourInfo   = (string) ($_POST['tour_info'] ?? '');
            $companions = json_decode((string) ($_POST['companions'] ?? '[]'), true) ?: [];
            $caches     = json_decode((string) ($_POST['caches'] ?? '[]'), true) ?: [];
            $gemini     = new Gemini((string) $config['gemini_api_key'], (string) $config['gemini_model'], 'dbg_log', $GEMINI_FALLBACK);
            $context    = $gemini->generate(Prompts::system(), Prompts::context($tourInfo, $companions, $caches));
            respond(['ok' => true, 'context' => $context]);
        }

        case 'generate_log': {
            $context = (string) ($_POST['context'] ?? '');
            $cache   = json_decode((string) ($_POST['cache'] ?? '{}'), true) ?: [];
            $notes   = (string) ($_POST['notes'] ?? '');
            $companions = json_decode((string) ($_POST['companions'] ?? '[]'), true) ?: [];
            $gemini  = new Gemini((string) $config['gemini_api_key'], (string) $config['gemini_model'], 'dbg_log', $GEMINI_FALLBACK);
            $log     = $gemini->generate(
                Prompts::system(),
                Prompts::log($context, $cache, $notes, $companions),
                ['maxOutputTokens' => Prompts::isForeign($cache) ? 4096 : 2048]
            );
            respond(['ok' => true, 'log' => $log]);
        }

        case 'generate_all': {
            // Sammel-Modus: alle übergebenen (offenen) Caches in EINER Anfrage.
            $context = (string) ($_POST['context'] ?? '');
            $caches  = json_decode((string) ($_POST['caches'] ?? '[]'), true) ?: [];
            $companions = json_decode((string) ($_POST['companions'] ?? '[]'), true) ?: [];
            if ($caches === []) {
                fail('Keine Caches zum Generieren übergeben.');
            }

            // Ausgabebudget großzügig an die Cache-Zahl anpassen.
            // Zweisprachige Logs (Caches außerhalb Deutschlands) brauchen etwa doppelt so viel.
            $maxTokens = 2048;
            foreach ($caches as $c) {
                $maxTokens += Prompts::isForeign($c) ? 3000 : 1500;
            }
            $maxTokens = min(32768, $maxTokens);

            $gemini = new Gemini((string) $config['gemini_api_key'], (string) $config['gemini_model'], 'dbg_log', $GEMINI_FALLBACK);
            $raw    = $gemini->generate(
                Prompts::system(),
                Prompts::logBatch($context, $caches, $companions),
                ['json' => true, 'maxOutputTokens' => $maxTokens]
            );

            // Robust auswerten: auch abgeschnittene Antworten nutzen, Unbrauchbares
            // verwerfen. Verworfene Caches bleiben leer (nächster Durchlauf füllt sie).
            ['logs' => $logs, 'rejected' => $rejected] = BatchLogs::evaluate($raw, $caches);
            if ($rejected !== []) {
                dbg_log('generate_all: verworfen: ' . json_encode($rejected, JSON_UNESCAPED_UNICODE)
                    . ' | Rohantwort: ' . substr($raw, 0, 1000));
            }

            respond(['ok' => true, 'logs' => $logs, 'rejected' => $rejected, 'count' => count($logs)]);
        }

        case 'list_tours': {
            if (empty($config['store_logs'])) {
                respond(['ok' => true, 'tours' => []]);
            }
            $db = new Db((string) $config['db_path']);
            respond(['ok' => true, 'tours' => $db->listTours()]);
        }

        case 'get_tour': {
            $db   = new Db((string) $config['db_path']);
            $id   = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
            $tour = $db->getTour($id);
            if ($tour === null) {
                fail('Tour nicht gefunden.', 404);
            }
            respond(['ok' => true, 'tour' => $tour]);
        }

        case 'delete_tour': {
            if (empty($config['store_logs'])) {
                fail('Speichern/Löschen ist in der Konfiguration deaktiviert.');
            }
            $id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
            if ($id <= 0) {
                fail('Ungültige Tour-ID.');
            }
            $db = new Db((string) $config['db_path']);
            $db->deleteTour($id);
            respond(['ok' => true]);
        }

        case 'debug_log': {
            if (!$DEBUG) {
                fail("Debug ist nicht aktiviert (config.php: 'debug' => true).");
            }
            $lines = is_file($DEBUG_LOG) ? array_slice(file($DEBUG_LOG) ?: [], -300) : [];
            respond(['ok' => true, 'log' => implode('', $lines), 'path' => $DEBUG_LOG]);
        }

        case 'debug_clear': {
            if (!$DEBUG) {
                fail("Debug ist nicht aktiviert (config.php: 'debug' => true).");
            }
            if (is_file($DEBUG_LOG)) {
                @unlink($DEBUG_LOG);
            }
            respond(['ok' => true]);
        }

        default:
            fail('Unbekannte Aktion.');
    }
} catch (Throwable $e) {
    fail($e->getMessage(), 500, $e);
}
