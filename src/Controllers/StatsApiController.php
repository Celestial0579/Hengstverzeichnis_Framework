<?php
// src/Controllers/StatsApiController.php

namespace App\Controllers;

use App\Database;
use PDO;

/**
 * Zeitreihen-Endpunkt für externe Dashboards (#270).
 *
 * Anlass: Es gab bisher keinerlei Metrik-Historie. `DigestService` liefert
 * zwei Live-Zähler per E-Mail, `recordStatus()` speichert nur den letzten
 * Schnappschuss, das Admin-Dashboard ist ebenfalls eine Momentaufnahme.
 *
 * Bewusst KEINE neue Historien-Tabelle und kein Sammel-Job: Die Zeitstempel
 * für die interessanten Verläufe stehen längst in den Fachtabellen
 * (`created_at` und Freunde, überwiegend indiziert). Eine zweite, redundante
 * Ablage müsste befüllt, überwacht und aufgeräumt werden und wäre nach einem
 * Rollback still unvollständig - während die Fachtabellen die Wahrheit ohnehin
 * schon enthalten. Reicht die Auflösung eines Tages nicht mehr, ist das der
 * Moment für einen Sammler, nicht vorher.
 *
 * Ebenfalls bewusst kein Prometheus-`/metrics`: Das brächte ein weiteres
 * Composer-Paket und ein eigenes Auth-Modell neben dem vorhandenen
 * Bearer-Mechanismus. Grafana bindet diesen Endpunkt über eine generische
 * JSON-Datenquelle (Infinity o. ä.) ein, die Bearer-Header nativ kennt.
 *
 * ## Sicherheit
 *
 * Die Zahlen hier sind betriebsintern - DSGVO-Anfragen, Login-Fehlversuche,
 * Benutzer- und Schlüsselbestand. Ein offener Endpunkt verriete Angreifern,
 * ob ihre Versuche ankommen. Deshalb: Bearer-Pflicht wie die übrige API PLUS
 * ein eigenes Recht `stats.view`, das in keiner Standardgruppe vorbelegt ist
 * (siehe `PermissionRegistry`). Ein Katalog-Schlüssel mit `horses.view` kommt
 * hier also NICHT durch.
 *
 * ## Keine Zeichenkette aus dem Request landet je in SQL
 *
 * `metric` und `interval` werden nicht eingesetzt, sondern als Schlüssel in
 * feste Definitionen nachgeschlagen (METRICS/INTERVALS). Ein unbekannter Wert
 * ist ein 400, kein Fragment. Datumsangaben werden geparst und normalisiert
 * wieder ausgegeben, nie durchgereicht; Grenzen gehen als Parameter in die
 * Prepared Statements.
 */
class StatsApiController extends JsonApiController {

    /** Recht, das dieser Endpunkt verlangt (Modul × Aktion). */
    private const PERMISSION_MODULE = 'stats';
    private const PERMISSION_ACTION = 'view';

    /**
     * Obergrenze für die Zahl der Zeitkübel einer Antwort.
     *
     * Ohne Deckel ließe sich mit `interval=day&from=1900-01-01` eine Antwort
     * mit Zehntausenden Zeilen anfordern - die Lückenfüllung (siehe
     * `fillGaps()`) erzeugt sie auch dann, wenn gar keine Daten vorliegen.
     * 1500 Tage sind gut vier Jahre am Stück und damit jenseits dessen, was
     * ein Dashboard-Panel sinnvoll darstellt.
     */
    private const MAX_BUCKETS = 1500;

    /** Standard-Zeitraum, wenn der Aufrufer keinen nennt. */
    private const DEFAULT_DAYS = 30;

