<?php
// src/Service/Maintenance.php

namespace App\Service;

/**
 * Wartungsmodus (#232): Marker-Datei var/wartung.lock + früher Check im
 * Bootstrap (public/index.php, siehe guard()).
 *
 * Anlass ist das Addon `datenmigration`, das beim Import die komplette
 * Datenbank ersetzt (DROP/CREATE/INSERT je Tabelle): Während der wenigen
 * Sekunden des Einspielens dürfen parallele Requests nicht auf halb
 * aufgebaute Tabellen treffen. Deshalb ist der Mechanismus bewusst eine
 * reine Datei-Prüfung OHNE jede Datenbank-Abhängigkeit - der Check muss
 * gerade dann funktionieren, wenn die Datenbank nicht benutzbar ist.
 *
 * Typische Verwendung in einem Werkzeug/Addon:
 *
 *     $token = Maintenance::enable('Datenbank-Restore aus Backup xyz');
 *     try { ... riskante Arbeit ... } finally { Maintenance::disable($token); }
 *
 * Der Request, der enable() aufruft, läuft selbst normal weiter (der Guard
 * wurde für ihn ja bereits zu Request-Beginn passiert) - gesperrt werden
 * nur alle NACH dem Setzen des Markers eintreffenden Requests.
 *
 * DREI ARTEN VON MARKERN (Audit N72):
 *
 * 1. enable() - an den PROZESS gebunden. Der Marker trägt ein Token; der
 *    Prozess hält solange eine geteilte flock-Sperre auf var/wartung.inhaber.
 *    Endet der Prozess - regulär, per exit, nach einem Fatal Error, unter
 *    PHP-FPM auch nur der Request -, fällt der Marker: im Shutdown-Handler
 *    oder spätestens, wenn der nächste Request sieht, dass niemand mehr die
 *    Inhaber-Sperre hält. Diese Sperre führt der Kernel; sie braucht keine
 *    Mitwirkung des Aufrufers (kein Heartbeat - datenmigration spielt den
 *    Import in einem einzigen PDO::exec() ein) und keine Zeitschwelle. Die
 *    Prozesskennung taugte dafür nicht: Unter PHP-FPM, mod_php und LSAPI
 *    lebt der Worker nach einem Fatal Error weiter.
 *    Im selben Prozess ist enable() verschachtelbar: dasselbe Token, ein
 *    Zähler, erst das äußerste disable() hebt den Marker auf.
 * 2. enableDauerhaft() und `touch var/wartung.lock` - Marker OHNE Token und
 *    ohne Prozesskennung. Er verfällt nie und fällt nicht mit dem Prozess;
 *    aufgehoben wird er von Hand oder per disable() ohne Token. Für
 *    Werkzeuge, die den Wartungsmodus über ihr Ende hinaus setzen.
 * 3. enable($grund, true) - die Migrationssperre (Audit N39): wie 2., dazu
 *    das Flag migration_gesperrt.
 *
 * Marker ohne Token und ohne pid (2., 3. und der Marker, den das Addon
 * datenmigration selbst schreibt) fasst KEIN Automatismus an - weder das
 * Shutdown-Aufräumen noch guard(). Ältere Marker mit pid und ohne Token
 * folgen der bisherigen Regel in isStale().
 */
final class Maintenance {

    /**
     * Wert für den Retry-After-Header der 503-Antwort in Sekunden. Import/
     * Restore dauern typischerweise Sekunden, nicht Minuten - 30 Sekunden
     * sind für wohlerzogene Clients (Crawler, Monitoring) ein sinnvoller
     * Wiedervorlage-Abstand, ohne dass Besucher unnötig lange fernbleiben.
     */
    private const RETRY_AFTER_SECONDS = 30;

