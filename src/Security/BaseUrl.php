<?php
// src/Security/BaseUrl.php

namespace App\Security;

/**
 * Die feste Stamm-URL der Installation (Audit M6, Issue #116).
 *
 * WORUM ES GEHT. Links in Mails, die ein Einmal-Token tragen (Passwort-Reset,
 * Bestätigung der Registrierung, Bestätigung einer neuen Adresse), dürfen nie
 * aus dem Host-Header der Anfrage entstehen. Der Header gehört dem Absender:
 * `POST /forgot-password` mit `Host: evil.example` erzeugte sonst eine echte
 * Verbandsmail mit einem Link auf die Domain des Angreifers - und das Token
 * landete dort, sobald das Opfer klickt.
 *
 * config/config.php definiert die Konstante APP_URL IMMER - ohne
 * Umgebungsvariable aus dem (nur syntaktisch geprüften) Host-Header. Die
 * Konstante taugt deshalb nicht als Vertrauensanker; diese Klasse liest die
 * Umgebungsvariable direkt.
 *
 * VERTRAUENSWÜRDIG ist eine Basis nur, wenn sie
 *  - fest konfiguriert ist: `settings.base_url` (Systemeinstellungen bzw.
 *    Einrichtungsassistent), sonst die Umgebungsvariable `APP_URL`, oder
 *  - aus einem Host stammt, den die Allowlist TRUSTED_HOSTS ausdrücklich
 *    zulässt (App\Security\TrustedHost).
 * Alles andere liefert null. Wer null bekommt, verschickt keinen Token-Link
 * (App\Service\Mailer) und lässt Abläufe, die auf einen solchen Link
 * angewiesen sind, gar nicht erst beginnen (Registrierung, Adressänderung).
 *
 * Nur statische, seiteneffektfreie Methoden: Unit-Tests spielen die Quellen
 * per putenv() und $_SERVER durch.
 */
final class BaseUrl {

    private function __construct() {}