    /**
     * Die auswertbaren Reihen. Jede nennt ihre Tabelle, die Zeitspalte und
     * eine feste Zusatzbedingung - alles Literale aus dieser Datei.
     *
     * `filter_column` benennt die Spalte, auf die der optionale Parameter
     * `filter` wirkt. Auch sie ist ein Literal von hier; der WERT des Filters
     * kommt als gebundener Parameter in die Abfrage.
     *
     * @var array<string, array{table:string, time:string, where:string, filter_column:?string, label:string}>
     */
    private const METRICS = [
        'horses.created' => [
            'table' => 'horses',
            'time' => 'created_at',
            'where' => 'deleted_at IS NULL',
            'filter_column' => null,
            'label' => 'Angelegte Pferde (ohne gelöschte)',
        ],
        'horses.published' => [
            'table' => 'horses',
            'time' => 'created_at',
            'where' => 'deleted_at IS NULL AND is_published = 1',
            'filter_column' => null,
            // Kein Veröffentlichungs-Zeitstempel im Schema: gezählt wird nach
            // ANLAGE-Datum, eingeschränkt auf heute veröffentlichte Pferde.
            // Die Reihe beantwortet "wie viele der damals angelegten sind
            // heute öffentlich", nicht "wann wurde veröffentlicht".
            'label' => 'Angelegte Pferde, die heute veröffentlicht sind',
        ],
        'gdpr_requests.created' => [
            'table' => 'gdpr_requests',
            'time' => 'created_at',
            'where' => '1',
            'filter_column' => 'request_type',
            'label' => 'DSGVO-Anfragen (filter: info|deletion)',
        ],
        'audit_logs.created' => [
            'table' => 'audit_logs',
            'time' => 'created_at',
            'where' => '1',
            'filter_column' => 'category',
            'label' => 'Audit-Ereignisse (filter: Kategorie, z. B. security)',
        ],
        'login_attempts.created' => [
            'table' => 'login_attempts',
            'time' => 'created_at',
            'where' => '1',
            'filter_column' => 'type',
            'label' => 'Fehlgeschlagene Anmeldeversuche (filter: Typ, z. B. login)',
        ],
        'api_keys.created' => [
            'table' => 'api_keys',
            'time' => 'created_at',
            'where' => '1',
            'filter_column' => null,
            'label' => 'Ausgestellte API-Schlüssel',
        ],
        'api_keys.last_used' => [
            'table' => 'api_keys',
            'time' => 'last_used_at',
            'where' => 'last_used_at IS NOT NULL',
            'filter_column' => null,
            'label' => 'Zuletzt benutzte API-Schlüssel',
        ],
        'users.created' => [
            'table' => 'users',
            'time' => 'created_at',
            'where' => '1',
            'filter_column' => null,
            'label' => 'Angelegte Benutzerkonten',
        ],
    ];

    /**
     * Kübelbreiten. `sql` gruppiert, `step` schreitet beim Lückenfüllen fort.
     *
     * Die Woche beginnt montags (`WEEKDAY()` ist 0 für Montag) - ISO-8601 und
     * damit dieselbe Woche, die Grafana anzeigt.
     *
     * @var array<string, array{sql:string, step:string}>
     */
    private const INTERVALS = [
        'day' => ['sql' => 'DATE(%s)', 'step' => '+1 day'],
        'week' => ['sql' => 'DATE(%s - INTERVAL WEEKDAY(%s) DAY)', 'step' => '+1 week'],
        'month' => ['sql' => "DATE_FORMAT(%s, '%%Y-%%m-01')", 'step' => '+1 month'],
    ];