    /**
     * Ab welchem Alter der Marker eines NACHWEISLICH beendeten Prozesses als
     * verwaist gilt. Bewusst weit jenseits jedes regulären Fensters (die
     * Klasse ist für Sekunden gebaut) - der Wert ist kein Zeitlimit für die
     * Arbeit, sondern eine Sicherheitsschwelle gegen einen liegengebliebenen
     * Marker.
     */
    private const STALE_AFTER_SECONDS = 900;

    /** Token des eigenen, noch offenen Markers (enable()). */
    private static ?string $eigenesToken = null;

    /** Verschachtelungstiefe von enable() in diesem Prozess. */
    private static int $tiefe = 0;

    /** @var resource|null Geteilte Sperre auf var/wartung.inhaber. */
    private static $inhaberGriff = null;

    private static bool $aufraeumenRegistriert = false;

    private function __construct() {}

    /**
     * Absoluter Pfad der Marker-Datei. var/ liegt bewusst außerhalb von
     * public/ (nicht über HTTP erreichbar) und ist per .gitignore vom Repo
     * ausgenommen - der Marker ist Laufzeitzustand, kein Repo-Inhalt.
     */
    public static function lockFile(): string {
        return dirname(__DIR__, 2) . '/var/wartung.lock';
    }

    /**
     * Die Datei, auf der die Inhaber eines Markers eine geteilte flock-Sperre
     * halten (Audit N72). Sie bleibt dauerhaft liegen; entscheidend ist
     * allein, ob jemand sie gesperrt hält.
     */
    public static function inhaberDatei(): string {
        return dirname(__DIR__, 2) . '/var/wartung.inhaber';
    }

    /**
     * Aktiviert den Wartungsmodus für die Dauer DIESES Prozesses und liefert
     * das Token des Markers (siehe Klassendoku). Der Grund wird zusammen mit
     * dem Zeitpunkt in der Marker-Datei abgelegt - für Betreiber, die bei
     * einem hängen gebliebenen Marker nachvollziehen müssen, WER die Sperre
     * WARUM gesetzt hat. Auf der öffentlichen Hinweisseite erscheint der
     * Grund bewusst NICHT (könnte Interna wie Backup-Namen oder
     * Werkzeug-Details preisgeben).
     *
     * Ruft derselbe Prozess enable() erneut auf, bevor er disable() gerufen
     * hat, bleibt das Token gleich; nur der Grund wird aktualisiert und ein
     * Zähler erhöht. Ohne Zähler hätte ein inneres enable() (Reparatur im
     * Update, Hooks der Addon-Phase) das Token überschrieben, und sein
     * disable() hätte das äußere Fenster mitten im Kopieren aufgehoben.
     *
     * $migrationSperren (Audit N39, Rückweg #336): Der Marker trägt dann
     * WEDER Prozesskennung NOCH Token - er verfällt also nie, fällt nicht mit
     * dem Prozess - und das Flag migration_gesperrt, das
     * SchemaMigrator::run() respektiert. Der Rückweg setzt die Datenbank auf
     * den Stand vor #336 zurück; ohne Sperre machte der nächste Request das
     * sofort wieder rückgängig. Eine Sperre in settings wäre die Alternative
     * gewesen - aber die alte Version, die danach eingespielt wird, kennt sie
     * nicht, könnte sie also nie aufheben, und ein späteres erneutes Update
     * bliebe still blockiert. Einen Marker ohne pid respektieren auch
     * v0.7.1/v0.7.2. Aufgehoben wird er von Hand (Datei löschen) bzw. über
     * disable() ohne Token. Rückgabe dann ''.
     */
    public static function enable(string $grund, bool $migrationSperren = false): string {
        if ($migrationSperren) {
            self::schreibeMarker([
                'grund' => $grund,
                'seit' => date('c'),
                'pid' => null,
                'migration_gesperrt' => true,
            ]);
            return '';
        }

        if (self::$eigenesToken !== null && self::$tiefe > 0) {
            self::$tiefe++;
            self::schreibeMarker([
                'grund' => $grund,
                'seit' => date('c'),
                'pid' => getmypid(),
                'token' => self::$eigenesToken,
                'lebenszeichen' => is_resource(self::$inhaberGriff) ? 'flock' : null,
            ]);
            return self::$eigenesToken;
        }

        self::verzeichnisSicherstellen();

        // ERST die Inhaber-Sperre, DANN der Marker: Sähe ein paralleler
        // Request den Marker vor der Sperre, hielte er ihn für verwaist.
        $lebenszeichen = self::inhaberSperreNehmen() ? 'flock' : null;
        $token = bin2hex(random_bytes(16));

        try {
            self::schreibeMarker([
                'grund' => $grund,
                'seit' => date('c'),
                // Rückfall, falls es keine flock-Sperre gibt - siehe isStale().
                'pid' => getmypid(),
                'token' => $token,
                'lebenszeichen' => $lebenszeichen,
            ]);
        } catch (\Throwable $e) {
            self::inhaberSperreLoesen();
            throw $e;
        }

        self::$eigenesToken = $token;
        self::$tiefe = 1;

        // Aufräumen beim Prozessende, und zwar als LETZTE Shutdown-Funktion:
        // Die Registrierung aus einer Shutdown-Funktion heraus hängt sich
        // hinten an die dann laufende Liste - so räumt der Rückweg des
        // Updates (UpdateService::nachAbbruch()) zuerst zurück, bevor der
        // Marker fällt.
        if (!self::$aufraeumenRegistriert) {
            self::$aufraeumenRegistriert = true;
            register_shutdown_function(static function (): void {
                register_shutdown_function([self::class, 'nachProzessende']);
            });
        }

        return $token;
    }

