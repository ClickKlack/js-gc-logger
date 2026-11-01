<?php

declare(strict_types=1);

/**
 * Gastseite für Freigabe-Links (spec-v3): Ein Begleiter lädt ohne Login seine Field
 * Notes für die freigegebenen Touren herunter.
 *
 * Einzige öffentliche Seite neben dem Login. Bewusst getrennt von api.php: Von hier
 * aus ist nur erreichbar, was der Link-Empfänger braucht (Ansicht, Vorschau, eigene
 * Vorlage speichern, Download). Kein Auth::start(), keine Gemini-/Backup-/Tour-Aktionen.
 *
 *   GET  share.php?t=TOKEN               → Seite (bzw. „abgelaufen oder ungültig")
 *   POST share.php  t=TOKEN action=…     → load | save_template | download
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

/** @var array $config */
$config = require __DIR__ . '/../lib/bootstrap.php';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');        // Token nicht per Referer weitergeben
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function share_respond(array $data, int $code = 200): never
{
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function share_fail(string $msg, int $code = 400): never
{
    share_respond(['ok' => false, 'error' => $msg], $code);
}

/** Vorlage aus dem Request (Zeilenenden normalisiert, Längenlimit) oder null. */
function share_template(): ?string
{
    if (!isset($_POST['template'])) {
        return null;
    }
    $tpl = str_replace(["\r\n", "\r"], "\n", (string) $_POST['template']);
    if (mb_strlen($tpl) > Share::MAX_TEMPLATE) {
        share_fail('Die Vorlage ist zu lang (max. ' . Share::MAX_TEMPLATE . ' Zeichen).');
    }
    return $tpl;
}

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$token  = (string) ($isPost ? ($_POST['t'] ?? '') : ($_GET['t'] ?? ''));
$me     = trim((string) ($config['gc_username'] ?? ''));

$db    = null;
$share = null;
try {
    if (!empty($config['store_logs']) && preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
        $db    = new Db((string) $config['db_path']);
        $share = $db->findShare($token);
    }
} catch (Throwable $e) {
    error_log('[gc_logger] share.php: ' . $e->getMessage());
    $isPost ? share_fail('Serverfehler.', 500) : http_response_code(500);
}
if ($share === null) {
    usleep(random_int(200000, 400000));   // ungültige Links nicht im Schnelldurchlauf testen
}

if ($isPost) {
    if ($share === null) {
        share_fail('Dieser Link ist abgelaufen oder ungültig.', 404);
    }
    $recipient = (string) $share['recipient'];
    try {
        switch ((string) ($_POST['action'] ?? '')) {
            case 'load': {
                // Ansicht; mit template = Vorschau einer geänderten Vorlage
                $tpl    = share_template();
                $stored = $db->companionTemplate($recipient);
                $use    = $tpl ?? $stored['template'];
                $left   = (int) ceil((strtotime($share['expires_at'] . ' UTC') - time()) / 86400);
                share_respond([
                    'ok'              => true,
                    'recipient'       => $recipient,
                    'expires_at'      => $share['expires_at'],
                    'days_left'       => max(0, $left),
                    'template'        => $use,
                    'template_source' => $tpl !== null ? 'edited' : $stored['source'],
                    'unknown'         => FieldNotesExport::unknownPlaceholders($use),
                    'tours'           => Share::publicTours(Share::tours($db, $share, $use, $me)),
                ]);
            }

            case 'save_template': {
                // Nur die eigene Vorlage des Link-Empfängers — nie die Standardvorlage
                $tpl = share_template();
                if ($tpl === null || trim($tpl) === '') {
                    share_fail('Die Vorlage ist leer.');
                }
                $db->saveTemplate($recipient, $tpl, 'share');
                share_respond(['ok' => true]);
            }

            case 'download': {
                $tpl   = share_template() ?? $db->companionTemplate($recipient)['template'];
                $ids   = array_map('intval', (array) (json_decode((string) ($_POST['ids'] ?? '[]'), true) ?: []));
                $tours = Share::tours($db, $share, $tpl, $me);
                $entries = Share::entries($tours, $ids);
                if ($entries === []) {
                    share_fail('Keine Caches ausgewählt.');
                }
                $bytes = FieldNotesExport::build($entries);
                $db->markExported((int) $share['id'], array_column($entries, 'id'));
                if (ob_get_level() > 0) {
                    ob_end_clean();
                }
                header('Content-Type: text/plain; charset=UTF-16LE');
                header('Content-Disposition: attachment; filename="' . Share::filename($recipient, $tours) . '"');
                header('Content-Length: ' . strlen($bytes));
                echo $bytes;
                exit;
            }

            default:
                share_fail('Unbekannte Aktion.');
        }
    } catch (Throwable $e) {
        error_log('[gc_logger] share.php: ' . $e->getMessage());
        share_fail('Serverfehler.', 500);
    }
}

if ($share === null && http_response_code() === 200) {
    http_response_code(404);
}
ob_end_flush();
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Field Notes</title>
    <link rel="stylesheet" href="assets/style.css?v=31">
</head>
<body>

<header class="topbar">
    <h1>🧭 Field Notes</h1>
</header>

<main class="wrap">
<?php if ($share === null): ?>
    <section class="card">
        <h2>Link abgelaufen oder ungültig</h2>
        <p>Dieser Link funktioniert nicht (mehr). Freigabe-Links gelten 14 Tage.
            Bitte frag nach einem neuen Link.</p>
    </section>
<?php else: ?>
    <section class="card">
        <h2 id="share-title">Field Notes für <?= htmlspecialchars((string) $share['recipient'], ENT_QUOTES) ?></h2>
        <p id="share-valid" class="muted"></p>
        <p class="muted">Caches auswählen, Text prüfen, Datei herunterladen und bei
            <b>geocaching.com</b> unter <b>Drafts → Upload</b> hochladen. Dort kannst du jeden
            Draft noch anpassen, bevor er als Log veröffentlicht wird.</p>
    </section>

    <section class="card">
        <h2>✏️ Log-Text</h2>
        <label>Vorlage <span id="template-source" class="badge"></span>
            <textarea id="share-template" rows="5"></textarea>
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
        <p id="template-unknown" class="field-hint hidden"></p>
        <p class="muted">Änderungen gelten für diesen Download. Gespeichert wird die Vorlage auch
            für spätere Field Notes für dich verwendet.</p>
        <div class="row">
            <button id="btn-template-save" class="ghost" type="button">💾 Als meine Vorlage speichern</button>
        </div>
        <p id="template-status" class="muted" aria-live="polite"></p>
        <details class="listing-wrap">
            <summary>Vorschau der ersten Einträge</summary>
            <pre id="share-preview" class="export-preview"></pre>
        </details>
    </section>

    <section class="card">
        <h2>📋 Caches</h2>
        <p id="share-summary" class="muted"></p>
        <div id="share-groups"></div>
        <div class="row">
            <button id="btn-download" class="primary" type="button" disabled>⬇ Field Notes herunterladen</button>
        </div>
        <p id="download-status" class="muted" aria-live="polite"></p>
    </section>
<?php endif; ?>
</main>

<?php if ($share !== null): ?>
<script src="assets/common.js?v=1"></script>
<script src="assets/share.js?v=2"></script>
<?php endif; ?>
</body>
</html>