    /**
     * GET /api/stats
     *
     * Parameter: metric (Pflicht), from, to (JJJJ-MM-TT), interval
     * (day|week|month), filter.
     *
     * Ohne `metric` wird der Katalog der verfügbaren Reihen geliefert - so
     * findet sich beim Einrichten der Datenquelle heraus, was es gibt, ohne
     * in die Dokumentation zu wechseln.
     */
    public function index(): void {
        $this->requireApiKey();

        if (!$this->apiCan(self::PERMISSION_MODULE, self::PERMISSION_ACTION)) {
            $this->respondJson([
                'error' => 'forbidden',
                'message' => 'Dieser Schlüssel darf keine Statistiken lesen (benötigt das Recht "stats.view").',
            ], 403);
        }

        $metricKey = trim((string)($_GET['metric'] ?? ''));
        if ($metricKey === '') {
            $this->respondCatalogue();
        }

        if (!isset(self::METRICS[$metricKey])) {
            $this->respondJson([
                'error' => 'unknown_metric',
                'message' => 'Unbekannte Reihe "' . $metricKey . '".',
                'available' => array_keys(self::METRICS),
            ], 400);
        }

        $intervalKey = trim((string)($_GET['interval'] ?? 'day'));
        if (!isset(self::INTERVALS[$intervalKey])) {
            $this->respondJson([
                'error' => 'unknown_interval',
                'message' => 'Unbekannte Kübelbreite "' . $intervalKey . '".',
                'available' => array_keys(self::INTERVALS),
            ], 400);
        }

        [$from, $to] = $this->resolveRange();
        if ($from > $to) {
            $this->respondJson([
                'error' => 'invalid_range',
                'message' => 'from liegt nach to.',
            ], 400);
        }

        $buckets = $this->countBuckets($from, $to, self::INTERVALS[$intervalKey]['step']);
        if ($buckets > self::MAX_BUCKETS) {
            $this->respondJson([
                'error' => 'range_too_large',
                'message' => sprintf(
                    'Der Zeitraum ergäbe %d Kübel, erlaubt sind %d. Zeitraum verkürzen oder gröber gruppieren (interval=week|month).',
                    $buckets,
                    self::MAX_BUCKETS
                ),
            ], 400);
        }

        $filter = $this->resolveFilter($metricKey);
        $rows = $this->query($metricKey, $intervalKey, $from, $to, $filter);
        $series = $this->fillGaps($rows, $from, $to, self::INTERVALS[$intervalKey]['step']);

        $this->respondJson([
            'data' => $series,
            'meta' => [
                'metric' => $metricKey,
                'label' => self::METRICS[$metricKey]['label'],
                'interval' => $intervalKey,
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'filter' => $filter,
                'buckets' => count($series),
                'total' => array_sum(array_column($series, 'value')),
            ],
        ]);
    }

    /** Katalog der Reihen - die Antwort auf "was gibt es hier eigentlich?". */
    private function respondCatalogue(): void {
        $metrics = [];
        foreach (self::METRICS as $key => $definition) {
            $metrics[] = [
                'metric' => $key,
                'label' => $definition['label'],
                'filterable' => $definition['filter_column'] !== null,
            ];
        }

        $this->respondJson([
            'data' => $metrics,
            'meta' => [
                'intervals' => array_keys(self::INTERVALS),
                'max_buckets' => self::MAX_BUCKETS,
                'default_days' => self::DEFAULT_DAYS,
                'usage' => '/api/stats?metric=<metric>&from=JJJJ-MM-TT&to=JJJJ-MM-TT&interval=day|week|month',
            ],
        ]);
    }

    /**
     * `from`/`to` einlesen. Fehlt etwas, gilt der Standardzeitraum bis heute.
     * Ungültige Angaben sind ein Fehler und werden nicht stillschweigend durch
     * den Standard ersetzt - sonst zeigt ein Dashboard nach einem Tippfehler
     * plausible, aber falsche Zahlen.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function resolveRange(): array {
        $to = $this->parseDate((string)($_GET['to'] ?? ''), 'to') ?? new \DateTimeImmutable('today');
        $from = $this->parseDate((string)($_GET['from'] ?? ''), 'from')
            ?? $to->modify('-' . (self::DEFAULT_DAYS - 1) . ' days');

        return [$from, $to];
    }

    /**
     * Strikt JJJJ-MM-TT. `DateTimeImmutable` allein genügt nicht: Es
     * akzeptiert auch "morgen" oder "2026-02-31" (und rollt Letzteres
     * stillschweigend in den März).
     */
    private function parseDate(string $value, string $parameter): ?\DateTimeImmutable {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            $this->respondJson([
                'error' => 'invalid_date',
                'message' => 'Parameter "' . $parameter . '" muss JJJJ-MM-TT sein.',
            ], 400);
        }