    /**
     * Setzt einen Marker, der den Prozess überdauert (Audit N72): ohne Token
     * und ohne Prozesskennung, verfällt also nie - wie `touch
     * var/wartung.lock`. Für Werkzeuge, die den Wartungsmodus setzen und sich
     * dann beenden (`php -r '…Maintenance::enableDauerhaft("Umzug");'`); mit
     * enable() fiele der Marker bei deren Ende sofort wieder.
     */
    public static function enableDauerhaft(string $grund): void {
        self::schreibeMarker([
            'grund' => $grund,
            'seit' => date('c'),
            'pid' => null,
        ]);
    }

    /**
     * Beendet den Wartungsmodus. Ein fehlender Marker ist kein Fehler -
     * disable() steht typischerweise in einem finally-Block und muss auch
     * nach einem Fehlschlag VOR enable() gefahrlos aufrufbar sein.
     *
     * - Mit Token (oder ohne Argument in einem Prozess, der selbst enable()
     *   gerufen hat): Verschachtelte Aufrufe zählen nur herunter; entfernt
     *   wird der Marker nur, wenn er noch DIESES Token trägt. Einen fremden
     *   Marker - ein anderer Prozess hat inzwischen enable() gerufen, oder
     *   ein Dauer-/Handmarker - lässt er liegen.
     * - Ohne jedes Token: bedingungslos wie bisher (Betreiber, Werkzeuge).
     */
    public static function disable(?string $token = null): void {
        $token ??= self::$eigenesToken;
        $lockFile = self::lockFile();

        if ($token === null || $token === '') {
            if (is_file($lockFile)) {
                @unlink($lockFile);
            }
            return;
        }

        $eigenes = self::$eigenesToken !== null && hash_equals(self::$eigenesToken, $token);
        if ($eigenes && self::$tiefe > 1) {
            self::$tiefe--;
            return;
        }

        $info = self::info();
        if ($info !== null && $info['token'] !== null && hash_equals($info['token'], $token)) {
            @unlink($lockFile);
        }

        if ($eigenes) {
            // Erst das Token vergessen, dann die Inhaber-Sperre lösen - in
            // dieser Reihenfolge, damit ein Shutdown dazwischen nichts mehr
            // für eigen hält.
            self::$eigenesToken = null;
            self::$tiefe = 0;
            self::inhaberSperreLoesen();
        }
    }

