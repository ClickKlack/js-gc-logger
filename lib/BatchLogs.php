<?php

declare(strict_types=1);

/**
 * Auswertung der Sammel-Antwort (generate_all): JSON robust lesen – auch wenn die
 * Antwort abgeschnitten ist – und unbrauchbare Logs aussortieren. Aussortierte
 * Caches bleiben leer und können in einem weiteren Durchlauf gefüllt werden.
 */
final class BatchLogs
{
    private const MIN_LENGTH = 80; // Zeichen; kürzer = kein echtes Log

    /**
     * @param array<int, array<string,mixed>> $caches die angefragten Caches
     * @return array{logs: array<string,string>, rejected: array<string,string>}
     *         logs: code => Logtext; rejected: code => Grund
     */
    public static function evaluate(string $raw, array $caches): array
    {
        $byCode = [];
        foreach ($caches as $c) {
            $byCode[(string) ($c['code'] ?? '')] = $c;
        }

        $logs     = [];
        $rejected = [];
        foreach (self::extract($raw) as $code => $log) {
            if (!isset($byCode[$code])) {
                continue; // unbekannter Code -> ignorieren
            }
            $reason = self::problem($log, $byCode[$code]);
            if ($reason !== null) {
                $rejected[$code] = $reason;
            } else {
                $logs[$code] = trim($log);
            }
        }
        foreach ($byCode as $code => $_) {
            if (!isset($logs[$code]) && !isset($rejected[$code])) {
                $rejected[$code] = 'fehlt in der Antwort';
            }
        }

        return ['logs' => $logs, 'rejected' => $rejected];
    }

    /**
     * Liest code => log aus der Antwort. Ist das JSON kaputt (z. B. bei MAX_TOKENS
     * mitten im Text abgeschnitten), werden nur die vollständigen Objekte übernommen.
     *
     * @return array<string,string>
     */
    public static function extract(string $raw): array
    {
        $clean   = trim($raw);
        $clean   = (string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $clean);
        $decoded = json_decode($clean, true);

        $items = is_array($decoded) ? $decoded : self::completeObjects($clean);

        // Array von {code, log} ODER {code: log}
        $logs = [];
        foreach ($items as $k => $v) {
            if (is_array($v) && isset($v['code'], $v['log']) && is_string($v['log'])) {
                $logs[(string) $v['code']] = $v['log'];
            } elseif (is_string($v)) {
                $logs[(string) $k] = $v;
            }
        }
        return $logs;
    }

    /**
     * Sucht alle vollständig geschlossenen {...}-Objekte der obersten Array-Ebene.
     *
     * @return array<int, mixed>
     */
    private static function completeObjects(string $text): array
    {
        $out      = [];
        $depth    = 0;
        $inString = false;
        $escape   = false;
        $start    = -1;
        $len      = strlen($text);

        for ($i = 0; $i < $len; $i++) {
            $ch = $text[$i];
            if ($inString) {
                if ($escape)        { $escape = false; }
                elseif ($ch === '\\') { $escape = true; }
                elseif ($ch === '"')  { $inString = false; }
                continue;
            }
            if ($ch === '"') {
                $inString = true;
            } elseif ($ch === '{') {
                if ($depth === 0) {
                    $start = $i;
                }
                $depth++;
            } elseif ($ch === '}' && $depth > 0) {
                $depth--;
                if ($depth === 0 && $start >= 0) {
                    $obj = json_decode(substr($text, $start, $i - $start + 1), true);
                    if (is_array($obj)) {
                        $out[] = $obj;
                    }
                    $start = -1;
                }
            }
        }
        return $out;
    }

    /**
     * Grund, warum ein Log unbrauchbar ist, oder null wenn es in Ordnung ist.
     *
     * @param array<string,mixed> $cache
     */
    private static function problem(string $log, array $cache): ?string
    {
        $log = trim($log);
        if (mb_strlen($log) < self::MIN_LENGTH) {
            return 'zu kurz';
        }

        // Sätze, die sich auffällig wiederholen (typisches "Abdriften" langer Antworten)
        $sentences = array_values(array_filter(array_map(
            static fn ($s) => mb_strtolower(trim($s)),
            preg_split('/(?<=[.!?])\s+/u', $log) ?: []
        ), static fn ($s) => mb_strlen($s) >= 15));
        if (count($sentences) >= 4 && count(array_unique($sentences)) / count($sentences) < 0.7) {
            return 'Wiederholungen';
        }
        // Gleicher Textbaustein mehrfach direkt hintereinander
        if (preg_match('/(.{12,80}?)\1{2,}/su', $log) === 1) {
            return 'Wiederholungen';
        }

        // Zweisprachig verlangt: Hat das Log eine Länderflagge, muss auch der deutsche
        // Teil (🇩🇪) da sein. Ohne Flagge = deutschsprachiges Land, nur Deutsch – ok.
        if (Prompts::isForeign($cache)
            && preg_match('/[\x{1F1E6}-\x{1F1FF}]{2}/u', $log) === 1
            && !str_contains($log, "\u{1F1E9}\u{1F1EA}")) {
            return 'deutscher Teil fehlt';
        }

        return null;
    }
}