        return $date;
    }

    /** Optionaler Wertfilter - nur für Reihen, die eine Filterspalte nennen. */
    private function resolveFilter(string $metricKey): ?string {
        $filter = trim((string)($_GET['filter'] ?? ''));
        if ($filter === '') {
            return null;
        }

        if (self::METRICS[$metricKey]['filter_column'] === null) {
            $this->respondJson([
                'error' => 'filter_not_supported',
                'message' => 'Die Reihe "' . $metricKey . '" kennt keinen Filter.',
            ], 400);
        }

        return $filter;
    }

    /**
     * Zahl der Kübel im Zeitraum - vorab berechnet, damit eine zu große
     * Anfrage abgelehnt wird, BEVOR die Datenbank sie ausführt.
     */
    private function countBuckets(\DateTimeImmutable $from, \DateTimeImmutable $to, string $step): int {
        $count = 0;
        $cursor = $this->snapToBucket($from, $step);
        while ($cursor <= $to) {
            $count++;
            if ($count > self::MAX_BUCKETS) {
                // Weiterzählen wäre bei einem Zeitraum von Jahrhunderten teuer;
                // für die Ablehnung genügt "mehr als erlaubt".
                return $count;
            }
            $cursor = $cursor->modify($step);
        }

        return $count;
    }

    /**
     * Setzt einen Zeitpunkt auf den Anfang seines Kübels - sonst begänne die
     * Lückenfüllung neben dem Raster, das die Datenbank gruppiert hat, und
     * KEIN gefüllter Kübel träfe einen echten.
     */
    private function snapToBucket(\DateTimeImmutable $date, string $step): \DateTimeImmutable {
        return match ($step) {
            '+1 week' => $date->modify('monday this week'),
            '+1 month' => $date->modify('first day of this month'),
            default => $date,
        };
    }

    /**
     * @param array<int, array{bucket:string, value:int}> $rows
     * @return array<int, array{bucket:string, value:int}>
     */
    private function query(
        string $metricKey,
        string $intervalKey,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?string $filter
    ): array {
        $metric = self::METRICS[$metricKey];
        $timeColumn = '`' . $metric['time'] . '`';

        // Beide %s der Wochen-Variante brauchen dieselbe Spalte.
        $bucketSql = sprintf(
            self::INTERVALS[$intervalKey]['sql'],
            $timeColumn,
            $timeColumn
        );

        $sql = 'SELECT ' . $bucketSql . ' AS bucket, COUNT(*) AS value'
            . ' FROM `' . $metric['table'] . '`'
            . ' WHERE ' . $metric['where']
            . ' AND ' . $timeColumn . ' >= ?'
            . ' AND ' . $timeColumn . ' < ?';

        // Obergrenze exklusiv auf den Folgetag: So zählt der letzte Tag
        // vollständig mit, ohne dass die Zeitspalte in der Bedingung in eine
        // Funktion gewickelt wird (das schlüge den Index aus).
        $params = [
            $from->format('Y-m-d') . ' 00:00:00',
            $to->modify('+1 day')->format('Y-m-d') . ' 00:00:00',
        ];

        if ($filter !== null) {
            $sql .= ' AND `' . $metric['filter_column'] . '` = ?';
            $params[] = $filter;
        }

        $sql .= ' GROUP BY bucket ORDER BY bucket ASC';

        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute($params);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = ['bucket' => (string)$row['bucket'], 'value' => (int)$row['value']];
        }

        return $rows;
    }

    /**
     * Fehlende Kübel mit 0 auffüllen.
     *
     * Ohne das zeichnet Grafana eine Linie von einem Datenpunkt zum nächsten
     * und überspringt die Tage ohne Ereignisse - ein Tag ohne Anmeldeversuche
     * sähe aus wie ein Tag, der nie stattgefunden hat.
     *
     * @param array<int, array{bucket:string, value:int}> $rows
     * @return array<int, array{bucket:string, value:int}>
     */
    private function fillGaps(array $rows, \DateTimeImmutable $from, \DateTimeImmutable $to, string $step): array {
        $byBucket = [];
        foreach ($rows as $row) {
            $byBucket[$row['bucket']] = $row['value'];
        }

        $series = [];
        $cursor = $this->snapToBucket($from, $step);
        while ($cursor <= $to) {
            $key = $cursor->format('Y-m-d');
            $series[] = ['bucket' => $key, 'value' => (int)($byBucket[$key] ?? 0)];
            unset($byBucket[$key]);
            $cursor = $cursor->modify($step);
        }

        // Sollte die Datenbank doch einen Kübel außerhalb des Rasters liefern,
        // geht er nicht verloren - er würde sonst still fehlen, und die Summe
        // im meta-Block wäre falsch.
        foreach ($byBucket as $bucket => $value) {
            $series[] = ['bucket' => (string)$bucket, 'value' => (int)$value];
        }
        usort($series, static fn(array $a, array $b): int => strcmp($a['bucket'], $b['bucket']));

        return $series;
    }
}