    /**
     * Letzte Shutdown-Funktion (siehe enable()): Ist noch ein eigener Marker
     * offen, hat der Aufrufer sein disable() nicht mehr erreicht - Fatal
     * Error, exit, Zeitlimit. Die Arbeit ist nachweislich beendet; der
     * Marker fällt, damit Admins die Oberfläche erreichen (etwa, um ein
     * Backup zurückzuspielen).
     *
     * @internal nur für register_shutdown_function()
     */
    public static function nachProzessende(): void {
        if (self::$eigenesToken === null) {
            return;
        }
        $fehler = error_get_last();
        error_log(sprintf(
            'Wartungsmodus: Marker beim Prozessende aufgehoben, disable() wurde nicht erreicht%s',
            is_array($fehler) ? ' - letzter Fehler: ' . $fehler['message'] . ' in ' . $fehler['file'] . ':' . $fehler['line'] : ''
        ));
        self::$tiefe = 1;
        self::disable(self::$eigenesToken);
    }

    /**
     * Nur für Tests: vergisst Token und Zähler, löst die Inhaber-Sperre und
     * entfernt den Marker bedingungslos.
     */
    public static function resetForTests(): void {
        self::$eigenesToken = null;
        self::$tiefe = 0;
        self::inhaberSperreLoesen();
        if (is_file(self::lockFile())) {
            @unlink(self::lockFile());
        }
    }

    public static function isActive(): bool {
        return is_file(self::lockFile());
    }

