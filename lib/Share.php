<?php

declare(strict_types=1);

/**
 * Field-Notes-Export über einen Freigabe-Link (spec-v3): Kandidaten über alle Touren
 * der Freigabe, Exportiert-Status je Cache und die Gast-Ansicht.
 *
 * Die Gast-Ansicht ist eine Whitelist (VIEW_*_FIELDS): Der Begleiter sieht nie meine
 * KI-Logs, Notizen, den Tour-Kontext oder die rohe GPX.
 */
final class Share
{
    /** Maximale Länge einer Vorlage vom Gast (Zeichen). */
    public const MAX_TEMPLATE = 4000;

    private const VIEW_CACHE_FIELDS = ['id', 'code', 'name', 'type', 'log_type', 'ts', 'estimated', 'text',
        'companion_logged', 'exported_at', 'selected', 'locked'];
    private const VIEW_TOUR_FIELDS = ['id', 'tour_date', 'title', 'mitcacher'];

    /**
     * Touren der Freigabe (neueste zuerst) mit Kandidaten je Cache: wie beim Export für
     * einen Begleiter, dazu Typ, exported_at und locked (schon über diesen Link exportiert
     * → nicht mehr wählbar).
     * @param array<string,mixed> $share Ergebnis von Db::findShare
     * @return list<array<string,mixed>> je Tour: id, tour_date, title, mitcacher, caches
     */
    public static function tours(Db $db, array $share, string $template, string $me): array
    {
        $recipient = (string) $share['recipient'];
        $exported  = $db->exportedCaches((int) $share['id']);
        $out       = [];
        foreach ($share['tour_ids'] as $tid) {
            $tour = $db->getTour((int) $tid);
            if ($tour === null) {
                continue;
            }
            $types  = array_column($tour['caches'], 'type', 'id');
            $caches = FieldNotesExport::candidatesForCompanion($tour, $recipient, $template, $me);
            foreach ($caches as &$c) {
                $c['type']        = (string) ($types[$c['id']] ?? '');
                $c['exported_at'] = $exported[$c['id']] ?? null;
                $c['locked']      = $c['exported_at'] !== null;
                $c['selected']    = $c['selected'] && !$c['locked'];
            }
            unset($c);
            $out[] = [
                'id'        => (int) $tour['id'],
                'tour_date' => (string) ($tour['tour_date'] ?? ''),
                'title'     => (string) ($tour['title'] ?? ''),
                'mitcacher' => FieldNotesExport::mitcacher((array) $tour['companions'], $me, $recipient),
                'caches'    => $caches,
            ];
        }
        usort($out, static fn (array $a, array $b): int => [$b['tour_date'], $b['id']] <=> [$a['tour_date'], $a['id']]);
        return $out;
    }

    /**
     * Gast-Ansicht (nur Whitelist-Felder).
     * @param list<array<string,mixed>> $tours Ergebnis von tours()
     * @return list<array<string,mixed>>
     */
    public static function publicTours(array $tours): array
    {
        $cacheKeys = array_flip(self::VIEW_CACHE_FIELDS);
        return array_map(static fn (array $t): array => array_intersect_key($t, array_flip(self::VIEW_TOUR_FIELDS)) + [
            'caches' => array_map(static fn (array $c): array => array_intersect_key($c, $cacheKeys), $t['caches']),
        ], $tours);
    }

    /**
     * Einträge für die Draft-Datei: gewählte, wählbare Caches mit Text.
     * @param list<array<string,mixed>> $tours Ergebnis von tours()
     * @param list<int> $ids
     * @return list<array<string,mixed>>
     */
    public static function entries(array $tours, array $ids): array
    {
        $out = [];
        foreach ($tours as $t) {
            foreach ($t['caches'] as $c) {
                if (in_array($c['id'], $ids, true) && $c['available'] && !$c['locked'] && trim($c['text']) !== '') {
                    $out[] = $c;
                }
            }
        }
        return $out;
    }

    /**
     * Dateiname: eine Tour wie beim normalen Export, mehrere → mit Empfänger und heutigem
     * Datum, z. B. geocache_visits_Clicki17_2026-11-01.txt
     * @param list<array<string,mixed>> $tours
     */
    public static function filename(string $recipient, array $tours): string
    {
        if (count($tours) === 1) {
            return FieldNotesExport::filename($tours[0], $recipient);
        }
        $who = trim((string) preg_replace('/[^A-Za-z0-9_\-]+/', '_', $recipient), '_');
        return 'geocache_visits' . ($who !== '' ? '_' . $who : '') . '_' . Db::today() . '.txt';
    }
}
