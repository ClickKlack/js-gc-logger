<?php

declare(strict_types=1);

/** Interner Marker für vorübergehende (wiederholbare) Gemini-Fehler. */
final class GeminiTransientException extends RuntimeException {}

/**
 * Schlanker Client für die kostenlose Google-Gemini-REST-API (generateContent).
 * Nutzt cURL, keine externen Abhängigkeiten.
 *
 * - wiederholt vorübergehende Fehler (429/500/503, Netzwerk) automatisch
 * - kann optional auf Ausweich-Modelle umschalten, wenn das Hauptmodell überlastet ist
 */
final class Gemini
{
    private const MAX_ATTEMPTS = 3; // Versuche pro Modell

    /** @var callable|null */
    private $logger;

    /**
     * @param array<string> $fallbackModels weitere Modelle, falls das Hauptmodell überlastet ist
     */
    public function __construct(
        private string $apiKey,
        private string $model,
        ?callable $logger = null,
        private array $fallbackModels = []
    ) {
        $this->logger = $logger;
    }

    private function log(string $msg): void
    {
        if ($this->logger !== null) {
            ($this->logger)('Gemini: ' . $msg);
        }
    }

    public function generate(string $systemInstruction, string $userPrompt): string
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('Kein Gemini-API-Key konfiguriert. Bitte in config.php eintragen.');
        }

        // Hauptmodell + optionale Ausweichmodelle, Duplikate raus
        $models = array_values(array_unique(array_filter(
            array_merge([$this->model], $this->fallbackModels),
            static fn ($m) => is_string($m) && trim($m) !== ''
        )));

        $lastTransient = null;

        foreach ($models as $model) {
            for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
                try {
                    return $this->callOnce($model, $systemInstruction, $userPrompt);
                } catch (GeminiTransientException $e) {
                    $lastTransient = $e;
                    $this->log("vorübergehender Fehler ($model, Versuch $attempt/" . self::MAX_ATTEMPTS . '): ' . $e->getMessage());
                    if ($attempt < self::MAX_ATTEMPTS) {
                        sleep($attempt); // 1s, dann 2s
                    }
                }
                // RuntimeException (dauerhaft: falscher Key, Modell 404, Block) fliegt direkt durch
            }
            if (count($models) > 1) {
                $this->log("wechsle zum nächsten Modell nach Überlastung von $model");
            }
        }

        throw new RuntimeException(
            'Das KI-Modell ist gerade überlastet. Bitte in ein paar Sekunden noch einmal versuchen.'
            . ($lastTransient ? ' (' . $lastTransient->getMessage() . ')' : '')
        );
    }

    /**
     * Ein einzelner API-Aufruf. Wirft GeminiTransientException bei wiederholbaren
     * Fehlern, RuntimeException bei dauerhaften.
     */
    private function callOnce(string $model, string $systemInstruction, string $userPrompt): string
    {
        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            rawurlencode($model),
            rawurlencode($this->apiKey)
        );

        $genConfig = ['temperature' => 0.9, 'maxOutputTokens' => 2048];
        // Bei Gemini-2.5-Modellen das interne "Thinking" abschalten. Sonst zählen
        // die Reasoning-Tokens gegen maxOutputTokens und der Log wirkt abgeschnitten
        // (finishReason MAX_TOKENS). Für kurze Logs ist Thinking ohnehin unnötig.
        if (stripos($model, '2.5') !== false) {
            $genConfig['thinkingConfig'] = ['thinkingBudget' => 0];
        }

        $payload = [
            'systemInstruction' => ['parts' => [['text' => $systemInstruction]]],
            'contents'          => [['role' => 'user', 'parts' => [['text' => $userPrompt]]]],
            'generationConfig'  => $genConfig,
        ];

        $this->log("Anfrage model=$model promptLen=" . strlen($userPrompt));

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT        => 60,
        ]);
        $resp     = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);

        $this->log("Antwort HTTP=$httpCode bodyLen=" . strlen((string) $resp));

        // Netzwerkfehler -> wiederholbar
        if ($resp === false) {
            throw new GeminiTransientException('Netzwerkfehler: ' . $curlErr);
        }

        $data = json_decode((string) $resp, true);
        $apiMsg = $data['error']['message'] ?? ('HTTP ' . $httpCode);

        // Vorübergehende Server-/Limit-Fehler -> wiederholbar
        if (in_array($httpCode, [429, 500, 503], true)) {
            $this->log("vorübergehend ($httpCode): " . substr((string) $resp, 0, 500));
            throw new GeminiTransientException($apiMsg);
        }

        // Dauerhafte Fehler -> klare Meldung, kein Retry
        if ($httpCode >= 400) {
            $this->log("Fehler-Body ($httpCode): " . substr((string) $resp, 0, 1000));
            if ($httpCode === 404) {
                throw new RuntimeException("Gemini-Modell '$model' nicht gefunden. Modellname in config.php prüfen.");
            }
            if ($httpCode === 400 || $httpCode === 401 || $httpCode === 403) {
                throw new RuntimeException('Gemini lehnt die Anfrage ab (evtl. ungültiger API-Key): ' . $apiMsg);
            }
            throw new RuntimeException('Gemini-Fehler: ' . $apiMsg);
        }

        $finishReason = $data['candidates'][0]['finishReason'] ?? '';
        $text         = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if ($text === null || trim((string) $text) === '') {
            $this->log('Leere/blockierte Antwort: ' . substr((string) $resp, 0, 1000));
            $reason = $data['promptFeedback']['blockReason'] ?: ($finishReason ?: 'unbekannt');
            throw new RuntimeException('Keine verwertbare Antwort von Gemini erhalten (Grund: ' . $reason . ').');
        }

        // Wurde wegen Token-Limit abgeschnitten? Loggen (mit Fix oben sollte das nicht mehr passieren).
        if ($finishReason === 'MAX_TOKENS') {
            $this->log('WARNUNG: Antwort bei MAX_TOKENS abgeschnitten – maxOutputTokens ggf. erhöhen.');
        } else {
            $this->log("ok finishReason=$finishReason textLen=" . strlen((string) $text));
        }

        return trim((string) $text);
    }
}