    /**
     * Inhalt der Marker-Datei (grund, seit) für Diagnose-Zwecke - oder null,
     * wenn kein Wartungsmodus aktiv oder die Datei nicht lesbar/korrupt ist.
     * Ein von Hand angelegter, leerer Marker (`touch var/wartung.lock`)
     * aktiviert den Wartungsmodus genauso - isActive() prüft nur die
     * Existenz, nie den Inhalt.
     *
     * 'token' und 'lebenszeichen' (Audit N72) sind bei Markern aus
     * enableDauerhaft(), der Migrationssperre, von Hand und im Altformat null.
     *
     * @return array{grund: string, seit: string, pid: ?int, migration_gesperrt: bool, token: ?string, lebenszeichen: ?string}|null
     */
    public static function info(): ?array {
        if (!self::isActive()) {
            return null;
        }
        $raw = @file_get_contents(self::lockFile());
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['grund'], $data['seit'])) {
            return null;
        }
        return [
            'grund' => (string)$data['grund'],
            'seit' => (string)$data['seit'],
            'pid' => isset($data['pid']) && is_int($data['pid']) ? $data['pid'] : null,
            'migration_gesperrt' => ($data['migration_gesperrt'] ?? false) === true,
            'token' => isset($data['token']) && is_string($data['token']) && $data['token'] !== '' ? $data['token'] : null,
            'lebenszeichen' => ($data['lebenszeichen'] ?? null) === 'flock' ? 'flock' : null,
        ];
    }

    /**
     * Sperrt der aktive Marker die Schema-Migration (Rückweg #336, siehe
     * enable())? Ein von Hand angelegter oder gewöhnlicher Marker tut das
     * nicht.
     */
    public static function sperrtMigration(): bool {
        $info = self::info();
        return $info !== null && $info['migration_gesperrt'];
    }

    /**
     * Ist der Marker verwaist - also von einem Prozess gesetzt, den es nicht
     * mehr gibt, und seit Längerem unangetastet?
     *
     * Anlass ist der einzige Fall, in dem das `finally` eines Aufrufers nicht
     * mehr läuft: ein harter Abbruch (E_COMPILE_ERROR, ein abgelaufenes
     * `request_terminate_timeout` von PHP-FPM, ein getöteter Worker). Dann
     * bleibt der Marker liegen, und guard() beantwortet ab da JEDEN Request
     * mit 503 - bewusst auch für Admins. Seit das Update unbeaufsichtigt per
     * Cron laufen kann, sitzt niemand mehr davor, der das gleich bemerkt.
     *
     * Eine laufende Arbeit darf dieser Mechanismus unter keinen Umständen
     * aufreißen - das wäre genau der Schaden, gegen den es den Wartungsmodus
     * überhaupt gibt. Deshalb zwei Regeln, je nach Art des Markers:
     *
     * A. Marker mit Token und Lebenszeichen 'flock' (enable() seit Audit
     *    N72): verwaist genau dann, wenn niemand mehr die Inhaber-Sperre
     *    var/wartung.inhaber hält. Die hält der setzende Prozess vom enable()
     *    bis zum disable(); der Kernel löst sie beim Prozessende - auch nach
     *    einem Fatal Error, bei dem ein FPM-Worker mit derselben PID
     *    weiterlebt. Keine Zeitschwelle.
     *
     * B. Alle anderen (Altformat, Dateisystem ohne flock) - alle drei
     *    Bedingungen müssen zutreffen:
     *    1. Der Marker trägt eine auswertbare Nutzlast MIT Prozesskennung.
     *       Ein von Hand angelegter Marker (`touch var/wartung.lock`), einer
     *       aus enableDauerhaft(), die Migrationssperre und der Marker des
     *       Addons datenmigration ohne pid verfallen damit nie - geplante
     *       Wartung bleibt geplante Wartung.
     *    2. Dieser Prozess läuft nachweislich nicht mehr. Lässt sich das
     *       nicht feststellen, gilt er als lebend - im Zweifel bleibt
     *       gesperrt.
     *    3. Der Marker ist älter als STALE_AFTER_SECONDS.
     */
    public static function isStale(): bool {
        $info = self::info();
        if ($info === null) {
            return false;
        }

        if ($info['token'] !== null && $info['lebenszeichen'] === 'flock') {
            $frei = self::inhaberSperreFrei();
            if ($frei !== null) {
                return $frei;
            }
            // flock nicht feststellbar - Rückfall auf die PID-Regel.
        }

        if ($info['pid'] === null) {
            return false;
        }

        if (self::processIsRunning($info['pid'])) {
            return false;
        }

        $seit = strtotime($info['seit']);
        return $seit !== false && (time() - $seit) > self::STALE_AFTER_SECONDS;
    }

    /**
     * Läuft der Prozess noch? Im Zweifel JA - ein "weiß nicht" darf nie dazu
     * führen, dass eine laufende Arbeit ihre Sperre verliert. Deshalb liefert
     * diese Methode nur dann false, wenn die Abwesenheit tatsächlich belegt
     * ist.
     */
    private static function processIsRunning(int $pid): bool {
        if ($pid < 1) {
            return false;
        }
        // Linux: /proc ist die verlässlichste Auskunft und braucht keine
        // Erweiterung. Ohne /proc (etwa BSD-Hosting) entscheidet posix_kill
        // mit Signal 0; fehlt auch das, bleibt es bei "läuft".
        if (is_dir('/proc')) {
            return is_dir('/proc/' . $pid);
        }
        if (function_exists('posix_kill') && function_exists('posix_get_last_error')) {
            if (posix_kill($pid, 0)) {
                return true;
            }
            // Nur ESRCH (3, "kein solcher Prozess") belegt die Abwesenheit.
            // EPERM heißt: es gibt ihn, er gehört bloß jemand anderem.
            return posix_get_last_error() !== 3;
        }
        return true;
    }

    /**
     * Räumt einen verwaisten Marker weg (guard()). Liefert false, wenn er
     * liegen bleiben muss.
     *
     * - Liegt noch ein Update-Journal, obwohl der frühe Haken in
     *   public/index.php schon gelaufen ist, ist der Codebaum womöglich ein
     *   Mischstand (Rückweg belegt oder unvollständig): Der Marker bleibt.
     * - Marker mit Inhaber-Sperre: unter EXKLUSIVER Sperre erneut lesen und
     *   nur bei unverändertem Token löschen. Ein gleichzeitiges enable()
     *   wartet solange auf seine geteilte Sperre und schreibt danach seinen
     *   eigenen Marker - es kann ihn also nicht verlieren.
     */
    private static function verwaistenMarkerAufraeumen(): bool {
        if (is_file(dirname(__DIR__, 2) . '/var/update-journal/journal.jsonl')) {
            return false;
        }

        $info = self::info();
        if ($info === null || $info['token'] === null || $info['lebenszeichen'] !== 'flock') {
            // Altformat bzw. ohne flock: Die PID-Regel hat entschieden.
            @unlink(self::lockFile());
            return true;
        }

        $griff = @fopen(self::inhaberDatei(), 'c') ?: @fopen(self::inhaberDatei(), 'r');
        if ($griff === false) {
            return false;
        }
        try {
            if (!@flock($griff, LOCK_EX | LOCK_NB)) {
                return false;
            }
            $jetzt = self::info();
            if ($jetzt === null) {
                return !self::isActive();
            }
            if ($jetzt['token'] === null || !hash_equals($info['token'], $jetzt['token'])) {
                return false;
            }
            @unlink(self::lockFile());
            return true;
        } finally {
            @flock($griff, LOCK_UN);
            @fclose($griff);
        }
    }

    private static function verzeichnisSicherstellen(): void {
        $dir = dirname(self::lockFile());
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Wartungsmodus: Verzeichnis {$dir} kann nicht angelegt werden.");
        }
    }

    /** @param array<string, mixed> $nutzlast */
    private static function schreibeMarker(array $nutzlast): void {
        self::verzeichnisSicherstellen();
        $lockFile = self::lockFile();
        $payload = json_encode($nutzlast, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        // LOCK_EX gegen zwei gleichzeitig schreibende Prozesse - der Inhalt
        // ist rein informativ, aber eine halb geschriebene Datei soll info()
        // trotzdem nie zu sehen bekommen.
        if (@file_put_contents($lockFile, $payload, LOCK_EX) === false) {
            throw new \RuntimeException("Wartungsmodus: Marker-Datei {$lockFile} kann nicht geschrieben werden.");
        }
    }

    /**
     * Geteilte Sperre auf var/wartung.inhaber nehmen. false, wenn das nicht
     * geht (var/ nicht beschreibbar, kein flock) - dann trägt der Marker kein
     * Lebenszeichen und isStale() fällt auf die PID-Regel zurück.
     */
    private static function inhaberSperreNehmen(): bool {
        if (is_resource(self::$inhaberGriff)) {
            return true;
        }
        $griff = @fopen(self::inhaberDatei(), 'c');
        if ($griff === false) {
            return false;
        }
        // Nicht unbegrenzt blockieren: Exklusiv hält die Datei nur guard()
        // für einen Augenblick beim Aufräumen.
        for ($versuch = 0; $versuch < 100; $versuch++) {
            if (@flock($griff, LOCK_SH | LOCK_NB, $wuerdeBlockieren)) {
                self::$inhaberGriff = $griff;
                return true;
            }
            if (!$wuerdeBlockieren) {
                break; // kein flock auf diesem Dateisystem
            }
            usleep(50000);
        }
        @fclose($griff);
        error_log('Wartungsmodus: Inhaber-Sperre var/wartung.inhaber nicht verfügbar - Rückfall auf die Prozesskennung.');
        return false;
    }

    private static function inhaberSperreLoesen(): void {
        if (is_resource(self::$inhaberGriff)) {
            @flock(self::$inhaberGriff, LOCK_UN);
            @fclose(self::$inhaberGriff);
        }
        self::$inhaberGriff = null;
    }

    /**
     * Hält irgendjemand die Inhaber-Sperre? true = frei (verwaist), false =
     * gehalten, null = nicht feststellbar.
     */
    private static function inhaberSperreFrei(): ?bool {
        $datei = self::inhaberDatei();
        if (!is_file($datei)) {
            return true;
        }
        $griff = @fopen($datei, 'r');
        if ($griff === false) {
            return null;
        }
        try {
            if (@flock($griff, LOCK_EX | LOCK_NB, $wuerdeBlockieren)) {
                @flock($griff, LOCK_UN);
                return true;
            }
            return $wuerdeBlockieren ? false : null;
        } finally {
            @fclose($griff);
        }
    }

    /**
     * Früher Bootstrap-Check (public/index.php): Bei aktivem Wartungsmodus
     * wird der Request mit HTTP 503 + Retry-After und einer schlichten
     * Hinweisseite beendet - VOR Plugin-Boot, Router und jedem
     * Datenbank-Zugriff, damit die Sperre auch bei halb eingespielter
     * Datenbank greift (das ist ihr eigentlicher Zweck, siehe Klassendoku).
     *
     * Admin-Sessions sind bewusst NICHT ausgenommen (das Issue #232 nennt
     * die Ausnahme ausdrücklich als optional): Ohne Datenbank ließe sich die
     * Admin-Eigenschaft nur der unbestätigten Session-Behauptung entnehmen -
     * vor allem aber schützt die Sperre Admins genauso wie Besucher, denn
     * gerade ein Admin-Klick (Speichern, Löschen) zwischen DROP und INSERT
     * ist das Schadensszenario, gegen das der Wartungsmodus existiert. Bei
     * einem Zeitfenster von Sekunden ist die Aussperrung verschmerzbar; ein
     * hängen gebliebener Marker wird per Datei gelöst (rm var/wartung.lock
     * bzw. Maintenance::disable()), nicht per Sonderzugang.
     *
     * Ein unterbrochenes Update rollt NICHT dieser Guard zurück, sondern der
     * frühe Haken ganz oben in public/index.php (Audit M45) - der läuft auch
     * dann, wenn vendor/ oder config.php halb kopiert sind und die Anwendung
     * gar nicht bis hierher käme.
     *
     * Unter CLI (Cron-Skripte, Werkzeuge) ist der Guard ein No-Op - genau
     * die Werkzeuge, die den Wartungsmodus setzen, müssen ja weiterarbeiten
     * können.
     */
    public static function guard(): void {
        if (PHP_SAPI === 'cli') {
            return;
        }
        if (!self::isActive()) {
            return;
        }

        // Marker eines beendeten Prozesses: Der Abbruch war hart genug, dass
        // weder finally noch Shutdown-Aufräumen lief (SIGKILL, FPM-Timeout) -
        // ohne diese Prüfung bliebe die Installation dauerhaft mit 503
        // gesperrt, und zwar auch für Admins. isStale() ist bewusst streng,
        // siehe dort; eine laufende Arbeit verliert ihre Sperre hier nie.
        if (self::isStale() && self::verwaistenMarkerAufraeumen()) {
            return;
        }

        // Locale ohne Datenbank bestimmen: Die reguläre Auflösung
        // (Translator::resolveRequestLocale()) braucht die Settings aus der
        // Datenbank - hier steht nur die bereits in config.php gestartete
        // Session zur Verfügung. Eine früher gewählte Sprache bleibt so
        // erhalten, alle anderen sehen die Fallback-Sprache.
        \App\I18n\Translator::init((string)($_SESSION['locale'] ?? 'de'));

        http_response_code(503);
        header('Retry-After: ' . self::RETRY_AFTER_SECONDS);
        // Fehlerseiten sollen nicht im Cache landen und nach der Wartung
        // weiter ausgeliefert werden.
        header('Cache-Control: no-store');

        require dirname(__DIR__) . '/Views/error_503.php';
        exit;
    }
}