    /**
     * Prüft eine eingegebene Stamm-URL und liefert den geprüften Wert mit
     * abschließendem Slash - oder null.
     *
     * Aus AdminController::updateSystemSettings() übernommen, damit
     * Systemeinstellungen und Einrichtungsassistent dieselbe Regel haben.
     * BEWUSST OHNE Ausnahme für lokale Adressen im Assistenten: Ein dort
     * gespeichertes `http://localhost:8080/` stünde danach vorbelegt im Feld
     * der Systemeinstellungen und würde bei JEDEM späteren Speichern mit
     * invalid_base_url abgelehnt - der Admin käme an keine Systemeinstellung
     * mehr heran, ohne das Feld zu leeren.
     *
     * Das Protokoll wird VERLANGT, nicht ergänzt.
     *
     * Hier stand für schemalose Eingaben einmal ein
     * `$baseUrl = 'https://' . $baseUrl;`. Das war aus drei Gründen die
     * falsche Freundlichkeit:
     *
     * 1. Geprüft wurde danach eine Zeichenkette, die zur Hälfte von uns
     *    selbst stammte. Dieselbe Bauart hatte schon einmal einen Fehler
     *    versteckt (geprüft wurde `rtrim(...)`, gespeichert die Eingabe).
     * 2. Sie widerspricht dem eigenen Formular: Das Feld ist
     *    `<input type="url">`, Beschriftung und Hilfetext verlangen das
     *    Protokoll ausdrücklich. Ein Browser lässt eine Eingabe ohne Schema
     *    gar nicht erst abschicken - ergänzt wurde also nur für Anfragen, die
     *    das Formular umgehen.
     * 3. Ein Schema-Literal unmittelbar vor einem Wert aus `$_POST` ist die
     *    Bauform, aus der SSRF entsteht; Semgrep meldet sie als
     *    `tainted-url-host`. Die Regel kennt keinen Sanitizer - keine noch so
     *    strenge Prüfung danach kann sie erfüllen, nur das
     *    Nicht-mehr-Zusammensetzen.
     *
     * Das Schema kommt aus der geprüften Adresse und muss in der Allowlist
     * stehen: FILTER_VALIDATE_URL lässt auch `ftp://` und `javascript:` durch.
     *
     * Zurückgegeben wird der GEPRÜFTE Wert, nicht die Eingabe: filter_var
     * liefert die Adresse bei Erfolg zurück, und dieser Rückgabewert ist die
     * einzige Quelle - sonst laufen Prüfung und Ablage bei der nächsten
     * Änderung unbemerkt auseinander.
     *
     * Verteidigung in die Tiefe (OWASP SSRF Cheat Sheet): "localhost" sowie
     * literale private/reservierte IPs (auch IPv6 in eckigen Klammern) sind
     * als Host gesperrt. base_url wird nur zur Erzeugung von Links genutzt und
     * nie serverseitig abgerufen - das soll auch so bleiben, falls hier
     * künftig ein serverseitiger Abruf hinzukommt.
     */
    public static function normalize(string $input): ?string {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        $geprueft = filter_var(rtrim($input, '/'), FILTER_VALIDATE_URL);
        if (!is_string($geprueft)) {
            return null;
        }

        $host = (string)(parse_url($geprueft, PHP_URL_HOST) ?? '');
        $schema = strtolower((string)(parse_url($geprueft, PHP_URL_SCHEME) ?? ''));

        // parse_url liefert IPv6-Literale mit Klammern - filter_var erkennt
        // sie nur ohne.
        $ipKandidat = (str_starts_with($host, '[') && str_ends_with($host, ']')) ? substr($host, 1, -1) : $host;
        $istPrivateOderLoopbackIp = filter_var($ipKandidat, FILTER_VALIDATE_IP) !== false
            && filter_var($ipKandidat, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;

        if (
            !in_array($schema, ['http', 'https'], true)
            || $host === ''
            || strcasecmp($host, 'localhost') === 0
            || $istPrivateOderLoopbackIp
        ) {
            return null;
        }

        return $geprueft . '/';
    }

    /**
     * Die fest konfigurierte Stamm-URL mit abschließendem Slash: die nicht
     * leere settings.base_url, sonst die Umgebungsvariable APP_URL, sonst
     * null.
     *
     * getenv() statt der Konstante APP_URL: Die Konstante enthält auch den
     * Host-Header-Rückfall aus config/config.php. APP_URL setzt der Betreiber
     * selbst; geprüft wird nur, dass es eine http(s)-Adresse mit Host ist -
     * lokale Adressen sind für Test- und Entwicklungsumgebungen gewollt.
     */
    public static function fixed(?string $settingBaseUrl): ?string {
        $setting = trim((string)$settingBaseUrl);
        if ($setting !== '') {
            return rtrim($setting, '/') . '/';
        }

        $env = trim((string)(getenv('APP_URL') ?: ''));
        if ($env === '') {
            return null;
        }
        $schema = strtolower((string)(parse_url($env, PHP_URL_SCHEME) ?? ''));
        $host = (string)(parse_url($env, PHP_URL_HOST) ?? '');
        if (!in_array($schema, ['http', 'https'], true) || $host === '') {
            // Fail-closed: ein unbrauchbares APP_URL ist keine Basis für
            // Links, und der Host-Header ersetzt es nicht.
            return null;
        }
        return rtrim($env, '/') . '/';
    }

    /**
     * Die Basis für absolute Links in Mails: fixed(), sonst - NUR mit
     * konfigurierter TRUSTED_HOSTS-Allowlist - Schema und geprüfter Host der
     * Anfrage, sonst null.
     *
     * Ohne Allowlist liefert TrustedHost::resolve() weiterhin jeden
     * syntaktisch gültigen Host; für Token-Links reicht das nicht.
     */
    public static function forLinks(?string $settingBaseUrl): ?string {
        $fest = self::fixed($settingBaseUrl);
        if ($fest !== null) {
            return $fest;
        }

        if (!TrustedHost::hasAllowlist()) {
            return null;
        }
        $host = TrustedHost::resolve();
        if ($host === '') {
            return null;
        }
        return (ClientIp::isHttps() ? 'https://' : 'http://') . $host . '/';
    }

    /**
     * Gibt es überhaupt eine vertrauenswürdige Quelle? Unabhängig von der
     * einzelnen Anfrage - für die Warnung im Admin-Dashboard.
     */
    public static function isTrusted(?string $settingBaseUrl): bool {
        return self::fixed($settingBaseUrl) !== null || TrustedHost::hasAllowlist();
    }

    /**
     * Ein VORSCHLAG für das Feld Stamm-URL aus der aktuellen Anfrage - nur,
     * wenn er normalize() besteht (also nicht bei localhost oder privaten
     * IPs), sonst null.
     *
     * Nie ungefragt speichern: Der Wert stammt aus dem Host-Header. Er wird
     * nur angezeigt bzw. vorbelegt, der Admin bestätigt ihn mit dem Absenden.
     * In Views immer mit htmlspecialchars ausgeben.
     */
    public static function suggestion(): ?string {
        $host = TrustedHost::resolve();
        if ($host === '') {
            return null;
        }
        return self::normalize((ClientIp::isHttps() ? 'https://' : 'http://') . $host);
    }

    /**
     * forLinks() mit der settings.base_url aus der Datenbank - für Stellen,
     * die die Einstellungen nicht ohnehin geladen haben.
     */
    public static function forLinksFromSettings(): ?string {
        return self::forLinks(self::settingFromDb());
    }

    private static function settingFromDb(): ?string {
        try {
            $stmt = \App\Database::getInstance()->query("SELECT setting_value FROM settings WHERE setting_key = 'base_url' LIMIT 1");
            $wert = $stmt ? $stmt->fetchColumn() : false;
            return is_string($wert) ? $wert : null;
        } catch (\Throwable $e) {
            // Fail-closed: ohne lesbare Einstellung gilt nur, was die
            // Umgebung vorgibt.
            return null;
        }
    }
}
